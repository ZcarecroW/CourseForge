---
title: AI providers
description: Every way CourseForge can reach a model - Anthropic, OpenAI, Gemini, OpenRouter, sixteen presets, any OpenAI-compatible endpoint or a local server - and what each one does differently.
---

CourseForge reaches a model through an **AI account** on a [profile](profiles.md). There are five types: four native adapters and one for everything else that speaks OpenAI's `/chat/completions`.

| Type | Endpoint | Batch queue | Credential |
|---|---|---|---|
| **Anthropic API** | `POST /v1/messages` | yes, Message Batches | `sk-ant-` key |
| **OpenAI** | `POST /v1/chat/completions`; `POST /v1/responses` for a live page that researches | yes, file upload | API key |
| **Google Gemini** (beta) | `POST /v1beta/models/{model}:generateContent` | yes, paid tier only | Gemini API key |
| **OpenRouter** | `POST /api/v1/chat/completions` | yes, `/api/beta/batches` | OpenRouter key |
| **A preset or a custom endpoint** | `POST {base}/chat/completions` | if the endpoint has one - asked, not assumed | API key, or none for a local server |

All of them work on an ordinary web host.

## Why four native adapters

Because all four providers report at least one kind of failure with an **HTTP 200**, and in every case the body would otherwise be stored as a course page. A blank page, or one that stops mid-sentence, looks like work that succeeded. Each adapter knows its provider's version of:

1. **A refusal or a safety block** - Anthropic answers one at 200; Gemini returns no candidates and a `blockReason`.
2. **An answer cut off by the output limit** - `stop_reason`, `finishReason: MAX_TOKENS` (or `RECITATION`, frequent for long educational prose), `finish_reason: length`. Every one arrives with most of a lesson attached.
3. **An upstream error reported at 200** by a gateway that fans out.
4. **A routed provider that failed after it started** - OpenRouter commits the 200 as soon as the model starts producing.

Every one of those raises an error with the provider's reason instead of storing half a page, on the live path and the batch path alike.

## Anthropic

- Speaks API version **`2026-10-21`**, which refuses `temperature`, `top_p` and `top_k` on every model, so none is sent: the profile's temperature does not apply to Claude.
- What differs between models is read from the models endpoint's `capabilities` object - whether the batch queue takes a model and how long an answer it can produce - never from the model's name. The list is cached for a day. When the endpoint cannot be reached, the picker falls back to `claude-opus-5-5`, `claude-sonnet-5-5` and `claude-haiku-5-5`.
- The shared part of every page prompt carries **cache breakpoints**, so pages after the first read it from the prompt cache. A one-off request (an outline, a scope plan) gets none, because a cache write costs a quarter more and would never be read. Batches use the one-hour cache. See [Tokens and caching](token-economy.md).
- Results of large batches are streamed line by line, so a gigabyte of prose never has to fit in PHP's memory.
- **Web research** uses the search tool that filters results with code before the model reads them, on every model the list says can do it; it costs fewer input tokens on a page that searches a lot. Other models, and every queued page, use the basic tool. A long search that the API pauses is resumed, up to four times. Filtered search runs through code execution, so it is not covered by a zero-data-retention agreement.
- **A page the model declines** is retried by Anthropic on the model it recommends for that kind of refusal, which is mostly what a security or biology course needs. Live pages only, because the batch queue rejects the option. When it happens the page is kept and a line naming both models goes to the log. A refused page still costs what the refusal costs, and the fallback model bills at its own rates.

## OpenAI

- **Reasoning models** - the GPT-5 and GPT-6 families (`gpt-6-sol`, `gpt-6-luna`, `gpt-6-astra`, `gpt-6.1-sol`, `gpt-6.1-terra`…) and the o-series - take `max_completion_tokens` and refuse `temperature`, `top_p`, the penalties, `logit_bias` and `logprobs`. CourseForge decides that from the model id, because OpenAI's model list carries no capability data; a model released after that rule was written costs one retry, because a 400 that blames a parameter is retried once without it.
- The model picker is curated - `/v1/models` mixes embeddings, audio, images and every fine-tune in with the chat models - but only when the address really is `api.openai.com`.
- **Prompt caching:** `prompt_cache_key` keeps a course's pages where its cache is warm. On `gpt-5.6` and later, which bill cache writes, CourseForge sets explicit cache points behind each shared part so only the shared part is written.
- **Web research** on a live page goes through the Responses API with its `web_search` tool, on any model, because Chat Completions can only search on a search-tuned model such as `gpt-5-search-api`. It is billed at $10 per 1,000 searches plus the search content as tokens, and the course is not stored on OpenAI's side. **The batch queue cannot search:** a queued page is written from what the model knows, so generate a page live when it has to be researched.

## Google Gemini

