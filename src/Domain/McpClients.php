<?php
declare(strict_types=1);

namespace CourseForge\Domain;

use CourseForge\Support\Db;
use CourseForge\Support\HttpException;

/**
 * The tokens a Claude client uses to reach this installation.
 *
 * One row per connection, because that is the unit you revoke: the laptop, the
 * desktop, the one you set up once to try it. Only the hash is stored, so a
 * token is shown exactly once - when it is created - and a copy of the database
 * does not hand anybody a working connection.
 *
 * SHA-256 rather than a password hash on purpose. These are 32 random bytes,
 * not something a person chose, so there is nothing to brute force; and the
 * endpoint has to find the row *by* the token on every request, which a salted
 * password hash cannot do.
 */
final class McpClients
{
    private const PREFIX = 'cf3_';

    /**
     * Issues a token and returns it in the clear, once.
     *
     * @return array{client:array<string,mixed>,token:string}
     */
    public static function create(string $username, string $name): array
    {
        $name = trim($name) !== '' ? mb_substr(trim($name), 0, 60) : 'Claude';
        $token = self::PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        Db::run(
            'INSERT INTO mcp_clients (username, name, token_hash, created_at, last_used_at, uses) VALUES (?,?,?,?,0,0)',
            [$username, $name, self::hash($token), time()]
        );

        return ['client' => self::require($username, Db::lastId()), 'token' => $token];
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(string $username): array
    {
        $rows = Db::rows(
            'SELECT id, name, created_at, last_used_at, uses FROM mcp_clients WHERE username = ? ORDER BY created_at DESC',
            [$username]
        );
        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'created_at' => (int)$row['created_at'],
            'last_used_at' => (int)$row['last_used_at'],
            'uses' => (int)$row['uses'],
        ], $rows);
    }

    /** @return array<string,mixed> */
    public static function require(string $username, int $id): array
    {
        $row = Db::row('SELECT id, name, created_at, last_used_at, uses FROM mcp_clients WHERE username = ? AND id = ?', [$username, $id]);
        if ($row === null) {
            throw HttpException::notFound('Connection not found.');
        }
        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'created_at' => (int)$row['created_at'],
            'last_used_at' => (int)$row['last_used_at'],
            'uses' => (int)$row['uses'],
        ];
    }

    public static function delete(string $username, int $id): void
    {
        self::require($username, $id);
        Db::run('DELETE FROM mcp_clients WHERE username = ? AND id = ?', [$username, $id]);
    }

    /**
     * The user a token belongs to, or null.
     *
     * The lookup is by hash, which is a unique index, so a wrong token costs one
     * index probe and reveals nothing by timing.
     */
    public static function resolve(string $token): ?string
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $row = Db::row('SELECT id, username FROM mcp_clients WHERE token_hash = ?', [self::hash($token)]);
        if ($row === null) {
            return null;
        }

        Db::run('UPDATE mcp_clients SET last_used_at = ?, uses = uses + 1 WHERE id = ?', [time(), (int)$row['id']]);
        return (string)$row['username'];
    }

    /** Whether anybody has connected anything at all. */
    public static function any(): bool
    {
        return (int)(Db::row('SELECT COUNT(*) AS n FROM mcp_clients')['n'] ?? 0) > 0;
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
