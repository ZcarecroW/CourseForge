<?php
declare(strict_types=1);

namespace CourseForge\Ai\Provider;

use CourseForge\Ai\AiRequest;
use CourseForge\Ai\Batch\BatchHandle;
use CourseForge\Ai\Batch\BatchItemRequest;
use CourseForge\Ai\Batch\BatchItemResult;
use CourseForge\Ai\Batch\BatchLimits;
use CourseForge\Ai\Batch\BatchStatus;
use CourseForge\Ai\Batch\Driver\OpenAiFileBatch;
use CourseForge\Support\Config;
use CourseForge\Support\Http;
use CourseForge\Support\HttpException;
use CourseForge\Support\HttpResult;
use CourseForge\Support\Text;
use Throwable;

/**
 * One adapter for every endpoint that speaks OpenAI's /chat/completions,
 * driven entirely by a PresetSpec.
 *
 * Twenty gateways claim OpenAI compatibility and the claim is true - about the
 * chat body. Everything around it differs, and differs in ways that are pure
 * configuration: Groq hangs the whole API under /openai/v1 and rejects
 * `logprobs` with a 400 where OpenAI would have ignored it, DeepInfra's shim
 * lives at /v1/openai, Z.ai has no /v1 segment at all, Ollama takes any key or
 * none. A class per gateway would be twenty classes differing by four strings,
 * each rotting on its own schedule. This is one class and a table.
 *
 * The structural decision worth knowing is that OpenAiProvider extends this
 * rather than sitting beside it. OpenAI is the reference implementation of the
 * shape every preset imitates, so making it the canonical spec means the
 * preset lane is exercised by the most-used provider on every single request
 * and cannot quietly stop working.
 *
 * The rule this class exists to enforce, more than any translation it does:
 * chat() throws rather than returning something that is not a page. A gateway
 * that fans out to an upstream vendor commits its HTTP status before the
 * upstream call happens, so a rate limit two hops away arrives as a perfectly
 * successful 200 with an `error` key in it. The output cap is the worse one: an
 * answer stopped by `finish_reason: length` arrives as a 200 carrying most of a
 * lesson, ending mid-sentence, and nothing about the shape of the response says
 * so. Both are judged by batchFailure() before a single character is handed
 * back - one implementation, read by the live path and by the queued one -
 * because a blank or half-written course page in the database looks like work
 * that succeeded and loses the reason it did not.
 */
class OpenAiCompatibleProvider extends HttpProvider implements BatchCapable
{
    /** The account kind this class answers to when no first-class adapter applies. */
    public const KIND = 'oai-compat';

    /** How much of a results download stays in memory before the spool spills to a temp file. */
    private const SPOOL_BYTES = 8388608;

    /** How much of a failed download is read back to explain it. Error bodies are small. */
    private const ERROR_BYTES = 8192;

    /**
     * How many whole copies of the input file are live at the moment it is
     * uploaded, which is the number the submission ceiling has to be divided by.
     *
     * Four of them are real and countable - the decoded requests the file was
     * built from, the file itself, the multipart body it is wrapped in, and
     * libcurl's copy of that - and the fifth is headroom for everything else
     * the request is doing while all four exist.
     */
    private const UPLOAD_COPIES = 5;

    protected readonly PresetSpec $spec;

    private ?OpenAiFileBatch $batch = null;

    /** Whether a live queue check has already been made for this instance. */
    private ?bool $queueSeen = null;

    /** The last model-list body, kept only so an empty list can quote it. */
    private string $modelsRaw = '';

    /** @param array<string,mixed> $account */
    public function __construct(array $account, ?PresetSpec $spec = null)
    {
        // Set before the parent constructor runs: it calls normaliseBaseUrl(),
        // which falls back to the preset's own address when the account has
        // none, and that is the entire point of picking a preset.
        $this->spec = $spec ?? static::resolveSpec($account);
        parent::__construct($account);
    }

