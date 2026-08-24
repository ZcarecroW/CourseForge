# CourseForge 3

A self-hosted tool that turns a one-line brief into a complete course — outline,
chapters, written pages, flashcards — and publishes it into a
[BookStack](https://www.bookstackapp.com/) knowledge base.

```
"Vue.js – complete course from beginner to professional; IDE: PhpStorm"
        │
        ├─ AI designs the outline        20 chapters, 140 pages
        ├─ AI writes each page           in parallel, resumable
        ├─ you steer what goes in        per course, chapter or page
        └─ CourseForge publishes it      into BookStack, links and all
```

## What it is made of

No build step, no package manager, no framework runtime to compile at load time.
Copy the directory to a PHP host and it runs.

| Layer     | Choice                                                             |
|-----------|--------------------------------------------------------------------|
| Frontend  | Vue 3 as native ES modules through an import map, hand-written CSS  |
| Editor    | CodeMirror 6; Shiki, Mermaid and MathJax in the preview             |
| Backend   | PHP 8.1+, no Composer, one front controller                        |
| Storage   | SQLite, migrated automatically                                     |
| AI        | OpenAI-compatible, Anthropic, OpenRouter, or a Claude subscription |
| Bulk runs | In the background from cron, or a provider batch queue at half price |
| Publishing| The BookStack REST API                                             |

## Getting started

```bash
# 1. copy the directory to your web root
# 2. make data/ writable by PHP
# 3. set the first password
$EDITOR data/users.json

# 4. check the installation
php tools/diagnose.php

# 5. open index.html in a browser and sign in
```

Then, inside the app: create a **Profile** (AI account, BookStack instance,
models, language), create a **Course**, generate the **Structure**, write the
**Content**, and **Publish**.

Full documentation, including the nginx configuration and the technical
background, is in [docs.md](docs.md).

## What is new in 3.2

**Close the browser.** Starting "write all missing pages" used to be a loop in
the browser tab: it stopped when the window did, which is fine for three pages
and useless for five hundred. Now it is a *run* — written down on the server
before any work starts, and carried out by CourseForge itself from cron, or by
the provider's own batch queue. Reopen the course tomorrow and the run is still
there, still counting. Point your host at `/cron.php?token=...` once a minute
and a course writes itself overnight.

**Anthropic, natively.** Not through a compatibility shim: the Messages API as
it actually is, with the system prompt in its own field, `max_tokens` supplied
because it is mandatory, and the answer read out of the content blocks rather
than assumed to be the first one. The models that refuse a `temperature` are
known about, and a model released after this was written that also refuses one
is retried without it rather than failed.

**`:batch` models, at half price.** Write `claude-opus-5:batch`, or tick the box
in Profiles, and a whole course goes to the provider's batch queue instead.
Anthropic's Message Batches, OpenAI's Batch API and OpenRouter's queue are all
spoken natively — and OpenRouter's own `:batch` model slugs are exactly the same
convention, so picking one from the list does what its name says.

**Let Claude write your courses.** Open **Connect**, create a connection, paste
one line into Claude Code or the Claude desktop app. CourseForge then hands
Claude the same writing brief it would have sent a model itself — the course
structure, the page's place in it, the resolved content details — and takes the
finished page back. The writing happens inside Claude, on your own Pro or Max
plan, and the server never holds a credential.

```bash
claude mcp add --transport http courseforge https://your-install/api/mcp.php   --header "Authorization: Bearer cf3_..."
```

There is also a **Claude subscription** account type that drives the `claude`
CLI directly, for a CourseForge running on your own machine. It is local only —
there is no HTTP endpoint anywhere that bills a subscription, and lifting the
OAuth token out of `~/.claude` is prohibited by Anthropic's terms. On a hosted
install, Connect is the answer.

A 3.0 or 3.1 profile keeps working untouched: an AI account with no type
recorded is read as the OpenAI-compatible one it was.

## What is new in 3.1

The Content tab stopped being a textarea next to a rough preview and became a
place you would willingly write a page in.

- **A real Markdown editor.** CodeMirror 6 with syntax highlighting the way an
  IDE does it: headings that read as headings, emphasis that is emphasised,
  line numbers, bracket matching and find. A fenced block is highlighted in its
  own language, and the two markers CourseForge itself writes — `(🔗 Title)`
  cross references and `{{c1::…}}` cloze deletions — are picked out so a broken
  one is visible before it is published.
- **Diagrams and formulas in the preview.** Mermaid draws the ```mermaid blocks
  and MathJax typesets `\( … \)` and `$$ … $$`, both with the delimiters
  BookStack uses — so a diagram that will not parse is caught while the page is
  being written rather than after a publish.
- **Code highlighting in 232 languages**, via Shiki and the same TextMate
  grammars VS Code uses; 121 of them in the editor as well, as you type. Both
  palettes are baked into one render, so switching the theme costs nothing.
- **Blocks whose fence is wrong or missing are worked out from the code.** A
  conservative detector reads the block itself, and only answers when the
  evidence is one-sided — so ```text holding a JSON document is highlighted as
  JSON and a stack trace is left alone. Every guess is labelled as one.
- **A header on every block in the preview**, naming the language, with a copy
  button and switches for line numbers and line wrapping — both on by default,
  both remembered.
- **Linked scrolling.** In the split view the two halves stay on the same
  passage, matched on real positions in the text rather than on a percentage of
  the scroll height — so a page with one long diagram does not drift. The link
  can be switched off, and the choice is remembered.

Everything new is fetched only when it is needed: signing in costs exactly what
it did in 3.0, and a course with no diagrams never downloads Mermaid.

## What is new in 3

- **Content details** — thirteen switchable elements (summary, exercises,
  diagrams, formulas, flashcards, …) and seven values (minimum and **maximum
  length**, diagram count, card count, audience, …), each settable on the
  course, a chapter or a single page, with real inheritance.
- **Auto links** — the AI marks cross references as plain text while writing;
  after publishing, CourseForge rewrites them into real BookStack links without
  calling the AI again.
- **A prompt library you can actually navigate** — 41 slots in four groups, each
  documented, overridable per profile, with clickable placeholder chips.
- **A rebuilt UI** — three-pane workspace on a Full HD desktop, collapsing to a
  single column on a phone, in a dark or a light theme.
- **A rewritten codebase** — namespaced PHP with a declarative router, ES
  modules with explicit imports, and a design system instead of a runtime CSS
  compiler.

A CourseForge 2.x database is migrated in place on first start; nothing is lost.
