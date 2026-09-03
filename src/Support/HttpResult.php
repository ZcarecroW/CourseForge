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

    /**
     * The error the server put in its JSON envelope, and nothing else.
     *
     * message() falls back to the raw body when there is no envelope, which is
     * right for a log and wrong for a sentence shown to whoever typed the
     * address: a base URL pointed at something that is not a provider would
     * have its answer quoted back, and reading a stranger's answer through
     * CourseForge is exactly what an address field must not be good for. So
     * an error that reaches a person carries the envelope's message or a
     * description of what arrived - never the body.
     */
    public function errorMessage(int $length = 300): string
    {
        if (is_array($this->data)) {
            foreach ([['error', 'message'], ['error'], ['message'], ['detail']] as $path) {
                $node = $this->data;
                foreach ($path as $key) {
                    $node = is_array($node) && array_key_exists($key, $node) ? $node[$key] : null;
                }
                if (is_string($node) && trim($node) !== '') {
                    return mb_substr(trim($node), 0, $length);
                }
            }
            return 'The answer was JSON without an error message.';
        }
        if ($this->raw === '') {
            return $this->error !== '' ? $this->error : 'The answer was empty.';
        }
        $looksHtml = preg_match('/<\s*(html|!doctype|body|head)\b/i', substr($this->raw, 0, 512)) === 1;
        return ($looksHtml ? 'The answer was an HTML page' : 'The answer was not JSON')
            . ' (' . strlen($this->raw) . ' bytes), which is not what a provider sends - check the address.';
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
