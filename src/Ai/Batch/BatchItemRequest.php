<?php
declare(strict_types=1);

namespace CourseForge\Ai\Batch;

use CourseForge\Ai\AiRequest;

/** One entry of a submitted batch: a completion request plus the id it answers under. */
final class BatchItemRequest
{
    public function __construct(
        public readonly string $customId,
        public readonly AiRequest $request,
    ) {
    }
}
