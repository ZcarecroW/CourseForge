<?php
declare(strict_types=1);

namespace CourseForge\Security;

use CourseForge\Support\Db;
use CourseForge\Support\HttpException;

/**
 * The invite code that turns into an account.
 *
 * A fresh installation is a web application with no accounts and a form that
 * creates the first administrator. Anything reachable from the internet in that
 * state is a race between the person who installed it and whoever finds it
 * first, so the form asks for a code that only exists on the file system:
 * `INVITE-CODE.txt`, written next to `index.html` the first time CourseForge is
 * opened. Somebody who can read that file can already read `config/`, so it
 * proves exactly the right thing.
 *
 * The same mechanism serves a second door once that first account exists: an
 * administrator issues an invite for a role, and whoever holds the code creates
 * their own account with it and chooses their own password, rather than being
 * handed one over a chat. The role is the one on the row, decided by the
 * administrator who issued it - a code is never an argument about what it is
 * worth.
 *
 * The plain code is in that file and nowhere else - the database keeps a hash,
 * the same way it does for a password or a connection token. An invite carries
 * a number of uses and, unless it is the first one, an expiry; the ordinary
 * invite is worth one account, and an administrator can issue one worth more
 * when a whole group is being let in at once. A code worth five accounts is
 * worth five accounts to whoever finds the file, which is why the ceiling is
 * low and the file is still the only place the code exists.
 */
final class Invite
{
    public const FILE = 'INVITE-CODE.txt';

    /** How long an invite an administrator issues from the app stays valid. */
    public const DEFAULT_TTL_HOURS = 48;

    /**
     * The most accounts one code may ever create.
     *
     * Not a technical limit - the counter would hold any number. It is a blast
     * radius: the code sits in a plain file, and whoever finds that file gets
     * every remaining use of it. Fifty is enough for a cohort and small enough
     * that leaving one open is not the same as leaving the door open.
     */
    public const MAX_USES = 50;

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
     * @param int $maxUses how many accounts this one code may create
     * @return array{code:string,path:string,role:string,expires_at:int,max_uses:int}
     */
    public static function issue(
        string $role,
        int $ttlHours = self::DEFAULT_TTL_HOURS,
        string $issuedBy = '',
        int $maxUses = 1,
    ): array {
        $role = Actor::normaliseRole($role);
        $code = self::freshCode();
        $expires = $ttlHours > 0 ? time() + ($ttlHours * 3600) : 0;
        // Clamped rather than refused: every caller already clamps at its own
        // edge, and this is the one place none of them can bypass.
        $maxUses = max(1, min(self::MAX_USES, $maxUses));

        // Only one invite is ever open: the file holds exactly one code, so a
        // second open row would be a code nobody can read.
        Db::run('UPDATE invites SET used_at = ?, used_by = ? WHERE used_at = 0', [time(), 'superseded']);
        Db::run(
            'INSERT INTO invites (code_hash, role, created_at, expires_at, issued_by, max_uses) '
                . 'VALUES (?, ?, ?, ?, ?, ?)',
            [self::hash($code), $role, time(), $expires, $issuedBy, $maxUses]
        );

        $path = self::write(Db::lastId(), $code, $role, $expires, $maxUses);
        return [
            'code' => $code, 'path' => $path, 'role' => $role,
            'expires_at' => $expires, 'max_uses' => $maxUses,
        ];
    }

    /**
     * The invite that is currently open, if there is one.
     *
     * @return array<string,mixed>|null
     */
    public static function open(): ?array
    {
        // Two conditions, and both are needed. "uses < max_uses" is what runs
        // out on its own; "used_at = 0" is still how a row is closed by hand -
        // superseded by a newer invite, or abandoned because its file was lost -
        // and such a row can be closed with slots still on it.
        return Db::row(
            'SELECT * FROM invites WHERE used_at = 0 AND uses < max_uses '
                . 'AND (expires_at = 0 OR expires_at > ?) ORDER BY id DESC LIMIT 1',
            [time()]
        );
    }

    /** The person on the first-run screen: they installed this and can read files on the server. */
    public const AUDIENCE_INSTALLER = 'installer';

