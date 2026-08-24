<?php
declare(strict_types=1);

namespace CourseForge\Mcp;

use CourseForge\Domain\McpClients;
use CourseForge\Support\Config;
use CourseForge\Support\HttpException;
use CourseForge\Support\Runtime;
use Throwable;

/**
 * A Model Context Protocol endpoint, over streamable HTTP.
 *
 * Small on purpose. The protocol allows a server to answer every request with
 * plain `application/json`, so there is no SSE stream, no long-lived
 * connection and no state to keep between requests - which is exactly what a
 * PHP application can offer without changing what it is.
 *
 * The handshake a current Claude Code makes, in order:
 *
 *   1. `server/discover`      - the newer protocol revision. Answering
 *                               JSON-RPC -32601 makes it fall back cleanly.
 *                               Failing to answer at all breaks the connection
 *                               before it starts, so unknown methods must
 *                               always come back as a proper error object.
 *   2. `initialize`           - answered with the client's own protocol
 *                               version, the tools capability and a session id.
 *   3. `notifications/initialized` - a notification: HTTP 202, empty body.
 *   4. `GET` for an SSE stream - 405 is a legitimate answer and the client
 *                               carries on without it.
 *   5. `tools/list`, `tools/call`.
 *
 * Access is a token created in the app, one per connected client, stored only
 * as a hash. An installation with no connections has no way in - this is a
 * second front door into somebody's courses, so it does not open by accident,
 * and it needs no configuration file to close.
 */
final class Server
{
    /** What we answer `initialize` with when the client asks for something unknown. */
    private const PROTOCOL = '2025-11-25';

    /** Revisions this implementation is compatible with, newest last. */
    private const SPOKEN = ['2025-03-26', '2025-06-18', '2025-11-25'];

    public static function handle(): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        // A DNS-rebinding defence the spec asks for by name: a page on another
        // origin must not be able to drive this endpoint from a browser.
        if (!self::originAllowed()) {
            self::send(['error' => 'Origin not allowed.'], 403);
        }

        if ($method === 'OPTIONS') {
            self::send(['ok' => true], 200, ['Allow' => 'POST, OPTIONS']);
        }
        if ($method === 'DELETE') {
            self::send(['ok' => true], 200); // session teardown; nothing is kept
        }
        if ($method !== 'POST') {
            // The optional server-to-client stream. Declining it is allowed.
            self::send(['error' => 'This endpoint speaks JSON-RPC over POST only.'], 405, ['Allow' => 'POST']);
        }

        $username = self::authenticate();

        $raw = (string)file_get_contents('php://input');
        $message = json_decode($raw, true);
        if (!is_array($message)) {
            self::send(self::error(null, -32700, 'The request body was not valid JSON.'), 400);
        }

        // A batch of calls is legal in JSON-RPC; answering one at a time keeps
        // the rest of this file honest about what it supports.
        if (array_is_list($message)) {
            self::send(self::error(null, -32600, 'Batched JSON-RPC requests are not supported here.'), 400);
        }

        $id = $message['id'] ?? null;
        $rpc = (string)($message['method'] ?? '');
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        // A notification carries no id and expects no answer at all.
        if ($id === null && str_starts_with($rpc, 'notifications/')) {
            http_response_code(202);
            exit;
        }

        try {
            $result = self::dispatch($username, $rpc, $params);
        } catch (HttpException $e) {
            self::send(self::error($id, -32602, $e->getMessage()), 200);
        } catch (Throwable $e) {
            Runtime::log('mcp', $e);
            self::send(self::error($id, -32603, Runtime::debug() ? $e->getMessage() : 'Internal error.'), 200);
        }

        if ($result === null) {
            // Unknown method. This is the one that has to be right, or the
            // client's first probe kills the connection.
            self::send(self::error($id, -32601, 'Method "' . $rpc . '" is not implemented.'), 200);
        }

