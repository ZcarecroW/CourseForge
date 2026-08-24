<?php
declare(strict_types=1);

namespace CourseForge\Support;

/**
 * The `meta` table as a key/value store.
 *
 * Used for the handful of facts that belong to the installation rather than to
 * any user: the schema version, and when cron last ran. Anything bigger than a
 * scalar belongs in a table of its own.
 */
final class Meta
{
    public static function get(string $key, string $default = ''): string
    {
        $row = Db::row('SELECT value FROM meta WHERE key = ?', [$key]);
        return $row === null ? $default : (string)$row['value'];
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key, '');
        return is_numeric($value) && is_finite((float)$value) ? (int)$value : $default;
    }

    public static function set(string $key, string $value): void
    {
        Db::run(
            'INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value',
            [$key, $value]
        );
    }
}
