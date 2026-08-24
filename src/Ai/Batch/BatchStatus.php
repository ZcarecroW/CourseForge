<?php
declare(strict_types=1);

namespace CourseForge\Ai\Batch;

/**
 * Where a submitted batch currently stands, normalised across providers.
 *
 * Anthropic calls it `processing_status` and admits three values, OpenAI calls
 * it `status` and has eight, Gemini reports a long-running Operation whose
 * state string is spelled `JOB_STATE_SUCCEEDED` in one document and
 * `BATCH_STATE_SUCCEEDED` in another, and OpenRouter is a beta endpoint whose
 * vocabulary is not published at all. Nothing downstream should have to know
 * any of that, so every adapter maps onto the constants below.
 *
 * `rawState` keeps the provider's own word untouched next to the mapped one.
 * That is not decoration: it is the only way to diagnose a batch that sat in
 * some state CourseForge has never seen, and the vocabularies do change - a
 * beta endpoint most of all. It is logged and displayed verbatim, never parsed.
 *
 * `ref` carries whatever the poll discovered back to the caller, which then
 * merges it into the stored handle. OpenAI's `output_file_id` and
 * `error_file_id` do not exist until the batch completes, so this is the only
 * moment they can be learned.
 */
final class BatchStatus
{
    /** Accepted, nothing has run yet. */
    public const PENDING = 'pending';
    /** Requests are being answered. */
    public const RUNNING = 'running';
    /** All answered, the provider is still assembling the results. */
    public const FINALIZING = 'finalizing';
    /** Finished. Individual requests inside it may still have failed. */
    public const DONE = 'done';
    /** The batch itself failed - most often the input file did not validate. */
    public const FAILED = 'failed';
    /** The window closed on work that was still queued. */
    public const EXPIRED = 'expired';
    /** A cancellation was requested and has not taken effect yet. */
    public const CANCELLING = 'cancelling';
    public const CANCELLED = 'cancelled';

    /** States that will never change again without a new submission. */
    private const TERMINAL = [self::DONE, self::FAILED, self::EXPIRED, self::CANCELLED];

    /**
     * @param array<string,int> $counts the provider's own tally, kept verbatim
     * @param array<string,mixed> $ref merged back into the handle by the caller
     */
    public function __construct(
        public readonly string $state,
        public readonly ?string $rawState = null,
        public readonly int $total = 0,
        public readonly int $completed = 0,
        public readonly int $failed = 0,
        public readonly array $ref = [],
        public readonly string $error = '',
        public readonly array $counts = [],
    ) {
    }

    /**
     * The same thing built from a provider's request-counts object.
     *
     * Every queue reports progress as a small map, and no two of them agree on
     * the keys: Anthropic sends processing/succeeded/errored/canceled/expired,
     * OpenAI sends total/completed/failed. Both are kept as they arrived - the
     * run panel shows them - and the three numbers everything else reads are
     * derived here rather than in each adapter.
     *
     * @param array<string,int|string> $counts
     * @param array<string,mixed> $ref
     */
    public static function fromCounts(
        string $state,
        string $rawState,
        array $counts,
        array $ref = [],
        string $error = '',
    ): self {
        $tally = [];
        foreach ($counts as $key => $value) {
            // Gemini reports its batchStats counters as int64-in-a-JSON-string,
            // so a cast is not optional anywhere this is used.
            $tally[(string)$key] = (int)$value;
        }

        $completed = $tally['succeeded'] ?? $tally['completed'] ?? 0;
        $failed = $tally['errored'] ?? $tally['failed'] ?? 0;
        $total = $tally['total'] ?? array_sum($tally);

        return new self($state, $rawState, $total, $completed, $failed, $ref, $error, $tally);
    }

    public function finished(): bool
    {
        return in_array($this->state, self::TERMINAL, true);
    }

    /**
     * True when results are worth downloading.
     *
     * A cancelled batch keeps whatever it managed to answer first, and so does
     * an expired one on every provider except Gemini, where the 48 hour expiry
     * returns nothing at all. Asking for those and getting an empty set costs
     * one request; not asking loses pages that were paid for. Only an outright
     * failure - the input file never validated - has nothing behind it.
     */
    public function hasResults(): bool
    {
        return $this->state === self::DONE
            || $this->state === self::EXPIRED
            || $this->state === self::CANCELLED;
    }
}