    /** The person redeeming an invite: they were sent a code and have nothing else. */
    public const AUDIENCE_HOLDER = 'holder';

    /**
     * Checks a code against the open invite.
     *
     * The refusal is worded for whoever is reading it, which is not the same
     * person at the two doors. On the first run it is the installer, who can
     * open INVITE-CODE.txt and should be told to. On the other it is somebody
     * who was sent a code and has no account, no shell and no reason to have
     * heard of that file - pointing them at it sends them looking for something
     * they cannot reach, which is worse than saying nothing.
     *
     * @return array<string,mixed> the invite row
     */
    public static function verify(string $code, string $audience = self::AUDIENCE_INSTALLER): array
    {
        $code = self::normalise($code);
        $invite = self::open();

        // Always spend the comparison, so a wrong code and no invite at all
        // are indistinguishable from the outside.
        $expected = (string)($invite['code_hash'] ?? str_repeat('0', 64));
        $matches = hash_equals($expected, self::hash($code));

        if ($invite === null || !$matches) {
            throw HttpException::forbidden(
                'That invite code is not valid. ' . ($audience === self::AUDIENCE_HOLDER
                    ? 'Check you have copied all six groups of it, and ask whoever sent it to you for a new one '
                        . 'if it has been used already.'
                    : 'Read the current one from ' . self::FILE . ', in the folder CourseForge is installed in.')
            );
        }
        return $invite;
    }

    /**
     * Spends an invite, and says whether this caller is the one who spent it.
     *
     * The write is conditional on the row still having a slot rather than being
     * a bare UPDATE, because "an invite is good for N accounts" has to hold when
     * two requests arrive with the same code at the same instant. The count is
     * re-read inside the statement and SQLite serialises the two writers, so the
     * one that arrives second at the last slot matches no rows and is told so -
     * two simultaneous setups used to produce two administrators from a single
     * one-shot code.
     *
     * Taking the last slot is what stamps used_at, which is what closes the row.
     * used_by therefore names whoever took that last one; the record of each
     * individual redemption is the invite.redeem line in the audit log.
     *
     * The caller is expected to be inside the transaction that creates the
     * account, so that a spent invite and an account are the same event.
     */
    public static function consume(array $invite, string $username): bool
    {
        return Db::run(
            'UPDATE invites SET uses = uses + 1, used_by = ?, '
                . 'used_at = CASE WHEN uses + 1 >= max_uses THEN ? ELSE 0 END '
                . 'WHERE id = ? AND used_at = 0 AND uses < max_uses',
            [$username, time(), (int)$invite['id']]
        )->rowCount() > 0;
    }

    /**
     * Removes the file a spent invite was published in.
     *
     * Separate from consume() because a transaction can be rolled back and an
     * unlinked file cannot: the code lives in that file and nowhere else, so
     * deleting it before the account is certain would leave an invite nobody
     * can read. Both fixed locations are swept as well as the recorded one, so
     * that a file left behind by an earlier invite cannot outlive it.
     *
     * An invite with slots left keeps its file, for the same reason: the code is
     * in it and nowhere else, and the next person to redeem it has to be able to
     * be sent it. The row is re-read rather than trusted from the caller's copy,
     * which was fetched before the slot was taken and would still say the invite
     * is open even on the redemption that closed it.
     */
    public static function discard(array $invite): void
    {
        $row = Db::row('SELECT used_at FROM invites WHERE id = ?', [(int)($invite['id'] ?? 0)]);
        if ($row !== null && (int)$row['used_at'] === 0) {
            return;
        }

        @unlink(self::pathOf($invite));
        @unlink(CF_ROOT . '/' . self::FILE);
        @unlink(CF_DATA . '/' . self::FILE);
    }

