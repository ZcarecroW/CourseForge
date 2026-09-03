<?php
declare(strict_types=1);

namespace CourseForge\Api;

use CourseForge\Security\Actor;
use CourseForge\Security\Hardening;
use CourseForge\Support\Audit;
use CourseForge\Support\HttpException;
use CourseForge\Support\Request;
use CourseForge\Support\Runtime;

/**
 * Administration › Security: is this server keeping the data directory
 * private, and what to do if it is not.
 *
 * The verdict is taken by asking the server for its own files over HTTP -
 * see Security\Hardening. Until it is "secure", every field that would store
 * a secret is locked, and the only way past that lock is the acknowledgement
 * at the bottom of the screen: a six-character code shown there, typed back
 * here, recorded with who and when.
 */
final class SecurityController
{
    /** @return array<string,mixed> */
    public static function show(Request $request, ?Actor $actor): array
    {
        $me = self::admin($actor);

        // The code is written into the session before the request is marked
        // long-running, because that releases the session and nothing may be
        // written to it afterwards.
        $code = Hardening::issueCode();

        Runtime::beginLongRequest();
        $status = Hardening::due() ? Hardening::check() : Hardening::status();

        return ['security' => $status, 'ack_code' => $code, 'me' => $me->username];
    }

    /** Takes the verdict again, now. @return array<string,mixed> */
    public static function check(Request $request, ?Actor $actor): array
    {
        $me = self::admin($actor);
        $code = Hardening::issueCode();

        Runtime::beginLongRequest();
        $status = Hardening::check();
        Audit::record($me->username, 'security.check', $status['verdict'], $status['reason']);

        return ['security' => $status, 'ack_code' => $code];
    }

    /**
     * Accepts the risk, deliberately.
     *
     * @return array<string,mixed>
     */
    public static function acknowledge(Request $request, ?Actor $actor): array
    {
        $me = self::admin($actor);
        $typed = $request->str('code');

        if (!Hardening::codeMatches($typed)) {
            throw HttpException::unprocessable(
                'That is not the code shown on this screen. It is case-sensitive, and it changes every time the '
                . 'screen is opened - reload and type the one you see now.'
            );
        }

        $status = Hardening::status();
        if ($status['verdict'] === Hardening::VERDICT_SECURE) {
            throw HttpException::unprocessable('The server passed the check, so there is nothing to accept.');
        }

        Hardening::acknowledge($me->username);
        Audit::record($me->username, 'security.acknowledge', (string)$status['verdict'], 'secrets may be stored despite the verdict');

        return ['security' => Hardening::status()];
    }

    /** Takes the acknowledgement back. @return array<string,mixed> */
    public static function revoke(Request $request, ?Actor $actor): array
    {
        $me = self::admin($actor);
        Hardening::revokeAcknowledgement();
        Audit::record($me->username, 'security.revoke', '', 'the acknowledgement was withdrawn');

        return ['security' => Hardening::status(), 'ack_code' => Hardening::issueCode()];
    }

    private static function admin(?Actor $actor): Actor
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $me->requireAdmin();
        return $me;
    }
}
