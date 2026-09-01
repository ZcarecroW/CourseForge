<?php
declare(strict_types=1);

namespace CourseForge\Domain;

use CourseForge\Ai\Provider\PresetSpec;
use CourseForge\Ai\Provider\Probe;
use CourseForge\Ai\Provider\Providers;
use CourseForge\Support\Config;
use CourseForge\Support\Db;
use CourseForge\Support\HttpException;
use CourseForge\Support\Runtime;
use Throwable;

/**
 * Reusable configurations: AI accounts, BookStack instances, model choices,
 * language, concurrency and per-profile prompt overrides.
 *
 * Credentials live in the `data` JSON blob and never leave the server: every
 * profile that goes to the browser is redacted, and an empty secret coming back
 * means "keep the stored one".
 *
 * An AI account also carries what CourseForge has learned about the endpoint
 * behind it - which preset it is on and what the capability probe found - so
 * that a question already answered against a live server is answered from the
 * row the next time rather than asked again on every render.
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
            'typography' => Config::bool('app.typography', true),
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

    /** The raw payload including credentials - server side only. @return array<string,mixed> */
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
        $data = self::normalise(self::mergeStored($username, $id, $data));
        Db::run('UPDATE profiles SET name = ?, data = ?, updated_at = ? WHERE username = ? AND id = ?',
            [$name, self::encode($data), time(), $username, $id]);
        return self::require($username, $id);
    }

    public static function delete(string $username, int $id): void
    {
        self::require($username, $id);
        Db::run('DELETE FROM profiles WHERE username = ? AND id = ?', [$username, $id]);
        // Projects keep working: profile_id becomes a dangling reference,
        // which the UI reports as "no profile" instead of silently deleting work.
        Db::run('UPDATE projects SET profile_id = NULL WHERE username = ? AND profile_id = ?', [$username, $id]);
    }

    /**
     * Strips credentials and adds a `<field>_set` flag so the UI can show
     * "stored" instead of an empty box.
     *
     * The probe result stays - it is what the queue badge is drawn from - but
     * the fingerprint tying it to a key does not. That field is a hash of a live
     * credential, and a browser is the one reader the credential was redacted
     * for; handing it a hash it could check candidates against would undo the
     * redaction it is standing next to.
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
        foreach ((array)($profile['data']['ai'] ?? []) as $i => $entry) {
            if (is_array($entry['batch_probe'] ?? null)) {
                $profile['data']['ai'][$i]['batch_probe']['for'] = '';
            }
        }
        return $profile;
    }

    /* --------------------------------------------------------- batch probe */

    /**
     * Remembers what the capability probe concluded about one AI account.
     *
     * The probe is four requests against somebody else's server, so it is taken
     * when an account is saved or checked and read back from this row every
     * other time the question comes up. Without somewhere to put the answer it
     * is retaken on every page render, which is the one thing the probe's own
     * design forbids.
     *
     * Stamping the credentials onto the row happens here and in storeProbeFor()
     * below, and nowhere else: a probe result is only ever true of the endpoint
     * and the key it was taken against, and this is the layer that holds both.
     *
     * `updated_at` deliberately does not move. The account did not change; what
     * CourseForge knows about it did, and a profile list that reshuffled itself
     * because a queue was checked would be reporting an edit nobody made.
     *
     * @param array<string,mixed> $probe as returned by Probe::run()
     * @return array<string,mixed> the profile, reshaped and read back
     */
    public static function storeProbe(string $username, int $id, string $aiId, array $probe): array
    {
        $data = self::require($username, $id)['data'];
        $written = false;

        foreach ($data['ai'] as $i => $account) {
            if ((string)$account['id'] !== $aiId) {
                continue;
            }
            $probe['for'] = Probe::fingerprint((string)$account['base_url'], (string)$account['api_key']);
            $data['ai'][$i]['batch_probe'] = $probe;
            $written = true;
        }

        if ($written) {
            Db::run(
                'UPDATE profiles SET data = ? WHERE username = ? AND id = ?',
                [self::encode(self::normalise($data)), $username, $id]
            );
        }
        return self::require($username, $id);
    }

    /**
     * Writes one probe result onto every account that shares an endpoint and a
     * key, wherever in the installation it is stored.
     *
     * This is the self-healing path. A real batch submission that comes back
     * 404 or 405 disproves whatever a probe concluded earlier, and it disproves
     * it for every profile holding the same credentials rather than only for
     * the one that happened to be running. The fingerprint is the whole address
     * used here because the caller has a provider in hand rather than a profile
     * row, and "this endpoint with this key" is exactly the scope of what a
     * failed submission proved.
     *
     * Nothing here is allowed to raise. The caller is on its way to telling the
     * user why their run cannot be queued, and replacing that message with a
     * database error would lose the only part of the failure they can act on.
     *
     * @param array<string,mixed> $probe as returned by Probe::disprovedBySubmit()
     * @return int how many accounts were told
     */
    public static function storeProbeFor(string $fingerprint, array $probe): int
    {
        if ($fingerprint === '') {
            return 0;
        }
        $probe['for'] = $fingerprint;
        $touched = 0;

        try {
            foreach (Db::rows('SELECT id, data FROM profiles') as $row) {
                $decoded = json_decode((string)$row['data'], true);
                $data = self::normalise(is_array($decoded) ? $decoded : []);
                $hit = false;

                foreach ($data['ai'] as $i => $account) {
                    $mine = Probe::fingerprint((string)$account['base_url'], (string)$account['api_key']);
                    if (!hash_equals($fingerprint, $mine)) {
                        continue;
                    }
                    $data['ai'][$i]['batch_probe'] = $probe;
                    $hit = true;
                    $touched++;
                }

                if ($hit) {
                    Db::run('UPDATE profiles SET data = ? WHERE id = ?', [self::encode($data), (int)$row['id']]);
                }
            }
        } catch (Throwable $e) {
            Runtime::log('profiles.store_probe', $e);
        }
        return $touched;
    }

    /* ----------------------------------------------------------- internals */

    /**
     * The fields a browser is not the author of, carried across a save.
     *
     * Two kinds of them, both matched by entry id. An empty incoming secret
     * means "keep the stored one", which is how a redacted key survives a round
     * trip. And a probe result is taken from the stored row whatever arrived
     * with the request, because it is something the server measured rather than
     * something a person typed: the browser is shown it so the queue badge has
     * something to draw, and is never believed about it.
     *
     * Nothing here decides whether a carried-forward probe result is still
     * true. That is normalise()'s job - it checks the fingerprint against the
     * base URL and key this very save is writing, so an edit to either drops
     * the result on its way past.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function mergeStored(string $username, int $id, array $data): array
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

        $probes = [];
        foreach ((array)($stored['ai'] ?? []) as $entry) {
            $probes[(string)($entry['id'] ?? '')] = $entry['batch_probe'] ?? null;
        }
        foreach ((array)($data['ai'] ?? []) as $i => $entry) {
            if (is_array($entry)) {
                $data['ai'][$i]['batch_probe'] = $probes[(string)($entry['id'] ?? '')] ?? null;
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
            $baseUrl = rtrim(trim((string)($entry['base_url'] ?? '')), '/');
            $apiKey = (string)($entry['api_key'] ?? '');
            $preset = Providers::presetKeyOf($entry);
            $inline = $entry['preset'] ?? null;

            $ai[] = [
                'id' => (string)($entry['id'] ?? ''),
                'name' => (string)($entry['name'] ?? 'AI account'),
                'kind' => Providers::kindOf($entry),
                'preset_key' => $preset,
                // A custom endpoint may carry a whole preset row inline, which is
                // how a gateway with no table entry remembers the shape somebody
                // discovered for it. PresetSpec does the shaping, so the one
                // description of a preset row stays in the one class that owns it.
                'preset' => is_array($inline) && $inline !== []
                    ? PresetSpec::fromArray($preset, $inline)->toArray()
                    : null,
                'base_url' => $baseUrl,
                'api_key' => $apiKey,
                'organization' => trim((string)($entry['organization'] ?? '')),
                'cli_path' => trim((string)($entry['cli_path'] ?? '')),
                'site_url' => trim((string)($entry['site_url'] ?? '')),
                'site_name' => trim((string)($entry['site_name'] ?? '')),
                // What the capability probe last concluded, kept only while it
                // still belongs to this base URL and this key. An edit to either
                // drops it, which is how "re-probe when the endpoint changes"
                // happens without anything having to watch for the edit - and
                // the same check is why a batch_probe a browser invented and
                // posted back cannot survive a save.
                'batch_probe' => Probe::stored(
                    $entry['batch_probe'] ?? null,
                    Probe::fingerprint($baseUrl, $apiKey),
                ),
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

        // Only string overrides count. An intentionally empty override is kept -
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
            // Absent means "whatever the installation says", which is what lets
            // an administrator change the answer for every profile that has
            // never disagreed with it - the same arrangement language and
            // concurrency have had since 4.0.
            'typography' => filter_var(
                $data['typography'] ?? $defaults['typography'],
                FILTER_VALIDATE_BOOLEAN
            ),
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
