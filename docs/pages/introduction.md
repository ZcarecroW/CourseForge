---
title: Introduction
nav: What CourseForge is
description: What CourseForge does, how a course is made, and the handful of words the rest of these pages use.
---

CourseForge is a self-hosted web application that uses AI to **design, write and publish complete courses** into a [BookStack](https://www.bookstackapp.com/) knowledge base. You describe what should be taught, to whom and up to which level, in one line. CourseForge turns that into a book with chapters and pages, writes the teaching content of every page, attaches tags and flashcards, and pushes the result into one or more BookStack wikis over their REST API.

```text
"Vue.js – complete course from beginner to professional; IDE: PhpStorm"
        │
        ├─ AI designs the outline        20 chapters, 140 pages, a scope under each
        ├─ AI writes each page           in the background, or at half price in a batch queue
        ├─ you steer what goes in        per course, chapter or page
        └─ CourseForge publishes it      into BookStack - one wiki or several, links and all
```

You can do all of that in a browser, or from an AI client - Claude Code, the Claude desktop app, OpenAI Codex, Cursor, VS Code or Gemini CLI - which connects over the Model Context Protocol and can do everything the browser can.

## How a course is made

::diagram pipeline | The four stages of a course. A profile supplies the AI; a connected client can take over any step.

<div class="steps">

1. **The brief.** A course starts as one sentence: the subject, the reader and how far the course should go. *"Machine learning with Python, from a first linear model to a served pipeline, for developers who already write Python."*
2. **The outline.** The AI designs chapters and pages from the brief, and under every page one line - the page's **scope** - naming what that page and no other page teaches. The outline is Markdown: read it, edit it, ask the AI to revise it, then **Apply** it. See [The outline](outline.md).
3. **The pages.** Every page is written from a brief that carries the course map, its own chapter, the content details that apply to it and the headings of the pages already written beside it. You can write pages one at a time while you watch, hand the whole course to a background run, or send it to the provider's batch queue at about half price. See [Writing pages](writing-pages.md) and [Runs](runs.md).
4. **Publishing.** CourseForge creates the book in BookStack, puts it on a shelf, creates or updates every chapter and page with its tags, and turns cross-reference markers into real links. Pushing again only sends what changed. See [Publishing](publishing.md).

</div>

## What makes it different

**Pages that do not repeat each other.** A page is written in a request of its own, so it only knows about the rest of the course what its prompt tells it. CourseForge tells it what every other page owns, through the scopes, so the page on `ref()` leaves `watch()` to the page that teaches it and links there instead. [How that works](token-economy.md).

**A prompt a third the size, mostly cached.** The shared part of every page prompt is byte-identical from page to page and is marked for each provider's prompt cache. On a course of 20 chapters × 7 pages, a page prompt went from about 139,000 characters to 47,000, and a live run on Anthropic is billed for roughly a tenth of the input it used to be.

**Nothing hidden.** Every instruction the AI receives is one of [[count:slots]] documented, editable prompt slots. An administrator can change them for the whole installation; anybody can override them for their own profile. See [The prompt library](prompt-library.md).

**Made for a web host.** It is PHP 8.1+ with SQLite and no dependencies to install. Long jobs survive a closed browser tab, a crashed worker or a host time limit, because work is written down before it starts and every page is stored the moment it is finished.

**Your provider, your price.** CourseForge talks to Anthropic, OpenAI, Google Gemini and OpenRouter natively, to sixteen OpenAI-compatible gateways through presets, and to any other endpoint you type in, including a model running on your own machine. See [AI providers](providers.md).

## The words these pages use

| Word | Meaning |
|---|---|
| **Course** | One book being written: its brief, outline, chapters, pages, tags and where it publishes. Courses are listed under **Courses**. |
| **Outline** (Structure) | The course as Markdown: the book title and description, numbered chapters with a goal each, numbered pages, and a scope under each page. **Apply** turns it into chapters and pages. |
| **Scope** | The one line under a page in the outline that names what that page alone teaches. Every page is written knowing every other page's scope. |
| **Profile** | A reusable bundle of AI accounts, BookStack instances, models, course language, content defaults and prompt overrides. A course is written with one profile. |
| **AI account** | One connection to a model provider: a type (Anthropic, OpenAI, Gemini, OpenRouter, a preset or a custom endpoint), a key and an address. |
| **Model slots** | A profile has two: the model that designs the **outline** and the model that writes the **pages**. They can use different accounts. |
| **Content details** | The *elements* a page is made of (summary, exercises, diagrams, formulas, Anki cards…) and the *values* that size them (minimum length, exercises per page…). Inherited from installation to profile to course to chapter to page. |
| **Run** | A batch of pages the server writes for you, in the background from cron or through a provider's batch queue. A run survives the tab, the session and a restart. |
| **Destination** | A BookStack instance a course publishes into, with its own shelf and its own book. A course may have several. |
| **Look** | A BookStackDev configuration that decides how a wiki renders code, diagrams and formulas, loaded by one line in BookStack's custom head. |
| **Connection** | A token an MCP client uses to drive CourseForge, optionally narrowed to some tool groups. |

## What you need

- A web host with **PHP 8.1 or newer** (8.4 or 8.5 recommended) and the cURL, PDO SQLite, mbstring, json and session extensions.
- A **BookStack** instance with an API token, if you want to publish. You can write a whole course without one.
- An **AI provider account**, or an MCP client such as Claude Code to do the writing. You need at least one of them.
- Optionally, a way to call a URL once a minute (any hosting control panel's cron), which is what lets runs and publishing carry on with the browser closed.

The full list is on [Installation](installation.md).

## Where to go next

- **Trying it out?** Follow the [Quick start](quick-start.md): from a fresh install to a published course.
- **Setting up a server?** Read [Installation](installation.md), then [Security](security.md) and [The scheduler](scheduler.md).
- **Using Claude Code or the Claude app?** Go to [Connect an AI client](mcp.md).
- **Watching the bill?** Read [Runs](runs.md) and [Tokens and caching](token-economy.md).
