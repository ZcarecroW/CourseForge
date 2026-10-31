<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * What a page is told about the rest of the course, and what that costs.
 *
 * Three promises, each of which fails silently when it breaks:
 *
 *   - a page knows what every other page teaches: the outline carries a scope
 *     per page, the scope survives every road an outline travels (apply,
 *     rename, rebuild), and every page brief carries every page's scope;
 *   - the part of the prompt pages share is byte-identical between them, so a
 *     provider's prompt cache can serve it - one changed byte and the cache
 *     misses on every page, which nothing reports except the bill;
 *   - the shared part reaches each provider in the shape its cache needs, and
 *     a request that is sent once is not marked for a cache it will never read.
 */

use CourseForge\Ai\AiRequest;
use CourseForge\Ai\CourseContext;
use CourseForge\Ai\PageGenerator;
use CourseForge\Ai\Provider\AnthropicProvider;
use CourseForge\Ai\Provider\GeminiProvider;
use CourseForge\Ai\Provider\OpenAiCompatibleProvider;
use CourseForge\Ai\Provider\OpenAiProvider;
use CourseForge\Ai\Provider\OpenRouterProvider;
use CourseForge\Ai\ScopePlanner;
use CourseForge\Domain\Pages;
use CourseForge\Domain\Profiles;
use CourseForge\Domain\Projects;
use CourseForge\Domain\Structure;
use CourseForge\Domain\Tags;
use CourseForge\Mcp\Tools;
use CourseForge\Security\Actor;
use CourseForge\Support\Db;

const SCOPED_OUTLINE = "# Vue from the ground up\n\n"
    . "A course for developers who know JavaScript and want to build real applications with Vue.\n\n"
    . "1. Getting started\n"
    . "   GOAL-ONE: install the toolchain, scaffold a project and read every file in it.\n"
    . "   1. Installing Node and the CLI {{Tooling}}\n"
    . "      Node LTS; npm; create-vue; the dev server\n"
    . "   2. The project layout {{Tooling, Structure}}\n"
    . "      src and public; main.js; single-file components\n"
    . "2. Reactivity\n"
    . "   GOAL-TWO: hold state that the page follows by itself.\n"
    . "   1. Reactive state with ref and reactive {{Reactivity}}\n"
    . "      ref(); reactive(); when to use which; unwrapping in templates\n"
    . "   2. Computed values and watchers {{Reactivity}}\n"
    . "      computed(); caching; watch(); watchEffect()\n";

/** @return array{project:array<string,mixed>,profile:array<string,mixed>,pages:array<int,array<string,mixed>>} */
function scopedCourse(string $owner, string $outline = SCOPED_OUTLINE): array
{
    $profile = Profiles::create($owner, 'p-' . $owner, Profiles::defaults());
    $project = Projects::create($owner, 'Vue', 'Vue 3 for JavaScript developers; IDE: PhpStorm', (int)$profile['id']);
    Projects::update($owner, (int)$project['id'], ['auto_tags' => 1]);
    $project = Projects::require($owner, (int)$project['id']);
    Projects::applyStructure($project, $outline);
    $project = Projects::require($owner, (int)$project['id']);

    return ['project' => $project, 'profile' => $profile, 'pages' => Pages::outline((int)$project['id'])];
}

/** @return array<string,mixed> */
function payloadOf(object $provider, AiRequest $request, bool $queued = false): array
{
    $m = new ReflectionMethod($provider, 'payload');
    return $provider instanceof AnthropicProvider ? $m->invoke($provider, $request, $queued) : $m->invoke($provider, $request);
}

/* ----------------------------------------------------------- the outline */

test('a scope written by toMarkdown reads back as the same scope', static function (): void {
    $chapters = [[
        'title' => 'Chapter',
        'description' => 'What it is for.',
        'tags' => [],
        'pages' => [
            ['title' => 'Plain', 'tags' => ['A'], 'scope' => 'one; two; three'],
            ['title' => 'Looks like a list', 'tags' => [], 'scope' => '2. steps of the build; the cache'],
            ['title' => 'No scope', 'tags' => [], 'scope' => ''],
        ],
    ]];

    $parsed = Structure::parse(Structure::toMarkdown('Book', 'About it.', $chapters));
    same(
        ['one; two; three', '2. steps of the build; the cache', ''],
        array_column($parsed['chapters'][0]['pages'], 'scope'),
        'every scope, including one that starts like a list item, survives the round trip'
    );
    same(['Plain', 'Looks like a list', 'No scope'], array_column($parsed['chapters'][0]['pages'], 'title'),
        'and no scope line became a page');
});

