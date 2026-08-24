<?php
declare(strict_types=1);

namespace CourseForge\Mcp;

/**
 * Short ways of writing the JSON Schema fragments a tool's arguments need.
 *
 * Fifty tools written out longhand would be a wall of `['type' => 'string',
 * 'description' => ...]`, and the descriptions - which are the part a model
 * actually reads - would be lost in it. These helpers exist so that the
 * declaration of a tool stays one readable line per argument.
 */
final class Schema
{
    /** @return array<string,mixed> */
    public static function string(string $description, ?string $example = null): array
    {
        $schema = ['type' => 'string', 'description' => $description];
        if ($example !== null) {
            $schema['examples'] = [$example];
        }
        return $schema;
    }

    /** @return array<string,mixed> */
    public static function text(string $description): array
    {
        return ['type' => 'string', 'description' => $description];
    }

    /** @return array<string,mixed> */
    public static function int(string $description, ?int $min = null, ?int $max = null): array
    {
        $schema = ['type' => 'integer', 'description' => $description];
        if ($min !== null) {
            $schema['minimum'] = $min;
        }
        if ($max !== null) {
            $schema['maximum'] = $max;
        }
        return $schema;
    }

    /** @return array<string,mixed> */
    public static function bool(string $description): array
    {
        return ['type' => 'boolean', 'description' => $description];
    }

    /**
     * @param string[] $values
     * @return array<string,mixed>
     */
    public static function enum(string $description, array $values): array
    {
        return ['type' => 'string', 'description' => $description, 'enum' => array_values($values)];
    }

    /** @return array<string,mixed> */
    public static function ints(string $description): array
    {
        return ['type' => 'array', 'description' => $description, 'items' => ['type' => 'integer']];
    }

    /** @return array<string,mixed> */
    public static function strings(string $description): array
    {
        return ['type' => 'array', 'description' => $description, 'items' => ['type' => 'string']];
    }

    /** @return array<string,mixed> */
    public static function object(string $description): array
    {
        return ['type' => 'object', 'description' => $description, 'additionalProperties' => true];
    }

    /** The course id, which more than half the tools take. @return array<string,mixed> */
    public static function courseId(): array
    {
        return self::int('The course id, as returned by list_courses.');
    }
}
