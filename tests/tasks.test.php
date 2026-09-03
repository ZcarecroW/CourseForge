<?php
/**
 * Publishing as a task: written down, worked in slices, picked up again from
 * where it stopped.
 *
 * Pressing Publish used to hold one request open for the whole push and lose
 * it with the request. A task is the opposite promise, and every clause of it
 * is tested here against a wiki that can be told to fail on cue: a push that
 * breaks half way carries on from the page it stopped at rather than from the
 * start, a slice that runs out of time keeps its place, a wiki that fails does
 * not stop the others, a worker that dies hands the task back, and the log
 * outlives all of it.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use CourseForge\Domain\Profiles;
use CourseForge\Domain\Projects;
use CourseForge\Domain\Tasks;
use CourseForge\Publish\BookStackClient;
use CourseForge\Publish\PublishBudget;
use CourseForge\Publish\Publisher;
use CourseForge\Publish\TargetPublisher;
use CourseForge\Publish\Targets;
use CourseForge\Support\Config;
use CourseForge\Support\Db;
use CourseForge\Support\HttpException;
use CourseForge\Tasks\Runner;

/**
 * A wiki in memory that can be told to fail after a number of writes.
 *
 * Every create and update is counted, so a test can say not only that the
 * course arrived but that nothing arrived twice.
 */
final class TaskWiki extends BookStackClient
{
    /** @var array<string,array<int,array<string,mixed>>> */
    public array $store = ['books' => [], 'chapters' => [], 'pages' => []];
    public int $writes = 0;
    public int $pageCreates = 0;
    public int $pageUpdates = 0;
    /** Fail every write once this many have succeeded, until `heal()`. */
    public ?int $failAfter = null;
    public string $failure = 'BookStack POST /pages failed (HTTP 504): Gateway Timeout';
    private int $next = 100;

    public function __construct(public readonly string $label = 'wiki')
    {
        parent::__construct('https://' . $label . '.test', 'id', 'secret');
    }

    public function heal(): void
    {
        $this->failAfter = null;
    }

    protected function get(string $type, int $id): ?array
    {
        return $this->store[$type][$id] ?? null;
    }

    protected function call(string $method, string $path, mixed $payload = null): array
    {
        if ($this->failAfter !== null && $this->writes >= $this->failAfter) {
            throw HttpException::badRequest($this->failure);
        }
        $this->writes++;

        if ($method === 'POST' && preg_match('#^/(books|chapters|pages)$#', $path, $m) === 1) {
            $id = ++$this->next;
            $row = ['id' => $id, 'slug' => $m[1] . '-' . $id] + (array)$payload;
            $this->store[$m[1]][$id] = $row;
            if ($m[1] === 'pages') {
                $this->pageCreates++;
            }
            return $row;
        }
        if ($method === 'PUT' && preg_match('#^/(books|chapters|pages)/(\d+)$#', $path, $m) === 1) {
            $id = (int)$m[2];
            $this->store[$m[1]][$id] = array_merge($this->store[$m[1]][$id] ?? ['id' => $id, 'slug' => $m[1] . '-' . $id], (array)$payload);
            if ($m[1] === 'pages') {
                $this->pageUpdates++;
            }
            return $this->store[$m[1]][$id];
        }
        return [];
    }
}

/** The wikis by instance id, handed to the publisher instead of real clients. */
final class TaskWikis
{
    /** @var array<string,TaskWiki> */
    public static array $byInstance = [];

    public static function install(): void
    {
        Publisher::$clientFactory = static fn(array $credentials, string $instanceId): BookStackClient =>
            self::$byInstance[$instanceId] ?? throw HttpException::unprocessable('No wiki called ' . $instanceId);
    }

    public static function reset(): void
    {
        self::$byInstance = [];
    }
}

