<?php
/**
 * The findings of the 4.10 review, each held down by a test.
 *
 * Eleven readers went over the application with one instruction - find what
 * is actually wrong, not what could be tidier - and what they found was
 * reproduced before it was fixed. What is here is the smallest thing that
 * fails if a fix is undone. The order follows the damage: work that was lost,
 * money that was spent twice, answers that were wrong, and then the rest.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use CourseForge\Ai\AiRequest;
use CourseForge\Ai\Batch\BatchHandle;
use CourseForge\Ai\Batch\BatchItemResult;
use CourseForge\Ai\Batch\BatchStatus;
use CourseForge\Ai\Prompt;
use CourseForge\Ai\Provider\OpenAiCompatibleProvider;
use CourseForge\Ai\Provider\OpenAiProvider;
use CourseForge\Ai\Provider\Providers;
use CourseForge\Ai\Run\BatchDriver;
use CourseForge\Ai\Run\RunManager;
use CourseForge\Api\ProjectController;
use CourseForge\Domain\Profiles;
use CourseForge\Domain\Projects;
use CourseForge\Domain\Runs;
use CourseForge\Mcp\Tools;
use CourseForge\Publish\BookStackClient;
use CourseForge\Publish\TargetPublisher;
use CourseForge\Publish\Targets;
use CourseForge\Security\Actor;
use CourseForge\Support\Config;
use CourseForge\Support\Db;
use CourseForge\Support\HttpException;
use CourseForge\Support\Json;
use CourseForge\Support\Markdown;
use CourseForge\Support\Request;
use CourseForge\Support\Text;

/* ----------------------------------------------------------------- fixtures */

/** A course with one chapter and $n pages, owned by $owner. @return array{0:array<string,mixed>,1:int,2:int[]} */
function rfCourse(string $owner, int $n = 2, ?int $profileId = null): array
{
    Db::run(
        'INSERT INTO projects (username, profile_id, name, topic, book_title, book_desc, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?)',
        [$owner, $profileId, 'Review ' . random_int(1, 1_000_000), 'testing', 'A book', 'A description.', time(), time()]
    );
    $projectId = Db::lastId();
    Db::run('INSERT INTO chapters (project_id, idx, title, description) VALUES (?,0,?,?)',
        [$projectId, 'Getting started', 'The first chapter.']);
    $chapterId = Db::lastId();

    $pages = [];
    for ($i = 1; $i <= $n; $i++) {
        Db::run(
            'INSERT INTO pages (project_id, chapter_id, idx, title, content, status, updated_at) VALUES (?,?,?,?,?,?,?)',
            [$projectId, $chapterId, $i, 'Page ' . $i, '', 'pending', time()]
        );
        $pages[] = Db::lastId();
    }
    return [Projects::require($owner, $projectId), $chapterId, $pages];
}

/** A Request carrying a body and route parameters, the shape the front controller hands a handler. */
function rfRequest(string $method, array $body, array $params = []): Request
{
    $class = new ReflectionClass(Request::class);
    $request = $class->newInstanceWithoutConstructor();
    foreach (['method' => $method, 'path' => 'projects', 'body' => $body, 'params' => $params] as $key => $value) {
        $class->getProperty($key)->setValue($request, $value);
    }
    return $request;
}

/** A BookStack in memory whose shelf call can be made to fail. */
final class ReviewWiki extends BookStackClient
{
    public int $created = 0;
    public bool $shelfBroken = true;
    /** @var array<int,array<string,mixed>> */
    private array $books = [];
    private int $next = 0;

    public function __construct()
    {
        parent::__construct('https://wiki.test', 'id', 'secret');
    }

    protected function get(string $type, int $id): ?array
    {
        return $type === 'books' ? ($this->books[$id] ?? null) : null;
    }

    protected function call(string $method, string $path, mixed $payload = null): array
    {
        if ($method === 'POST' && $path === '/books') {
            $id = ++$this->next;
            $this->created++;
            return $this->books[$id] = ['id' => $id, 'slug' => 'book-' . $id, 'name' => (string)($payload['name'] ?? '')];
        }
        if ($method === 'PUT' && preg_match('#^/books/(\d+)$#', $path, $m) === 1) {
            return $this->books[(int)$m[1]] ?? [];
        }
        if (str_starts_with($path, '/shelves/')) {
            if ($this->shelfBroken) {
                throw HttpException::badRequest('BookStack GET ' . $path . ' failed (HTTP 404): Shelf not found');
            }
            return $method === 'GET' ? ['id' => 999, 'name' => 'Handbooks', 'books' => []] : [];
        }
        return [];
    }
}

