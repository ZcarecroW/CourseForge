<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * The live run state machine, and the two ways it used to lose a page for good.
 *
 * A page can be claimed by exactly one run at a time - that is what the unique
 * partial index on batch_items(page_id) enforces, and it is what makes starting
 * a run safe. The cost of that guarantee is that every path out of a claim has
 * to actually release it. Two did not:
 *
 *   - stopping a run left its in-flight item `working`, and the worker's own
 *     failure then put that item back to `pending` on a run that had already
 *     ended. Nothing claims a pending item of a terminal run, so the page was
 *     held against every future run for ever;
 *   - an item had no owner, so a worker whose lease had expired hours earlier
 *     could settle a claim that now belonged to somebody else, take the page
 *     off the worker writing it, and have it generated - and paid for - twice.
 *
 * Both are about the same property: a claim is a lease with an owner, and the
 * item's state alone never says whose it is.
 */

use CourseForge\Ai\Run\LiveDriver;
use CourseForge\Ai\Run\RunManager;
use CourseForge\Domain\Runs;
use CourseForge\Support\Db;
use CourseForge\Support\HttpException;

/** A course with $n pages. @return array{0:int,1:array<int,int>} */
function rsmCourse(string $owner, int $n): array
{
    Db::run(
        'INSERT INTO projects (username, name, topic, created_at, updated_at) VALUES (?,?,?,?,?)',
        [$owner, 'Run state ' . random_int(1, 1_000_000), 'testing', time(), time()]
    );
    $projectId = Db::lastId();
    Db::run('INSERT INTO chapters (project_id, idx, title) VALUES (?,?,?)', [$projectId, 0, 'Chapter']);
    $chapterId = Db::lastId();

    $pages = [];
    for ($i = 1; $i <= $n; $i++) {
        Db::run(
            'INSERT INTO pages (project_id, chapter_id, idx, title, status, updated_at) VALUES (?,?,?,?,?,?)',
            [$projectId, $chapterId, $i, 'Page ' . $i, 'pending', time()]
        );
        $pages[] = Db::lastId();
    }
    return [$projectId, $pages];
}

/** An open live run over those pages, with the pages marked queued as start() does. */
function rsmRun(string $owner, int $projectId, array $pageIds): int
{
    $rows = [];
    foreach ($pageIds as $pageId) {
        $rows[] = ['page_id' => $pageId, 'custom_id' => RunManager::customId($pageId), 'title' => 'A page'];
    }
    $runId = Runs::reserve($owner, $projectId, null, 'page', [
        'mode' => Runs::MODE_LIVE,
        'provider' => 'anthropic',
        'ai_id' => 'a1',
        'model' => 'claude-opus-5',
    ], $rows);
    Runs::start($runId);
    foreach ($pageIds as $pageId) {
        Db::run('UPDATE pages SET status = ? WHERE id = ?', ['queued', $pageId]);
    }
    return $runId;
}

function rsmItemStatus(int $runId, int $pageId): string
{
    $row = Db::row(
        'SELECT status FROM batch_items WHERE job_id = ? AND custom_id = ?',
        [$runId, RunManager::customId($pageId)]
    );
    return (string)($row['status'] ?? '(no item)');
}

function rsmPageStatus(int $pageId): string
{
    return (string)(Db::row('SELECT status FROM pages WHERE id = ?', [$pageId])['status'] ?? '(gone)');
}

/** Whether a page is free to be put into a new run - the property that was lost. */
function rsmClaimable(string $owner, int $projectId, int $pageId): bool
{
    try {
        $runId = rsmRun($owner, $projectId, [$pageId]);
    } catch (HttpException) {
        return false;
    }
    Db::run('DELETE FROM batch_jobs WHERE id = ?', [$runId]);
    return true;
}

/** recordFailure is the driver's own private decision; the test drives the real one. */
function rsmRecordFailure(int $runId, string $customId, int $projectId, int $pageId, int $attempts, string $owner): void
{
    (new ReflectionMethod(LiveDriver::class, 'recordFailure'))
        ->invoke(null, $runId, $customId, $projectId, $pageId, $attempts, 3, 'HTTP 429', $owner);
}

/* ------------------------------------------------- a claim has an owner */

test('a claim carries a token, and a fresh one every time', static function (): void {
    [$projectId, $pages] = rsmCourse('claims', 1);
    $runId = rsmRun('claims', $projectId, $pages);

    $first = Runs::claimNextItem();
    ok($first !== null, 'the worker gets the page');
    ok(($first['owner'] ?? '') !== '', 'and a token with it');

    // Hand it back the way an expired lease does, then claim it again.
    Runs::requeueItem($runId, (string)$first['custom_id'], '', (string)$first['owner']);
    $second = Runs::claimNextItem();

    ok($second !== null, 'the page is claimable again');
    ok($first['owner'] !== $second['owner'], 'the second claim is a different claim');
});