    /**
     * Which preset this account is on.
     *
     * A stored `preset` array wins over a `preset_key`, because that is how a
     * custom endpoint remembers the shape a user discovered for it; a key
     * names a table row; anything else is a bare base URL, which licenses no
     * assumption beyond the OpenAI defaults.
     *
     * @param array<string,mixed> $account
     */
    protected static function resolveSpec(array $account): PresetSpec
    {
        $baseUrl = rtrim(trim((string)($account['base_url'] ?? '')), '/');
        $key = trim((string)($account['preset_key'] ?? ''));

        $inline = $account['preset'] ?? null;
        if (is_array($inline) && $inline !== []) {
            return PresetSpec::fromArray($key !== '' ? $key : Presets::CUSTOM, $inline);
        }
        if ($key !== '' && $key !== Presets::CUSTOM && Presets::has($key)) {
            $spec = Presets::spec($key);
            return $baseUrl !== '' ? $spec->withBaseUrl($baseUrl) : $spec;
        }
        return PresetSpec::forBaseUrl($baseUrl);
    }

    /** Only ever quoted as an example; the real address comes from the preset. */
    public static function defaultBaseUrl(): string
    {
        return 'https://api.openai.com/v1';
    }

    public function kind(): string
    {
        return static::KIND;
    }

    public function label(): string
    {
        return $this->spec->label !== '' ? $this->spec->label : 'OpenAI-compatible endpoint';
    }

    /** The preset driving this account, for the probe and the picker UI. */
    public function spec(): PresetSpec
    {
        return $this->spec;
    }

