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
 *
 * `context` is the part of the instructions that other requests share - the
 * course map, then the chapter - as segments in the order they are sent, ahead
 * of `system`. It is kept apart from `system` for one reason: so that each
 * provider can mark where the shared part ends, which is where its prompt cache
 * can stop. Anthropic and OpenRouter put a breakpoint behind each segment,
 * OpenAI's newer models an explicit cache point, and every other provider gets
 * the segments joined in front of the system text - which is still the order
 * an automatic prefix cache needs. A provider that knows nothing of caching
 * loses nothing: systemText() is the same instructions, in the same order.
 */
final class AiRequest
{
    /**
     * @param string[] $context
     */
    public function __construct(
        public readonly string $model,
        public readonly string $system,
        public readonly string $user,
        public readonly float $temperature = 0.7,
        public readonly int $maxTokens = 0,
        public readonly bool $research = false,
        public readonly int $maxSearches = 0,
        public readonly array $context = [],
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
            $this->context,
        );
    }

    /**
     * The shared segments and the system text, in sending order, blanks dropped.
     *
     * @return string[]
     */
    public function systemParts(): array
    {
        return array_values(array_filter(
            [...$this->context, $this->system],
            static fn(string $part): bool => trim($part) !== ''
        ));
    }

    /** Everything a provider without segments puts in its system field. */
    public function systemText(): string
    {
        return implode("\n\n", array_map('trim', $this->systemParts()));
    }

    /** Whether there is a shared part worth marking for a cache. */
    public function hasContext(): bool
    {
        foreach ($this->context as $segment) {
            if (trim($segment) !== '') {
                return true;
            }
        }
        return false;
    }

    /** The name of the shared part, for providers that route their cache by key. */
    public function cacheKey(): string
    {
        return CourseContext::key(array_values(array_filter(
            $this->context,
            static fn(string $part): bool => trim($part) !== ''
        )));
    }

    /** The OpenAI `messages` array. An empty system prompt contributes nothing. */
    public function messages(): array
    {
        $messages = [];
        $system = $this->systemText();
        if ($system !== '') {
            $messages[] = ['role' => 'system', 'content' => $system];
        }
        $messages[] = ['role' => 'user', 'content' => $this->user];
        return $messages;
    }
}
