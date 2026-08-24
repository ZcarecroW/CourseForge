<?php
declare(strict_types=1);

namespace CourseForge\Api;

use CourseForge\Security\Auth;
use CourseForge\Security\LoginThrottle;
use CourseForge\Security\Session;
use CourseForge\Security\Users;
use CourseForge\Support\Config;
use CourseForge\Support\HttpException;
use CourseForge\Support\Request;
use CourseForge\Support\Response;

/** Sign in, sign out, who am I, change my password. */
final class SessionController
{
    /** @return array<string,mixed> */
    public static function show(Request $request, string $username): array
    {
        $user = Auth::current();
        return [
            'csrf' => Session::csrf(),
            'user' => $user,
            'locked_for' => $user === null ? LoginThrottle::lockoutRemaining() : 0,
            'app' => ['name' => Config::str('app.name', 'CourseForge'), 'version' => CF_VERSION],
        ];
    }

    public static function login(Request $request, string $username): array
    {
        $result = Auth::login($request->str('username'), $request->raw('password'));
        if ($result['ok'] !== true) {
            Response::send($result + ['csrf' => Session::csrf()], 401);
        }
        return ['user' => $result['user'], 'csrf' => Session::csrf()];
    }

    public static function logout(Request $request, string $username): array
    {
        Auth::logout();
        return [];
    }

    public static function changePassword(Request $request, string $username): array
    {
        $old = $request->raw('old');
        $new = $request->raw('new');

        if (strlen($new) < 8) {
            throw HttpException::unprocessable('The new password must be at least 8 characters.');
        }
        if ($old === $new) {
            throw HttpException::unprocessable('The new password must differ from the current one.');
        }
        if (!Users::changePassword($username, $old, $new)) {
            throw HttpException::forbidden('The current password is incorrect.');
        }
        return [];
    }
}