    /**
     * Takes the open invite back, before anybody has spent it.
     *
     * The one thing an administrator could not do from the application. An
     * invite sent to the wrong person, or issued for the wrong role, could only
     * be answered by issuing a second one - which closes the first row but
     * leaves a live code sitting in INVITE-CODE.txt for whoever finds it, and
     * leaves the installation with an open invite nobody meant to be open.
     *
     * Closing the row is the whole of the guarantee: verify() reads the open
     * row and there is no longer one to match, so the code is worthless the
     * instant this returns. Deleting the file is tidiness on top of that, and
     * discard() is what does it - the row is closed first precisely so that its
     * "only unlink the file of a closed row" rule lets it through.
     *
     * `used_by` is written as the bare word "revoked" rather than as a
     * sentence, because Db::migrate() reads that column to tell a row closed
     * administratively from one somebody actually spent, and it matches on
     * exact names. Who did it is the invite.revoke line in the audit log.
     *
     * @return array<string,mixed>|null the row that was closed, or null when
     *                                  there was nothing open to take back
     */
    public static function revoke(): ?array
    {
        $invite = self::open();
        if ($invite === null) {
            return null;
        }

        // Conditional on the row still being open, for the same reason
        // consume() is: the last redemption and this can arrive together, and
        // the one that loses must not report that it revoked anything.
        $closed = Db::run(
            'UPDATE invites SET used_at = ?, used_by = ? WHERE id = ? AND used_at = 0',
            [time(), 'revoked', (int)$invite['id']]
        )->rowCount() > 0;

        // Only the caller that actually closed the row may sweep the file.
        // Losing the race here does not mean losing it to a redemption: it also
        // means losing it to issue(), which supersedes this row and writes a
        // NEW code into the very same file. Unlinking unconditionally would
        // have deleted that one - leaving an invite open in the database whose
        // code nobody, including the administrator who had just issued it,
        // could ever read.
        if ($closed) {
            self::discard($invite);
        }
        return $closed ? $invite : null;
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
            return [
                'open' => false, 'path' => '', 'role' => '', 'expires_at' => 0,
                'uses' => 0, 'max_uses' => 0, 'uses_left' => 0,
            ];
        }
        $path = self::pathOf($invite);
        $uses = (int)$invite['uses'];
        $maxUses = (int)$invite['max_uses'];
        return [
            'open' => true,
            'path' => $path,
            'file_present' => is_file($path),
            'role' => (string)$invite['role'],
            'created_at' => (int)$invite['created_at'],
            'expires_at' => (int)$invite['expires_at'],
            'uses' => $uses,
            'max_uses' => $maxUses,
            'uses_left' => max(0, $maxUses - $uses),
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
    private static function write(
        int $inviteId,
        string $code,
        string $role,
        int $expires,
        int $maxUses = 1,
    ): string {
        // How many accounts, said once and reused, because the count has to
        // agree in all three places this file states it.
        $many = $maxUses > 1;
        $accounts = $many ? "{$maxUses} {$role} accounts" : "one {$role} account";

        $lifetime = $expires > 0
            ? 'This code expires on ' . gmdate('Y-m-d H:i', $expires) . ' UTC'
            : 'This code does not expire';
        $when = $lifetime . ($many
            ? ", and it can be used {$maxUses} times."
            : ', but it can only be used once.');

        // The first code is the only way to reach the setup screen; a later one
        // is redeemed against an installation that already has accounts, and
        // saying "setup screen" there would send its holder to a form that
        // refuses them.
        $purpose = $expires > 0
            ? "This code creates {$accounts}, whose password" . ($many ? 's are' : ' is')
                . ' chosen by whoever uses it:'
            : "Type this code into the setup screen to create the first {$role} account:";

        // A code worth several accounts is worth several accounts to whoever
        // finds it, and the file is where it is findable. Say so here rather
        // than only in the documentation.
        $fate = $many
            ? "The code is deleted the moment the {$maxUses}th account is created with it, "
                . 'so until then anyone who can read this file can create one of the '
                . 'remaining accounts.'
            : 'The code is deleted the moment an account is created with it.';

        $body = <<<TXT
        CourseForge - invite code
        =========================

        {$purpose}

            {$code}

        {$when}

        {$fate} If you lose this file before then, an administrator can issue a
        new invite from Settings, or you can delete the row from the `invites`
        table and restart.

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