test('applying an outline stores each page scope, and the stored outline keeps them through a rename', static function (): void {
    ['project' => $project, 'pages' => $pages] = scopedCourse('ctx-apply');
    same('ref(); reactive(); when to use which; unwrapping in templates', (string)$pages[2]['scope'],
        'the scope under a page is stored on that page');

    // A rename goes through resyncStructure, which rebuilds the outline from
    // the rows - and a scope it forgot would be read as removed on the next apply.
    Pages::update((int)$pages[0]['id'], ['title' => 'Installing Node']);
    Projects::resyncStructure('ctx-apply', (int)$project['id']);
    $project = Projects::require('ctx-apply', (int)$project['id']);

    $reparsed = Structure::parse((string)$project['structure_md']);
    same('Node LTS; npm; create-vue; the dev server', $reparsed['chapters'][0]['pages'][0]['scope'],
        'the rebuilt outline still carries the scope');

    Projects::applyStructure($project, (string)$project['structure_md']);
    same('Node LTS; npm; create-vue; the dev server', (string)Pages::outline((int)$project['id'])[0]['scope'],
        'and applying it again changes nothing');
});

/* ------------------------------------------------------------ the brief */

test('every page brief carries every page and its scope, and none of the other chapters\' descriptions', static function (): void {
    ['project' => $project, 'profile' => $profile, 'pages' => $pages] = scopedCourse('ctx-map');

    $plan = PageGenerator::plan($profile, $project, (int)$pages[0]['id']);
    $course = $plan['shared']['course'];

    foreach ($pages as $page) {
        ok(str_contains($course, CourseContext::ref($page) . ' ' . $page['title']), 'the map names ' . $page['title']);
        ok(str_contains($course, (string)$page['scope']), 'with its scope');
    }
    ok(str_contains($course, 'IDE: PhpStorm'), 'the brief the course was designed from is part of it');
    same(false, str_contains($course, 'GOAL-TWO'), 'a page in chapter 1 is not sent chapter 2\'s description');
    ok(str_contains($plan['shared']['chapter'], 'GOAL-ONE'), 'only its own chapter\'s, in the chapter segment');
    ok(str_contains($plan['user'], 'What this page alone teaches: Node LTS'), 'the brief names the page\'s own scope');
    ok(str_contains($plan['user'], '1.2 "The project layout"'), 'and the page after it, by number');
});

test('the shared part of the prompt is byte-identical where pages share it', static function (): void {
    ['project' => $project, 'profile' => $profile, 'pages' => $pages] = scopedCourse('ctx-cache');

    $a = PageGenerator::plan($profile, $project, (int)$pages[0]['id']);
    $b = PageGenerator::plan($profile, $project, (int)$pages[1]['id']);
    $c = PageGenerator::plan($profile, $project, (int)$pages[2]['id']);

    same($a['shared']['course'], $c['shared']['course'], 'the course segment is the same in two chapters');
    same($a['shared']['chapter'], $b['shared']['chapter'], 'the chapter segment is the same within a chapter');
    ok($a['shared']['chapter'] !== $c['shared']['chapter'], 'and different across chapters');

    // The tags differ between these two pages. They used to be part of the
    // system text, which made it different for every page with tagging on.
    ok(Tags::names(Tags::resolved((int)$project['id'])['effective']['page'][(int)$pages[0]['id']] ?? [])
        !== Tags::names(Tags::resolved((int)$project['id'])['effective']['page'][(int)$pages[1]['id']] ?? []),
        'the two pages really do carry different tags');
    same($a['system'], $b['system'], 'and still share the system text');
    ok(str_contains($b['user'], 'Keywords: Structure, Tooling'), 'the tags travel in the page brief instead');
});

test('a page sees the sections its written neighbours actually have', static function (): void {
    ['project' => $project, 'profile' => $profile, 'pages' => $pages] = scopedCourse('ctx-written');

    $before = PageGenerator::plan($profile, $project, (int)$pages[1]['id']);
    same(false, str_contains($before['user'], 'Already written'), 'nothing written yet, so nothing is said about it');

    Pages::update((int)$pages[0]['id'], [
        'content' => "Intro.\n\n## Installing Node\n\nText.\n\n```bash\n# not a heading\nnode -v\n```\n\n### Checking the version\n\n## Summary\n",
        'status' => 'generated',
    ]);
    $after = PageGenerator::plan($profile, $project, (int)$pages[1]['id']);
    ok(str_contains($after['user'], '- 1.1 "Installing Node and the CLI": Installing Node; Checking the version; Summary'),
        'the neighbour is listed with its headings, and a comment inside a code fence is not one');
    same($before['shared'], $after['shared'], 'and the shared part did not change because a page was written');

    $otherChapter = PageGenerator::plan($profile, $project, (int)$pages[2]['id']);
    same(false, str_contains($otherChapter['user'], 'Already written'), 'a page in another chapter is not told');
});

