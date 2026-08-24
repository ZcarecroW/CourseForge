<?php
declare(strict_types=1);

namespace CourseForge\Ai\Provider;

use CourseForge\Ai\AiRequest;
use CourseForge\Ai\Batch\BatchHandle;
use CourseForge\Ai\Batch\BatchItemRequest;
use CourseForge\Ai\Batch\BatchItemResult;
use CourseForge\Ai\Batch\BatchStatus;
use CourseForge\Support\Config;
use CourseForge\Support\HttpException;
use CourseForge\Support\Text;

/**
 * The native Anthropic Messages API.
 *
 * Four things make it genuinely different from an OpenAI-compatible endpoint,
 * and all four are handled here so the generators never see them:
 *
 *   - the system prompt is a top-level `system` field, not a message,
 *   - `max_tokens` is required and has no server-side default,
 *   - the answer is an array of typed blocks, and on the current models a
 *     `thinking` block arrives before the text one, so `content[0]` is the
 *     wrong thing to read,
 *   - the newest models reject any non-default `temperature` with a 400, and
 *     the ceiling on the models that do accept one is 1.0, not OpenAI's 2.0.
 *
 * It also implements the Message Batches queue, which answers the same prompts
 * at half price within 24 hours.
 */
final class AnthropicProvider extends HttpProvider implements BatchCapable
{
    /** Required on every call, and unchanged since the API launched. */
    private const VERSION = '2023-06-01';

    private const BATCH_LIMIT = 100000;

    /**
     * Models that answer 400 to any non-default `temperature`, `top_p` or
     * `top_k`. The Models API reports no capability flag for sampling, so this
     * has to be a list. It is only the first line of defence: chat() also
     * retries once without sampling when a 400 blames it, which covers models
     * released after this list was written.
     */
    private const NO_SAMPLING = [
        'claude-fable-5',
        'claude-mythos-5',
        'claude-mythos-preview',
        'claude-opus-5',
        'claude-opus-4-8',
        'claude-opus-4-7',
        'claude-sonnet-5',
    ];

    /** @var string[] filled as a side effect of models() */
    private array $batchModels = [];

    public static function defaultBaseUrl(): string
    {
        return 'https://api.anthropic.com';
    }

    public function kind(): string
    {
        return Providers::ANTHROPIC;
    }

    public function label(): string
    {
        return 'Anthropic';
    }

    public function supportsBatch(): bool
    {
        return true;
    }

    public function batchLimit(): int
    {
        return self::BATCH_LIMIT;
    }

