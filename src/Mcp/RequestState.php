<?php
declare(strict_types=1);

namespace CourseForge\Mcp;

use CourseForge\Support\Db;
use CourseForge\Support\Meta;

/**
 * The continuation blob that survives a pause, and the reasons it cannot be trusted.
 *
 * Multi Round-Trip Requests (MRTR, SEP-2322, shipped in the 2026-07-28 MCP
 * revision) work by ending the client's request rather than holding it open: the
 * server answers `resultType: "input_required"`, the client goes and gets what
 * was asked for, and then re-issues the *same* call with the answers and this
 * blob attached. Nothing is held between the two - no session, no stream, no
 * row pinned to one PHP process - which is exactly why it suits a server that
 * answers every request with one JSON object and forgets.
 *
 * The price is that the state travels through the client, and the spec is blunt
 * about what follows: a server MUST treat `requestState` as attacker-controlled.
 * It is a bearer of intent, and everything it might be abused for has to be shut
 * off explicitly:
 *
 *   - **Forgery** - the payload is signed with HMAC-SHA256 over a secret that
 *     never leaves the installation. A blob whose signature does not verify is
 *     refused without being parsed.
 *   - **Cross-principal replay** - the connection id and the account are inside
 *     the signed payload and are compared against whoever is calling now. A
 *     token minted for one connection is useless on another, even on the same
 *     account.
 *   - **Cross-request replay** - the tool name and a digest of the arguments are
 *     signed in too, so a continuation issued for `apply_structure` cannot be
 *     redeemed against `delete_course`, and one issued to confirm the deletion
 *     of three pages cannot be re-used to confirm the deletion of fifty.
 *   - **Reuse** - a TTL bounds the window, but the spec says plainly that a TTL
 *     does not make a thing single-use. Redemption is recorded and a second
 *     attempt is refused. This is the part that cannot be done with signing
 *     alone, and it is why there is a table.
 *
 * The blob holds a small continuation, never a payload: what was being done and
 * for whom, not the course, the outline or anything a caller could learn by
 * decoding it. Nothing secret goes in it, because it passes through the client.
 */
final class RequestState
{
    /** How long a paused call may sit before the answer stops being accepted. */
    private const TTL_SECONDS = 900;

    /** The meta key the signing secret lives under. */
    private const SECRET_KEY = 'mcp.request_state_secret';

    /**
     * Signs a continuation for the current caller and call.
     *
     * @param array<string,mixed> $arguments the arguments this call was made with
     * @param array<string,mixed> $carry     small, non-secret, decodable by anyone
     */
    public static function issue(
        int $clientId,
        string $username,
        string $tool,
        array $arguments,
        array $carry = []
    ): string {
        $payload = [
            'v' => 1,
            'jti' => bin2hex(random_bytes(16)),
            'cid' => $clientId,
            'sub' => $username,
            'tool' => $tool,
            'arg' => self::digest($arguments),
            'exp' => time() + self::TTL_SECONDS,
            'carry' => $carry,
        ];

        $body = self::b64((string)json_encode($payload, JSON_UNESCAPED_SLASHES));
        return $body . '.' . self::b64(self::sign($body));
    }

