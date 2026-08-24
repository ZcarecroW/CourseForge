<?php
declare(strict_types=1);

namespace CourseForge\Api;

use CourseForge\Domain\McpClients;
use CourseForge\Support\Config;
use CourseForge\Support\Request;

/**
 * Connecting a Claude client to this installation.
 *
 * The whole feature is one screen: press a button, copy a line, paste it into
 * Claude. Everything below exists to make that true - the endpoint URL is worked
 * out from the request rather than configured, and the token is created here
 * rather than typed into a file on the server.
 */
final class ConnectController
{
    /** @return array<string,mixed> */
    public static function index(Request $request, string $username): array
    {
        return [
            'connect' => [
                'url' => self::endpointUrl(),
                'clients' => McpClients::all($username),
                'enabled' => Config::get('mcp.enabled') === null || Config::bool('mcp.enabled', true),
            ],
        ];
    }

    /**
     * Issues a token. This is the only time it is ever readable.
     *
     * @return array<string,mixed>
     */
    public static function create(Request $request, string $username): array
    {
        $created = McpClients::create($username, $request->str('name', 'Claude'));

        return [
            'connect' => [
                'url' => self::endpointUrl(),
                'clients' => McpClients::all($username),
                'enabled' => Config::get('mcp.enabled') === null || Config::bool('mcp.enabled', true),
            ],
            // Returned once, shown once, never recoverable.
            'token' => $created['token'],
            'client' => $created['client'],
        ];
    }

    /** @return array<string,mixed> */
    public static function delete(Request $request, string $username): array
    {
        McpClients::delete($username, $request->requiredId('client_id', 'Connection id'));
        return ['connect' => [
            'url' => self::endpointUrl(),
            'clients' => McpClients::all($username),
            'enabled' => Config::get('mcp.enabled') === null || Config::bool('mcp.enabled', true),
        ]];
    }

    /* ------------------------------------------------------------ internals */

    /**
     * The public address of the MCP endpoint.
     *
     * Derived from the request that is asking, because that is the address the
     * user actually reached the app on - which is the one their Claude client
     * will be able to reach too. A configured value wins when there is one, for
     * installs behind a proxy that rewrites the host.
     */
    private static function endpointUrl(): string
    {
        $configured = trim(Config::str('mcp.public_url', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;

        $host = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');
        $host = trim(explode(',', $host)[0]);

        // The app lives wherever index.html does, and api/mcp.php sits beside
        // the front controller. The last two segments are cut off by hand
        // rather than with dirname(), which uses the platform's separator and
        // would put a backslash in a URL when the server runs on Windows.
        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/api/index.php');
        $base = rtrim((string)preg_replace('#/api/[^/]*$#', '', $script), '/');

        return ($https ? 'https://' : 'http://') . $host . $base . '/api/mcp.php';
    }
}