test('the brief does not read the text of every page to write one', static function (): void {
    ['project' => $project, 'profile' => $profile, 'pages' => $pages] = scopedCourse('ctx-light');
    // A sentinel in a page of the other chapter: if plan() quoted or digested
    // it, the light outline query would not be what the brief was built from.
    Pages::update((int)$pages[3]['id'], ['content' => "## SENTINEL-HEADING\n\nSENTINEL-TEXT", 'status' => 'generated']);

    $plan = PageGenerator::plan($profile, $project, (int)$pages[0]['id']);
    $all = implode("\n", $plan['context']) . $plan['system'] . $plan['user'];
    same(false, str_contains($all, 'SENTINEL'), 'nothing from a page in another chapter is in the prompt');
    same(false, array_key_exists('content', Pages::outline((int)$project['id'])[0]), 'and the outline rows carry no text');
});

test('a customised brief that still quotes the chapter goal does not get it twice', static function (): void {
    ['project' => $project, 'profile' => $profile, 'pages' => $pages] = scopedCourse('ctx-custom');
    $profile['prompts'] = ['page_user' => "Write {{page_title}}.\nChapter goal: {{chapter_description}}"];

    $plan = PageGenerator::plan($profile, $project, (int)$pages[0]['id']);
    same('', $plan['shared']['chapter'], 'the chapter segment steps aside');
    same(1, substr_count(implode("\n", $plan['context']) . $plan['user'], 'GOAL-ONE'), 'and the goal is sent once');
});

/* ------------------------------------------------------- the providers */

test('Anthropic caches behind each shared part, and marks nothing on a request sent once', static function (): void {
    $shared = [str_repeat('course map ', 400), str_repeat('chapter goal ', 200)];
    $page = new AiRequest('claude-opus-5-5', str_repeat('rules ', 400), 'brief', 0.7, 4000, false, 0, $shared);
    $once = new AiRequest('claude-opus-5-5', str_repeat('rules ', 400), 'design an outline');

    $system = payloadOf(new AnthropicProvider(['api_key' => 'k']), $page)['system'];
    same(3, count($system), 'course, chapter and system text are three blocks');
    same([true, true, true], array_map(static fn(array $b): bool => isset($b['cache_control']), $system),
        'with a breakpoint behind each');
    same('brief', payloadOf(new AnthropicProvider(['api_key' => 'k']), $page)['messages'][0]['content'],
        'and the page brief behind the last one');

    $queued = payloadOf(new AnthropicProvider(['api_key' => 'k']), $page, true)['system'];
    same('1h', $queued[0]['cache_control']['ttl'] ?? '', 'a queued page keeps its entry for the hour a batch takes');

    same(true, is_string(payloadOf(new AnthropicProvider(['api_key' => 'k']), $once)['system']),
        'a one-off request has no breakpoint, because a write it never reads is a quarter more on the bill');
});

test('OpenAI is told where the shared part ends on the models that bill cache writes', static function (): void {
    $shared = ['the course map', 'the chapter goal'];
    $provider = new OpenAiProvider(['api_key' => 'k']);

    $new = payloadOf($provider, new AiRequest('gpt-6.1-sol', 'rules', 'brief', 0.7, 4000, false, 0, $shared));
    same(['mode' => 'explicit'], $new['prompt_cache_options'] ?? null, 'explicit caching on gpt-6.1');
    same(3, count($new['messages'][0]['content']), 'the system message is the shared parts and the rules');
    same(['mode' => 'explicit'], $new['messages'][0]['content'][0]['prompt_cache_breakpoint'] ?? null,
        'each with a cache point');
    ok(str_starts_with((string)($new['prompt_cache_key'] ?? ''), 'cf-'), 'and a key that keeps the course together');
    ok(isset($new['max_completion_tokens']) && !isset($new['temperature']),
        'gpt-6 is a reasoning model: max_completion_tokens, and no temperature');

    $old = payloadOf($provider, new AiRequest('gpt-5.5', 'rules', 'brief', 0.7, 4000, false, 0, $shared));
    same(false, isset($old['prompt_cache_options']), 'gpt-5.5 caches implicitly and takes no options');
    same(true, is_string($old['messages'][0]['content']), 'so its system message stays one string');
    same(true, str_starts_with($old['messages'][0]['content'], 'the course map'), 'with the shared part first');

    $gateway = new OpenAiProvider(['api_key' => 'k', 'base_url' => 'https://gateway.example/v1']);
    $copy = payloadOf($gateway, new AiRequest('gpt-6.1-sol', 'rules', 'brief', 0.7, 4000, false, 0, $shared));
    same(false, isset($copy['prompt_cache_key']) || isset($copy['prompt_cache_options']),
        'a gateway that only copies the API is not sent fields it may refuse');
});

