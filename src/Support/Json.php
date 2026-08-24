<?php
declare(strict_types=1);

namespace CourseForge\Support;

use RuntimeException;

/**
 * Reading and writing the two JSON documents CourseForge keeps on disk.
 *
 * Writing is atomic: the new content goes to a temporary file in the same
 * directory and is then moved over the old one, so a process that dies half way
 * through a write leaves the previous version intact rather than a truncated
 * file. On Windows `rename()` refuses to overwrite, which is why the target is
 * unlinked first there and only there - on POSIX the unlink would open exactly
 * the window the atomic move exists to close.
 */
final class Json
{
    /**
     * @return array<string,mixed>|null null when the file does not exist
     * @throws RuntimeException when it exists but is not valid JSON
     */
    public static function read(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException('Cannot read ' . basename($file) . ' (' . $file . ').');
        }
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(basename($file) . ' is not valid JSON: ' . json_last_error_msg());
        }
        /** @var array<string,mixed> $decoded */
        return $decoded;
    }

    /** @param array<string,mixed> $data */
    public static function write(string $file, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Cannot encode ' . basename($file) . ': ' . json_last_error_msg());
        }

        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create the directory ' . $dir . '.');
        }

        $tmp = $dir . '/.' . basename($file) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException($dir . ' is not writable (needed to save ' . basename($file) . ').');
        }
        @chmod($tmp, 0664);

        if (DIRECTORY_SEPARATOR === '\\' && is_file($file)) {
            @unlink($file);
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot replace ' . $file . '.');
        }
    }

    /**
     * Recursive merge in which the overlay wins for every scalar and a list
     * replaces a list outright.
     *
     * Merging lists item by item is never what a settings file wants: an
     * override that names two allowed origins means those two, not those two
     * plus whatever shipped.
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $overlay
     * @return array<string,mixed>
     */
    public static function merge(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])
                && !array_is_list($value) && !array_is_list($base[$key])) {
                $base[$key] = self::merge($base[$key], $value);
                continue;
            }
            $base[$key] = $value;
        }
        return $base;
    }

    /**
     * Everything in $current that differs from $base, as a document of the same
     * shape. This is what turns a whole edited config file back into the small
     * set of overrides worth storing.
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $current
     * @return array<string,mixed>
     */
    public static function diff(array $base, array $current): array
    {
        $out = [];
        foreach ($current as $key => $value) {
            if (!array_key_exists($key, $base)) {
                $out[$key] = $value;
                continue;
            }
            $old = $base[$key];
            if (is_array($value) && is_array($old) && !array_is_list($value) && !array_is_list($old)) {
                $nested = self::diff($old, $value);
                if ($nested !== []) {
                    $out[$key] = $nested;
                }
                continue;
            }
            if ($old !== $value) {
                $out[$key] = $value;
            }
        }
        return $out;
    }
}
