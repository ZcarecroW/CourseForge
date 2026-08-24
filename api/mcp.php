<?php
/**
 * CourseForge 4 - the Model Context Protocol endpoint.
 *
 * A second, deliberately separate front door. api/index.php serves the browser:
 * cookie session, CSRF token, one account signed in at a time. This one serves
 * an MCP client - Claude Code on the same machine, the Claude desktop app over
 * the internet, Cursor, VS Code - which has none of those things and
 * authenticates with a bearer token instead.
 *
 * Nothing is configured in a file. A connection is created in the application,
 * under Connect, which issues a token once and stores only its hash. That
 * connection belongs to an account, inherits that account's role on every
 * request, and may be limited to some of the tool groups. An installation with
 * no connections has no way in here at all.
 *
 *   claude mcp add --transport http courseforge https://example.com/api/mcp.php  *     --header "Authorization: Bearer cf4_..."
 *
 * The whole protocol lives in src/Mcp/Server.php; this file exists so that the
 * endpoint has a URL of its own and so that a failure during boot still answers
 * in JSON-RPC rather than with a blank 500.
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
