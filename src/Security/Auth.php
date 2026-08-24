<?php
declare(strict_types=1);

namespace CourseForge\Security;

use CourseForge\Support\Config;
use CourseForge\Support\HttpException;

/** Sign in, sign out and "who is asking?". */
final class Auth
{
    /**
     * The signed-in account, re-read from the database on every request.
     *
     * The session holds the user name and nothing else that matters. Role,
     * display name and whether the account still exists are looked up each
     * time, so a demotion, a rename or a deletion takes effect at once instead
     * of when the session happens to expire.
     */
    public static function current(): ?Actor
    {
        $username = trim((string)($_SESSION['user'] ?? ''));
        if ($username === '') {
            return null;
        }

        $user = Users::find($username);
        if ($user === null || (int)$user['disabled'] === 1) {
            return null;
        }

        return Actor::make(
            (string)$user['username'],
            (string)($user['display_name'] ?: $user['username']),
            (string)$user['role']
        );
    }

    public static function require(): Actor
    {
        $actor = self::current();
        if ($actor === null) {
            throw HttpException::unauthorized();
        }
        return $actor;
    }

    public static function requireAdmin(): Actor
    {
        $actor = self::require();
        $actor->requireAdmin();
        return $actor;
    }

    /** @return array{ok:bool,error?:string,locked_for?:int,user?:array<string,mixed>} */
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

        try {
            $user = Users::verify($username, $password);
        } catch (HttpException $e) {
            // A disabled account is a real answer, not a throttled guess.
            LoginThrottle::record($ip, $username, false);
            return ['ok' => false, 'error' => $e->getMessage(), 'locked_for' => 0];
        }

        LoginThrottle::record($ip, $username, $user !== null);

        if ($user === null) {
            $left = max(0, Config::int('security.max_login_attempts', 5) - LoginThrottle::failuresInWindow($ip));
            return [
                'ok' => false,
                'error' => 'Invalid credentials.' . ($left > 0 ? ' ' . $left . ' attempt(s) left.' : ''),
                'locked_for' => LoginThrottle::lockoutRemaining($ip),
            ];
        }

        self::establish((string)$user['username']);
        LoginThrottle::clear($ip);

        return ['ok' => true, 'user' => self::describe()];
    }

    /** Puts an account into the current session. Also used right after setup. */
    public static function establish(string $username): void
    {
        session_regenerate_id(true);
        $_SESSION['user'] = $username;
        $_SESSION['last_seen'] = time();
    }

    /**
     * The signed-in account as the SPA wants it.
     *
     * @return array<string,mixed>|null
     */
    public static function describe(): ?array
    {
        $actor = self::current();
        if ($actor === null) {
            return null;
        }
        $row = Users::find($actor->username);
        $view = $actor->toArray();
        $view['must_change_password'] = $row !== null && (int)($row['must_change_password'] ?? 0) === 1;
        return $view;
    }

    public static function logout(): void
    {
        Session::destroy();
    }
}
