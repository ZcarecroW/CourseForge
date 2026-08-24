<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * The three pieces of plumbing under a run: the transaction, the lease and the
 * page ids a run is asked for.
 *
 * All three failed in the same shape - a rule stated in one place and applied
 * differently in another:
 *
 *   - Db::transaction promised the connection's fifteen-second busy timeout and
 *     opened a DEFERRED transaction, which in WAL mode cannot wait once it has
 *     read. A transaction that read before it wrote failed outright while one
 *     that wrote first waited and succeeded;
 *   - Lock::heldFor() and Lock::acquire() disagreed about whether a lease whose
 *     expiry is this very second is free, so a precondition could pass while the
 *     acquire it was checking for was refused;
 *   - the run endpoints cast page ids with (int), and PHP turns any array and
 *     `true` into 1, so a body naming pages 3 and 4 as objects ran page 1
 *     instead - a written page, overwritten, on the owner's AI account.
 */

use CourseForge\Api\RunController;
use CourseForge\Support\Db;
use CourseForge\Support\HttpException;
use CourseForge\Support\Lock;
use CourseForge\Support\Request;

/* ------------------------------------------------------------ transactions */

test('a transaction commits, and a throwing one leaves nothing behind', static function (): void {
    $count = static fn(): int => (int)Db::row("SELECT COUNT(*) AS n FROM meta WHERE key LIKE 'tx.%'")['n'];
    $before = $count();

    Db::transaction(static function (): void {
        Db::run('INSERT INTO meta (key, value) VALUES (?,?)', ['tx.kept', '1']);
    });
    same($before + 1, $count(), 'the committed row is there');

    $e = raises(static function (): void {
        Db::transaction(static function (): void {
            Db::run('INSERT INTO meta (key, value) VALUES (?,?)', ['tx.dropped', '1']);
            throw new RuntimeException('boom');
        });
    }, 'the closure throws through');
    same('boom', $e->getMessage(), 'and it is the closure\'s own error, not a rollback failure');
    same($before + 1, $count(), 'the rolled back row is not');
    ok(!Db::pdo()->inTransaction(), 'and the connection is left clean');
});

test('a transaction inside a transaction joins the one already open', static function (): void {
    Db::transaction(static function (): void {
        Db::run('INSERT INTO meta (key, value) VALUES (?,?)', ['tx.outer', '1']);
        Db::transaction(static function (): void {
            Db::run('INSERT INTO meta (key, value) VALUES (?,?)', ['tx.inner', '1']);
        });
        ok(Db::pdo()->inTransaction(), 'the inner call did not commit the outer one');
    });
    same(2, (int)Db::row("SELECT COUNT(*) AS n FROM meta WHERE key IN ('tx.outer','tx.inner')")['n'],
        'and both rows land together');
});

test('a transaction kind that SQLite has never heard of is refused, not sent', static function (): void {
    $e = raises(static fn(): mixed => Db::transaction(static fn(): int => 1, 'sideways'), 'an unknown kind throws');
    ok(str_contains($e->getMessage(), 'sideways'), 'and says which one - got: ' . $e->getMessage());
    ok(!Db::pdo()->inTransaction(), 'nothing was begun');
});

