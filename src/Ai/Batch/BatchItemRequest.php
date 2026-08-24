<?php
declare(strict_types=1);

namespace CourseForge\Ai\Batch;

use CourseForge\Ai\AiRequest;

/**
 * One entry of a submitted batch: a completion request plus the id it answers
 * under.
 *
 * This is the whole of what the four batch styles have in common. Anthropic
 * takes the requests inline, OpenAI takes an uploaded JSONL file, Gemini starts
 * a long-running operation and OpenRouter posts them as one JSON body with a
 * load-bearing key order - but every one of them reduces to a custom id and a
 * request body, which is why no abstraction above this one would carry any
 * meaning.
 *
 * The custom id has to survive every provider's rules at once, and Anthropic's
 * are the narrowest: 1 to 64 characters of letters, digits, hyphen and
 * underscore, unique within the batch. Results come back in an arbitrary order
 * everywhere, so this id is the only thing that matches an answer to a page.
 */
final class BatchItemRequest
{
    public function __construct(
        public readonly string $customId,
        public readonly AiRequest $request,
    ) {
    }
}
