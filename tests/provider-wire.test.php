<?php
declare(strict_types=1);

use CourseForge\Ai\AiRequest;
use CourseForge\Ai\Provider\AnthropicProvider;
use CourseForge\Ai\Provider\GeminiProvider;
use CourseForge\Ai\Provider\OpenAiProvider;
use CourseForge\Ai\Provider\Presets;
use CourseForge\Support\HttpResult;

/**
 * What each provider actually puts on the wire and how it reads the answer,
 * for the parts of their APIs that changed in 2026.
 *
 * AnthropicProvider is final and its chat() now makes several requests, so it
 * is driven over a real socket against tests/support/fake-provider.php. OpenAI
 * is not final, and the seam the other tests use - overriding send() - is
 * enough to record its calls.
 */
final class FakeWire
{
    private static ?string $base = null;
    private static string $dir = '';
    /** @var resource|null */
    private static $process = null;

    /** The address of a running fake provider, started on first use. */
    public static function base(): string
    {
        if (self::$base !== null) {
            return self::$base;
        }

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        ok($probe !== false, 'a free port for the fake provider');
        $name = (string)stream_socket_get_name($probe, false);
        fclose($probe);

        self::$dir = sys_get_temp_dir() . '/cf-fake-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir(self::$dir, 0770, true);

        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        self::$process = proc_open(
            [PHP_BINARY, '-S', $name, __DIR__ . '/support/fake-provider.php'],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
            null,
            array_merge(getenv(), ['FAKE_PROVIDER_DIR' => self::$dir]),
        );
        ok(is_resource(self::$process), 'the fake provider started');

        register_shutdown_function(static function (): void {
            if (is_resource(self::$process)) {
                proc_terminate(self::$process);
            }
            array_map(static fn(string $f): bool => @unlink($f), glob(self::$dir . '/*') ?: []);
            @rmdir(self::$dir);
        });

        [$host, $port] = explode(':', $name);
        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen($host, (int)$port, $errno, $error, 0.2);
            if ($socket !== false) {
                fclose($socket);
                return self::$base = 'http://' . $name;
            }
            usleep(50000);
        }
        throw new RuntimeException('The fake provider never answered on ' . $name);
    }

    /**
     * @param array<int,array<string,mixed>> $replies
     * @param array<int,array<string,mixed>> $models
     */
    public static function script(array $replies, array $models = []): void
    {
        self::base();
        array_map(static fn(string $f): bool => unlink($f), glob(self::$dir . '/request-*.json') ?: []);
        file_put_contents(self::$dir . '/script.json', (string)json_encode($replies));
        file_put_contents(self::$dir . '/models.json', (string)json_encode(['data' => $models, 'has_more' => false]));
    }

    /** @return array<int,array{path:string,headers:array<string,string>,raw:string,body:array<string,mixed>}> */
    public static function requests(): array
    {
        $files = glob(self::$dir . '/request-*.json') ?: [];
        sort($files);
        return array_map(static function (string $file): array {
            $row = json_decode((string)file_get_contents($file), true);
            $row['body'] = json_decode((string)$row['raw'], true);
            return $row;
        }, $files);
    }
}

/** A provider with a key of its own, because the model listing is cached per key. */
function wireClaude(): AnthropicProvider
{
    return new AnthropicProvider(['base_url' => FakeWire::base(), 'api_key' => 'k' . bin2hex(random_bytes(6))]);
}

/** @return array<string,mixed> one model row of the listing */
function claudeRow(string $id, ?bool $search = true, ?bool $filter = true): array
{
    $capabilities = ['batch' => ['supported' => true]];
    if ($search !== null) {
        $capabilities['server_tools'] = ['supported' => $search, 'web_search' => ['supported' => $search]];
    }
    if ($filter !== null) {
        $capabilities['code_execution'] = ['supported' => $filter];
    }
    return ['id' => $id, 'max_tokens' => 128000, 'max_input_tokens' => 1000000, 'capabilities' => $capabilities];
}

/** @return array<string,mixed> */
function claudeText(string $text, string $stop = 'end_turn'): array
{
    return ['body' => [
        'id' => 'msg_1',
        'model' => 'claude-opus-5-5',
        'stop_reason' => $stop,
        'content' => [['type' => 'text', 'text' => $text]],
    ]];
}

