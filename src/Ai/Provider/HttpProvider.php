<?php
declare(strict_types=1);

namespace CourseForge\Ai\Provider;

use CourseForge\Support\Config;
use CourseForge\Support\Http;
use CourseForge\Support\HttpException;
use CourseForge\Support\HttpResult;
use CourseForge\Support\Text;
use Throwable;

/**
 * Shared plumbing for the three providers that are reached over HTTP.
 *
 * It owns the base URL, the credentials and – more usefully – one consistent
 * way of turning a failed call into a message a person can act on. Every
 * provider phrases its errors differently; a user only ever sees which URL was
 * called, what the status was and what the body said.
 */
abstract class HttpProvider implements Provider
{
    protected readonly string $baseUrl;
    protected readonly string $apiKey;

    /** @param array<string,mixed> $account */
    public function __construct(protected readonly array $account)
    {
        $this->baseUrl = $this->normaliseBaseUrl((string)($account['base_url'] ?? ''));
        $this->apiKey = trim((string)($account['api_key'] ?? ''));
    }

    /** The default a fresh account of this kind starts with. */
    abstract public static function defaultBaseUrl(): string;

    /** @return array<string,string> */
    abstract protected function headers(): array;

    /**
     * Providers that anchor on a version segment override this so both
     * `https://host` and `https://host/v1` end up meaning the same thing.
     */
    protected function normaliseBaseUrl(string $url): string
    {
        return rtrim(trim($url), '/');
    }

    protected function url(string $path): string
    {
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    protected function chatTimeout(): int
    {
        return max(60, Config::int('app.ai_timeout_seconds', 1800));
    }

    protected function metaTimeout(): int
    {
        return max(15, Config::int('app.ai_models_timeout_seconds', 240));
    }

    /** @param array<string,mixed>|null $payload */
    protected function send(string $method, string $path, ?array $payload, int $timeout): HttpResult
    {
        $url = $this->url($path);
        try {
            // follow: false – the key travels in a custom header, and cURL only
            // strips `Authorization` across a redirect, not `x-api-key`.
            return Http::json($method, $url, $this->headers(), $payload, $timeout, false);
        } catch (Throwable $e) {
            throw HttpException::badRequest(
                $this->label() . ': the request to ' . $url . ' crashed – ' . $e->getMessage()
            );
        }
    }

    /** Raw GET, for result payloads that are not JSON (batch output is JSONL). */
    protected function sendRaw(string $method, string $url, int $timeout): HttpResult
    {
        try {
            return Http::request($method, $url, $this->headers(), null, $timeout, false);
        } catch (Throwable $e) {
            throw HttpException::badRequest(
                $this->label() . ': the request to ' . $url . ' crashed – ' . $e->getMessage()
            );
        }
    }

    /** Turns any non-2xx or unreachable result into the one error a user reads. */
    protected function assertOk(HttpResult $res, string $what, string $url = ''): void
    {
        $where = $url !== '' ? ' (' . $url . ')' : '';
        if ($res->unreachable()) {
            throw HttpException::badRequest(
                $this->label() . ': could not reach the endpoint' . $where . ' – ' . $res->error . '.'
            );
        }
        if ($res->truncated()) {
            throw HttpException::badRequest(
                $this->label() . ': the connection dropped part way through ' . $what . $where
                    . ' – ' . $res->error . '. Nothing was stored; try again.'
            );
        }
        if ($res->status >= 300 && $res->status < 400) {
            // Redirects are not followed on a credentialed request, so a 3xx
            // is a configuration problem rather than something to chase.
            throw HttpException::badRequest(
                $this->label() . ': the endpoint redirected' . $where . ' (HTTP ' . $res->status . '). '
                    . 'Point the base URL at the address it redirects to.'
            );
        }
        if ($res->status === 401 || $res->status === 403) {
            throw HttpException::badRequest(
                $this->label() . ': ' . $what . ' was refused (HTTP ' . $res->status . '). '
                . 'Check the API key and its permissions. ' . $res->errorMessage(300)
            );
        }
        if ($res->status === 429) {
            throw HttpException::badRequest(
                $this->label() . ': rate limited (HTTP 429) during ' . $what . '. ' . $res->errorMessage(300)
            );
        }
        if (!$res->ok()) {
            throw HttpException::badRequest(
                $this->label() . ': ' . $what . ' failed (HTTP ' . $res->status . '): ' . $res->errorMessage(500)
            );
        }
    }

    /**
     * The body itself never reaches the caller here, only what kind of thing
     * it was: the address is typed by a person, and a wrong one would
     * otherwise have whatever it points at quoted back through CourseForge.
     */
    protected function assertJson(HttpResult $res, string $what): void
    {
        if (!is_array($res->data)) {
            throw HttpException::badRequest(
                $this->label() . ': ' . $what . ' did not return JSON (HTTP ' . $res->status . '). '
                . $res->errorMessage(200)
            );
        }
    }

    protected function assertConfigured(): void
    {
        if ($this->baseUrl === '') {
            throw HttpException::unprocessable(
                'This ' . $this->label() . ' account has no base URL (for example ' . static::defaultBaseUrl() . ').'
            );
        }
        if (preg_match('#^https?://#i', $this->baseUrl) !== 1) {
            throw HttpException::unprocessable(
                'The base URL must start with http:// or https:// – got "' . $this->baseUrl . '".'
            );
        }
        if ($this->apiKey === '') {
            throw HttpException::unprocessable('This ' . $this->label() . ' account has no API key.');
        }
    }

    /**
     * Model ids out of any reasonable list shape, deduplicated and sorted.
     *
     * @param array<int,mixed> $items
     * @return string[]
     */
    protected static function collectModelIds(array $items): array
    {
        $models = [];
        foreach ($items as $item) {
            $id = self::modelId($item);
            if ($id !== null) {
                $models[] = $id;
            }
        }
        $models = array_values(array_unique($models));
        sort($models, SORT_NATURAL | SORT_FLAG_CASE);
        return $models;
    }

    private static function modelId(mixed $item): ?string
    {
        if (is_string($item)) {
            return trim($item) !== '' ? trim($item) : null;
        }
        if (!is_array($item)) {
            return null;
        }
        foreach (['id', 'model', 'slug', 'name'] as $key) {
            if (isset($item[$key]) && is_string($item[$key]) && trim($item[$key]) !== '') {
                return trim($item[$key]);
            }
        }
        return null;
    }
}
