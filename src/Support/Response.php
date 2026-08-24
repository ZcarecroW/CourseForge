<?php
declare(strict_types=1);

namespace CourseForge\Support;

/**
 * The only place that writes to the output buffer.
 *
 * Every response is JSON, carries the hardening headers and ends the request,
 * so a half-rendered body can never reach the browser.
 */
final class Response
{
    /**
     * @param array<string,mixed> $payload
     * @param array<string,string> $headers
     */
    public static function send(array $payload, int $status = 200, array $headers = []): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
            foreach ($headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }

    /** @param array<string,mixed> $payload */
    public static function ok(array $payload = []): never
    {
        self::send(['ok' => true] + $payload);
    }

    /** @param array<string,mixed> $extra */
    public static function fail(string $error, int $status = 400, array $extra = []): never
    {
        $headers = [];
        if (isset($extra['allow']) && is_array($extra['allow'])) {
            $headers['Allow'] = implode(', ', array_unique([...$extra['allow'], 'OPTIONS']));
            unset($extra['allow']);
        }
        self::send(['ok' => false, 'error' => $error] + $extra, $status, $headers);
    }
}
