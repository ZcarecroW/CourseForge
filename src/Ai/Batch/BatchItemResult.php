<?php
declare(strict_types=1);

namespace CourseForge\Ai\Batch;

/**
 * One answer out of a finished batch, matched back by its custom id.
 *
 * The interesting field is `error`, and it is an array on purpose. A batch line
 * that failed carries the provider's whole error envelope - a type, a code, a
 * message, sometimes a nested upstream error and a request id - and flattening
 * that to a sentence at the point it is read throws away the only thing the
 * caller needs later: whether the failure is worth retrying. `rate_limit_error`
 * and `invalid_request_error` read almost identically as prose and mean
 * opposite things. The envelope is kept as it arrived, and `errorMessage()`
 * flattens it once, at the moment a person is shown it.
 *
 * `httpStatus` is separate from `error` because OpenAI, Groq and OpenRouter put
 * a per-line `status_code` inside an HTTP 200 download: the batch succeeded,
 * the file downloaded, and line 4,000 of it is a 429. `usage` is here for the
 * same reason - a queued run is where the token accounting for a whole course
 * lives, and it is only reported per line.
 */
final class BatchItemResult
{
    public const SUCCEEDED = 'succeeded';
    public const ERRORED = 'errored';
    public const EXPIRED = 'expired';
    public const CANCELLED = 'cancelled';

    /**
     * @param array<string,mixed>|null $usage
     * @param array<string,mixed>|null $error the raw provider envelope
     */
    public function __construct(
        public readonly string $customId,
        public readonly string $status,
        public readonly ?string $text = null,
        public readonly ?array $usage = null,
        public readonly ?array $error = null,
        public readonly ?int $httpStatus = null,
    ) {
    }

    /** @param array<string,mixed>|null $usage */
    public static function ok(string $customId, string $text, ?array $usage = null): self
    {
        return new self($customId, self::SUCCEEDED, $text, $usage);
    }

    /**
     * A line that did not produce text.
     *
     * A plain string is accepted and wrapped, because an adapter that has only
     * ever seen a message - a truncated answer, an empty content array - has no
     * envelope to hand over and inventing one would be a lie. Anything that
     * came off the wire as an object should be passed as the object.
     *
     * @param array<string,mixed>|string $error
     */
    public static function failed(string $customId, string $status, array|string $error, ?int $httpStatus = null): self
    {
        return new self(
            $customId,
            $status,
            null,
            null,
            is_array($error) ? $error : ['message' => $error],
            $httpStatus,
        );
    }

    /**
     * Whether this answer can be written to a page.
     *
     * Blank text counts as a failure even when the provider called it a
     * success, which happens more often than it should: a refusal, a response
     * truncated by the output cap, a Gemini RECITATION block. Storing the empty
     * string would leave a blank page behind and no sign of why.
     */
    public function succeeded(): bool
    {
        return $this->status === self::SUCCEEDED && trim((string)$this->text) !== '';
    }

    /** The text, never null, for a caller that has already checked succeeded(). */
    public function content(): string
    {
        return (string)$this->text;
    }

    /** The envelope flattened to one line, for a run log or a page's error field. */
    public function errorMessage(): string
    {
        if ($this->error === null) {
            return '';
        }

        $inner = is_array($this->error['error'] ?? null) ? $this->error['error'] : $this->error;
        $message = trim((string)($inner['message'] ?? ''));
        $code = trim((string)($inner['code'] ?? $inner['type'] ?? ''));

        if ($message === '') {
            $message = (string)(json_encode($this->error, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        }
        if ($code !== '' && !str_starts_with($message, $code)) {
            $message = $code . ': ' . $message;
        }
        if ($this->httpStatus !== null && $this->httpStatus > 0) {
            $message = 'HTTP ' . $this->httpStatus . ' - ' . $message;
        }

        return $message;
    }
}