    /** An account that picked a preset does not have to type its address as well. */
    protected function normaliseBaseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        return $url !== '' ? $url : rtrim($this->spec->baseUrl, '/');
    }

    /**
     * The same checks as every HTTP provider, minus the key on a local server.
     *
     * Ollama, LM Studio, vLLM and llama.cpp answer an unauthenticated request
     * and reject a malformed one, so demanding a key would lock a user out of
     * the only provider that costs nothing.
     */
    protected function assertConfigured(): void
    {
        if ($this->baseUrl === '') {
            $example = $this->spec->baseUrl !== '' ? $this->spec->baseUrl : static::defaultBaseUrl();
            throw HttpException::unprocessable(
                'This ' . $this->label() . ' account has no base URL (for example ' . $example . ').'
            );
        }
        if (preg_match('#^https?://#i', $this->baseUrl) !== 1) {
            throw HttpException::unprocessable(
                'The base URL must start with http:// or https:// - got "' . $this->baseUrl . '".'
            );
        }
        if ($this->apiKey === '' && $this->spec->requiresKey) {
            throw HttpException::unprocessable('This ' . $this->label() . ' account has no API key.');
        }
    }

    /** @return array<string,string> */
    protected function headers(): array
    {
        return $this->spec->authHeaders($this->apiKey);
    }

    /* --------------------------------------------------------------- models */

    /** @return string[] */
    public function models(): array
    {
        return $this->pickModels($this->fetchModelRows());
    }

    /**
     * Which of the listed models the picker should offer.
     *
     * Everything, here. A gateway's catalogue is its own business and filtering
     * it would mean guessing at ids CourseForge has never seen; the one
     * endpoint whose list genuinely needs curating - OpenAI's, which mixes
     * embeddings, audio and fine-tunes into the same array with no capability
     * metadata - overrides this.
     *
     * @param array<int,mixed> $rows
     * @return string[]
     */
    protected function pickModels(array $rows): array
    {
        $models = self::collectModelIds($rows);
        if ($models === []) {
            throw HttpException::badRequest(
                'The endpoint answered, but no model ids were found. Raw: ' . Text::snippet($this->modelsRaw)
            );
        }
        return $models;
    }

    /**
     * A gateway never says which of its models the queue will take, so the
     * answer everywhere here is "whatever supportsBatch() says".
     *
     * @return string[]
     */
    public function batchModels(): array
    {
        return [];
    }

    /**
     * Whether `model:batch` will work on this account.
     *
     * The order is deliberate. A local server has no queue and never will, so
     * it is answered without a network call. A stored probe result is the next
     * best thing and, crucially, outranks the preset table: the table describes
     * an endpoint, the probe describes an endpoint *and a key*, and Gemini's
     * paid-tier-only queue is the reminder that those are different questions.
     * Only a preset whose queue is documented is believed on its own word, and
     * a preset marked 'probe' with nothing stored falls back to asking the
     * endpoint directly - one free GET, cached for this instance.
     */
    public function supportsBatch(): bool
    {
        if ($this->baseUrl === '' || ($this->apiKey === '' && $this->spec->requiresKey)) {
            return false;
        }
        if ($this->spec->local) {
            return false;
        }

        $stored = Probe::supported($this->storedProbe());
        if ($stored !== null) {
            return $stored;
        }
        if ($this->spec->batchDeclared()) {
            return true;
        }
        if ($this->spec->batchRefused()) {
            return false;
        }
        return $this->queueSeen ??= $this->queueRouteExists();
    }

    /**
     * The full capability probe, for an account that was just saved or for a
     * "re-check" button.
     *
     * Never called on page render: it is four requests against somebody else's
     * server, and the answer changes about as often as a provider ships a
     * feature.
     *
     * @return array<string,mixed> the shape stored on the account row
     */
    public function probe(): array
    {
        $this->assertConfigured();
        return (new Probe($this->baseUrl, $this->headers(), $this->spec, $this->metaTimeout()))->run();
    }

    /** What a previous probe concluded for this account. @return array<string,mixed>|null */
    protected function storedProbe(): ?array
    {
        $stored = $this->account['batch_probe'] ?? null;
        return is_array($stored) ? $stored : null;
    }

    /**
     * Which endpoint and which key a stored capability result belongs to.
     *
     * The batch driver needs it in order to write a disproof home when a real
     * submission comes back 404: it holds a provider rather than a profile row,
     * and "this endpoint with this key" is exactly the scope of what such a
     * failure proved. The account's own base URL is used rather than the
     * normalised one, because the stored field is what identifies the row that
     * has to be found again.
     */
    public function probeFingerprint(): string
    {
        return Probe::fingerprint((string)($this->account['base_url'] ?? ''), $this->apiKey);
    }

    /* ----------------------------------------------------------------- chat */

    public function chat(AiRequest $request): string
    {
        $this->assertConfigured();
        $this->assertModel($request);

        $payload = $this->payload($request);
        $res = $this->post($payload);
        $res = $this->retryWithoutRejectedParams($res, $payload);

        $this->assertOk($res, 'the completion', $this->url($this->spec->chatPath));
        $this->assertJson($res, 'the completion');
        $this->assertBodyOk($res);

        return $this->readCompletion($res);
    }

    /* ---------------------------------------------------------------- batch */

    /** @param array<int,BatchItemRequest> $items */
    public function submitBatch(array $items): BatchHandle
    {
        return $this->fileBatch()->submit($items);
    }

    public function pollBatch(BatchHandle $handle): BatchStatus
    {
        return $this->fileBatch()->poll($handle);
    }

    /** @return iterable<string,BatchItemResult> */
    public function fetchBatchResults(BatchHandle $handle): iterable
    {
        return $this->fileBatch()->fetch($handle);
    }

    public function cancelBatch(BatchHandle $handle): bool
    {
        return $this->fileBatch()->cancel($handle);
    }

    public function canCancel(): bool
    {
        return true;
    }

    public function releaseBatch(BatchHandle $handle): void
    {
        $this->fileBatch()->release($handle);
    }

    /**
     * 50,000 rows or a 200 MB input file unless the preset says otherwise, and
     * the byte bound is the one that binds on course prompts.
     *
     * These are OpenAI's numbers, used as the conservative default for every
     * gateway that copied the API, because no free call reveals what a queue
     * will actually swallow and a probe must never be asked to guess. Only the
     * window comes from the preset - Groq takes 7d and recommends it.
     *
     * The file ceiling is then lowered again to what this process can build,
     * which is a smaller number answering a different question - see
     * uploadBytes().
     */
    public function batchLimits(): BatchLimits
    {
        return new BatchLimits(
            50000,
            self::uploadBytes(200 * BatchLimits::MEGABYTE),
            null,
            $this->spec->window,
            30,
        );
    }

    /**
     * The largest input file this process can assemble, which is not the
     * largest one the provider would accept.
     *
     * The upload is not streamed and cannot be from here. Http::multipart
     * builds the body as a string on purpose - a shared host is the last place
     * to depend on a writable temp file - and libcurl copies that string again
     * on its way out, so UPLOAD_COPIES of the JSONL exist at once. A 200 MB
     * submission therefore wants a gigabyte of memory limit; what it actually
     * does on a default install is exhaust the limit part way through building
     * the file, after the hours of prompt assembly that came first and with
     * nothing to show for them.
     *
     * So the ceiling is read from `memory_limit` rather than from OpenAI's
     * documentation, and it fails in the direction of a sentence somebody can
     * act on: a run too large is refused up front with "generate it in smaller
     * selections", which is what a 128 MB install would have had to do in any
     * case. An unlimited or unreadable limit is taken at its word and gets the
     * provider's own number, because an operator who removed the limit has
     * already answered this question.
     */
    private static function uploadBytes(int $providerBytes): int
    {
        $limit = self::memoryLimitBytes();
        if ($limit <= 0) {
            return $providerBytes;
        }

        return max(BatchLimits::MEGABYTE, min($providerBytes, intdiv($limit, self::UPLOAD_COPIES)));
    }

    /** `memory_limit` in bytes, or 0 when there is no limit to read. */
    private static function memoryLimitBytes(): int
    {
        $raw = strtolower(trim((string)ini_get('memory_limit')));
        if ($raw === '' || (int)$raw <= 0) {
            return 0;
        }

        return match (substr($raw, -1)) {
            'g' => (int)$raw * 1024 * BatchLimits::MEGABYTE,
            'm' => (int)$raw * BatchLimits::MEGABYTE,
            'k' => (int)$raw * 1024,
            default => (int)$raw,
        };
    }

    /* ------------------------------------------- what the batch driver needs */

    /*
     * OpenAiFileBatch lives in its own class because the same three-step file
     * dance - upload JSONL, create a batch, download two result files - serves
     * OpenAI, Groq and every preset whose probe finds the routes. The four
     * methods below, plus spec() and label(), are the whole of what it needs
     * from a provider, and they are public for that one reader.
     */

    public function batchUrl(string $path): string
    {
        return $this->url($path);
    }

    /** @param array<string,mixed>|null $payload */
    public function batchRequest(string $method, string $path, ?array $payload = null): HttpResult
    {
        $this->assertConfigured();
        return $this->send($method, $path, $payload, $this->metaTimeout());
    }

    /**
     * The multipart upload of the input file.
     *
     * The Content-Type header is not ours to write beyond the boundary that
     * goes with the body - a multipart POST whose declared boundary does not
     * match the one in the payload is rejected by every gateway, and one sent
     * as application/json is rejected by all of them.
     */
    public function batchUpload(string $jsonl, string $filename): HttpResult
    {
        $this->assertConfigured();
        $url = $this->url($this->spec->filesPath);
        try {
            return Http::multipart(
                $url,
                $this->headers(),
                ['purpose' => 'batch'],
                ['file' => ['filename' => $filename, 'type' => 'application/jsonl', 'content' => $jsonl]],
                $this->chatTimeout(),
            );
        } catch (Throwable $e) {
            throw HttpException::badRequest($this->label() . ': the file upload crashed - ' . $e->getMessage());
        }
    }

    /**
     * A results file as a stream of whole lines, or null when there is no such
     * file.
     *
     * A results file is JSONL rather than JSON and a finished course is tens of
     * megabytes of it, so the ordinary request path is the wrong shape twice
     * over: it would hold the entire body as one string and then hand the
     * caller a second copy of it to split. cURL is driven by a write callback
     * instead. The callback holds back the tail of each chunk until a newline
     * arrives, so the spool only ever contains complete lines and the reader
     * never has to reassemble one; the spool is a php://temp stream, which
     * stays in memory up to SPOOL_BYTES and spills to a temporary file past
     * that. Returning anything but the byte count aborts the transfer, which is
     * why the callback always returns what it was handed.
     *
     * Null means the file is not there, which is the ordinary case rather than
     * an error: a batch with no failures has no error file, and asking for one
     * is how that is discovered. Every other failure is raised through the
     * account's own error ladder, because a caller that reads a short result
     * set as success marks a whole course of finished pages as never answered.
     *
     * @return resource|null
     */
    public function batchStream(string $url)
    {
        $this->assertConfigured();

        if (!function_exists('curl_init')) {
            throw HttpException::badRequest('The PHP cURL extension is not enabled on this server.');
        }

        $spool = @fopen('php://temp/maxmemory:' . self::SPOOL_BYTES, 'w+b');
        if ($spool === false) {
            throw HttpException::badRequest(
                $this->label() . ': the batch results could not be buffered - no writable temporary stream.'
            );
        }

        $headerLines = [];
        foreach ($this->headers() as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        if (defined('CF_VERSION')) {
            $headerLines[] = 'User-Agent: CourseForge/' . CF_VERSION . ' (+PHP ' . PHP_VERSION . ')';
        }

        $pending = '';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,          // moot while a write callback is set, and honest about intent
            CURLOPT_TIMEOUT => max(0, $this->chatTimeout()),
            CURLOPT_CONNECTTIMEOUT => max(5, Config::int('app.connect_timeout_seconds', 30)),
            CURLOPT_FOLLOWLOCATION => false,         // the key travels in a header cURL would replay verbatim
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING => '',                  // decompressed before the callback sees it
            CURLOPT_NOSIGNAL => true,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_TCP_KEEPIDLE => 60,
            CURLOPT_TCP_KEEPINTVL => 30,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($spool, &$pending): int {
                $length = strlen($chunk);
                $pending .= $chunk;

                $cut = strrpos($pending, "\n");
                if ($cut !== false) {
                    fwrite($spool, substr($pending, 0, $cut + 1));
                    $pending = substr($pending, $cut + 1);
                }

                return $length;
            },
        ]);

        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        unset($ch); // PHP 8 frees the handle; curl_close() is deprecated in 8.5

        if ($pending !== '') {
            fwrite($spool, $pending); // a final line with no newline after it
        }

        if ($status === 404) {
            fclose($spool);
            return null;
        }

        if ($errno !== 0 || $status < 200 || $status >= 300) {
            // An error body is JSON and small, so reading it back to explain
            // the failure costs nothing. A healthy body is the whole download
            // and must never be read as one.
            rewind($spool);
            $raw = (string)stream_get_contents($spool, self::ERROR_BYTES);
            fclose($spool);

            $this->assertOk(
                new HttpResult(
                    $status,
                    $raw,
                    json_decode($raw, true),
                    $errno !== 0 ? $error . ' (errno ' . $errno . ')' : '',
                    $errno,
                ),
                'the batch results',
                $url,
            );

            // assertOk throws on everything that reaches here; this exists so
            // the method cannot fall through to a closed stream.
            throw HttpException::badRequest($this->label() . ': the batch results could not be downloaded.');
        }

        rewind($spool);
        return $spool;
    }

    /**
     * The same text extraction chat() uses, applied to one result line's body.
     *
     * An instance method rather than a static one so a provider that knows a
     * dialect of the response shape - a gateway that nests its content
     * differently - fixes both paths at once by overriding extractContent().
     *
     * @param array<string,mixed> $body
     */
    public function batchText(array $body): string
    {
        return static::extractContent($body);
    }

    /**
     * The failure inside a body that arrived as a success, or null.
     *
     * Read before the text on every completion this class handles, live or
     * queued, because there are three ways a 200 can carry no usable page and
     * only one of them shows in the text. A gateway that fans out to an
     * upstream vendor reports a rate limit as an `error` key under a perfectly
     * good status. `finish_reason: error` is a failure part way through, with
     * whatever was written before it. `length` and `content_filter` are the
     * dangerous pair: both arrive with text beside them, so a reader that looks
     * at the content first sees a page and stores half a lesson, or the opening
     * paragraph of one the provider then refused to finish. There is nothing
     * else in the response to tell those apart from a finished answer.
     *
     * The envelope comes back as it arrived rather than flattened, so a batch
     * line can keep it whole and a caller can still tell a rate limit apart
     * from an invalid request. A synthesised one carries a `code` in the
     * provider's own vocabulary for the stop reason, so both read alike.
     *
     * Public, and an instance method, for the same reason batchText() is: the
     * file driver judges its result lines with this, so a queued page and a
     * live one are refused by one piece of code rather than by two that drift
     * until only one of them notices a truncation.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>|null
     */
    public function batchFailure(array $body): ?array
    {
        $error = $body['error'] ?? $body['choices'][0]['error'] ?? null;
        if (is_array($error) && $error !== []) {
            return $error;
        }
        if (is_string($error) && trim($error) !== '') {
            return ['message' => trim($error)];
        }

        $choice = is_array($body['choices'][0] ?? null) ? $body['choices'][0] : [];

        return match (strtolower(trim((string)($choice['finish_reason'] ?? '')))) {
            'error' => [
                'code' => 'error',
                'message' => 'The provider stopped this answer part way through (finish_reason=error), '
                    . 'so the page would have been stored half written.',
            ],
            'length' => [
                'code' => 'length',
                'message' => 'The answer was cut off by the output limit, so the page is incomplete '
                    . '(finish_reason=length). Raise "Max tokens" for this slot, or shorten the brief.',
            ],
            'content_filter' => [
                'code' => 'content_filter',
                'message' => 'The provider blocked this answer (finish_reason=content_filter). '
                    . 'Whatever text came with it is the part written before the block, not a page.',
            ],
            default => null,
        };
    }

    /**
     * One error envelope as a single line, for a person to read.
     *
     * The code goes in front of the message because a gateway's message is
     * often the generic half of the pair, and it is dropped again when the
     * message already contains it - which is what a synthesised envelope always
     * does, since it names its own stop reason. An envelope with no message at
     * all is printed as its own JSON: unreadable, but it is the evidence, and
     * an empty error line is worse than an ugly one.
     *
     * @param array<string,mixed> $failure
     */
    protected static function failureLine(array $failure): string
    {
        $message = trim((string)($failure['message'] ?? ''));
        if ($message === '') {
            $message = (string)(json_encode($failure, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        }

        $code = trim((string)($failure['code'] ?? $failure['type'] ?? ''));
        if ($code !== '' && !str_contains(strtolower($message), strtolower($code))) {
            $message = $code . ': ' . $message;
        }

        return mb_substr(trim($message), 0, 400);
    }

    /**
     * The shared phrasing for a call that failed, so a batch error reads like
     * every other error this account can produce rather than like a second
     * dialect invented in the driver.
     */
    public function batchAssert(HttpResult $res, string $what, string $url = '', bool $json = false): void
    {
        $this->assertOk($res, $what, $url);
        if ($json) {
            $this->assertJson($res, $what);
        }
    }

    /**
     * The body of one JSONL line, which has to be the body chat() would have
     * sent for the same request.
     *
     * The reasoning-parameter rules apply inside a batch line exactly as they
     * do live, and a 50,000-line file that gets every one of them wrong is the
     * most expensive way to learn that.
     *
     * @return array<string,mixed>
     */
    public function batchBody(AiRequest $request): array
    {
        $this->assertModel($request);
        return $this->payload($request);
    }

    /* ------------------------------------------------------------ internals */

    private function fileBatch(): OpenAiFileBatch
    {
        return $this->batch ??= new OpenAiFileBatch($this);
    }

    /**
     * One free GET that separates "this gateway has no batch API" from "your
     * key is wrong".
     *
     * A 404 or 405 is the answer; a 401 or 403 is not, and caching it as
     * "unsupported" would disable batching for good the moment somebody
     * mistypes a key. The full story is in Probe, which also catches a queue
     * with no upload lane - this is only the cheap version used when nothing
     * has been stored yet.
     */
    private function queueRouteExists(): bool
    {
        try {
            $res = $this->send('GET', $this->spec->batchesPath . '?limit=1', null, $this->metaTimeout());
        } catch (Throwable) {
            return false;
        }
        return $res->ok() && is_array($res->data) && array_key_exists('data', $res->data);
    }

    /** @return array<int,mixed> the raw entries of the model list */
    protected function fetchModelRows(): array
    {
        $this->assertConfigured();
        $path = $this->spec->modelsPath;
        $url = $this->url($path);

        $res = $this->send('GET', $path, null, $this->metaTimeout());
        $this->assertOk($res, 'the model list', $url);
        $this->assertJson($res, 'the model list');
        $this->modelsRaw = $res->raw;

        // Three shapes are in the wild: OpenAI's {data:[]}, a {models:[]} used
        // by a few shims, and a bare array from the smaller local servers.
        $items = $res->data['data'] ?? $res->data['models'] ?? $res->data;
        if (!is_array($items)) {
            throw HttpException::badRequest('Unexpected model list format: ' . Text::snippet($res->raw));
        }
        return $items;
    }

    /** @param array<string,mixed> $payload */
    protected function post(array $payload): HttpResult
    {
        return $this->send('POST', $this->spec->chatPath, $payload, $this->chatTimeout());
    }

    /** @return array<string,mixed> */
    protected function payload(AiRequest $request): array
    {
        $payload = [
            'model' => $request->model,
            'messages' => $request->messages(),
            'temperature' => $request->temperature,
        ];
        if ($request->maxTokens > 0) {
            $payload[$this->spec->maxTokensField] = $request->maxTokens;
        }

        $payload = $this->tuneForModel($payload, $request->model);

        // Last, so a preset's blanket rejection list wins over anything the
        // model-specific tuning above put back.
        return $this->spec->stripUnsupported($payload);
    }

    /**
     * A hook for the model-specific parameter rules of one particular vendor.
     *
     * Nothing generic belongs here. It exists so OpenAI's reasoning models -
     * which answer 400 to `max_tokens` and to every sampling parameter - can be
     * handled in OpenAiProvider without that knowledge leaking into a driver
     * that also has to serve Ollama.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    protected function tuneForModel(array $payload, string $model): array
    {
        return $payload;
    }

    /**
     * Private rather than protected on purpose: a subclass that wants its own
     * model check writes one, and inheriting a name here would constrain the
     * two first-class adapters that already have theirs.
     */
    private function assertModel(AiRequest $request): void
    {
        if (trim($request->model) === '') {
            throw HttpException::unprocessable('No model is selected for this request.');
        }
    }

    /**
     * One retry when a 400 blames a parameter the gateway does not take.
     *
     * The preset's `strip` list covers the gateways whose rejections are known;
     * this covers the ones released after the table was written, and the model
     * that started refusing `temperature` in a minor version. It fires only on
     * a 400 that names the parameter, and only once.
     *
     * @param array<string,mixed> $payload
     */
    protected function retryWithoutRejectedParams(HttpResult $res, array $payload): HttpResult
    {
        if ($res->status !== 400) {
            return $res;
        }

        $reason = strtolower(is_array($res->data) ? $res->message(500) : $res->raw);
        $retry = $payload;
        $changed = false;

        foreach (['temperature', 'top_p', 'presence_penalty', 'frequency_penalty'] as $param) {
            if (isset($retry[$param]) && str_contains($reason, $param)) {
                unset($retry[$param]);
                $changed = true;
            }
        }
        if (str_contains($reason, 'max_completion_tokens') && isset($retry['max_tokens'])) {
            $retry['max_completion_tokens'] = $retry['max_tokens'];
            unset($retry['max_tokens']);
            $changed = true;
        }

        return $changed ? $this->post($retry) : $res;
    }

    /**
     * The assistant text, or an exception saying why there is none.
     *
     * Never a blank string, and never a partial one. Everything that reaches
     * this point was a 2xx with a JSON body, which says nothing whatever about
     * whether there is a page in it, so the failure is judged first and out of
     * batchFailure() - before the content is looked at, because the two
     * failures that matter most here arrive with text attached. An answer cut
     * off at the output cap is most of a lesson ending mid-sentence; a filtered
     * one is whatever the model managed before the block. Both look like a
     * finished page to anything that reads the content and nothing else, and
     * both are discarded here rather than written to the database.
     *
     * What is left is an answer that is genuinely empty, which is the shape
     * nobody expected: no content key, a gateway with a dialect of its own, a
     * stop reason with nothing behind it. That one quotes the raw response,
     * because the reason is not in any field this class knows the name of.
     */
    protected function readCompletion(HttpResult $res): string
    {
        $body = is_array($res->data) ? $res->data : [];

        $failure = $this->batchFailure($body);
        if ($failure !== null) {
            throw HttpException::badRequest($this->label() . ': ' . self::failureLine($failure));
        }

        $content = self::extractContent($body);
        if ($content !== '') {
            return $content;
        }

        $finish = strtolower(trim((string)($body['choices'][0]['finish_reason'] ?? '')));

        throw HttpException::badRequest(
            $this->label() . ' returned an empty response'
            . ($finish !== '' ? ' (finish_reason=' . $finish . ')' : '')
            . '. Raw: ' . Text::snippet($res->raw)
        );
    }

    /**
     * The text out of a chat completion body.
     *
     * `content` is a plain string on OpenAI itself and an array of parts on
     * several gateways, and the parts are iterated rather than indexed: a
     * reasoning part can sit in front of the text one, and reading part zero
     * would store a chain of thought as a course page. Anything that is not
     * text is skipped rather than rejected, because the union of part types
     * grows with every release.
     *
     * @param array<string,mixed> $body
     */
    protected static function extractContent(array $body): string
    {
        $content = $body['choices'][0]['message']['content'] ?? null;

        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                if (is_string($part)) {
                    $parts[] = $part;
                    continue;
                }
                if (!is_array($part) || !isset($part['text']) || !is_string($part['text'])) {
                    continue;
                }
                $type = strtolower((string)($part['type'] ?? 'text'));
                if ($type === 'thinking' || $type === 'reasoning' || $type === 'thought') {
                    continue;
                }
                $parts[] = $part['text'];
            }
            $content = implode('', $parts);
        }

        return is_string($content) ? trim($content) : '';
    }

    /**
     * A 200 that carries a failure anyway.
     *
     * Gateways that fan out to upstream vendors commit the status code before
     * the upstream call happens, so a rate limit or a moderation block two hops
     * away arrives as a perfectly successful HTTP response with an `error` key
     * in it. OpenRouter does this once the model has started producing, and
     * marks the same failure a second way, in `finish_reason` - which is also
     * where a truncated answer says so, carrying the half page it wrote before
     * it stopped.
     *
     * All of that is read out of batchFailure(), the same judgement the queued
     * path applies to a result line. Two readings of one response shape is how
     * the live lane and the batch lane drift until only one of them notices a
     * truncation.
     */
    protected function assertBodyOk(HttpResult $res): void
    {
        if (!is_array($res->data)) {
            return;
        }

        $failure = $this->batchFailure($res->data);
        if ($failure !== null) {
            throw HttpException::badRequest($this->label() . ': ' . self::failureLine($failure));
        }
    }

    /**
     * JSONL to decoded lines. Blank lines are skipped, which is what every
     * provider's results file ends with.
     *
     * @return array<int,array<string,mixed>>
     */
    protected static function jsonLines(string $body): array
    {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $lines[] = $decoded;
            }
        }
        return $lines;
    }
}
