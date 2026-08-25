<?php
declare(strict_types=1);

namespace CourseForge\Security;

use CourseForge\Support\Db;
use CourseForge\Support\HttpException;
use CourseForge\Support\Json;

/**
 * The accounts, in the database.
 *
 * CourseForge 3.x kept a single administrator in `data/users.json`, edited by
 * hand before the first start. 4.0 has real accounts with roles, created from
 * the application itself, so they live in a table like everything else - and
 * `users.json`, if one is found, is imported once and then left alone.
 *
 * A password hash never leaves this class. Everything else about an account is
 * public to an administrator and to the account itself.
 */
final class Users
{
    /** Below this a password is refused outright, whoever sets it. */
    public const MIN_PASSWORD = 10;

    /**
     * The ceiling, in bytes rather than characters, because that is the unit
     * the hash reads.
     *
     * PASSWORD_DEFAULT is bcrypt, and bcrypt uses the first 72 bytes and
     * silently discards the rest - two different eighty-character passwords
     * verify against each other, which was measured rather than assumed. A
     * character is not a byte either: thirty emoji is a hundred and twenty
     * bytes. So the limit is stated in the unit that matters and enforced
     * before the hash can quietly enforce it worse.
     */
    public const MAX_PASSWORD_BYTES = 72;

    public static function count(): int
    {
        return (int)(Db::row('SELECT COUNT(*) AS n FROM users')['n'] ?? 0);
    }

    public static function adminCount(): int
    {
        return (int)(Db::row(
            'SELECT COUNT(*) AS n FROM users WHERE role = ? AND disabled = 0',
            [Actor::ROLE_ADMIN]
        )['n'] ?? 0);
    }

    /**
     * True before the first administrator exists: the setup screen is due.
     *
     * A 3.x installation that has just been updated has no rows in this table
     * and its accounts in `data/users.json`, so the file is imported here,
     * where the question "are there any accounts?" is actually asked, rather
     * than from a migration step nothing calls. An installation with accounts
     * never gets as far as the import; one without pays a single stat for a
     * file that is not there.
     */
    public static function needsSetup(): bool
    {
        if (self::count() > 0) {
            return false;
        }
        self::importLegacyFile();
        return self::count() === 0;
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        $rows = Db::rows('SELECT * FROM users ORDER BY role = \'admin\' DESC, username COLLATE NOCASE');
        return array_map([self::class, 'publicView'], $rows);
    }

    /** @return array<string,mixed>|null */
    public static function find(string $username): ?array
    {
        $row = Db::row('SELECT * FROM users WHERE username = ? COLLATE NOCASE', [trim($username)]);
        return $row === null ? null : $row;
    }

    /** @return array<string,mixed> */
    public static function require(string $username): array
    {
        $row = self::find($username);
        if ($row === null) {
            throw HttpException::notFound('There is no account called "' . $username . '".');
        }
        return $row;
    }