test('GPT-6 models are offered and recognised', static function (): void {
    foreach (['gpt-6-sol', 'gpt-6-luna', 'gpt-6-astra', 'gpt-6.1-terra', 'gpt-6.1-sol'] as $model) {
        same(true, OpenAiProvider::isReasoning($model), $model . ' is a reasoning model');
        $chat = new ReflectionMethod(OpenAiProvider::class, 'isChatModel');
        same(true, $chat->invoke(null, $model), $model . ' is offered in the model picker');
    }
    same(false, OpenAiProvider::isReasoning('gpt-4o'), 'gpt-4o still is not');
    same(true, OpenAiProvider::cachesExplicitly('gpt-5.6-sol'), 'gpt-5.6 is the first that bills cache writes');
    same(false, OpenAiProvider::cachesExplicitly('gpt-5.1-chat-latest'), 'and gpt-5.1 does not');
});

test('the other providers get the shared part first, where an automatic cache looks for it', static function (): void {
    $shared = ['the course map', 'the chapter goal'];
    $request = new AiRequest('m', 'rules', 'brief', 0.7, 0, false, 0, $shared);

    $gemini = payloadOf(new GeminiProvider(['api_key' => 'k']), new AiRequest('gemini-4-argon', 'rules', 'brief', 0.7, 0, false, 0, $shared));
    same(['the course map', 'the chapter goal', 'rules'], array_column($gemini['systemInstruction']['parts'], 'text'),
        'Gemini: the shared parts open the system instruction');

    $compatible = payloadOf(new OpenAiCompatibleProvider(['api_key' => 'k', 'base_url' => 'https://api.groq.com/openai/v1']), $request);
    same("the course map\n\nthe chapter goal\n\nrules", $compatible['messages'][0]['content'],
        'a compatible gateway: one system string, shared part first');

    $claude = payloadOf(new OpenRouterProvider(['api_key' => 'k']), new AiRequest('anthropic/claude-opus-5-5', 'rules', 'brief', 0.7, 0, false, 0, $shared));
    same(['type' => 'ephemeral'], $claude['messages'][0]['content'][0]['cache_control'] ?? null,
        'OpenRouter marks the shared part for Claude, which caches nothing unmarked');
    $deepseek = payloadOf(new OpenRouterProvider(['api_key' => 'k']), new AiRequest('deepseek/deepseek-v4', 'rules', 'brief', 0.7, 0, false, 0, $shared));
    same(true, is_string($deepseek['messages'][0]['content']), 'and leaves a self-caching upstream a plain string');
    ok(($deepseek['prompt_cache_key'] ?? '') !== '', 'with the key that keeps it on one provider');
});

test('Claude is asked on API version 2026-10-21 and never sent a sampling parameter', static function (): void {
    $provider = new AnthropicProvider(['api_key' => 'k']);
    $headers = (new ReflectionMethod($provider, 'headers'))->invoke($provider);
    same('2026-10-21', $headers['anthropic-version'] ?? '', 'the version every endpoint is asked under');

    // Every model, live and queued: on this version any of the three is a 400,
    // and a queued one would only say so a day later.
    foreach (['claude-opus-5-5', 'claude-haiku-4-5', 'claude-sonnet-4-6', 'a-model-nobody-listed'] as $model) {
        foreach ([false, true] as $queued) {
            $body = payloadOf($provider, new AiRequest($model, 'sys', 'usr', 0.3, 1000), $queued);
            same([], array_values(array_intersect(['temperature', 'top_p', 'top_k'], array_keys($body))),
                $model . ($queued ? ' queued' : ' live') . ' carries no sampling parameter');
        }
    }
});

test('a request keeps its shared context through withModel', static function (): void {
    $request = (new AiRequest('m:batch', 's', 'u', 0.7, 0, false, 0, ['course', 'chapter']))->withModel('m');
    same(['course', 'chapter'], $request->context, 'the queued path is the one that calls it');
    same("course\n\nchapter\n\ns", $request->systemText(), 'and a provider without segments gets them joined');
});

