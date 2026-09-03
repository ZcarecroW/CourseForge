<?php
declare(strict_types=1);

namespace CourseForge\Security;

use CourseForge\Support\Config;

/**
 * Session bootstrap and the CSRF token.
 *
 * The cookie is scoped to the install directory rather than the whole host, so
 * two CourseForge instances on one domain do not fight over it.
 */
final class Session
{
    public static function boot(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $lifetime = Config::int('security.session_lifetime_minutes', 480) * 60;

        // /app/api/index.php → /app/
        $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        $dir = preg_replace('#/api$#', '', $dir) ?? $dir;

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $dir === '' ? '/' : $dir . '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => self::isHttps(),
        ]);
        // A session id the client made up is never adopted: PHP mints one of
        // its own instead, which closes fixation before sign-in as well as
        // after it.
        @ini_set('session.use_strict_mode', '1');
        session_name('courseforge');
        session_start();

        if (isset($_SESSION['user'], $_SESSION['last_seen']) && (time() - (int)$_SESSION['last_seen']) > $lifetime) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['last_seen'] = time();

        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(18));
        }
    }

    public static function isHttps(): bool
    {
        $https = (string)($_SERVER['HTTPS'] ?? '');
        if ($https !== '' && strtolower($https) !== 'off') {
            return true;
        }
        return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public static function csrf(): string
    {
        return (string)($_SESSION['csrf'] ?? '');
    }

    public static function csrfValid(string $token): bool
    {
        $expected = self::csrf();
        return $expected !== '' && hash_equals($expected, $token);
    }

    /**
     * Writes the session and releases its exclusive file lock.
     *
     * PHP holds that lock for the whole request, which serialises every
     * concurrent request of the same user. The long-running endpoints call this
     * so parallel page generation is genuinely parallel. $_SESSION stays
     * readable afterwards – it just must not be written any more.
     */
    public static function release(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['last_seen'] = time();
            session_write_close();
        }
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name() ?: 'courseforge', '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }
}
