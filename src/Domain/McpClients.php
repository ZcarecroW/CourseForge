<?php
declare(strict_types=1);

namespace CourseForge\Domain;

use CourseForge\Security\Actor;
use CourseForge\Security\Users;
use CourseForge\Support\Db;
use CourseForge\Support\HttpException;

/**
 * The tokens an MCP client uses to reach this installation.
 *
 * One row per connection, because that is the unit you revoke: the laptop, the
 * desktop, the one you set up once to try it. Only the hash is stored, so a
 * token is shown exactly once - when it is created - and a copy of the database
 * hands nobody a working connection.
 *
 * SHA-256 rather than a password hash, on purpose. These are 32 random bytes,
 * not something a person chose, so there is nothing to brute force; and the
 * endpoint has to find the row *by* the token on every request, which a salted
 * password hash cannot do.
 *
 * Three things were added in 4.0, all of them about giving away less:
 *
 *   - **scopes**. A connection may be limited to some of the tool groups. A
 *     token that only ever writes pages has no business deleting a course.
 *   - **an expiry**. A connection made for an afternoon can be made to end by
 *     itself.
 *   - **the role is read at request time**, from the account, not frozen into
 *     the token. Demote somebody and their connections stop being able to
 *     administer the installation on the next request, not whenever they happen
 *     to make a new one.
 */
final class McpClients
{
    private const PREFIX = 'cf4_';

    /** Tokens issued by CourseForge 3, which stay valid until they are revoked. */
    private const LEGACY_PREFIX = 'cf3_';

    /**
     * Issues a token and returns it in the clear, once.
     *
     * @param string[] $scopes empty for "everything this account may do"
     * @return array{client:array<string,mixed>,token:string}
     */
    public static function create(
        string $username,
        string $name,
        array $scopes = [],
        int $ttlDays = 0,
        string $note = '',
    ): array {
        $name = trim($name) !== '' ? mb_substr(trim($name), 0, 60) : 'Claude';
        $token = self::PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expires = $ttlDays > 0 ? time() + ($ttlDays * 86400) : 0;

        Db::run(
            'INSERT INTO mcp_clients (username, name, token_hash, scopes, note, expires_at, created_at, last_used_at, uses)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0)',
            [
                $username,
                $name,
                self::hash($token),
                implode(',', array_values(array_unique(array_filter(array_map('strval', $scopes))))),
                mb_substr(trim($note), 0, 200),
                $expires,
                time(),
            ]
        );

        return ['client' => self::byId(Db::lastId()), 'token' => $token];
    }

    /**
     * One account's connections, or every connection on the installation.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function all(?string $username): array
    {
        $where = $username === null ? '' : 'WHERE username = ?';
        $args = $username === null ? [] : [$username];

        $rows = Db::rows(
            "SELECT id, username, name, note, scopes, expires_at, created_at, last_used_at, uses
               FROM mcp_clients {$where} ORDER BY created_at DESC",
            $args
        );
        return array_map(static fn(array $row): array => self::shape($row), $rows);
    }

    /** @return array<string,mixed> */
    public static function byId(int $id): array
    {
        $row = Db::row(
            'SELECT id, username, name, note, scopes, expires_at, created_at, last_used_at, uses FROM mcp_clients WHERE id = ?',
            [$id]
        );
        if ($row === null) {
            throw HttpException::notFound('Connection not found.');
        }
        return self::shape($row);
    }

    /** @return array<string,mixed> */
    public static function require(string $username, int $id): array
    {
        $row = Db::row(
            'SELECT id, username, name, note, scopes, expires_at, created_at, last_used_at, uses
               FROM mcp_clients WHERE username = ? AND id = ?',
            [$username, $id]
        );
        if ($row === null) {
            throw HttpException::notFound('Connection not found.');
        }
        return self::shape($row);
    }

    public static function delete(string $username, int $id): void
    {
        self::require($username, $id);
        Db::run('DELETE FROM mcp_clients WHERE username = ? AND id = ?', [$username, $id]);
    }

    /** Revoking somebody else's connection, as an administrator. */
    public static function deleteById(int $id): void
    {
        Db::run('DELETE FROM mcp_clients WHERE id = ?', [$id]);
    }

    public static function rename(string $username, int $id, string $name, string $note): array
    {
        self::require($username, $id);
        Db::run(
            'UPDATE mcp_clients SET name = ?, note = ? WHERE id = ?',
            [mb_substr(trim($name), 0, 60) ?: 'Claude', mb_substr(trim($note), 0, 200), $id]
        );
        return self::byId($id);
    }

    /**
     * Who a token belongs to, and what they may do with it.
     *
     * The lookup is by hash against a unique index, so a wrong token costs one
     * index probe. Everything else - the role, whether the account still exists,
     * whether it has been disabled - is read from the account itself, which is
     * what makes revocation immediate.
     *
     * @return array{actor:Actor,client_id:int,client_name:string,scopes:string[]}|null
     */
    public static function resolve(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || (!str_starts_with($token, self::PREFIX) && !str_starts_with($token, self::LEGACY_PREFIX))) {
            return null;
        }

        $row = Db::row(
            'SELECT id, username, name, scopes, expires_at FROM mcp_clients WHERE token_hash = ?',
            [self::hash($token)]
        );
        if ($row === null) {
            return null;
        }
        if ((int)$row['expires_at'] > 0 && (int)$row['expires_at'] < time()) {
            return null;
        }

        $user = Users::find((string)$row['username']);
        if ($user === null || (int)$user['disabled'] === 1) {
            return null;
        }

        Db::run('UPDATE mcp_clients SET last_used_at = ?, uses = uses + 1 WHERE id = ?', [time(), (int)$row['id']]);

        return [
            'actor' => Actor::make(
                (string)$user['username'],
                (string)($user['display_name'] ?: $user['username']),
                (string)$user['role']
            ),
            'client_id' => (int)$row['id'],
            'client_name' => (string)$row['name'],
            'scopes' => self::splitScopes((string)$row['scopes']),
        ];
    }

    /** Whether anybody has connected anything at all. */
    public static function any(): bool
    {
        return (int)(Db::row('SELECT COUNT(*) AS n FROM mcp_clients')['n'] ?? 0) > 0;
    }

    /** @return string[] */
    public static function splitScopes(string $stored): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $stored))));
    }

    /** @return array<string,mixed> */
    private static function shape(array $row): array
    {
        $expires = (int)($row['expires_at'] ?? 0);
        return [
            'id' => (int)$row['id'],
            'owner' => (string)($row['username'] ?? ''),
            'name' => (string)$row['name'],
            'note' => (string)($row['note'] ?? ''),
            'scopes' => self::splitScopes((string)($row['scopes'] ?? '')),
            'expires_at' => $expires,
            'expired' => $expires > 0 && $expires < time(),
            'created_at' => (int)$row['created_at'],
            'last_used_at' => (int)$row['last_used_at'],
            'uses' => (int)$row['uses'],
        ];
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
