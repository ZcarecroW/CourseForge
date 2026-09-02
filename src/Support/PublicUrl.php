<?php
declare(strict_types=1);

namespace CourseForge\Support;

use CourseForge\Security\Session;

/**
 * The address this installation is reached at, for the URLs it hands out.
 *
 * Two things build one: the scheduler URL a hosting panel calls once a minute,
 * and the BookStackDev link a BookStack administrator pastes into a header.
 * Both used to be worked out inside Cron, and both want the same answer: the
 * public address if the administrator set one, otherwise whatever the current
 * request says - scheme, host and the directory index.html lives in, with the
 * `/api` a browser request arrives through taken back off.
 */
final class PublicUrl
{
    /** The installation root, without a trailing slash. */
    public static function base(): string
    {
        $base = trim(Config::str('app.public_url', ''));

        if ($base === '' && isset($_SERVER['HTTP_HOST'])) {
            $scheme = Session::isHttps() ? 'https' : 'http';
            $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
            $dir = (string)preg_replace('#/api$#', '', $dir);
            $base = $scheme . '://' . $_SERVER['HTTP_HOST'] . $dir;
        }
        if ($base === '') {
            $base = 'https://your-install';
        }

        return rtrim($base, '/');
    }

    /** One file under the root: `PublicUrl::file('cron.php')`. */
    public static function file(string $name): string
    {
        return self::base() . '/' . ltrim($name, '/');
    }
}
