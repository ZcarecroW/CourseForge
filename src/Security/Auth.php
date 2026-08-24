<?php
declare(strict_types=1);

namespace CourseForge\Security;

use CourseForge\Support\Config;
use CourseForge\Support\HttpException;

/** Sign in, sign out and "who is asking?". */
final class Auth
{
    /** @return array{username:string,display_name:string}|null */
    public static function current(): ?array
    {
        if (empty($_SESSION['user'])) {
            return null;
        }
        return [
            'username' => (string)$_SESSION['user'],
            'display_name' => (string)($_SESSION['display_name'] ?? $_SESSION['user']),
        ];
    }

    public static function requireUser(): string
    {
        $user = self::current();
        if ($user === null) {
            throw HttpException::unauthorized();
        }
        return $user['username'];
    }

    /** @return array{ok:bool,error?:string,locked_for?:int,user?:array<string,string>} */
    public static function login(string $username, string $password): array
    {
        $ip = LoginThrottle::ip();

        $remaining = LoginThrottle::lockoutRemaining($ip);
        if ($remaining > 0) {
            return [
                'ok' => false,
                'error' => 'Too many failed attempts. Try again in ' . (int)ceil($remaining / 60) . ' minute(s).',
                'locked_for' => $remaining,
            ];
        }

        $user = Users::verify($username, $password);
        LoginThrottle::record($ip, $username, $user !== null);

        if ($user === null) {
            $left = max(0, Config::int('security.max_login_attempts', 5) - LoginThrottle::failuresInWindow($ip));
            return [
                'ok' => false,
                'error' => 'Invalid credentials.' . ($left > 0 ? ' ' . $left . ' attempt(s) left.' : ''),
                'locked_for' => LoginThrottle::lockoutRemaining($ip),
            ];
        }

        session_regenerate_id(true);
        $_SESSION['user'] = (string)$user['username'];
        $_SESSION['display_name'] = (string)($user['display_name'] ?? $user['username']);
        $_SESSION['last_seen'] = time();
        LoginThrottle::clear($ip);

        return ['ok' => true, 'user' => self::current()];
    }

    public static function logout(): void
    {
        Session::destroy();
    }
}
