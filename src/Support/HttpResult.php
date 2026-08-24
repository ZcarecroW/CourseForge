<?php
declare(strict_types=1);

namespace CourseForge\Support;

/** Result of one outbound HTTP call – a non-2xx status is data, never an exception. */
final class HttpResult
{
    public function __construct(
        public readonly int $status,
        public readonly string $raw,
        public readonly mixed $data,
        public readonly string $error,
        public readonly int $errno,
    ) {
    }

    /**
     * A 2xx that actually arrived in full.
     *
     * The errno check is not paranoia: with CURLOPT_RETURNTRANSFER a transfer
     * that dies after the response line - a timeout mid-body, a dropped
     * connection - leaves the status at 200 while the body is truncated or
     * empty. Reading that as success is how a whole batch of finished pages
     * gets thrown away and marked as never answered.
     */
    public function ok(): bool
    {
        return $this->errno === 0 && $this->status >= 200 && $this->status < 300;
    }

    /** True when the request never reached the server (DNS, TLS, timeout). */
    public function unreachable(): bool
    {
        return $this->status === 0 && $this->error !== '';
    }

    /** True when the server answered but the body did not arrive intact. */
    public function truncated(): bool
    {
        return $this->status !== 0 && $this->errno !== 0;
    }

    /** Best-effort error text from the usual JSON error envelopes. */
    public function message(int $length = 400): string
    {
        if (is_array($this->data)) {
            foreach ([['error', 'message'], ['error'], ['message'], ['detail']] as $path) {
                $node = $this->data;
                foreach ($path as $key) {
                    $node = is_array($node) && array_key_exists($key, $node) ? $node[$key] : null;
                }
                if (is_string($node) && trim($node) !== '') {
                    return mb_substr($node, 0, $length);
                }
            }
        }
        return Text::snippet($this->raw !== '' ? $this->raw : $this->error, $length);
    }
}
