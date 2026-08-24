# CourseForge 3 — Documentation

CourseForge is a self-hosted single-page application that uses AI - an
OpenAI-compatible provider, Anthropic, OpenRouter, or a Claude Pro/Max
subscription - to design, write and publish complete courses into a
[BookStack](https://www.bookstackapp.com/) knowledge base. It turns a one-line
course brief into a structured book with chapters and pages, writes the actual
teaching content, attaches tags and flashcards, and pushes everything into
BookStack over its REST API.

---

## 1. Using the application

### Signing in

CourseForge is driven by a `data/users.json` file. After five failed attempts
(configurable) your IP is locked out for fifteen minutes. Once signed in you can
change your password from the **account** entry at the bottom of the sidebar;
the new password must be at least eight characters and differ from the old one.

The sidebar holds the three areas — **Courses**, **Tags**, **Profiles** — plus
the theme switch. On screens narrower than 1024 px it collapses into a drawer
behind the ☰ button.

### Step 1 — Create a profile

Open **Profiles** and create one. A profile has three tabs.

**Accounts.** Add one or more *BookStack instances* (base URL, API token id and
secret) and one or more *AI accounts*. Every AI account has a **type**, and the
fields change with it:

| Type | What it needs |
|------|---------------|
| OpenAI-compatible | A base URL ending at `/v1` and an API key. Anything that speaks `/chat/completions`: OpenAI, Azure OpenAI, Groq, Together, DeepInfra, Mistral, vLLM, LM Studio, Ollama. |
| Anthropic API | An `sk-ant-` key. Uses the native Messages API, and the Message Batches queue. |
| OpenRouter | One key for every vendor OpenRouter fronts. Optionally a site URL and app name, which are how OpenRouter attributes requests. |
| Claude subscription (Pro / Max) | Nothing, but it only works when CourseForge runs on the same machine you signed in to Claude Code on. On a hosted install use **Connect** instead — see [section 5](#5-providers-runs-and-the-claude-app). |

**Test this account** proves an account works before a course depends on it: for
the API types it fetches the model list, and for the subscription it reports
whether the CLI is installed, signed in, and signed in to a plan rather than to
an API key.

Secrets are stored server-side and never sent back to the browser: a stored
secret shows as `•••••••• stored`, and leaving the field empty on save keeps it.
The subscription account has no secret to store at all.

**Models & output.** Choose which AI account and model designs the **course
outline** and which writes the **individual pages**, set temperature and a token
ceiling, pick the **course language**, and decide how many pages are written **in
parallel** (default 2, up to 12). The model box is a fuzzy search; *fetch list*
pulls the live model list from the provider.

Under the page slot there is one more switch: **write these pages through the
provider's batch queue**. It appends `:batch` to the model, which is how
CourseForge marks a slot for bulk generation at half price — see
[section 5](#5-providers-runs-and-the-claude-app).

**Prompts.** Every prompt CourseForge sends, in four groups, each documented and
overridable *for this profile only*. See [section 3](#3-the-prompt-library).

### Step 2 — Create a course

In **Courses**, click **New course** and describe what should be taught, to whom
and up to which level. Pick a profile. You land in the workspace, which has five
tabs.

#### Structure

Click **Generate structure**. The AI returns a strict-Markdown outline — one book
title, ordered chapters with descriptions, nested pages — which you can edit
directly in the left pane. The **Request changes** box on the right asks the AI to
revise the existing outline instead of designing a new one; it is told to
reproduce every untouched title character for character, which is what keeps
already-written content attached.

**Apply** parses the Markdown into chapters and pages. Matching happens **by
title**, so applying a revised outline preserves the content, the tags and the
content details of everything whose title did not change. A rename detaches the
content deliberately. If the answer cannot be parsed at all, nothing is changed.

#### Content

Three panes on a wide screen:

- **left** — the outline, with a coloured status dot per page (grey = not written,
  amber pulsing = being written, blue = written, amber = changed since publishing,
  green = published, red = failed), a filter, a search box, and the generation
  controls.
- **middle** — the editor: title, Markdown body in *edit*, *split* or *preview*
  mode, word count, and a link to the published page. `Ctrl+S` saves.
- **right** — the inspector: **details**, **tags** and **context** for whatever is
  selected. Below 1280 px it becomes a slide-over.

Selecting a chapter instead of a page turns the middle pane into a chapter
editor (title and goal) and points the inspector at the chapter.

##### The Markdown editor

The left half of the editor is a full Markdown editor rather than a text box.
Headings, emphasis, links, quotes, list markers and inline code are highlighted
as you type; a fenced block is highlighted in its own language, for a hundred
and twenty-one of them. Two CourseForge-specific things are picked out as well,
so a malformed one is visible before it is published: a cross-reference marker
`(🔗 Exact Title)` and an Anki cloze deletion `{{c1::hidden text}}`.

It behaves the way an editor is expected to. `Ctrl+F` opens find, `Ctrl+Z` and
`Ctrl+Y` undo and redo, `Tab` indents, brackets close themselves, and long lines
wrap instead of scrolling sideways. Undo history belongs to the page: switching
to another page and back cannot undo into the wrong one.

**Selected text** is drawn as a wash of the accent colour and nothing is allowed
to paint over it: the current-line highlight steps aside while anything is
selected, and the other copies of a selected word — which the editor marks for
you — are ringed rather than filled, so the one you actually selected is never
mistaken for one of its echoes.

##### The preview

The right half renders the page the way BookStack will.

- **Code** is highlighted with the same grammars VS Code uses, in 232 languages.
  A fence nobody has a grammar for stays plain — it is never an error.
- **Every block carries a header** naming the language it is being rendered in,
  and three controls that appear when the pointer is over it: **copy**, **line
  numbers** and **line wrapping**. The last two apply to every code block on
  every page, are on by default, and are remembered.
- **Diagrams.** A ` ```mermaid ` block is drawn as you write it. A diagram that
  does not parse shows Mermaid's own message, with the line and column, in place
  of the picture — which is the point of having it here rather than discovering
  it after a publish.
- **Formulas.** `\( … \)` and `$$ … $$` are typeset — the same delimiters the
  prompt library hands the AI and the ones BookStack renders. Until the
  typesetter has loaded the LaTeX itself is shown, never a blank.

A single `$` is left alone, so prices stay prices.

##### Blocks with no language, or the wrong one

An AI writes a lot of fenced blocks and does not always label them. When a fence
says nothing, says `text`, or names something that turns out not to match what
is inside it, CourseForge reads the block and works the language out — in the
preview *and* in the editor, so both halves agree.

It is built to decline. Each language is described by things that are hard to
write by accident — `defmodule`, `#include <stdio.h>`, `let mut`,
`{ get; set; }` — and a guess is only used when one language is well clear of
every other. Braces and semicolons count for almost nothing. Program output, a
directory tree, a table of results or a line of prose stays exactly as plain as
it was, which is the behaviour that matters most: a wrong guess reads as a bug
in the page.

Three different amounts of evidence are required, depending on what is at stake:

| The fence says          | What it takes to override it                    |
|-------------------------|-------------------------------------------------|
| nothing                 | a clear winner                                  |
| `text`, `plain`, `none` | a strong one — the author may have meant it     |
| a language              | an emphatic one, and only for a language the detector knows how to assess |

So ```python holding a JSON document is corrected, and ```python holding
unusual Python is not. Every language that was worked out rather than read off
the fence is marked `auto` in the block's header, and hovering it says what
happened — including which language the fence had asked for.

`tools/detect-test.mjs` is the corpus this is checked against: sixty snippets
that must be recognised, twenty-five that must not be, and the rule that a fence
naming the right language is never overruled. It needs Node and nothing else,
and nothing is generated from it.

##### Linked scrolling

In **split** mode the two halves scroll together, and the ⇅ button beside the
mode switch turns that off and on. The choice is remembered.

The two are matched on positions rather than percentages: every block in the
preview knows which line of the Markdown it came from, and the scroll position
is interpolated between those points. A page with one tall diagram or a long
code block therefore stays aligned, which a percentage of the scroll height
would not. Whichever half you last touched is the one that leads.

The generation panel writes **all missing pages**, the **next N**, only the
**failed** ones, or **rewrites everything**. It runs as many requests in parallel
as the profile allows and can be stopped; pages that were already written are
kept. Each page also has a **feedback** box for a targeted rewrite.

#### Details

The course-wide defaults — see [section 2](#2-content-details). Also shows the
auto-link status and a table of every chapter and page that overrides something,
with a one-click way to make it follow the course again.

#### Publish

Pick the BookStack instance and, optionally, a shelf, then push **everything** or
just the **book metadata**; single chapters and pages can be pushed from the
Content tab. Existing books, chapters and pages are updated in place — nothing is
ever duplicated. Pages without content are skipped. **Force overwrite** re-sends
even unchanged items. A live log shows what was created, updated and skipped.

This tab also holds **Resolve auto links** — see [section 4](#4-auto-links).

#### Settings

Rename the course, change its profile and BookStack instance, switch on **AI
tagging**, manage the course tags, and delete the course.

### Tags

Tags are a reusable library (**Tags** in the sidebar) that can be attached to a
course, a chapter or a page. A tag marked as **inheriting** flows down: from the
course to every chapter and page, from a chapter to its pages. What gets pushed
to BookStack is the *effective* set — own plus inherited.

With **AI tagging** enabled, the model also proposes tags while designing the
outline; they appear in violet and can be deactivated individually without being
deleted. A deactivated tag is ignored everywhere: in prompts, in hashes and in
BookStack. You can restrict the AI to a **tag pool**, optionally strictly.

---

## 2. Content details

Every page is assembled from *elements* that can be switched on or off, and
*values* that size them. Both are set at three levels and inherited downwards:

```
config.json defaults  →  course  →  chapter  →  page
```

Each level either decides or defers. In the UI that is the three-way switch:

| ↳ on / ↳ off | inherit — the arrow shows what the level above currently resolves to |
|--------------|----------------------------------------------------------------------|
| **On**       | force on here and for everything below                               |
| **Off**      | force off here and for everything below                              |

Only deviations are stored. A course with nothing overridden carries an empty
settings object, so changing a default in `config.json` immediately reaches every
course that never disagreed with it.

### The elements

| Element | Default | What it adds to a page |
|---|---|---|
| Learning objectives | off | An opening list of what the reader will be able to do |
| Summary section | on | A closing recap of the key points |
| Practice exercises | on | Hands-on tasks with worked solutions |
| Key terms glossary | off | The terms this page introduced |
| Further reading | off | Pointers to documentation and standards |
| Code examples | on | Fenced, runnable samples with a language identifier |
| Comparison tables | on | Markdown tables, with the pipe-escaping rule |
| Note / Tip / Warning callouts | on | Blockquote callouts |
| Mermaid diagrams | on | ` ```mermaid ` blocks, with the syntax rules BookStack needs |
| MathJax formulas | on | `\( … \)` and `$$ … $$`, the delimiters BookStack renders |
| Emojis | on | A handful, in headings and do/don't markers |
| Anki cloze cards | off | An importable `## Anki Cards` section |
| Auto links | off | Cross references to other pages — [section 4](#4-auto-links) |

Switching an element **off** is not the same as leaving it out: CourseForge sends
an explicit instruction not to produce it, because models default to their own
habits when a topic is simply unmentioned.

### The values

| Value | Default | Placeholder | Used by |
|---|---|---|---|
| Minimum length | 1200 words | `{{min_length}}` | the length contract |
| **Maximum length** | 3000 words | `{{max_length}}` | the length contract |
| Diagrams per page | 2 | `{{diagram_max}}` | Mermaid diagrams |
| Exercises per page | 3 | `{{exercise_count}}` | Practice exercises |
| Anki cards per page | 8 | `{{anki_cards}}` | Anki cloze cards |
| Auto links per page | 5 | `{{link_count}}` | Auto links |
| Audience | — | `{{audience}}` | the audience instruction |

A value box shows your own number, or nothing plus the inherited number as a
greyed placeholder; the ✕ next to it goes back to inheriting. A value whose
element is currently off is dimmed, because it changes nothing.

The catalogue itself lives in `data/config.json` under `details`. Adding a
fourteenth element means adding one entry there plus its two prompt slots — no
code change, no migration.

---

## 3. The prompt library

Every instruction CourseForge sends is a named slot in `data/config.json`, and
every slot can be overridden per profile from **Profiles → Prompts**. The tab
groups them, shows the description of each slot, marks which ones you changed,
and offers clickable chips for the placeholders that slot understands — clicking
one drops it at the cursor.

| Group | Slots | Sent when |
|---|---|---|
| **Global** | 3 | with every request: persona, language reminder, audience |
| **Course structure** | 5 | designing or revising an outline |
| **Page writing** | 7 | writing one page: persona, formatting, length, context, feedback |
| **Content details** | 26 | one *enabled* and one *disabled* text per element |

An override that is deliberately **empty** is honoured: that slot then sends
nothing at all. That is how you switch a whole block of instructions off without
touching the config file. The handful of slots the pipeline cannot work without
(`page_system`, `page_rules`, `page_user`, `overview_user`, …) fall back to a
compact built-in text rather than sending nothing.

How a page prompt is assembled:

```
system  = global_system
        + audience_block          (only when an audience is set)
        + page_system
        + page_rules
        + length_rules            (only when a min or max length is set)
        + feature_<element>_on|off   for all 13 elements, in catalogue order
        + the two hard rules enforced in code
        + language_instruction

user    = page_context_block      (title, description, full outline)
        + page_user               (this page's place in the course)
        + extra_context_block     (only when the page has extra context)
        + page_regenerate_user    (only on a rewrite with feedback)
```

The two hard rules — never emit a level-1 heading, never emit raw HTML — live in
`PageGenerator` rather than in the config, because the rest of the application
depends on them.

`{{c1::…}}` in the Anki prompt is *not* a CourseForge placeholder. Substitution
only replaces names it knows, so Anki's own cloze syntax passes through untouched.

---

## 4. Auto links

Switch **Auto links** on for a course and the AI starts marking cross references
while it writes:

```markdown
The reactive system (🔗 Reactive state with ref and reactive) does this for you.
```

At that moment it is plain text. Nothing is a link yet, and nothing needs to be:
when page 3 is written, page 40 may not exist in BookStack.

After the course has been published, CourseForge walks every page and rewrites
those markers into real links — **programmatically, with no second AI call**:

```markdown
The reactive system ([🔗 Reactive state with ref and reactive](https://books.example/books/vue/page/reactive-state)) does this for you.
```

This runs automatically at the end of every full publish, and on demand from
**Publish → Resolve auto links**. Only pages whose text actually changed are
re-sent.

### How a marker is matched

1. the title exactly as written,
2. a normalised comparison (case, punctuation and spacing ignored),
3. a unique prefix — `(🔗 Reactivity)` finds *Reactivity in depth*,
4. the closest match above a similarity floor.

### What happens in each case

| Situation | Result |
|---|---|
| The target exists and is published | a real link, using the target's **own** spelling |
| The target exists but is not published yet | the marker stays, and resolves on the next publish |
| No such chapter or page | published as plain text `(Title)`, and reported in the log |
| The page references itself | published as plain text |
| The marker sits inside a code block or inline code | left completely alone |

Two properties make this safe to run as often as you like:

- **The stored page keeps the raw marker.** Resolution happens on the way out, so
  it is idempotent, survives a regeneration, and can never corrupt the source
  text. Renaming a target degrades its stale references back to plain text rather
  than pointing them at the wrong page.
- **What is hashed is what is sent.** The "out of sync" badges account for
  resolved links, so a link change is detected exactly once and never loops.

The Content tab shows a link icon on every page that carries markers, and the
header and Publish tab show *resolved / written* for the whole course.

---

## 5. Providers, runs and the Claude app

CourseForge speaks four ways of reaching a model, and offers three ways of
writing a course with one. This section is about choosing between them.

### The four account types

| Type | Endpoint | Batch queue | Credential | Works on a hosted install |
|------|----------|-------------|------------|---------------------------|
| OpenAI-compatible | `POST {base}/chat/completions` | if the gateway has one | API key | yes |
| Anthropic API | `POST /v1/messages` | yes, Message Batches | `sk-ant-` key | yes |
| OpenRouter | `POST /api/v1/chat/completions` | yes, `/api/beta/batches` | OpenRouter key | yes |
| Claude subscription | the local `claude` CLI | no | none | **no — local installs only** |

They differ in more than the URL, and the differences are handled for you:

- **Anthropic** puts the system prompt in its own top-level field rather than in
  the message list, requires `max_tokens` on every request (there is no server
  default), and answers with an array of typed blocks in which the text is not
  necessarily the first one. The current models also reject any `temperature`
  outright; CourseForge knows which, omits it for them, and — for a model
  released after this was written — retries once without it when a request is
  refused for that reason.
- **OpenRouter** can return HTTP 200 with an error in the body, because it
  commits the status code before the upstream vendor has answered. That case is
  detected rather than stored as an empty page.
- **The subscription account** has no temperature and no token ceiling to set.
  Those fields are ignored for it, not silently applied.

An AI account saved by CourseForge 3.0 or 3.1 has no type recorded. It is read
as OpenAI-compatible, which is what it was, so an upgraded profile keeps working
without being touched.

### Three ways to write a course

| | Who does the work | Can you close the tab? | Needs |
|---|---|---|---|
| **In this tab** | the browser, N pages at a time | no | nothing |
| **In the background** | CourseForge, from cron | yes | the scheduler |
| **As a batch** | the provider's own queue, at half price | yes | a `:batch` model |

The first is what CourseForge has always done and is still the right thing for
three pages you are watching: it starts immediately and you see each page land.
It is a loop in the browser, so it stops when the window closes.

The other two are **runs**. A run is written down on the server before any work
starts, so it survives the tab, the session and a restart. The Content tab shows
it as a card with its own progress; reopen the course tomorrow and the card is
still there, still counting. The button offers whichever of the two the course is
set up for, with "or write them now, in this tab" underneath.

Two rules keep the three from tripping over each other:

- A page that belongs to a run cannot be started again — not by a second run,
  not by the in-tab generator, not by the Regenerate button. The database
  enforces it, so two browser tabs cannot both start the same course.
- A batch answer that arrives for a page somebody has since written another way
  is discarded rather than applied, and reported as "already written".

### Setting up the scheduler

Background runs need something to call CourseForge once a minute. Set a secret
in `data/config.json`:

```json
"app": {
  "cron_token": "a long random string",
  "cron_seconds": 50,
  "cron_workers": 2
}
```

Then point your host at it. Most shared hosts give you a box in a control panel
where you paste a URL and choose an interval — all-inkl/KAS, Plesk, cPanel and
DirectAdmin all work this way:

```
https://your-install/cron.php?token=<your cron_token>      every minute
```

On a host with a real crontab, skip the web server and the token:

```cron
* * * * * php /path/to/courseforge/tools/cron.php --quiet
```

Either way `php tools/diagnose.php` tells you whether it is actually running.
Until it is, the Content tab does not offer background runs at all, and says so.

**What a tick does**, in this order and for a reason: it gives back any page
whose worker died, collects finished provider batches, and then writes pages
until it runs out of time. Nothing is unbounded — a tick that is cut short by a
host time limit loses nothing, because every claim is a lease with an expiry and
every page is stored the moment it is finished rather than at the end.

**Parallelism comes from cron itself.** `cron_workers` is the number of ticks
allowed to work side by side; with two, a call that arrives while the previous
one is still busy takes the second slot instead of queueing behind it. Raise it
only as far as your provider's rate limits allow. A tick that finds every slot
busy exits immediately, so calling once a minute is safe whatever the setting.

Rough arithmetic: one worker writes roughly one page a minute, so a 500-page
course finishes overnight on two workers. There is nothing to watch while it
does.

### `:batch` — a whole course at half price

Batch APIs answer within 24 hours instead of straight away, and cost half as
much. CourseForge exposes that through a suffix on the model name:

```
claude-opus-5:batch
gpt-5.2:batch
anthropic/claude-opus-5:batch
```

The suffix is CourseForge's own marker, not part of any provider's model id — it
is stripped before the request goes out. It works the same way for every
provider, so a profile switches between live and queued generation by editing one
string, or by ticking the box in **Profiles → Models & output**.

On OpenRouter the convention happens to be literal: OpenRouter publishes its
batch pricing as separate model slugs ending in `:batch`, and CourseForge lists
them, so picking `anthropic/claude-opus-5:batch` from the dropdown does exactly
what its name says.

Anthropic accepts 100,000 requests per batch and reports which models the queue
will take, so the profile screen can warn before you submit. OpenAI's shape —
upload JSONL, create the batch, download two result files — is also implemented
by Groq, DeepInfra, Azure and a LiteLLM proxy, and is simply absent from vLLM,
Ollama and LM Studio; rather than keep a list that goes stale, CourseForge asks
the endpoint whether it has the queue. OpenRouter takes the requests inline and
hands the results back in the poll.

A batch still needs the scheduler to collect it, unless you happen to have the
tab open when it finishes. Results are stored in CourseForge's own database as
they arrive: every provider deletes batch results after about a month.

Regenerating one page from the editor always runs live, whatever the slot says —
waiting a day for a page you are looking at would be absurd. The course outline
is never batched either; it is a single call.

### Connecting Claude to CourseForge

The other direction. Instead of CourseForge calling a model, a Claude client —
Claude Code, or the Claude desktop app — connects to CourseForge, asks what needs
writing, and writes it.

**This is how you use a Claude Pro or Max subscription with a hosted
CourseForge.** There is no HTTP endpoint anywhere that bills a subscription, and
the subscription account type above only works when CourseForge is running on the
same machine you signed in to Claude Code on. On a server somewhere else, the
answer is to turn it around: Claude runs where you are, CourseForge stores the
work, and no credential ever reaches the server.

**Setting it up.** Open **Connect**, create a connection, and copy the line it
gives you:

```bash
claude mcp add --transport http courseforge https://your-install/api/mcp.php \
  --header "Authorization: Bearer cf3_…"
```

The Claude desktop app's **Settings → Connectors → Add custom connector** takes a
URL and nothing else, so the same screen offers a second form with the token in
the address. That form puts a secret in browser history and server logs; prefer
the header wherever the client allows it.

The token is shown once, on the card that created it, and stored only as a hash.
Lose it and you make another. Each connection is separate, so revoking the laptop
does not touch the desktop, and the **Connect** screen shows when each was last
used.

Claude Code can reach a `127.0.0.1` address. The Claude desktop app cannot — its
custom connectors must be reachable over the public internet, so a self-hosted
CourseForge needs a real hostname or a tunnel for that client.

**The tools.**

| Tool | What it does |
|------|--------------|
| `list_courses` | Every course and how far along it is |
| `get_course` | The outline: chapters, pages, which are written |
| `get_page_brief` | The full writing brief for one page — or for the next unwritten one |
| `write_page` | Store a finished page |
| `get_structure_brief` | The brief for designing or revising an outline |
| `apply_structure` | Replace an outline with new Markdown |

`get_page_brief` is the one that matters. It returns the *same* prompt
CourseForge would have sent a model itself — the persona, the formatting
contract, the resolved content details for that page, the course structure, the
page's place in it, what came before and what comes next. A page written this way
carries the same rules as one written by the API, so a course can be half written
each way and stay coherent.

In practice you say something like *"write the next three pages of my Vue course
in CourseForge"* and Claude loops brief → write → store on its own.

The endpoint speaks JSON-RPC over plain HTTP POST — no streaming, no session
state, nothing to keep between requests. It answers a foreign `Origin` with 403
and an unknown token with 401, and an installation with no connections has no way
in at all.

### Using a Claude subscription on a local install

If CourseForge runs on your own machine — the laptop you use Claude Code on —
there is a second option: an AI account of type **Claude subscription (Pro /
Max)**, which runs the `claude` CLI you have already signed in to.

It never reads, stores or forwards a credential: the sign-in already happened, in
the CLI, under your own account. The alternative — lifting the OAuth token out of
`~/.claude` and posting it directly — is **not implemented, on purpose**.
Anthropic's terms are explicit that OAuth credentials are for ordinary use of
Claude's own applications and that third-party products must use API keys.

Three things go wrong often enough to be worth naming:

- **`ANTHROPIC_API_KEY` in the environment silently wins.** The CLI uses it
  instead of the subscription, with no warning, and the bill lands on the API
  account. CourseForge builds the child environment from scratch and removes it,
  so its own calls are safe — but *Test this account* will still tell you the key
  is there, because everything else on the machine is still affected by it.
- **`proc_open` is disabled** on many hosts. Without it PHP cannot start the CLI.
- **No batch queue and no background runs.** Half-price bulk generation exists
  only on the API surfaces, and the cron worker cannot use this account either.

---

## 6. Installation

### Requirements

- PHP 8.1 or newer (8.2 / 8.3 recommended).
- Extensions: **cURL**, **PDO SQLite**, **mbstring**, **json**, **session**.
- A web server that can serve the directory and run PHP.
- Outbound HTTPS to your AI provider and your BookStack instance.
- A browser with import-map support (Chrome/Edge 89+, Firefox 108+, Safari 16.4+).

Optional, and only for the features that need them:

- **A scheduler** - either a control-panel cron that can call a URL once a
  minute, or a real crontab. Without it, courses are still written, but only
  while a browser tab is open.
- **`proc_open` enabled**, plus the `claude` CLI installed and signed in, for a
  Claude Pro/Max subscription account. This only applies to a CourseForge running
  on your own machine; a hosted install uses **Connect** instead.

No Composer, no npm, no build step.

### Steps

1. **Copy the files** to your web root, or a subdirectory of it. The document
   root is the folder holding `index.html` and `api/`.

2. **Make `data/` writable.** The SQLite database (`data/app.sqlite`, created and
   migrated on first run), the session data and the one-time password hash all
   depend on it.

3. **Set the first credentials** in `data/users.json`. On first start CourseForge
   reads `password_plain`, replaces it with a `password_hash` and rewrites the
   file. Change the default before deploying.

4. **Review `data/config.json`**: `app.name`, the timeouts (AI generation
   defaults to 1800 s), the login policy, and the detail catalogue. Leave
   `app.debug` off in production — it exposes exception details in API responses.

5. **Verify** with `php tools/diagnose.php`, which checks the PHP version, the
   extensions, the paths, the configuration, the user file and the database.

6. **Open the app** in a browser and sign in.

7. **Set up the scheduler**, so courses are written with the browser closed.
   Put a secret in `app.cron_token` and have your host call, once a minute:

   ```
   https://your-install/cron.php?token=<your cron_token>
   ```

   or, with a real crontab:

   ```cron
   * * * * * php /path/to/courseforge/tools/cron.php --quiet
   ```

8. **To let Claude write your courses**, open **Connect** in the app and follow
   the one screen there. Nothing needs editing on the server.

### Upgrading from CourseForge 3.0 or 3.1

Nothing to do. The database gains its new tables and columns on first start, and
an AI account with no type recorded is read as the OpenAI-compatible one it was,
so existing profiles and courses are untouched. Every new key in
`data/config.json` has a default, so an old config file keeps working - copy the
new ones across only if you want background runs (`app.cron_token`) or to change
a default.

### Upgrading from CourseForge 2.x

Point `data/` at your existing installation's data directory (or copy
`app.sqlite`, `users.json` and `config.json` across). On first start CourseForge
adds the new columns and converts the old per-level `anki` flag into the new
content-detail system. Projects, chapters, pages, tags, tag links and profiles —
including stored secrets and prompt overrides — carry over untouched, and
everything the 2.x catalogue did not know about falls back to the config
defaults.

Take a copy of `app.sqlite` first anyway. The migration runs once, guarded by a
`schema_version` row, and never touches a value you have since edited.

### Serving it somewhere other than Apache

CourseForge protects `data/`, `src/` and `tools/` with **`.htaccess`**.
**Nginx, Caddy, IIS and PHP's built-in server ignore `.htaccess` entirely.** On
those you must reproduce the protection yourself or `data/app.sqlite`,
`data/users.json` and `data/config.json` may be downloadable.

The rules do four things:

1. route `/api/…` to `api/index.php`,
2. leave `api/mcp.php` alone, because it is a second front door with its own
   authentication and must not be swallowed by the front controller,
3. deny all access to `data/`, `src/` and `tools/`,
4. deny `*.sqlite`, `*.json`, `*.md`, `*.log`, `*.ini`, `*.txt` everywhere.

`cron.php` sits in the document root and is meant to be reachable: it is the only
thing a control-panel scheduler can call. It is gated on `app.cron_token` and
answers 404 to anything else, including when no token has been configured.

Routing itself is not a hard requirement: the SPA calls
`api/index.php?r=<route>`, and `api/index.php/<route>` works as well, so the app
functions without any rewrite rules. The directory protection is not optional.

```nginx
# Block the private directories outright
location ^~ /data/  { deny all; return 403; }
location ^~ /src/   { deny all; return 403; }
location ^~ /tools/ { deny all; return 403; }

# Block sensitive file types anywhere
location ~* \.(sqlite|sqlite3|db|db-wal|db-shm|json|md|log|ini)$ { deny all; return 403; }

# API front controller. The regex location below matches .php first, so
# api/mcp.php is handed to PHP rather than routed into index.php.
location /api/ {
    try_files $uri /api/index.php$is_args$args;
}

location ~ \.php$ {
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_read_timeout 1800;          # a page generation may take minutes
}
```

The safest option on any server is to **move `data/` outside the document root**
entirely and point CourseForge at it:

```
SetEnv COURSEFORGE_DATA_DIR /var/lib/courseforge      # Apache
fastcgi_param COURSEFORGE_DATA_DIR /var/lib/courseforge;   # nginx
```

Then only `index.html`, `assets/` and `api/` need to be public at all.

### Hardening applied regardless of the server

The session cookie is `HttpOnly`, `SameSite=Lax`, scoped to the install
directory, and `Secure` whenever HTTPS is detected (including behind a proxy that
sets `X-Forwarded-Proto`). Every non-GET request requires a CSRF token. Every
response sends `X-Content-Type-Options: nosniff` and `Cache-Control: no-store`.
Always serve CourseForge over **HTTPS** — API tokens and session cookies travel
with every request.

---

## 7. Technical background

### Shape of the thing

A buildless single-page application plus a thin PHP JSON API. There is no
bundler, no package manager and no framework build step in either half.

```
index.html          import map + six stylesheets
assets/css/         tokens → base → layout → components → prose → editor
assets/js/          main → App → core/ + components/ + views/
assets/vendor/      Vue, marked, DOMPurify, Fuse, CodeMirror, Shiki,
                    Mermaid, MathJax (pinned, see VENDOR.md)
api/index.php       the front controller: every route, in one readable list
src/Support/        Config, Db, Http, Request, Response, Router, Markdown, Text
src/Security/       Session, Auth, Users, LoginThrottle
src/Domain/         Projects, Chapters, Pages, Tags, Profiles, Details,
                    Structure, AutoLinker, LinkIndex
src/Ai/             Completion, Prompt, AiRequest, ModelId,
                    StructureGenerator, PageGenerator
src/Ai/Provider/    Provider + BatchCapable interfaces, the Providers factory,
                    and one class each for OpenAI-compatible, Anthropic,
                    OpenRouter and the Claude CLI
src/Ai/Batch/       the value objects a provider queue is described with
src/Ai/Run/         RunManager, and one driver per way of writing a course:
                    BatchDriver (a provider's queue) and LiveDriver (cron)
src/Mcp/            Server (JSON-RPC transport) and Tools (what it offers)
api/mcp.php         the MCP front door: bearer token, no cookie session
cron.php            the scheduler, over HTTP, for hosts without a crontab
src/Publish/        BookStackClient, Publisher
src/Api/            one controller per resource
data/               config.json, users.json, app.sqlite
tools/diagnose.php  installation check
tools/cron.php         the scheduler, on the command line
tools/detect-test.mjs  the corpus behind the language detector
```

### Frontend

Dependencies are vendored ES modules reached through bare specifiers:

```html
<script type="importmap">
  { "imports": { "vue": "./assets/vendor/vue.esm-browser.prod.js", "@/": "./assets/js/" } }
</script>
```

so application code reads `import { ref } from 'vue'` exactly as it would in a
bundled project, while deployment stays a file copy. The `@/` prefix gives every
module an absolute path to any other, so no file depends on where it sits in the
tree.

State is a single reactive `state` object in `core/store.js` plus a handful of
derived selectors. The rule is: anything two views need lives in the store,
anything one view needs stays in that view. The store never renders and never
imports a component, which keeps the dependency graph one-directional.

All server traffic goes through `core/api.js`, which attaches the CSRF token to
writes, replays a request once after a `419` (the server ships a fresh token with
it), reports a lost session through one callback, and turns any non-ok envelope
into an `ApiError` carrying the server's message.

Styling is a hand-written design system: `tokens.css` defines every colour,
radius, shadow and type step as custom properties for both themes, and nothing
else hard-codes a colour. There is no runtime CSS compiler — the previous version
shipped a 500 KB Tailwind JIT to the browser on every load.

Two Vue details worth knowing. Templates are string templates compiled at
runtime, which is why the full Vue build is vendored. And a literal `{{…}}` must
never appear inside a template: Vue's parser closes the interpolation at the
first `}}`, so example markers are passed in as data instead.

### The editor

Four libraries, none of which is on the critical path. CodeMirror is behind a
`defineAsyncComponent` and arrives when the Content tab first renders; Shiki
fetches one grammar file per language actually shown, out of 232 on disk;
CodeMirror does the same with its stream modes; Mermaid and MathJax are loaded
by the first diagram and the first formula on a page. Signing in costs what it
did before, and so does opening a course. `VENDOR.md` has the measurements and
the reasoning behind each build that was chosen.

`core/markdown.js` is where the three of them meet. It renders the Markdown
synchronously and leaves a placeholder for each thing that needs a library:

| in the Markdown        | what lands in the HTML                  | finished by       |
|------------------------|-----------------------------------------|-------------------|
| ` ```mermaid ` block   | `<div class="cf-diagram">`              | `core/diagrams.js`  |
| any other fenced block | `<pre class="cf-code" data-info>`       | `core/highlight.js` |
| `\( … \)`, `$$ … $$`   | a `cf-math` span or div                 | `core/math.js`      |

Four things about that are not obvious:

**Formulas are lifted out during tokenising, not afterwards.** CommonMark reads
`\(` as an escaped bracket and `x_i` inside `$$` as emphasis, so by the time the
HTML exists the LaTeX would already be mangled. Two `marked` extensions claim
those spans before anything else sees them.

**The sources travel as element text, not in an attribute.** DOMPurify drops any
attribute value containing `-->` or `<` followed by a letter — a defence against
comment breakout, and precisely what Mermaid arrows and LaTeX comparisons are
made of. Text content has no such problem and doubles as the placeholder shown
while the library is still loading.

**A fenced block leaves the Markdown as one `<span class="line">` per line,
which is the shape Shiki's own output has.** The preview swaps one for the
other when the grammar lands, and because both have the same structure, line
numbers are a CSS counter and wrapping is one rule — for highlighted and plain
blocks alike. The header above a block is built in `MarkdownPreview.js` with DOM
calls rather than written into the Markdown, because the sanitiser forbids
`<button>` in generated prose and should go on forbidding it; a constructed
element is not text and there is nothing to sanitise. One delegated listener on
the rendered body serves every block's three controls.

**Sanitising is not skipped for any of them, and the defaults are not trusted
either.** The prose is sanitised with `<style>`, `<form>` and the form controls
forbidden, and with the inline `style` attribute dropped: the preview is
`v-html`-ed into the workspace rather than into an iframe, so any of those is a
full-viewport overlay or a submittable form in the application's own origin, and
the author of the text is a language model. Shiki's output goes through
DOMPurify as well. Mermaid's SVG does too, with `foreignObject` allowed by name
— it is how Mermaid draws node labels, and DOMPurify treats HTML inside SVG as a
namespace escape unless told otherwise; what is inside is still sanitised as
HTML. That last option is the one thing DOMPurify 3.2.4 carries over between
calls instead of resetting, so the other two sanitisers pin it back to the
default rather than inherit it. MathJax is configured without the `require` and
`autoload` packages, which is both what keeps it from fetching extension files
that were never vendored and what removes `\href`, the one TeX macro that can
put a `javascript:` URL on the page.

Both halves of the split view are anchored on `data-src-line`, an attribute
`core/markdown.js` puts on every top-level block. `core/scrollsync.js` reads one
side's position, interpolates through that table and writes the other's. There
is no lock and no timer against the feedback loop: exactly one pane is the
driver at a time, chosen by what the reader last touched, and only the driver's
scroll events are acted on, so a programmatic scroll cannot answer back.

CodeMirror is told which *class* each token gets, never which colour, and
`editor.css` resolves those against the design tokens. The editor therefore
themes itself with the rest of the application and dark ⇄ light needs no
reconfiguration. Shiki does the equivalent by writing both palettes into every
token as custom properties, so switching the theme costs no re-render either.

#### One table for both halves

`core/languages.js` is what stops the two halves of the editor disagreeing about
what ```c# meant. It holds three tables and imports nothing — the preview must
not drag CodeMirror in and the editor must not drag Shiki in — so each side
turns the same data into loaders of its own:

- every Shiki grammar with its display name and the aliases it answers to,
  generated from `@shikijs/langs`;
- the aliases the grammars do not declare but an author writes anyway —
  `golang`, `mysql`, `htaccess` — which is also where Shiki's own odder ones are
  corrected, since `cmd` is not Visual Basic;
- the CodeMirror stream mode that colours the same language in the editor, as a
  file name and an export. There are fewer of those, and some are deliberate
  approximations: Solidity and Zig get the C++ mode, GDScript the Python one. A
  near relative colours strings, comments and numbers correctly and misses a few
  keywords, which beats one undifferentiated grey block.

`core/detect.js` is the other half of the answer and is described under
[the preview](#blocks-with-no-language-or-the-wrong-one). Wiring it into the
editor took one step beyond what `lang-markdown` offers: its `codeLanguages`
hook is handed the word after the backticks and nothing else, which is no use
when the fence is empty. `MarkdownEditor.js` passes its own `parseMixed` wrapper
through the parser's `extensions` instead, which is given the node *and* the
input and can therefore read the block. `lang-markdown` keeps its own wrapper
for embedded HTML; `@lezer/markdown` composes the two, and they never touch the
same node type.

### Backend

Everything enters through `api/index.php`, which turns PHP warnings into
exceptions, guarantees a JSON body even when boot itself fails, enforces CSRF on
non-GET requests, and declares the entire HTTP surface as a list of routes.

`Router` matches path segments, so a path that exists but was called with the
wrong verb produces a `405` with an `Allow` header rather than a misleading
`404`. `Request` parses the body once and validates on demand — every accessor
either returns the promised type or throws a `422`. Errors the client is allowed
to see are `HttpException`s; anything else is logged and masked.

`Runtime::beginLongRequest()` is what makes concurrent generation work: before
any slow AI or BookStack call it closes the session (releasing PHP's exclusive
lock on the session file), removes the time limit and sets `ignore_user_abort`.
Without it every request of the same user would serialise behind the session
lock, and a closed tab would discard a finished page.

### The domain

- **Details** — the catalogue and the inheritance rule. Features are tri-state
  (`-1` off, `0` inherit, `1` on), values are nullable, and `resolve()` walks
  `config → course → chapter → page`. `describe()` returns the three views an
  editor row needs: what this level stores, what it would get without those
  overrides, and what actually applies.
- **Structure** — strict outline Markdown ⇄ data, purely programmatic. Tolerant
  of the ways real models drift (bullets instead of numbers, `1)` markers, bold
  titles) and extracts the `{{Tag}}` markers.
- **Projects** — persistence plus `applyStructure()`, which diffs a parsed outline
  against the existing rows **matching by title** so generated content survives a
  revision, and refuses to delete anything when parsing yields no chapters.
  `tree()` assembles what the UI renders: page summaries, resolved tags, resolved
  details, sync flags and link statistics, and self-heals pages stuck in
  `generating` after a crash.
- **AutoLinker / LinkIndex** — [section 4](#4-auto-links). The transformation is
  pure: text in, text out, with the index as its only input.
- **Tags** — the library, the assignments, inheritance resolution, AI-tag sync
  (which never re-enables a deactivated link nor downgrades a manual one), and
  pruning of orphans.
- **Profiles** — CRUD with server-side redaction and secret merging. Incoming
  data is shaped explicitly rather than recursively merged, so an unknown key is
  dropped and a removed account really is gone.

### Publishing

`Publisher` is idempotent by construction. Every item is created once and updated
afterwards; an item whose hash matches what was last pushed is skipped; an item
that vanished in BookStack is recreated. What gets hashed is exactly what gets
sent — resolved links included — so the badges in the UI never drift from
reality. `BookStackClient` treats only a genuine `404` as "gone": any other error
aborts the push rather than risking a duplicate course.

### Data

Everything lives in `data/app.sqlite` (WAL mode, foreign keys on, 15 s busy
timeout): profiles, projects, chapters, pages, tags, tag links, login attempts,
the run tables, the cron leases, the connected Claude clients, and a `meta` table
holding the schema version. A run outlives every process that touches it - a
provider is allowed a full day, and a five-hundred-page background run takes
hours - so `batch_jobs` holds the run and `batch_items` one row per page, written
before any work starts. Those two kept their original names when live runs were
added to them; renaming a table with data in it buys nothing. Content details are a JSON
`settings` column per level rather than a column per feature, which is why the
catalogue can grow without a migration. Users live in `data/users.json`, and the
prompt library plus the catalogue in `data/config.json`.

### Design principles worth naming

- **Title-based content preservation.** Because generated content is matched to
  the outline by title, the revision prompt is explicitly instructed to keep
  untouched titles byte-identical, and every rename triggers a resync of
  `structure_md`.
- **Prompt transparency.** Every instruction is a documented, editable slot. The
  complete AI behaviour is auditable and tunable without touching code.
- **Store the source, derive the output.** Auto links are resolved on the way out
  rather than written into the page, so the stored text stays the thing the user
  wrote and every transformation is repeatable.
- **Resilience over speed.** Long requests survive a closed browser, killed
  generations self-heal into an `error` state, and an unparsable AI answer is
  refused rather than allowed to destroy existing work.
