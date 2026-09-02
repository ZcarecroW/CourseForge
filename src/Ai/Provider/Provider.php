<?php
declare(strict_types=1);

namespace CourseForge\Ai\Provider;

use CourseForge\Ai\AiRequest;

/**
 * One way of reaching a language model.
 *
 * Five kinds implement it: OpenAI, the Anthropic Messages API, the Gemini
 * Developer API, OpenRouter, and the preset lane -
 * one class serving every gateway that speaks OpenAI's /chat/completions, told
 * apart by the preset each account is on. Everything above this layer - the
 * generators, the prompt library, the batch runner - is written against this
 * interface and never learns which one answered.
 *
 * The interface itself is deliberately small, and 4.0 did not widen it. What a
 * queue needs is in BatchCapable, what a gateway needs to be configured is in
 * PresetSpec, and what the account form needs is in Providers::catalogue(). An
 * adapter with something extra to offer - Anthropic's per-model capabilities,
 * OpenRouter's priced catalogue, the preset lane's probe - publishes it as its
 * own method, and the one caller that wants it asks for it by class.
 */
interface Provider
{
    /**
     * The stable key stored in the profile, and the same string
     * Providers::kindOf() returns for the account this instance was built from:
     * openai | anthropic | gemini | openrouter | oai-compat.
     */
    public function kind(): string;

    /** Human-readable name for error messages. */
    public function label(): string;

    /**
     * Every model id the account can use, sorted.
     *
     * @return string[]
     */
    public function models(): array;

    /**
     * One completion, start to finish.
     *
     * Throws on any failure, and "failure" includes the ones that arrive as a
     * perfectly successful HTTP 200: a refusal, a prompt-level block, an answer
     * cut off by the output cap, an upstream error reported in the body. An
     * implementation must never answer those with an empty or partial string.
     * A blank course page written into the database looks like work that
     * succeeded and loses the reason it did not, which is a far worse outcome
     * for this application than a loud error the operator can read.
     */
    public function chat(AiRequest $request): string;

    /**
     * Whether `model:batch` will work here.
     *
     * A provider may answer false even when it implements BatchCapable - a
     * server on your own machine, for instance, has no queue to submit to. This is the
     * endpoint's answer; whether a particular account can queue right now also
     * depends on its key and its model, which is what
     * Providers::batchReadiness() puts together.
     */
    public function supportsBatch(): bool;

    /**
     * The subset of models() that the batch queue actually accepts, when the
     * provider is able to say.
     *
     * An empty array means "it did not say", not "none of them". Anthropic
     * reports it per model, OpenRouter infers it from the priced twin of each
     * slug, and a generic gateway cannot answer at all - there the truth is the
     * provider-wide supportsBatch().
     *
     * @return string[]
     */
    public function batchModels(): array;
}
