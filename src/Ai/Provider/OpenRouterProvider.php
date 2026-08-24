<?php
declare(strict_types=1);

namespace CourseForge\Ai\Provider;

use CourseForge\Ai\Batch\BatchHandle;
use CourseForge\Ai\Batch\BatchItemRequest;
use CourseForge\Ai\Batch\BatchItemResult;
use CourseForge\Ai\Batch\BatchStatus;
use CourseForge\Support\Config;
use CourseForge\Support\HttpException;
use CourseForge\Support\Text;

/**
 * OpenRouter.
 *
 * The synchronous half is plain OpenAI, so it is inherited. Three things are
 * not:
 *
 *   - the catalogue publishes batch pricing as separate model slugs ending in
 *     `:batch`, which is where CourseForge's own `:batch` convention comes
 *     from - picking `anthropic/claude-opus-5:batch` from the model list does
 *     exactly what its name says,
 *   - the batch queue lives under /api/beta rather than /api/v1, takes the
 *     requests inline instead of as an uploaded file, and hands the results
 *     back inside the status response,
 *   - an upstream failure can arrive as a perfectly successful HTTP 200 with
 *     an `error` key in the body, which the inherited body check catches.
 */
final class OpenRouterProvider extends OpenAiProvider
{
    /** No documented ceiling; this is a sanity bound, not a published limit. */
    private const BATCH_LIMIT = 50000;

    /** @var string[] filled as a side effect of models() */
    private array $batchable = [];

    public static function defaultBaseUrl(): string
    {
        return 'https://openrouter.ai/api/v1';
    }

    public function kind(): string
    {
        return Providers::OPENROUTER;
    }

    public function label(): string
    {
        return 'OpenRouter';
    }

    public function batchLimit(): int
    {
        return self::BATCH_LIMIT;
    }

    /** OpenRouter always has the queue; there is nothing to probe. */
    public function supportsBatch(): bool
    {
        return true;
    }

