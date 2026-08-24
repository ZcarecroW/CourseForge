<?php
declare(strict_types=1);

namespace CourseForge\Security;

use RuntimeException;

/**
 * The user list in data/users.json.
 *
 * A `password_plain` entry is a one-time convenience for the first start: it is
 * hashed and written back immediately, so the plain text never survives the
 * first request.
 */
final class Users
{
    public static function file(): string
    {
        return CF_DATA . '/users.json';
    }

    /** @return array{users:array<int,array<string,mixed>>} */
    public static function load(): array
    {
        $raw = @file_get_contents(self::file());
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || !isset($data['users']) || !is_array($data['users'])) {
            throw new RuntimeException('users.json is missing or invalid (expected at ' . self::file() . ').');
        }

        $changed = false;
        foreach ($data['users'] as $i => $user) {
            if (!empty($user['password_plain'])) {
                $data['users'][$i]['password_hash'] = password_hash((string)$user['password_plain'], PASSWORD_DEFAULT);
                unset($data['users'][$i]['password_plain']);
                $changed = true;
            }
        }
        if ($changed) {
            self::save($data);
        }
        /** @var array{users:array<int,array<string,mixed>>} $data */
        return $data;
    }

    /** @param array{users:array<int,array<string,mixed>>} $data */
    public static function save(array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || @file_put_contents(self::file(), $json, LOCK_EX) === false) {
            throw new RuntimeException('users.json is not writable: ' . self::file());
        }
    }

    /** @return array<string,mixed>|null */
    public static function find(string $username): ?array
    {
        foreach (self::load()['users'] as $user) {
            if (strcasecmp((string)($user['username'] ?? ''), $username) === 0) {
                return $user;
            }
        }
        return null;
    }

    public static function verify(string $username, string $password): ?array
    {
        $user = self::find($username);
        // Always run a hash comparison so a missing user and a wrong password
        // take the same amount of time.
        $hash = (string)($user['password_hash'] ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv');
        return password_verify($password, $hash) && $user !== null ? $user : null;
    }

    public static function changePassword(string $username, string $old, string $new): bool
    {
        $data = self::load();
        foreach ($data['users'] as $i => $user) {
            if (strcasecmp((string)($user['username'] ?? ''), $username) !== 0) {
                continue;
            }
            if (!password_verify($old, (string)($user['password_hash'] ?? ''))) {
                return false;
            }
            $data['users'][$i]['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
            self::save($data);
            return true;
        }
        return false;
    }
}
