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
use CourseForge\Support\HttpException;
use CourseForge\Support\Request;
use CourseForge\Support\Response;

/** Sign in, sign out, who am I, change my own password. */
final class SessionController
{
    /** @return array<string,mixed> */
    public static function show(Request $request, ?Actor $actor): array
    {
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
        if (!Users::changePassword($me->username, $old, $new)) {
            throw HttpException::forbidden('The current password is incorrect.');
        }

        Audit::record($me->username, 'account.password_changed', $me->username);
        return ['user' => Auth::describe()];
    }

    /** Renaming yourself. The user name is the key and cannot change. */
    public static function updateProfile(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        Users::setDisplayName($me->username, $request->str('display_name'));
        return ['user' => Auth::describe()];
    }
}
