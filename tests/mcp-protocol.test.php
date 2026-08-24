<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Two things the MCP front door has to get right about its own inputs.
 *
 * A client states which revision it speaks in one of two places - inside the
 * request, or in the MCP-Protocol-Version header - and the server used to read
 * both when deciding and only one when explaining. A client that negotiates by
 * header, which is every HTTP-transport client from 2025-06-18 onwards, was
 * refused and told it had asked for "": the one thing the answer existed to
 * say was the one thing missing from it.
 *
 * The routing headers are the other. Mcp-Method and Mcp-Name let a proxy route
 * on what a request claims to be while this server acts on what it actually is,
 * so the two disagreeing is a security problem and the check refuses it. But a
 * header that was not sent, one sent empty, and one whose base64 sentinel would
 * not decode all came back as the same empty string, and an empty string read
 * as "no header, nothing to compare" - so a value this server could not read
 * turned the check off rather than failing it. Absent has to be tellable from
 * present-but-unreadable, and that is what is asserted here.
 *
 * The refusals themselves answer the socket and stop the process, so they are
 * driven over HTTP rather than from here; what these tests hold is the decision
 * each refusal is made from.
 */

use CourseForge\Mcp\Server;

/** One of Server's private decisions, reached by name. @return mixed */
function protocolCall(string $method, mixed ...$args): mixed
{
    return (new ReflectionMethod(Server::class, $method))->invoke(null, ...$args);
}

/** The request headers as PHP would have found them. */
function protocolHeaders(array $headers): void
{
    foreach (['HTTP_MCP_PROTOCOL_VERSION', 'HTTP_MCP_METHOD', 'HTTP_MCP_NAME'] as $key) {
        unset($_SERVER[$key]);
    }
    foreach ($headers as $key => $value) {
        $_SERVER[$key] = $value;
    }
}

const PROTOCOL_META = 'io.modelcontextprotocol/protocolVersion';

test('the version a request is judged on is the version it is told about', static function (): void {
    protocolHeaders(['HTTP_MCP_PROTOCOL_VERSION' => '2019-01-01']);
    same('2019-01-01', protocolCall('requestedVersion', []), 'the header alone');

    protocolHeaders([]);
    same('2019-01-01', protocolCall('requestedVersion', [PROTOCOL_META => '2019-01-01']), '_meta alone');

    protocolHeaders(['HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18']);
    same(
        '2019-01-01',
        protocolCall('requestedVersion', [PROTOCOL_META => '2019-01-01']),
        'and _meta wins when a request carries both, because that is the one it wrote inside itself'
    );

    protocolHeaders([]);
    same('', protocolCall('requestedVersion', []), 'a request that states nothing states nothing');

    protocolHeaders(['HTTP_MCP_PROTOCOL_VERSION' => '  2025-06-18  ']);
    same('2025-06-18', protocolCall('requestedVersion', []), 'and whitespace in a header is not a version');
});

test('a routing header that was not sent is not the same as one that is empty', static function (): void {
    protocolHeaders([]);
    same(null, protocolCall('routingHeader', null, 'MCP_METHOD', 'Mcp-Method'), 'absent is null, and null skips the check');

    protocolHeaders(['HTTP_MCP_METHOD' => '']);
    same('', protocolCall('routingHeader', null, 'MCP_METHOD', 'Mcp-Method'), 'present and empty is a claim, and an empty one matches no method');

    protocolHeaders(['HTTP_MCP_METHOD' => 'tools/call']);
    same('tools/call', protocolCall('routingHeader', null, 'MCP_METHOD', 'Mcp-Method'), 'a plain value is itself');

    protocolHeaders(['HTTP_MCP_METHOD' => '=?base64?dG9vbHMvY2FsbA==?=']);
    same('tools/call', protocolCall('routingHeader', null, 'MCP_METHOD', 'Mcp-Method'), 'and a sentinel is what it decodes to');

    protocolHeaders(['HTTP_MCP_NAME' => '=?base64??=']);
    same(
        '',
        protocolCall('routingHeader', null, 'MCP_NAME', 'Mcp-Name'),
        'an empty payload decodes to an empty string, which is still a header that was sent'
    );
});

test('the tidying up', static function (): void {
    protocolHeaders([]);
    ok(true, 'the request headers this file invented are gone');
});