test('a settle from a worker that no longer holds the claim is refused', static function (): void {
    [$projectId, $pages] = rsmCourse('stale', 1);
    $runId = rsmRun('stale', $projectId, $pages);
    $customId = RunManager::customId($pages[0]);

    $a = Runs::claimNextItem();
    Runs::requeueItem($runId, $customId, '', (string)$a['owner']); // A's lease expires
    $b = Runs::claimNextItem();                                    // B takes the page over

    ok(!Runs::settleItem($runId, $customId, Runs::ITEM_DONE, '', Runs::ITEM_WORKING, (string)$a['owner']),
        'A cannot report a result for a claim that is not A\'s');
    ok(!Runs::requeueItem($runId, $customId, 'a rate limit, hours ago', (string)$a['owner']),
        'and A cannot hand back a page it no longer holds');
    same(Runs::ITEM_WORKING, rsmItemStatus($runId, $pages[0]), 'B still holds it');

    ok(Runs::settleItem($runId, $customId, Runs::ITEM_DONE, '', Runs::ITEM_WORKING, (string)$b['owner']),
        'B, which does hold it, settles');
});

test('a stale worker cannot get the same page generated a second time', static function (): void {
    [$projectId, $pages] = rsmCourse('twice', 1);
    $runId = rsmRun('twice', $projectId, $pages);
    $customId = RunManager::customId($pages[0]);

    $a = Runs::claimNextItem();
    Runs::requeueItem($runId, $customId, '', (string)$a['owner']);
    $b = Runs::claimNextItem();

    // A wakes up inside its provider call and reports the failure it hit.
    rsmRecordFailure($runId, $customId, $projectId, $pages[0], (int)$a['attempts'], (string)$a['owner']);

    same(Runs::ITEM_WORKING, rsmItemStatus($runId, $pages[0]), 'the page is still B\'s');
    same(null, Runs::claimNextItem(), 'so no third worker can be handed it');
    same((int)$b['attempts'], (int)Db::row('SELECT attempts FROM batch_items WHERE job_id = ?', [$runId])['attempts'],
        'and it was not charged for another attempt');
});

test('a refused settle does not stamp an error on a page somebody else wrote', static function (): void {
    [$projectId, $pages] = rsmCourse('noerror', 1);
    $runId = rsmRun('noerror', $projectId, $pages);
    $customId = RunManager::customId($pages[0]);

    $a = Runs::claimNextItem();
    // The page is written another way while A is still in its provider call.
    Runs::settleItem($runId, $customId, Runs::ITEM_DONE, '', Runs::ITEM_WORKING);
    Db::run('UPDATE pages SET status = ?, content = ? WHERE id = ?', ['generated', 'written by hand', $pages[0]]);

    // A's last attempt fails, with nothing left to retry into.
    (new ReflectionMethod(LiveDriver::class, 'recordFailure'))
        ->invoke(null, $runId, $customId, $projectId, $pages[0], 3, 3, 'the provider gave up', (string)$a['owner']);

    same(Runs::ITEM_DONE, rsmItemStatus($runId, $pages[0]), 'the item stays written');
    same('generated', rsmPageStatus($pages[0]), 'and the page is not shown as failed');
    same('written by hand', (string)Db::row('SELECT content FROM pages WHERE id = ?', [$pages[0]])['content'],
        'and keeps its content');
});

/* ------------------------------------- stopping a run releases every page */

test('a stopped run leaves nothing for a worker to pick up', static function (): void {
    [$projectId, $pages] = rsmCourse('order', 3);
    $runId = rsmRun('order', $projectId, $pages);
    Runs::claimNextItem();

    RunManager::cancel('order', $runId);

    same(null, Runs::claimNextItem(), 'the queue is empty');
    same('canceled', rsmItemStatus($runId, $pages[1]), 'the untouched pages are released');
    same('pending', rsmPageStatus($pages[2]), 'and say so in the outline');
    ok(rsmClaimable('order', $projectId, $pages[1]), 'and are free for another run at once');
});

test('a page in flight when the run is stopped is not thrown away', static function (): void {
    [$projectId, $pages] = rsmCourse('inflight', 2);
    $runId = rsmRun('inflight', $projectId, $pages);
    $item = Runs::claimNextItem();

    RunManager::cancel('inflight', $runId);
    same(Runs::ITEM_WORKING, rsmItemStatus($runId, $pages[0]), 'the worker keeps its claim');

    ok(Runs::settleItem($runId, (string)$item['custom_id'], Runs::ITEM_DONE, '', Runs::ITEM_WORKING, (string)$item['owner']),
        'and the page it paid for is still recorded when it finishes');
    ok(rsmClaimable('inflight', $projectId, $pages[0]), 'the page is free again afterwards');
});