function researching(string $model = 'claude-opus-5-5'): AiRequest
{
    return new AiRequest($model, 'Write well.', 'Write the page.', 0.7, 4000, true, 3);
}

function plainPage(string $model = 'claude-opus-5-5'): AiRequest
{
    return new AiRequest($model, 'Write well.', 'Write the page.', 0.7, 4000);
}

/* ------------------------------------------------------- Anthropic, on the wire */

test('Claude: a live page asks for the refusal fallback, with the beta only on that request', static function (): void {
    FakeWire::script([claudeText('# Page')], [claudeRow('claude-opus-5-5')]);

    same('# Page', wireClaude()->chat(plainPage()), 'the page comes back');

    $sent = FakeWire::requests()[0];
    same('/v1/messages', $sent['path'], 'the Messages endpoint');
    same('default', $sent['body']['fallbacks'] ?? null, 'the fallback is the server-defined default');
    same('server-side-fallback-2026-07-01', $sent['headers']['anthropic-beta'] ?? '', 'with its beta header');
    same('2026-10-21', $sent['headers']['anthropic-version'] ?? '', 'on the current API version');
    same([], array_values(array_intersect(['temperature', 'top_p', 'top_k'], array_keys($sent['body']))),
        'and no sampling parameter');
});

test('Claude: a model that will not take the fallback is asked again without it', static function (): void {
    FakeWire::script([
        ['status' => 400, 'body' => ['type' => 'error', 'error' => [
            'type' => 'invalid_request_error',
            'message' => 'fallbacks: this model has no fallback routing',
        ]]],
        claudeText('# Page'),
    ], [claudeRow('claude-opus-4-8')]);

    same('# Page', wireClaude()->chat(plainPage('claude-opus-4-8')), 'the page still comes back');

    $sent = FakeWire::requests();
    same(2, count($sent), 'one retry');
    same(false, isset($sent[1]['body']['fallbacks']), 'without the field');
    same(false, isset($sent[1]['headers']['anthropic-beta']), 'and without the beta');
});

test('Claude: a 400 about something else is the error it is, not a retry', static function (): void {
    FakeWire::script([
        ['status' => 400, 'body' => ['type' => 'error', 'error' => [
            'type' => 'invalid_request_error',
            'message' => 'prompt is too long',
        ]]],
    ], [claudeRow('claude-opus-5-5')]);

    $e = raises(static fn(): string => wireClaude()->chat(plainPage()), 'a bad request raises');
    ok(str_contains($e->getMessage(), 'prompt is too long'), 'with the API\'s own words');
    same(1, count(FakeWire::requests()), 'and is not sent twice');
});

test('Claude: research uses the filtering search where the listing says the model can', static function (): void {
    FakeWire::script([claudeText('# Page')], [claudeRow('claude-opus-5-5')]);
    wireClaude()->chat(researching());

    $tool = FakeWire::requests()[0]['body']['tools'][0];
    same('web_search_20260318', $tool['type'] ?? '', 'the current tool version');
    same('excluded', $tool['response_inclusion'] ?? '', 'leaving out results the filter already read');
    same(3, $tool['max_uses'] ?? 0, 'with the per-page cap');
});

test('Claude: a model without filtering gets the basic search, and one without search gets none', static function (): void {
    FakeWire::script([claudeText('# Page')], [claudeRow('claude-haiku-4-5', true, false)]);
    wireClaude()->chat(researching('claude-haiku-4-5'));
    same('web_search_20250305', FakeWire::requests()[0]['body']['tools'][0]['type'] ?? '', 'the basic tool');

    FakeWire::script([claudeText('# Page')], [claudeRow('claude-old', false, false)]);
    wireClaude()->chat(researching('claude-old'));
    same(false, isset(FakeWire::requests()[0]['body']['tools']), 'no tool at all when the listing says it cannot search');

    FakeWire::script([claudeText('# Page')], [claudeRow('claude-unsaid', null, null)]);
    wireClaude()->chat(researching('claude-unsaid'));
    same('web_search_20250305', FakeWire::requests()[0]['body']['tools'][0]['type'] ?? '',
        'a model the listing says nothing about still gets the tool that works everywhere');
});