    /**
     * Verifies a continuation and burns it, or explains why it will not do.
     *
     * Returns the carried data on success. The refusal is a sentence rather
     * than a code because it is shown to a model, which can act on "that
     * confirmation was for a different request" and cannot act on "invalid".
     *
     * @param array<string,mixed> $arguments
     * @return array{ok:true,carry:array<string,mixed>}|array{ok:false,why:string}
     */
    public static function redeem(
        string $state,
        int $clientId,
        string $username,
        string $tool,
        array $arguments
    ): array {
        $parts = explode('.', $state);
        if (count($parts) !== 2) {
            return ['ok' => false, 'why' => 'That continuation is not in a shape this server issued.'];
        }

        [$body, $signature] = $parts;

        // Compared before decoding: an unverified blob is somebody else's text,
        // and json_decode is not a thing to point at somebody else's text.
        if (!hash_equals(self::sign($body), (string)self::unb64($signature))) {
            return ['ok' => false, 'why' => 'That continuation did not verify. Start the call again.'];
        }

        $payload = json_decode((string)self::unb64($body), true);
        if (!is_array($payload) || ($payload['v'] ?? 0) !== 1) {
            return ['ok' => false, 'why' => 'That continuation is from a different version of this server.'];
        }

        if ((int)($payload['exp'] ?? 0) < time()) {
            return [
                'ok' => false,
                'why' => 'That continuation has expired - they last ' . (self::TTL_SECONDS / 60)
                    . ' minutes. Make the original call again.',
            ];
        }

        if ((int)($payload['cid'] ?? 0) !== $clientId
            || !hash_equals((string)($payload['sub'] ?? ''), $username)) {
            return ['ok' => false, 'why' => 'That continuation was issued to a different connection.'];
        }

        if ((string)($payload['tool'] ?? '') !== $tool) {
            return [
                'ok' => false,
                'why' => 'That continuation was issued for ' . (string)($payload['tool'] ?? 'another tool')
                    . ', not for ' . $tool . '.',
            ];
        }

        if (!hash_equals((string)($payload['arg'] ?? ''), self::digest($arguments))) {
            return [
                'ok' => false,
                'why' => 'The arguments changed between the two halves of this call. A confirmation only covers '
                    . 'the request it was asked about, so make the call again as it now stands.',
            ];
        }

        // A TTL bounds the window; it does not make anything single-use. The
        // spec is explicit that one-time redemption has to be enforced by the
        // server, and this is the only line that does it.
        if (!self::burn((string)($payload['jti'] ?? ''), (int)$payload['exp'])) {
            return ['ok' => false, 'why' => 'That continuation has already been used. Make the call again.'];
        }

        $carry = $payload['carry'] ?? [];
        return ['ok' => true, 'carry' => is_array($carry) ? $carry : []];
    }

    /**
     * Records a redemption, returning false when it was already recorded.
     *
     * The uniqueness is the primary key's, not a read followed by a write:
     * two clients racing the same continuation must not both be told yes.
     */
    private static function burn(string $jti, int $expires): bool
    {
        if ($jti === '') {
            return false;
        }

        // Nothing here is worth keeping past its own expiry.
        Db::run('DELETE FROM mcp_continuations WHERE expires_at < ?', [time()]);

        try {
            Db::run(
                'INSERT INTO mcp_continuations (jti, expires_at) VALUES (?, ?)',
                [$jti, $expires]
            );
        } catch (\Throwable) {
            return false;
        }
        return true;
    }

    /** A stable fingerprint of the arguments a call was made with. */
    private static function digest(array $arguments): string
    {
        $normalised = $arguments;
        self::sortDeep($normalised);
        return hash('sha256', (string)json_encode($normalised, JSON_UNESCAPED_SLASHES));
    }

    /** @param array<mixed> $value */
    private static function sortDeep(array &$value): void
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                self::sortDeep($item);
            }
        }
        unset($item);

        // A list's order is meaningful; an object's is not, and a client that
        // re-serialises its own arguments may not preserve key order.
        if (!array_is_list($value)) {
            ksort($value);
        }
    }

    private static function sign(string $body): string
    {
        return hash_hmac('sha256', $body, self::secret(), true);
    }

    /**
     * The signing secret, made once and kept.
     *
     * It lives in the database rather than in a config file because it belongs
     * to the installation rather than to the release: an update replaces the
     * files, and a secret that moved with them would invalidate every
     * continuation in flight and, worse, would be identical on every
     * installation that took the same release.
     */
    private static function secret(): string
    {
        $secret = Meta::get(self::SECRET_KEY, '');
        if ($secret === '') {
            $secret = bin2hex(random_bytes(32));
            Meta::set(self::SECRET_KEY, $secret);
        }
        return $secret;
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $encoded): string
    {
        return (string)base64_decode(strtr($encoded, '-_', '+/'), true);
    }
}
