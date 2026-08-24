<?php
/**
 * CourseForge 3 - the Model Context Protocol endpoint.
 *
 * A second, deliberately separate front door. api/index.php serves the browser:
 * cookie session, CSRF token, one user signed in at a time. This one serves an
 * MCP client - Claude Code on the same machine, or the Claude desktop app over
 * the internet - which has none of those things and authenticates with a bearer
 * token instead.
 *
 * Turning it on is three lines in data/config.json:
 *
 *   "mcp": { "enabled": true, "token": "<a long random string>", "username": "you" }
 *
 * and then, in a terminal:
 *
 *   claude mcp add --transport http courseforge https://example.com/api/mcp.php \
 *     --header "Authorization: Bearer <the same string>"
 *
 * It stays off until all three are set, because it exposes one user's courses
 * to anyone holding the token.
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use CourseForge\Mcp\Server;
use CourseForge\Support\Response;
use CourseForge\Support\Runtime;

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

try {
    Server::handle();
} catch (Throwable $e) {
    Runtime::log('mcp-boot', $e);
    Response::send(['jsonrpc' => '2.0', 'id' => null, 'error' => [
        'code' => -32603,
        'message' => 'The MCP endpoint could not start. See the server log.',
    ]], 500);
}