/** A queue that answers a scripted sequence of states and hands over one finished page. */
final class ReviewQueue extends OpenAiCompatibleProvider
{
    public bool $cancelAsked = false;
    /** @var string[] */
    public array $states;

    /** @param string[] $states @param array<string,string> $answers custom id to text */
    public function __construct(array $states, private readonly array $answers, private readonly bool $cancellable = true)
    {
        parent::__construct(['base_url' => 'https://queue.test/v1', 'api_key' => 'k']);
        $this->states = $states;
    }

    public function label(): string
    {
        return 'The review queue';
    }

    public function pollBatch(BatchHandle $handle): BatchStatus
    {
        $state = count($this->states) > 1 ? array_shift($this->states) : $this->states[0];
        return new BatchStatus($state, $state, 2, count($this->answers), 0);
    }

    public function canCancel(): bool
    {
        return $this->cancellable;
    }

    public function cancelBatch(BatchHandle $handle): bool
    {
        $this->cancelAsked = true;
        return true;
    }

    public function fetchBatchResults(BatchHandle $handle): iterable
    {
        foreach ($this->answers as $customId => $text) {
            yield $customId => BatchItemResult::ok($customId, $text);
        }
    }

    public function releaseBatch(BatchHandle $handle): void
    {
    }
}

/** An open batch run over the pages of a course, as start() would have left it. */
function rfBatchRun(string $owner, int $projectId, int $profileId, array $pageIds): int
{
    $rows = [];
    foreach ($pageIds as $pageId) {
        $rows[] = ['page_id' => $pageId, 'custom_id' => RunManager::customId($pageId), 'title' => 'A page'];
    }
    $runId = Runs::reserve($owner, $projectId, $profileId, 'page', [
        'mode' => Runs::MODE_BATCH,
        'provider' => 'oai-compat',
        'ai_id' => 'rf-queue',
        'model' => 'a-model',
    ], $rows);
    Runs::activate($runId, 'batch_' . $runId, 'in_progress', '', time() + 86400, time() + 30 * 86400);
    foreach ($pageIds as $pageId) {
        Db::run('UPDATE pages SET status = ? WHERE id = ?', ['queued', $pageId]);
    }
    return $runId;
}

function rfPage(int $pageId): array
{
    return Db::row('SELECT status, content FROM pages WHERE id = ?', [$pageId]) ?? [];
}

function rfItem(int $runId, int $pageId): string
{
    $row = Db::row('SELECT status FROM batch_items WHERE job_id = ? AND custom_id = ?', [$runId, RunManager::customId($pageId)]);
    return (string)($row['status'] ?? '(no item)');
}

/* ------------------------------------------ stopping a batch keeps its answers */

test('stopping a batch keeps the run open until the provider hands over what it finished', static function (): void {
    $profile = Profiles::create('rf-stop', 'queue', array_replace(Profiles::defaults(), [
        'ai' => [['id' => 'rf-queue', 'name' => 'Queue', 'kind' => 'oai-compat', 'base_url' => 'https://queue.test/v1', 'api_key' => 'k']],
    ]));
    [$project, , $pages] = rfCourse('rf-stop', 2, (int)$profile['id']);
    $runId = rfBatchRun('rf-stop', (int)$project['id'], (int)$profile['id'], $pages);

    $queue = new ReviewQueue(
        [BatchStatus::RUNNING, BatchStatus::CANCELLING, BatchStatus::CANCELLED],
        [RunManager::customId($pages[0]) => 'The page the provider finished before it was stopped.']
    );
    Providers::$factory = static fn(array $account): ?ReviewQueue => ($account['id'] ?? '') === 'rf-queue' ? $queue : null;

    try {
        $answer = BatchDriver::cancel('rf-stop', $runId);
        ok($queue->cancelAsked, 'the provider was asked to stop');
        same(true, $answer['canceled'] ?? null, 'and the answer says so');
        ok(!Runs::isTerminal((string)Runs::require('rf-stop', $runId)['status']), 'but the run is not closed yet');
        same('queued', (string)rfPage($pages[0])['status'], 'the pages stay queued while the provider winds down');
        same('pending', rfItem($runId, $pages[0]), 'and nothing has been written off');

        BatchDriver::poll('rf-stop', $runId);   // cancelling
        ok(!Runs::isTerminal((string)Runs::require('rf-stop', $runId)['status']), 'still open while cancelling');

        BatchDriver::poll('rf-stop', $runId);   // cancelled, with one answer
        same(Runs::CANCELED, (string)Runs::require('rf-stop', $runId)['status'], 'the run ends as cancelled');
        same('The page the provider finished before it was stopped.', (string)rfPage($pages[0])['content'],
            'the page that was paid for is stored');
        same(Runs::ITEM_DONE, rfItem($runId, $pages[0]), 'and its item says so');
        same('canceled', rfItem($runId, $pages[1]), 'the page that never ran is the one that was stopped');
        same('pending', (string)rfPage($pages[1])['status'], 'and it goes back to pending, not into an error');
    } finally {
        Providers::$factory = null;
    }
});

