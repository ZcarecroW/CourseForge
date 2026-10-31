---
title: Architecture
nav: Architecture and contributing
description: How CourseForge is built - a buildless Vue front end, a thin PHP API, an MCP server, SQLite - and how to run its tests and build these pages.
---

CourseForge is a buildless single-page application plus a thin PHP JSON API, a second front door that speaks JSON-RPC for MCP clients, a scheduler endpoint and an endpoint that serves BookStackDev looks. There is no bundler, no package manager and no framework build step in any of them.

::diagram architecture | Four front doors, one code base. Every door calls the same domain classes and the same access checks.

## The layout

```text
index.html            the import map and seven stylesheets
assets/css/           tokens → base → layout → components → admin → prose → editor
assets/js/            main → App → core/ + components/ + views/ + views/admin/
assets/vendor/        Vue, marked, DOMPurify, Fuse, CodeMirror, Shiki, Mermaid, MathJax
                      (pinned; see VENDOR.md)
assets/bookstackdev/  the BookStackDev files, as a wiki receives them

config/defaults.json  shipped settings, content-detail catalogue, prompt library
data/                 config.json (overrides only), app.sqlite, backups/, updates/

api/index.php         the browser's front controller: every route, in one list
api/mcp.php           the MCP front door: bearer token, no cookie session
cron.php              the scheduler over HTTP, for hosts without a crontab
bs.php                BookStackDev looks, to the wikis that may load them

src/Support/          Config, Settings, Db, Http, Request, Response, Router, Runtime, Audit, …
src/Security/         Actor, Access, Auth, Users, Invite, Session, LoginThrottle, Hardening
src/Domain/           Projects, Chapters, Pages, Tags, Profiles, Details, Structure,
                      AutoLinker, LinkIndex, Runs, Targets, Tasks, Transfers, BookStackDev, …
src/Ai/               Completion, AiRequest, CourseContext, PageGenerator,
                      StructureGenerator, ScopePlanner, Research
src/Ai/Provider/      one adapter each for Anthropic, OpenAI, Gemini, OpenRouter and the
                      OpenAI-compatible catch-all, plus Presets and Probe
src/Ai/Batch/         the queue drivers (Anthropic inline, OpenAI file, Gemini LRO,
                      OpenRouter inline) and the JSONL chunker
src/Ai/Run/           RunManager, BatchDriver, LiveDriver
src/Mcp/              Server (both protocol eras), Tools, Tool, Scopes, Resolve, Args, Prompts
src/Mcp/Handlers/     one class per tool group
src/Publish/          BookStackClient, Publisher, TargetPublisher
src/Update/           GitHub, Release, Archive, Updater
src/Api/              one controller per resource

tools/                diagnose.php, cron.php, update.php, deploy.php, router-dev.php,
                      assets.php, docs-data.php, the outline and detector test corpora
tests/                run.php and one *.test.php per subject
docs/                 these pages: Markdown, the generator, images and diagrams
```

## Front end

Dependencies are vendored ES modules reached through bare specifiers in an import map, so application code reads `import { ref } from 'vue'` exactly as in a bundled project while deployment stays a file copy. The `@/` prefix gives every module an absolute path to any other.

**Every asset URL carries the release** (`?v=5.3.0`). With no build step nothing is ever renamed when its contents change, while the caching rules promise `immutable` for a year - so the version stamp is what makes an upgrade reach returning visitors. `php tools/assets.php` writes the stamp into `index.html` after `CF_VERSION` is bumped, and `--check` says whether it was forgotten. Every module is listed in the map individually (a prefix mapping cannot carry a query string), and no module imports another by a relative path (a query string is not inherited). `index.html` is the one file served `no-cache`.

State is one reactive `state` object in `core/store.js` plus a few derived selectors: anything two views need lives in the store, anything one view needs stays in that view. All server traffic goes through `core/api.js`, which attaches the CSRF token, replays a request once after a 419, and turns an error envelope into an `ApiError`.

Styling is a hand-written design system: `tokens.css` defines every colour, radius, shadow and type step for both themes, and nothing else hard-codes a colour. Templates are string templates compiled at runtime, which is why the full Vue build is vendored.

### The editor and the preview

None of the four heavy libraries is on the critical path. CodeMirror arrives with the first tab that needs it; Shiki fetches one grammar per language actually shown, out of 232; Mermaid (vendored as its ES build with chunks, about 860 KB for a flowchart instead of a 5.5 MB single file) and MathJax load with the first diagram or formula.

`core/markdown.js` renders Markdown synchronously and leaves a placeholder for each thing that needs a library - a `cf-diagram` div, a `cf-code` block, a `cf-math` span - which `core/enhance.js` fills in afterwards. Formulas are lifted out during tokenising, before CommonMark can mangle `\(` and `x_i`; sources travel as element text rather than attributes, because DOMPurify drops attribute values containing `-->` or `<`, which is what Mermaid arrows and LaTeX comparisons are made of. Everything is sanitised, Shiki's and Mermaid's output included.

The split view is anchored on `data-src-line`, set on every top-level block; `core/scrollsync.js` interpolates between those anchors, with exactly one pane driving at a time. `core/languages.js` holds the language tables both halves share without either importing the other's library, and `core/detect.js` is the evidence-based language detector.

## Back end

