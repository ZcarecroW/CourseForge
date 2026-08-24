<?php
declare(strict_types=1);

namespace CourseForge\Ai\Batch;

/** One answer out of a finished batch, matched back by its custom id. */
final class BatchItemResult
{
    public function __construct(
        public readonly string $customId,
        public readonly string $state,      // succeeded | errored | canceled | expired
        public readonly string $content = '',
        public readonly string $error = '',
    ) {
    }

    public static function ok(string $customId, string $content): self
    {
        return new self($customId, 'succeeded', $content);
    }

    public static function failed(string $customId, string $state, string $error): self
    {
        return new self($customId, $state, '', $error);
    }

    public function succeeded(): bool
    {
        return $this->state === 'succeeded' && trim($this->content) !== '';
    }
}
