---
title: Runs - background and batch
nav: Runs and batches
description: Writing a whole course while the browser is closed - in the background from the scheduler, or through a provider's batch queue at about half price.
---

There are three ways to have CourseForge write pages, and the difference that matters is not live versus batch - it is **browser versus server**.

| | Who does the work | Close the tab? | Price | Needs |
|---|---|---|---|---|
| **In this tab** | the browser, N pages at a time | no | normal | nothing |
| **In the background** | CourseForge, from the scheduler | yes | normal | [the scheduler](scheduler.md) |
| **As a batch** | the provider's own queue | yes | about half | a `:batch` model and the scheduler |

The first is right for three pages you are watching: it starts immediately and you see each page land. The other two are **runs**. A run is written down on the server before any work starts, so it survives the tab, the session and a restart. The Content tab shows it as a card with its own progress; reopen the course tomorrow and the card is still there, still counting.

## Starting a run

On the **Content** tab, choose what to write (all missing pages, the next N, the failed ones, everything) and click the run button. It offers whichever kind the profile is set up for:

- the page model has `:batch` on the end (the **batch queue** switch on [Profiles › Models & output](profiles.md#the-batch-switch)) - the run goes to the provider's queue;
- otherwise - a background run, written by the scheduler.

Until the scheduler is set up and calling in, the Content tab does not offer runs at all, and says why.

## Background runs

A background run is worked by `cron.php`, one tick a minute. Each tick claims a page, writes it with the profile's page model, stores it, and moves on until its time is up; a page is stored the moment it is finished, so a tick cut short by a host time limit loses nothing.

How many pages are written at once is the scheduler's **worker count** (`app.cron_workers`): with two workers, a tick that arrives while the previous one is still busy takes the second slot, and roughly twice as many pages get written. Raise it only as far as your provider's rate limits allow - the failure mode of too many workers is a run full of 429 errors, not a faster course. See [The scheduler](scheduler.md).

A page that fails is retried on a later tick, up to the configured number of attempts, and then marked failed with the provider's reason.

## Batch runs: `:batch`

Provider batch APIs answer within a day instead of straight away, and cost about half as much. CourseForge exposes that through a suffix on the page model:

```text
claude-sonnet-5-5:batch
gpt-6-luna:batch
anthropic/claude-opus-5:batch
```

The suffix is CourseForge's own marker, not part of any provider's model id. It is stripped before the request goes out, only ever from the end, so an OpenRouter slug such as `deepseek/deepseek-r1:free` survives untouched. A profile switches between live and queued generation by editing one string, or by ticking the box.

Regenerating one page from the editor always runs live, whatever the slot says - waiting a day for a page you are looking at would be absurd. The outline is never batched either; it is one call.

### What happens to a batch

<div class="steps">

1. **Submitted.** CourseForge builds every page's prompt, packs them into the provider's queue format - in as many chunks as the provider's limits require - and submits them. The run records the provider's batch id.
2. **Polled.** The scheduler asks the provider how far the batch has got, no more often than the polling interval in **Settings › Batch and runs**. **Check the run** on the card (or `poll_run` over MCP) asks immediately.
3. **Collected.** Finished answers are written into the database as they arrive. A page that came back refused or cut off is marked failed with the reason, exactly as a live page would be.
4. **Closed.** When the provider reports the batch done, the run closes. Failed pages can be retried with **Retry the failed pages**.

</div>

A batch answer that arrives for a page somebody has since written another way is discarded rather than applied, and reported as *already written*.

### Which providers have a queue

| Provider | Queue | Notes |
|---|---|---|
| **Anthropic** | Message Batches | Requests inline in one body. Results can be very large; CourseForge streams them line by line. |
| **OpenAI** | Batch API | Upload a JSONL file, poll, download **two** result files (successes and failures). |
| **Google Gemini** | Batch mode (paid tier) | A long-running operation. **Expires after 48 hours with no results at all** if not finished. |
| **OpenRouter** | Batch beta | Requests inline; answers come back in the status response. No cancel route. |
| **Groq** and probed presets | OpenAI-shaped file queue | Whether a gateway has a queue is asked of the endpoint with your key, not assumed. |
| **Mistral**, local servers | none | Mistral's own queue is not OpenAI-shaped; Ollama, LM Studio, vLLM and llama.cpp have none. |

**Check this account** on the profile records what the queue can do for your key. Whether an account can queue *right now* is three questions: does the adapter implement a queue, did the probe find one for this key, and does the provider say its queue takes this model. The profile's batch switch is disabled with the reason when the answer is no.

### Two deadlines

| Provider | Unfinished work expires | Results downloadable until |
|---|---|---|
| Anthropic | 24 h after creation (unbilled) | 29 days |
| OpenAI and file queues | 24 h (Groq also offers 7 days) | 30 days |
| Gemini | **48 h, and then nothing is returned** | six weeks |
| OpenRouter | 24 h | 30 days |

No provider is durable storage. CourseForge writes answers into its own database as they arrive and polls runs nearest their download deadline first - but only while the scheduler is running.

## Estimating first

Before a large run, the estimate (and `estimate_run` over MCP) answers what the run would do: how many pages, which model and provider, background or queue, and roughly how many input and output tokens. The input figure is **measured** from the real prompts the run would send - sampled across the selection and scaled up - and the answer says how many pages were measured. It gives no price, because CourseForge does not know what your account pays; multiply by your provider's per-token price. The warning worth reading is the one that says the provider has a queue you are not using.

## Stopping a run

**Stop run** on the card:

- A **background** run stops immediately: the run closes, and every page not yet written is released.
- A **batch** is stopped at the provider's pace. The cancel is asynchronous and the provider keeps whatever it finished before the request landed, so the run stays open until the provider reports the batch cancelled. The next check then collects the finished pages, releases the ones that never ran, and closes the run. OpenRouter has no cancel route; a run there stays open and says so.

A finished run's card can be removed with **Remove run**; old run records are cleaned up after the retention period in **Settings › Batch and runs**.

## What is not supported

- **Streaming.** Pages are requested whole and stored when complete; progress is shown per page, not per token.
- **Webhooks.** Batches are collected by polling from the scheduler, which also works on hosts nothing inbound can reach.
- **AWS Bedrock, Google Vertex AI, Azure.** No account type; put a [LiteLLM proxy](providers.md#the-presets) in front of them.

## Over MCP

`estimate_run`, `start_run`, `list_runs`, `get_run`, `poll_run`, `cancel_run`, `delete_run` and `scheduler_status`. **This is the group that spends money at scale** - narrow a connection's scopes if it does not need it. See the [MCP tool reference](mcp-tools.md#generation-runs).