test('Claude: an account that refuses the filtering search falls back to the basic one', static function (): void {
    FakeWire::script([
        ['status' => 400, 'body' => ['type' => 'error', 'error' => [
            'type' => 'invalid_request_error',
            'message' => 'web_search_20260318 requires code_execution, which is disabled',
        ]]],
        claudeText('# Page'),
    ], [claudeRow('claude-opus-5-5')]);

    same('# Page', wireClaude()->chat(researching()), 'the page still comes back');

    $tool = FakeWire::requests()[1]['body']['tools'][0];
    same('web_search_20250305', $tool['type'] ?? '', 'through the basic tool');
    same(3, $tool['max_uses'] ?? 0, 'keeping the cap');
    same(false, isset($tool['response_inclusion']), 'and nothing only the newer tool accepts');
});

test('Claude: a paused search is resumed with the content byte-faithful and the same tools', static function (): void {
    $paused = '{"id":"msg_1","model":"claude-opus-5-5","stop_reason":"pause_turn",'
        . '"container":{"id":"container_7","expires_at":"2026-11-10T12:00:00Z"},'
        . '"content":[{"type":"text","text":"Looking it up."},'
        . '{"type":"server_tool_use","id":"srvtoolu_1","name":"web_search","input":{}}]}';

    FakeWire::script([
        ['raw' => $paused],
        ['body' => ['id' => 'msg_2', 'stop_reason' => 'end_turn', 'content' => [
            ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_1', 'content' => []],
            ['type' => 'text', 'text' => '# The finished page'],
        ]]],
    ], [claudeRow('claude-opus-5-5')]);

    same('# The finished page', wireClaude()->chat(researching()), 'only the finished answer is the page');

    $sent = FakeWire::requests();
    same(2, count($sent), 'one continuation');
    same('assistant', $sent[1]['body']['messages'][1]['role'] ?? '', 'the paused turn goes back as the assistant');
    ok(str_contains($sent[1]['raw'], '"input":{}'), 'an empty tool input stays an object, or the API refuses it');
    same('container_7', $sent[1]['body']['container'] ?? '', 'in the same container');
    same($sent[0]['body']['tools'], $sent[1]['body']['tools'], 'with the same tools');
    same(1, substr_count($sent[1]['raw'], '"role":"user"'), 'and no extra user message');
});

test('Claude: every paused response stays in the conversation, in order', static function (): void {
    $pause = static fn(string $id): array => ['raw' => '{"stop_reason":"pause_turn","content":[{"type":"server_tool_use","id":"'
        . $id . '","name":"web_search","input":{}}]}'];

    FakeWire::script([$pause('a'), $pause('b'), claudeText('# Page')], [claudeRow('claude-opus-5-5')]);
    wireClaude()->chat(researching());

    $last = FakeWire::requests()[2]['body']['messages'];
    same(['user', 'assistant', 'assistant'], array_column($last, 'role'), 'both paused turns are sent back');
    same('a', $last[1]['content'][0]['id'] ?? '', 'the first one first');
    same('b', $last[2]['content'][0]['id'] ?? '', 'the second after it');
});

test('Claude: a search that never finishes stops and says so', static function (): void {
    FakeWire::script([['raw' => '{"stop_reason":"pause_turn","content":[{"type":"text","text":"still going"}]}']],
        [claudeRow('claude-opus-5-5')]);

    $e = raises(static fn(): string => wireClaude()->chat(researching()), 'it raises in the end');
    ok(str_contains($e->getMessage(), 'pause_turn'), 'naming the reason');
    same(5, count(FakeWire::requests()), 'the first request and four resumes, no more');
});

