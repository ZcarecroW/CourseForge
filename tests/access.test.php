<?php
declare(strict_types=1);

/**
 * Who may reach whose rows, and what a listing narrows to.
 *
 * CourseForge 4.0 has accounts, and everything an account owns carries its name
 * in a column. Two ideas are deliberately kept apart: the actor is who is
 * asking, and the owner is whose data a row is. Authorisation reads the actor;
 * every related lookup reads the row's own `username`, so an administrator
 * opening somebody else's course sees it exactly as its owner would rather than
 * as a course with no profile and no tags.
 *
 * The one behaviour worth stating out loud is that a row the actor may not
 * reach comes back as missing rather than as forbidden. Telling somebody that
 * course 47 exists but is not theirs is a small leak, and it costs nothing to
 * avoid - so these tests assert the status code, not only that it raised.
 */

use CourseForge\Domain\Projects;
use CourseForge\Security\Access;
use CourseForge\Security\Actor;
use CourseForge\Support\Db;
use CourseForge\Support\HttpException;

/** One course row, written straight in: this is about reaching it, not making it. */
function courseOwnedBy(string $owner, string $name): int
{
    Db::run(
        'INSERT INTO projects (username, name, topic, created_at, updated_at) VALUES (?,?,?,?,?)',
        [$owner, $name, 'testing', time(), time()]
    );
    return Db::lastId();
}

function runOwnedBy(string $owner, int $projectId): int
{
    Db::run(
        'INSERT INTO batch_jobs (username, project_id, slot, mode, provider, ai_id, model,
                                 remote_id, remote_state, remote_ref, status, error, counts,
                                 created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$owner, $projectId, 'page', 'batch', 'anthropic', 'a1', 'claude-opus-5',
         'msgbatch_test', 'in_progress', '', 'submitted', '', '{}', time(), time()]
    );
    return Db::lastId();
}

$alice = Actor::make('alice', 'Alice', Actor::ROLE_USER);
$bob = Actor::make('bob', 'Bob', Actor::ROLE_USER);
$root = Actor::make('root', 'Root', Actor::ROLE_ADMIN);

$aliceCourse = courseOwnedBy('alice', 'Tides');
$bobCourse = courseOwnedBy('bob', 'Bridges');
$aliceRun = runOwnedBy('alice', $aliceCourse);

test('an account reaches its own course', static function () use ($alice, $aliceCourse): void {
    same('alice', (string)Access::project($alice, $aliceCourse)['username'], 'the owner on the row');
});

test('an account cannot reach another account\'s course, and is told it is missing', static function () use ($bob, $aliceCourse): void {
    $e = raises(static fn(): array => Access::project($bob, $aliceCourse), 'somebody else\'s course');
    ok($e instanceof HttpException, 'refused with an HttpException');
    same(404, $e->status(), 'not found rather than forbidden, so the row is not confirmed to exist');
});

test('an administrator reaches any account\'s course, as its owner', static function () use ($root, $aliceCourse): void {
    same('alice', (string)Access::project($root, $aliceCourse)['username'], 'the owner travels with the row');
});

test('the same rule covers runs', static function () use ($bob, $root, $aliceRun): void {
    same(404, raises(static fn(): array => Access::run($bob, $aliceRun), 'somebody else\'s run')->status(), 'refused');
    same('alice', (string)Access::run($root, $aliceRun)['username'], 'an administrator may look');
});

test('a listing narrows to the account, and widens for an administrator', static function () use ($alice, $root): void {
    $hers = Projects::all(Access::listingOwner($alice));
    same(1, count($hers), 'alice sees only her own course');
    same('Tides', (string)$hers[0]['name'], 'and it is hers');

    $all = Projects::all(Access::listingOwner($root));
    ok(count($all) >= 2, 'an administrator sees the whole installation, got ' . count($all));
});

test('an administrator can narrow a listing to one account on purpose', static function () use ($root): void {
    same('bob', Access::listingOwner($root, 'bob'), 'the account asked for');
    same(null, Access::listingOwner($root), 'and everybody when none was');

    $his = Projects::all(Access::listingOwner($root, 'bob'));
    same(1, count($his), 'one course');
    same('Bridges', (string)$his[0]['name'], 'and it is bob\'s');
});

test('a normal account may not list somebody else', static function () use ($alice): void {
    same('alice', Access::listingOwner($alice), 'asking for nothing means asking for your own');
    same('alice', Access::listingOwner($alice, 'ALICE'), 'and your own name is your own, whatever the case');
    same(
        403,
        raises(static fn(): ?string => Access::listingOwner($alice, 'bob'), 'listing another account')->status(),
        'forbidden - unlike a row, a listing gives nothing away by refusing plainly'
    );
});

test('the scope fragment is the whole of how a scoped query narrows', static function () use ($alice, $root): void {
    same(['username = ?', ['alice']], $alice->scope(), 'an account is narrowed by its name');
    same(['1 = 1', []], $root->scope(), 'an administrator is not narrowed at all');

    [$where, $params] = $alice->scope('username');
    $rows = Db::rows('SELECT id FROM batch_jobs WHERE ' . $where, $params);
    same(1, count($rows), 'and the fragment really does bind');
});

test('reaching is case-insensitive on the name but not blind to it', static function () use ($alice): void {
    ok($alice->mayReach('ALICE'), 'the same account written differently');
    ok(!$alice->mayReach('alice2'), 'a name that merely starts the same is a different account');
    same(403, raises(static fn(): mixed => $alice->requireAdmin(), 'an ordinary account')->status(), 'refused');
});
