<?php
declare(strict_types=1);

namespace CourseForge\Security;

use CourseForge\Support\Config;
use CourseForge\Support\Db;

/** IP based login rate limiting, backed by the `login_attempts` table. */
final class LoginThrottle
{
    public static function ip(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /** Seconds left in the lockout, 0 when not locked. */
    public static function lockoutRemaining(?string $ip = null): int
    {
        $ip ??= self::ip();
        $window = Config::int('security.attempt_window_minutes', 15) * 60;
        $max = Config::int('security.max_login_attempts', 5);
        $lock = Config::int('security.lockout_minutes', 15) * 60;

        $row = Db::row(
            'SELECT COUNT(*) AS failures, COALESCE(MAX(ts), 0) AS last
               FROM login_attempts WHERE ip = ? AND ok = 0 AND ts > ?',
            [$ip, time() - $window]
        ) ?? ['failures' => 0, 'last' => 0];

        if ((int)$row['failures'] < $max) {
            return 0;
        }
        return max(0, ((int)$row['last'] + $lock) - time());
    }

    public static function failuresInWindow(?string $ip = null): int
    {
        $ip ??= self::ip();
        $window = Config::int('security.attempt_window_minutes', 15) * 60;
        $row = Db::row(
            'SELECT COUNT(*) AS failures FROM login_attempts WHERE ip = ? AND ok = 0 AND ts > ?',
            [$ip, time() - $window]
        );
        return (int)($row['failures'] ?? 0);
    }

    public static function record(string $ip, string $username, bool $ok): void
    {
        Db::run('INSERT INTO login_attempts (ip, username, ok, ts) VALUES (?,?,?,?)', [$ip, $username, $ok ? 1 : 0, time()]);
        Db::run('DELETE FROM login_attempts WHERE ts < ?', [time() - 86400]);
    }

    public static function clear(string $ip): void
    {
        Db::run('DELETE FROM login_attempts WHERE ip = ? AND ok = 0', [$ip]);
    }
}
