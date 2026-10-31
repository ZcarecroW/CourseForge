---
title: Tokens and caching
description: What a page knows about the rest of the course, why that keeps pages from repeating each other, and how CourseForge keeps the prompt small and cached.
---

A page is written in a request of its own, so it knows about the rest of the course exactly what its prompt tells it. Two goals pull against each other here: a page should know enough about the others **not to repeat them**, and every page pays for whatever it is told. Since 5.3 CourseForge gets both.

## What a page is told

**What every page owns.** The outline carries a [scope](outline.md#page-scopes) under every page, and the page prompt carries the **course map**: every chapter, and every page numbered `chapter.page` with its title and scope.

```text
1. Foundations
   1.1 What a model learns - Supervised and unsupervised learning; features and labels
   1.2 Setting up the toolkit - Python 3.14 with uv; NumPy, pandas and scikit-learn
2. Your first models
   2.1 Linear regression - The hypothesis; least squares; reading coefficients
   2.2 Gradient descent - The loss surface; the update rule; learning rate
```

The model writing 2.1 can see that the update rule belongs to 2.2 and leave it there, naming that page instead of teaching it. The scopes are planned once, together, when the outline is designed - the one moment anything sees the whole course at once.

**Its own chapter.** The chapter's goal goes with every page of that chapter, and only that chapter's.

**What its neighbours actually say.** When pages of the same chapter are already written, the brief lists them with their section headings, read from the stored text. Plans drift; headings show where. They cost one line per heading, no extra request, and only the same chapter is shown - which is where overlap happens. A batch submits every page at once, so it has no written neighbours and relies on the map alone.

## Ordered for the cache

Providers cache the **beginning** of a prompt: if a request starts with exactly the same bytes as a recent one, that prefix is read back at a fraction of the price. So CourseForge puts everything shared first, byte-identical for every page that shares it, and everything that differs last.

::diagram prompt-cache | The shared part comes first and is identical from page to page; only the short brief at the end is paid in full.

| Provider | What CourseForge sends |
|---|---|
| **Anthropic** | A cache breakpoint behind the course map, the chapter and the system text. A request sent once - an outline, a scope plan - gets none, because a cache write costs a quarter more and would never be read. Batches use the one-hour cache. |
| **OpenAI** (gpt-5.6 and later) | An explicit cache point behind each shared part and `prompt_cache_options.mode: explicit`, so only the shared part is written - these models bill cache writes. Plus `prompt_cache_key`, which keeps a course's pages where its cache is warm. Earlier models: the key, and the shared part first. |
| **Google Gemini** | The shared parts open the system instruction, which is what implicit caching keys on (4,096 tokens minimum on current models). |
| **OpenRouter** | `cache_control` markers for Claude, Gemini and Qwen upstreams, and `prompt_cache_key` for every model. |
| **Everything else** | The shared part first, in one system message - the order any automatic prefix cache needs. |

> [!IMPORTANT]
> If you edit the prompt library, keep anything that differs from page to page in `page_user`, never in the shared segments or in `page_system`. One changed byte early in a prompt is a cache miss for everything after it. See [The prompt library](prompt-library.md#how-a-page-prompt-is-assembled).

## The numbers

Measured on a course of 20 chapters × 7 pages with six-hundred-word chapter descriptions, all pages written, building every page's prompt with the old code and the new:

| Per page | 5.2 | 5.3 |
|---|---:|---:|
| Prompt size | 138,832 characters (about 35,000 tokens) | 47,464 characters (about 12,000 tokens) |
| Identical for every page of the course or chapter | the system text, and only when no page had tags | 35,727 characters |
| Building the prompt | 18 ms, 10 MB peak | 7 ms, 6 MB peak |

That is **two thirds less input before any cache**, and with Anthropic's cache reading the shared part at a tenth of the price, a little over **nine tenths less billed input** on a live run. Building a prompt also stopped reading the text of every page of the course, which a batch used to do once per page.

5.2's single cache breakpoint sat on a part of the prompt that differed for every page as soon as pages had tags, so it paid the cache-write premium on every page and was never read back. That is the kind of mistake the byte-identical-prefix rule exists to prevent.

## Over MCP: sending the map once

A client writing a whole course in one conversation would otherwise receive the course map with every page brief. `get_page_brief` hands the shared parts out separately, each with an id; a client that passes the ids back as `known_context` is not sent the same text again. For a hundred-page course that is the difference between the map entering the client's context once and entering it a hundred times. See [Connect an AI client](mcp.md#two-ways-to-write-a-course).

## Practical advice

- **Plan scopes.** A course with scopes writes better pages *and* the scopes are what make the map useful. **Plan missing scopes** on the Structure tab fills them in for an older outline in one request.
- **Write in chapter order** when writing live, so later pages see their neighbours' headings.
- **Use the batch queue** for whole courses you can wait a day for: about half price on top of caching where the provider caches batch requests.
- **Keep the research briefing short.** It rides in the shared part of every page (cached, but not free).
- **Estimate first.** `estimate_run` measures the real prompts and reports tokens before anything is spent. See [Runs](runs.md#estimating-first).