/* ------------------------------------------------- planning the scopes */

test('a scope plan is read by page number, whatever a model puts around it', static function (): void {
    $answer = "Here you go:\n1.1 Node LTS; npm\n- 1.2: src and public\n**2.1** – ref(); reactive()\n2.2) computed()\n9.9 nowhere\n";
    same(
        ['1.1' => 'Node LTS; npm', '1.2' => 'src and public', '2.1' => 'ref(); reactive()', '2.2' => 'computed()', '9.9' => 'nowhere'],
        ScopePlanner::parse($answer),
        'every line with a number is read, and the chatter is not'
    );
});

test('planning asks only for the pages without a scope and shows the rest as fixed', static function (): void {
    $outline = str_replace("      src and public; main.js; single-file components\n", '', SCOPED_OUTLINE);
    ['project' => $project, 'profile' => $profile, 'pages' => $pages] = scopedCourse('ctx-plan', $outline);

    $targets = array_values(array_filter($pages, static fn(array $row): bool => trim((string)$row['scope']) === ''));
    same(['The project layout'], array_column($targets, 'title'), 'one page has no scope');

    $prompt = ScopePlanner::prompt($profile, $project, $pages, $targets, false);
    ok(str_contains($prompt['user'], "1.2 The project layout"), 'it is the one listed');
    same(false, str_contains($prompt['user'], "1.1 Installing Node"), 'the others are not asked for again');
    ok(str_contains($prompt['user'], 'ref(); reactive()'), 'but their scopes are shown, so the new one does not overlap');

    $fresh = ScopePlanner::prompt($profile, $project, $pages, $pages, true);
    same(false, str_contains($fresh['user'], 'ref(); reactive()'), 'planning all afresh hides the old scopes');
});

test('get_page_brief sends the shared context once and then only its id', static function (): void {
    ['project' => $project, 'pages' => $pages] = scopedCourse('ctx-mcp');
    $actor = Actor::make('ctx-mcp', 'Ctx', Actor::ROLE_USER);
    $courseId = (int)$project['id'];

    $first = (array)(Tools::call($actor, 'get_page_brief', ['course_id' => $courseId, 'page_id' => (int)$pages[0]['id']])['data'] ?? []);
    ok(str_contains((string)$first['shared_context'], 'Node LTS; npm'), 'the first brief carries the map');
    ok(str_starts_with((string)$first['shared_context_id'], 'ctx-'), 'under an id');

    $known = $first['shared_context_id'] . ', ' . $first['chapter_context_id'];
    $second = (array)(Tools::call($actor, 'get_page_brief', [
        'course_id' => $courseId, 'page_id' => (int)$pages[1]['id'], 'known_context' => $known,
    ])['data'] ?? []);
    ok(str_starts_with((string)$second['shared_context'], '(unchanged'), 'the next page of the chapter is not sent the map again');
    ok(str_starts_with((string)$second['chapter_context'], '(unchanged'), 'nor the chapter');

    $third = (array)(Tools::call($actor, 'get_page_brief', [
        'course_id' => $courseId, 'page_id' => (int)$pages[2]['id'], 'known_context' => $known,
    ])['data'] ?? []);
    ok(str_starts_with((string)$third['shared_context'], '(unchanged'), 'the next chapter shares the map');
    ok(str_contains((string)$third['chapter_context'], 'GOAL-TWO'), 'and is sent its own chapter');
    same('ref(); reactive(); when to use which; unwrapping in templates', (string)$third['page_scope'], 'and the page scope');
});

test('update_page sets a scope and writes it into the outline', static function (): void {
    ['project' => $project, 'pages' => $pages] = scopedCourse('ctx-edit');
    $actor = Actor::make('ctx-edit', 'Ctx', Actor::ROLE_USER);

    Tools::call($actor, 'update_page', ['course_id' => (int)$project['id'], 'page_id' => (int)$pages[1]['id'], 'scope' => "  vite.config.js;\n index.html "]);
    $stored = Projects::require('ctx-edit', (int)$project['id']);
    same('vite.config.js; index.html', Structure::parse((string)$stored['structure_md'])['chapters'][0]['pages'][1]['scope'],
        'one tidy line, in the stored outline');

    Tools::call($actor, 'update_page', ['course_id' => (int)$project['id'], 'page_id' => (int)$pages[1]['id'], 'content' => 'Text.']);
    same('generated', (string)Db::row('SELECT status FROM pages WHERE id = ?', [(int)$pages[1]['id']])['status'],
        'text stored over MCP leaves the page in the status every other road does');
});
