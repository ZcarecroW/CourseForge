<?php
declare(strict_types=1);

namespace CourseForge\Ai\Batch;

/**
 * Where a submitted batch currently stands, normalised across providers.
 *
 * Anthropic calls it `processing_status`, OpenAI calls it `status`, and the two
 * vocabularies barely overlap; everything downstream reads these four states
 * instead.
 */
final class BatchStatus
{
    public const RUNNING = 'running';
    public const ENDED = 'ended';
    public const CANCELED = 'canceled';
    public const FAILED = 'failed';

    /** @param array<string,int> $counts */
    public function __construct(
        public readonly string $state,
        public readonly string $remoteState = '',
        public readonly array $counts = [],
        public readonly string $error = '',
        public readonly string $resultsRef = '',
    ) {
    }

    public function finished(): bool
    {
        return $this->state !== self::RUNNING;
    }

    /** True when results are worth downloading – a failed batch has none. */
    public function hasResults(): bool
    {
        return $this->state === self::ENDED || $this->state === self::CANCELED;
    }
}