test('Claude: a page another model finished is a page', static function (): void {
    FakeWire::script([['body' => [
        'model' => 'claude-opus-4-8',
        'stop_reason' => 'end_turn',
        'content' => [
            ['type' => 'fallback', 'from' => ['model' => 'claude-opus-5-5'], 'to' => ['model' => 'claude-opus-4-8']],
            ['type' => 'text', 'text' => '# Security page'],
        ],
        'usage' => ['iterations' => [['type' => 'message'], ['type' => 'fallback_message']]],
    ]]], [claudeRow('claude-opus-5-5')]);

    same('# Security page', wireClaude()->chat(plainPage()), 'the fallback block is not text and does not hide the text');
});

test('Claude: a refusal the fallback could not get past is still a failure', static function (): void {
    FakeWire::script([['body' => [
        'stop_reason' => 'refusal',
        'stop_details' => ['category' => 'bio', 'explanation' => 'declined'],
        'content' => [],
    ]]], [claudeRow('claude-opus-5-5')]);

    $e = raises(static fn(): string => wireClaude()->chat(plainPage()), 'a refusal raises');
    ok(str_contains($e->getMessage(), 'refusal') && str_contains($e->getMessage(), 'bio'), 'naming the category');
});

test('Claude: a queued page is asked for without the fallback and with the basic search', static function (): void {
    FakeWire::script([claudeText('unused')], [claudeRow('claude-opus-5-5')]);

    $provider = wireClaude();
    $payload = new ReflectionMethod($provider, 'payload');
    $body = $payload->invoke($provider, researching(), true);

    same(false, isset($body['fallbacks']), 'the batch API errors an item that carries it');
    same('web_search_20250305', $body['tools'][0]['type'] ?? '', 'and a queued page takes no chances on the tool');
});

test('Claude: the page is what follows the last tool result, whichever tool it was', static function (): void {
    $read = AnthropicProvider::readMessage(['stop_reason' => 'end_turn', 'content' => [
        ['type' => 'text', 'text' => 'I will filter the results.'],
        ['type' => 'server_tool_use', 'id' => 'a', 'name' => 'code_execution', 'input' => []],
        ['type' => 'text', 'text' => 'Now checking one more source.'],
        ['type' => 'code_execution_tool_result', 'tool_use_id' => 'a', 'content' => []],
        ['type' => 'text', 'text' => '# The page'],
    ]]);
    same('# The page', $read['text'], 'code execution counts as a tool like search does');
});

test('Claude: searchModels reads the listing', static function (): void {
    FakeWire::script([claudeText('unused')], [claudeRow('claude-b'), claudeRow('claude-a'), claudeRow('claude-no', false, false)]);
    same(['claude-a', 'claude-b'], wireClaude()->searchModels(), 'only the models that can search, sorted');
});

/* ----------------------------------------------- OpenAI, through the send() seam */

final class RecordingOpenAi extends OpenAiProvider
{
    /** @var array<int,array{0:string,1:array<string,mixed>|null}> */
    public array $calls = [];

    /** @param array<int,array{0:int,1:array<string,mixed>}> $replies */
    public function __construct(private array $replies, string $baseUrl = 'https://api.openai.com/v1')
    {
        parent::__construct(['base_url' => $baseUrl, 'api_key' => 'test-key']);
    }

    /** @param array<string,mixed>|null $payload */
    protected function send(string $method, string $path, ?array $payload, int $timeout): HttpResult
    {
        $this->calls[] = [$path, $payload];
        [$status, $body] = array_shift($this->replies) ?? [500, ['error' => ['message' => 'no reply scripted']]];
        return new HttpResult($status, (string)json_encode($body), $body, '', 0);
    }
}

/** @return array<string,mixed> */
function responseWith(string $text): array
{
    return ['status' => 'completed', 'error' => null, 'output' => [
        ['type' => 'reasoning', 'summary' => []],
        ['type' => 'web_search_call', 'status' => 'completed'],
        ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]]],
    ]];
}

/** @return array<string,mixed> */
function chatWith(string $text): array
{
    return ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => $text]]]];
}

