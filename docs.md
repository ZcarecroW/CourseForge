# CourseForge 4 — Documentation

CourseForge is a self-hosted single-page application that uses AI — Anthropic,
OpenAI, Google Gemini, OpenRouter, sixteen more gateways that speak OpenAI's API,
or a Claude Pro/Max subscription — to design, write and publish complete courses
into a [BookStack](https://www.bookstackapp.com/) knowledge base. It turns a
one-line course brief into a structured book with chapters and pages, writes the
actual teaching content, attaches tags and flashcards, and pushes everything into
BookStack over its REST API.

It can be driven from a browser or, over the Model Context Protocol, entirely
from Claude Code or the Claude desktop app. Everything the browser can do, an
MCP client can do — see [section 6](#6-providers-runs-and-the-claude-app).

The shorter statement of the same material is [README.md](README.md). This file
is the long one, and it explains why as well as what.

---

## 1. Using the application

### The first run, and signing in

A CourseForge that has never been used has no accounts. Opening it shows a setup
screen that asks for the code in `INVITE-CODE.txt` — a file CourseForge has just
written next to `index.html`, or into `data/` if it could not write there — and
creates the first administrator from it. That account is signed in by the same
request that creates it. [Section 2](#2-accounts-and-roles) explains what the
code proves and why nothing else is needed at that moment.

After that it is an ordinary sign-in. Five failed attempts (configurable) lock
your IP address out for fifteen minutes. An account whose password was set by an
administrator is asked to choose its own before it can do anything else.

The **account** entry at the bottom of the sidebar is where you change your
display name — the name shown against everything you create — and your password.
A password needs at least ten characters and must differ from the old one. Your
*user name* is the key every row you own is filed under and cannot be changed,
by you or by an administrator; a new name means a new account and a transfer.

The sidebar holds the four areas — **Courses**, **Tags**, **Profiles**,
**Connect** — plus the theme switch, the account entry and sign out. An
administrator gets a second group below the first: **Accounts**, **Settings**,
**Prompts** and **Updates**. On screens narrower than 1024 px the whole thing
collapses into a drawer behind the ☰ button.

### Step 1 — Create a profile

Open **Profiles** and create one. A profile has three tabs.

**Accounts.** Add one or more *BookStack instances* (base URL, API token id and
secret) and one or more *AI accounts*. Every AI account has a **type**, and the
fields change with it:

| Type | What it needs |
|------|---------------|
| OpenAI | An OpenAI API key. The model picker is curated, because `/v1/models` returns embeddings, audio and every fine-tune mixed in with the chat models. |
| Anthropic API | An `sk-ant-` key. The native Messages API and the Message Batches queue. |
| Google Gemini (beta) | A Gemini API key. The native `generateContent` API — see the warning about Google's key migration in [section 6](#6-providers-runs-and-the-claude-app). |
| OpenRouter | One key for every vendor OpenRouter fronts. Model ids carry a vendor prefix, as in `anthropic/claude-opus-5`. |
| One of sixteen presets | A gateway that speaks `/chat/completions`: Groq, DeepSeek, Together, Fireworks, xAI, Mistral, Cerebras, DeepInfra, Nebius, Moonshot, Z.ai, a LiteLLM proxy, and the local servers Ollama, LM Studio, vLLM and llama.cpp. Picking one fills in the base URL and the quirks; the local ones ask for no key at all. |
| Custom OpenAI-compatible endpoint | A base URL you type, with nothing assumed about it. CourseForge probes it for a model list and a batch queue without spending anything. |
| Claude subscription (Pro / Max) | Nothing, but it only works when CourseForge runs on the same machine you signed in to Claude Code on. On a hosted install use **Connect** instead — see [section 6](#6-providers-runs-and-the-claude-app). |

**Test this account** proves an account works before a course depends on it: for
the API types it fetches the model list, and for the subscription it reports
whether the CLI is installed, signed in, and signed in to a plan rather than to
an API key. For anything with a queue it also records what that queue can do
for *this* key, which is a different question from what the endpoint offers.

Secrets are stored server-side and never sent back to the browser: a stored
secret shows as `•••••••• stored`, and leaving the field empty on save keeps it.
The subscription account and the local presets have no secret to store at all.

**Models & output.** Choose which AI account and model designs the **course
outline** and which writes the **individual pages**, set temperature and a token
ceiling, pick the **course language**, and decide how many pages are written **in
parallel** (default 2, up to 12). The model box is a fuzzy search; *fetch list*
pulls the live model list from the provider.

Under the page slot there is one more switch: **write these pages through the
provider's batch queue**. It appends `:batch` to the model, which is how
CourseForge marks a slot for bulk generation at about half price — see
[section 6](#6-providers-runs-and-the-claude-app). It is offered on the page slot
only — the outline is a single call, and queueing one request means waiting a day
for it — and it is disabled, with the reason underneath, when this account cannot
queue. Where the provider names the models its queue accepts, those are listed
beside it with the chosen one highlighted; where it does not, the box says so,
because then a model can only be found unacceptable at submission time.

**Prompts.** Every prompt CourseForge sends, in four groups, each documented and
overridable *for this profile only* — on top of whatever an administrator has set
for the whole installation. See [section 4](#4-the-prompt-library).

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

The same panel offers to hand the work to the server instead, as a **run** — in
the background from cron, or to the provider's batch queue — which is the right
choice for anything you are not going to sit and watch. A run appears as a card
with its own progress and is still there tomorrow. See
[section 6](#6-providers-runs-and-the-claude-app).

#### Details

The course-wide defaults — see [section 3](#3-content-details). Also shows the
auto-link status and a table of every chapter and page that overrides something,
with a one-click way to make it follow the course again.

#### Publish

Pick the BookStack instance and, optionally, a shelf, then push **everything** or
just the **book metadata**; single chapters and pages can be pushed from the
Content tab. Existing books, chapters and pages are updated in place — nothing is
ever duplicated. Pages without content are skipped. **Force overwrite** re-sends
even unchanged items. A live log shows what was created, updated and skipped.

This tab also holds **Resolve auto links** — see [section 5](#5-auto-links).

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

## 2. Accounts and roles

CourseForge 3.x had one password, kept in `data/users.json` and edited by hand
before the first start. 4.0 has real accounts, in the database, with two roles,
created from inside the application. If a `data/users.json` is found it is
imported once — those accounts could do everything, so they arrive as
administrators — and the file is then renamed to `users.json.imported` rather
than deleted, because it is the only copy of the hash and an import that went
wrong should be recoverable by hand.

### The first administrator

A brand-new installation is a web address with no accounts and a form that
creates the first one. Whoever reaches that form first owns the installation,
and a machine that scans for new hosts is always faster than a person. So the
form asks for a code that exists nowhere on the network: `INVITE-CODE.txt`,
which CourseForge writes the first time the setup screen is opened.

That file proves exactly the right thing and nothing more. Reading it needs the
same access as reading `config/` — which is to say, you are the person who put
these files on this server. It proves nothing about who you are, and it does not
need to: at that moment there is nobody to be.

The code is six groups of four from an alphabet with no character that can be
mistaken for another — no `I`, `O`, `0` or `1` — because it is typed by hand,
from a text file, once. Case, spaces and hyphens are normalised away, so pasting
it out of the file works however it arrives.

Four properties are worth knowing before you deploy:

- **Only the hash is stored.** The plain code is in that file and nowhere else,
  the same way a password or a connection token is. If the file is lost before
  it is used, delete the open row from the `invites` table and reload, and a
  fresh code is written.
- **One invite is open at a time.** The file holds exactly one code, so issuing
  a second closes the first — a second open row would be a code nobody can read.
- **It is spent on use.** Creating the account marks the invite used and deletes
  the file, from the install root and from `data/`.
- **The door closes for good.** Once any account exists, the setup route reports
  `needs_setup: false` and refuses to create anything. There is no second chance
  to slip in through it.

**Where the file lands.** CourseForge writes it next to `index.html`, and falls
back to the data directory when the install root is not writable — which is the
normal arrangement on a host that deploys into a document root PHP may only
read. The setup screen names the path it used, because the path is not the
secret and without it nobody knows where to look. If neither directory is
writable the invite cannot be published at all and setup says so; make one of
them writable by PHP and reload.

The shipped `.htaccess` refuses every `.txt` file, so on Apache the code is not
servable even while it sits in the document root. **Nginx, Caddy and IIS ignore
`.htaccess` entirely**, and on those `INVITE-CODE.txt` is a downloadable file
holding the key to the installation until you say otherwise — see
[section 8](#8-installation).

### Making more accounts

**Administration → Accounts** is where an administrator adds people. There are
two ways, and they differ in who ends up knowing the password:

| | How it works | Use it when |
|---|---|---|
| **Create an account** | You set a password, or leave it blank and CourseForge generates one. Either way it is shown once, on the card that created it, and the account is asked to choose its own at first sign-in. | You are handing somebody an account face to face |
| **Issue an invite** | A code is written to `INVITE-CODE.txt` again, with a role and an expiry — 48 hours by default, 30 days at most. The holder creates their own account with it. | You would rather not send a password over a chat |

An invite grants nothing that reading the server's file system did not already
grant, which is why an administrator is allowed to issue one at all. It is not,
though, a recovery mechanism: issuing one needs an administrator session. An
installation where *every* administrator password has been lost is recovered
from the database instead — delete the rows in `users`, and the setup screen and
a fresh code come back.

A password needs at least ten characters — up from eight in 3.x — and is stored
with `password_hash()` at the current default cost, rehashed on sign-in whenever
that default moves. A user name may hold letters, digits, spaces and
`. _ @ + -`, and must start with a letter or a digit.

Sign-in is throttled per IP address: five failures inside fifteen minutes lock
that address out for fifteen minutes, all three configurable under
**Settings → Security**. A missing account still costs one full hash
comparison, so "no such user" and "wrong password" take the same time. A
*disabled* account is told that it is disabled, which is a real answer rather
than a guess to be throttled.

### What the two roles mean

| | A user | An administrator |
|---|---|---|
| Their own courses, profiles, tags, connections | yes | yes |
| Everybody's courses | no | yes |
| Accounts, invites, roles | no | yes |
| Settings, the prompt library, the cron token | no | yes |
| Diagnostics, the audit log, updates | no | yes |

The Administration group only appears in the sidebar for an administrator, and
every route behind it is guarded twice — once by the router, which will not hand
an admin route to a normal account, and once inside the handler, because a
handler that is safe on its own must not be made unsafe by a routing change made
later.

A role is read from the database on **every** request rather than frozen into
the session or into a token. Demote somebody, disable them or delete them and it
takes effect on their next request — including on the MCP connections they have
already made.

### The ownership model, which is the subtle part

This is the thing an administrator will get wrong if nobody explains it, so it
is worth spelling out.

Every row a person owns — course, profile, tag, connection, run — carries their
user name in a column, and every domain call takes that name as its first
argument: `Projects::require($owner, $id)`, `Profiles::data($owner, $id)`. That
is unchanged from CourseForge 3, where there was only ever one account.

What 4.0 adds is a second, separate question. **Authorisation is asked of the
actor; data access is asked of the row's owner.** `Access::project($actor, $id)`
answers "may you reach this?" and hands back the row; the caller then reads
`$row['username']` and carries on exactly as before. So an administrator who
opens somebody else's course resolves *that course's* profile, *that course's*
tags and *that course's* BookStack instance — never their own. Generating a page
there spends the owner's AI account, and assigning a profile to that course is
checked against the owner's library, so an administrator cannot lend their own
API key to somebody else's course by pasting in a profile id.

The two ideas are kept apart deliberately, and nothing outside `src/Security/`
consults the session. A row the actor may not reach is reported as **not found**
rather than as forbidden: telling somebody that course 47 exists but is not
theirs is a small leak that costs nothing to avoid.

### What widens for an administrator, and what does not

The application holds two kinds of list, and they want opposite defaults.

**A course listing is an inventory.** What is being written on this
installation, what is stuck, how much of it there is. That is exactly what an
administrator is for, so **Courses** widens by itself to every account, each row
marked with its owner, with a filter to narrow it back to one.

**Profiles, tags and connections are working sets.** The picker on the course
screen, the twelve tags actually in use, the two machines that can reach this
install. Pouring six accounts' rows into those would bury the ones the person
came for, and a profile they cannot use is a trap rather than an option. So in
the browser these stay the administrator's own unless asked to widen, with
`?owner=name` for one other account and `?all=1` for the whole installation.

That rule is asked on writes as well as on the listing route, because the list a
write returns replaces the screen the caller is looking at: an administrator who
revokes alice's connection acts on alice's row and still gets their own list
back, rather than having the screen swapped underneath them for one they never
opened.

**The MCP tools make the opposite choice for `list_profiles` and `list_tags`.**
For an administrator those return every account's rows, each marked with its
owner, and take an `owner` argument to narrow. A model reading a list has no
screen to lose and is better served by seeing what exists — but if you are
automating against both surfaces, do not assume they answer the same question.

### Deleting an account

Deleting an account never silently deletes its courses. There is no safe default
here — destroying somebody's work by accident is unrecoverable, and leaving it
orphaned is worse — so the dialog counts what the account owns and makes you say
which of the two you mean:

- **transfer** hands the courses, profiles, runs and connections to another
  account, and merges the tag library into theirs by name. A tag whose name is
  already taken there has its links repointed at the existing one and is then
  dropped, because tags are unique per owner and name and a collision has to be
  resolved rather than allowed to abort the transfer half way through.
- **delete** removes all of it, cascading through chapters, pages, tag links and
  run rows.

Destroying anything asks for the user name to be typed first.

Three things are refused outright by the server, whatever the browser sends: you
cannot delete or disable the account you are signed in with, you cannot take
your own administrator rights away, and **the last enabled administrator cannot
be deleted, disabled or demoted**. An installation with no administrator can
only be repaired from the file system, which on a shared host may mean not at
all.

### Handing one course to somebody else

Moving a single course is not one `UPDATE`, and `Domain\Transfers` is its own
class because two front doors do it — the HTTP route and the `transfer_course`
MCP tool — and a transfer that is complete on one and partial on the other is
the kind of difference nobody finds until the data is already wrong.

| | What happens to it |
|---|---|
| The course, its chapters and its pages | move |
| The run history | moves — it is stored against the owner's name, and a course whose runs stayed behind looks to the new owner as though it had never been generated |
| The tag links | are re-pointed at the receiving library, matched by name, and what is missing there is created |
| The profile | is **cleared**, not shared. It carries an API key and belongs to the old owner; the new owner picks one of their own before the course can generate anything |
| The published book | is untouched. It lives in BookStack and CourseForge does not own it — publishing again needs a profile with a BookStack instance of the same name |

Two cases are reported rather than quietly absorbed. A tag that already exists
in the receiving library **with a different value** keeps the receiving
library's value, which changes what the tag says; the value is part of the hash
that decides whether a page is in sync with BookStack, so those pages go out of
sync and the next push rewrites the published tag. And a course with a
**generation run in flight** refuses to move at all: that run is billed to the
old owner's AI account and cannot be handed over with the course. Let it finish,
or stop it first.

There is no button for this in the browser. It is available over MCP and on
`POST api/projects/{id}/transfer`, both administrators only.

### The audit log

One line per administrative act — an account created, a role changed, a setting
saved, a connection issued or revoked, an update installed or rolled back — with
who, when, from which IP address, and whether it came from the web, from MCP,
from cron or from the command line. Five thousand entries are kept and old ones
are trimmed on write. Nothing secret is ever written to it: a setting change
records the key, never the value.

It exists because an installation with several accounts has to be able to answer
"who did that?" long after the fact, and because an unattended update at five in
the morning should leave a trace somewhere a person will actually look.

---

## 3. Content details

Every page is assembled from *elements* that can be switched on or off, and
*values* that size them. Both are set at three levels and inherited downwards:

```
catalogue defaults  →  course  →  chapter  →  page
```

The catalogue is the merge of `config/defaults.json`, which ships with the
release, and whatever this installation has overridden in `data/config.json` —
see [section 8](#8-installation). Everything below it is per course.

Each level either decides or defers. In the UI that is the three-way switch:

| ↳ on / ↳ off | inherit — the arrow shows what the level above currently resolves to |
|--------------|----------------------------------------------------------------------|
| **On**       | force on here and for everything below                               |
| **Off**      | force off here and for everything below                              |

Only deviations are stored. A course with nothing overridden carries an empty
settings object, so changing a default reaches every course that never disagreed
with it — including the new defaults an update brings with it.

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
| Auto links | off | Cross references to other pages — [section 5](#5-auto-links) |

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

The catalogue itself lives in `config/defaults.json` under `details`, as
`features` and `params`. Adding a fourteenth element means adding one entry
there plus its two prompt slots — no code change, no migration. Because it
ships with the release, an update may add elements to it, and anything a course
had already decided survives that, since only deviations are stored.

The Settings screen does not offer the catalogue: it renders the 36 declared
application settings and nothing else, and the detail defaults are a data
structure rather than a field. To change one for the whole installation, put it
in `data/config.json` under `details` — never in `config/defaults.json`, which
the next update replaces wholesale.

---

## 4. The prompt library

Every instruction CourseForge sends is a named slot. There are forty-one of
them, they ship in `config/defaults.json`, and they can be overridden in two
places:

| Layer | Where | Who | Applies to |
|---|---|---|---|
| **Installation** | Administration → Prompts | an administrator | every course, unless a profile disagrees |
| **Profile** | Profiles → Prompts | anybody, on their own profiles | the courses that use that profile |

The profile layer wins. Somebody who edits the wrong one sees no change at all
and no explanation for it, which is why both screens say so at the top, present
the same slots in the same order under the same labels, and mark the ones that
have been changed.

The two layers treat an **empty** slot differently, and the difference is
deliberate:

- a **profile** override that is deliberately empty is honoured — that slot then
  sends nothing at all, which is how you switch a whole block of instructions
  off without touching a file;
- clearing a slot on the **installation** screen removes the override, so the
  shipped default applies again. There is no "send nothing for everybody",
  because that is a decision about one profile's courses rather than about the
  installation.

Both screens show the description of each slot and offer clickable chips for the
placeholders that slot understands; clicking one drops it at the cursor.
Forty-one slots is too many for one long page, so the administrator's screen is
three panes of narrowing scope — group, slot, editor — with a search that
ignores the groups entirely, because the usual question is "where is the one
about diagrams" rather than "show me group three".

| Group | Slots | Sent when |
|---|---|---|
| **Global** | 3 | with every request: persona, language reminder, audience |
| **Course structure** | 5 | designing or revising an outline |
| **Page writing** | 7 | writing one page: persona, formatting, length, context, feedback |
| **Content details** | 26 | one *enabled* and one *disabled* text per element |

The handful of slots the pipeline cannot work without (`page_system`,
`page_rules`, `page_user`, `overview_user`, …) fall back to a compact built-in
text rather than sending nothing, whichever layer emptied them.

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

An MCP client with the `admin` group can read and write the installation layer
with `get_prompts` and `set_prompts`, which is the same catalogue and the same
validation — there is no second list that could disagree with this one.

---

## 5. Auto links

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

## 6. Providers, runs and the Claude app

CourseForge reaches a model in one of six ways, offers three ways of writing a
course with one, and can also be turned around entirely so that Claude does the
writing and CourseForge only stores it. This section is about choosing between
them.

### The six account types

| Type | Endpoint | Batch queue | Credential | Works on a hosted install |
|------|----------|-------------|------------|---------------------------|
| OpenAI | `POST /v1/chat/completions` | yes, upload-a-file | API key | yes |
| Anthropic API | `POST /v1/messages` | yes, Message Batches | `sk-ant-` key | yes |
| Google Gemini | `POST /v1beta/models/{model}:generateContent` | yes, paid tier only | Gemini API key | yes |
| OpenRouter | `POST /api/v1/chat/completions` | yes, `/api/beta/batches` | OpenRouter key | yes |
| A preset, or a custom endpoint | `POST {base}/chat/completions` | if the endpoint has one, which is asked rather than assumed | API key, or none for a local server | yes |
| Claude subscription | the local `claude` CLI | no | none | **no — local installs only** |

The first four have a class of their own. Everything else that speaks
`/chat/completions` shares one class and a table.

### Why four native adapters rather than one compatibility shim

Because all four of them report at least one kind of failure with an **HTTP
200**, and in every case the body that comes back would otherwise be stored as a
course page. A blank page, or one that stops mid-sentence, looks like work that
succeeded and loses the reason it did not — which is a far worse outcome for
this application than a loud error somebody can read. The `Provider` interface
says so as a contract: `chat()` throws on any failure, and "failure" includes
the ones that arrive as a perfectly successful response.

There are four shapes of that, and each adapter has to know its own:

1. **A refusal or a safety block.** Anthropic returns one at 200. Gemini returns
   a 200 with no `candidates` key at all and a `promptFeedback.blockReason`.
2. **An answer cut off by the output ceiling.** Anthropic reports it in
   `stop_reason`; Gemini in `finishReason: MAX_TOKENS`; the OpenAI shape in
   `finish_reason: length`. Every one of them arrives with most of a lesson
   attached, ending in the middle of a sentence, and nothing about the shape of
   the response says so. Gemini adds `RECITATION`, which is a real and frequent
   outcome for long-form educational prose — which is precisely what CourseForge
   asks for.
3. **An upstream error reported at 200 by a gateway that fans out.** Any gateway
   commits its status code before the upstream call happens, so a rate limit two
   hops away comes back as a successful response with an `error` key in it.
4. **A routed provider that failed after it started.** OpenRouter commits the
   200 as soon as the model it routed to starts producing, so a provider falling
   over mid-answer arrives as a choice whose `finish_reason` is `error`, or as a
   top-level `error` object beside a perfectly well-formed response. Their own
   documented read order is error first, `finish_reason` second, content last.

The same judgement is used by the live path and by the batch path in every
adapter — one implementation, so a queued page and a live page refuse exactly
the same answers.

The other differences are ordinary translation work, handled so the generators
never see them.

**Anthropic** puts the system prompt in a top-level `system` field rather than in
the message list, requires `max_tokens` on every request, and answers with an
array of typed blocks in which a `thinking` block may arrive before the text, so
`content[0]` is the wrong thing to read. The newest models reject any non-default
`temperature` with a 400, and the ceiling on the ones that accept it is 1.0
rather than OpenAI's 2.0. Which model is in which camp is **not** decided by
name: the models endpoint reports a `capabilities` object per model, and every
gate in the adapter is read out of it — whether the queue takes this model, how
large an output it will produce, whether a sampling parameter is legal. A list of
model ids written today is wrong within a release or two and fails silently, and
the capability shape has outlived several generations of ids. The listing is
cached for a day, so reading it costs a database row.

**OpenAI** is the reference implementation of the shape everything else copies,
so its adapter inherits the generic one rather than sitting beside it — which
means the preset lane is exercised by the busiest provider on every request and
cannot quietly stop working. What it adds is OpenAI's own: the reasoning models
take `max_completion_tokens` instead of `max_tokens` and reject `temperature`,
`top_p`, the penalties, `logit_bias` and `logprobs` outright rather than ignoring
them. That is decided from the model id, because OpenAI's model list carries no
capability metadata at all; a model released after that rule was written costs
one extra round trip rather than a lost page, because a 400 that blames a
parameter is retried once without it. The model picker is a curated intersection
because `/v1/models` is unfiltered and unordered and mixes embeddings, audio,
image, moderation and every fine-tune in with the chat models — and the curation
applies **only** when the endpoint really is `api.openai.com`, because every
account written before 4.0 carries this type whatever it points at.

**Gemini** is not OpenAI-shaped in any particular. The model id is in the URL
path, authentication is an `x-goog-api-key` header, the conversation is
`contents[].parts[]` with the roles `user` and `model`, there is no system role —
the system prompt is a top-level `systemInstruction` — everything about sampling
and length is nested in `generationConfig` under different names, and the answer
comes back in `candidates[]` rather than `choices[]`.

> **Gemini's API keys are being migrated, and this adapter ships during the
> changeover.** As of 2026-08-24 the code records that unrestricted standard keys
> are already rejected, that the Gemini API is expected to stop accepting
> standard keys altogether in September 2026, and that their replacement — the
> service-account-bound key with an `AQ.` prefix — has an open, unresolved
> cluster of reports of answering `401 ACCESS_TOKEN_TYPE_UNSUPPORTED` on
> `generateContent`. Because of that, the adapter never collapses a Gemini 4xx
> into "authentication failed": it hands you Google's own error envelope
> verbatim — status, numeric code, every `reason` in `details[]` and the raw body
> — because during this window that envelope is the only thing that distinguishes
> a wrong key from a key type the endpoint will not take. This is why Gemini is
> offered as beta. If the caution looks excessive by the time you read it, that
> is the intended outcome.

**OpenRouter** carries the vendor prefix in the model id (`anthropic/claude-opus-5`)
and its own routing suffixes (`:free`, `:nitro`) which are typed in rather than
picked from the list.

**The subscription account** has no temperature and no token ceiling to set.
Those fields are ignored for it, not silently applied.

### The presets

A great many gateways claim OpenAI compatibility, and the claim is true — about
the chat body. Everything around it differs, and differs in ways that are pure
configuration: Groq hangs the whole API under `/openai/v1` and rejects `logprobs`
with a 400 where OpenAI would have ignored it, DeepInfra's shim lives at
`/v1/openai`, Z.ai has no `/v1` segment at all, Ollama takes any key or none.
Twenty classes differing by four strings would each rot on their own schedule.
So there is one class and a table.

Each row carries a base URL, which parameters that gateway 400s on rather than
ignoring, which field the output ceiling is called, what its model list is like,
a documentation link, and two honesty flags. `batch` is `true`, `false` or
`probe` — the last meaning nobody can know without asking with a real key.
`verified` says whether the row was read off live documentation on 2026-08-24 or
written from general knowledge; **only the Groq row is marked verified**, and an
unverified base URL is a suggestion that a probe has to confirm before any badge
in the UI claims anything about it.

The sixteen presets, plus the escape hatch:

| Group | Presets |
|---|---|
| Hosted, queue confirmed | `groq` |
| Hosted, queue unknown until probed | `deepseek`, `together`, `fireworks`, `xai`, `cerebras`, `deepinfra`, `nebius`, `moonshot`, `zai` |
| Hosted, no OpenAI-shaped queue | `mistral` |
| Local / self-hosted | `ollama`, `lmstudio`, `vllm`, `llamacpp` — no queue at all; and `litellm`, which is probed like a hosted one because a proxy has whatever queue its backends have |
| Escape hatch | `custom` — any base URL you type, with nothing assumed |

The local ones never ask for a key and always allow a model id to be typed by
hand, because the list such a server returns is only ever advisory: vLLM serves
exactly one model and the id must match `--served-model-name` exactly, LM Studio
reports local file identifiers, llama.cpp often reports a single placeholder, and
a LiteLLM proxy reports the aliases in its own `config.yaml` rather than upstream
ids. Ollama carries a warning of its own: its OpenAI shim cannot set `num_ctx`,
so a long course prompt can be silently truncated by the server default — raise
`OLLAMA_CONTEXT_LENGTH` on the host.

### Three ways to write a course

| | Who does the work | Can you close the tab? | Needs |
|---|---|---|---|
| **In this tab** | the browser, N pages at a time | no | nothing |
| **In the background** | CourseForge, from cron | yes | the scheduler |
| **As a batch** | the provider's own queue, at about half price | yes | a `:batch` model |

The first is what CourseForge has always done and is still the right thing for
three pages you are watching: it starts immediately and you see each page land.
It is a loop in the browser, so it stops when the window closes.

The other two are **runs**. A run is written down on the server before any work
starts, so it survives the tab, the session and a restart. The Content tab shows
it as a card with its own progress; reopen the course tomorrow and the card is
still there, still counting. The button offers whichever of the two the course is
set up for, with "or write them now, in this tab" underneath. The distinction
that matters is not batch versus live — it is browser versus server.

Two rules keep the three from tripping over each other:

- A page that belongs to a run cannot be started again — not by a second run,
  not by the in-tab generator, not by the Regenerate button. The database
  enforces it, so two browser tabs cannot both start the same course.
- A batch answer that arrives for a page somebody has since written another way
  is discarded rather than applied, and reported as "already written".

Setting up the scheduler is in [section 8](#8-installation), along with what a
tick does and how `cron_workers` turns into parallelism. Until it is running, the
Content tab does not offer background runs at all, and says so.

### `:batch` — a whole course at roughly half price

Batch APIs answer within a day instead of straight away, and cost about half as
much. CourseForge exposes that through a suffix on the model name:

```
claude-opus-5:batch
gpt-5.2:batch
anthropic/claude-opus-5:batch
```

The suffix is CourseForge's own marker, not part of any provider's model id — it
is stripped before the request goes out, and only ever from the end, so an
OpenRouter slug such as `deepseek/deepseek-r1:free` survives untouched. It works
the same way for every provider, so a profile switches between live and queued
generation by editing one string, or by ticking the box in
**Profiles → Models & output**.

**On OpenRouter the convention collides with reality**, and the collision is
resolved rather than lived with. OpenRouter's catalogue contains sixty-one real
model slugs that themselves end in `:batch`. The two readings happen to agree —
the discount comes from using the Batch API, not from the suffix — so the adapter
removes the ambiguity: routing variants never enter the model picker, a slug is
split on its last colon, and what goes upstream is always the plain one. The
`:batch` twins are kept for exactly one purpose, which is reading what the queue
actually costs rather than assuming it is half.

Regenerating one page from the editor always runs live, whatever the slot says —
waiting a day for a page you are looking at would be absurd. The course outline
is never batched either; it is a single call.

### Four queue shapes, because there is no common one

| Provider | Shape |
|---|---|
| **Anthropic** | Requests inline in one JSON body. Nothing to upload, nothing to delete. Results are one JSONL body that may hold 100,000 lines. |
| **OpenAI, Groq, and every probed preset** | Upload the prompts as one JSONL file, point a batch at it, come back for **two** result files. |
| **Gemini** | Not a batch object at all but a long-running Operation, whose progress is under `.metadata` and whose answers appear under `.response` only once `.done` is true. |
| **OpenRouter** | Requests inline to a beta endpoint; the answers come back inside the ordinary status response. Poll and fetch are the same GET made twice. |

Each of those has one detail that costs a course if it is taken at face value.

Anthropic's is the size of the read-back: a course of long pages is comfortably a
gigabyte of prose, and nothing that large may exist as a PHP string, so the
download is driven by a cURL write callback that cuts the incoming bytes into
whole lines and spools them, and the caller gets a generator. Peak memory is one
network chunk plus one page.

The file lane's are all inside a successful response. There are **two** result
files — successes in `output_file_id`, failures in `error_file_id` — and a client
that fetches one reads a partial set as "the provider had nothing to say about
those pages". The download is an HTTP 200 and line 4,000 of it can be a 429. And
`finish_reason` inside a line that is a 200 all the way down still means a page
that stops mid-sentence. The uploaded input file is deleted once the answers are
safely on this side; it counts against the organisation's storage forever
otherwise, and a 500-page course uploads a new one every time it is regenerated.

Gemini's is the vocabulary and the arithmetic: the state string is spelled
`JOB_STATE_SUCCEEDED` in Google's own REST example and `BATCH_STATE_SUCCEEDED` in
the API reference and both are in the wild, so every comparison is made on the
suffix; and the counters in `batchStats` are int64 values serialised as JSON
strings, so `"1200" > 999` is a string comparison in PHP and quietly wrong. Only
the inline lane is built. Google offers a second one that uploads a JSONL file of
up to 2 GB, but reaching it means driving the resumable File API, whose start
request names the upload address in a *response header* — and CourseForge's HTTP
wrapper returns bodies and never exposes response headers. 20 MB of inline
requests is around 2,500 course pages, more than any single run submits.

OpenRouter's is that its create body is stream-parsed, so key order is part of
the contract — `endpoint` and `model` have to be on the wire before `requests` or
an otherwise valid body comes back 400 — and that neither a request cap nor a
byte cap is published. The only evidence that a ceiling exists is a typed
`payload_too_large` error, so the limits are a guess: a submission is chunked
against them, halved once if a chunk is refused, and sent as however many batches
that takes. One run can therefore stand behind several OpenRouter batches, whose
ids live in the handle and are polled as one aggregate state. The file lane
refuses a run that needs more than one batch, and is right to, because its limits
are published numbers.

### Chunking by bytes, not by rows

OpenAI publishes two numbers — 50,000 lines per batch and a 200 MB input file —
and the first is the one everybody quotes. A CourseForge page prompt carries the
entire course context with it and runs to something like 8 KB once it is
JSON-encoded, so 200 MB is reached at roughly **25,000 rows**: for this workload
the file ceiling binds at half the row ceiling, every time. A chunker that
counted rows would build a legal-looking 50,000-line file, upload it, and be told
413 — after the upload, which for a large course is the expensive half.

So chunking is by bytes first, with the row count only as a second bound.
Measuring is left to each adapter, because only it knows its own envelope: OpenAI
wraps each body in `custom_id`/`method`/`url`, Anthropic in `custom_id`/`params`,
and the JSON escaping of the prompt itself is worth several percent on text with
quotation marks or accents in it. The default estimate encodes the prompt
properly and adds a deliberately generous 512 bytes of per-row overhead, because
guessing high costs one extra chunk and guessing low costs the upload. A single
page too large to be sent on its own is refused by name, before anything is
uploaded, rather than as a 413 nobody can trace back to a page.

An endpoint nobody has verified is given OpenAI's own numbers, which are the
conservative choice: everything that copied the API copied these, and a chunk
that turns out to be too big comes back as a 413 that can be retried at half the
size.

### Two expiry dates, not one

Treating them as one loses a course.

| Provider | The batch dies | Results stay downloadable |
|---|---|---|
| Anthropic | 24 h after creation; anything still queued comes back `expired`, unbilled | 29 days from creation |
| OpenAI and the file lane | the completion window, 24 h (Groq also offers 7 d and recommends it) | 30 days |
| Gemini | **48 h, and it returns zero results** — not partial ones, none | six weeks |
| OpenRouter | 24 h; there is no flexible window | 30 days |

The first column is when the provider stops running what is still queued. The
second falls long after the batch has finished and is the download deadline. No
provider is durable storage: the answers have to be pulled across and persisted
before that date, which is why results are written into CourseForge's own
database as they arrive.

Gemini's is the one to watch. A job still pending or running at 48 hours flips to
EXPIRED and yields nothing at all, so the whole submission has to be made again.
Every Gemini handle therefore carries an `expiresAt` 48 hours out so the
scheduler can see the cliff coming, and an expired poll spells the rule out in
its error text.

### Asking an endpoint whether it has a queue

Rather than keep a list of which gateways have a batch API — a list that goes
stale — CourseForge asks. The probe is three GETs, and where the answer is still
ambiguous one POST of an empty object that every real queue rejects with a
field-level validation error. Nothing is created and nothing is a chat request:
probing with a POST to `/chat/completions` would be worse than useless against a
local server, where naming a model that is on disk but not resident makes LM
Studio or llama-server block for minutes while gigabytes load.

It produces one of five verdicts, and the two that earn the whole feature are:

- **a queue with no upload lane.** Gemini's OpenAI compatibility layer answers
  `/batches` and genuinely 404s `/files`, so the file-JSONL driver can never work
  there however healthy the queue looks. Without this step that endpoint would be
  badged as batch-capable and fail at the first upload, hours into a course.
- **forbidden, as distinct from absent.** Gemini's real queue is paid-tier only.
  Caching a 403 as "unsupported" would disable batching permanently for an
  account that only needed an upgrade.

The answer belongs to the **account**, not to the preset — the same base URL
behaves differently per key — so it is stored on the account row and re-taken
when the key or the URL changes, when it is a month old, or when a real
submission comes back 404 and proves it wrong. Sizes are deliberately not probed:
no free call reveals how large a file a queue will swallow, and guessing from a
successful small upload would be guessing.

Whether a given account can queue *right now* is therefore three questions, not
one: does this class implement a queue, did the probe find one for this key, and
does the provider say its queue takes this model. A badge drawn from the preset
table alone would be wrong for every gateway whose queue turns out to be
per-account.

**`estimate_run`** answers the same question with numbers attached: how many
pages, which model and provider, whether it would go to the background worker or
to the queue, and roughly how many input and output tokens are involved. The
input figure is measured from the real prompts — the same ones the run would send
— sampled across the selection and scaled up, and the answer says how many pages
were measured and at how many characters to the token. It gives no price, because
CourseForge does not know what an account pays and a confident number in the
wrong currency is worse than a token count. The warning worth reading is the one
that says the provider has a queue nobody is using.

### What is not supported, and why

- **Streaming.** The shared HTTP wrapper buffers every response, by design.
  CourseForge asks for whole pages, stores them when they are complete, and shows
  progress per page rather than per token; nothing in the application has a place
  to put a partial one.
- **Webhooks.** No provider callback is ever received. Batches are collected by
  polling from the scheduler, which is the only mechanism that also works on a
  host where nothing inbound can reach the application.
- **Mistral's own batch queue.** It exists, at `/v1/batch/jobs`, and it is not
  OpenAI-shaped. Mistral is therefore a sync-only preset in 4.0.
- **vLLM's "batch".** An offline CLI, not an HTTP queue. The same goes for
  Ollama, LM Studio and llama.cpp, which have no queue at all.
- **AWS Bedrock, Google Vertex AI and Azure.** There is no account type for any
  of them. Each fronts models with an authentication scheme of its own rather
  than a bearer key on an OpenAI-shaped path, so none fits either the preset
  table or an existing adapter. The supported route is a **LiteLLM proxy** in
  front of them, which is what that preset is for. Note that CourseForge 3.2's
  documentation listed Azure among the endpoints with an OpenAI-shaped queue;
  4.0's preset table does not, and the table is what the code reads.
- **Lifting the OAuth token out of `~/.claude`.** See below.

### Using a Claude subscription on a local install

If CourseForge runs on your own machine — the laptop you use Claude Code on —
there is an AI account of type **Claude subscription (Pro / Max)**, which runs the
`claude` CLI you have already signed in to.

There is no HTTP endpoint that bills a subscription. `api.anthropic.com` bills an
API key or a Console workspace, and the only sanctioned subscription-backed
programmatic surface is Claude Code itself, run locally by the subscriber. So
CourseForge runs `claude -p` as a child process. It never reads, stores, forwards
or displays a credential of any kind: the sign-in already happened, in the CLI,
under your own account. The alternative — lifting the OAuth token out of
`~/.claude` and posting it directly — is **not implemented, on purpose**.
Anthropic's terms are explicit that OAuth credentials are for ordinary use of
Claude's own applications and that third-party products must use API keys.

The consequence is that this provider is for one person running their own copy.
It bills whoever is signed in on the server, so a multi-user install has to stay
on API keys.

Four things go wrong often enough to be worth naming:

- **`ANTHROPIC_API_KEY` in the environment silently wins.** The CLI uses it
  instead of the subscription, with no warning, and the bill lands on the API
  account. CourseForge builds the child environment from scratch and removes it —
  along with a dozen relatives, including the variables that would redirect the
  CLI to Bedrock, Vertex or Foundry — so its own calls are safe. *Test this
  account* will still tell you the key is there, because everything else on the
  machine is still affected by it.
- **`proc_open` is disabled** on many hosts. Without it PHP cannot start the CLI.
- **The binary is not free text.** The path on the account is ordinary profile
  data, editable by anyone who can sign in, so treating it as a command to
  execute would turn an authenticated session into arbitrary execution on the
  server. The file name has to be the Claude CLI, and an absolute path is honoured
  only when `app.claude_cli_allowed_paths` lists it.
- **No batch queue and no background runs.** Half-price bulk generation exists
  only on the API surfaces, and the cron worker cannot use this account either.

### Connecting Claude to CourseForge

The other direction. Instead of CourseForge calling a model, a Claude client —
Claude Code, the Claude desktop app, Cursor, VS Code — connects to CourseForge
over the Model Context Protocol and drives it.

**This is how you use a Claude Pro or Max subscription with a hosted
CourseForge.** There is no HTTP endpoint anywhere that bills a subscription, and
the subscription account type above only works when CourseForge is running on the
same machine you signed in on. On a server somewhere else the answer is to turn
it around: Claude runs where you are, CourseForge stores the work, and no
credential ever reaches the server.

CourseForge 3.2 offered six tools, all of them variations on "here is a writing
brief, give me the page back". That is still here and is still the cheapest way
to write a course. 4.0 adds the other half — everything the browser can do.

**Setting it up.** Open **Connect**, create a connection, choose which tool
groups it may use, and copy the line it gives you:

```bash
claude mcp add --transport http courseforge https://your-install/api/mcp.php \
  --header "Authorization: Bearer cf4_…"
```

The Claude desktop app's **Settings → Connectors → Add custom connector** takes a
URL and nothing else, so the same screen offers a second form with the token in
the address:

```
https://your-install/api/mcp.php?token=cf4_…
```

That form puts a secret in browser history and server logs; prefer the header
wherever the client allows it. It exists because a connector that cannot be added
is worse than one whose secret is in a URL — but it is a genuine trade-off rather
than a free convenience.

The token is shown once, on the card that created it, and stored only as a
SHA-256 digest. Lose it and you make another. Each connection is separate, so
revoking the laptop does not touch the desktop, and the **Connect** screen shows
when each was last used and how often. A connection may also be given an expiry,
up to a year.

Claude Code can reach a `127.0.0.1` address. The Claude desktop app cannot — its
custom connectors must be reachable over the public internet, so a self-hosted
CourseForge needs a real hostname or a tunnel for that client.

### The ten tool groups

There are seventy-seven tools. They are not listed here, because `tools/list`
lists them and every one carries its own description, its arguments and an
annotation saying whether it reads, writes, destroys or spends money. What is
worth knowing is the shape.

| Group | Tools | What it is for | Spends |
|---|---|---|---|
| **account** | 6 | Who you are connected as, your own display name and password, your own connections. Never anybody else's. | no |
| **courses** | 5 | List, read, create, rename and delete courses | no |
| **structure** | 5 | The outline: read the brief, write one, preview it, or have CourseForge design it | yes |
| **pages** | 7 | Read a writing brief, store a finished page, edit a page or a chapter, or have CourseForge write one | yes |
| **details** | 4 | The thirteen switchable elements and seven values, at course, chapter or page level | no |
| **tags** | 8 | The tag library and what is tagged with what | no |
| **runs** | 8 | Start, estimate, watch and cancel background and batch runs. **This is the group that spends money at scale.** | yes |
| **profiles** | 9 | AI accounts, models and BookStack instances. Keys are never readable, but they can be replaced. | no |
| **publish** | 4 | Push into BookStack and resolve cross references | no |
| **admin** | 21 | Accounts, settings, the cron token, prompts, diagnostics, the audit log, every connection, and updates. Administrators only. | no |

The ten or so that matter:

| Tool | What it does |
|---|---|
| `whoami` | The account this connection belongs to, its role, and what this installation can currently do — including whether the scheduler is running, so background runs are possible. The first thing to call. |
| `list_courses` | Every course this account can see, with its progress. An administrator sees every account's, each marked with its owner. |
| `create_course` | An empty course from a one-line brief. It has no outline yet. |
| `generate_structure` | CourseForge calls the course's own model and designs the outline. Matching is by title, so a new outline over a written course would delete every page it does not name — when that would happen the outline is handed back to look at instead of being applied, and applying it afterwards costs nothing, so the model is never called twice over the same decision. |
| `get_page_brief` | The complete writing brief for one page, or for the next unwritten one. Costs nothing: no model is called. |
| `write_page` | Stores the Markdown of one page, and says how many are still unwritten, so a loop knows when it is finished. |
| `estimate_run` | What `start_run` would do with the same arguments, without doing it. Free, because the prompts it measures are built and thrown away. |
| `start_run` | Queues a set of pages on the server and returns at once. Survives the client disconnecting, the session ending and a restart of the server. |
| `poll_run` | Asks the provider *now* whether a batch has finished and writes home everything that has, instead of waiting for the next scheduler tick. Safe to call as often as you like. |
| `publish_course` | Pushes the course into BookStack: creates the book, puts it on the shelf, creates or updates every chapter and page with its effective tags, skips what has not changed, and resolves cross references afterwards. |
| the admin ones | `list_settings` / `set_settings` / `reset_setting`, `generate_cron_token`, `list_users` / `create_user` / `update_user` / `delete_user`, `issue_invite`, `transfer_course`, `get_diagnostics`, `get_audit_log`, `list_connections` / `revoke_connection`, `get_prompts` / `set_prompts`, and `check_for_update` / `install_update` / `rollback_update`. |

### The two ways to write a course, and what each costs

This is the most important thing on this surface, and it is the one decision a
model cannot work out from a tool description — which is why the server says it
in its own instructions rather than leaving it to be inferred.

**You write it.** `get_page_brief` hands you the *same* prompt CourseForge would
have sent a model itself: the persona, the formatting contract, the content
details resolved for that page, the course structure, the page's place in it,
what came before and what comes next. You write the page and store it with
`write_page`. **This spends nothing on the server.** The work happens inside the
Claude application, on your own subscription, and CourseForge never holds a
credential. A page written this way carries exactly the same rules as one written
by the API, so a course can be half written each way and stay coherent.

In practice you say *"write the next three pages of my Vue course in
CourseForge"* and Claude loops brief → write → store on its own.

**CourseForge writes it.** `start_run` queues every unwritten page against the
course's own AI account and keeps going after you disconnect — through the
background worker, or through the provider's batch queue at about half price.
This spends real money on somebody's provider account, and at scale: five hundred
pages is five hundred requests. Call `estimate_run` first.

The first is free and bounded by your attention. The second costs money and is
bounded by nothing you are watching. `generate_page` and `generate_structure` sit
between them: one call each, billed to the profile, blocking while the model
works.

### Scopes

A connection may be narrowed to some of the ten groups, so a token that only
writes pages cannot delete a course or read your settings. Four rules hold it
together:

- **Scopes narrow, they never widen.** Every tool runs as the actor the token
  resolves to, through the same `Access` checks the HTTP API uses. A connection
  with no groups chosen gets everything its account is allowed — which is what a
  connection created without thinking about it should do.
- **`admin` is gated on the account, not on the token.** A normal account's
  connection cannot have it, whatever the token asks for, and there is no way to
  grant it.
- **`account` is never narrowed away.** It holds nothing but "who am I", "what
  are my own connections" and "change my own password", and a connection that
  cannot answer the first of those is harder to use for no gain in safety.
- **The role is read on every request.** It is not frozen into the token, so a
  demotion or a disabled account takes effect immediately rather than whenever
  the client happens to reconnect.

A tool the connection cannot see is reported as **unknown** rather than as
forbidden. The model does not need to learn that a tool exists which it may not
call — it needs to stop trying, and an unknown-tool answer does that without
teaching it anything.

**There is deliberately no tool that creates a connection.** A token able to mint
another token could mint one carrying scopes wider than its own, and every
narrowing on the Connect screen would become a formality — the way past it would
be one tool call. Issuing a connection stays in the browser, where a person is
present to decide what it may do and to take the token, which is shown exactly
once.

### One URL, two eras of the protocol

MCP was rebuilt in revision **2026-07-28**, and the change is not a detail. The
protocol is now stateless: there is no `initialize` handshake, no
`notifications/initialized`, no session id, no GET stream, and no way for a
server to start a conversation. Every request carries its own protocol version
and client capabilities in `_meta`, and a server either serves that version or
refuses that one request.

Meanwhile every client actually installed on somebody's machine today still
speaks one of the four legacy revisions, and **a legacy client has no way to fall
forward**. So CourseForge answers both, on one URL, with the same tools:
`server/discover` for the modern clients, `initialize` for the older ones. Which
era a request belongs to is read from the request itself and never remembered,
which costs nothing here — a PHP application that answers each POST and forgets
it was always the shape the new revision has now standardised.

The differences that change bytes on the wire:

| | Modern (2026-07-28) | Legacy |
|---|---|---|
| Handshake | none; `server/discover` is optional | `initialize`, answered with the client's own revision |
| Every result | carries `resultType` | does not |
| A cacheable list | carries `ttlMs` (ten minutes) and `cacheScope: private` | does not |
| An unknown method | HTTP 404 | HTTP 200 with a JSON-RPC error |
| `serverInfo` | inside `_meta` | top level |
| `Mcp-Session-Id` | not minted, echoed or required | likewise |

The unknown-method answer is the one that has to be right, because it is the
first thing a dual-era client tries: a modern client reads 404 as "not this era,
fall back", and a legacy client reads any 4xx as a dead server and gives up. The
cache scope is `private` and not negotiable, because the tool list depends on the
account and the connection's scopes, so one client's list must never be served to
another from a shared cache.

Two smaller rules. A request with no version at all is treated as `2025-03-26`,
which is what the specification allows a server supporting older clients to do.
And where the modern routing headers `Mcp-Method` and `Mcp-Name` are present they
must match the body — a load balancer routing on the header while the server acts
on the body is a security problem rather than an inconsistency — but a client that
sends no header creates no such disagreement and is not refused for it.

The transport itself is plain HTTP POST. `GET` and `DELETE` answer 405: one was
the server-to-client stream and the other was session teardown, and neither
exists any more. A foreign `Origin` is refused with 403, an unknown token with
401, and an installation with no connections has no way in at all.

### What a large tool surface costs a client

Seventy-seven tools is a lot to put in front of a model, and it is not free.

Every tool's name, description and input schema is sent to the model on the
requests where the surface is in play, and that is context the conversation does
not get to use. This is the main reason to narrow a connection's scopes even when
you trust it completely: a token for writing pages that carries the `pages`,
`courses` and `account` groups puts eighteen tools in front of the model instead
of seventy-seven.

Two limits are worth knowing about because CourseForge works around them rather
than hitting them:

- **Claude Code truncates a server's instructions at two kilobytes.** The text
  the endpoint sends about itself therefore puts the useful part first and stays
  short — it names the two ways a course gets written, because that is the one
  decision a model cannot work out from a tool description, and little else.
- **Claude Code truncates a tool result at 25,000 tokens by default.** A finished
  course goes through that several times over. A tool that can legitimately
  return a great deal declares a larger ceiling in the `_meta` slot the protocol
  reserves for client hints — `get_course` with `include_content` is a whole book
  — rather than being silently cut off half way through a page.

The tool list itself may be cached by a modern client for ten minutes, which is
right: the surface only changes when the installation is updated.

---

## 7. Updates

CourseForge can replace itself with a newer CourseForge from GitHub, from
**Administration → Updates**, from `php tools/update.php --install`, or
unattended at a fixed hour.

This is the most dangerous thing in the application, because the files being
replaced are the files doing the replacing, and because the person who finds out
that it went wrong is an administrator looking at a blank page with no
application left to explain it. Everything below is arranged around that one
fact.

### Checking

A check reads two endpoints on `api.github.com` — the latest release, or a page
of the twenty most recent when the channel is set to include pre-releases — and
caches the answer for an hour. A failed check backs off for fifteen minutes.

Both windows exist for the same reason. **GitHub allows sixty unauthenticated
requests an hour per IP address.** An Updates screen that asked on every load
would spend the whole allowance in an hour of ordinary use, and the scheduler
asks once a minute; without the failure window, a repository name with a typo in
it would call GitHub sixty times an hour for ever to be told the same thing.
With it, a check that cannot succeed costs four calls an hour.

The **Check now** button forces the network call, because that is the one moment
the person is entitled to a real answer. Installing always asks afresh as well:
acting on an hour-old cache is how an installation ends up downloading a release
that has since been pulled.

A 403 from GitHub is usually the rate limit, and the only useful thing to say
about a rate limit is when it lifts, so the reply's `x-ratelimit-reset` header is
read and reported as a time. Set `updates.github_token` to raise the allowance,
or to reach a private fork.

### Preconditions

Every condition is answered separately, and before anything is downloaded,
because "the update failed" is not a useful sentence. An administrator who is
told that the install directory is not writable can go and fix that.

| Check | Blocking | Why it is there |
|---|---|---|
| Updates are switched on | yes | `updates.enabled` off means CourseForge never contacts GitHub and never replaces its own files |
| A repository is configured | yes | there is nowhere to fetch from otherwise |
| PHP can open a zip archive | for a zip release | releases are zips; a fork shipping a tarball needs `ext-phar` instead, which the archive layer reports for itself |
| PHP may write to the installation | yes | it is about to rewrite the document root, and a deploy that runs as a different user than PHP is the usual reason this fails |
| PHP may write to the data directory | yes | the download, the staging tree and the backup all live under `data/` |
| No update is already running | yes | one at a time, across every tab and every cron worker |
| A newer version is available | yes | reinstalling the same version is not an update |
| No generation run is in flight | **no** | a warning. Pages are stored as they finish so nothing is lost, but a worker mid-request is cut off by the swap and its page retried on a later tick |
| There is room for the download and a backup | **no** | a warning, sized at three times the release: download, extraction, backup |

`php tools/update.php --status` prints the same rows, with `OK`, `WARN` or
`FAIL` against each. The command-line tool is also the only way in when the web
application has stopped answering.

### What an install actually does

1. **Download** the release asset, or GitHub's generated zipball when the
   release publishes no asset, straight to a file handle rather than into a
   string — the memory limit on a shared host is the first thing an update would
   otherwise hit. Only GitHub's own hosts are ever fetched from, whatever the
   release document says.
2. **Verify** the digest if one was published. A missing checksum is allowed
   through and the log says so, because refusing without one would make the
   feature useless for most forks; a checksum that was published and does not
   match is never allowed through, and "there was none" and "there was one and
   we never saw it" are logged as the different facts they are.
3. **Extract** into `data/updates/`. The top-level directory of a zipball is
   named after the repository and the commit, so it is found by looking rather
   than by guessing, and every entry name is checked before anything is written
   — a zip may contain `../../etc/passwd`, and a library that extracts it
   faithfully is doing exactly what it was asked to.
4. **Confirm it is CourseForge** by looking for `src/bootstrap.php`,
   `config/defaults.json` and `api/index.php` in the staged tree.
5. **Warm up.** Every class the rest of the update needs is loaded now, while
   the old files are still on disk. A class first autoloaded *after* the swap
   would be read from the new release and dropped into a process full of the old
   one, which is a mixture nobody has ever tested.
6. **Back up, completely, before replacing anything** — see below.
7. **Swap**, file by file.
8. **Prove it works**, and roll back if it does not.

The install root is never swapped wholesale. Unpacking beside the installation
and renaming the two directories is the obvious design and the wrong one here:
the document root is usually not something PHP may rename, because it is what
the web server is pointed at and its parent belongs to the hosting account
rather than to the site — and the script performing the rename is inside the
directory being renamed, which on some platforms pulls the ground out from under
the running request. Walking the release file by file is slower, entirely
undramatic, and can be undone.

**`data/` is never touched, whatever the archive contains.** That is the whole
bargain of the two-layer configuration. `INVITE-CODE.txt`, `.git` and `.github`
are protected the same way: the invite code is generated on this server and
exists nowhere else, so losing it would lock the first administrator out, and
repository plumbing has no business in a document root.

### Why the backup is one archive and not a tree

The backup is written and **closed before a single file is replaced**. Which
files the swap will touch is known before it touches any of them, so a swap that
dies half way through is covered completely rather than up to whichever file it
had reached. Its manifest — what was replaced, what was added, what was deleted,
and the manifest that was in force before — is an entry inside the same archive.

It is a single zip under `data/backups/` rather than a directory of files, and
that is a security decision rather than a tidy one. A backup tree is a complete
and executable second copy of the application sitting under `data/`, and `data/`
is kept out of the web by an `.htaccess` whose no-`mod_rewrite` fallback names
sqlite, json, md, log, ini, txt and zip — but deliberately not php, because the
application is made of PHP files. There are hosts that ignore an `.htaccess` in
a subdirectory altogether. Where both of those hold, every backed-up `.php` file
is reachable and runnable over HTTP: an old version of this application, with
whatever was wrong with it, served beside the new one. Nothing inside a zip
executes, whatever the web server believes about the directory it is in.

`updates.keep_backups` decides how many are kept; the default is two. Setting it
to zero deletes the one just taken, which means the update cannot be rolled
back, and the log says so in as many words.

### The smoke check, and why the child process is not optional

A finished update proves itself before it is accepted.

In this process: every PHP file the release installed is parsed with
`token_get_all(…, TOKEN_PARSE)`, which is a real syntax check and is what
catches a copy cut short by a full disk; the new `config/defaults.json` is read;
the database is opened; and the new `CF_VERSION` is read back out of the
installed `src/bootstrap.php` and has to be newer than the old one, which is how
"the files did not land" is told apart from "the release is broken". A tag and a
constant that disagree are logged as a slip in somebody's release process, not
treated as a failure — rolling a working update back over that would help
nobody.

None of that can test the release's **database migration**, and this is the part
worth understanding. This process opened its PDO handle long before the swap and
hands back the same one for ever after; the class that would do the migrating is
the old one it loaded at the time. Asking it to migrate does nothing at all.

So a child process is started: a fresh PHP that boots the new code from scratch,
walks `src/`, loads every class in it, and opens the database — which is what
runs the release's migration — then prints the schema version it arrived at. It
exits `3` for a migration that threw, so "it booted and could not migrate" and
"it does not start" can be told apart in the log. Parsing a file proves it
compiles; it does not prove that `bootstrap.php` still runs, that the autoloader
still finds anything, or that a class can be *declared* — a missing parent class
or an interface that changed shape is a fatal error at declaration time and
nowhere earlier.

The child writes to the live database, deliberately: a release with a broken
schema step fails here and is rolled back.

On a host where PHP may not start a program — which is most shared hosting — the
deep check is skipped, and the log says which of the two happened rather than
letting an administrator assume the good one: *"the database migration was NOT
tested: a schema change in this release will first run, and first be able to
fail, on somebody's next page load."* The in-process checks stand on their own.

### Rollback

If the smoke check reports anything, the backup goes straight back, the
configuration cache is flushed, and the history row records `rolled_back` with
the reason. If anything throws *after* the backup exists, the same thing
happens — the backup's presence is the test, because anything that failed
earlier changed nothing and anything later must not be left as a mixture of two
releases. If the rollback itself fails, the log says where the archive is and
that it can be unpacked over the installation by hand.

**Rollback** on the Updates screen, and `--rollback` on the command line, restore
the most recent backup on demand: files back, files the update added removed,
and then the file-level half of the smoke check again over what was restored.

Every attempt — installed, failed, rolled back, restored — is a row in
`update_history` carrying the full log, and a line in the audit log.

### Unattended updates

`updates.auto_check` asks GitHub once a day so the Updates screen is current when
you open it. `updates.auto_install` installs at `updates.auto_time` in
`updates.timezone`. Both need the scheduler; without cron neither happens.

Three decisions here are not the obvious ones.

**"Exactly once a day" is a calendar day, not a minute.** The marker stored in
the meta table is the date in the configured time zone. A minute is far too
small a target — a host that runs cron every five minutes, a tick that overruns,
a server that was asleep at five in the morning would all miss it, and the
feature would work on some installations and silently not on others. Comparing
days instead means the window is the rest of the day. It is also the answer to a
clock that goes backwards: an hour put back lands on the same calendar day, the
marker for that day is already stored, and nothing runs twice.

**A missed window runs late rather than not at all.** If the time is 05:00 and
the server was down until 09:20, the update happens at 09:20. An administrator
who asked for unattended updates wants them installed; the hour is a preference
about when it is least disruptive, not a condition.

**A busy installation gets two hours' grace.** A run in flight defers the update;
past two hours it goes ahead anyway, because a run that has been open all
morning is more likely to be stuck than busy, and an update that never happens is
worse than a page that gets retried.

Two smaller rules follow from the same caution. The day's marker is written
*before* the install, so an update that takes the process down with it does not
have the next tick walk into the same crash a minute later — the cost is that a
crashed unattended update waits until tomorrow, which is the right way round,
because an administrator can always press the button. And an update that was
*refused* rather than attempted — a precondition that failed, a lease a manual
install is holding — gives the day's slot back, because nothing was touched and
there is no crash to avoid.

Switching automatic installation on is, on its own, a request to ask GitHub;
`auto_check` is a separate setting about the screen being current, and an
installation allowed to update itself unattended has to ask regardless or it
would act on a cache that nothing refreshes.

### On a host with a real crontab

`tools/update.php` does the same work without a browser tab that can be closed
half way through:

```bash
php tools/update.php --status      # what is installed, what is available, preconditions
php tools/update.php --check       # ask GitHub now, ignoring the cache
php tools/update.php --install     # install the newest release on the channel
php tools/update.php --rollback    # restore the most recent backup
```

Nothing is interactive and nothing asks for confirmation, because the point of
it is to be runnable from a scheduler. `--install` installs. Add `--quiet` to
print only what went wrong; the log is printed anyway when something did.

An unattended nightly update needs nothing from this file — `tools/cron.php`
already drives it — but a machine you have a shell on is a better place to run
an update from than a browser tab.

---

## 8. Installation

### Requirements

- **PHP 8.1 or newer.** 8.4 or 8.5 is faster and is what CourseForge is
  developed on. The floor is a fact about the parser rather than a taste for new
  things: readonly promoted constructor properties, the `never` return type and
  first-class callable syntax all arrived in 8.1 and all three are read by the
  parser, so an 8.0 interpreter does not reach the line that would refuse
  politely — it rejects the whole file and serves a blank page.
- Extensions: **cURL**, **PDO SQLite**, **mbstring**, **json**, **session**.
- A web server that can serve the directory and run PHP.
- Outbound HTTPS to your AI provider and your BookStack instance.
- A browser with import-map support (Chrome/Edge 89+, Firefox 108+, Safari 16.4+).

Optional, and only for the features that need them:

- **A scheduler** — either a control-panel cron that can call a URL once a
  minute, or a real crontab. Without it, courses are still written, but only
  while a browser tab is open, and there are no background runs, no batch
  collection and no unattended updates.
- **`ext-zip`**, for installing an update from GitHub.
- **`proc_open` enabled**, plus the `claude` CLI installed and signed in, for a
  Claude Pro/Max subscription account. It is also what lets an update run the
  new release's database migration in a child process before accepting it; where
  it is missing, that one check is skipped and the log says so.

No Composer, no npm, no build step.

### First run

There is no file to edit before the first start.

1. **Copy the files** to your web root, or a subdirectory of it. The document
   root is the folder holding `index.html` and `api/`.

2. **Make `data/` writable** by the user PHP runs as. The SQLite database
   (`data/app.sqlite`, created and migrated on first open), the session data,
   the staged updates and the backups all live there.

3. **Open it in a browser.** CourseForge writes `INVITE-CODE.txt` next to
   `index.html` — or into `data/` if the install root is not writable — and shows
   the setup screen, which names the path it used.

4. **Read the code out of that file** and type it into the setup screen, with a
   user name and a password of at least ten characters. That account is the
   first administrator and is signed in by the same request that creates it. The
   file is deleted at that moment. See [section 2](#2-accounts-and-roles) for
   what the code proves and why it is enough.

5. **Set up the scheduler**, which is the one thing that still has to be done
   outside the browser — see below.

6. Everything else — AI accounts, BookStack instances, models, the prompt
   library, the security policy, updates — is set from inside the application.

`php tools/diagnose.php` checks the installation from a shell, if you have one.
The same rows are on **Administration → Settings → Diagnostics**, because the
person who most needs to be told that the data directory is read-only or that
the scheduler has never ticked is the administrator sitting in front of the
application, and on a shared host that person has no shell at all.

### Configuration, in two layers

```
config/defaults.json   ships with the release. Application settings, the
                       security policy, the detail catalogue and the whole
                       prompt library. Replaced wholesale by an update.
                       Never written to.
data/config.json       what this installation changed, and only that.
                       Written from the admin screens. No update touches it.
```

Splitting them is what makes an update safe. Replacing the release directory
brings new defaults and new prompt slots along with it, and the things the
administrator decided are in a file the update is not allowed to write. It also
makes "reset to default" a delete rather than a copy of something remembered:
setting a value back to the shipped default removes it from the override file,
so `data/config.json` stays a short, readable list of what this installation
actually decided rather than a full document nobody can diff.

A `data/config.json` written by CourseForge 3.x is a complete document rather
than a set of overrides. **It is reduced to its overrides the first time 4.0
reads it** — recognised by the presence of `prompts`, `details` or
`prompt_groups` at the top level — and rewritten. An upgraded installation
therefore keeps exactly the settings it had changed and starts following the new
defaults for everything it never disagreed with.

### The settings

Every setting is declared **once**, in `Support\Settings`, and three things read
that one declaration: the Settings screen, which renders a field per entry from
its type; the API, which validates and coerces a submitted value against it; and
the MCP tools, which describe and change settings without a second catalogue
that could disagree. A setting added later appears in all three without a line
of frontend code.

There are thirty-six of them in eight groups, and the screen documents itself —
each field carries its own description, its range and whether this installation
has overridden it. What follows is what each group is for, and then the handful
whose consequences are worth stating in prose.

| Group | What it decides |
|---|---|
| **General** | The installation's name, the default course language, how many pages the in-tab generator writes at once, the public address, and debug mode |
| **Scheduler** | The cron token and how a tick behaves: how long it works for, how many may run side by side, how often a failed page is retried, how long a worker's claim on a page lasts |
| **Batch and runs** | How rarely a queued batch is polled, how long finished run records are kept, and the output ceiling used where a provider demands an explicit one |
| **Updates** | Repository, channel, whether to check and install automatically, at what time, how many backups to keep, and a GitHub token |
| **MCP** | Whether the endpoint answers at all, the public URL to hand a client, and which browser origins may connect |
| **Claude subscription** | Where the `claude` binary is, which directories it may be started from, and which models the Profiles screen offers for it |
| **Security** | Sign-in throttling and session lifetime |
| **Timeouts** | How long CourseForge waits for a provider, a model list, a connection, or BookStack |

Six of them have consequences that are not obvious from the field label.

**`app.cron_token`.** The secret in the URL your host calls once a minute.
Without one, `cron.php` answers 404 to everybody — including you — and
CourseForge does not offer background runs at all, because it knows they would
never be written. It must be at least sixteen characters; the Settings screen
generates one and hands you the finished URL to paste into a hosting control
panel. Rotating it invalidates the old URL immediately, so change the control
panel entry in the same sitting.

**`app.cron_workers`.** How many ticks may work side by side. This is where
parallelism in background generation comes from: with two, a call that arrives
while the previous one is still busy takes the second slot instead of queueing
behind it, and roughly two pages a minute get written. Raise it only as far as
your provider's rate limits allow — the failure mode of setting it too high is
a run full of 429s, not a faster course. A tick that finds every slot busy exits
immediately, so calling once a minute is safe at any setting.

**The timeouts.** `app.ai_timeout_seconds` defaults to 1800 because a long page
on a reasoning model genuinely takes minutes; below about 600 you will start
losing work that the provider was about to deliver. Whatever you set here, your
web server has its own limit — `fastcgi_read_timeout`, `max_execution_time`, a
proxy — and the smallest of them wins. This is the single most common cause of
"generation stops half way through" on a new install.

**`updates.auto_install`.** Off by default. Switching it on means this
installation will replace its own files unattended, from the internet, at the
configured hour. It takes a backup first and restores it if the new version
fails to start ([section 7](#7-updates)), but it is still an unattended change
to a production system, and it needs the scheduler.

**`mcp.allowed_origins`.** Empty by default, which refuses every browser-based
MCP client and affects nothing else: Claude Code, the Claude desktop app and
every other command-line or desktop client send no `Origin` header at all. It
exists as a DNS-rebinding defence — without it, a page on any other origin could
drive your MCP endpoint from a browser that already holds the token. Add an
origin only when you actually have a browser client, and add the exact origin.

**`app.debug`.** Puts exception classes, messages, files, lines and a stack
trace into API responses and into MCP tool errors. Leave it off on anything
reachable from the internet.

Two settings hold secrets — `app.cron_token` and `updates.github_token` — and
neither ever travels to a client. The screen is told whether one is stored, not
what it is, and an empty string on save leaves the stored value alone rather
than clearing it. Clearing one is an explicit reset.

### Setting up the scheduler

Background runs, batch collection, unattended updates and the "is the scheduler
alive?" indicator all need something to call CourseForge once a minute.

Most shared hosts give you a box in a control panel where you paste a URL and
choose an interval — all-inkl/KAS, Plesk, cPanel and DirectAdmin all work this
way:

```
https://your-install/cron.php?token=<your cron_token>      every minute
```

The token may also be sent as an `X-Cron-Token` header, for a scheduler that can
manage one. Both are trimmed, because a token pasted into a control panel
arrives with a trailing space often enough to be worth allowing for.

On a host with a real crontab, skip the web server and the token entirely:

```cron
* * * * * php /path/to/courseforge/tools/cron.php --quiet
```

**Settings → Scheduler** writes both of those out for you, with the token and the
absolute path already filled in, alongside how long ago the last tick was. That
last number is the one to look at: a scheduler that was configured six months ago
and stopped answering last week looks exactly like one that was never set up.

`cron.php` in the document root is *meant* to be reachable — it is the only
thing a control-panel scheduler can call. It is gated on `app.cron_token`,
compared in constant time, and answers 404 to everything else including the case
where no token has been configured, so an installation that never set one is not
left with an open endpoint. Run from a shell it refuses and points at
`tools/cron.php`, with a failing exit status, because the likely reader is a
crontab pointed at the wrong file and a clean exit would let it report success
every minute while nothing ever ticks.

**What a tick does**, in this order and for a reason: it gives back any page
whose worker died, collects finished provider batches, writes pages until it
runs out of time, and only then considers an unattended update — because an
update replaces the very files the tick is executing, so anything still owed to
a course is finished first. Nothing is unbounded. A tick cut short by a host
time limit loses nothing, because every claim is a lease with an expiry and
every page is stored the moment it is finished rather than at the end.

`php tools/diagnose.php` tells you whether it is actually running, and so does
the Scheduler section of the Diagnostics screen.

### Upgrading from CourseForge 3.x

Copy your `data/` directory across, or point the new installation at the old one.
Three things happen on first start, each exactly once:

- the database gains its new tables — `users`, `invites`, `audit_log`,
  `update_history`, `locks` — and its new columns;
- `data/users.json` is imported into the accounts table as administrators and
  renamed to `users.json.imported`;
- `data/config.json` is reduced to the settings you actually changed, so the new
  defaults apply to everything else.

An AI account with no type recorded is still read as the OpenAI-compatible one
it was, so existing profiles and courses keep working untouched. Tokens issued
by CourseForge 3 (`cf3_…`) stay valid until they are revoked.

There is one thing to check by hand: `config/` is new, and a deployment that
copies only the files it recognises will leave `config/defaults.json` behind.
Without it nothing starts — the API answers "the server is not configured
correctly" and the real message, naming the file and the path it was expected
at, goes to the server log. `php tools/diagnose.php` names it outright.

### Upgrading from CourseForge 2.x

Point `data/` at your existing installation's data directory (or copy
`app.sqlite`, `users.json` and `config.json` across). On first start CourseForge
adds the new columns and converts the old per-level `anki` flag into the
content-detail system. Projects, chapters, pages, tags, tag links and profiles —
including stored secrets and prompt overrides — carry over untouched, and
everything the 2.x catalogue did not know about falls back to the defaults.

Take a copy of `app.sqlite` first anyway. The migration runs once, guarded by a
`schema_version` row, and never touches a value you have since edited.

### Serving it somewhere other than Apache

CourseForge protects itself with **`.htaccess`**. **Nginx, Caddy, IIS and PHP's
built-in server ignore `.htaccess` entirely.** On those you must reproduce the
protection yourself, or `data/app.sqlite`, `config/defaults.json`,
`data/config.json` and — worst of all on a fresh install — `INVITE-CODE.txt` may
be downloadable.

That last one is why this section matters more in 4.0 than it did in 3.x. The
invite code is the key to the first administrator account. On Apache the shipped
rules refuse every `.txt` file and it is safe where it lies; anywhere else it is
an ordinary file in the document root.

The shipped rules do five things:

1. route `/api/…` to `api/index.php`, and `/mcp` to `api/mcp.php`;
2. leave `api/mcp.php` alone, because it is a second front door with its own
   authentication and must not be swallowed by the front controller;
3. deny all access to `data/`, `src/` and `tools/` — and `config/`, `data/`,
   `src/` and `tools/` each carry a second `.htaccess` of their own;
4. deny `*.sqlite`, `*.sqlite3`, `*.db`, `*.db-wal`, `*.db-shm`, `*.json`,
   `*.md`, `*.log`, `*.ini`, `*.txt`, `*.zip`, `*.tar`, `*.gz`, `*.bak`,
   `*.sql` and `*.sh` everywhere — which is the belt-and-braces layer that works
   even without `mod_rewrite` and on hosts that silently ignore a subdirectory
   `.htaccess`;
5. re-expose the `Authorization` header, which Apache drops under CGI/FastCGI
   before PHP sees it — without that line every MCP request looks
   unauthenticated.

`.php` is deliberately **not** on that deny list, because the application is made
of PHP files. That is also why an update stores the version it replaced as a
single zip rather than as a tree of files — see [section 7](#7-updates).

**`tests/` is not on the deny list either, and should be.** The runner refuses to
run outside the command line and the test files themselves fatal harmlessly
without it, so nothing is exposed today — but they are executable PHP in the
document root that nothing checks, and the honest thing is to say so rather than
to rely on the current contents staying harmless. Add `tests/` to your server
rules along with the other three, or leave the directory out of what you deploy;
it is a development artefact, not part of a running installation.

Routing itself is not a hard requirement: the SPA calls
`api/index.php?r=<route>`, and `api/index.php/<route>` works as well, so the app
functions with no rewrite rules at all. The directory and file-type protection
is not optional.

```nginx
# Block the private directories outright
location ^~ /data/   { deny all; return 403; }
location ^~ /config/ { deny all; return 403; }
location ^~ /src/    { deny all; return 403; }
location ^~ /tools/  { deny all; return 403; }
location ^~ /tests/  { deny all; return 403; }

# Block sensitive file types anywhere. .txt is here for INVITE-CODE.txt.
location ~* \.(sqlite|sqlite3|db|db-wal|db-shm|json|md|log|ini|txt|zip|tar|gz|bak|sql|sh)$ {
    deny all; return 403;
}

# API front controller. The regex location below matches .php first, so
# api/mcp.php is handed to PHP rather than routed into index.php.
location /api/ {
    try_files $uri /api/index.php$is_args$args;
}

location = /mcp { rewrite ^ /api/mcp.php last; }

location ~ \.php$ {
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_read_timeout 1800;          # a page generation may take minutes
}
```

Caddy says the same thing with fewer words:

```caddy
courseforge.example.com {
    root * /var/www/courseforge

    @privateDirs path /data/* /config/* /src/* /tools/* /tests/*
    respond @privateDirs 403

    @privateFiles path *.sqlite *.sqlite3 *.db *.db-wal *.db-shm *.json *.md *.log *.ini *.txt *.zip *.tar *.gz *.bak *.sql *.sh
    respond @privateFiles 403

    rewrite /mcp /api/mcp.php
    php_fastcgi unix//run/php/php-fpm.sock
    file_server
}
```

On **IIS**, the equivalents are `<requestFiltering><hiddenSegments>` for the five
directories and `<fileExtensions>` for the suffixes, in `web.config`. IIS does
not read `.htaccess` and does not fall back to it, so nothing is protected until
that file exists.

**PHP's built-in server ignores all of this too.** `tools/router-dev.php`
reproduces the directory rules and the database suffixes for development, but
not the `.json`, `.txt` or `.md` deny — so under `php -S` both
`config/defaults.json` and, if it exists, `INVITE-CODE.txt` are downloadable.
That is acceptable on a laptop and is not a way to serve CourseForge to anybody
else.

The safest option on any server is to **move `data/` outside the document root**
entirely and point CourseForge at it:

```
SetEnv COURSEFORGE_DATA_DIR /var/lib/courseforge             # Apache
fastcgi_param COURSEFORGE_DATA_DIR /var/lib/courseforge;     # nginx
```

The invite code then goes there too, since the install root is generally not
writable in that arrangement. `config/` still has to be readable by PHP and not
by the web, and only `index.html`, `assets/`, `api/` and `cron.php` need to be
public at all.

### Hardening applied regardless of the server

The session cookie is `HttpOnly`, `SameSite=Lax`, scoped to the install
directory rather than the whole host — so two CourseForge instances on one domain
do not fight over it — and `Secure` whenever HTTPS is detected, including behind
a proxy that sets `X-Forwarded-Proto`. Every non-GET request requires a CSRF
token. Every response sends `X-Content-Type-Options: nosniff` and
`Cache-Control: no-store`, and Apache adds `X-Frame-Options: SAMEORIGIN` and
`Referrer-Policy: same-origin`.

The MCP endpoint has no session and no cookie: a bearer token, an `Origin` check
that refuses any origin that is neither this host nor listed in
`mcp.allowed_origins`, and 401 for an unknown token. An installation with no
connections has no way in there at all.

Always serve CourseForge over **HTTPS** — API tokens and session cookies travel
with every request.

---

## 9. Technical background

### Shape of the thing

A buildless single-page application plus a thin PHP JSON API, and a second front
door speaking JSON-RPC. There is no bundler, no package manager and no framework
build step in any of them.

```
index.html            import map + seven stylesheets
assets/css/           tokens → base → layout → components → admin → prose → editor
assets/js/            main → App → core/ + components/ + views/ + views/admin/
assets/vendor/        Vue, marked, DOMPurify, Fuse, CodeMirror, Shiki,
                      Mermaid, MathJax (pinned, see VENDOR.md)

config/defaults.json  the shipped configuration: settings, the detail
                      catalogue, the whole prompt library. Replaced by an update
data/                 config.json (overrides only), app.sqlite, backups/,
                      updates/, and nothing an update may write

api/index.php         the browser front controller: every route, in one list
api/mcp.php           the MCP front door: bearer token, no cookie session
cron.php              the scheduler, over HTTP, for hosts without a crontab

src/Support/          Config, Settings, Db, Http, HttpResult, HttpException,
                      Json, Lock, Meta, Request, Response, Router, Runtime,
                      Markdown, Text, Audit, Cron, Diagnostics
src/Security/         Actor, Access, Auth, Users, Invite, Session, LoginThrottle
src/Domain/           Projects, Chapters, Pages, Tags, Profiles, Details,
                      Structure, AutoLinker, LinkIndex, Runs, McpClients,
                      Transfers
src/Ai/               Completion, Prompt, AiRequest, ModelId,
                      StructureGenerator, PageGenerator
src/Ai/Provider/      the Provider and BatchCapable interfaces, the Providers
                      factory, HttpProvider, one adapter each for Anthropic,
                      OpenAI, Gemini, OpenRouter, the OpenAI-compatible
                      catch-all and the Claude CLI, plus Presets, PresetSpec
                      and Probe
src/Ai/Batch/         the value objects a provider queue is described with,
                      and JsonlChunker
src/Ai/Batch/Driver/  one driver per queue shape: AnthropicInlineBatch,
                      OpenAiFileBatch, GeminiLroBatch, OpenRouterInlineBatch
src/Ai/Run/           RunManager, BatchDriver, LiveDriver
src/Mcp/              Server (dual-era transport), Tools (the registry), Tool,
                      Scopes, Resolve, Args, Schema
src/Mcp/Handlers/     one class per tool group: Account, Course, Structure,
                      Page, Detail, Tag, Run, Profile, Publish, Admin
src/Update/           GitHub, Release, Archive, Updater
src/Publish/          BookStackClient, Publisher
src/Api/              one controller per resource, plus Setup, User, Settings,
                      Config, Update and Connect

tools/diagnose.php    installation check, printed
tools/cron.php        the scheduler, on the command line
tools/update.php      check, install and roll back from a shell
tools/deploy.php      an FTP deploy that uploads only what changed
tools/router-dev.php  makes `php -S` behave roughly like the .htaccess
tools/detect-test.mjs the corpus behind the language detector
tests/run.php         the test runner, plus one *.test.php file per subject
```

### The test harness

```bash
php tests/run.php              # every test
php tests/run.php openrouter   # only the files whose name contains that word
```

No framework, and deliberately no dependency on one. CourseForge installs
without Composer, so a suite that needed PHPUnit could not be run on the machine
it is meant to protect. `tests/run.php` includes every `*.test.php` file beside
it, each `test()` call prints one line, and the exit code is what a pipeline
reads. There are four assertions — `ok`, `same`, `raises`, and whatever a test
throws itself — and `same()` prints both sides, because that is most of the
value.

It runs against a scratch database in the system temporary directory, never
`data/`: `COURSEFORGE_DATA_DIR` is set before the bootstrap fixes `CF_DATA`. The
scratch directory is emptied on the way *in* rather than on the way out, because
the database connection is still open when a run finishes and on Windows an open
file cannot be deleted — which also means every run starts from an empty
database without leaving a trail of them behind.

Seven files, chosen because each covers something whose failure mode is silent:

| File | What it pins down |
|---|---|
| `access.test.php` | Actor versus owner: who reaches whose rows, that a row you may not reach is reported as *missing*, and which listings widen for an administrator |
| `adapters.test.php` | One case per adapter of a failure arriving at HTTP 200 — a refusal, a safety block, a truncation — each of which must raise rather than store half a page |
| `batch-deadlines.test.php` | That the two expiry dates stay apart, that a run stored before the second one existed does not read as expired in 1970, and that runs are polled nearest download deadline first |
| `config.test.php` | The two configuration layers: that writing the shipped default back *removes* the override, and that a 3.x complete document is reduced to overrides on first read |
| `invite.test.php` | That a wrong code, a spent code, an expired invite and no invite at all are all refused, and refused the same way |
| `jsonl-chunker.test.php` | That the 200 MB ceiling binds at about 25,000 course prompts rather than at the 50,000-row cap, and that an unsendable row is refused by name |
| `openrouter-body.test.php` | That the create body serialises as `endpoint`, `model`, `requests`, in that order, because the beta endpoint stream-parses it |

Fifty-two tests at the time of writing, all passing.

`tools/detect-test.mjs` is separate and older: sixty snippets the language
detector must recognise, twenty-five it must not, and the rule that a fence
naming the right language is never overruled. It needs Node and nothing else,
and nothing is generated from it.

Between them those cover the parts that are hard to see going wrong. The rest is
checked by `tools/diagnose.php` against a live installation, and by the update's
own smoke check against a freshly installed one.

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

Everything a browser sends enters through `api/index.php`, which turns PHP
warnings into exceptions, guarantees a JSON body even when boot itself fails,
enforces CSRF on non-GET requests, and declares the entire HTTP surface as a
list of routes. Reading that file top to bottom gives you the whole API: the
verbs, which routes are reachable without a session, and which need an
administrator.

The one carve-out in the error handler is `E_DEPRECATED`, which is logged rather
than thrown. A deprecation is a message about the next major PHP release, not a
fault in this request, and turning one into an exception is how a working
installation gets a 500 on the day the host upgrades.

`Router` matches path segments, so a path that exists but was called with the
wrong verb produces a `405` with an `Allow` header rather than a misleading
`404`. Each route carries two flags — `auth` and `admin` — and the dispatcher
acts on them before the handler is reached; every admin handler then asks again
for itself, because a handler that is safe on its own must not be made unsafe by
a routing change made later. `Request` parses the body once and validates on
demand — every accessor either returns the promised type or throws a `422`.
Errors the client is allowed to see are `HttpException`s; anything else is
logged and masked.

`Runtime::beginLongRequest()` is what makes concurrent generation work: before
any slow AI or BookStack call it closes the session (releasing PHP's exclusive
lock on the session file), removes the time limit and sets `ignore_user_abort`.
Without it every request of the same user would serialise behind the session
lock, and a closed tab would discard a finished page. The MCP endpoint calls it
before every tool call for the same reason.

### Security

Three small classes and one rule.

- **Actor** — who is asking. It carries the user name, the display name and the
  role, and it hands out the `WHERE` fragment every scoped query uses:
  `username = ?` for a normal account, a tautology for an administrator. That is
  why there is not a second set of queries for administrators.
- **Access** — the bridge between "who is asking" and "whose data is this". Each
  method takes the actor and an id, answers "may you?", and returns the row, from
  which the caller reads `username` and carries on. A row the actor may not
  reach is reported as missing rather than forbidden.
- **Auth** — the session holds a user name and nothing else that matters. Role,
  display name and whether the account still exists are re-read on every
  request, so a demotion, a rename or a deletion takes effect at once instead of
  when the session happens to expire.

The rule is the one in [section 2](#2-accounts-and-roles): authorisation reads
the actor, data access reads `$row['username']`, and nothing outside
`src/Security/` consults the session.

`Invite` and `Users` sit beside them. `Users` is the only class that touches a
password hash, and `Invite` is the only one that writes `INVITE-CODE.txt`.

### The MCP layer

`Server` is the transport and knows nothing about courses: era detection,
version negotiation, the `Origin` check, the bearer token, and the two response
shapes. `Tools` is the registry, `Tool` is one tool — definition and
implementation in the same object, because a catalogue in one place and a
`match` in another is a pair that drifts. `Scopes` is the group list. `Args`
reads a call's arguments with the same discipline `Request` uses, and throws
messages written for a model to read and correct rather than for a log.

`Resolve` is the smallest and the most important. Every handler starts the same
way — authorise against the actor, then use the row's owner for everything
downstream — and getting that opening wrong is the one mistake on this surface
that would actually matter. Putting the two lines in one place is how they stay
together.

The ten handler classes under `Mcp/Handlers/` hold the tools themselves and call
the same domain classes the HTTP controllers do. Nothing in `src/Mcp/` reaches
into the database directly.

### Updates

`GitHub` is the only class that talks to GitHub, and it deliberately does not use
`Support\Http`: a 403 from GitHub is usually the rate limit, the only useful
thing to say about a rate limit is when it lifts, and that is in a response
header that `Support\Http`'s result object does not carry. `Release` is the
version comparison and the asset choice. `Archive` is the one class that knows
how a zip is opened, extracted safely and closed, and it writes the backup as
well as reading the release. `Updater` is the sequence, and its docblocks argue
each step — [section 7](#7-updates) is the compressed version of them.

### The domain

- **Details** — the catalogue and the inheritance rule. Features are tri-state
  (`-1` off, `0` inherit, `1` on), values are nullable, and `resolve()` walks
  `catalogue → course → chapter → page`. `describe()` returns the three views an
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
- **AutoLinker / LinkIndex** — [section 5](#5-auto-links). The transformation is
  pure: text in, text out, with the index as its only input.
- **Tags** — the library, the assignments, inheritance resolution, AI-tag sync
  (which never re-enables a deactivated link nor downgrades a manual one), and
  pruning of orphans.
- **Profiles** — CRUD with server-side redaction and secret merging. Incoming
  data is shaped explicitly rather than recursively merged, so an unknown key is
  dropped and a removed account really is gone.
- **Runs** — the run and its items, the vocabulary both drivers share, and the
  claim that stops two workers writing the same page. A run outlives every
  process that touches it, so nothing about it is held in memory.
- **McpClients** — one row per connection: the token digest, the scopes, an
  optional expiry, and the resolution that turns a presented token into an actor
  with the account's *current* role.
- **Transfers** — handing one course to another account, and the four things
  that break quietly if only the course moves.

### Publishing

`Publisher` is idempotent by construction. Every item is created once and updated
afterwards; an item whose hash matches what was last pushed is skipped; an item
that vanished in BookStack is recreated. What gets hashed is exactly what gets
sent — resolved links included — so the badges in the UI never drift from
reality. `BookStackClient` treats only a genuine `404` as "gone": any other error
aborts the push rather than risking a duplicate course.

### Data

Everything lives in `data/app.sqlite` (WAL mode, foreign keys on, 15 s busy
timeout): users, invites, profiles, projects, chapters, pages, tags, tag links,
login attempts, the run tables, the leases, the connected MCP clients, the audit
log, the update history, and a `meta` table holding the schema version and the
handful of facts that belong to the installation rather than to anybody — when
cron last ran, when GitHub was last asked, which calendar day the unattended
update slot was spent on.

A run outlives every process that touches it — a provider is allowed a full day,
and a five-hundred-page background run takes hours — so `batch_jobs` holds the
run and `batch_items` one row per page, written before any work starts. Those two
kept their original names when live runs were added to them; renaming a table
with data in it buys nothing. Content details are a JSON `settings` column per
level rather than a column per feature, which is why the catalogue can grow
without a migration.

Three kinds of secret are stored, and none of them is recoverable: passwords as
`password_hash()` digests, invite codes as SHA-256, and connection tokens as
SHA-256. The tokens use a plain digest rather than a password hash on purpose —
they are 32 random bytes, so there is nothing to brute force, and the endpoint
has to find the row *by* the token on every request, which a salted password
hash cannot do. Provider API keys are the exception: they are stored so they can
be sent, which is why a profile is redacted on the way out and why moving a
course clears its profile rather than sharing it.

Everything else on disk is either the release (`config/`, `src/`, `api/`,
`assets/`) or this installation's own (`data/config.json`, the backups, the
staged update, the invite code). An update writes only the first group.

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
- **Declare it once.** The settings, the tools and the content-detail catalogue
  each exist in exactly one place, and every screen, endpoint and tool that needs
  them reads that one declaration. A second catalogue is a catalogue that will
  disagree.
- **Authorise against the actor, read against the owner.** The one rule the
  multi-account model rests on, kept in `Security/Access` and `Mcp/Resolve` so
  that it is written down twice rather than reimplemented thirty times.
- **Resilience over speed.** Long requests survive a closed browser, killed
  generations self-heal into an `error` state, an unparsable AI answer is
  refused rather than allowed to destroy existing work, and an update that
  cannot prove it works is put back.
