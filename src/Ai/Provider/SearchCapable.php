<?php
declare(strict_types=1);

namespace CourseForge\Ai\Provider;

/**
 * A provider whose endpoint can search the web while it answers.
 *
 * This is the same shape as `BatchCapable`: an extra interface rather than more
 * methods on `Provider`, because the base interface is the contract every
 * adapter has to satisfy and most of them cannot do this. `Providers` asks with
 * `instanceof` and the UI hides what is not there, exactly as it does for the
 * batch queue.
 *
 * There is deliberately **no method for shaping the request** here. Every
 * provider already has one private `payload()` that both the live and the
 * queued path go through, and the search tool belongs in it beside the other
 * things that are decided per request. A second place that edits a body would
 * be a second place for the two paths to diverge, and the queued one is the
 * path where diverging is not noticed for a day.
 *
 * What this interface is for is telling the person clicking the toggle what it
 * will do to their bill, before they turn it on for a five-hundred-page course.
 */
interface SearchCapable
{
    /** Whether this endpoint has a server-side search tool at all. */
    public function supportsSearch(): bool;

    /**
     * The models the search tool actually works on, where the provider says.
     *
     * An empty array means "it did not say", never "none of them" - the same
     * contract `batchModels()` has. Anthropic's `/v1/models` reports batch,
     * citations, code execution, effort and thinking among its capabilities and
     * says nothing whatsoever about web search, so the honest answer there is
     * the empty array and the toggle is offered for every model.
     *
     * @return array<int,string>
     */
    public function searchModels(): array;

    /**
     * What one search costs here, in words.
     *
     * Shown next to the toggle. A course is hundreds of pages and this is a
     * per-page cost on top of the tokens, which is the kind of arithmetic
     * nobody does in their head before switching something on.
     */
    public function searchNote(): string;
}
