<?php
declare(strict_types=1);

namespace CourseForge\Support;

use RuntimeException;

/**
 * The configuration, in two layers.
 *
 *   config/defaults.json   ships with the release. Application settings, the
 *                          security policy, the catalogue of content details
 *                          and the whole prompt library. Never written to.
 *   data/config.json       the overrides this installation has made, and only
 *                          those. Written from the admin screens.
 *
 * Splitting them is what makes an update safe: replacing the release directory
 * brings new defaults and new prompt slots along with it, and the things the
 * administrator changed are in a file the update never touches. It also means
 * "reset to default" is a delete rather than a copy of something remembered.
 *
 * `all()` is the merge of the two and is what the rest of the application
 * reads. It is cached for the request, and every write flushes the cache.
 */
final class Config
{
    /** @var array<string,mixed>|null */
    private static ?array $merged = null;

    /** @var array<string,mixed>|null */
    private static ?array $defaults = null;

    /** @var array<string,mixed>|null */
    private static ?array $overrides = null;

    /** Keys that hold a secret: never handed to anyone who is not an administrator. */
    public const SECRET_PATHS = ['app.cron_token', 'updates.github_token'];

    public static function defaultsFile(): string
    {
        return CF_ROOT . '/config/defaults.json';
    }

    public static function file(): string
    {
        return CF_DATA . '/config.json';
    }

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        if (self::$defaults !== null) {
            return self::$defaults;
        }
        $data = Json::read(self::defaultsFile());
        if ($data === null) {
            throw new RuntimeException('config/defaults.json is missing (expected at ' . self::defaultsFile() . ').');
        }
        return self::$defaults = $data;
    }

    /**
     * The overrides, as stored.
     *
     * A `data/config.json` written by CourseForge 3.x is a complete document
     * rather than a set of overrides. It is reduced to one the first time it is
     * read, so an upgraded installation keeps exactly the settings it had and
     * starts following the new defaults for everything it never changed.
     *
     * @return array<string,mixed>
     */
    public static function overrides(): array
    {
        if (self::$overrides !== null) {
            return self::$overrides;
        }
        $data = Json::read(self::file()) ?? [];

        if (isset($data['prompts']) || isset($data['details']) || isset($data['prompt_groups'])) {
            $data = Json::diff(self::defaults(), $data);
            unset($data['_comment'], $data['_note']);
            Json::write(self::file(), $data);
        }
        return self::$overrides = $data;
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        if (self::$merged !== null) {
            return self::$merged;
        }
        return self::$merged = Json::merge(self::defaults(), self::overrides());
    }

    /** Dot-path lookup: Config::get('app.name', 'CourseForge'). */
    public static function get(string $path, mixed $default = null): mixed
    {
        return self::dig(self::all(), $path, $default);
    }

    public static function int(string $path, int $default): int
    {
        $value = self::get($path, $default);
        return is_numeric($value) ? (int)$value : $default;
    }

    public static function str(string $path, string $default): string
    {
        $value = self::get($path, $default);
        return is_scalar($value) ? (string)$value : $default;
    }

    public static function bool(string $path, bool $default = false): bool
    {
        $value = self::get($path, $default);
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** @return string[] */
    public static function strings(string $path): array
    {
        $value = self::get($path, []);
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn(mixed $v): string => is_scalar($v) ? trim((string)$v) : '',
            $value
        ), static fn(string $v): bool => $v !== ''));
    }

    /* --------------------------------------------------------------- writing */

    /**
     * Stores one setting.
     *
     * A value equal to the default is removed from the override file rather
     * than written, so `data/config.json` stays a readable list of what this
     * installation actually decided.
     */
    public static function set(string $path, mixed $value): void
    {
        self::setMany([$path => $value]);
    }

    /**
     * Stores several settings in one write.
     *
     * @param array<string,mixed> $values dot-path => value
     */
    public static function setMany(array $values): void
    {
        $overrides = self::overrides();
        $defaults = self::defaults();

        foreach ($values as $path => $value) {
            $default = self::dig($defaults, (string)$path, null);
            $overrides = ($value === null || $value === $default)
                ? self::forget($overrides, (string)$path)
                : self::plant($overrides, (string)$path, $value);
        }

        Json::write(self::file(), $overrides);
        self::flush();
    }

    /** Drops an override so the shipped default applies again. */
    public static function reset(string $path): void
    {
        Json::write(self::file(), self::forget(self::overrides(), $path));
        self::flush();
    }

    /** True when this installation has changed the value at $path. */
    public static function isOverridden(string $path): bool
    {
        return self::dig(self::overrides(), $path, '__cf_absent__') !== '__cf_absent__';
    }

    /* ------------------------------------------------------- prompt library */

    /**
     * The prompt library, normalised and sorted.
     *
     * @return array<string,array{group:string,order:int,label:string,description:string,placeholders:string[],value:string}>
     */
    public static function promptSlots(): array
    {
        $slots = [];
        foreach ((array)self::get('prompts', []) as $key => $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $slots[(string)$key] = [
                'group' => (string)($slot['group'] ?? 'global'),
                'order' => (int)($slot['order'] ?? 999),
                'label' => (string)($slot['label'] ?? $key),
                'description' => (string)($slot['description'] ?? ''),
                'placeholders' => array_values(array_map('strval', (array)($slot['placeholders'] ?? []))),
                'value' => (string)($slot['value'] ?? ''),
            ];
        }
        uasort($slots, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);
        return $slots;
    }

    /** @return array<string,array{order:int,label:string,description:string}> */
    public static function promptGroups(): array
    {
        $groups = [];
        foreach ((array)self::get('prompt_groups', []) as $key => $group) {
            if (!is_array($group)) {
                continue;
            }
            $groups[(string)$key] = [
                'order' => (int)($group['order'] ?? 999),
                'label' => (string)($group['label'] ?? $key),
                'description' => (string)($group['description'] ?? ''),
            ];
        }
        uasort($groups, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);
        return $groups;
    }

    /**
     * Prompt values only - the base layer that profile overrides sit on top of.
     *
     * @return array<string,string>
     */
    public static function defaultPrompts(): array
    {
        return array_map(static fn(array $slot): string => $slot['value'], self::promptSlots());
    }

    /** Forget the cached documents. */
    public static function flush(): void
    {
        self::$merged = null;
        self::$defaults = null;
        self::$overrides = null;
    }

    /* --------------------------------------------------------------- helpers */

    /** @param array<string,mixed> $doc */
    private static function dig(array $doc, string $path, mixed $default): mixed
    {
        $node = $doc;
        foreach (explode('.', $path) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return $default;
            }
            $node = $node[$key];
        }
        return $node;
    }

    /**
     * @param array<string,mixed> $doc
     * @return array<string,mixed>
     */
    private static function plant(array $doc, string $path, mixed $value): array
    {
        $keys = explode('.', $path);
        $node = &$doc;
        foreach ($keys as $i => $key) {
            if ($i === count($keys) - 1) {
                $node[$key] = $value;
                break;
            }
            if (!isset($node[$key]) || !is_array($node[$key])) {
                $node[$key] = [];
            }
            $node = &$node[$key];
        }
        unset($node);
        return $doc;
    }

    /**
     * @param array<string,mixed> $doc
     * @return array<string,mixed>
     */
    private static function forget(array $doc, string $path): array
    {
        $keys = explode('.', $path);
        $last = (string)array_pop($keys);

        $node = &$doc;
        foreach ($keys as $key) {
            if (!isset($node[$key]) || !is_array($node[$key])) {
                unset($node);
                return $doc;
            }
            $node = &$node[$key];
        }
        unset($node[$last]);
        unset($node);

        // Prune the branches the unset has just emptied, so the override file
        // never grows a skeleton of keys that hold nothing.
        return self::prune($doc);
    }

    /**
     * @param array<string,mixed> $doc
     * @return array<string,mixed>
     */
    private static function prune(array $doc): array
    {
        foreach ($doc as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            // An empty array is a list as far as array_is_list is concerned, so
            // testing for a list first would leave behind exactly the skeleton
            // this method exists to remove: the branch whose last override has
            // just been deleted.
            if ($value === []) {
                unset($doc[$key]);
                continue;
            }
            if (array_is_list($value)) {
                continue;
            }
            $doc[$key] = self::prune($value);
            if ($doc[$key] === []) {
                unset($doc[$key]);
            }
        }
        return $doc;
    }
}
