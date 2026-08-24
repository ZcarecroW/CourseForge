<?php
declare(strict_types=1);

namespace CourseForge\Mcp;

use CourseForge\Domain\McpClients;
use CourseForge\Security\Actor;
use CourseForge\Support\Config;
use CourseForge\Support\HttpException;
use CourseForge\Support\Runtime;
use Throwable;

/**
 * A Model Context Protocol endpoint, over streamable HTTP, speaking both eras.
 *
 * The protocol was rebuilt in revision 2026-07-28 and the change is not a
 * detail. MCP is now stateless: there is no `initialize` handshake, no
 * `notifications/initialized`, no session id, no GET stream, and no way for a
 * server to start a conversation. Every request carries its own protocol
 * version and client capabilities in `_meta`, and a server either serves that
 * version or refuses that one request.
 *
 * Meanwhile every client that is actually installed on somebody's machine today
 * still speaks one of the four legacy revisions, and a legacy client has no way
 * to fall forward. So this class is **dual-era**: it answers `initialize` for
 * the clients that ask for it, `server/discover` for the ones that do not, and
 * the same tool surface either way. Which era a request belongs to is read from
 * the request itself, never remembered - which costs nothing here, because
 * CourseForge had no session state to keep in the first place. A PHP
 * application that answers each POST and forgets it was always the shape the
 * new revision has now standardised.
 *
 * The differences that actually change bytes on the wire:
 *
 *   - a modern result carries `resultType`, and a cacheable list also carries
 *     `ttlMs` and `cacheScope`;
 *   - a modern unknown method is HTTP 404, a legacy one is HTTP 200 with a
 *     JSON-RPC error - the legacy client would treat a 404 as a dead server;
 *   - `serverInfo` moved into `_meta`;
 *   - `Mcp-Session-Id` is not minted, echoed or required by either era. A
 *     legacy server is allowed to be sessionless, and this one is.
 *
 * Access is a token created in the app, one per connected client, stored only
 * as a hash, resolved to an account and a role on every request. An
 * installation with no connections has no way in at all.
 */
final class Server
{
    /** The revision this server prefers, and the only stateless one. */
    private const MODERN = '2026-07-28';

    /** Revisions with an `initialize` handshake, newest first. */
    private const LEGACY = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    /**
     * What a client is told when it asks for something we do not speak.
     *
     * Newest first, because that is the order a client picks from.
     */
    private const SUPPORTED = [self::MODERN, '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    /** How long a client may cache `tools/list`. Ten minutes: the surface only changes on an update. */
    private const LIST_TTL_MS = 600000;

    /** Set once per request, so the response knows which shape to take. */
    private static bool $modern = false;

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
        if ($method !== 'POST') {
            // GET was the server-to-client stream and DELETE was session
            // teardown. Neither exists any more, and 405 is the answer the
            // specification names for both.
            self::send(
                ['error' => 'This endpoint speaks JSON-RPC over POST only.'],
                405,
                ['Allow' => 'POST, OPTIONS']
            );
        }

        $context = self::authenticate();

        $raw = (string)file_get_contents('php://input');
        $message = json_decode($raw, true);
        if (!is_array($message)) {
            self::send(self::error(null, -32700, 'The request body was not valid JSON.'), 400);
        }
        if (array_is_list($message)) {
            self::send(self::error(null, -32600, 'Batched JSON-RPC requests are not supported here.'), 400);
        }

        $id = $message['id'] ?? null;
        $rpc = (string)($message['method'] ?? '');
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];

        self::$modern = self::isModern($rpc, $meta);

        // Only checked when the header is actually present. The rule exists so
        // that a proxy routing on the header and a server acting on the body
        // cannot be made to disagree; a client that sends no header creates no
        // such disagreement, and refusing it would break every legacy client.
        self::assertHeadersMatch($id, $rpc, $params);

        if (!self::versionAccepted($meta)) {
            self::send([
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => [
                    'code' => -32022,
                    'message' => 'Unsupported protocol version',
                    'data' => [
                        'supported' => self::SUPPORTED,
                        'requested' => (string)($meta['io.modelcontextprotocol/protocolVersion'] ?? ''),
                    ],
                ],
            ], 400);
        }