test('a page whose attempt fails after the run was stopped is released, not stranded', static function (): void {
    [$projectId, $pages] = rsmCourse('stranded', 2);
    $runId = rsmRun('stranded', $projectId, $pages);
    $item = Runs::claimNextItem();

    RunManager::cancel('stranded', $runId);
    rsmRecordFailure($runId, (string)$item['custom_id'], $projectId, $pages[0], 1, (string)$item['owner']);

    same('canceled', rsmItemStatus($runId, $pages[0]), 'the item ends rather than going back to pending');
    same('pending', rsmPageStatus($pages[0]), 'the outline stops saying the page is queued');
    ok(rsmClaimable('stranded', $projectId, $pages[0]), 'and a new run can have the page');
});

test('a page whose worker never comes back after a stop is released by the scheduler', static function (): void {
    [$projectId, $pages] = rsmCourse('killed', 2);
    $runId = rsmRun('killed', $projectId, $pages);
    $item = Runs::claimNextItem();

    RunManager::cancel('killed', $runId);
    Db::run('UPDATE batch_items SET started_at = ? WHERE id = ?', [time() - 7200, (int)$item['id']]);

    ok(LiveDriver::recover() >= 1, 'the tick releases it');
    same('canceled', rsmItemStatus($runId, $pages[0]), 'the item ends');
    ok(rsmClaimable('killed', $projectId, $pages[0]), 'and the page is free');
});

test('a page stranded by an earlier release is swept up by the scheduler', static function (): void {
    [$projectId, $pages] = rsmCourse('legacy', 2);
    $runId = rsmRun('legacy', $projectId, $pages);
    // Exactly the row the old cancel-then-requeue left: pending, terminal run.
    Db::run('UPDATE batch_jobs SET status = ?, finished_at = ? WHERE id = ?', [Runs::CANCELED, time(), $runId]);

    ok(!rsmClaimable('legacy', $projectId, $pages[0]), 'which is unclaimable to start with');
    ok(LiveDriver::recover() >= 2, 'the tick sweeps both');
    same('canceled', rsmItemStatus($runId, $pages[0]), 'the items end');
    same('pending', rsmPageStatus($pages[0]), 'the pages stop claiming to be queued');
    ok(rsmClaimable('legacy', $projectId, $pages[0]), 'and they can be run again');
});

test('a worker cannot requeue into a run that has ended', static function (): void {
    [$projectId, $pages] = rsmCourse('terminal', 1);
    $runId = rsmRun('terminal', $projectId, $pages);
    $item = Runs::claimNextItem();
    Runs::update($runId, ['status' => Runs::COMPLETED, 'finished_at' => time()]);

    ok(!Runs::requeueItem($runId, (string)$item['custom_id'], 'a rate limit', (string)$item['owner']),
        'the requeue is refused by the run\'s own status');
    same(Runs::ITEM_WORKING, rsmItemStatus($runId, $pages[0]), 'and the item is left exactly as it was');
    ok(!Runs::requeueItem($runId, 'cf-page-no-such-thing', '', ''), 'an item that is not there is not invented');
});

/* ------------------------------------------------- what still has to work */

test('the ordinary run still runs', static function (): void {
    [$projectId, $pages] = rsmCourse('happy', 3);
    $runId = rsmRun('happy', $projectId, $pages);

    $seen = [];
    while (($item = Runs::claimNextItem()) !== null) {
        $seen[] = (int)$item['page_id'];
        ok(Runs::settleItem($runId, (string)$item['custom_id'], Runs::ITEM_DONE, '', Runs::ITEM_WORKING, (string)$item['owner']),
            'the worker that holds page ' . $item['page_id'] . ' settles it');
    }

    same($pages, $seen, 'every page was handed out exactly once, in order');
    ok(Runs::closeIfDone($runId), 'and the run closes');
    same(Runs::COMPLETED, (string)Runs::require('happy', $runId)['status'], 'as completed');
});

test('a failure below the attempt limit still goes back into the queue', static function (): void {
    [$projectId, $pages] = rsmCourse('retry', 1);
    $runId = rsmRun('retry', $projectId, $pages);
    $item = Runs::claimNextItem();

    rsmRecordFailure($runId, (string)$item['custom_id'], $projectId, $pages[0], 1, (string)$item['owner']);

    same(Runs::ITEM_PENDING, rsmItemStatus($runId, $pages[0]), 'the page waits for another go');
    same('queued', rsmPageStatus($pages[0]), 'and the outline shows it waiting');
    ok(Runs::claimNextItem() !== null, 'so the next tick picks it up');
});