test('a provider with no cancel route is not pretended to have one', static function (): void {
    $profile = Profiles::create('rf-nocancel', 'queue', array_replace(Profiles::defaults(), [
        'ai' => [['id' => 'rf-queue', 'name' => 'Queue', 'kind' => 'oai-compat', 'base_url' => 'https://queue.test/v1', 'api_key' => 'k']],
    ]));
    [$project, , $pages] = rfCourse('rf-nocancel', 1, (int)$profile['id']);
    $runId = rfBatchRun('rf-nocancel', (int)$project['id'], (int)$profile['id'], $pages);

    $queue = new ReviewQueue([BatchStatus::RUNNING], [], false);
    Providers::$factory = static fn(array $account): ?ReviewQueue => ($account['id'] ?? '') === 'rf-queue' ? $queue : null;

    try {
        $answer = BatchDriver::cancel('rf-nocancel', $runId);
        same(false, $answer['canceled'] ?? null, 'nothing was stopped');
        ok(str_contains((string)($answer['message'] ?? ''), 'no way to stop'), 'and the answer says why');
        ok(!Runs::isTerminal((string)Runs::require('rf-nocancel', $runId)['status']),
            'the run stays open so the pages are collected when they arrive');
        same('queued', (string)rfPage($pages[0])['status'], 'and the page is still queued, which is the truth');
    } finally {
        Providers::$factory = null;
    }
});

/* ------------------------------------------ a book is created exactly once */

test('a book created before its shelf failed is found again on the retry, not created twice', static function (): void {
    $profile = Profiles::create('rf-pub', 'one wiki', array_replace(Profiles::defaults(), [
        'bookstack' => [['id' => 'live', 'name' => 'Live', 'base_url' => 'https://wiki.test', 'token_id' => 'a', 'token_secret' => 'b']],
    ]));
    [$project] = rfCourse('rf-pub', 1, (int)$profile['id']);
    $projectId = (int)$project['id'];
    Targets::setPrimary('rf-pub', $projectId, 'live', ['shelf_id' => 999, 'shelf_name' => 'Gone']);

    $wiki = new ReviewWiki();
    $push = static function () use ($project, $projectId, $wiki): void {
        (new TargetPublisher($project, Targets::all($projectId)[0], $wiki))->push('book');
    };

    raises($push, 'the first push fails at the shelf');
    same(1, $wiki->created, 'one book was created');
    same(1, (int)Targets::all($projectId)[0]['book_id'], 'and the destination remembers it');

    raises($push, 'the second push fails at the same shelf');
    same(1, $wiki->created, 'without creating a second book');

    $wiki->shelfBroken = false;
    $push();
    same(1, $wiki->created, 'and once the shelf is back the same book is used');
    ok((string)Targets::all($projectId)[0]['pushed_hash'] !== '', 'and the push is recorded as complete');
});

/* ------------------------------------------ a save is not lost to a read */

test('reading the settings no longer writes them, and a 3.x document is reduced once', static function (): void {
    $file = Config::file();
    $original = is_file($file) ? (string)file_get_contents($file) : null;

    try {
        // A 4.x installation that has overridden one prompt: the shape that
        // used to be rewritten on every request. Written compactly, so that a
        // rewrite - which pretty-prints - cannot leave the bytes as they were.
        $before = (string)json_encode(['app' => ['name' => 'Locked name'], 'prompts' => ['global_system' => 'custom text']]);
        file_put_contents($file, $before);
        Config::flush();
        same('Locked name', (string)Config::get('app.name'), 'the override is read');
        same($before, (string)file_get_contents($file), 'and the file was not touched by reading it');

        // A whole 3.x document is a different matter, and is reduced.
        $full = Json::merge(Config::defaults(), ['app' => ['name' => 'Legacy name']]);
        unset($full['_comment'], $full['_note']);
        Json::write($file, $full);
        Config::flush();
        same('Legacy name', (string)Config::get('app.name'), 'a legacy document still reads');
        $reduced = Json::read($file) ?? [];
        same(['app' => ['name' => 'Legacy name']], $reduced, 'and is reduced on disk to the one thing it changed');
    } finally {
        if ($original === null) {
            @unlink($file);
        } else {
            file_put_contents($file, $original);
        }
        Config::flush();
    }
});

