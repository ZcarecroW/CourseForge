<?php
declare(strict_types=1);

namespace CourseForge\Domain;

use CourseForge\Support\Config;

/**
 * Content details: what a generated page is made of.
 *
 * Two kinds of setting, one inheritance rule:
 *
 *   features  tri-state   -1 = off, 0 = inherit, 1 = on
 *   params    nullable    null / '' = inherit, anything else = own value
 *
 * The chain is  config default → course → chapter → page,  and the value that
 * wins is the one closest to the page. Only deviations are stored, so a course
 * with nothing overridden carries an empty settings object.
 */
final class Details
{
    public const INHERIT = 0;
    public const ON = 1;
    public const OFF = -1;

    /** @var array{features:array<string,array<string,mixed>>,params:array<string,array<string,mixed>>}|null */
    private static ?array $catalogue = null;

    /**
     * The catalogue from config.json, normalised and ordered.
     *
     * @return array{features:array<string,array<string,mixed>>,params:array<string,array<string,mixed>>}
     */
    public static function catalogue(): array
    {
        if (self::$catalogue !== null) {
            return self::$catalogue;
        }

        $features = [];
        foreach ((array)Config::get('details.features', []) as $key => $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $features[(string)$key] = [
                'key' => (string)$key,
                'order' => (int)($spec['order'] ?? 999),
                'icon' => (string)($spec['icon'] ?? 'check'),
                'label' => (string)($spec['label'] ?? $key),
                'description' => (string)($spec['description'] ?? ''),
                'default' => filter_var($spec['default'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }
        uasort($features, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);

        $params = [];
        foreach ((array)Config::get('details.params', []) as $key => $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $type = (string)($spec['type'] ?? 'int') === 'text' ? 'text' : 'int';
            $params[(string)$key] = [
                'key' => (string)$key,
                'order' => (int)($spec['order'] ?? 999),
                'label' => (string)($spec['label'] ?? $key),
                'description' => (string)($spec['description'] ?? ''),
                'unit' => (string)($spec['unit'] ?? ''),
                'type' => $type,
                'min' => (int)($spec['min'] ?? 0),
                'max' => (int)($spec['max'] ?? 1000000),
                'step' => max(1, (int)($spec['step'] ?? 1)),
                'default' => $type === 'text' ? (string)($spec['default'] ?? '') : (int)($spec['default'] ?? 0),
            ];
        }
        uasort($params, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);

        return self::$catalogue = ['features' => $features, 'params' => $params];
    }

    /** @return string[] */
    public static function featureKeys(): array
    {
        return array_keys(self::catalogue()['features']);
    }

    /**
     * The bottom of the chain: whatever config.json declares.
     *
     * @return array{features:array<string,bool>,params:array<string,int|string>}
     */
    public static function baseline(): array
    {
        $catalogue = self::catalogue();
        return [
            'features' => array_map(static fn(array $f): bool => (bool)$f['default'], $catalogue['features']),
            'params' => array_map(static fn(array $p): int|string => $p['default'], $catalogue['params']),
        ];
    }

    /**
     * Reads a stored settings column into the canonical shape, dropping
     * anything the catalogue no longer knows about.
     *
     * @return array{features:array<string,int>,params:array<string,int|string>}
     */
    public static function decode(?string $json): array
    {
        $raw = json_decode((string)($json ?? ''), true);
        return self::normalise(is_array($raw) ? $raw : []);
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{features:array<string,int>,params:array<string,int|string>}
     */
    public static function normalise(array $raw): array
    {
        $catalogue = self::catalogue();

        $features = [];
        foreach ((array)($raw['features'] ?? []) as $key => $state) {
            if (!isset($catalogue['features'][$key])) {
                continue;
            }
            $state = (int)$state;
            if ($state !== self::INHERIT) {
                $features[(string)$key] = $state > 0 ? self::ON : self::OFF;
            }
        }

        $params = [];
        foreach ((array)($raw['params'] ?? []) as $key => $value) {
            if (!isset($catalogue['params'][$key]) || $value === null) {
                continue;
            }
            $spec = $catalogue['params'][$key];
            if ($spec['type'] === 'text') {
                $text = trim((string)$value);
                if ($text !== '') {
                    $params[(string)$key] = $text;
                }
                continue;
            }
            if (!is_numeric($value)) {
                continue;
            }
            $params[(string)$key] = max((int)$spec['min'], min((int)$spec['max'], (int)$value));
        }

        return ['features' => $features, 'params' => $params];
    }

    /** @param array{features:array<string,int>,params:array<string,int|string>} $settings */
    public static function encode(array $settings): string
    {
        $clean = array_filter([
            'features' => $settings['features'] ?? [],
            'params' => $settings['params'] ?? [],
        ], static fn(array $group): bool => $group !== []);

        return json_encode($clean === [] ? new \stdClass() : $clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * Merges an incoming partial patch into stored settings.
     * A feature sent as 0, or a param sent as null / '', means "inherit again".
     *
     * @param array{features:array<string,int>,params:array<string,int|string>} $current
     * @param array<string,mixed> $features
     * @param array<string,mixed> $params
     * @return array{features:array<string,int>,params:array<string,int|string>}
     */
    public static function patch(array $current, array $features, array $params): array
    {
        $catalogue = self::catalogue();

        foreach ($features as $key => $state) {
            if (!isset($catalogue['features'][$key])) {
                continue;
            }
            $state = (int)$state;
            if ($state === self::INHERIT) {
                unset($current['features'][$key]);
            } else {
                $current['features'][$key] = $state > 0 ? self::ON : self::OFF;
            }
        }

        foreach ($params as $key => $value) {
            if (!isset($catalogue['params'][$key])) {
                continue;
            }
            if ($value === null || (is_string($value) && trim($value) === '')) {
                unset($current['params'][$key]);
                continue;
            }
            $spec = $catalogue['params'][$key];
            if ($spec['type'] === 'text') {
                $current['params'][$key] = trim((string)$value);
                continue;
            }
            if (is_numeric($value)) {
                $current['params'][$key] = max((int)$spec['min'], min((int)$spec['max'], (int)$value));
            }
        }

        return self::normalise($current);
    }

    /**
     * Resolves the whole chain. Pass the ancestors in order, closest last.
     *
     * @param array{features:array<string,int>,params:array<string,int|string>} ...$chain
     * @return array{features:array<string,bool>,params:array<string,int|string>}
     */
    public static function resolve(array ...$chain): array
    {
        $resolved = self::baseline();

        foreach ($chain as $level) {
            foreach ((array)($level['features'] ?? []) as $key => $state) {
                if ((int)$state !== self::INHERIT && array_key_exists($key, $resolved['features'])) {
                    $resolved['features'][$key] = (int)$state === self::ON;
                }
            }
            foreach ((array)($level['params'] ?? []) as $key => $value) {
                if ($value !== null && $value !== '' && array_key_exists($key, $resolved['params'])) {
                    $resolved['params'][$key] = $value;
                }
            }
        }

        return $resolved;
    }

    /**
     * The three views one editor row needs: what this level stores, what it
     * would get without those overrides, and what actually applies.
     *
     * @param array{features:array<string,int>,params:array<string,int|string>} $own
     * @param array{features:array<string,int>,params:array<string,int|string>} ...$ancestors
     * @return array{
     *   own:array{features:array<string,int>,params:array<string,int|string>},
     *   inherited:array{features:array<string,bool>,params:array<string,int|string>},
     *   effective:array{features:array<string,bool>,params:array<string,int|string>}
     * }
     */
    public static function describe(array $own, array ...$ancestors): array
    {
        return [
            'own' => $own,
            'inherited' => self::resolve(...$ancestors),
            'effective' => self::resolve(...$ancestors, ...[$own]),
        ];
    }

    /** Test seam – forget the cached catalogue. */
    public static function flush(): void
    {
        self::$catalogue = null;
    }
}
