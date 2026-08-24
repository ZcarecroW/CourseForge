<?php
declare(strict_types=1);

namespace CourseForge\Ai\Batch;

/**
 * What a provider hands back the moment a batch is accepted, and everything
 * CourseForge needs in order to find that batch again days later.
 *
 * `ref` is the field that earns this class. It is an opaque, provider-owned,
 * JSON-serialisable bag: OpenAI keeps `output_file_id` and `error_file_id`
 * there, Anthropic a `results_url`, Gemini either a `responses_file` or a flag
 * saying the answers arrived inline, OpenRouter nothing at all. It is the one
 * mutable thing here because on OpenAI those file ids do not exist at submit
 * time - they only materialise when the batch finishes - so polling has to be
 * able to write them back into a handle the caller stored hours earlier.
 *
 * The two timestamps are different deadlines, and treating them as one loses a
 * course. `expiresAt` is when the batch itself dies: Gemini stops at 48 hours
 * and returns ZERO results for everything still queued, so a run polled too
 * slowly has nothing to collect and has to be submitted again from scratch.
 * `resultsExpireAt` is the download deadline, which falls long after the batch
 * has finished and differs per provider - 29 days at Anthropic, 30 at OpenAI
 * and OpenRouter, six weeks at Gemini. No provider is durable storage, and the
 * answers have to be pulled across and persisted before that date passes.
 */
final class BatchHandle
{
    /** @param array<string,mixed> $ref */
    public function __construct(
        public readonly string $remoteId,
        public readonly string $remoteState = '',
        public array $ref = [],
        public readonly ?int $expiresAt = null,
        public readonly ?int $resultsExpireAt = null,
    ) {
    }

    /**
     * Rebuilds a handle from the run row it was stored in.
     *
     * A `ref` that does not decode to an object is dropped rather than
     * repaired. That covers a batch submitted by an older version, which wrote
     * a bare string into the same column: the ref comes back empty, the next
     * poll fills it in from the provider, and nothing has to migrate.
     */
    public static function fromStorage(
        string $remoteId,
        string $remoteState = '',
        string $ref = '',
        ?int $expiresAt = null,
        ?int $resultsExpireAt = null,
    ): self {
        $decoded = $ref !== '' ? json_decode($ref, true) : null;

        return new self(
            $remoteId,
            $remoteState,
            is_array($decoded) ? $decoded : [],
            $expiresAt !== null && $expiresAt > 0 ? $expiresAt : null,
            $resultsExpireAt !== null && $resultsExpireAt > 0 ? $resultsExpireAt : null,
        );
    }

    /**
     * Folds what a poll learned into the handle.
     *
     * Empty values are ignored, so a provider that reports the same object
     * twice - once while it is running and the result files do not exist yet,
     * once after - never blanks out what it already told us.
     *
     * @param array<string,mixed> $ref
     */
    public function mergeRef(array $ref): void
    {
        foreach ($ref as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $this->ref[(string)$key] = $value;
        }
    }

    /** One scalar out of the bag, as a string, for the common case. */
    public function refValue(string $key, string $default = ''): string
    {
        $value = $this->ref[$key] ?? null;
        return is_scalar($value) ? (string)$value : $default;
    }

    /** The bag as it goes into the run row's `remote_ref` column. */
    public function refJson(): string
    {
        return $this->ref === []
            ? ''
            : (string)(json_encode($this->ref, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }

    /** True once the provider will no longer run whatever is still queued. */
    public function dead(?int $now = null): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= ($now ?? time());
    }

    /** True once finished results can no longer be downloaded. */
    public function unreachable(?int $now = null): bool
    {
        return $this->resultsExpireAt !== null && $this->resultsExpireAt <= ($now ?? time());
    }
}
