<?php
declare(strict_types=1);

namespace CourseForge\Ai\Provider;

use CourseForge\Ai\AiRequest;
use CourseForge\Ai\Batch\BatchHandle;
use CourseForge\Ai\Batch\BatchItemRequest;
use CourseForge\Ai\Batch\BatchItemResult;
use CourseForge\Ai\Batch\BatchStatus;
use CourseForge\Support\Http;
use CourseForge\Support\HttpException;
use CourseForge\Support\HttpResult;
use CourseForge\Support\Text;
use Throwable;

/**
 * Any endpoint that speaks OpenAI's /chat/completions.
 *
 * Deliberately tolerant, because "OpenAI-compatible" is a spectrum: model lists
 * arrive as `{data:[]}`, `{models:[]}` or a bare array depending on the
 * gateway, and reasoning models reject `temperature` or insist on
 * `max_completion_tokens`. Both are handled here rather than in the generators.
 *
 * Batching is the part where the spectrum really shows. The three-step dance
 * below - upload a JSONL file, create a batch, download two result files - is
 * implemented by OpenAI itself, Groq, DeepInfra, Azure and a LiteLLM proxy, and
 * simply absent from vLLM, Ollama and LM Studio. Rather than keep a list that
 * goes stale, supportsBatch() is answered by asking the endpoint.
 */
class OpenAiProvider extends HttpProvider implements BatchCapable
{
    /** OpenAI's documented ceiling; the gateways that copy the API copy this too. */
    private const BATCH_LIMIT = 50000;

    public static function defaultBaseUrl(): string
    {
        return 'https://api.openai.com/v1';
    }

    public function kind(): string
    {
        return Providers::OPENAI;
    }

    public function label(): string
    {
        return 'OpenAI-compatible endpoint';
    }

    public function batchLimit(): int
    {
        return self::BATCH_LIMIT;
    }

