# CourseForge 4

A self-hosted tool that turns a one-line brief into a complete course — outline,
chapters, written pages, flashcards — and publishes it into a
[BookStack](https://www.bookstackapp.com/) knowledge base.

```
"Vue.js – complete course from beginner to professional; IDE: PhpStorm"
        │
        ├─ AI designs the outline        20 chapters, 140 pages
        ├─ AI writes each page           in the background, or at half price in a batch queue
        ├─ you steer what goes in        per course, chapter or page
        └─ CourseForge publishes it      into BookStack, links and all
```

You can do all of that in a browser, or you can do all of it from an AI client —
Claude Code, the Claude desktop app, OpenAI Codex, Cursor, VS Code or Gemini CLI.

## What it is made of

No build step, no package manager, no framework runtime to compile at load time.
Copy the directory to a PHP host and it runs.

| Layer      | Choice                                                              |
|------------|---------------------------------------------------------------------|
| Frontend   | Vue 3 as native ES modules through an import map, hand-written CSS  |
| Editor     | CodeMirror 6; Shiki, Mermaid and MathJax in the preview             |
| Backend    | PHP 8.1+, no Composer, one front controller                         |
| Storage    | SQLite, migrated automatically                                      |
| AI         | Anthropic, OpenAI, Google Gemini, OpenRouter, twenty-odd gateways, or a local model |
| Bulk runs  | In the background from cron, or a provider batch queue at half price |
| Publishing | The BookStack REST API                                              |
| Automation | A Model Context Protocol server with the whole application on it    |

## Getting started

```bash
# 1. copy the directory to your web root
# 2. make data/ writable by PHP
# 3. open it in a browser
```

There is no configuration file to edit before the first start. CourseForge
writes `INVITE-CODE.txt` next to `index.html`, the setup screen asks for the code
in it, and the account it creates is the first administrator. Everything after
that — the AI accounts, the scheduler, the security policy, the whole prompt
library — is set from inside the application.

```bash
php tools/diagnose.php     # checks the installation, if you have a shell
```

Then: create a **Profile** (an AI account, a BookStack instance, models,
language), create a **Course**, generate the **Structure**, write the
**Content**, and **Publish**.

Full documentation, including the nginx configuration and the technical
background, is in [docs.md](docs.md).

## What is new in 4

### The whole application, over MCP

CourseForge 3.2 offered a connected client six tools, all of them variations on
"here is a writing brief, give me the page back". That is still here, and it is
still the cheapest way to write a course: the writing happens inside the client,
on your own subscription, and the server never holds a credential.

Version 4 adds the other half — everything the browser can do:

```bash
claude mcp add --transport http courseforge https://your-install/api/mcp.php \
  --header "Authorization: Bearer cf4_..."
```

The endpoint is ordinary streamable HTTP with a bearer token, so it is not a
Claude feature — Codex takes the same connection in `~/.codex/config.toml`:

```toml
[mcp_servers.courseforge]
url = "https://your-install/api/mcp.php"
bearer_token_env_var = "COURSEFORGE_TOKEN"
tool_timeout_sec = 1800
```

The **Connect** screen writes the exact configuration for whichever client you
use, so neither of these has to be typed from memory.

> *"Create a Vue 3 course in CourseForge, design the outline, then queue the
> whole thing to Anthropic's batch queue and tell me what it will cost."*

Create courses, design outlines with the profile's own model, edit pages and
chapters, set content details at any level, manage tags, start and watch
generation runs, publish to BookStack, resolve cross references — and, for an
administrator, manage accounts, change any setting, rotate the cron token, read
the diagnostics and install an update. Seventy-odd tools in ten groups.

A connection can be **narrowed to some of those groups**, so a token that only
writes pages cannot delete a course or read your settings. It inherits the role
of the account that made it, and inherits it *on every request* — demote somebody
and their connections lose the administrator tools immediately, rather than
whenever they happen to reconnect.

The endpoint speaks **both eras of the protocol**. Revision `2026-07-28` rebuilt
MCP as a stateless protocol with no `initialize` handshake and no session; every
client installed today still speaks the older one, and an old client has no way
to fall forward. So CourseForge answers `server/discover` for the new ones and
`initialize` for the old ones, on the same URL, with the same tools.

### Accounts, with roles

An installation is no longer one password in a JSON file.

- The first administrator is created from a **setup screen**, gated on the code
  in `INVITE-CODE.txt`. Somebody who can read that file can already read
  `config/`, so it proves exactly the right thing — and an installation that is
  reachable from the internet before you have finished setting it up is not a
  race you can lose.
- An administrator **creates accounts**, changes anybody's role afterwards,
  disables and deletes them. Deleting an account never silently deletes its
  courses: you choose between removing them and handing them to somebody else.
- Everyone's courses, profiles and tags are their own. An administrator can see
  and manage all of them, and a course opened by an administrator still resolves
  *its owner's* profile, tags and BookStack instance — never theirs.
- The last enabled administrator cannot be deleted, disabled or demoted, because
  an installation with no administrator can only be repaired from the file
  system.

### Everything is configurable in the UI

The configuration is now two layers. `config/defaults.json` ships with the
release and is replaced wholesale by an update. `data/config.json` holds only
what this installation changed, and nothing else touches it — so an update
brings new defaults and new prompt slots along with it without ever stepping on
a decision you made.

Every setting is declared once, in one place, and three things read that
declaration: the Settings screen, which renders a field per entry; the API,
which validates against it; and the MCP tools, which describe and change
settings without a second catalogue that could disagree. A setting added later
appears in all three without a line of frontend code.

That includes the **cron token**, which is what makes background work possible
at all. The Settings screen generates one and hands you the finished URL to
paste into your hosting control panel.

### Updates, from GitHub, in one click

An administrator can check whether a newer release exists and install it —
or turn on unattended updates at, say, five in the morning.

An update takes a backup first, stages the release, replaces the files, runs the
database migration in a *child process* so that a broken release cannot pass by
reusing the current one's connection, and rolls back automatically if the new
version fails to start. The backup is a single archive rather than a live tree,
because a directory full of executable PHP under the document root is an old
version of the application waiting to be served next to the new one.

### More providers, and batch generation that is worth using

CourseForge speaks the **Anthropic Messages API**, **OpenAI**, the **Google
Gemini Developer API** and **OpenRouter** natively — not through a compatibility
shim, because every one of those four reports at least one kind of failure with
an HTTP 200 and a body that has to be read properly. An empty course page is a
worse outcome than a loud error.

Everything else that speaks `/chat/completions` is a **preset**: Groq, DeepSeek,
xAI, Together, Fireworks, Cerebras, DeepInfra, Nebius, Moonshot, Z.ai, Mistral,
a LiteLLM proxy, and the local servers — Ollama, LM Studio, vLLM, llama.cpp —
which never ask for an API key and always let you type a model id by hand.

Batch queues are the point of all this. A course does not need to be written
quickly; it needs to be written well, and a provider's batch queue answers within
a day at about half the price. Write `claude-opus-5:batch`, or tick the box, and
the whole course goes to the queue.

- **Four queue shapes are implemented**, because there is no common one:
  Anthropic's inline Message Batches, OpenAI's upload-a-JSONL-file flow (which
  also serves Groq and every preset whose queue is OpenAI-shaped), Gemini's
  long-running operation, and OpenRouter's beta endpoint.
- **Work is split by bytes, not by row count.** OpenAI's 200 MB input limit binds
  at around 25,000 course prompts — half its 50,000-row cap — so chunking by rows
  silently produces failures.
- **Two expiry dates are tracked, not one:** when the batch dies, and when the
  results stop being downloadable. Gemini's batches expire after 48 hours
  returning *nothing*, and results are kept for between 29 days and six weeks
  depending on the provider.
- **An endpoint is asked whether it has a queue** rather than assumed, with three
  free GET requests. The most useful thing that probe catches is a provider that
  has a batch API but no compatible file upload, which would fail only on submit.
- **`estimate_run` tells you the size of it before you spend anything.**

## What was new in 3

The 3.x notes — the content-detail system, auto links, the prompt library, the
Markdown editor with 232 languages of syntax highlighting, diagrams and formulas
in the preview, and runs that survive a closed browser — are in
[docs.md](docs.md). All of it is still here.

## Upgrading

From **3.x**: copy your `data/` directory across. The database gains its new
tables on first start, `users.json` is imported once into the accounts table and
renamed, and `data/config.json` is reduced to the settings you actually changed
so that the new defaults apply to everything else. An AI account with no type
recorded is still read as the OpenAI-compatible one it was.

From **2.x**: see [docs.md](docs.md). Take a copy of `app.sqlite` first.

## Licence

[MIT](LICENSE). The libraries vendored under `assets/vendor/` keep their own
licences, listed in [assets/vendor/VENDOR.md](assets/vendor/VENDOR.md).