test('OpenAI: a live page that researches goes through the Responses API', static function (): void {
    $provider = new RecordingOpenAi([[200, responseWith('# Page')]]);
    $request = new AiRequest('gpt-6.1-sol', 'Rules.', 'The brief.', 0.7, 9000, true, 4, ['The course map.', 'The chapter.']);

    same('# Page', $provider->chat($request), 'the page comes back');

    [$path, $body] = $provider->calls[0];
    same('/responses', $path, 'on the Responses endpoint');
    same([['type' => 'web_search']], $body['tools'] ?? null, 'with the search tool');
    same(false, $body['store'] ?? null, 'and not stored on OpenAI\'s side');
    same(9000, $body['max_output_tokens'] ?? 0, 'the output cap under its Responses name');
    same(4, $body['max_tool_calls'] ?? 0, 'the search cap');
    same(false, isset($body['temperature']), 'no temperature on a reasoning model');

    same('developer', $body['input'][0]['role'] ?? '', 'the instructions as a developer message');
    same(['The course map.', 'The chapter.', 'Rules.'], array_column($body['input'][0]['content'], 'text'), 'in cache order');
    same(['mode' => 'explicit'], $body['input'][0]['content'][0]['prompt_cache_breakpoint'] ?? null, 'with its cache point');
    same(['mode' => 'explicit'], $body['prompt_cache_options'] ?? null, 'in explicit mode');
    same('user', $body['input'][1]['role'] ?? '', 'the brief as the user turn');
    same('input_text', $body['input'][1]['content'][0]['type'] ?? '', 'in the Responses shape');
});

test('OpenAI: an older model keeps its temperature and no cache points on the Responses path', static function (): void {
    $provider = new RecordingOpenAi([[200, responseWith('# Page')]]);
    $provider->chat(new AiRequest('gpt-4.1', 'Rules.', 'The brief.', 0.4, 0, true, 0, ['The course map.']));

    $body = $provider->calls[0][1];
    same(0.4, $body['temperature'] ?? null, 'the temperature reaches the request');
    same(false, isset($body['prompt_cache_options']), 'no explicit cache mode before gpt-5.6');
    same(false, isset($body['input'][0]['content'][0]['prompt_cache_breakpoint']), 'and no cache point');
    ok(isset($body['prompt_cache_key']), 'the cache key still keeps the course together');
    same(false, isset($body['max_tool_calls']), 'no search cap unless one was set');
    same(false, isset($body['max_output_tokens']), 'no output cap unless one was set');
});

test('OpenAI: everything else stays on chat/completions', static function (): void {
    $plain = new RecordingOpenAi([[200, chatWith('# Page')]]);
    $plain->chat(plainPage('gpt-6.1-sol'));
    same('/chat/completions', $plain->calls[0][0], 'a page that does not research');

    $tuned = new RecordingOpenAi([[200, chatWith('# Page')]]);
    $tuned->chat(researching('gpt-5-search-api'));
    same('/chat/completions', $tuned->calls[0][0], 'a search-tuned model');
    ok(isset($tuned->calls[0][1]['web_search_options']), 'which searches through its own field');

    $gateway = new RecordingOpenAi([[200, chatWith('# Page')]], 'https://gateway.test/v1');
    $gateway->chat(researching('gpt-6.1-sol'));
    same('/chat/completions', $gateway->calls[0][0], 'a gateway that only wears this API');
});

test('OpenAI: a queued page never asks for the search the queue refuses', static function (): void {
    $provider = new RecordingOpenAi([]);
    $body = $provider->batchBody(researching('gpt-6.1-sol'));

    same(false, isset($body['tools']), 'no tool on a batch line');
    same(false, isset($body['web_search_options']), 'and no search option');
    ok(isset($body['messages']), 'it stays a chat body');
});

test('OpenAI: a model or account without the tool still gets its page', static function (): void {
    $provider = new RecordingOpenAi([
        [400, ['error' => ['message' => "Hosted tool 'web_search' is not supported with gpt-4.1-nano."]]],
        [200, chatWith('# From memory')],
    ]);

    same('# From memory', $provider->chat(researching('gpt-4.1-nano')), 'nothing is refused for wanting to search');
    same('/chat/completions', $provider->calls[1][0], 'it is written the ordinary way');
});