    /**
     * Anthropic paths all start at `/v1`, but people paste the base URL with
     * and without it. Both are accepted and mean the same endpoint.
     */
    protected function normaliseBaseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        return (string)preg_replace('#/v1$#i', '', $url);
    }

    /** @return array<string,string> */
    protected function headers(): array
    {
        return [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::VERSION,
        ];
    }

    /* --------------------------------------------------------------- models */

    /** @return string[] */
    public function models(): array
    {
        $this->assertConfigured();

        $ids = [];
        $batch = [];
        $after = '';

        // The list is paginated and newest-first; 1000 is the documented cap.
        for ($page = 0; $page < 20; $page++) {
            $query = '/v1/models?limit=1000' . ($after !== '' ? '&after_id=' . rawurlencode($after) : '');
            $res = $this->send('GET', $query, null, $this->metaTimeout());
            $this->assertOk($res, 'the model list', $this->url($query));
            $this->assertJson($res, 'the model list');

            $items = is_array($res->data['data'] ?? null) ? $res->data['data'] : [];
            foreach ($items as $item) {
                if (!is_array($item) || !is_string($item['id'] ?? null) || trim($item['id']) === '') {
                    continue;
                }
                $id = trim($item['id']);
                $ids[] = $id;
                if (($item['capabilities']['batch']['supported'] ?? null) === true) {
                    $batch[] = $id;
                }
            }

            if (($res->data['has_more'] ?? false) !== true || !is_string($res->data['last_id'] ?? null)) {
                break;
            }
            $after = (string)$res->data['last_id'];
        }

        if ($ids === []) {
            throw HttpException::badRequest(
                'Anthropic answered, but returned no models. Check that the key belongs to an active workspace.'
            );
        }

        sort($batch, SORT_NATURAL | SORT_FLAG_CASE);
        $this->batchModels = array_values(array_unique($batch));

        return self::collectModelIds($ids);
    }

    /** @return string[] */
    public function batchModels(): array
    {
        return $this->batchModels;
    }

    /* ----------------------------------------------------------------- chat */

    public function chat(AiRequest $request): string
    {
        $this->assertConfigured();
        $this->assertModel($request);

        $payload = $this->payload($request);
        $res = $this->send('POST', '/v1/messages', $payload, $this->chatTimeout());

        // A model added after NO_SAMPLING was written still rejects temperature.
        // One retry without it turns a hard failure into a written page.
        if ($res->status === 400 && isset($payload['temperature']) && self::blamesSampling($res->message(500))) {
            unset($payload['temperature']);
            $res = $this->send('POST', '/v1/messages', $payload, $this->chatTimeout());
        }

        $this->assertOk($res, 'the completion', $this->url('/v1/messages'));
        $this->assertJson($res, 'the completion');

        $text = self::extractText(is_array($res->data) ? $res->data : []);
        if ($text === '') {
            throw HttpException::badRequest('Anthropic returned an empty response' . self::whyEmpty($res->data) . '.');
        }
        return $text;
    }

    /* ---------------------------------------------------------------- batch */

    /** @param array<int,BatchItemRequest> $items */
    public function submitBatch(array $items): BatchHandle
    {
        $this->assertConfigured();
        if ($items === []) {
            throw HttpException::unprocessable('There is nothing to submit.');
        }
        if (count($items) > self::BATCH_LIMIT) {
            throw HttpException::unprocessable(
                'Anthropic accepts at most ' . number_format(self::BATCH_LIMIT) . ' requests per batch.'
            );
        }

        $requests = [];
        foreach ($items as $item) {
            self::assertCustomId($item->customId);
            $this->assertModel($item->request);
            // max_tokens: 0 and stream are both rejected inside a batch, and
            // payload() never emits either.
            $requests[] = ['custom_id' => $item->customId, 'params' => $this->payload($item->request)];
        }

        $res = $this->send('POST', '/v1/messages/batches', ['requests' => $requests], $this->metaTimeout());
        $this->assertOk($res, 'the batch submission', $this->url('/v1/messages/batches'));
        $this->assertJson($res, 'the batch submission');

        $id = (string)($res->data['id'] ?? '');
        if ($id === '') {
            throw HttpException::badRequest('Anthropic accepted the batch but returned no id: ' . Text::snippet($res->raw));
        }

        return new BatchHandle(
            $id,
            (string)($res->data['processing_status'] ?? ''),
            '',
            self::timestamp($res->data['expires_at'] ?? null),
        );
    }

    public function pollBatch(string $remoteId, string $resultsRef = ''): BatchStatus
    {
        $this->assertConfigured();
        $path = '/v1/messages/batches/' . rawurlencode($remoteId);

        $res = $this->send('GET', $path, null, $this->metaTimeout());
        $this->assertOk($res, 'the batch status', $this->url($path));
        $this->assertJson($res, 'the batch status');

        $remote = (string)($res->data['processing_status'] ?? '');
        $counts = [];
        foreach ((array)($res->data['request_counts'] ?? []) as $key => $value) {
            $counts[(string)$key] = (int)$value;
        }

        // Exactly three values exist: in_progress, canceling, ended. A batch
        // that was cancelled or that expired still ends up in "ended" - the
        // outcome shows up per request, in the result lines.
        $state = $remote === 'ended' ? BatchStatus::ENDED : BatchStatus::RUNNING;

        return new BatchStatus($state, $remote, $counts, '', '');
    }

    /** @return array<string,BatchItemResult> */
    public function fetchBatchResults(string $remoteId, string $resultsRef = ''): array
    {
        $this->assertConfigured();

        // Always the canonical path rather than the returned results_url: the
        // auth header travels with the request, and cURL keeps headers across a
        // redirect, so a URL from the response is a needless place to leak a key.
        $url = $this->url('/v1/messages/batches/' . rawurlencode($remoteId) . '/results');

        $res = $this->sendRaw('GET', $url, $this->chatTimeout());
        $this->assertOk($res, 'the batch results', $url);

        $results = [];
        foreach (self::jsonLines($res->raw) as $line) {
            $customId = (string)($line['custom_id'] ?? '');
            if ($customId === '') {
                continue;
            }
            $result = is_array($line['result'] ?? null) ? $line['result'] : [];
            $type = (string)($result['type'] ?? '');

            if ($type === 'succeeded') {
                $message = is_array($result['message'] ?? null) ? $result['message'] : [];
                $text = self::extractText($message);
                $results[$customId] = $text !== ''
                    ? BatchItemResult::ok($customId, $text)
                    : BatchItemResult::failed($customId, 'errored', 'Empty response' . self::whyEmpty($message) . '.');
                continue;
            }

            if ($type === 'errored') {
                $results[$customId] = BatchItemResult::failed($customId, 'errored', self::batchError($result));
                continue;
            }

            $results[$customId] = BatchItemResult::failed(
                $customId,
                $type !== '' ? $type : 'errored',
                $type === 'expired'
                    ? 'The request was still queued when the batch hit its 24 hour limit.'
                    : 'The request was cancelled before it ran.'
            );
        }

        return $results;
    }

    public function cancelBatch(string $remoteId): void
    {
        $this->assertConfigured();
        $path = '/v1/messages/batches/' . rawurlencode($remoteId) . '/cancel';
        $res = $this->send('POST', $path, null, $this->metaTimeout());

        // A batch that already ended cannot be cancelled, and saying so is not
        // useful to anyone: the caller only wants it stopped if it still can be.
        if (!$res->ok() && $res->status !== 404 && $res->status !== 409 && $res->status !== 400) {
            $this->assertOk($res, 'the batch cancellation', $this->url($path));
        }
    }

    /* ------------------------------------------------------------ internals */

    /** @return array<string,mixed> */
    private function payload(AiRequest $request): array
    {
        $payload = [
            'model' => $request->model,
            'max_tokens' => $this->maxTokens($request),
            'messages' => [['role' => 'user', 'content' => $request->user]],
        ];
        if (trim($request->system) !== '') {
            $payload['system'] = $request->system;
        }
        if (self::acceptsSampling($request->model)) {
            // Anthropic's ceiling is 1.0; CourseForge's slider goes to 2.0
            // because OpenAI's does.
            $payload['temperature'] = max(0.0, min(1.0, $request->temperature));
        }
        return $payload;
    }

    /**
     * Unlike OpenAI, there is no "let the provider decide" here: the field is
     * required. A slot left at 0 gets the configured default.
     */
    private function maxTokens(AiRequest $request): int
    {
        if ($request->maxTokens > 0) {
            return $request->maxTokens;
        }
        return max(1, Config::int('app.anthropic_max_tokens', 16000));
    }

    private function assertModel(AiRequest $request): void
    {
        if (trim($request->model) === '') {
            throw HttpException::unprocessable('No model is selected for this request.');
        }
    }

    private static function acceptsSampling(string $model): bool
    {
        $model = strtolower(trim($model));
        foreach (self::NO_SAMPLING as $blocked) {
            if ($model === $blocked || str_starts_with($model, $blocked . '-')) {
                return false;
            }
        }
        return true;
    }

    private static function blamesSampling(string $message): bool
    {
        $message = strtolower($message);
        return str_contains($message, 'temperature') || str_contains($message, 'top_p') || str_contains($message, 'top_k');
    }

    /**
     * The assistant text out of one Message object.
     *
     * Every block whose type is not `text` is skipped rather than rejected:
     * `thinking` arrives first on the current models, and the block union grows
     * with every release.
     *
     * @param array<string,mixed> $message
     */
    private static function extractText(array $message): string
    {
        $parts = [];
        foreach ((array)($message['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }
        return trim(implode('', $parts));
    }

    /** The reason an answer came back with no text, phrased for the person reading it. */
    private static function whyEmpty(mixed $message): string
    {
        if (!is_array($message)) {
            return '';
        }
        $stop = (string)($message['stop_reason'] ?? '');
        if ($stop === 'max_tokens') {
            return ' (stop_reason=max_tokens - raise "Max tokens" for this slot)';
        }
        if ($stop === 'refusal') {
            $why = trim((string)($message['stop_details']['explanation'] ?? ''));
            return ' (the model declined the request' . ($why !== '' ? ': ' . mb_substr($why, 0, 200) : '') . ')';
        }
        return $stop !== '' ? ' (stop_reason=' . $stop . ')' : '';
    }

    /** The error text of one failed batch line, which is nested one level deeper than you expect. */
    private static function batchError(array $result): string
    {
        $error = is_array($result['error'] ?? null) ? $result['error'] : [];
        $inner = is_array($error['error'] ?? null) ? $error['error'] : [];

        $message = (string)($inner['message'] ?? $error['message'] ?? '');
        $type = (string)($inner['type'] ?? $error['type'] ?? '');

        if ($message === '' && $type === '') {
            return 'The provider reported an error without a message.';
        }
        return trim(($type !== '' ? $type . ': ' : '') . $message);
    }

    private static function assertCustomId(string $id): void
    {
        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id) !== 1) {
            throw HttpException::unprocessable(
                'Anthropic only accepts batch ids of 1-64 letters, digits, hyphens or underscores - got "' . $id . '".'
            );
        }
    }

    /**
     * Batch results arrive as JSONL, one object per line.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function jsonLines(string $body): array
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

    /** RFC 3339 to a Unix timestamp, and 0 for anything unparseable. */
    private static function timestamp(mixed $value): int
    {
        if (!is_string($value) || trim($value) === '') {
            return 0;
        }
        $time = strtotime($value);
        return $time === false ? 0 : $time;
    }
}