    /** @return array<string,string> */
    protected function headers(): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->apiKey];
        $organization = trim((string)($this->account['organization'] ?? ''));
        if ($organization !== '') {
            $headers['OpenAI-Organization'] = $organization;
        }
        return $headers;
    }

    /* --------------------------------------------------------------- models */

    /** @return string[] */
    public function models(): array
    {
        $this->assertConfigured();
        $url = $this->url('/models');

        $res = $this->send('GET', '/models', null, $this->metaTimeout());
        $this->assertOk($res, 'the model list', $url);
        $this->assertJson($res, 'the model list');

        $items = $res->data['data'] ?? $res->data['models'] ?? $res->data;
        if (!is_array($items)) {
            throw HttpException::badRequest('Unexpected model list format: ' . Text::snippet($res->raw));
        }

        $models = self::collectModelIds($items);
        if ($models === []) {
            throw HttpException::badRequest(
                'The endpoint answered, but no model ids were found. Raw: ' . Text::snippet($res->raw)
            );
        }
        return $models;
    }

    /** @return string[] */
    public function batchModels(): array
    {
        return []; // An OpenAI-compatible gateway never says which models it will queue.
    }

    /**
     * Asks the endpoint whether it has a batch queue at all.
     *
     * A GET that lists one batch is free, submits nothing, and is the only
     * signal that separates "this gateway has no batch API" (404/405) from
     * "your key is wrong" (401/403) - a distinction worth keeping, because
     * caching the second as "unsupported" would disable batching for good the
     * moment somebody mistypes a key.
     */
    public function supportsBatch(): bool
    {
        if ($this->apiKey === '' || $this->baseUrl === '') {
            return false;
        }
        try {
            $res = $this->send('GET', '/batches?limit=1', null, $this->metaTimeout());
        } catch (Throwable) {
            return false;
        }
        return $res->ok() && is_array($res->data) && array_key_exists('data', $res->data);
    }

    /* ----------------------------------------------------------------- chat */

    public function chat(AiRequest $request): string
    {
        $this->assertConfigured();
        if (trim($request->model) === '') {
            throw HttpException::unprocessable('No model is selected for this request.');
        }

        $payload = $this->payload($request);
        $res = $this->post($payload);

        // Reasoning models refuse "temperature" and want "max_completion_tokens".
        // Retry once with a sanitised payload instead of failing the whole page.
        if ($res->status === 400) {
            $reason = strtolower(is_array($res->data) ? $res->message(500) : $res->raw);
            $retry = $payload;
            $changed = false;
            if (str_contains($reason, 'temperature')) {
                unset($retry['temperature']);
                $changed = true;
            }
            if (str_contains($reason, 'max_completion_tokens') && isset($retry['max_tokens'])) {
                $retry['max_completion_tokens'] = $retry['max_tokens'];
                unset($retry['max_tokens']);
                $changed = true;
            }
            if ($changed) {
                $res = $this->post($retry);
            }
        }

        $this->assertOk($res, 'the completion', $this->url('/chat/completions'));
        $this->assertJson($res, 'the completion');
        $this->assertBodyOk($res);

        $content = self::extractContent(is_array($res->data) ? $res->data : []);
        if ($content === '') {
            $finish = (string)($res->data['choices'][0]['finish_reason'] ?? '');
            throw HttpException::badRequest(
                'The AI returned an empty response'
                . ($finish === 'length' ? ' (finish_reason=length - raise "Max tokens" for this slot)' : '')
                . '. Raw: ' . Text::snippet($res->raw)
            );
        }
        return $content;
    }

    /* ---------------------------------------------------------------- batch */

    /**
     * Upload the prompts as one JSONL file, then point a batch at it.
     *
     * @param array<int,BatchItemRequest> $items
     */
    public function submitBatch(array $items): BatchHandle
    {
        $this->assertConfigured();
        if ($items === []) {
            throw HttpException::unprocessable('There is nothing to submit.');
        }
        if (count($items) > self::BATCH_LIMIT) {
            throw HttpException::unprocessable(
                'This endpoint accepts at most ' . number_format(self::BATCH_LIMIT) . ' requests per batch.'
            );
        }

        $lines = [];
        foreach ($items as $item) {
            // A blank line fails the whole input file at validation time, hours
            // later, so an unencodable page has to be caught here instead.
            $line = json_encode([
                'custom_id' => $item->customId,
                'method' => 'POST',
                'url' => '/v1/chat/completions',
                'body' => $this->payload($item->request),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

            if ($line === false) {
                throw HttpException::unprocessable(
                    'One of the pages could not be encoded for the batch (' . json_last_error_msg()
                    . '). Check it for invalid characters and try again.'
                );
            }
            $lines[] = $line;
        }

        $fileId = $this->uploadJsonl(implode("\n", $lines) . "\n");

        $res = $this->send('POST', '/batches', [
            'input_file_id' => $fileId,
            'endpoint' => '/v1/chat/completions',
            'completion_window' => '24h',
        ], $this->metaTimeout());
        $this->assertOk($res, 'the batch submission', $this->url('/batches'));
        $this->assertJson($res, 'the batch submission');

        $id = (string)($res->data['id'] ?? '');
        if ($id === '') {
            throw HttpException::badRequest('The batch was accepted but no id came back: ' . Text::snippet($res->raw));
        }

        return new BatchHandle(
            $id,
            (string)($res->data['status'] ?? ''),
            '',
            (int)($res->data['expires_at'] ?? 0),
        );
    }

    public function pollBatch(string $remoteId, string $resultsRef = ''): BatchStatus
    {
        $this->assertConfigured();
        $path = '/batches/' . rawurlencode($remoteId);

        $res = $this->send('GET', $path, null, $this->metaTimeout());
        $this->assertOk($res, 'the batch status', $this->url($path));
        $this->assertJson($res, 'the batch status');

        $remote = strtolower((string)($res->data['status'] ?? ''));
        $counts = [];
        foreach ((array)($res->data['request_counts'] ?? []) as $key => $value) {
            $counts[(string)$key] = (int)$value;
        }

        // "failed" here means the input file did not validate - no result file
        // will ever appear, and the reason sits in errors.data[].
        $state = match ($remote) {
            'completed', 'expired' => BatchStatus::ENDED,
            'cancelled', 'canceled' => BatchStatus::CANCELED,
            'failed' => BatchStatus::FAILED,
            default => BatchStatus::RUNNING,
        };

        // Both result files are needed: successes and per-request failures live
        // in different ones, and a "completed" batch can still hold failures.
        $refs = array_filter([
            (string)($res->data['output_file_id'] ?? ''),
            (string)($res->data['error_file_id'] ?? ''),
        ]);

        return new BatchStatus($state, $remote, $counts, self::validationErrors($res->data), implode(',', $refs));
    }

    /** @return array<string,BatchItemResult> */
    public function fetchBatchResults(string $remoteId, string $resultsRef = ''): array
    {
        $this->assertConfigured();

        $fileIds = array_filter(array_map('trim', explode(',', $resultsRef)));
        if ($fileIds === []) {
            // The caller may not have polled first; ask again for the file ids.
            $fileIds = array_filter(array_map('trim', explode(',', $this->pollBatch($remoteId)->resultsRef)));
        }

        $results = [];
        foreach ($fileIds as $fileId) {
            $url = $this->url('/files/' . rawurlencode($fileId) . '/content');
            $res = $this->sendRaw('GET', $url, $this->chatTimeout());

            // A 404 is the ordinary case: a batch with no failures has no error
            // file. Anything else - a rate limit, an expired key, a connection
            // that died half way through - must be raised, because the caller
            // would otherwise read a short result set as "the provider had
            // nothing to say about those pages" and mark them all failed.
            if ($res->status === 404) {
                continue;
            }
            $this->assertOk($res, 'the batch results', $url);

            foreach (self::jsonLines($res->raw) as $line) {
                $result = self::readResultLine($line);
                if ($result !== null) {
                    $results[$result->customId] = $result;
                }
            }
        }
        return $results;
    }

    public function cancelBatch(string $remoteId): void
    {
        $this->assertConfigured();
        $path = '/batches/' . rawurlencode($remoteId) . '/cancel';
        $res = $this->send('POST', $path, null, $this->metaTimeout());

        if (!$res->ok() && $res->status !== 404 && $res->status !== 409 && $res->status !== 400) {
            $this->assertOk($res, 'the batch cancellation', $this->url($path));
        }
    }

    /* ------------------------------------------------------------ internals */

    /** @param array<string,mixed> $payload */
    protected function post(array $payload): HttpResult
    {
        return $this->send('POST', '/chat/completions', $payload, $this->chatTimeout());
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
            $payload['max_tokens'] = $request->maxTokens;
        }
        return $payload;
    }

    /** Uploads the JSONL and returns the file id the batch will read. */
    private function uploadJsonl(string $jsonl): string
    {
        $url = $this->url('/files');
        try {
            $res = Http::multipart(
                $url,
                $this->headers(),
                ['purpose' => 'batch'],
                ['file' => ['filename' => 'courseforge-batch.jsonl', 'type' => 'application/jsonl', 'content' => $jsonl]],
                $this->chatTimeout(),
            );
        } catch (Throwable $e) {
            throw HttpException::badRequest($this->label() . ': the file upload crashed - ' . $e->getMessage());
        }

        $this->assertOk($res, 'the batch file upload', $url);
        $this->assertJson($res, 'the batch file upload');

        $id = (string)($res->data['id'] ?? '');
        if ($id === '') {
            throw HttpException::badRequest('The batch file uploaded but no id came back: ' . Text::snippet($res->raw));
        }
        return $id;
    }

    /**
     * One JSONL result line, which can fail in two entirely different ways.
     *
     * `error` is set when the request never produced a response at all - most
     * often because the batch hit its 24 hour window. A null `error` with a
     * non-200 `response.status_code` is the opposite: the request ran and the
     * provider rejected it, and the real message is nested inside the body.
     *
     * @param array<string,mixed> $line
     */
    private static function readResultLine(array $line): ?BatchItemResult
    {
        $customId = (string)($line['custom_id'] ?? '');
        if ($customId === '') {
            return null;
        }

        if (is_array($line['error'] ?? null)) {
            $code = (string)($line['error']['code'] ?? '');
            $message = (string)($line['error']['message'] ?? 'The request failed without a message.');
            return BatchItemResult::failed(
                $customId,
                $code === 'batch_expired' ? 'expired' : 'errored',
                trim(($code !== '' ? $code . ': ' : '') . $message)
            );
        }

        $response = is_array($line['response'] ?? null) ? $line['response'] : [];
        $status = (int)($response['status_code'] ?? 0);
        $body = is_array($response['body'] ?? null) ? $response['body'] : [];

        if ($status !== 0 && ($status < 200 || $status >= 300)) {
            $message = (string)($body['error']['message'] ?? 'HTTP ' . $status . '.');
            return BatchItemResult::failed($customId, 'errored', $message);
        }

        $content = self::extractContent($body);
        if ($content === '') {
            $finish = (string)($body['choices'][0]['finish_reason'] ?? '');
            return BatchItemResult::failed(
                $customId,
                'errored',
                'Empty response' . ($finish === 'length' ? ' (finish_reason=length - raise "Max tokens")' : '') . '.'
            );
        }
        return BatchItemResult::ok($customId, $content);
    }

    /** The input file failed validation: say which line, because a 50k-line file hides it well. */
    private static function validationErrors(mixed $batch): string
    {
        if (!is_array($batch) || !is_array($batch['errors']['data'] ?? null)) {
            return '';
        }
        $parts = [];
        foreach (array_slice($batch['errors']['data'], 0, 3) as $error) {
            if (!is_array($error)) {
                continue;
            }
            $line = isset($error['line']) ? ' (line ' . (int)$error['line'] . ')' : '';
            $parts[] = trim((string)($error['message'] ?? 'Validation failed.')) . $line;
        }
        return implode(' ', $parts);
    }

    /** Some gateways answer with content parts instead of a plain string. */
    protected static function extractContent(array $body): string
    {
        $content = $body['choices'][0]['message']['content'] ?? null;
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                if (is_string($part)) {
                    $parts[] = $part;
                } elseif (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    $parts[] = $part['text'];
                }
            }
            $content = implode('', $parts);
        }
        return is_string($content) ? trim($content) : '';
    }

    /**
     * A 200 that carries an error anyway.
     *
     * Gateways that fan out to upstream vendors commit the status code before
     * the upstream call happens, so a rate limit two hops away arrives as a
     * perfectly successful HTTP response with an `error` key in it. Subclasses
     * that need it override this; the base check is cheap enough to keep here.
     */
    protected function assertBodyOk(HttpResult $res): void
    {
        if (!is_array($res->data)) {
            return;
        }
        $error = $res->data['error'] ?? $res->data['choices'][0]['error'] ?? null;
        if (is_array($error) || is_string($error)) {
            $message = is_array($error) ? (string)($error['message'] ?? 'Unknown error.') : (string)$error;
            throw HttpException::badRequest($this->label() . ' reported an error: ' . mb_substr($message, 0, 400));
        }
    }

    /** @return array<int,array<string,mixed>> */
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