/* ------------------------------------------ the course editor asks before it forgets (server side) */

test('a course update refused for its shelf changes nothing at all', static function (): void {
    [$project] = rfCourse('rf-upd', 1);
    $projectId = (int)$project['id'];
    $actor = Actor::make('rf-upd', 'Updater', Actor::ROLE_USER);

    $e = raises(
        static fn(): array => ProjectController::update(
            rfRequest('PUT', ['name' => 'Renamed by a refused request', 'shelf_id' => 7], ['id' => (string)$projectId]),
            $actor
        ),
        'a shelf on a course with no destination'
    );
    ok($e instanceof HttpException && $e->status() === 422, 'is refused with 422');
    same((string)$project['name'], (string)Projects::require('rf-upd', $projectId)['name'], 'and the name is as it was');
});

/* ------------------------------------------ a profile with a run under it stays */

test('deleting a profile with an open run is refused, and allowed once the run is over', static function (): void {
    $profile = Profiles::create('rf-run', 'busy', Profiles::defaults());
    [$project, , $pages] = rfCourse('rf-run', 1, (int)$profile['id']);
    $runId = Runs::reserve('rf-run', (int)$project['id'], (int)$profile['id'], 'page', [
        'mode' => Runs::MODE_LIVE, 'provider' => 'anthropic', 'ai_id' => 'a1', 'model' => 'claude-opus-5',
    ], [['page_id' => $pages[0], 'custom_id' => RunManager::customId($pages[0]), 'title' => 'A page']]);
    Runs::start($runId);

    $e = raises(static fn() => Profiles::delete('rf-run', (int)$profile['id']), 'deleting a profile in use');
    ok(str_contains($e->getMessage(), 'still used by'), 'names the reason: ' . $e->getMessage());
    ok(Profiles::find('rf-run', (int)$profile['id']) !== null, 'and the profile is still there');

    RunManager::cancel('rf-run', $runId);
    Profiles::delete('rf-run', (int)$profile['id']);
    same(null, Profiles::find('rf-run', (int)$profile['id']), 'once the run is over it can go');
});

/* ------------------------------------------ the tools and the browser agree */

test('create_course refuses a profile that belongs to somebody else, even for an administrator', static function (): void {
    $theirs = Profiles::create('rf-bob', 'Bob\'s profile', Profiles::defaults());
    $admin = Actor::make('rf-admin', 'Admin', Actor::ROLE_ADMIN);

    $e = raises(
        static fn(): array => Tools::call($admin, 'create_course', ['name' => 'Borrowed', 'profile_id' => (int)$theirs['id']]),
        'a course under one account with a profile from another'
    );
    ok(str_contains($e->getMessage(), 'Profile not found'), 'is refused as the browser refuses it: ' . $e->getMessage());
    same(0, count(Projects::all('rf-admin')), 'and nothing was created');

    $own = Profiles::create('rf-admin', 'Own profile', Profiles::defaults());
    $made = (array)(Tools::call($admin, 'create_course', ['name' => 'Mine', 'profile_id' => (int)$own['id']])['data'] ?? []);
    same((int)$own['id'], (int)($made['profile_id'] ?? 0), 'while the administrator\'s own profile is taken');
});

test('update_profile adds the first account of a profile from a preset, with its extras and its name', static function (): void {
    $profile = Profiles::create('rf-empty', 'fresh from the browser', Profiles::defaults());
    $actor = Actor::make('rf-empty', 'Empty', Actor::ROLE_USER);

    Tools::call($actor, 'update_profile', [
        'profile_id' => (int)$profile['id'],
        'preset_key' => 'groq',
        'api_key' => 'gsk-test',
        'organization' => 'org-1',
        'ai_name' => 'Fast one',
        'bookstack_url' => 'https://docs.example.com/',
        'bookstack_token_id' => 'tok-1',
        'bookstack_token_secret' => 'sec-1',
        'bookstack_name' => 'Staging wiki',
    ]);

    $data = Profiles::data('rf-empty', (int)$profile['id']);
    $account = $data['ai'][0] ?? [];
    same('groq', (string)($account['preset_key'] ?? ''), 'the preset is the one that was named');
    same('https://api.groq.com/openai/v1', (string)($account['base_url'] ?? ''), 'with its endpoint');
    same('org-1', (string)($account['organization'] ?? ''), 'and its extras');
    same('Fast one', (string)($account['name'] ?? ''), 'and its name');
    same('Staging wiki', (string)($data['bookstack'][0]['name'] ?? ''), 'and the first instance is called what it was called');
});

