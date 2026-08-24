<?php
declare(strict_types=1);

namespace CourseForge\Mcp;

use CourseForge\Support\HttpException;

/**
 * Reading a tool call's arguments, with the same discipline as the HTTP API.
 *
 * A model is a client like any other and gets things wrong in the same ways: a
 * number as a string, a missing required field, an array where a string was
 * expected. Every accessor either returns the promised type or throws, and the
 * message it throws is written for the model to read and correct - "course_id
 * is required and must be a whole number" tells it what to do next, where
 * "invalid argument" does not.
 */
final class Args
{
    /** @param array<string,mixed> $args */
    public function __construct(private readonly array $args)
    {
    }

    /** @param array<string,mixed> $args */
    public static function of(array $args): self
    {
        return new self($args);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->args) && $this->args[$key] !== null;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->args;
    }

    public function id(string $key = 'course_id'): int
    {
        $value = $this->args[$key] ?? null;
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1 && (int)$value > 0) {
            return (int)$value;
        }
        throw HttpException::unprocessable($key . ' is required and must be a positive whole number.');
    }

    public function optionalId(string $key): ?int
    {
        return $this->has($key) ? $this->id($key) : null;
    }

    public function str(string $key, string $default = ''): string
    {
        $value = $this->args[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        if (!is_scalar($value)) {
            throw HttpException::unprocessable($key . ' must be text.');
        }
        return trim((string)$value);
    }

    /** Whitespace preserved: Markdown, prompts and passwords all need it. */
    public function raw(string $key, string $default = ''): string
    {
        $value = $this->args[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        if (!is_scalar($value)) {
            throw HttpException::unprocessable($key . ' must be text.');
        }
        return (string)$value;
    }

    public function requiredStr(string $key): string
    {
        $value = $this->str($key);
        if ($value === '') {
            throw HttpException::unprocessable($key . ' is required.');
        }
        return $value;
    }

    public function requiredRaw(string $key): string
    {
        $value = $this->raw($key);
        if (trim($value) === '') {
            throw HttpException::unprocessable($key . ' is required and must not be empty.');
        }
        return $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        if (!$this->has($key)) {
            return $default;
        }
        return filter_var($this->args[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->args[$key] ?? null;
        return is_numeric($value) ? (int)$value : $default;
    }

    public function intOrNull(string $key): ?int
    {
        $value = $this->args[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw HttpException::unprocessable($key . ' must be a whole number.');
        }
        return (int)$value;
    }

    /** @param string[] $allowed */
    public function enum(string $key, array $allowed, string $default): string
    {
        $value = strtolower($this->str($key, $default));
        if ($value === '') {
            $value = $default;
        }
        if (!in_array($value, $allowed, true)) {
            throw HttpException::unprocessable(
                $key . ' must be one of: ' . implode(', ', $allowed) . '.'
            );
        }
        return $value;
    }

    /** @return array<int,int> */
    public function ids(string $key): array
    {
        $value = $this->args[$key] ?? [];
        if (!is_array($value)) {
            throw HttpException::unprocessable($key . ' must be a list of whole numbers.');
        }
        $out = [];
        foreach ($value as $item) {
            if (!is_numeric($item) || (int)$item <= 0) {
                throw HttpException::unprocessable($key . ' must hold positive whole numbers only.');
            }
            $out[] = (int)$item;
        }
        return array_values(array_unique($out));
    }

    /** @return array<string,mixed> */
    public function object(string $key): array
    {
        $value = $this->args[$key] ?? [];
        if ($value === null) {
            return [];
        }
        if (!is_array($value)) {
            throw HttpException::unprocessable($key . ' must be an object.');
        }
        return $value;
    }

    /** @return array<int,string> */
    public function strings(string $key): array
    {
        $value = $this->args[$key] ?? [];
        if ($value === null) {
            return [];
        }
        if (!is_array($value)) {
            throw HttpException::unprocessable($key . ' must be a list of strings.');
        }
        return array_values(array_filter(array_map(
            static fn(mixed $v): string => is_scalar($v) ? trim((string)$v) : '',
            $value
        ), static fn(string $v): bool => $v !== ''));
    }
}
