<?php
declare(strict_types=1);

namespace CourseForge\Support;

/**
 * What was done to the installation, rather than to a course.
 *
 * One line per administrative act: an account created, a role changed, a
 * setting saved, an update installed, a connection revoked. It exists because
 * an installation with several accounts has to be able to answer "who did
 * that?" long after the fact, and because an unattended update at five in the
 * morning should leave a trace somewhere a person will actually look.
 *
 * Nothing secret is ever written here. A setting change records the key, never
 * the value.
 */
final class Audit
{
    /** How many entries are kept. Old ones are trimmed on write. */
    private const KEEP = 5000;

    public static function record(string $actor, string $action, string $subject = '', string $detail = '', string $source = 'web'): void
    {
        try {
            Db::run(
                'INSERT INTO audit_log (ts, actor, action, subject, detail, ip, source) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [time(), $actor, $action, $subject, mb_substr($detail, 0, 2000), self::ip(), $source]
            );
            if (random_int(1, 50) === 1) {
                // The bound parameter would arrive as text and SQLite would do
                // the subtraction on a string, so the limit is inlined - it is
                // a class constant, not input.
                Db::run('DELETE FROM audit_log WHERE id < (SELECT MAX(id) FROM audit_log) - ' . self::KEEP);
            }
        } catch (\Throwable $e) {
            // An audit line is never worth failing the request it describes.
            Runtime::log('audit', $e);
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function recent(int $limit = 200, string $action = ''): array
    {
        $limit = max(1, min(1000, $limit));
        if ($action !== '') {
            // The filter is a prefix, so the two LIKE wildcards in it are
            // characters to match rather than patterns.
            $pattern = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $action) . '%';
            return Db::rows(
                "SELECT * FROM audit_log WHERE action LIKE ? ESCAPE '\\' ORDER BY id DESC LIMIT " . $limit,
                [$pattern]
            );
        }
        return Db::rows('SELECT * FROM audit_log ORDER BY id DESC LIMIT ' . $limit);
    }

    private static function ip(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }
}
