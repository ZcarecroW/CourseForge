<?php
declare(strict_types=1);

namespace CourseForge\Domain;

use CourseForge\Ai\Provider\Providers;
use CourseForge\Support\Config;
use CourseForge\Support\Db;
use CourseForge\Support\HttpException;

/**
 * Reusable configurations: AI accounts, BookStack instances, model choices,
 * language, concurrency and per-profile prompt overrides.
 *
 * Credentials live in the `data` JSON blob and never leave the server: every
 * profile that goes to the browser is redacted, and an empty secret coming back
 * means "keep the stored one".
 */
final class Profiles
{
    /** Fields stripped before a profile is sent to the browser. */
    private const SECRETS = ['ai' => 'api_key', 'bookstack' => 'token_secret'];

    private const MODEL_SLOTS = ['overview', 'page'];

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        $slots = [];
        foreach (self::MODEL_SLOTS as $slot) {
            $slots[$slot] = ['ai_id' => '', 'model' => '', 'temperature' => 0.7, 'max_tokens' => 0];
        }
        return [
            'bookstack' => [],
            'ai' => [],
            'models' => $slots,
            'concurrency' => Config::int('app.default_concurrency', 2),
            'language' => Config::str('app.default_language', 'English'),
            'prompts' => new \stdClass(),
        ];
    }

    /**
     * One account's profiles, or every profile on the installation.
     *
     * Secrets are redacted here as they are everywhere else, so an
     * administrator listing all of them learns that an account exists and what
     * it points at - never a key.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function all(?string $username): array
    {
        $rows = $username === null
            ? Db::rows('SELECT * FROM profiles ORDER BY username COLLATE NOCASE, name COLLATE NOCASE')
            : Db::rows('SELECT * FROM profiles WHERE username = ? ORDER BY name COLLATE NOCASE', [$username]);

        return array_map(static function (array $row): array {
            $profile = self::redact(self::hydrate($row));
            $profile['owner'] = (string)$row['username'];
            return $profile;
        }, $rows);
    }

    /** @return array<string,mixed>|null */
    public static function find(string $username, int $id): ?array
    {
        $row = Db::row('SELECT * FROM profiles WHERE username = ? AND id = ?', [$username, $id]);
        return $row === null ? null : self::hydrate($row);
    }

    /** @return array<string,mixed> */
    public static function require(string $username, int $id): array
    {
        return self::find($username, $id) ?? throw HttpException::notFound('Profile not found.');
    }

    /** The raw payload including credentials – server side only. @return array<string,mixed> */
    public static function data(string $username, int $id): array
    {
        return self::require($username, $id)['data'];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public static function create(string $username, string $name, array $data): array
    {
        $now = time();
        Db::run('INSERT INTO profiles (username, name, data, created_at, updated_at) VALUES (?,?,?,?,?)',
            [$username, $name, self::encode(self::normalise($data)), $now, $now]);
        return self::require($username, Db::lastId());
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public static function update(string $username, int $id, string $name, array $data): array
    {
        self::require($username, $id);
        $data = self::normalise(self::mergeSecrets($username, $id, $data));
        Db::run('UPDATE profiles SET name = ?, data = ?, updated_at = ? WHERE username = ? AND id = ?',
            [$name, self::encode($data), time(), $username, $id]);
        return self::require($username, $id);
    }

    public static function delete(string $username, int $id): void
    {
        self::require($username, $id);
        Db::run('DELETE FROM profiles WHERE username = ? AND id = ?', [$username, $id]);
        // Projects keep working: profile_id simply becomes a dangling reference,
        // which the UI reports as "no profile" instead of silently deleting work.
        Db::run('UPDATE projects SET profile_id = NULL WHERE username = ? AND profile_id = ?', [$username, $id]);
    }

    /**
     * Strips credentials and adds a `<field>_set` flag so the UI can show
     * "stored" instead of an empty box.
     *
     * @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    public static function redact(array $profile): array
    {
        foreach (self::SECRETS as $group => $field) {
            foreach ((array)($profile['data'][$group] ?? []) as $i => $entry) {
                $profile['data'][$group][$i][$field] = '';
                $profile['data'][$group][$i][$field . '_set'] = trim((string)($entry[$field] ?? '')) !== '';
            }
        }
        return $profile;
    }

    /* ----------------------------------------------------------- internals */

    /**
     * An empty incoming secret means "keep the stored one", matched by entry id.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function mergeSecrets(string $username, int $id, array $data): array
    {
        $stored = self::find($username, $id)['data'] ?? [];

        foreach (self::SECRETS as $group => $field) {
            $previous = [];
            foreach ((array)($stored[$group] ?? []) as $entry) {
                $previous[(string)($entry['id'] ?? '')] = (string)($entry[$field] ?? '');
            }
            foreach ((array)($data[$group] ?? []) as $i => $entry) {
                unset($data[$group][$i][$field . '_set']); // never persist the UI flag
                if (trim((string)($entry[$field] ?? '')) === '') {
                    $data[$group][$i][$field] = $previous[(string)($entry['id'] ?? '')] ?? '';
                }
            }
        }
        return $data;
    }

    /**
     * Explicit shaping instead of a recursive merge: unknown keys are dropped,
     * every known key gets the right type, and a removed account really is gone.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function normalise(array $data): array
    {
        $defaults = self::defaults();

        $bookstack = [];
        foreach ((array)($data['bookstack'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $bookstack[] = [
                'id' => (string)($entry['id'] ?? ''),
                'name' => (string)($entry['name'] ?? 'BookStack'),
                'base_url' => rtrim(trim((string)($entry['base_url'] ?? '')), '/'),
                'token_id' => trim((string)($entry['token_id'] ?? '')),
                'token_secret' => (string)($entry['token_secret'] ?? ''),
            ];
        }

        // An account written before 3.2 carries no `kind`; kindOf() reads it off
        // the base URL instead, so an upgraded profile keeps working untouched.
        $ai = [];
        foreach ((array)($data['ai'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $ai[] = [
                'id' => (string)($entry['id'] ?? ''),
                'name' => (string)($entry['name'] ?? 'AI account'),
                'kind' => Providers::kindOf($entry),
                'base_url' => rtrim(trim((string)($entry['base_url'] ?? '')), '/'),
                'api_key' => (string)($entry['api_key'] ?? ''),
                'organization' => trim((string)($entry['organization'] ?? '')),
                'cli_path' => trim((string)($entry['cli_path'] ?? '')),
                'site_url' => trim((string)($entry['site_url'] ?? '')),
                'site_name' => trim((string)($entry['site_name'] ?? '')),
            ];
        }

        $models = [];
        foreach (self::MODEL_SLOTS as $slot) {
            $incoming = (array)($data['models'][$slot] ?? []);
            $models[$slot] = [
                'ai_id' => (string)($incoming['ai_id'] ?? ''),
                'model' => trim((string)($incoming['model'] ?? '')),
                'temperature' => max(0.0, min(2.0, (float)($incoming['temperature'] ?? 0.7))),
                'max_tokens' => max(0, (int)($incoming['max_tokens'] ?? 0)),
            ];
        }

        // Only string overrides count. An intentionally empty override is kept –
        // the UI documents it as "send nothing for this slot".
        $known = array_keys(Config::promptSlots());
        $prompts = [];
        foreach ((array)($data['prompts'] ?? []) as $key => $value) {
            if (is_string($value) && in_array((string)$key, $known, true)) {
                $prompts[(string)$key] = $value;
            }
        }

        return [
            'bookstack' => $bookstack,
            'ai' => $ai,
            'models' => $models,
            'concurrency' => max(1, min(12, (int)($data['concurrency'] ?? $defaults['concurrency']))),
            'language' => trim((string)($data['language'] ?? $defaults['language'])) ?: (string)$defaults['language'],
            'prompts' => $prompts === [] ? new \stdClass() : $prompts,
        ];
    }

    /** @param array<string,mixed> $data */
    private static function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function hydrate(array $row): array
    {
        $decoded = json_decode((string)$row['data'], true);
        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'data' => self::normalise(is_array($decoded) ? $decoded : []),
            'created_at' => (int)$row['created_at'],
            'updated_at' => (int)$row['updated_at'],
        ];
    }
}
