<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * The OpenAI-shaped queue: what a capability probe is allowed to conclude, and
 * what a results download is allowed to be read as.
 *
 * Every case here is a way of turning "we do not know" into a confident, wrong
 * answer, and each one costs something different. A probe that could not reach
 * the queue route, stored as "this account has no queue", takes queued
 * generation away from an endpoint that has one. A results file that could not
 * be downloaded, read as "the provider had no answers", fails every page in a
 * run that was generated and paid for and then closes the run as finished -
 * with no error recorded anywhere and no way to collect it again.
 *
 * The transport half of the probe (a batch list whose download dies mid-body)
 * needs a server that lies about Content-Length and then hangs up, which is a
 * second process this suite deliberately does not spawn. What is covered here
 * instead is the durable half: an inconclusive verdict, however it was reached,
 * must never answer "no", and a row written by an older probe must not be
 * trusted at all.
 */

use CourseForge\Ai\Batch\BatchHandle;
use CourseForge\Ai\Provider\OpenAiCompatibleProvider;
use CourseForge\Ai\Provider\OpenAiProvider;
use CourseForge\Ai\Provider\Probe;
use CourseForge\Support\HttpException;

/** A stored probe row, shaped the way Probe::stored() reads one back. */
function probeRow(string $result, int $version = Probe::VERSION): array
{
    return [
        'at' => time(),
        'result' => $result,
        'codes' => ['models' => 200, 'batches' => 0, 'files' => 0, 'create' => 0],
        'window' => '24h',
        'probe_ver' => $version,
        'reason' => 'Recorded by a test.',
        'for' => '',
    ];
}

/**
 * A provider whose results download is a table of canned files.
 *
 * batchStream() is public precisely so the file driver can be driven without a
 * socket. A string is the file's content, and null is a 404 - answered the way
 * the real method answers one, which is the behaviour under test: absent is
 * ordinary for the error file and is lost work for the output file.
 */
final class SpooledGateway extends OpenAiCompatibleProvider
{
    /** @var array<string,bool> every url this was asked for, and how */
    public array $asked = [];

    /** @param array<string,string|null> $files by file id */
    public function __construct(private readonly array $files)
    {
        parent::__construct(['base_url' => 'https://gateway.test/v1', 'api_key' => 'test-key']);
    }

    /** @return resource|null */
    public function batchStream(string $url, bool $optional = false)
    {
        $this->asked[$url] = $optional;

        $id = basename(dirname($url));
        $body = $this->files[$id] ?? null;
        if ($body === null) {
            if ($optional) {
                return null;
            }
            throw HttpException::badRequest('the batch results are no longer at ' . $url . ' (HTTP 404).');
        }

        $spool = fopen('php://temp', 'w+b');
        fwrite($spool, $body);
        rewind($spool);
        return $spool;
    }
}

/** One JSONL result line. @param array<string,mixed> $line */
function resultLine(array $line): string
{
    return (string)json_encode($line, JSON_UNESCAPED_SLASHES) . "\n";
}

/** A line that answered a page properly. */
function answeredLine(string $customId, string $text): string
{
    return resultLine([
        'id' => 'l-' . $customId,
        'custom_id' => $customId,
        'response' => ['status_code' => 200, 'body' => [
            'choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']],
        ]],
    ]);
}

/* ------------------------------------------------------ the probe's vocabulary */

test('probe: an inconclusive result decides nothing rather than answering no', static function (): void {
    same(null, Probe::supported(probeRow(Probe::UNKNOWN)), 'unknown must not become false');
});

test('probe: the verdicts that really are negative still answer false', static function (): void {
    same(false, Probe::supported(probeRow(Probe::NO)), 'no');
    same(false, Probe::supported(probeRow(Probe::NO_UPLOAD_LANE)), 'no upload lane');
    // The queue is real and this key may not use it, so another round trip
    // changes nothing - a tier upgrade is what does.
    same(false, Probe::supported(probeRow(Probe::FORBIDDEN)), 'forbidden');
    same(true, Probe::supported(probeRow(Probe::YES)), 'yes');
});

test('probe: a row taken by an older probe is not trusted at all', static function (): void {
    // Version 2 could record a definitive no on a batch list whose download
    // died half way, and nothing tells such a row apart afterwards.
    same(null, Probe::supported(probeRow(Probe::NO, 2)), 'an old no must not still refuse');
    ok(Probe::stale(probeRow(Probe::NO, 2)), 'an old row is due to be taken again');
});

test('probe: an inconclusive row leaves a declared queue switched on', static function (): void {
    $account = [
        'id' => 'acct-probe',
        'kind' => 'oai-compat',
        'preset_key' => 'groq',
        'base_url' => 'https://api.groq.com/openai/v1',
        'api_key' => 'gsk_test',
        'batch_probe' => probeRow(Probe::UNKNOWN),
    ];
    $provider = new OpenAiCompatibleProvider($account);
    ok($provider->spec()->batchDeclared(), 'the preset itself declares a queue');
    // No network call is made: the preset answers before queueRouteExists() is
    // reached, which is what makes this safe to assert offline.
    ok($provider->supportsBatch(), 'an undecided probe must not outrank a documented queue');
});

test('probe: a definitive no still switches a declared queue off', static function (): void {
    $provider = new OpenAiCompatibleProvider([
        'id' => 'acct-probe-no',
        'kind' => 'oai-compat',
        'preset_key' => 'groq',
        'base_url' => 'https://api.groq.com/openai/v1',
        'api_key' => 'gsk_test',
        'batch_probe' => probeRow(Probe::NO),
    ]);
    same(false, $provider->supportsBatch(), 'a measured no outranks the preset table');
});

/* --------------------------------------------------- the results download */