> [!WARNING]
> **Gemini is offered as beta because of its API keys.** Google rejects unrestricted standard keys, while standard keys with explicit restrictions keep working. Every key AI Studio has issued since May 2026 is a service-account-bound auth key with an `AQ.` prefix, and developers still report some of those answering `401 ACCESS_TOKEN_TYPE_UNSUPPORTED`. So the adapter never collapses a Gemini error into "authentication failed": it shows Google's own error envelope verbatim, which is the only way to tell a wrong key from a key type the endpoint will not take.

- Not OpenAI-shaped in any particular: the model is in the URL path, authentication is an `x-goog-api-key` header, the system prompt is a top-level `systemInstruction`, and answers come back in `candidates[]`.
- The batch queue is paid-tier only and accepts a published list of models, including `gemini-4-carbon`, `gemini-4-argon` and `gemini-3.8-flash`. **A batch still running after 48 hours expires with no results at all.**
- Every request sets `store: false`. New paid projects keep requests in AI Studio's logs for 55 days by default, and CourseForge has no use for them.
- The profile's temperature does not apply: Google has deprecated the sampling parameters, so none is sent.
- Grounding with Google Search is billed per search query on Gemini 3 and later, at $14 per 1,000 after 5,000 free a month, and is not available on the free tier.
- Caching is implicit: the shared part of every prompt opens the system instruction, which is what Gemini's cache keys on (4,096 tokens minimum on current models).

## OpenRouter

- One key for every vendor OpenRouter fronts. Model ids carry a vendor prefix (`anthropic/claude-opus-5`); routing suffixes such as `:free` or `:nitro` are typed in by hand rather than offered in the picker.
- Sixty-odd real OpenRouter slugs end in `:batch`, which collides with CourseForge's marker. The adapter splits a slug on its last colon and always sends the plain one upstream, so both readings agree.
- Batches go to the beta endpoint, which has no cancel route: a stopped run there stays open until the provider is done.
- Caching: `cache_control` markers for Claude, Gemini and Qwen upstreams, and `prompt_cache_key` for every model, which OpenRouter also uses to keep a course on one upstream provider.

## The presets

Many gateways claim OpenAI compatibility, and the claim is true about the chat body. Everything around it differs - Groq hangs its API under `/openai/v1` and rejects `logprobs`, DeepInfra lives at `/v1/openai`, Z.ai has no `/v1` at all, Ollama takes any key or none. That is configuration, so it is one class and a table: picking a preset fills in the base URL, the parameters that gateway refuses, what its output limit is called and whether it might have a queue.

::presets

The local presets never ask for a key and always let you type a model id, because what such a server lists is only advisory: vLLM serves one model and the id must match `--served-model-name`, LM Studio reports local file names, llama.cpp often reports a placeholder, and a LiteLLM proxy reports the aliases in its own config.

> [!CAUTION]
> **Ollama's OpenAI endpoint cannot set the context length**, so a long course prompt can be silently truncated to the server's default. Raise `OLLAMA_CONTEXT_LENGTH` on the machine running Ollama.

**Custom** is the escape hatch: any base URL, with nothing assumed. Only the Groq row was verified against live documentation; for the rest, the probe below confirms what an endpoint really does before any badge claims it.

## Asking an endpoint whether it has a queue

Rather than keep a list of which gateways have a batch API, CourseForge asks: three GETs, and where the answer is still ambiguous one POST of an empty object that every real queue rejects with a validation error. Nothing is created and no chat request is made - which matters for local servers, where naming a model that is not loaded can block for minutes while gigabytes load.

The answer belongs to the **account**, not the preset, because the same address behaves differently per key. It is stored on the account and taken again when the key or address changes, when it is a month old, or when a real submission proves it wrong. Two verdicts earn the feature its keep: *a queue with no upload lane* (Gemini's compatibility layer answers `/batches` and 404s `/files`), and *forbidden, as distinct from absent* (Gemini's queue is paid-tier only, and a 403 must not disable batching for an account that only needs an upgrade).

## Choosing models

- **Outline slot:** your strongest model. It is one request per course, and every page inherits its quality through the scopes.
- **Page slot:** a capable mid-range model is usually the best trade. Try three pages in the tab before starting a run of three hundred.
- **Batch:** if you can wait a day, the batch switch halves the price of the page slot on every provider that has a queue.
- **Local:** a local model is free per token and fine for drafting outlines; make sure its context window holds the course map.

## Not supported

- **AWS Bedrock, Google Vertex AI and Azure** have no account type: each uses an authentication scheme of its own rather than a bearer key. Put a **LiteLLM proxy** in front of them and use the `litellm` preset.
- **Streaming** and **webhooks** - see [Runs](runs.md#what-is-not-supported).
- **Mistral's own batch queue**, which is not OpenAI-shaped. Mistral is live-only.
- **A Claude Pro/Max subscription as an AI account.** Releases 4.0-5.0 offered one, which ran the `claude` CLI on the same machine; it only ever worked on a laptop and was retired in 5.1. To use a subscription with a hosted CourseForge, turn it around: [connect the Claude app or Claude Code over MCP](mcp.md) and let it do the writing.