    /**
     * Bearer plus the two attribution headers.
     *
     * HTTP-Referer is what OpenRouter identifies an application by; without it
     * the requests are anonymous. Both are optional and both default to
     * something sensible so a user who does not care never has to fill them in.
     *
     * @return array<string,string>
     */
    protected function headers(): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->apiKey];

        $site = trim((string)($this->account['site_url'] ?? ''));
        if ($site !== '') {
            $headers['HTTP-Referer'] = $site;
        }
        $title = trim((string)($this->account['site_name'] ?? ''));
        $headers['X-OpenRouter-Title'] = $title !== '' ? $title : Config::str('app.name', 'CourseForge');

        return $headers;
    }

    /* --------------------------------------------------------------- models */

    /** @return string[] */
    public function models(): array
    {
        $models = parent::models();

        // A model can be batched exactly when the catalogue also carries its
        // `:batch` twin. There is no capability field to ask instead.
        $ids = array_flip($models);
        $batchable = [];
        foreach ($models as $id) {
            if (str_ends_with($id, ':batch')) {
                $base = substr($id, 0, -strlen(':batch'));
                if (isset($ids[$base])) {
                    $batchable[] = $base;
                }
            }
        }
        sort($batchable, SORT_NATURAL | SORT_FLAG_CASE);
        $this->batchable = array_values(array_unique($batchable));

        return $models;
    }

    /** @return string[] */
    public function batchModels(): array
    {
        return $this->batchable;
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
                'OpenRouter batches are capped at ' . number_format(self::BATCH_LIMIT) . ' requests here.'
            );
        }

        // The model is a property of the batch, not of each request.
        $model = $items[0]->request->model;
        $requests = [];
        foreach ($items as $item) {
            if ($item->request->model !== $model) {
                throw HttpException::unprocessable('An OpenRouter batch can only use one model at a time.');
            }
            $body = $this->payload($item->request);
            unset($body['model']); // inherited from the batch
            $requests[] = ['custom_id' => $item->customId, 'body' => $body];
        }

        // Key order is load-bearing: OpenRouter streams the body as it parses,
        // so `endpoint` and `model` have to be on the wire before `requests` or
        // the submission is rejected. PHP keeps insertion order through
        // json_encode, which is the only reason this reads as ordinary code.
        $payload = [
            'endpoint' => '/v1/chat/completions',
            'model' => $model,
            'requests' => $requests,
        ];

        $url = $this->betaUrl('/batches');
        $res = $this->sendBeta('POST', $url, $payload, $this->chatTimeout());
        $this->assertOk($res, 'the batch submission', $url);
        $this->assertJson($res, 'the batch submission');

        $id = (string)($res->data['id'] ?? '');
        if ($id === '') {
            throw HttpException::badRequest('OpenRouter accepted the batch but returned no id: ' . Text::snippet($res->raw));
        }

        return new BatchHandle($id, (string)($res->data['status'] ?? ''), '', 0);
    }

    public function pollBatch(string $remoteId, string $resultsRef = ''): BatchStatus
    {
        $batch = $this->batch($remoteId);

        $remote = strtolower((string)($batch['status'] ?? ''));
        $counts = [];
        foreach ((array)($batch['request_counts'] ?? []) as $key => $value) {
            $counts[(string)$key] = (int)$value;
        }

        $state = match ($remote) {
            'completed' => BatchStatus::ENDED,
            'expired' => BatchStatus::ENDED,
            'cancelled', 'canceled' => BatchStatus::CANCELED,
            'failed' => BatchStatus::FAILED,
            default => BatchStatus::RUNNING,
        };

        $error = '';
        if (isset($batch['error'])) {
            $error = is_array($batch['error'])
                ? (string)($batch['error']['message'] ?? '')
                : (string)$batch['error'];
        }

        return new BatchStatus($state, $remote, $counts, $error, '');
    }

    /**
     * There is no results endpoint: a finished batch carries them inline, so
     * this is the same GET the poll makes.
     *
     * @return array<string,BatchItemResult>
     */
    public function fetchBatchResults(string $remoteId, string $resultsRef = ''): array
    {
        $batch = $this->batch($remoteId);
        $rows = is_array($batch['results'] ?? null) ? $batch['results'] : [];

        $results = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $customId = (string)($row['custom_id'] ?? '');
            if ($customId === '') {
                continue;
            }

            if (is_array($row['error'] ?? null)) {
                $results[$customId] = BatchItemResult::failed(
                    $customId,
                    'errored',
                    trim((string)($row['error']['message'] ?? 'The request failed without a message.'))
                );
                continue;
            }

            $response = is_array($row['response'] ?? null) ? $row['response'] : [];
            $status = (int)($response['status_code'] ?? 0);
            $body = is_array($response['body'] ?? null) ? $response['body'] : [];

            if ($status !== 0 && ($status < 200 || $status >= 300)) {
                $results[$customId] = BatchItemResult::failed(
                    $customId,
                    'errored',
                    (string)($body['error']['message'] ?? 'HTTP ' . $status . '.')
                );
                continue;
            }

            $content = self::extractContent($body);
            $results[$customId] = $content !== ''
                ? BatchItemResult::ok($customId, $content)
                : BatchItemResult::failed($customId, 'errored', 'Empty response.');
        }
        return $results;
    }

    /**
     * Undocumented but implied by the status vocabulary, which includes
     * `cancelling` and `cancelled`. Any failure is swallowed: a cancel that is
     * not supported must not look like a broken course.
     */
    public function cancelBatch(string $remoteId): void
    {
        $this->assertConfigured();
        try {
            $this->sendBeta('POST', $this->betaUrl('/batches/' . rawurlencode($remoteId) . '/cancel'), null, $this->metaTimeout());
        } catch (\Throwable) {
            // nothing to do - the caller already stopped tracking it
        }
    }

    /* ------------------------------------------------------------ internals */

    /** @return array<string,mixed> */
    private function batch(string $remoteId): array
    {
        $this->assertConfigured();
        $url = $this->betaUrl('/batches/' . rawurlencode($remoteId));

        $res = $this->sendBeta('GET', $url, null, $this->chatTimeout());
        $this->assertOk($res, 'the batch status', $url);
        $this->assertJson($res, 'the batch status');

        return is_array($res->data) ? $res->data : [];
    }

    /** The batch API sits beside /v1, not inside it. */
    private function betaUrl(string $path): string
    {
        $root = (string)preg_replace('#/v1$#i', '', $this->baseUrl);
        return $root . '/beta' . '/' . ltrim($path, '/');
    }

    /** @param array<string,mixed>|null $payload */
    private function sendBeta(string $method, string $url, ?array $payload, int $timeout)
    {
        try {
            return \CourseForge\Support\Http::json($method, $url, $this->headers(), $payload, $timeout);
        } catch (\Throwable $e) {
            throw HttpException::badRequest('OpenRouter: the request to ' . $url . ' crashed - ' . $e->getMessage());
        }
    }
}
