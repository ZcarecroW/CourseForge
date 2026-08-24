<?php
declare(strict_types=1);

namespace CourseForge\Api;

use CourseForge\Domain\McpClients;
use CourseForge\Security\Access;
use CourseForge\Security\Actor;
use CourseForge\Support\Audit;
use CourseForge\Support\Config;
use CourseForge\Support\HttpException;
use CourseForge\Support\Request;
use CourseForge\Support\Runtime;
use Throwable;

/**
 * Connecting a Claude client to this installation.
 *
 * The whole feature is one screen: press a button, copy a line, paste it into
 * Claude. Everything below exists to make that true - the endpoint URL is worked
 * out from the request rather than configured, and the token is created here
 * rather than typed into a file on the server.
 *
 * A connection is now more than a name and a token: it may be limited to some
 * of the tool groups and it may be given an expiry. Both are decided at the
 * moment it is created, because a token that quietly gains rights later is
 * exactly what the scopes exist to prevent - to widen one, you revoke it and
 * issue another.
 *
 * Every route answers with the caller's own list of connections, whoever's row
 * was just acted on - see `Access::workingSetOwner()`, which is where that rule
 * and its reasoning live.
 */
final class ConnectController
{
    /**
     * Where the tool scope list comes from, named rather than imported.
     *
     * `src/Mcp` is being rewritten alongside this controller, and a `use`
     * statement for a class that does not exist yet would take the Connect
     * screen down with it. Resolving the name at call time keeps the screen
     * reachable; what a catalogue that cannot be read costs from there is set
     * out on `scopeCatalogue()` below.
     */
    private const TOOLS = 'CourseForge\\Mcp\\Tools';

    /** A connection may be given at most a year before it expires by itself. */
    private const MAX_TTL_DAYS = 365;

    /**
     * The tool catalogue as this request found it, or null before it looked.
     *
     * @var array{scopes:array<int|string,mixed>,available:bool}|null
     */
    private static ?array $catalogue = null;

    /** @return array<string,mixed> */
    public static function index(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        return ['connect' => self::payload(Access::workingSetOwner($me, $request))];
    }

    /**
     * Issues a token. This is the only time it is ever readable.
     *
     * @return array<string,mixed>
     */
    public static function create(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();

        // No catalogue, no token. An empty scope list is not "nothing": it
        // means "everything this account allows" by the time Scopes::effective()
        // reads it back, so a lookup that failed would hand out a token with
        // the full run of the account - and it would do it through a picker
        // that showed the person nothing to tick. Refusing costs one screen
        // saying to try again; issuing costs a key nobody chose the shape of.
        if (!self::scopeCatalogue()['available']) {
            throw HttpException::unprocessable(
                'The tool catalogue cannot be read, so there is no way to say what a connection would be '
                . 'allowed to do. No token has been issued - see the server log, and try again once the MCP '
                . 'tools load.'
            );
        }

        $scopes = self::requestedScopes($request);
        $ttlDays = max(0, min(self::MAX_TTL_DAYS, $request->intOrNull('ttl_days') ?? 0));

        // A connection is always issued to the account that asked for it. An
        // administrator cannot mint a token in somebody else's name: the whole
        // point of the row is that it says who was holding the client.
        $created = McpClients::create(
            $me->username,
            $request->str('name', 'Claude'),
            $scopes,
            $ttlDays,
            $request->str('note')
        );

        Audit::record(
            $me->username,
            'connect.create',
            (string)$created['client']['name'],
            'scopes=' . ($scopes === [] ? 'all' : implode(' ', $scopes)) . '; ttl_days=' . $ttlDays
        );

        return [
            'connect' => self::payload(Access::workingSetOwner($me, $request)),
            // Returned once, shown once, never recoverable.
            'token' => $created['token'],
            'client' => $created['client'],
        ];
    }

    /**
     * Renames a connection or changes the note on it.
     *
     * Deliberately the only thing that can be edited. Scopes and the expiry are
     * baked into what the token is allowed to do, and letting them be widened
     * after the fact would make the audit line that issued the token a lie.
     *
     * @return array<string,mixed>
     */
    public static function update(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $id = $request->id('id');

        $client = Access::connection($me, $id);
        $owner = (string)$client['username'];

        // Both fields are optional, and an omitted one keeps what is stored -
        // renaming a connection must not silently erase the note explaining
        // which machine it is on.
        $name = $request->has('name') ? $request->str('name') : (string)$client['name'];
        $note = $request->has('note') ? $request->str('note') : (string)$client['note'];

        $updated = McpClients::rename($owner, $id, $name, $note);

        // Recorded even though nothing is granted here, because the name is
        // what a person reads the list by. Renaming "old laptop, revoke me" to
        // "CI server" is how a connection nobody trusts is made to look like
        // one everybody does, and the old name is the only trace of it.
        Audit::record(
            $me->username,
            'connect.rename',
            (string)$updated['name'],
            'owner=' . $owner . '; was=' . (string)$client['name']
        );

        return ['connect' => self::payload(Access::workingSetOwner($me, $request)), 'client' => $updated];
    }