/** A profile with two BookStack instances, a course with two chapters of three written pages. */
function taskCourse(string $name, array $instances = ['alpha']): array
{
    TaskWikis::reset();
    $bookstack = [];
    foreach ($instances as $instance) {
        $bookstack[] = ['id' => $instance, 'name' => ucfirst($instance) . ' wiki', 'base_url' => 'https://' . $instance . '.test', 'token_id' => 'a', 'token_secret' => 'b'];
        TaskWikis::$byInstance[$instance] = new TaskWiki($instance);
    }
    $profile = Profiles::create('tasks', 'wikis ' . $name, ['bookstack' => $bookstack]);

    Db::run(
        'INSERT INTO projects (username, profile_id, name, topic, book_title, book_desc, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?)',
        ['tasks', (int)$profile['id'], $name, 'testing', $name, 'A description.', time(), time()]
    );
    $projectId = Db::lastId();

    $pageIds = [];
    foreach (['Beginnings', 'Endings'] as $ci => $chapterTitle) {
        Db::run('INSERT INTO chapters (project_id, idx, title, description) VALUES (?,?,?,?)', [$projectId, $ci, $chapterTitle, 'About ' . $chapterTitle]);
        $chapterId = Db::lastId();
        for ($pi = 0; $pi < 3; $pi++) {
            Db::run(
                'INSERT INTO pages (project_id, chapter_id, idx, title, content, status, updated_at) VALUES (?,?,?,?,?,?,?)',
                [$projectId, $chapterId, $pi, $chapterTitle . ' ' . ($pi + 1), 'Text of ' . $chapterTitle . ' ' . ($pi + 1) . '.', 'done', time()]
            );
            $pageIds[] = Db::lastId();
        }
    }

    Targets::replaceAll('tasks', $projectId, array_map(static fn(string $i): array => ['instance_id' => $i], $instances));
    return ['project' => Projects::require('tasks', $projectId), 'pages' => $pageIds, 'targets' => Targets::all($projectId)];
}

/** Works the queue with a generous budget, as a tick would. */
function tick(float $seconds = 30.0): array
{
    return Runner::work(microtime(true) + $seconds, 'test');
}

/** Pretends the wait a failed task was given has passed. */
function hurry(int $taskId): void
{
    Db::run('UPDATE tasks SET next_at = ? WHERE id = ?', [time() - 1, $taskId]);
}

TaskWikis::install();

/* --------------------------------------------------------------- the basics */

test('a publish task is written down, worked by the scheduler, and leaves a log the request never held', static function (): void {
    ['project' => $project, 'pages' => $pages] = taskCourse('plain');
    $projectId = (int)$project['id'];

    $task = Tasks::create('tasks', $projectId, Tasks::KIND_PUBLISH, ['scope' => 'all'], 'alice');
    same(Tasks::QUEUED, $task['status'], 'written down, nothing done yet');
    same(0, TaskWikis::$byInstance['alpha']->writes, 'and the wiki has not been contacted');

    $report = tick();
    same(1, $report['claimed'], 'the tick picked it up');
    same(1, $report['finished'], 'and finished it');

    $after = Tasks::summary(Tasks::require($task['id']));
    same(Tasks::DONE, $after['status'], 'the task is done');
    same('done', $after['progress']['targets'][(string)Targets::all($projectId)[0]['id']]['status'] ?? '', 'and says so per wiki');
    same(6, TaskWikis::$byInstance['alpha']->pageCreates, 'every page was created once');

    $tree = Projects::tree('tasks', $projectId);
    same(6, (int)$tree['stats']['pushed'], 'the course reports six pages published');

    $log = Tasks::logOf($task['id']);
    ok(count($log) >= 8, 'the log holds a line per item and more');
    $lines = array_map(static fn(array $l): string => $l['line'], $log);
    ok(in_array('Done.', $lines, true), 'and ends with Done');
    ok(array_filter($log, static fn(array $l): bool => $l['target_id'] !== null) !== [], 'lines carry the wiki they belong to');
});

test('a course with nowhere to publish is refused at the door rather than retried every minute', static function (): void {
    ['project' => $project] = taskCourse('nowhere');
    Targets::replaceAll('tasks', (int)$project['id'], []);

    $task = Tasks::create('tasks', (int)$project['id'], Tasks::KIND_PUBLISH, ['scope' => 'all'], 'alice');
    $report = tick();
    same(1, $report['failed'], 'it failed at once');

    $after = Tasks::summary(Tasks::require($task['id']));
    same(Tasks::FAILED, $after['status'], 'and is not queued again');
    ok(str_contains($after['error'], 'Choose a BookStack instance'), 'with the reason a person can act on');
});

/* -------------------------------------------------------------- resuming */

