<?php
declare(strict_types=1);

namespace CourseForge\Ai\Provider;

use CourseForge\Ai\Batch\BatchHandle;
use CourseForge\Ai\Batch\BatchItemRequest;
use CourseForge\Ai\Batch\BatchItemResult;
use CourseForge\Ai\Batch\BatchStatus;

/**
 * A provider that can take a pile of requests now and answer them later.
 *
 * The four calls are deliberately stateless: CourseForge stores the remote id
 * itself, so a batch survives a restart, a closed browser tab and a redeployed
 * front end. Results are keyed by custom id because every provider is explicit
 * that they may come back in any order.
 */
interface BatchCapable
{
    /** @param array<int,BatchItemRequest> $items */
    public function submitBatch(array $items): BatchHandle;

    public function pollBatch(string $remoteId, string $resultsRef = ''): BatchStatus;

    /** @return array<string,BatchItemResult> keyed by custom id */
    public function fetchBatchResults(string $remoteId, string $resultsRef = ''): array;

    /** Best effort – a batch that already ended cannot be cancelled. */
    public function cancelBatch(string $remoteId): void;

    /** Largest number of requests one submission may carry. */
    public function batchLimit(): int;
}