    /**
     * Revokes a connection.
     *
     * Two routes reach this: `DELETE connect/{id}`, which is what a REST client
     * expects, and `DELETE connect` with the id in the body, which is what a
     * browser that cannot be trusted to send a body on DELETE ends up using.
     * The id is read from whichever of the two carried it.
     *
     * @return array<string,mixed>
     */
    public static function delete(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();

        $id = $request->param('id') !== ''
            ? $request->id('id')
            : $request->requiredId('client_id', 'Connection id');

        $client = Access::connection($me, $id);
        $owner = (string)$client['username'];

        McpClients::delete($owner, $id);
        Audit::record($me->username, 'connect.revoke', (string)$client['name'], 'owner=' . $owner);

        return ['connect' => self::payload(Access::workingSetOwner($me, $request))];
    }

    /* ------------------------------------------------------------ internals */

    /**
     * The one block every route here answers with.
     *
     * @return array<string,mixed>
     */
    private static function payload(?string $owner): array
    {
        $catalogue = self::scopeCatalogue();

        return [
            'url' => self::endpointUrl(),
            'clients' => McpClients::all($owner),
            'enabled' => Config::get('mcp.enabled') === null || Config::bool('mcp.enabled', true),
            'scopes' => $catalogue['scopes'],
            // The screen has to be able to tell "this list is empty" from "this
            // list could not be read", because only one of the two is a safe
            // thing to press the button under. create() refuses on the second.
            'scopes_unavailable' => !$catalogue['available'],
        ];
    }

    /**
     * The tool scopes the UI may offer, and whether the answer is trustworthy.
     *
     * Whatever `Tools::scopes()` returns is passed through untouched: the shape
     * of a scope entry is that class's business, and guessing at it here would
     * only mean two places to change when it settles.
     *
     * The whole call is wrapped, not only the lookup. A class can exist, carry
     * the method, and still fail the moment it is asked - it builds its answer
     * from a registry of handler classes, and a half-deployed `src/Mcp` throws
     * on the class it cannot find. Showing the Connect screen anyway is still
     * right; what is not right is letting the failure look like an answer. An
     * empty scope list is read back by `Scopes::effective()` as "everything
     * this account allows", so a swallowed exception would quietly turn a
     * picker with nothing in it into a token with everything in it. The `false`
     * carries the failure out to both callers instead, and the reason is in the
     * server log.
     *
     * The answer is remembered for the request because create() asks for it
     * before doing anything and payload() asks again on the way out.
     *
     * @return array{scopes:array<int|string,mixed>,available:bool}
     */
    private static function scopeCatalogue(): array
    {
        if (self::$catalogue !== null) {
            return self::$catalogue;
        }

        $tools = self::TOOLS;
        if (!class_exists($tools) || !method_exists($tools, 'scopes')) {
            return self::$catalogue = ['scopes' => [], 'available' => false];
        }

        try {
            $scopes = $tools::scopes();
        } catch (Throwable $e) {
            Runtime::log('connect.scopes', $e);
            return self::$catalogue = ['scopes' => [], 'available' => false];
        }
        return self::$catalogue = is_array($scopes)
            ? ['scopes' => $scopes, 'available' => true]
            : ['scopes' => [], 'available' => false];
    }

    /**
     * The scopes asked for, reduced to a list of non-empty strings.
     *
     * They are not checked against `Tools::scopes()`, and that is on purpose:
     * an unknown scope grants nothing, because the endpoint decides what a
     * scope means when the tool is called. Rejecting one here would only mean
     * that adding a tool group breaks every client issued before it.
     *
     * @return string[]
     */
    private static function requestedScopes(Request $request): array
    {
        $scopes = [];
        foreach ($request->arr('scopes') as $value) {
            if (!is_scalar($value)) {
                throw HttpException::unprocessable('Each scope must be a string.');
            }
            $scope = trim((string)$value);
            if ($scope !== '') {
                $scopes[] = $scope;
            }
        }
        return array_values(array_unique($scopes));
    }

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
