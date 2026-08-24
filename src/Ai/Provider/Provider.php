<?php
declare(strict_types=1);

namespace CourseForge\Ai\Provider;

use CourseForge\Ai\AiRequest;

/**
 * One way of reaching a language model.
 *
 * Four exist: an OpenAI-compatible endpoint, the Anthropic Messages API,
 * OpenRouter, and the locally installed Claude CLI (which bills a Claude
 * Pro/Max subscription rather than an API key). Everything above this layer –
 * the generators, the prompt library, the batch runner – is written against
 * this interface and never learns which one answered.
 */
interface Provider
{
    /** The stable key stored in the profile: openai | anthropic | openrouter | claude_cli. */
    public function kind(): string;

    /** Human-readable name for error messages. */
    public function label(): string;

    /**
     * Every model id the account can use, sorted.
     *
     * @return string[]
     */
    public function models(): array;

    /** One completion, start to finish. Throws HttpException on any failure. */
    public function chat(AiRequest $request): string;

    /**
     * Whether `model:batch` will work here.
     *
     * A provider may answer false even when it implements BatchCapable – the
     * Claude CLI, for instance, has no queue to submit to.
     */
    public function supportsBatch(): bool;

    /**
     * The subset of models() that the batch queue actually accepts, when the
     * provider is able to say.
     *
     * An empty array means "it did not say", not "none of them". Only Anthropic
     * reports this per model today; everywhere else the answer is the
     * provider-wide supportsBatch().
     *
     * @return string[]
     */
    public function batchModels(): array;
}
