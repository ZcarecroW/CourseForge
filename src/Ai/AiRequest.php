<?php
declare(strict_types=1);

namespace CourseForge\Ai;

/**
 * One completion request, in the only shape CourseForge ever needs: a system
 * instruction and a single user turn.
 *
 * Providers translate this into their own wire format – OpenAI folds the system
 * text into `messages`, Anthropic keeps it in a top-level `system` field, and
 * the Claude CLI passes it as `--append-system-prompt`. Keeping the neutral
 * shape here is what lets one prompt be sent live or queued into a batch
 * without the generators knowing which of the two happened.
 */
final class AiRequest
{
    public function __construct(
        public readonly string $model,
        public readonly string $system,
        public readonly string $user,
        public readonly float $temperature = 0.7,
        public readonly int $maxTokens = 0,
    ) {
    }

    /** The same request against a different model id – used to strip `:batch`. */
    public function withModel(string $model): self
    {
        return new self($model, $this->system, $this->user, $this->temperature, $this->maxTokens);
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
