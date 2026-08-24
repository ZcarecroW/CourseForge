<?php
declare(strict_types=1);

namespace CourseForge\Ai\Batch;

/** What a provider hands back the moment a batch is accepted. */
final class BatchHandle
{
    public function __construct(
        public readonly string $remoteId,
        public readonly string $remoteState = '',
        public readonly string $resultsRef = '',
        public readonly int $expiresAt = 0,
    ) {
    }
}
