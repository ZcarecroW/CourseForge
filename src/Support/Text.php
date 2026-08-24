<?php
declare(strict_types=1);

namespace CourseForge\Support;

/** Small, dependency-free string helpers shared across the domain. */
final class Text
{
    /** Collapses runs of whitespace and trims – used for titles and tag names. */
    public static function tidy(string $value): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Comparison key for title matching: lower case, punctuation removed,
     * whitespace collapsed. "Reactive State (ref & reactive)" and
     * "reactive state ref reactive" therefore compare equal.
     */
    public static function key(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = (string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
        return trim((string)preg_replace('/\s+/u', ' ', $value));
    }

    /** 0.0 – 1.0 similarity, used as the last resort when matching link targets. */
    public static function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        // similar_text is O(n^2); the inputs here are titles, so that is fine.
        similar_text($a, $b, $percent);
        return $percent / 100;
    }

    /** Word count that behaves on non-ASCII text (str_word_count does not). */
    public static function words(string $value): int
    {
        return preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\'’\-]*/u', $value) ?: 0;
    }

    /** One-line excerpt for error messages. */
    public static function snippet(string $value, int $length = 300): string
    {
        $value = trim((string)preg_replace('/\s+/u', ' ', $value));
        return $value === '' ? '(empty body)' : mb_substr($value, 0, $length);
    }

    /** Stable fingerprint over an ordered list of parts. */
    public static function hash(string ...$parts): string
    {
        return sha1(implode("\x1f", $parts));
    }

    /**
     * Splits "Tag1, Tag2; Tag3" into a clean, case-insensitively unique list.
     * Used for tag markers, tag pools and any other comma separated field.
     *
     * @return string[]
     */
    public static function splitList(string $list): array
    {
        $out = [];
        foreach (preg_split('/[,;\n]+/u', $list) ?: [] as $part) {
            $name = self::tidy((string)$part);
            $name = trim($name, " \t\"'`*_#[]()");
            if ($name === '') {
                continue;
            }
            $out = self::mergeUnique($out, [$name]);
        }
        return $out;
    }

    /**
     * Union of two name lists, first spelling wins, comparison is case-insensitive.
     *
     * @param string[] $a
     * @param string[] $b
     * @return string[]
     */
    public static function mergeUnique(array $a, array $b): array
    {
        $seen = [];
        foreach ([...$a, ...$b] as $name) {
            $seen[mb_strtolower((string)$name)] ??= (string)$name;
        }
        return array_values($seen);
    }
}