    /** @return array<string,mixed>|null */
    public static function byId(int $id): ?array
    {
        return Db::row('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /**
     * Verifies a sign-in.
     *
     * A missing account still costs one hash comparison, so "no such user" and
     * "wrong password" take the same time and cannot be told apart.
     *
     * @return array<string,mixed>|null
     */
    public static function verify(string $username, string $password): ?array
    {
        $user = self::find($username);
        $hash = (string)($user['password_hash'] ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv');
        $ok = password_verify($password, $hash);

        if (!$ok || $user === null) {
            return null;
        }
        if ((int)$user['disabled'] === 1) {
            throw HttpException::forbidden('This account has been disabled.');
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [self::hash($password), (int)$user['id']]);
        }
        Db::run('UPDATE users SET last_login_at = ? WHERE id = ?', [time(), (int)$user['id']]);

        return $user;
    }

    /* --------------------------------------------------------------- writing */

    /**
     * Creates an account.
     *
     * `password_reset_at` is stamped because the password this account starts
     * with was chosen by whoever created it rather than by whoever will use it.
     * Nothing is revoked by it - a brand-new account owns no connections yet -
     * but it means the column says something true from the first row onwards,
     * rather than only after the first reset.
     *
     * @return array<string,mixed> the public view of the new account
     */
    public static function create(
        string $username,
        string $password,
        string $role,
        string $displayName = '',
        string $createdBy = '',
        bool $mustChangePassword = false,
    ): array {
        $username = self::validateUsername($username);
        self::validatePassword($password);

        if (self::find($username) !== null) {
            throw HttpException::unprocessable('An account called "' . $username . '" already exists.');
        }

        $now = time();
        Db::run(
            'INSERT INTO users (username, display_name, password_hash, role, disabled, created_at, updated_at, created_by, must_change_password, password_reset_at)
             VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?)',
            [
                $username,
                trim($displayName) !== '' ? trim($displayName) : $username,
                self::hash($password),
                Actor::normaliseRole($role),
                $now,
                $now,
                trim($createdBy),
                $mustChangePassword ? 1 : 0,
                $now,
            ]
        );

        return self::publicView(self::require($username));
    }

    /**
     * Changes the role of an account.
     *
     * The last enabled administrator cannot be demoted out of existence; an
     * installation with no administrator can only be repaired from the file
     * system, so the check is worth having - and worth making atomic, which is
     * what KEEPS_AN_ADMIN is for.
     */
    public static function setRole(string $username, string $role): array
    {
        $user = self::require($username);
        $role = Actor::normaliseRole($role);
        $sql = 'UPDATE users SET role = ?, updated_at = ? WHERE id = ?';
        $args = [$role, time(), (int)$user['id']];

        if ($role === Actor::ROLE_ADMIN) {
            Db::run($sql, $args);
        } else {
            self::writeKeepingAnAdmin($sql, $args, $username, 'change the role of');
        }
        return self::publicView(self::require($username));
    }

    public static function setDisabled(string $username, bool $disabled): array
    {
        $user = self::require($username);
        $sql = 'UPDATE users SET disabled = ?, updated_at = ? WHERE id = ?';
        $args = [$disabled ? 1 : 0, time(), (int)$user['id']];

        if ($disabled) {
            self::writeKeepingAnAdmin($sql, $args, $username, 'disable');
        } else {
            Db::run($sql, $args);
        }
        return self::publicView(self::require($username));
    }

    public static function setDisplayName(string $username, string $displayName): array
    {
        $user = self::require($username);
        $name = trim($displayName);
        Db::run(
            'UPDATE users SET display_name = ?, updated_at = ? WHERE id = ?',
            [$name === '' ? (string)$user['username'] : $name, time(), (int)$user['id']]
        );
        return self::publicView(self::require($username));
    }

    /** Changing your own password: the old one has to be right. */
    public static function changePassword(string $username, string $old, string $new): bool
    {
        $user = self::find($username);
        if ($user === null || !password_verify($old, (string)$user['password_hash'])) {
            return false;
        }
        self::validatePassword($new);
        Db::run(
            'UPDATE users SET password_hash = ?, must_change_password = 0, updated_at = ? WHERE id = ?',
            [self::hash($new), time(), (int)$user['id']]
        );
        return true;
    }

    /**
     * An administrator setting somebody else's password.
     *
     * This is the one write that stamps `password_reset_at`, and the stamp is
     * what revokes the account's older MCP connections - see
     * `McpClients::resolve()`. The reason a reset is treated differently from
     * the account changing its own password is what a reset is for: somebody
     * else now knows this password, so anything issued while the old one was
     * current has to stop working. An account choosing its own new password is
     * not evidence that anything was compromised, and revoking its connections
     * there would break the promise `change_my_password` makes in as many
     * words - including cutting off the connection making the call.
     */
    public static function setPassword(string $username, string $new, bool $mustChange = true): void
    {
        $user = self::require($username);
        self::validatePassword($new);
        $now = time();
        Db::run(
            'UPDATE users SET password_hash = ?, must_change_password = ?, updated_at = ?, password_reset_at = ? WHERE id = ?',
            [self::hash($new), $mustChange ? 1 : 0, $now, $now, (int)$user['id']]
        );
    }

    /**
     * Removes an account.
     *
     * What happens to the courses, profiles and tags it owns is the caller's
     * decision, because there is no safe default: deleting somebody's work by
     * accident is unrecoverable, and leaving it orphaned is worse.
     *
     * @param 'delete'|'transfer' $content
     */
    public static function delete(string $username, string $content, string $transferTo = ''): void
    {
        $user = self::require($username);
        $owner = (string)$user['username'];

        if ($content !== 'transfer' && $content !== 'delete') {
            throw HttpException::unprocessable('Say what should happen to this account\'s courses: "delete" or "transfer".');
        }

        $heir = '';
        if ($content === 'transfer') {
            $target = self::require($transferTo);
            if (strcasecmp((string)$target['username'], $owner) === 0) {
                throw HttpException::unprocessable('An account cannot inherit its own content.');
            }
            $heir = (string)$target['username'];
        }

        // The account row goes first, so the transaction opens as a writer -
        // which is what lets SQLite's busy handler apply - and the
        // last-administrator condition is decided by the same statement that
        // acts on it. The courses follow inside the same transaction, so a
        // refusal leaves them exactly where they were.
        Db::transaction(static function () use ($user, $username, $owner, $content, $heir): void {
            self::writeKeepingAnAdmin('DELETE FROM users WHERE id = ?', [(int)$user['id']], $username, 'delete');

            // The account's credentials die with it, whatever is chosen for its
            // content. A token that outlives its account is a way back in for
            // whoever still holds it; under 'transfer' it was worse than that,
            // because it came back as somebody else.
            Db::run('DELETE FROM mcp_clients WHERE username = ? COLLATE NOCASE', [$owner]);

            if ($content === 'transfer') {
                self::transferContent($owner, $heir);
            } else {
                self::deleteContent($owner);
            }
        });
    }

    /**
     * Moves the CONTENT one account owns to another.
     *
     * mcp_clients is deliberately not in this list. A connection token is the
     * account's credential, not one of its possessions: the only thing binding
     * a token to a person is the username on its row, and the role and scopes
     * it carries are re-derived from that account on every request. Moving the
     * row would not hand the heir a token - it would turn a token somebody else
     * is still holding into one that authenticates as the heir, and where the
     * heir is an administrator, into an administrator's.
     *
     * The caller deletes them instead, in the same transaction as the account.
     */
    public static function transferContent(string $from, string $to): void
    {
        Db::transaction(static function () use ($from, $to): void {
            foreach (['projects', 'profiles', 'batch_jobs'] as $table) {
                Db::run('UPDATE ' . $table . ' SET username = ? WHERE username = ? COLLATE NOCASE', [$to, $from]);
            }
            // Tags are unique per (owner, name), so a collision has to be
            // resolved rather than allowed to abort the whole transfer: the
            // receiving account already has a tag of that name, and every link
            // pointing at the old one is repointed at it.
            foreach (Db::rows('SELECT * FROM tags WHERE username = ? COLLATE NOCASE', [$from]) as $tag) {
                $existing = Db::row(
                    'SELECT id FROM tags WHERE username = ? COLLATE NOCASE AND name = ? COLLATE NOCASE',
                    [$to, (string)$tag['name']]
                );
                if ($existing === null) {
                    Db::run('UPDATE tags SET username = ? WHERE id = ?', [$to, (int)$tag['id']]);
                    continue;
                }
                Db::run(
                    'UPDATE OR IGNORE tag_links SET tag_id = ? WHERE tag_id = ?',
                    [(int)$existing['id'], (int)$tag['id']]
                );
                Db::run('DELETE FROM tags WHERE id = ?', [(int)$tag['id']]);
            }
        });
    }

    /** Removes everything one account owns. */
    public static function deleteContent(string $username): void
    {
        Db::transaction(static function () use ($username): void {
            // Courses cascade into chapters, pages, tag links and run rows.
            Db::run('DELETE FROM projects WHERE username = ? COLLATE NOCASE', [$username]);
            Db::run('DELETE FROM profiles WHERE username = ? COLLATE NOCASE', [$username]);
            Db::run('DELETE FROM tags WHERE username = ? COLLATE NOCASE', [$username]);
            Db::run('DELETE FROM mcp_clients WHERE username = ? COLLATE NOCASE', [$username]);
            Db::run('DELETE FROM batch_jobs WHERE username = ? COLLATE NOCASE', [$username]);
        });
    }

    /** How much of the installation one account owns, for the confirm dialog. */
    public static function contentSummary(string $username): array
    {
        $one = static fn(string $sql): int => (int)(Db::row($sql, [$username])['n'] ?? 0);
        return [
            'courses' => $one('SELECT COUNT(*) AS n FROM projects WHERE username = ? COLLATE NOCASE'),
            'profiles' => $one('SELECT COUNT(*) AS n FROM profiles WHERE username = ? COLLATE NOCASE'),
            'tags' => $one('SELECT COUNT(*) AS n FROM tags WHERE username = ? COLLATE NOCASE'),
            'connections' => $one('SELECT COUNT(*) AS n FROM mcp_clients WHERE username = ? COLLATE NOCASE'),
            'pages' => (int)(Db::row(
                'SELECT COUNT(*) AS n FROM pages p JOIN projects j ON j.id = p.project_id
                 WHERE j.username = ? COLLATE NOCASE',
                [$username]
            )['n'] ?? 0),
        ];
    }

    /* -------------------------------------------------------------- helpers */

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function validateUsername(string $username): string
    {
        $username = trim($username);
        if ($username === '') {
            throw HttpException::unprocessable('A user name is required.');
        }
        if (mb_strlen($username) > 64) {
            throw HttpException::unprocessable('That user name is too long (64 characters at most).');
        }
        if (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._@+-]*$/u', $username)) {
            throw HttpException::unprocessable(
                'A user name may hold letters, digits, spaces and . _ @ + - and must start with a letter or a digit.'
            );
        }
        return $username;
    }