test('batch results: a 404 on the OUTPUT file raises rather than yielding nothing', static function (): void {
    $gateway = new SpooledGateway(['file_out_1' => null]);
    $handle = new BatchHandle('batch_1', 'completed', ['output_file_id' => 'file_out_1']);

    $e = raises(static function () use ($gateway, $handle): void {
        foreach ($gateway->fetchBatchResults($handle) as $ignored) {
            // The generator is consumed here because a download raises while
            // its lines are read, not when the call is made.
        }
    }, 'a missing output file');

    ok(
        str_contains($e->getMessage(), '404'),
        'the reason should name the download, got: ' . $e->getMessage()
    );
    same(false, $gateway->asked['https://gateway.test/v1/files/file_out_1/content'],
        'the output file must never be asked for as optional');
});

test('batch results: a 404 on the ERROR file is ordinary and the answers still arrive', static function (): void {
    $gateway = new SpooledGateway([
        'file_out_1' => answeredLine('cf-page-1', 'Page one.') . answeredLine('cf-page-2', 'Page two.'),
        'file_err_1' => null,
    ]);
    $handle = new BatchHandle('batch_1', 'completed', [
        'output_file_id' => 'file_out_1',
        'error_file_id' => 'file_err_1',
    ]);

    $written = [];
    foreach ($gateway->fetchBatchResults($handle) as $customId => $result) {
        $written[$customId] = $result->succeeded();
    }

    same(['cf-page-1' => true, 'cf-page-2' => true], $written, 'both answers should arrive');
    same(true, $gateway->asked['https://gateway.test/v1/files/file_err_1/content'],
        'a batch with no failures has no error file, so that one may be absent');
});

test('batch results: an unreachable download raises rather than reading as empty', static function (): void {
    // The real batchStream(), driven at an address nothing is listening on.
    // A results file that could not be fetched must never leave the caller
    // holding zero lines and calling them the provider's answer.
    $provider = new OpenAiCompatibleProvider([
        'base_url' => 'http://127.0.0.1:1/v1',
        'api_key' => 'test-key',
    ]);
    raises(
        static fn(): mixed => $provider->batchStream('http://127.0.0.1:1/v1/files/file_out_1/content'),
        'a results download that never connected'
    );
});

test('batch results: a per-line error that is a plain string keeps its message', static function (): void {
    // OpenAI sends an object here; the LiteLLM, vLLM and self-hosted shims this
    // driver also serves send a bare sentence, and losing it leaves the page
    // blaming the provider for answering with no text.
    $gateway = new SpooledGateway([
        'file_out_1' => answeredLine('cf-page-1', 'Page one.')
            . resultLine(['id' => 'l7', 'custom_id' => 'cf-page-4', 'error' => 'something went wrong'])
            . resultLine(['id' => 'l8', 'custom_id' => 'cf-page-5',
                'error' => ['code' => 'server_error', 'message' => 'upstream blew up']]),
    ]);
    $handle = new BatchHandle('batch_1', 'completed', ['output_file_id' => 'file_out_1']);

    $messages = [];
    foreach ($gateway->fetchBatchResults($handle) as $customId => $result) {
        $messages[$customId] = $result->errorMessage();
    }

    same('something went wrong', $messages['cf-page-4'] ?? '', 'the string error should survive');
    same('server_error: upstream blew up', $messages['cf-page-5'] ?? '', 'the object error still reads the same');
    same('', $messages['cf-page-1'] ?? 'missing', 'the answered page has no error');
});

/* ------------------------------------------------------- the OpenAI catalogue */

/** OpenAI's own curation, with the model list handed to it rather than fetched. */
final class CatalogueOpenAi extends OpenAiProvider
{
    /** @return array<int,mixed> */
    protected function fetchModelRows(): array
    {
        return [
            ['id' => 'gpt-5.6'],
            ['id' => 'gpt-5.1-chat-latest'],
            ['id' => 'gpt-5-chat-latest'],
            ['id' => 'chatgpt-4o-latest'],
            ['id' => 'gpt-4o-realtime-preview'],
            ['id' => 'text-embedding-3-large'],
            ['id' => 'o3-mini'],
            ['id' => 'gpt-4o-2024-11-20', 'shutdown_date' => '2027-01-01'],
            ['id' => 'dall-e-3'],
        ];
    }
}

test('openai: no chat-latest model is offered to the queue', static function (): void {
    $provider = new CatalogueOpenAi(['base_url' => 'https://api.openai.com/v1', 'api_key' => 'sk-test']);
    $picked = $provider->models();
    $batchable = $provider->batchModels();

    // They are perfectly good chat models - they are just not batchable, and
    // OpenAI only says so a day later, in the result lines.
    ok(in_array('gpt-5-chat-latest', $picked, true), 'the picker keeps the chat aliases');
    ok(in_array('chatgpt-4o-latest', $picked, true), 'including the whole-id one');

    foreach (['gpt-5-chat-latest', 'gpt-5.1-chat-latest', 'chatgpt-4o-latest'] as $id) {
        ok(!in_array($id, $batchable, true), $id . ' must not be offered for the queue');
    }
    ok(in_array('gpt-5.6', $batchable, true), 'the batchable families are still offered');
    ok(in_array('o3-mini', $batchable, true), 'including the o-series');
});

test('openai: the non-text models are kept out of the picker entirely', static function (): void {
    $provider = new CatalogueOpenAi(['base_url' => 'https://api.openai.com/v1', 'api_key' => 'sk-test']);
    $picked = $provider->models();

    foreach (['dall-e-3', 'text-embedding-3-large', 'gpt-4o-realtime-preview'] as $id) {
        ok(!in_array($id, $picked, true), $id . ' is not something to write a course with');
    }
});
