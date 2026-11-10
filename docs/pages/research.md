---
title: Research
nav: Research briefing
description: Establish what is true today - the current version, what changed, what was deprecated - once per course, and have the outline and every page read it.
---

Models are trained on the past. A course about a library written from a model's memory teaches the version it remembers, which may be two releases old. **Web research** fixes that, at two levels.

## At page level

Switch the **Web research** element on for a page, chapter or course ([Content details](content-details.md)) and the page is written against what the web says today, with a `## Sources` list at the end. The **Searches per page** value caps how many searches the model may run (0 lets it decide).

That needs a provider with a server-side search tool: Anthropic, Gemini, OpenRouter or OpenAI. OpenAI's batch queue cannot search, so with OpenAI a page has to be generated live to be researched. It is right for *"is this example still the recommended way?"*, and it is paid for on every page.

## At course level: the research briefing

*"Which version of WordPress is this course about?"* is one fact, and every page needs the same answer. Asking two hundred pages to find it separately gets two hundred answers and pays for the search two hundred times. So a course carries a **research briefing**: findings established once, stored with the date they were established, and read from then on by the outline and by every page.

A briefing is headings, bullets and a list of sources:

```markdown
## Versions

- Python 3.14 is the current release; scikit-learn 1.9 and pandas 3 are current.
- PyTorch 2.11 is current.

## Deprecated

- `pandas.DataFrame.append` is gone; use `pd.concat`.

## Sources

- https://scikit-learn.org/stable/whats_new.html
```

### Who can write it

| Who | How |
|---|---|
| **An MCP client** - Claude Code most usefully | `get_research_brief` → it searches with its own tools → `store_research`. The searching happens inside a subscription you already pay for; the server spends nothing. |
| **A provider with a search tool** | Anthropic, Gemini, OpenRouter or OpenAI, when web research is on at course level. |
| **You** | Course › **Details** › **Research**: a split editor with the Markdown on the left and the rendered briefing on the right. |

Nothing downstream can tell the three apart.

### The order matters

With web research on at course level, research comes **before** the outline - the outline is designed from the findings, because a chapter list built around a version that no longer exists cannot be repaired by researching the pages under it. Over MCP, `get_next_step` reports the state `needs_research` until findings are stored.

```text
create_course(web_research: true)
get_next_step        → "needs_research"
get_research_brief   → the assignment: the current stable version and its date, what changed
                       recently enough that a model would get it wrong, what was deprecated
                       and what replaced it, where the documentation lives now
   (the client searches the web)
store_research       → stored, stamped with today's date
get_structure_brief  → the findings are part of the outline prompt
get_page_brief       → every page carries them
```

### It never expires by itself

Every answer says how old the findings are - *stored today*, *stored 12 days ago*, *stored 3 month(s) ago - worth refreshing* - because a six-month-old note about a stable subject still beats a model's recollection, and deciding when it is too stale depends on how fast the subject moves. Storing again replaces what is there.

### It is capped

A briefing is cut at **12,000 characters**, at a line boundary. The cap exists because the briefing rides in the context of every page: twelve thousand characters is about three thousand tokens, and a five-hundred-page course pays for it five hundred times (much of it from the prompt cache, see [Tokens and caching](token-economy.md)).

## The prompt slot

The briefing reaches the prompts through the `research_block` slot in the **Course structure** group, which renders `{{research_block}}` (the findings with their date wrapped around them) or `{{research_findings}}` for a wording of your own. A course with no findings contributes nothing at all, rather than a heading over an empty section.

`app.research_default_searches` (8 by default, under **Settings › General**) is the search budget quoted for researching a whole course when the course names none of its own.