    public static function validatePassword(string $password): void
    {
        if (str_contains($password, "\0")) {
            // password_hash() throws a ValueError on a null byte, and an
            // uncaught ValueError is a 500 for what is plainly bad input.
            throw HttpException::unprocessable('A password cannot contain a null character.');
        }
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            throw HttpException::unprocessable('A password needs at least ' . self::MIN_PASSWORD . ' characters.');
        }
        if (strlen($password) > self::MAX_PASSWORD_BYTES) {
            throw HttpException::unprocessable(
                'That password is too long. The hash reads the first ' . self::MAX_PASSWORD_BYTES
                . ' bytes and ignores the rest, so a longer one is not a stronger one - and accepting it would '
                . 'mean storing something different from what you typed. Note that a byte is not a character: an '
                . 'accented letter is two and an emoji is four.'
            );
        }
    }

    /** @return array<string,mixed> */
    public static function publicView(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'username' => (string)$row['username'],
            'display_name' => (string)($row['display_name'] ?: $row['username']),
            'role' => (string)$row['role'],
            'is_admin' => (string)$row['role'] === Actor::ROLE_ADMIN,
            'disabled' => (int)$row['disabled'] === 1,
            'must_change_password' => (int)($row['must_change_password'] ?? 0) === 1,
            'created_at' => (int)$row['created_at'],
            'updated_at' => (int)$row['updated_at'],
            'last_login_at' => (int)$row['last_login_at'],
            'created_by' => (string)($row['created_by'] ?? ''),
        ];
    }

    /**
     * The condition under which a row may be demoted, disabled or deleted: it
     * is not an enabled administrator, or it is not the last one.
     *
     * This is a SQL fragment rather than a check in PHP because the count and
     * the write have to be one statement. Reading the count and then writing
     * leaves a window - the few milliseconds of the commit - and two
     * administrators disabling each other inside it both read "two
     * administrators", both wrote, and left the installation with none, which
     * can only be repaired by editing app.sqlite by hand. Folded into the
     * write, the second statement is serialised behind the first and sees its
     * effect, so it matches no rows and is refused.
     *
     * Only ever interpolated into SQL, never near a value: it holds no
     * parameters of its own.
     */
    private const KEEPS_AN_ADMIN =
        "(role <> 'admin' OR disabled = 1 OR (SELECT COUNT(*) FROM users WHERE role = 'admin' AND disabled = 0) > 1)";

    /**
     * Runs a write that must not remove the last enabled administrator.
     *
     * The statement is expected to end in a WHERE clause, so the condition can
     * be appended to it. Nothing changed means either that the guard refused or
     * that the row went away underneath us, and those are different answers.
     *
     * @param array<int,mixed> $args
     */
    private static function writeKeepingAnAdmin(string $sql, array $args, string $username, string $verb): void
    {
        if (Db::run($sql . ' AND ' . self::KEEPS_AN_ADMIN, $args)->rowCount() > 0) {
            return;
        }

        self::require($username); // gone rather than guarded: that is a 404
        throw HttpException::unprocessable(
            'This is the only administrator left. Promote another account before you ' . $verb . ' this one.'
        );
    }

    /* ------------------------------------------------------------- migration */

    /**
     * Imports a CourseForge 3.x `data/users.json` exactly once.
     *
     * Those accounts were the only ones the installation had and could do
     * everything, so they arrive as administrators. The file is renamed rather
     * than deleted: it is the only copy of the hash, and an import that went
     * wrong should be recoverable by hand.
     *
     * `password_reset_at` is deliberately left at its column default of zero.
     * Nobody has reset these passwords - they are the ones the installation
     * already had - and stamping the import instead would revoke every
     * CourseForge 3 connection the moment 4.0 first booted, which is the
     * opposite of what those tokens were promised.
     */
    public static function importLegacyFile(): int
    {
        $file = CF_DATA . '/users.json';
        if (!is_file($file) || self::count() > 0) {
            return 0;
        }

        try {
            $data = Json::read($file) ?? [];
        } catch (\Throwable) {
            return 0;
        }
        $list = is_array($data['users'] ?? null) ? $data['users'] : [];

        $imported = 0;
        $now = time();
        foreach ($list as $user) {
            if (!is_array($user)) {
                continue;
            }
            $username = trim((string)($user['username'] ?? ''));
            if ($username === '') {
                continue;
            }
            $hash = (string)($user['password_hash'] ?? '');
            if ($hash === '' && ($user['password_plain'] ?? '') !== '') {
                $hash = self::hash((string)$user['password_plain']);
            }
            if ($hash === '') {
                continue;
            }
            Db::run(
                'INSERT OR IGNORE INTO users (username, display_name, password_hash, role, disabled, created_at, updated_at, created_by)
                 VALUES (?, ?, ?, ?, 0, ?, ?, ?)',
                [
                    $username,
                    trim((string)($user['display_name'] ?? '')) ?: $username,
                    $hash,
                    Actor::ROLE_ADMIN,
                    $now,
                    $now,
                    'users.json',
                ]
            );
            $imported++;
        }

        if ($imported > 0) {
            @rename($file, $file . '.imported');
        }
        return $imported;
    }
}
