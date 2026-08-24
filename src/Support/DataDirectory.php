<?php
declare(strict_types=1);

namespace CourseForge\Support;

use RuntimeException;

/**
 * Making sure the data directory exists and is refused over HTTP.
 *
 * `data/` holds `app.sqlite`, and `app.sqlite` holds every password hash on the
 * installation. It ships with an `.htaccess` that denies everything, and for
 * most of CourseForge's life that was considered sufficient - the file is in
 * the release, so it is in the directory.
 *
 * It is not sufficient, because there are several ways to end up with a data
 * directory the release never wrote:
 *
 *   - PHP creates it itself when it is missing, and created it with nothing in
 *     it;
 *   - an update deliberately never writes into `data/`, so an installation that
 *     lost the file could never get it back by updating;
 *   - a deployment tool that skips `data/` - because the rest of it is the
 *     server's, not the release's - skips the one file in it that is not.
 *     CourseForge's own shipped deploy tool did exactly that, and the
 *     installation check is what caught it.
 *
 * So the guarantee lives here instead, in code, and the content is a constant
 * rather than a copy of a file that may be the very thing that is missing. It
 * is written on the way to opening the database, which every request does, and
 * a failure to write is never allowed to break the request - the installation
 * check reports it, loudly, and that is the right division of labour.
 *
 * The file is written even when the data directory is outside the document
 * root, where it does nothing. It costs one `is_file()` per request to be
 * certain, and a directory that is moved back under the root later is a change
 * nobody would think to re-check.
 */
final class DataDirectory
{
    /**
     * What the shipped `data/.htaccess` says, and what is written when it is
     * absent. Kept byte-identical to the file in the release, so an
     * installation cannot end up with two subtly different answers to the same
     * question.
     */
    private const GUARD = <<<'HTACCESS'
        # The SQLite database, the settings this installation has changed and the
        # update backups live here. Nothing in this directory may ever be
        # reachable over HTTP.
        #
        # CourseForge rewrites this file whenever it finds it missing, because the
        # alternative is an installation whose password hashes are one URL away.
        <IfModule mod_authz_core.c>
            Require all denied
        </IfModule>
        <IfModule !mod_authz_core.c>
            Order allow,deny
            Deny from all
        </IfModule>
        HTACCESS;

    /** Set once per request: the check is cheap, but it is not free. */
    private static bool $checked = false;

    /**
     * Creates the directory if it is missing and guards it if it is unguarded.
     *
     * @throws RuntimeException only when the directory itself cannot be made or
     *                          written to - a state in which nothing else works
     *                          either, and which the caller has always had to
     *                          handle.
     */
    public static function ensure(): void
    {
        if (self::$checked) {
            return;
        }
        self::$checked = true;

        if (!is_dir(CF_DATA) && !@mkdir(CF_DATA, 0770, true) && !is_dir(CF_DATA)) {
            throw new RuntimeException('The data directory is missing and could not be created: ' . CF_DATA);
        }
        if (!is_writable(CF_DATA)) {
            throw new RuntimeException('The data directory is not writable by PHP: ' . CF_DATA);
        }

        self::guard();
    }

    /**
     * Writes the deny file if it is not there.
     *
     * Deliberately silent on failure. This runs on the way to answering a
     * request, and a host that will not let PHP write one file is not a reason
     * to refuse the request - it is a reason for `php tools/diagnose.php` and
     * the Settings screen to say so, which they do.
     */
    public static function guard(): bool
    {
        $path = CF_DATA . '/.htaccess';
        if (is_file($path)) {
            return true;
        }

        // Written through a temporary name and moved, for the same reason every
        // other write in this application is: a half-written deny file is worse
        // than none, because it looks like protection.
        $temporary = CF_DATA . '/.htaccess.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temporary, self::content(), LOCK_EX) === false) {
            return false;
        }
        @chmod($temporary, 0644);

        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            return false;
        }
        return true;
    }

    /** True when the directory is under something a web server serves. */
    public static function isUnderDocumentRoot(): bool
    {
        $data = realpath(CF_DATA);
        $root = realpath(CF_ROOT);
        if ($data === false || $root === false) {
            return false;
        }
        $data = str_replace('\\', '/', $data);
        $root = rtrim(str_replace('\\', '/', $root), '/');

        return $data === $root || str_starts_with($data, $root . '/');
    }

    /** The deny file's contents, with the heredoc's indentation removed. */
    public static function content(): string
    {
        return self::GUARD . "\n";
    }
}
