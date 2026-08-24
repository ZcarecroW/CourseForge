<?php
declare(strict_types=1);

namespace CourseForge\Ai\Batch;

/**
 * How much one submission is allowed to be.
 *
 * This used to be a single integer - the largest number of requests a provider
 * would take in one go - and that number is wrong in a way only a real course
 * exposes. OpenAI accepts 50,000 rows *or* a 200 MB input file, whichever it
 * reaches first, and a CourseForge page prompt carries the whole course context
 * with it, so it lands somewhere around 8 KB once it is JSON-encoded. 200 MB of
 * 8 KB rows is roughly 25,000 of them: the byte ceiling binds at half the row
 * ceiling, and a submission split by row count alone produces a file that looks
 * perfectly legal and comes back as a 413. One integer cannot say that, and it
 * cannot say Gemini's per-model enqueued-token quota either.
 *
 * The last two fields are not sizes at all but deadlines, and they are here
 * because they belong to the same decision. `window` is what the provider is
 * asked to promise (everyone offers 24h; Groq also takes 7d and recommends it),
 * and `retentionDays` is how long the finished results stay downloadable - 29
 * days at Anthropic, 30 at OpenAI and OpenRouter, six weeks at Gemini. A run
 * that is not collected inside that window is gone, so the scheduler has to
 * know the number rather than assume a month.
 */
final class BatchLimits
{
    public const MEGABYTE = 1048576;

    public function __construct(
        public readonly int $maxRequests,
        public readonly int $maxBytes,
        public readonly ?int $maxEnqueuedTokens = null,
        public readonly string $window = '24h',
        public readonly int $retentionDays = 30,
    ) {
    }

    /**
     * What to assume about an endpoint nobody has verified.
     *
     * The capability probe can tell whether a queue exists; it cannot tell how
     * big a file that queue will swallow without submitting one. So an unknown
     * OpenAI-compatible gateway is given OpenAI's own numbers, which are the
     * conservative choice: every gateway that copied the API copied these, and
     * a chunk that turns out to be too big is answered with a 413 that the
     * caller can retry at half the size.
     */
    public static function conservative(): self
    {
        return new self(50000, 200 * self::MEGABYTE);
    }

    /** The same limits with a smaller file ceiling, for retrying a 413. */
    public function withMaxBytes(int $maxBytes): self
    {
        return new self(
            $this->maxRequests,
            max(1, $maxBytes),
            $this->maxEnqueuedTokens,
            $this->window,
            $this->retentionDays,
        );
    }

    /** Half the file ceiling, which is the documented answer to payload_too_large. */
    public function halved(): self
    {
        return $this->withMaxBytes(intdiv($this->maxBytes, 2));
    }

    /** Both bounds in one line, for an error a person has to act on. */
    public function describe(): string
    {
        return number_format($this->maxRequests) . ' requests or '
            . number_format($this->maxBytes / self::MEGABYTE, 0) . ' MB, whichever comes first';
    }
}