test('a wiki that fails half way is picked up again from the page it stopped at, never from the start', static function (): void {
    ['project' => $project] = taskCourse('halfway');
    $wiki = TaskWikis::$byInstance['alpha'];
    // Book, chapter one, three pages, chapter two, then the wiki goes down on
    // the sixth write - the first page of chapter two.
    $wiki->failAfter = 6;

    $task = Tasks::create('tasks', (int)$project['id'], Tasks::KIND_PUBLISH, ['scope' => 'all'], 'alice');
    $report = tick();
    same(1, $report['retried'], 'the slice ended in a retry');

    $between = Tasks::summary(Tasks::require($task['id']));
    same(Tasks::QUEUED, $between['status'], 'the task is back in the queue');
    same(1, $between['attempts'], 'counted as one attempt');
    ok($between['next_at'] > time(), 'and waits before the next');
    $state = $between['progress']['targets'][(string)Targets::all((int)$project['id'])[0]['id']];
    same('failed', $state['status'], 'the wiki is recorded as failed');
    same('items', $state['work']['phase'], 'in the middle of the items');
    ok(str_contains((string)$state['error'], '504'), 'with the wiki\'s own words');
    same(3, $wiki->pageCreates, 'three pages had arrived');

    $wiki->heal();
    hurry($task['id']);
    $report = tick();
    same(1, $report['finished'], 'the next attempt finishes it');
    same(6, $wiki->pageCreates, 'the other three pages were created - and none of the first three again');
    same(0, $wiki->pageUpdates, 'nor rewritten');

    $lines = array_map(static fn(array $l): string => $l['line'], Tasks::logOf($task['id']));
    ok(count(array_filter($lines, static fn(string $l): bool => str_starts_with($l, 'Trying'))) === 1, 'the log says it tried again from where it stopped');
    ok(count(array_filter($lines, static fn(string $l): bool => str_contains($l, 'Will try again'))) === 1, 'and said so when it gave the wiki up for the moment');
});

test('a slice that runs out of time keeps its place, and the next one carries on', static function (): void {
    ['project' => $project] = taskCourse('slices');
    $wiki = TaskWikis::$byInstance['alpha'];
    $target = Targets::all((int)$project['id'])[0];

    // A deadline that has already passed: the book goes out - the budget is
    // asked between items, never inside one - and then the slice stops.
    $publisher = new TargetPublisher($project, $target, $wiki);
    $first = $publisher->push('all', null, false, [], new PublishBudget(microtime(true) - 1));
    same(false, $first['done'], 'the first slice is not finished');
    same('items', $first['state']['phase'], 'it stopped among the items');
    same(1, count($wiki->store['books']), 'after making the book');
    same(0, $wiki->pageCreates, 'and before any page');

    $second = (new TargetPublisher($project, Targets::all((int)$project['id'])[0], $wiki))
        ->push('all', null, false, $first['state'], PublishBudget::unlimited());
    same(true, $second['done'], 'the second slice finishes');
    same(1, count($wiki->store['books']), 'without a second book');
    same(6, $wiki->pageCreates, 'and every page once');
    same('done', $second['state']['phase'], 'and reports the pass complete');
});

test('a resume skips the chapter and the pages it had already written', static function (): void {
    ['project' => $project, 'pages' => $pages] = taskCourse('cursor');
    $wiki = TaskWikis::$byInstance['alpha'];
    $target = Targets::all((int)$project['id'])[0];

    // Straight through once, to learn the chapter ids the cursor will name.
    $full = (new TargetPublisher($project, $target, $wiki))->push('all', null, false, [], PublishBudget::unlimited());
    same(true, $full['done'], 'a plain push finishes');
    $writesAfterFull = $wiki->writes;

    // Now pretend a slice stopped after the second page of chapter one, and
    // that the pages after it changed meanwhile - so a resume that revisited
    // page one or two would show up as an update.
    $chapterOne = (int)Db::row('SELECT id FROM chapters WHERE project_id = ? ORDER BY idx', [(int)$project['id']])['id'];
    $chapterBs = (int)Targets::item((int)$target['id'], 'chapter', $chapterOne)['bs_id'];
    foreach ($pages as $pageId) {
        Db::run('UPDATE pages SET content = ? WHERE id = ?', ['Rewritten ' . $pageId, $pageId]);
    }
    $state = ['phase' => 'items', 'chapter_id' => $chapterOne, 'chapter_bs_id' => $chapterBs, 'page_id' => $pages[1]];
    $resumed = (new TargetPublisher($project, Targets::all((int)$project['id'])[0], $wiki))
        ->push('all', null, false, $state, PublishBudget::unlimited());

    same(true, $resumed['done'], 'the resume finishes');
    // Four pages come after the cursor: one in chapter one, three in chapter
    // two. Each is an update; chapter two itself is unchanged and costs a
    // read, not a write.
    same(4, $wiki->pageUpdates, 'exactly the pages after the cursor were written');
    same($writesAfterFull + 4, $wiki->writes, 'and nothing before the cursor');
});

/* ------------------------------------------------------------- several wikis */

