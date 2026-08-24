<?php
declare(strict_types=1);

namespace CourseForge\Support;

use RuntimeException;

/**
 * Read-only access to data/config.json.
 *
 * The file carries four things: application settings, the security policy, the
 * catalogue of content details (features + parameters) and the prompt library.
 * It is read once per request and never written.
 */
final class Config
{
    /** @var array<string,mixed>|null */
    private static ?array $data = null;

    public static function file(): string
    {
        return CF_DATA . '/config.json';
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }
        $raw = @file_get_contents(self::file());
        if ($raw === false) {
            throw new RuntimeException('config.json is missing (expected at ' . self::file() . ').');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('config.json is not valid JSON: ' . json_last_error_msg());
        }
        return self::$data = $decoded;
    }

    /** Dot-path lookup: Config::get('app.name', 'CourseForge'). */
    public static function get(string $path, mixed $default = null): mixed
    {
        $node = self::all();
        foreach (explode('.', $path) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return $default;
            }
            $node = $node[$key];
        }
        return $node;
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
     * Prompt values only – the base layer that profile overrides sit on top of.
     *
     * @return array<string,string>
     */
    public static function defaultPrompts(): array
    {
        return array_map(static fn(array $slot): string => $slot['value'], self::promptSlots());
    }

    /** Test seam: forget the cached file (used by the self-test tooling). */
    public static function flush(): void
    {
        self::$data = null;
    }
}