test('OpenAI: an optional field the API refuses is dropped once', static function (): void {
    $provider = new RecordingOpenAi([
        [400, ['error' => ['message' => "Unknown parameter: 'prompt_cache_options'."]]],
        [200, responseWith('# Page')],
    ]);

    $request = new AiRequest('gpt-6.1-sol', 'Rules.', 'The brief.', 0.7, 0, true, 0, ['The course map.']);
    same('# Page', $provider->chat($request), 'the page still comes back');

    $body = $provider->calls[1][1];
    same(false, isset($body['prompt_cache_options']), 'without the option');
    same(false, isset($body['prompt_cache_key']), 'or the key');
    same(false, isset($body['input'][0]['content'][0]['prompt_cache_breakpoint']), 'or the cache points');
});

test('OpenAI: a Responses object is judged before it is read', static function (): void {
    $text = OpenAiProvider::readResponse(['status' => 'completed', 'output' => [
        ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Let me check.']]],
        ['type' => 'web_search_call', 'status' => 'completed'],
        ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '# The page']]],
    ]]);
    same('# The page', $text['text'], 'the last message is the page, not the narration before it');

    $cut = OpenAiProvider::readResponse(['status' => 'incomplete',
        'incomplete_details' => ['reason' => 'max_output_tokens'],
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Half a les']]]],
    ]);
    same('', $cut['text'], 'half a page is not stored');
    ok(str_contains($cut['problem'], 'max_output_tokens'), 'and the limit is named');

    $filtered = OpenAiProvider::readResponse(['status' => 'incomplete', 'incomplete_details' => ['reason' => 'content_filter']]);
    ok(str_contains($filtered['problem'], 'content_filter'), 'a filtered answer says so');

    $failed = OpenAiProvider::readResponse(['status' => 'failed', 'error' => ['code' => 'server_error', 'message' => 'It broke.']]);
    ok(str_contains($failed['problem'], 'It broke.'), 'a failure keeps OpenAI\'s words');

    $refused = OpenAiProvider::readResponse(['status' => 'completed', 'output' => [
        ['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'I cannot help with that.']]],
    ]]);
    ok(str_contains($refused['problem'], 'I cannot help'), 'a refusal is not a page');

    ok(OpenAiProvider::readResponse(['status' => 'completed', 'output' => []])['problem'] !== '', 'nor is nothing at all');
});

/* ---------------------------------------------------------------- Gemini, presets */

test('Gemini: a request is kept out of the logs and carries no sampling parameter', static function (): void {
    $provider = new GeminiProvider(['api_key' => 'k']);
    $payload = new ReflectionMethod($provider, 'payload');

    foreach (['gemini-4-carbon', 'gemini-3.8-flash', 'gemini-2.5-pro'] as $model) {
        $body = $payload->invoke($provider, new AiRequest($model, 'Rules.', 'Brief.', 0.3, 2000, true));

        same(false, $body['store'] ?? null, $model . ' opts out of request storage');
        same(['maxOutputTokens' => 2000], $body['generationConfig'] ?? null, $model . ' has no temperature');
        // The field list of GenerateContentRequest in Google's own discovery
        // document: anything else here is a 400 on the live path and a day lost
        // on the queued one.
        same([], array_values(array_diff(array_keys($body), [
            'contents', 'safetySettings', 'tools', 'cachedContent', 'systemInstruction',
            'toolConfig', 'labels', 'model', 'generationConfig', 'store', 'continuationToken', 'serviceTier',
        ])), $model . ' sends only documented fields');
    }
});

test('Gemini: the queue knows the newest model and still its older ones', static function (): void {
    $models = (new GeminiProvider(['api_key' => 'k']))->batchModels();
    ok(in_array('gemini-4-carbon', $models, true), 'Gemini 4 Carbon');
    ok(in_array('gemini-4-argon', $models, true), 'Gemini 4 Argon');
    ok(in_array('gemini-3.8-flash', $models, true), 'Gemini 3.8 Flash');
});

test('Presets: Nebius is at its current address, and every hosted preset says where its docs are', static function (): void {
    same('https://api.tokenfactory.nebius.com/v1', Presets::spec('nebius')->baseUrl, 'Token Factory');

    foreach (Presets::specs() as $key => $spec) {
        ok($key === Presets::CUSTOM || str_starts_with($spec->docs, 'https://'), $key . ' has a documentation link');
    }
});