test('one wiki failing does not stop the other, and only the failed one is tried again', static function (): void {
    ['project' => $project] = taskCourse('pair', ['alpha', 'beta']);
    $beta = TaskWikis::$byInstance['beta'];
    $beta->failAfter = 0;
    $beta->failure = 'BookStack POST /books failed (HTTP 503): Service Unavailable';
    Config::set('app.task_max_attempts', 2);

    try {
        $task = Tasks::create('tasks', (int)$project['id'], Tasks::KIND_PUBLISH, ['scope' => 'all'], 'alice');
        $report = tick();
        same(1, $report['retried'], 'the first attempt is retried');
        same(6, TaskWikis::$byInstance['alpha']->pageCreates, 'alpha got the whole course');
        same(0, $beta->pageCreates, 'beta got nothing');

        $alphaWrites = TaskWikis::$byInstance['alpha']->writes;
        hurry($task['id']);
        $report = tick();
        same(1, $report['failed'], 'the second attempt was the last one allowed, and beta was still down');

        $after = Tasks::summary(Tasks::require($task['id']));
        same(Tasks::FAILED, $after['status'], 'so the task is given up on');
        ok(str_contains($after['error'], 'Beta wiki'), 'naming the wiki that failed');
        same($alphaWrites, TaskWikis::$byInstance['alpha']->writes, 'and alpha was not touched again');
        $targets = Targets::all((int)$project['id']);
        same('done', $after['progress']['targets'][(string)$targets[0]['id']]['status'], 'alpha stands as done');
        same('failed', $after['progress']['targets'][(string)$targets[1]['id']]['status'], 'beta as failed');

        // A person presses Retry: the counter starts again, and only beta is left to do.
        $beta->heal();
        ok(Tasks::requeue($task['id']), 'a failed task can be retried by hand');
        $report = tick();
        same(1, $report['finished'], 'and this time it finishes');
        same(6, $beta->pageCreates, 'beta now holds the course');
        same($alphaWrites, TaskWikis::$byInstance['alpha']->writes, 'alpha still untouched');
    } finally {
        Config::reset('app.task_max_attempts');
    }
});

test('a link pass is a task of its own and resolves into each wiki separately', static function (): void {
    ['project' => $project, 'pages' => $pages] = taskCourse('links', ['alpha', 'beta']);
    Db::run('UPDATE pages SET content = ? WHERE id = ?', ['See (🔗 Endings 2) for more.', $pages[0]]);

    Tasks::create('tasks', (int)$project['id'], Tasks::KIND_PUBLISH, ['scope' => 'chapter', 'item_id' => (int)Db::row('SELECT id FROM chapters WHERE project_id = ? ORDER BY idx', [(int)$project['id']])['id']], 'alice');
    tick();
    $task = Tasks::create('tasks', (int)$project['id'], Tasks::KIND_LINKS, [], 'alice');
    $report = tick();
    same(1, $report['finished'], 'the link pass finished');

    $after = Tasks::summary(Tasks::require($task['id']));
    same(Tasks::DONE, $after['status'], 'as done');
    $lines = array_map(static fn(array $l): string => $l['line'], Tasks::logOf($task['id']));
    same(2, count(array_filter($lines, static fn(string $l): bool => str_starts_with($l, 'Resolving links in'))), 'once per wiki');
});

/* ------------------------------------------------------------ the machinery */

test('a course never has two of its tasks worked at once', static function (): void {
    ['project' => $project] = taskCourse('serial');
    $first = Tasks::create('tasks', (int)$project['id'], Tasks::KIND_PUBLISH, ['scope' => 'book'], 'alice');
    $second = Tasks::create('tasks', (int)$project['id'], Tasks::KIND_LINKS, [], 'alice');

    $claimed = Tasks::claimNext('owner-a', 60);
    same($first['id'], (int)$claimed['id'], 'the older task is claimed first');
    same(null, Tasks::claimNext('owner-b', 60), 'and the other waits while its course is being worked');
    same(null, Tasks::claim($second['id'], 'owner-b', 60), 'even when asked for by name');

    ok(Tasks::finish($first['id'], 'owner-a', [], Tasks::DONE), 'the first finishes');
    $next = Tasks::claimNext('owner-b', 60);
    same($second['id'], (int)$next['id'], 'and the second becomes claimable');
    Tasks::finish($second['id'], 'owner-b', [], Tasks::DONE);
});

