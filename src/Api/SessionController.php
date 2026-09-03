<?php
declare(strict_types=1);

namespace CourseForge\Api;

use CourseForge\Security\Actor;
use CourseForge\Security\Auth;
use CourseForge\Security\LoginThrottle;
use CourseForge\Security\Session;
use CourseForge\Security\Users;
use CourseForge\Support\Audit;
use CourseForge\Support\Config;
use CourseForge\Support\Php;
use CourseForge\Support\HttpException;
use CourseForge\Support\Request;
use CourseForge\Support\Response;

/** Sign in, sign out, who am I, change my own password. */
final class SessionController
{
    /** @return array<string,mixed> */
    public static function show(Request $request, ?Actor $actor): array
    {
        // The first call the application makes, and the first moment an
        // administrator can be recognised - so it is where "run once when the
        // admin interface is opened" actually means that, rather than "when
        // somebody happens to visit Settings".
        //
        // In the ordinary case this is one hash comparison against what was
        // last written. It costs something only when there is something to
        // repair, which includes an update having replaced the file.
        if ($actor !== null && $actor->isAdmin()) {
            Php::ensure($actor->username);
        }

        return [
            'csrf' => Session::csrf(),
            'user' => Auth::describe(),
            'locked_for' => $actor === null ? LoginThrottle::lockoutRemaining() : 0,
            'needs_setup' => Users::needsSetup(),
            'app' => ['name' => Config::str('app.name', 'CourseForge'), 'version' => CF_VERSION],
        ];
    }

    public static function login(Request $request, ?Actor $actor): array
    {
        $name = $request->str('username');
        $result = Auth::login($name, $request->raw('password'));

        if ($result['ok'] !== true) {
            Audit::record($name, 'session.login_failed', $name, (string)($result['error'] ?? ''));
            Response::send($result + ['csrf' => Session::csrf()], 401);
        }

        Audit::record($name, 'session.login', $name);
        return ['user' => $result['user'], 'csrf' => Session::csrf()];
    }

    public static function logout(Request $request, ?Actor $actor): array
    {
        Auth::logout();
        return [];
    }

    public static function changePassword(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();

        $old = $request->raw('old');
        $new = $request->raw('new');

        Users::validatePassword($new);
        if ($old === $new) {
            throw HttpException::unprocessable('The new password must differ from the current one.');
        }

        // The current password is checked here, which makes this a place a
        // password can be guessed at - by whoever is holding a session. It is
        // throttled like the sign-in form, against the same counters.
        $ip = LoginThrottle::ip();
        $locked = LoginThrottle::lockoutRemaining($ip, $me->username);
        if ($locked > 0) {
            throw HttpException::forbidden(
                'Too many wrong passwords. Try again in ' . (int)ceil($locked / 60) . ' minute(s).'
            );
        }
        if (!Users::changePassword($me->username, $old, $new)) {
            LoginThrottle::record($ip, $me->username, false);
            Audit::record($me->username, 'account.password_refused', $me->username, 'wrong current password');
            throw HttpException::forbidden('The current password is incorrect.');
        }

        // A new password, a new session id: whatever was holding the old one
        // does not get to keep riding this session.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        Audit::record($me->username, 'account.password_changed', $me->username);
        return ['user' => Auth::describe(), 'csrf' => Session::csrf()];
    }

    /** Renaming yourself. The user name is the key and cannot change. */
    public static function updateProfile(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        Users::setDisplayName($me->username, $request->str('display_name'));
        return ['user' => Auth::describe()];
    }

    /**
     * The guided tour has been seen - finished or dismissed - so it stops
     * starting by itself. It can always be opened again from the sidebar.
     */
    public static function tourSeen(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        Users::markTourSeen($me->username);
        return ['user' => Auth::describe()];
    }
}
