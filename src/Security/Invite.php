<?php
declare(strict_types=1);

namespace CourseForge\Security;

use CourseForge\Support\Db;
use CourseForge\Support\HttpException;

/**
 * The invite code that opens the setup screen.
 *
 * A fresh installation is a web application with no accounts and a form that
 * creates the first administrator. Anything reachable from the internet in that
 * state is a race between the person who installed it and whoever finds it
 * first, so the form asks for a code that only exists on the file system:
 * `INVITE-CODE.txt`, written next to `index.html` the first time CourseForge is
 * opened. Somebody who can read that file can already read `config/`, so it
 * proves exactly the right thing.
 *
 * The plain code is in that file and nowhere else - the database keeps a hash,
 * the same way it does for a password or a connection token. An invite is good
 * for one account and, unless it is the first one, has an expiry.
 */
final class Invite
{
    public const FILE = 'INVITE-CODE.txt';

    /** How long an invite an administrator issues from the app stays valid. */
    public const DEFAULT_TTL_HOURS = 48;

    /**
     * Makes sure a brand-new installation has an open invite and a file to read
     * it from. Safe to call on every request: it does nothing once an account
     * exists, and nothing while an unused invite is still open.
     */
    public static function ensureBootstrap(): void
    {
        if (Users::count() > 0) {
            return;
        }
        $open = self::open();
        if ($open !== null && is_file(self::pathOf($open))) {
            return;
        }
        // An open invite whose file has been lost is replaced rather than
        // repaired: the plain code was only ever in that file.
        if ($open !== null) {
            Db::run('UPDATE invites SET used_at = ?, used_by = ? WHERE id = ?', [time(), 'file lost', (int)$open['id']]);
        }
        self::issue(Actor::ROLE_ADMIN, 0, 'first start');
    }

    /**
     * Writes a new invite and its file.
     *
     * @param int $ttlHours 0 for an invite that does not expire (the first one)
     * @return array{code:string,path:string,role:string,expires_at:int}
     */
    public static function issue(string $role, int $ttlHours = self::DEFAULT_TTL_HOURS, string $issuedBy = ''): array
    {
        $role = Actor::normaliseRole($role);
        $code = self::freshCode();
        $expires = $ttlHours > 0 ? time() + ($ttlHours * 3600) : 0;

        // Only one invite is ever open: the file holds exactly one code, so a
        // second open row would be a code nobody can read.
        Db::run('UPDATE invites SET used_at = ?, used_by = ? WHERE used_at = 0', [time(), 'superseded']);
        Db::run(
            'INSERT INTO invites (code_hash, role, created_at, expires_at, issued_by) VALUES (?, ?, ?, ?, ?)',
            [self::hash($code), $role, time(), $expires, $issuedBy]
        );

        $path = self::write(Db::lastId(), $code, $role, $expires);
        return ['code' => $code, 'path' => $path, 'role' => $role, 'expires_at' => $expires];
    }

    /**
     * The invite that is currently open, if there is one.
     *
     * @return array<string,mixed>|null
     */
    public static function open(): ?array
    {
        return Db::row(
            'SELECT * FROM invites WHERE used_at = 0 AND (expires_at = 0 OR expires_at > ?) ORDER BY id DESC LIMIT 1',
            [time()]
        );
    }

    /**
     * Checks a code against the open invite.
     *
     * @return array<string,mixed> the invite row
     */
    public static function verify(string $code): array
    {
        $code = self::normalise($code);
        $invite = self::open();

        // Always spend the comparison, so a wrong code and no invite at all
        // are indistinguishable from the outside.
        $expected = (string)($invite['code_hash'] ?? str_repeat('0', 64));
        $matches = hash_equals($expected, self::hash($code));

        if ($invite === null || !$matches) {
            throw HttpException::forbidden('That invite code is not valid. Read the current one from ' . self::FILE . '.');
        }
        return $invite;
    }

    /** Marks an invite as spent and removes the file it was published in. */
    public static function consume(array $invite, string $username): void
    {
        Db::run('UPDATE invites SET used_at = ?, used_by = ? WHERE id = ?', [time(), $username, (int)$invite['id']]);
        @unlink(self::pathOf($invite));
        @unlink(CF_ROOT . '/' . self::FILE);
        @unlink(CF_DATA . '/' . self::FILE);
    }

    /** Where the file for this invite was written, as recorded on the row. */
    public static function pathOf(array $invite): string
    {
        $path = trim((string)($invite['file_path'] ?? ''));
        return $path !== '' ? $path : CF_ROOT . '/' . self::FILE;
    }

    /** What the setup screen and the admin screen are allowed to know. */
    public static function status(): array
    {
        $invite = self::open();
        if ($invite === null) {
            return ['open' => false, 'path' => '', 'role' => '', 'expires_at' => 0];
        }
        $path = self::pathOf($invite);
        return [
            'open' => true,
            'path' => $path,
            'file_present' => is_file($path),
            'role' => (string)$invite['role'],
            'created_at' => (int)$invite['created_at'],
            'expires_at' => (int)$invite['expires_at'],
        ];
    }

    /* -------------------------------------------------------------- helpers */

    /**
     * Six groups of four, from an alphabet with no character that can be
     * mistaken for another. It is typed by hand, from a text file, once.
     */
    private static function freshCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $groups = [];
        for ($g = 0; $g < 6; $g++) {
            $chunk = '';
            for ($i = 0; $i < 4; $i++) {
                $chunk .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $groups[] = $chunk;
        }
        return implode('-', $groups);
    }

    /** Upper-cased and stripped of everything that is not part of the alphabet. */
    public static function normalise(string $code): string
    {
        $code = strtoupper(trim($code));
        $code = (string)preg_replace('/[^A-Z0-9]/', '', $code);
        return trim(chunk_split($code, 4, '-'), '-');
    }

    private static function hash(string $code): string
    {
        return hash('sha256', 'courseforge-invite:' . self::normalise($code));
    }

    /**
     * Publishes the code, next to index.html if that is writable and in the
     * data directory if it is not. Both are refused over HTTP by the shipped
     * server configuration.
     */
    private static function write(int $inviteId, string $code, string $role, int $expires): string
    {
        $when = $expires > 0
            ? 'This code expires on ' . gmdate('Y-m-d H:i', $expires) . ' UTC.'
            : 'This code does not expire, but it can only be used once.';

        $body = <<<TXT
        CourseForge - invite code
        =========================

        Type this code into the setup screen to create the first {$role} account:

            {$code}

        {$when}

        The code is deleted the moment an account is created with it. If you lose
        this file before then, an administrator can issue a new invite from
        Settings, or you can delete the row from the `invites` table and restart.

        Nobody who cannot read this file can create an account, which is the
        whole point of it - keep it off a public web server. The shipped
        .htaccess already refuses every .txt file; on nginx, Caddy or IIS you
        have to say so yourself (see docs.md).
        TXT;

        foreach ([CF_ROOT, CF_DATA] as $dir) {
            $path = rtrim($dir, "/\\") . '/' . self::FILE;
            if (@file_put_contents($path, $body . "\n", LOCK_EX) !== false) {
                @chmod($path, 0640);
                // By id, not by MAX(id): two invites issued in the same second
                // would otherwise leave one row pointing at the other one's file.
                Db::run('UPDATE invites SET file_path = ? WHERE id = ?', [$path, $inviteId]);
                return $path;
            }
        }

        throw new \RuntimeException(
            'Neither the installation directory nor the data directory is writable, so the invite code could not '
            . 'be written. Make one of them writable by PHP and reload.'
        );
    }
}
