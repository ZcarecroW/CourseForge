<?php
declare(strict_types=1);

namespace CourseForge\Ai;

/**
 * One completion request, in the only shape CourseForge ever needs: a system
 * instruction and a single user turn.
 *
 * Providers translate this into their own wire format – OpenAI folds the system
 * text into `messages`, Anthropic keeps it in a top-level `system` field, and
 * the Claude CLI passes it a system-prompt file. Keeping the neutral shape here
 * is what lets one prompt be sent live or queued into a batch without the
 * generators knowing which of the two happened.
 *
 * `research` is the one capability that travels with a request rather than
 * being a property of the account. It says the page should be written against
 * what the web says today, and each provider turns it into its own server-side
 * search tool inside its own `payload()`. A provider that cannot search ignores
 * it: the prompt still asks for current facts, and the model answers from what
 * it knows, which is the same page it would have written before this field
 * existed. Nothing is refused for wanting to search.
 */
final class AiRequest
{
    public function __construct(
        public readonly string $model,
        public readonly string $system,
        public readonly string $user,
        public readonly float $temperature = 0.7,
        public readonly int $maxTokens = 0,
        public readonly bool $research = false,
        public readonly int $maxSearches = 0,
    ) {
    }

    /**
     * The same request against a different model id – used to strip `:batch`.
     *
     * Every field is named, so a new one that is not added here is dropped in
     * silence on exactly the path that costs the most to get wrong: the queued
     * one, where nothing is noticed for a day.
     */
    public function withModel(string $model): self
    {
        return new self(
            $model,
            $this->system,
            $this->user,
            $this->temperature,
            $this->maxTokens,
            $this->research,
            $this->maxSearches,
        );
    }

    /** The OpenAI `messages` array. An empty system prompt contributes nothing. */
    public function messages(): array
    {
        $messages = [];
        if (trim($this->system) !== '') {
            $messages[] = ['role' => 'system', 'content' => $this->system];
        }
        $messages[] = ['role' => 'user', 'content' => $this->user];
        return $messages;
    }
}