        // A notification carries no id and expects no answer at all.
        if ($id === null && str_starts_with($rpc, 'notifications/')) {
            http_response_code(202);
            exit;
        }

        try {
            $result = self::dispatch($context, $rpc, $params);
        } catch (HttpException $e) {
            self::send(self::error($id, -32602, $e->getMessage()), 200);
        } catch (Throwable $e) {
            Runtime::log('mcp', $e);
            self::send(self::error($id, -32603, Runtime::debug() ? $e->getMessage() : 'Internal error.'), 200);
        }

        if ($result === null) {
            // The one answer that has to be right, because it is the first
            // thing a dual-era client tries. A modern client reads 404 as
            // "not this era, fall back"; a legacy client reads any 4xx as a
            // dead server and gives up, so it gets a 200 with the error in it.
            self::send(
                self::error($id, -32601, 'Method "' . $rpc . '" is not implemented.'),
                self::$modern ? 404 : 200
            );
        }

        self::send(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], 200);
    }

    /* ------------------------------------------------------------- dispatch */

    /**
     * @param array{actor:Actor,client_id:int,client_name:string,scopes:string[]} $context
     * @param array<string,mixed> $params
     * @return array<string,mixed>|\stdClass|null null means "no such method"
     */
    private static function dispatch(array $context, string $rpc, array $params): array|\stdClass|null
    {
        return match ($rpc) {
            // Modern. Servers must implement it, and a dual-era client may use
            // it to find out which era it is talking to.
            'server/discover' => self::complete([
                'supportedVersions' => self::SUPPORTED,
                'capabilities' => self::capabilities(),
                'instructions' => self::instructions($context),
                '_meta' => ['io.modelcontextprotocol/serverInfo' => self::serverInfo()],
            ] + self::cacheHints()),

            // Legacy. Answered with the client's own revision when we speak it.
            'initialize' => [
                'protocolVersion' => self::negotiate((string)($params['protocolVersion'] ?? '')),
                'capabilities' => self::capabilities(),
                'serverInfo' => self::serverInfo(),
                'instructions' => self::instructions($context),
            ],

            // The keep-alive. An empty PHP array would serialise as `[]`, and
            // clients validate the result as an object - so it has to be a real
            // empty object.
            'ping' => self::$modern ? self::complete([]) : new \stdClass(),

            'tools/list' => self::complete(
                ['tools' => Tools::catalogue($context['actor'], $context['scopes'])] + self::cacheHints()
            ),

            'tools/call' => self::complete(self::callTool($context, $params)),

            default => null,
        };
    }

    /**
     * @param array{actor:Actor,client_id:int,client_name:string,scopes:string[]} $context
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private static function callTool(array $context, array $params): array
    {
        $name = (string)($params['name'] ?? '');
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if ($name === '') {
            return self::toolResult(['text' => 'No tool name was given.', 'data' => null], true);
        }

        // A tool call may sit on a provider for minutes, or write five hundred
        // rows. Releasing the session lock and the time limit first is what
        // stops it blocking everything else the account has in flight.
        Runtime::beginLongRequest();

        try {
            return self::toolResult(
                Tools::call($context['actor'], $name, $arguments, $context['scopes']),
                false
            );
        } catch (HttpException $e) {
            // A tool that fails is a result the model can read and act on, not
            // a transport error - that is what isError is for.
            return self::toolResult(['text' => $e->getMessage(), 'data' => null], true);
        } catch (Throwable $e) {
            Runtime::log('mcp-tool', $e);
            return self::toolResult([
                'text' => Runtime::debug() ? $e->getMessage() : 'The tool failed. See the server log.',
                'data' => null,
            ], true);
        }
    }

    /**
     * @param array{text:string,data:array<string,mixed>|null} $result
     * @return array<string,mixed>
     */
    private static function toolResult(array $result, bool $isError): array
    {
        $payload = [
            'content' => [['type' => 'text', 'text' => $result['text']]],
            'isError' => $isError,
        ];

        // The structured copy is what a client that understands it will read;
        // the text block is the same data and is what everything else reads.
        // Sending both is what the specification asks for.
        if (!$isError && $result['data'] !== null) {
            $payload['structuredContent'] = $result['data'];
        }
        return $payload;
    }

    /* ------------------------------------------------------- era mechanics */

    /**
     * Whether this request belongs to the modern era.
     *
     * Three signals, any of which is decisive: the version in `_meta`, the
     * version in the header, and the method itself - `server/discover` exists
     * only in the modern revision and `initialize` only in the legacy one.
     *
     * @param array<string,mixed> $meta
     */
    private static function isModern(string $rpc, array $meta): bool
    {
        if ($rpc === 'initialize' || $rpc === 'notifications/initialized') {
            return false;
        }
        if ($rpc === 'server/discover') {
            return true;
        }

        $version = trim((string)($meta['io.modelcontextprotocol/protocolVersion'] ?? ''));
        if ($version === '') {
            $version = trim(self::header('MCP_PROTOCOL_VERSION'));
        }
        return $version === self::MODERN;
    }

    /**
     * A result in the shape the current era expects.
     *
     * `resultType` is required on every modern result and meaningless to a
     * legacy client, which is the whole of the difference.
     *
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private static function complete(array $result): array
    {
        return self::$modern ? ['resultType' => 'complete'] + $result : $result;
    }

    /**
     * Caching hints, which the modern revision requires on every list result.
     *
     * The scope is `private` and not negotiable: the tool surface depends on
     * the account and the connection's scopes, so one client's list must never
     * be served to another from a shared cache.
     *
     * @return array<string,mixed>
     */
    private static function cacheHints(): array
    {
        return self::$modern ? ['ttlMs' => self::LIST_TTL_MS, 'cacheScope' => 'private'] : [];
    }

    /**
     * Whether we speak the revision the request asked for.
     *
     * A request with no version at all is treated as `2025-03-26`, which is
     * what the specification says a server supporting older clients may do.
     *
     * @param array<string,mixed> $meta
     */
    private static function versionAccepted(array $meta): bool
    {
        $version = trim((string)($meta['io.modelcontextprotocol/protocolVersion'] ?? ''));
        if ($version === '') {
            $version = trim(self::header('MCP_PROTOCOL_VERSION'));
        }
        return $version === '' || in_array($version, self::SUPPORTED, true);
    }

    /**
     * The routing headers the modern revision adds.
     *
     * A load balancer may route on `Mcp-Method` while this server acts on the
     * body, so the two disagreeing is a security problem rather than an
     * inconsistency. Non-ASCII values arrive base64-wrapped in a sentinel and
     * have to be decoded before they are compared.
     *
     * @param array<string,mixed> $params
     */
    private static function assertHeadersMatch(mixed $id, string $rpc, array $params): void
    {
        $declared = self::decodeHeaderValue(self::header('MCP_METHOD'));
        if ($declared !== '' && $declared !== $rpc) {
            self::send(self::error($id, -32020, 'The Mcp-Method header does not match the request body.'), 400);
        }

        if (!in_array($rpc, ['tools/call', 'resources/read', 'prompts/get'], true)) {
            return;
        }
        $name = self::decodeHeaderValue(self::header('MCP_NAME'));
        $actual = (string)($params['name'] ?? $params['uri'] ?? '');
        if ($name !== '' && $name !== $actual) {
            self::send(self::error($id, -32020, 'The Mcp-Name header does not match the request body.'), 400);
        }
    }

    /** `=?base64?...?=` is how a header carries a value that is not ASCII. */
    private static function decodeHeaderValue(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^=\?base64\?(.*)\?=$/', $value, $m) === 1) {
            $decoded = base64_decode($m[1], true);
            return $decoded === false ? '' : $decoded;
        }
        return $value;
    }

    /* ------------------------------------------------------------ identity */

    /** @return array<string,mixed> */
    private static function capabilities(): array
    {
        // No resources, no prompts, no subscriptions: this server answers every
        // request with one JSON object and never holds a stream open, so it
        // must not advertise anything that would need one.
        return ['tools' => ['listChanged' => false]];
    }

    /** @return array<string,string> */
    private static function serverInfo(): array
    {
        return ['name' => 'courseforge', 'title' => Config::str('app.name', 'CourseForge'), 'version' => CF_VERSION];
    }

    /**
     * What the server tells a model about itself.
     *
     * Claude Code truncates this at two kilobytes and uses it to decide when to
     * go looking for these tools, so the useful part goes first and the whole
     * thing stays short. It names the two ways a course gets written, because
     * choosing between them is the one decision a model has to make here that
     * it cannot work out from a tool description.
     *
     * @param array{actor:Actor,scopes:string[]} $context
     */
    private static function instructions(array $context): string
    {
        $actor = $context['actor'];

        $text = 'CourseForge turns a one-line brief into a complete course - outline, chapters, written pages - '
            . "and publishes it into a BookStack knowledge base.\n\n"
            . "Start with list_courses, or whoami to see what this connection can do.\n\n"
            . "There are two ways to write a course, and they cost very different amounts:\n"
            . "1. You write it. get_page_brief hands you the exact brief CourseForge would have sent a model - "
            . "the persona, the format contract, the content details resolved for that page, the course structure "
            . "and the page's place in it. You write the page and store it with write_page. This spends nothing "
            . "on the server; the work happens here, on this subscription.\n"
            . "2. CourseForge writes it. start_run queues every unwritten page against the course's own AI "
            . "account and keeps going after you disconnect - either through the background worker or through "
            . "the provider's batch queue at about half price. Use estimate_run first to see the size of it.\n\n"
            . 'Connected as ' . $actor->username . ' (' . $actor->role . ').';

        if ($actor->isAdmin()) {
            $text .= ' Administrator tools for accounts, settings and updates are available.';
        }
        return $text;
    }

    /* --------------------------------------------------------------- access */

    /**
     * The account this request may act as, and what the connection may reach.
     *
     * The token identifies the connection; the connection identifies the
     * account; the account's current role decides what the tools do. Reading
     * the role now rather than freezing it into the token is what makes a
     * demotion or a disabled account take effect on the next request.
     *
     * @return array{actor:Actor,client_id:int,client_name:string,scopes:string[]}
     */
    private static function authenticate(): array
    {
        // A kill switch for an administrator who wants the endpoint off
        // regardless of what tokens exist.
        if (!Config::bool('mcp.enabled', true)) {
            self::send(['error' => 'This endpoint is switched off.'], 404);
        }

        $context = McpClients::resolve(self::presentedToken());
        if ($context === null) {
            self::send(
                ['error' => 'A valid token is required. Create one in CourseForge under Connect.'],
                401,
                ['WWW-Authenticate' => 'Bearer realm="CourseForge"']
            );
        }
        return $context;
    }

    /**
     * The token, from wherever the client could put it.
     *
     * `Authorization: Bearer` is the right answer and what `claude mcp add
     * --header` sends. The query string is there because the Claude desktop
     * app's "add a custom connector" form takes a URL and nothing else, and a
     * connector that cannot be added is worse than one whose secret is in a URL
     * - but it is a genuine trade-off, not a free convenience: a URL ends up in
     * browser history and server logs, and the documentation says so.
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

        return trim((string)($_GET['token'] ?? ''));
    }

    /**
     * Only same-origin or origin-less requests.
     *
     * An MCP client is a desktop or command-line application and sends no
     * Origin at all, so the absent case has to pass; a browser always sends
     * one, which is precisely the case worth refusing.
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

        $allowed = array_map('strtolower', Config::strings('mcp.allowed_origins'));
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
            'Access-Control-Allow-Headers' =>
                'Content-Type, Authorization, MCP-Protocol-Version, Mcp-Method, Mcp-Name, Mcp-Session-Id',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Vary' => 'Origin',
        ];
    }

    /* ------------------------------------------------------------ internals */

    /**
     * Answer a legacy `initialize` with the client's revision when we speak it.
     *
     * Echoing back any date-shaped string would claim support for a revision
     * this class has never seen, and the client would then hold us to it.
     * Naming the newest legacy revision instead is what the specification asks
     * for: the client decides whether it can live with the answer.
     */
    private static function negotiate(string $requested): string
    {
        return in_array($requested, self::LEGACY, true) ? $requested : self::LEGACY[0];
    }

    private static function header(string $name): string
    {
        return (string)($_SERVER['HTTP_' . $name] ?? '');
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
