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

    /** True before the first administrator exists: the setup screen is due. */
    public static function needsSetup(): bool
    {
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
            'INSERT INTO users (username, display_name, password_hash, role, disabled, created_at, updated_at, created_by, must_change_password)
             VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?)',
            [
                $username,
                trim($displayName) !== '' ? trim($displayName) : $username,
                self::hash($password),
                Actor::normaliseRole($role),
                $now,
                $now,
                trim($createdBy),
                $mustChangePassword ? 1 : 0,
            ]
        );

        return self::publicView(self::require($username));
    }

    /**
     * Changes the role of an account.
     *
     * The last enabled administrator cannot demote themselves out of existence;
     * an installation with no administrator can only be repaired from the file
     * system, so the check is worth having.
     */
    public static function setRole(string $username, string $role): array
    {
        $user = self::require($username);
        $role = Actor::normaliseRole($role);

        if ($role !== Actor::ROLE_ADMIN && (string)$user['role'] === Actor::ROLE_ADMIN) {
            self::guardLastAdmin($username, 'change the role of');
        }

        Db::run('UPDATE users SET role = ?, updated_at = ? WHERE id = ?', [$role, time(), (int)$user['id']]);
        return self::publicView(self::require($username));
    }

    public static function setDisabled(string $username, bool $disabled): array
    {
        $user = self::require($username);
        if ($disabled && (string)$user['role'] === Actor::ROLE_ADMIN) {
            self::guardLastAdmin($username, 'disable');
        }

        Db::run('UPDATE users SET disabled = ?, updated_at = ? WHERE id = ?', [$disabled ? 1 : 0, time(), (int)$user['id']]);
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

    /** An administrator setting somebody else's password. */
    public static function setPassword(string $username, string $new, bool $mustChange = true): void
    {
        $user = self::require($username);
        self::validatePassword($new);
        Db::run(
            'UPDATE users SET password_hash = ?, must_change_password = ?, updated_at = ? WHERE id = ?',
            [self::hash($new), $mustChange ? 1 : 0, time(), (int)$user['id']]
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
        self::guardLastAdmin($username, 'delete');

        if ($content === 'transfer') {
            $target = self::require($transferTo);
            if (strcasecmp((string)$target['username'], (string)$user['username']) === 0) {
                throw HttpException::unprocessable('An account cannot inherit its own content.');
            }
            self::transferContent((string)$user['username'], (string)$target['username']);
        } elseif ($content === 'delete') {
            self::deleteContent((string)$user['username']);
        } else {
            throw HttpException::unprocessable('Say what should happen to this account\'s courses: "delete" or "transfer".');
        }

        Db::run('DELETE FROM users WHERE id = ?', [(int)$user['id']]);
    }

    /** Moves everything one account owns to another. */
    public static function transferContent(string $from, string $to): void
    {
        Db::transaction(static function () use ($from, $to): void {
            foreach (['projects', 'profiles', 'batch_jobs', 'mcp_clients'] as $table) {
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
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            throw HttpException::unprocessable('A password needs at least ' . self::MIN_PASSWORD . ' characters.');
        }
        if (mb_strlen($password) > 4096) {
            throw HttpException::unprocessable('That password is absurdly long.');
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

    private static function guardLastAdmin(string $username, string $verb): void
    {
        $user = self::require($username);
        if ((string)$user['role'] !== Actor::ROLE_ADMIN || (int)$user['disabled'] === 1) {
            return;
        }
        if (self::adminCount() <= 1) {
            throw HttpException::unprocessable(
                'This is the only administrator left. Promote another account before you ' . $verb . ' this one.'
            );
        }
    }

    /* ------------------------------------------------------------- migration */

    /**
     * Imports a CourseForge 3.x `data/users.json` exactly once.
     *
     * Those accounts were the only ones the installation had and could do
     * everything, so they arrive as administrators. The file is renamed rather
     * than deleted: it is the only copy of the hash, and an import that went
     * wrong should be recoverable by hand.
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
