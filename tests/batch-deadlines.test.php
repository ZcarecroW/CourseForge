<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * A queued batch has two deadlines, and confusing them loses a course.
 *
 * The first is when the provider stops running whatever is still queued: 24
 * hours nearly everywhere, 48 on Gemini, which then returns nothing at all for
 * the pages it never reached. The second is when the finished answers stop
 * being downloadable - 29 days at Anthropic, 30 at OpenAI and OpenRouter, six
 * weeks at Gemini. They are weeks apart and they describe different losses, so
 * a batch that has died has to be settled rather than polled for ever, and a
 * run has to be collected long before the second date passes.
 *
 * Every driver computes both. What these tests pin down is that the second one
 * survives the trip through the run row and out to the tools that report it,
 * because the way this went wrong was quiet: the download deadline was dropped
 * on the way in and the batch's own expiry was reported under its name on the
 * way out, which told an agent that a month of retention was over tomorrow.
 */

use CourseForge\Ai\Batch\BatchHandle;
use CourseForge\Ai\Run\BatchDriver;
use CourseForge\Domain\Runs;
use CourseForge\Mcp\Tools;
use CourseForge\Security\Actor;
use CourseForge\Support\Db;

const DAY = 86400;

/** A course and a submitted batch run against it, with both deadlines set. */
function queuedRun(string $owner, string $course, int $expiresAt, int $resultsExpireAt): int
{
    Db::run(
        'INSERT INTO projects (username, name, topic, created_at, updated_at) VALUES (?,?,?,?,?)',
        [$owner, $course, 'testing', time(), time()]
    );
    $projectId = Db::lastId();

    $runId = Runs::reserve($owner, $projectId, null, 'page', [
        'mode' => Runs::MODE_BATCH,
        'provider' => 'anthropic',
        'ai_id' => 'a1',
        'model' => 'claude-opus-5',
    ], [['page_id' => random_int(100000, 999999), 'custom_id' => 'cf-page-' . $projectId, 'title' => 'A page']]);

    Runs::activate($runId, 'msgbatch_' . $projectId, 'in_progress', '{"results_url":"https://x/y"}', $expiresAt, $resultsExpireAt);
    return $runId;
}

test('a handle carries both deadlines back out of storage', static function (): void {
    $handle = BatchHandle::fromStorage('batch_1', 'in_progress', '{"input_file_id":"file_1"}', 1800000000, 1802592000);

    same(1800000000, $handle->expiresAt, 'when the batch dies');
    same(1802592000, $handle->resultsExpireAt, 'when the results go');
    same('file_1', $handle->refValue('input_file_id'), 'and the provider reference is intact');
});

test('a run stored before the second deadline existed reads as unknown, not as expired in 1970', static function (): void {
    $handle = BatchHandle::fromStorage('batch_1', '', '', 0, 0);

    same(null, $handle->expiresAt, 'no batch deadline');
    same(null, $handle->resultsExpireAt, 'no download deadline');
    ok(!$handle->dead(), 'an unknown deadline is not a dead batch');
    ok(!$handle->unreachable(), 'and not a deleted result set either');
});

test('dead and unreachable answer for their own deadline only', static function (): void {
    $now = 1800000000;
    $handle = new BatchHandle('batch_1', '', [], $now - 60, $now + 29 * DAY);

    ok($handle->dead($now), 'the window closed a minute ago');
    ok(!$handle->unreachable($now), 'but the answers are downloadable for another month');
    ok(!(new BatchHandle('b', '', [], $now + 60, $now + 29 * DAY))->dead($now), 'a minute still to run');
    ok((new BatchHandle('b', '', [], $now - DAY, $now - 60))->unreachable($now), 'past retention');
});