Everything a browser sends enters through `api/index.php`, which turns PHP warnings into exceptions (deprecations are logged, not thrown), guarantees a JSON body even when boot fails, enforces CSRF on non-GET requests and declares the whole HTTP surface as one list of routes - reading that file top to bottom gives you the entire API. `Router` matches path segments and answers 405 with an `Allow` header for a wrong verb. `Request` parses the body once and validates on demand; anything that is not an `HttpException` is logged and masked.

`Runtime::beginLongRequest()` is what makes concurrent generation work: before any slow AI or BookStack call it closes the session (releasing PHP's session lock), lifts the time limit and sets `ignore_user_abort`, so requests of the same user do not queue behind each other and a closed tab does not discard a finished page.

### Security

- **Actor** - who is asking: user name, display name, role, and the `WHERE` fragment every scoped query uses.
- **Access** - "may you reach this?": takes the actor and an id, returns the row, from which the caller reads the owner and carries on. A row you may not reach is reported as missing.
- **Auth** - the session holds a user name and nothing else that matters; role and existence are re-read on every request.

The rule: authorisation reads the actor, data access reads the row's owner, and nothing outside `src/Security/` consults the session.

### MCP

`Server` is the transport and knows nothing about courses: era detection, version negotiation, the `Origin` check, the bearer token, the two response shapes. `Tools` is the registry and `Tool` one tool - definition and implementation in the same object, so they cannot drift. `Resolve` holds the two lines every handler starts with - authorise against the actor, then use the row's owner - and the handlers call the same domain classes as the HTTP controllers.

### Providers

The `Provider` interface promises that `chat()` throws on any failure, including the ones that arrive as HTTP 200. `AiRequest` carries the system text, the shared context segments and the messages; each adapter maps the segments onto its provider's cache mechanism. Capability decisions come from the provider's model metadata where it exists (Anthropic) and from model-id rules where it does not (OpenAI), with a one-time retry on a 400 that blames a parameter.

### Publishing and tasks

`TargetPublisher` is idempotent by construction: every item is created once and updated afterwards, an item whose fingerprint matches what was last pushed to that wiki is skipped, and only a genuine 404 counts as "gone". `Publisher` fans out over destinations and folds their logs and counts into one answer. Since 5.2 a push is a row in `Domain\Tasks`, claimed under a lease and worked one tick's budget at a time; the publisher hands back its position (phase, last chapter and page, the link pass's cursor) so the next slice continues exactly there.

## Data

Everything lives in `data/app.sqlite` (WAL mode, foreign keys on): users, invites, profiles, projects, chapters, pages, tags and tag links, publish targets and items, runs (`batch_jobs`, `batch_items`), tasks and their log, leases, MCP clients, the audit log, update history, and a `meta` table for the schema version and installation facts. Content details are a JSON column per level, which is why the catalogue grows without migrations. The schema migrates itself on first open (schema version 11 in 5.3).

Secrets: passwords as `password_hash()` digests, invite codes and connection tokens as SHA-256 (connection tokens are 32 random bytes and must be looked up by value, which a salted hash cannot do). Provider keys are stored so they can be sent, which is why profiles are redacted on the way out.

## Design principles

- **Title-based content preservation.** Content is matched to the outline by title, so a revision keeps what was written.
- **Prompt transparency.** Every instruction is a documented, editable slot.
- **Store the source, derive the output.** Links are resolved on the way out; the stored text stays what was written.
- **Declare it once.** Settings, tools and the content-detail catalogue each exist in one place that every screen, endpoint and tool reads.
- **Authorise against the actor, read against the owner.**
- **Resilience over speed.** Long work survives a closed browser; an unparsable AI answer is refused rather than allowed to destroy existing work; an update that cannot prove it works is put back.

## Running the tests

No framework, no dependencies - CourseForge installs without Composer, so its tests must run without it too.

```bash
php tests/run.php              # every test (434 in 5.3)
php tests/run.php openrouter   # only the files whose name contains that word
node tools/outline-test.mjs    # the JavaScript outline parser against the PHP one's fixtures
node tools/detect-test.mjs     # the language detector's corpus
php tools/assets.php --check   # every asset URL carries the current version
```

The runner uses a scratch database in the system temp directory, never `data/`. The files worth reading first are the ones whose failure mode is silent: `adapters.test.php` (failures at HTTP 200), `access.test.php` (actor versus owner), `tasks.test.php` (resumable publishing), `jsonl-chunker.test.php`, `typography.test.php` and `course-context.test.php` (page scopes and cache-ordered prompts).

## Releasing

<div class="steps">

1. Bump `CF_VERSION` in `src/bootstrap.php` and run `php tools/assets.php` to restamp `index.html`.
2. Add a "What is new in X.Y" section to `README.md` - it becomes the release notes and the [changelog](changelog.md) page.
3. Tag `vX.Y.Z` and publish a GitHub release. Installations find it through **Administration › Updates**.

</div>

## Building these pages

The documentation is generated from `docs/pages/*.md` by `docs/build.mjs`, which needs Node and PHP and nothing else: Markdown through the vendored marked, code highlighted at build time by the vendored Shiki, and the reference pages (MCP tools, settings, prompt slots, presets, content details) from `php tools/docs-data.php`, which reads the declarations in the code.

```bash
node docs/build.mjs            # writes docs/dist/
php -S 127.0.0.1:8000 -t docs/dist    # or any static file server
```

A GitHub Actions workflow builds and deploys the site to GitHub Pages on every push to `main` that touches the docs or the declarations they are generated from. The build fails on a link to a page or an anchor that does not exist.
