<?php
declare(strict_types=1);

namespace CourseForge\Support;

use RuntimeException;

/**
 * The single cURL wrapper used for the AI provider and for BookStack.
 *
 * Keep-alive is on because a long generation otherwise gets dropped by
 * intermediate proxies, and redirects keep their method so a 307/308 on a POST
 * does not silently turn into a GET.
 */
final class Http
{
    /**
     * @param array<string,string> $headers
     * @param bool $follow Whether to follow redirects. On for BookStack, which
     *                     is routinely installed behind an http-to-https
     *                     redirect; off for the AI providers, because cURL only
     *                     strips `Authorization` when a redirect changes host -
     *                     a custom header such as Anthropic's `x-api-key` would
     *                     be replayed verbatim to wherever the redirect points.
     */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 60, bool $follow = true): HttpResult
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is not enabled on this server.');
        }

        $headers['User-Agent'] ??= 'CourseForge/' . CF_VERSION . ' (+PHP ' . PHP_VERSION . ')';
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => max(0, $timeout),                                  // 0 = no limit
            CURLOPT_CONNECTTIMEOUT => max(5, Config::int('app.connect_timeout_seconds', 30)),
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,                           // never downgrade on a redirect
            CURLOPT_POSTREDIR => 7,                                               // keep method + body on 301/302/303
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING => '',                                               // accept gzip/deflate
            CURLOPT_NOSIGNAL => true,                                             // safe with long timeouts
            CURLOPT_TCP_KEEPALIVE => 1,                                           // keep idle AI connections alive
            CURLOPT_TCP_KEEPIDLE => 60,
            CURLOPT_TCP_KEEPINTVL => 30,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch); // PHP 8 frees the handle; curl_close() is deprecated in 8.5

        $raw = is_string($response) ? $response : '';
        return new HttpResult(
            $status,
            $raw,
            json_decode($raw, true),
            $error !== '' ? $error . ' (errno ' . $errno . ')' : '',
            $errno,
        );
    }

    /**
     * A multipart/form-data POST, for the one endpoint that needs it: uploading
     * a batch input file to an OpenAI-compatible gateway.
     *
     * The body is assembled here rather than handed to cURL as an array of
     * CURLFile objects, because that form requires the payload to exist as a
     * real file on disk first. CourseForge generates the JSONL in memory and a
     * shared host is exactly where a temp file is most likely to fail.
     *
     * @param array<string,string> $headers
     * @param array<string,string> $fields    plain text fields
     * @param array<string,array{filename:string,type:string,content:string}> $files
     */
    public static function multipart(string $url, array $headers, array $fields, array $files, int $timeout = 60): HttpResult
    {
        // 32 random hex characters: the boundary must not occur inside any part,
        // and there is nothing to escape it with if it does.
        $boundary = '----CourseForge' . bin2hex(random_bytes(16));
        $eol = "\r\n";   // RFC 7578: every delimiter is CRLF, never bare LF
        $body = '';

        foreach ($fields as $name => $value) {
            $body .= '--' . $boundary . $eol
                . 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol
                . $value . $eol;
        }
        foreach ($files as $name => $file) {
            $body .= '--' . $boundary . $eol
                . 'Content-Disposition: form-data; name="' . $name . '"; filename="' . $file['filename'] . '"' . $eol
                . 'Content-Type: ' . $file['type'] . $eol . $eol
                . $file['content'] . $eol;
        }
        $body .= '--' . $boundary . '--' . $eol;

        $headers['Accept'] = 'application/json';
        $headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;

        return self::request('POST', $url, $headers, $body, $timeout, false);
    }

    /** @param array<string,string> $headers */
    public static function json(string $method, string $url, array $headers = [], mixed $payload = null, int $timeout = 60, bool $follow = true): HttpResult
    {
        $headers['Accept'] = 'application/json';
        $body = null;
        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );
            if ($body === false) {
                throw new RuntimeException('The request body could not be encoded as JSON: ' . json_last_error_msg());
            }
        }
        return self::request($method, $url, $headers, $body, $timeout, $follow);
    }
}
