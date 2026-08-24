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
     * A file-system path, spelled the way the machine spells it, for a person
     * to read.
     *
     * CF_ROOT comes from dirname(__DIR__), so on Windows it arrives in Windows
     * spelling - "G:\Coding\CourseForge" - while everything built on top of it
     * appends "/data" or "/tools/cron.php" the way the whole codebase writes
     * paths. What comes out is the mongrel "G:\Coding\CourseForge/data/config.json":
     * perfectly good for fopen(), and wrong on a screen. This puts one separator
     * through the whole string, so an administrator can read the path, recognise
     * it, and paste it into a file manager.
     *
     * FOR DISPLAY ONLY. Nothing that is opened, compared or written may be built
     * from the result. The updater decides which of its own files it is allowed
     * to replace by comparing realpath() output, and realpath() answers in the
     * platform's spelling of the moment; a path that had been through here would
     * compare unequal and the guard would fall the wrong way - which, in a
     * routine that deletes and overwrites program files, is not a cosmetic
     * mistake. Put a path through this on its way into an API response or a
     * printed line, and never on its way into a file operation.
     */
    public static function path(string $path): string
    {
        if ($path === '') {
            return '';
        }

        $sep = DIRECTORY_SEPARATOR;

        // Only ever rewritten on a platform whose separator is the backslash.
        // On Unix a backslash is an ordinary, legal character in a file name,
        // so touching one there would rename the file this is describing.
        if ($sep === '\\') {
            $path = str_replace('/', $sep, $path);
        }

        // A leading "\\" is a UNC host and has to survive the collapse below.
        $prefix = '';
        if ($sep === '\\' && str_starts_with($path, '\\\\')) {
            $prefix = '\\\\';
            $path = substr($path, 2);
        }

        while (str_contains($path, $sep . $sep)) {
            $path = str_replace($sep . $sep, $sep, $path);
        }

        return $prefix . $path;
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
