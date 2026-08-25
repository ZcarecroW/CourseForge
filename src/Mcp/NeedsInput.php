<?php
declare(strict_types=1);

namespace CourseForge\Mcp;

use RuntimeException;

/**
 * A tool stopping to ask, rather than failing.
 *
 * Thrown out of a handler and caught by the server, which turns it into one of
 * two answers depending on what the client on the other end can actually do:
 *
 *   - a client that declared elicitation gets `resultType: "input_required"`
 *     with the question and a signed continuation, and retries the same call
 *     with the answer;
 *   - a client that did not gets an ordinary tool error carrying `$insteadSay`,
 *     which names the argument to supply. That is what every one of these tools
 *     did before MRTR existed, and it stays the behaviour for the clients that
 *     are installed today.
 *
 * `settled` marks the case where the question was already put and came back
 * without an answer - declined or dismissed. There is nothing to ask again, so
 * the server must not pause a second time; it reports what happened and stops.
 * Without this an unlucky flow could re-ask the same question forever, which is
 * the failure the spec warns about and does not itself prevent.
 */
final class NeedsInput extends RuntimeException
{
    /**
     * @param array<string,mixed> $schema flat properties, per the elicitation contract
     * @param string[] $required
     */
    public function __construct(
        public readonly string $key,
        public readonly string $question,
        public readonly array $schema,
        public readonly array $required,
        public readonly string $insteadSay,
        public readonly bool $settled = false,
    ) {
        parent::__construct($insteadSay);
    }

    /**
     * The question, in the shape an `inputRequests` entry takes.
     *
     * Form mode, because everything CourseForge asks for is a fact about a
     * course. URL mode exists for credentials and payments, and the protocol
     * requires it for those - nothing here asks for either, and nothing here
     * ever should: an elicitation form must never be used to collect a
     * password, an API key or a token.
     *
     * @return array<string,mixed>
     */
    public function request(): array
    {
        return [
            'method' => 'elicitation/create',
            'params' => [
                'mode' => 'form',
                'message' => $this->question,
                'requestedSchema' => [
                    'type' => 'object',
                    'properties' => $this->schema,
                    'required' => $this->required,
                ],
            ],
        ];
    }
}
