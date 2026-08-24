<?php
declare(strict_types=1);

namespace CourseForge\Ai\Provider;

use CourseForge\Ai\Batch\BatchHandle;
use CourseForge\Ai\Batch\BatchItemRequest;
use CourseForge\Ai\Batch\BatchItemResult;
use CourseForge\Ai\Batch\BatchLimits;
use CourseForge\Ai\Batch\BatchStatus;

/**
 * A provider that can take a pile of requests now and answer them later.
 *
 * Every call is stateless and takes the handle CourseForge stored, so a batch
 * survives a restart, a closed browser tab and a redeployed front end: the
 * process that submits is rarely the process that collects. Results are keyed
 * by custom id because every provider is explicit that they come back in an
 * arbitrary order.
 *
 * The handle, rather than a bare remote id, is what the calls take - and that
 * is the change everything else here follows from. On OpenAI the reference
 * needed to download results is a pair of file ids that do not exist until the
 * batch completes, so polling has to be able to write them back into the
 * handle. A batch is therefore a small piece of mutable state that the caller
 * owns and persists, not a string.
 */
interface BatchCapable
{
    /**
     * Hands the whole submission over and returns what it will be found by.
     *
     * @param array<int,BatchItemRequest> $items
     */
    public function submitBatch(array $items): BatchHandle;

    /**
     * Where the batch stands, and anything new worth remembering about it.
     *
     * The returned status carries a `ref` for the caller to merge into the
     * stored handle: OpenAI's `output_file_id` and `error_file_id` are only
     * announced at completion, and a batch whose results reference was learned
     * and not written down is a batch that has to be polled again to be read.
     */
    public function pollBatch(BatchHandle $handle): BatchStatus;

    /**
     * The answers, streamed where the provider allows it.
     *
     * `iterable` rather than `array` because these files are large: 100,000
     * requests of course prose is a JSONL body that no default PHP memory limit
     * will hold, and Anthropic's own guidance is to read the results line by
     * line rather than buffer them. An adapter that has the whole thing in
     * memory anyway - OpenRouter returns its results inline in the poll
     * response - returns a plain array, which satisfies this just as well.
     *
     * @return iterable<string,BatchItemResult> keyed by custom id
     */
    public function fetchBatchResults(BatchHandle $handle): iterable;

    /**
     * Asks the provider to stop, and says whether the request went out.
     *
     * True means "cancellation requested", never "stopped". Cancellation is
     * asynchronous everywhere it exists: Anthropic moves to `canceling` and may
     * still finish requests already in flight, OpenAI stays `cancelling` for up
     * to ten minutes, and both end with partial results worth collecting. A
     * provider with no cancel route returns false rather than throwing - a
     * missing feature must not reach the user as a 404 from a button.
     */
    public function cancelBatch(BatchHandle $handle): bool;

    /** Whether there is a cancel route at all, so the UI can hide the button. */
    public function canCancel(): bool;

    /**
     * What one submission may contain.
     *
     * Two bounds and not one, because the row count published in the
     * documentation is not the bound that binds: OpenAI's 200 MB input file
     * fills at around 25,000 course prompts, half its 50,000-row cap.
     */
    public function batchLimits(): BatchLimits;

    /**
     * Throws away what the provider is holding for us, once the results are
     * safely persisted on this side.
     *
     * Nothing depends on this working, and an adapter with nothing to release
     * does nothing. It exists because the file-based styles leave litter: an
     * OpenAI batch input file counts against the organisation's storage quota
     * until it is deleted, forever, and a course of 500 pages uploads one every
     * time it is regenerated.
     */
    public function releaseBatch(BatchHandle $handle): void;
}