        $headers = $rpc === 'initialize' ? ['Mcp-Session-Id' => self::sessionId()] : [];
        self::send(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], 200, $headers);
    }

    /* ------------------------------------------------------------- dispatch */

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|\stdClass|null null means "no such method"
     */
    private static function dispatch(string $username, string $rpc, array $params): array|\stdClass|null
    {
        return match ($rpc) {
            'initialize' => [
                // Echo the client's version when we can speak it; otherwise
                // name ours and let it decide whether to continue.
                'protocolVersion' => self::negotiate((string)($params['protocolVersion'] ?? '')),
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'courseforge', 'version' => CF_VERSION],
                'instructions' => 'CourseForge turns a course outline into written pages. Call list_courses to see '
                    . 'what exists, get_page_brief for the next page that needs writing, write the page from the '
                    . 'brief it gives you, and send it back with write_page.',
            ],
            // The keep-alive. An empty PHP array would serialise as `[]`, and the
            // official clients validate the result as an object - so it has to be
            // a real empty object.
            'ping' => new \stdClass(),
            'tools/list' => ['tools' => Tools::catalogue()],
            'tools/call' => self::callTool($username, $params),
            default => null,
        };
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private static function callTool(string $username, array $params): array
    {
        $name = (string)($params['name'] ?? '');
        // `_meta` arrives alongside the arguments and is none of our business.
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if ($name === '') {
            return self::toolResult('No tool name was given.', true);
        }

        Runtime::beginLongRequest();

        try {
            return self::toolResult(Tools::call($username, $name, $arguments), false);
        } catch (HttpException $e) {
            // A tool that fails is a result the model can read and act on, not
            // a transport error - that is what isError is for.
            return self::toolResult($e->getMessage(), true);
        } catch (Throwable $e) {
            Runtime::log('mcp-tool', $e);
            return self::toolResult(
                Runtime::debug() ? $e->getMessage() : 'The tool failed. See the server log.',
                true
            );
        }
    }

    /** @return array<string,mixed> */
    private static function toolResult(string $text, bool $isError): array
    {
        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => $isError];
    }

    /* --------------------------------------------------------------- access */

    /**
     * The user whose courses this request may see.
     *
     * The token identifies the connection, and the connection identifies the
     * user - so there is nothing to configure in a file and nothing to keep in
     * step. An installation with no connections has no way in at all.
     */
    private static function authenticate(): string
    {
        // A kill switch for an administrator who wants the endpoint off
        // regardless of what tokens exist. Absent from config.json means on.
        if (Config::get('mcp.enabled') !== null && !Config::bool('mcp.enabled', true)) {
            self::send(['error' => 'This endpoint is switched off.'], 404);
        }

        $username = McpClients::resolve(self::presentedToken());
        if ($username === null) {
            self::send(
                ['error' => 'A valid token is required. Create one in CourseForge under Connect.'],
                401,
                ['WWW-Authenticate' => 'Bearer']
            );
        }
        return $username;
    }

    /**
     * The token, from wherever the client could put it.
     *
     * `Authorization: Bearer` is the right answer and what `claude mcp add
     * --header` sends. The query string is there because the Claude desktop
     * app's "add a custom connector" form takes a URL and nothing else, and a
     * connector that cannot be added is worse than one whose secret is in a URL
     * - the trade-off is documented rather than decided silently.
     */
    private static function presentedToken(): string
    {
        $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($header === '' && function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $header = (string)$value;
                    break;
                }
            }
        }
        if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $m) === 1) {
            return trim($m[1]);
        }

        $query = (string)($_GET['token'] ?? '');
        return trim($query);
    }

    /**
     * Only same-origin or origin-less requests.
     *
     * An MCP client is a desktop application and sends no Origin at all, so the
     * absent case has to pass; a browser always sends one, which is precisely
     * the case worth refusing.
     */
    private static function originAllowed(): bool
    {
        $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
        if ($origin === '') {
            return true;
        }
        $host = strtolower((string)parse_url($origin, PHP_URL_HOST));
        $self = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $self = (string)preg_replace('/:\d+$/', '', $self);

        $allowed = array_map('strtolower', array_map('strval', (array)Config::get('mcp.allowed_origins', [])));
        return $host === $self || in_array($host, $allowed, true);
    }

    /**
     * The CORS headers a browser-based client needs.
     *
     * Only sent for an origin `mcp.allowed_origins` has already accepted, so
     * this widens nothing: without them, listing an origin there would let the
     * request past the gate and the browser would still discard the answer,
     * which made the setting look broken.
     *
     * @return array<string,string>
     */
    private static function corsHeaders(): array
    {
        $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
        if ($origin === '' || !self::originAllowed()) {
            return [];
        }
        return [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, Mcp-Session-Id, MCP-Protocol-Version',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS, DELETE',
            'Access-Control-Expose-Headers' => 'Mcp-Session-Id',
            'Vary' => 'Origin',
        ];
    }

    /* ------------------------------------------------------------ internals */

    /**
     * Answer with the client's revision only when it is one we implement.
     *
     * Echoing back any date-shaped string would claim support for a revision
     * this class has never seen, and the client would then hold us to it. Naming
     * ours instead is what the spec asks for: the client decides whether it can
     * live with the answer.
     */
    private static function negotiate(string $requested): string
    {
        return in_array($requested, self::SPOKEN, true) ? $requested : self::PROTOCOL;
    }

    /**
     * A session id the client echoes back.
     *
     * Nothing is stored against it: every request carries its own token and
     * every tool call is independent, so there is no session state to lose and
     * no session to expire.
     */
    private static function sessionId(): string
    {
        return 'cf-' . bin2hex(random_bytes(16));
    }

    /** @return array<string,mixed> */
    private static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,string> $headers
     */
    private static function send(array $payload, int $status, array $headers = []): never
    {
        // Substitute rather than fail: one malformed byte anywhere in a page
        // must not turn the whole response into an empty body, which a client
        // would read as a successful call that returned nothing.
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($body === false) {
            $status = 500;
            $body = '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"The response could not be encoded."}}';
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        foreach ($headers + self::corsHeaders() as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $body;
        exit;
    }
}