test('activate stores both deadlines and the summary reports them apart', static function (): void {
    $expires = time() + DAY;
    $retention = time() + 29 * DAY;
    $runId = queuedRun('alice', 'Deadlines one', $expires, $retention);

    $summary = Runs::summary(Runs::require('alice', $runId));
    same($expires, (int)$summary['expires_at'], 'the batch deadline');
    same($retention, (int)$summary['results_expire_at'], 'the download deadline');
    ok(
        (int)$summary['results_expire_at'] - (int)$summary['expires_at'] > 20 * DAY,
        'the two are weeks apart, which is the whole reason there are two of them'
    );
});

test('open runs are polled nearest download deadline first', static function (): void {
    $later = queuedRun('carol', 'Six weeks left', time() + DAY, time() + 42 * DAY);
    $sooner = queuedRun('carol', 'Two days left', time() + DAY, time() + 2 * DAY);

    $ids = array_map(static fn(array $run): int => (int)$run['id'], Runs::open('carol'));
    same([$sooner, $later], $ids, 'the scheduler stops part way down this list when it runs out of time');
});

test('a live run, which no provider is holding anything for, sorts after the batches', static function (): void {
    Db::run(
        'INSERT INTO projects (username, name, topic, created_at, updated_at) VALUES (?,?,?,?,?)',
        ['dave', 'A live run', 'testing', time(), time()]
    );
    $liveRun = Runs::reserve('dave', Db::lastId(), null, 'page', [
        'mode' => Runs::MODE_LIVE,
        'provider' => 'anthropic',
        'ai_id' => 'a1',
        'model' => 'claude-opus-5',
    ], [['page_id' => random_int(100000, 999999), 'custom_id' => 'cf-live-1', 'title' => 'A page']]);
    Runs::start($liveRun);

    $queued = queuedRun('dave', 'A queued run', time() + DAY, time() + 30 * DAY);

    same([$queued, $liveRun], array_map(static fn(array $r): int => (int)$r['id'], Runs::open('dave')), 'the order');
});

test('a run whose results the provider has already deleted is closed, not polled for ever', static function (): void {
    $runId = queuedRun('frank', 'Collected too late', time() - 40 * DAY, time() - DAY);

    // No provider is involved. Past the download deadline there is nothing left
    // to ask anybody about, which is why the decision is made before the
    // credentials are even loaded.
    $summary = BatchDriver::poll('frank', $runId);

    same(Runs::FAILED, (string)$summary['status'], 'the run ends here rather than being retried every minute');
    ok(str_contains((string)$summary['error'], 'deleted'), 'and says so: ' . $summary['error']);
    same(1, (int)$summary['pages']['failed'], 'its page is released rather than left pending for ever');
});

test('a run with no download deadline recorded is polled as it always was', static function (): void {
    $runId = queuedRun('gwen', 'An older run', time() + DAY, 0);

    // It reaches the provider lookup and fails there, on this run having no
    // profile - which is the point: an unknown deadline is not treated as one
    // that has passed.
    $summary = BatchDriver::poll('gwen', $runId);
    ok(!str_contains((string)$summary['error'], 'deleted'), 'not written off as expired: ' . $summary['error']);
    ok(str_contains((string)$summary['error'], 'profile'), 'it got as far as looking for the credentials');
});

test('the tools name both deadlines, and neither carries the other one\'s value', static function (): void {
    $expires = time() + DAY;
    $retention = time() + 30 * DAY;
    $runId = queuedRun('erin', 'Deadlines two', $expires, $retention);

    $answer = Tools::call(Actor::make('erin', 'Erin', Actor::ROLE_USER), 'list_runs', [])['data'] ?? [];
    $runs = array_values(array_filter(
        (array)($answer['runs'] ?? []),
        static fn(array $row): bool => (int)$row['run_id'] === $runId
    ));
    same(1, count($runs), 'the run erin just queued');

    same(gmdate('c', $expires), $runs[0]['batch_expires_at'] ?? null, 'when the batch dies');
    same(gmdate('c', $retention), $runs[0]['results_expire_at'] ?? null, 'when the results go');
    ok(
        $runs[0]['batch_expires_at'] !== $runs[0]['results_expire_at'],
        'reporting the batch deadline as the retention one is the defect these two names exist to stop'
    );
});