/* ------------------------------------------ text is read the way it is written */

test('a longer fence keeps a shorter one inside it as code', static function (): void {
    $markdown = "````markdown\nWrite a fence like this:\n```\ninner example\n```\n````\nProse after it.\n";
    $segments = Markdown::segments($markdown);

    $inner = null;
    foreach ($segments as $segment) {
        if (str_contains($segment['text'], 'inner example')) {
            $inner = $segment;
        }
    }
    ok($inner !== null && $inner['code'] === true, 'the inner example is still inside the code block');
    $last = $segments[count($segments) - 1];
    ok($last['code'] === false && str_contains($last['text'], 'Prose after it.'), 'and the prose after the block is prose');
});

test('Chinese and Japanese count by character, everything with spaces by word', static function (): void {
    same(6, Text::words('这是一个句子'), 'six characters of Chinese are six words');
    same(8, Text::words('日本語のテキスト'), 'and so is Japanese');
    same(3, Text::words('한국어 문장 입니다'), 'Korean keeps its spaces');
    same(4, Text::words("It's a well-known fact"), 'and English is unchanged');
    same(4, Text::words('mixed 中文 words'), 'a mixed line counts both ways');
});

test('a placeholder inside a substituted value is left alone', static function (): void {
    $rendered = Prompt::render('Existing: {{existing_content}} Tags: {{tags}}', [
        'existing_content' => 'use `{{tags}}` in the template',
        'tags' => 'vue, templating',
    ]);
    same('Existing: use `{{tags}}` in the template Tags: vue, templating', $rendered, 'the value is not a template');
    same('Keep {{c1::this}}', Prompt::render('Keep {{c1::this}}', ['c1' => 'no']), 'and unknown braces stay');
});

/* ------------------------------------------ the providers */

test('gpt-5-chat-latest is a chat model and keeps its temperature', static function (): void {
    same(false, OpenAiProvider::isReasoning('gpt-5-chat-latest'), 'chat-latest is not a reasoning model');
    same(false, OpenAiProvider::isReasoning('gpt-5.1-chat-latest'), 'nor its versioned sibling');
    same(true, OpenAiProvider::isReasoning('gpt-5'), 'gpt-5 itself is');
    same(true, OpenAiProvider::isReasoning('gpt-5.2-mini'), 'and so is the rest of the family');
    same(true, OpenAiProvider::isReasoning('o3'), 'and the o-series');
    same(false, OpenAiProvider::isReasoning('gpt-4o'), 'while gpt-4o never was');

    $tuner = new class (['base_url' => 'https://api.openai.com/v1', 'api_key' => 'k']) extends OpenAiProvider {
        public function tune(array $payload, string $model): array
        {
            return $this->tuneForModel($payload, $model);
        }
    };
    $chat = $tuner->tune(['temperature' => 0.7, 'max_tokens' => 100], 'gpt-5-chat-latest');
    same(0.7, $chat['temperature'] ?? null, 'the temperature reaches the request');
    same(100, $chat['max_tokens'] ?? null, 'under the name the chat models take');

    $reasoning = $tuner->tune(['temperature' => 0.7, 'max_tokens' => 100], 'gpt-5');
    ok(!isset($reasoning['temperature']), 'a reasoning model still has it stripped');
    same(100, $reasoning['max_completion_tokens'] ?? null, 'and its ceiling renamed');
});

/* ------------------------------------------ the development router */

test('the development router refuses a private file however the path is spelled', static function (): void {
    $run = static function (string $uri): array {
        $_SERVER['REQUEST_URI'] = $uri;
        ob_start();
        $handled = (static fn(): mixed => require CF_ROOT . '/tools/router-dev.php')();
        return [$handled, (string)ob_get_clean()];
    };

    [$handled, $out] = $run('/x/../tools/detect-test.mjs');
    ok($handled === true, 'the request is answered by the router itself');
    ok(!str_contains($out, 'fence handling'), 'and the file behind the private directory is not in the answer');

    [$handled] = $run('/assets/favicon.svg');
    same(false, $handled, 'while an ordinary asset is left to the server');
    unset($_SERVER['REQUEST_URI']);
});