test('a transaction holds the write lock before its first statement', static function (): void {
    // Which lock is taken, and when, is not visible from inside the connection
    // that took it - so a second connection asks SQLite. It is given no busy
    // timeout at all, so it answers at once either way: BEGIN IMMEDIATE fails
    // while somebody else holds the write lock and succeeds while nobody does.
    $probe = new PDO('sqlite:' . Db::file(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $probe->exec('PRAGMA busy_timeout = 0');

    /** Whether the write lock is held by somebody else right now. */
    $lockedOut = static function () use ($probe): bool {
        try {
            $probe->exec('BEGIN IMMEDIATE');
            $probe->exec('ROLLBACK');
            return false;
        } catch (Throwable) {
            return true;
        }
    };

    $atStart = null;
    Db::transaction(static function () use ($lockedOut, &$atStart): void {
        // Nothing has been read or written yet. This is the moment that decides
        // whether busy_timeout will be honoured later: a DEFERRED transaction
        // holds nothing here and cannot wait when it upgrades, an IMMEDIATE one
        // already has the lock and never has to.
        $atStart = $lockedOut();
    });
    ok($atStart === true, 'the default kind is a writer from its first instruction');

    $atStartDeferred = null;
    Db::transaction(static function () use ($lockedOut, &$atStartDeferred): void {
        $atStartDeferred = $lockedOut();
    }, 'deferred');
    ok($atStartDeferred === false, 'and the read-only kind, when asked for, is not');

    ok(!$lockedOut(), 'neither leaves the lock behind');
});

/* ------------------------------------------------------------------ leases */

test('a lease that reports as free is a lease that can be taken', static function (): void {
    foreach ([-1, 0, 1] as $offset) {
        $name = 'test.lease.' . $offset;
        Db::run('DELETE FROM locks WHERE name = ?', [$name]);
        Db::run('INSERT INTO locks (name, until, owner) VALUES (?,?,?)', [$name, time() + $offset, 'somebody-else']);

        $held = Lock::heldFor($name);
        $taken = Lock::acquire($name, 60);

        same($held === 0, $taken !== false,
            'until = now' . sprintf('%+d', $offset) . ': heldFor said ' . $held
                . ' and acquire ' . ($taken === false ? 'refused' : 'took it'));
    }
});

test('a lease that has not run out is still refused', static function (): void {
    Db::run('DELETE FROM locks WHERE name = ?', ['test.lease.busy']);
    $mine = Lock::acquire('test.lease.busy', 60);

    ok($mine !== false, 'the first worker takes it');
    ok(Lock::heldFor('test.lease.busy') > 0, 'and it reports as held');
    ok(Lock::acquire('test.lease.busy', 60) === false, 'so nobody else gets it');
    ok(Lock::renew('test.lease.busy', 60, (string)$mine), 'the holder may extend it');
    ok(!Lock::renew('test.lease.busy', 60, 'not-the-holder'), 'and nobody else may');

    Lock::release('test.lease.busy', (string)$mine);
    same(0, Lock::heldFor('test.lease.busy'), 'a released lease is free at once');
    ok(Lock::acquire('test.lease.busy', 60) !== false, 'and the next worker takes it');
});

/* ------------------------------------------------------------- page ids in */

/** A Request carrying a body, the shape the front controller hands a handler. */
function plumbingRequest(array $body): Request
{
    $class = new ReflectionClass(Request::class);
    $request = $class->newInstanceWithoutConstructor();
    foreach (['method' => 'POST', 'path' => 'projects/1/runs', 'body' => $body, 'params' => []] as $key => $value) {
        $class->getProperty($key)->setValue($request, $value);
    }
    return $request;
}

/** @return array<int,int>|string the ids, or the message the request was refused with */
function plumbingSelection(mixed $pages, int $projectId): array|string
{
    try {
        return (new ReflectionMethod(RunController::class, 'selection'))
            ->invoke(null, plumbingRequest(['pages' => $pages]), $projectId);
    } catch (HttpException $e) {
        return $e->getMessage();
    }
}

test('a run is only ever asked for page ids that are page ids', static function (): void {
    Db::run('INSERT INTO projects (username, name, topic, created_at, updated_at) VALUES (?,?,?,?,?)',
        ['ids', 'Selection', 'testing', time(), time()]);
    $projectId = Db::lastId();
    Db::run('INSERT INTO chapters (project_id, idx, title) VALUES (?,?,?)', [$projectId, 0, 'Chapter']);
    $chapterId = Db::lastId();

    $pages = [];
    for ($i = 1; $i <= 4; $i++) {
        Db::run('INSERT INTO pages (project_id, chapter_id, idx, title, updated_at) VALUES (?,?,?,?,?)',
            [$projectId, $chapterId, $i, 'Page ' . $i, time()]);
        $pages[] = Db::lastId();
    }

    same([$pages[2], $pages[3]], plumbingSelection([$pages[2], $pages[3]], $projectId), 'numbers are ids');
    same([$pages[2]], plumbingSelection([(string)$pages[2], (string)$pages[2]], $projectId),
        'so are numeric strings, and a page named twice is one page');

    // Every one of these used to resolve to the integer 1 without a word.
    foreach ([
        'an object'        => [['id' => $pages[2]], ['id' => $pages[3]]],
        'a nested list'    => [[$pages[2]], [$pages[3]]],
        'a boolean'        => [true],
        'a decimal'        => ['3.9'],
        'a word'           => ['seven'],
        'nothing at all'   => [null],
        'a negative'       => [-1],
        'zero'             => [0],
    ] as $what => $body) {
        $result = plumbingSelection($body, $projectId);
        ok(is_string($result), $what . ' is refused rather than cast - got ' . json_encode($result));
        same('Every entry in "pages" must be a page id.', $result, $what . ' is refused by name');
    }
});
