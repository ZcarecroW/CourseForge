<?php
declare(strict_types=1);

namespace CourseForge\Ai;

/**
 * The `:batch` model suffix.
 *
 * A model written as `claude-opus-5:batch` means "send this through the
 * provider's batch API instead of asking for it now". The suffix is a
 * CourseForge convention that works the same way for every provider, so a
 * profile switches between live and queued generation by editing one string.
 *
 * It is only ever stripped from the end: OpenRouter slugs carry their own
 * colon-separated variants (`deepseek/deepseek-r1:free`), and those must
 * survive untouched.
 */
final class ModelId
{
    public const BATCH = ':batch';

    public static function isBatch(string $model): bool
    {
        return str_ends_with(strtolower(trim($model)), self::BATCH);
    }

    /** The model id the provider actually knows, with `:batch` removed. */
    public static function base(string $model): string
    {
        $model = trim($model);
        return self::isBatch($model) ? rtrim(substr($model, 0, -strlen(self::BATCH))) : $model;
    }
}