test('a stopped task is taken away from its worker at the next renew', static function (): void {
    ['project' => $project] = taskCourse('stop');
    $task = Tasks::create('tasks', (int)$project['id'], Tasks::KIND_PUBLISH, ['scope' => 'all'], 'alice');

    $claimed = Tasks::claim($task['id'], 'worker', 60);
    ok($claimed !== null, 'a worker holds it');
    ok(Tasks::renew($task['id'], 'worker', 60), 'and can renew');
    ok(Tasks::cancel($task['id']), 'somebody stops it');
    same(false, Tasks::renew($task['id'], 'worker', 60), 'the worker finds out at its next renew');
    same(false, Tasks::pause($task['id'], 'worker', []), 'and cannot put it back');
    same(Tasks::CANCELED, Tasks::summary(Tasks::require($task['id']))['status'], 'it stays stopped');

    $budget = new PublishBudget(null, static fn(): bool => Tasks::renew($task['id'], 'worker', 60), 0.0);
    same(true, $budget->exhausted(), 'a budget that asks sees the loss');
    same(true, $budget->lost(), 'and knows it was a loss rather than the clock');
});

test('a task whose worker died is given back to the queue with its place kept', static function (): void {
    ['project' => $project] = taskCourse('dead');
    $task = Tasks::create('tasks', (int)$project['id'], Tasks::KIND_PUBLISH, ['scope' => 'all'], 'alice');
    Tasks::claim($task['id'], 'ghost', 60);
    Db::run('UPDATE tasks SET lease_until = ?, progress = ? WHERE id = ?', [time() - 5, '{"targets":{"1":{"status":"partial"}}}', $task['id']]);

    same(1, Tasks::recover(), 'the sweep finds it');
    $after = Tasks::summary(Tasks::require($task['id']));
    same(Tasks::QUEUED, $after['status'], 'and queues it again');
    same(1, $after['attempts'], 'counting the lost slice');
    same('partial', $after['progress']['targets']['1']['status'] ?? '', 'with what it had achieved kept');
    ok(array_filter(Tasks::logOf($task['id']), static fn(array $l): bool => str_contains($l['line'], 'stopped answering')) !== [], 'and a line saying why');

    // The tick then finishes it as if nothing had happened.
    $report = tick();
    same(1, $report['finished'], 'the next tick completes it');
});

test('the browser can work a slice itself, but never beside the scheduler', static function (): void {
    ['project' => $project] = taskCourse('pump');
    $task = Tasks::create('tasks', (int)$project['id'], Tasks::KIND_PUBLISH, ['scope' => 'all'], 'alice');

    Tasks::claim($task['id'], 'cron', 60);
    $busy = Runner::runOne($task['id'], microtime(true) + 10, 'browser');
    same(false, $busy['ran'], 'while a tick holds the task the browser is refused');
    same('busy', $busy['outcome'], 'and told why');
    Tasks::pause($task['id'], 'cron', []);

    $ran = Runner::runOne($task['id'], microtime(true) + 30, 'browser');
    same(true, $ran['ran'], 'once it is free the browser works it');
    same('finished', $ran['outcome'], 'to the end, with this much time');
    same(Tasks::DONE, $ran['task']['status'], 'and the task is done');
    same(6, TaskWikis::$byInstance['alpha']->pageCreates, 'the course arrived');
});

test('the failure delay grows with the attempts and stops at a quarter of an hour', static function (): void {
    same(30, Tasks::backoff(1), 'half a minute first');
    same(60, Tasks::backoff(2), 'then a minute');
    same(900, Tasks::backoff(6), 'a quarter of an hour by the sixth');
    same(900, Tasks::backoff(40), 'and never more');
});

test('finished tasks are pruned per course, and the newest are the ones kept', static function (): void {
    ['project' => $project] = taskCourse('prune');
    for ($i = 0; $i < Tasks::KEEP_PER_PROJECT + 5; $i++) {
        $task = Tasks::create('tasks', (int)$project['id'], Tasks::KIND_PUBLISH, ['scope' => 'book'], 'alice');
        Db::run('UPDATE tasks SET status = ?, finished_at = ?, created_at = ? WHERE id = ?', [Tasks::DONE, time(), time() - 1000 + $i, $task['id']]);
    }
    $latest = Tasks::create('tasks', (int)$project['id'], Tasks::KIND_PUBLISH, ['scope' => 'book'], 'alice');
    $rows = Db::rows('SELECT id, status FROM tasks WHERE project_id = ? ORDER BY id', [(int)$project['id']]);
    same(Tasks::KEEP_PER_PROJECT + 1, count($rows), 'the oldest finished ones were forgotten, the open one kept');
    same($latest['id'], (int)end($rows)['id'], 'and the newest is the last');
    Tasks::cancel($latest['id']);
});

Publisher::$clientFactory = null;
TaskWikis::reset();
