---
title: Troubleshooting
nav: Troubleshooting and FAQ
description: The problems people actually run into, and what to do about each - installation, keys, generation, runs, publishing, MCP and updates.
toc: 2
---

Start with the two built-in checks: **Administration › Settings › Diagnostics** (or `php tools/diagnose.php` from a shell) and **Administration › Security**. Between them they name most problems outright.

## Installation

### The page is blank

Almost always PHP older than 8.1: the interpreter cannot parse the code and serves nothing. Check the PHP version your host runs for this directory - control panels often default an old site to an old PHP.

### "The server is not configured correctly"

`config/defaults.json` is missing - typically a deployment that copied only the files it recognised. Copy the whole release. The server log and `php tools/diagnose.php` name the path it expected.

### The setup screen says it cannot write the invite code

Neither the install root nor `data/` is writable by PHP. Make `data/` writable by the PHP user and reload. The setup screen names the path it tried.

### I lost the invite code before using it

Delete the open row from the `invites` table in `data/app.sqlite` and reload: a fresh code is written. If you already have an administrator, **Revoke** the invite on **Administration › Accounts** and issue a new one.

### I lost every administrator password

Delete the rows in the `users` table. The setup screen and a fresh invite code come back. Courses and profiles stay, owned by the old user names; create the new administrator with the same user name to keep them, or transfer them afterwards.

## Keys and security

### The API key fields are greyed out with a red mark

The security check has not passed, so CourseForge refuses to store secrets. Open **Administration › Security**: on Apache with the shipped `.htaccess` it usually passes by pressing **Check again**; on nginx, Caddy or IIS paste the configuration block the screen shows. See [Security](security.md).

### The verdict is "unverified"

The server could not fetch its own address. Set **Settings › General › Public address** (`app.public_url`) if CourseForge guessed it wrong (behind a reverse proxy, for instance). Some hosts block requests to their own name; then verify the protection by hand and **accept the risk** at the bottom of the Security screen.

### The verdict is "exposed"

At least one private file can be downloaded. Fix the server configuration first, then treat every key already stored as compromised and rotate it at the provider.

## Generating pages

### Generation stops half way, or pages fail with a timeout

The smallest time limit between CourseForge and the model wins:

- `app.ai_timeout_seconds` (default 1800) - keep it above 600;
- PHP's `max_execution_time` and `default_socket_timeout` - **Set up PHP** raises them where the host allows;
- the web server: nginx `fastcgi_read_timeout`, a proxy timeout, a hosting-panel limit.

Background runs are more robust than the in-tab generator here, because each page is its own short-lived request and is stored the moment it finishes.

### A page failed with "cut off by the output limit"

The model hit the slot's token ceiling, and CourseForge refused to store a page that stops mid-sentence. Raise the page slot's **token ceiling** (or set it to 0 for the provider's maximum), or lower the course's maximum length.

### Pages repeat each other

Check the Structure tab for pages without a scope and press **Plan missing scopes**. Scopes are how a page knows what its neighbours own. Writing live in chapter order also helps: later pages see the headings of the pages already written in their chapter. See [Tokens and caching](token-economy.md).

### A page ignores something I told it

Put page-specific facts in the inspector's **context** tab, which outranks the model's own assumptions. For a course-wide rule, use a profile prompt override or the course's content details. A rewrite with **feedback** corrects one written page.

### The model refuses temperature, or the setting has no effect

Claude (API version `2026-10-21`), Gemini and OpenAI's reasoning models refuse or have deprecated sampling parameters; CourseForge leaves them out, so the temperature has no effect on those models. That is expected.

### Requests fail with 429 (rate limited)

Too many requests at once for your provider tier. Lower **Pages in parallel** on the profile for in-tab generation, or `app.cron_workers` for background runs. A batch run avoids rate limits altogether.

### Pages from a local model ignore the course map or lose the thread

Ollama's OpenAI endpoint cannot set the context length, so long prompts are silently truncated. Raise `OLLAMA_CONTEXT_LENGTH` on the Ollama machine. Other local servers have a similar context setting.

### Gemini answers 401 ACCESS_TOKEN_TYPE_UNSUPPORTED

Developers still report some of the `AQ.` auth keys that AI Studio now issues being refused by `generateContent`, and Google rejects standard keys that have no restrictions. CourseForge shows Google's own error verbatim; try a standard key restricted to the Generative Language API, or use Gemini through OpenRouter. See [AI providers](providers.md#google-gemini).

## Runs and the scheduler

### The Content tab does not offer a background run

The scheduler is not set up or has never called in. Generate a cron token on **Settings › Scheduler** and add the URL to your host's cron, every minute. See [The scheduler](scheduler.md).

### The scheduler says it has never run

- Control-panel cron: check the URL and the token exactly (a trailing space is fine; a truncated token is not). `cron.php` answers 404 to a wrong or missing token.
- Crontab: point it at `tools/cron.php`, not `cron.php`. The web endpoint refuses to run from a shell.

### The batch switch is disabled

The account cannot queue, and the reason is shown under the switch: the provider has no OpenAI-shaped queue (Mistral, local servers), the probe found none for this key, the key is on a free tier (Gemini's queue is paid-only), or the provider's queue does not accept the chosen model. **Check this account** re-probes.

### A stopped batch run stays open

Cancelling a batch is asynchronous: the provider keeps what it finished, and the run closes once the provider confirms and the finished pages are collected. OpenRouter has no cancel route at all - its pages arrive when the provider is done. **Check the run** polls immediately.

### A Gemini batch came back with nothing

Gemini expires a batch that is still running after 48 hours, with no results at all, not even partial ones. Submit the remaining pages again; smaller runs finish sooner.

## Publishing

### Publishing creates a second book

The destination was **removed** and added again, or pointed at a different instance: CourseForge forgot the book it made there. To pause a destination, use its switch instead of removing it. Delete the duplicate book in BookStack.

### Somebody edited a page in BookStack and CourseForge does not notice

CourseForge compares against what it last pushed, not against BookStack. **Force overwrite** re-sends every page and restores CourseForge's version. Edit in CourseForge rather than in the wiki.

### A push keeps failing on one wiki

Read that destination's card and the publish log: the wiki's own error message is quoted. The other destinations carry on; the failed one is retried with growing pauses and can be retried by hand once fixed.

### Code, diagrams and formulas show raw in BookStack

BookStack needs help to render them: give the wiki a [BookStackDev look](bookstackdev.md) and paste its line into *Settings › Customization › Custom HTML head content*. If the loader is refused with a 403 in the browser console, add the wiki's origin to the look's allowed wikis.

### Cross references stay as plain text

The target was not published yet (it resolves on the next publish), does not exist under that title, or the marker is inside code. The publish log reports markers that could not be matched. See [Auto links](auto-links.md).

## MCP clients

### Every MCP request is 401 on Apache

Apache drops the `Authorization` header under CGI/FastCGI. The shipped `.htaccess` re-exposes it - make sure it is deployed and `AllowOverride` permits it - or use the `?token=` form of the URL.

### The Claude desktop app cannot add the connector

Custom connectors are reached from the public internet, so `localhost` or a VPN-only address does not work. Use a public hostname or a tunnel, or configure the server in `claude_desktop_config.json`, which runs on your machine. See [Connect an AI client](mcp.md#claude-desktop-app).

### Tool calls time out while a page is being written

Raise the client's tool timeout (Codex: `tool_timeout_sec = 1800`) and the server's own limits. Writing pages in the client with `get_page_brief` / `write_page` avoids long server calls altogether.

## Updates

### The update check fails with 403

That is GitHub's rate limit of sixty unauthenticated requests an hour per address, usually shared with other sites on the host. The screen says when it lifts; set `updates.github_token` to raise the limit.

### An update was rolled back

Read the log on **Administration › Updates** - it says which check failed. The previous version is running again, untouched. Common causes: PHP may not write to some files (a deploy that runs as a different user), or a disk filled up during the copy.

### After an update some screens look old

The browser is holding a stale `index.html`. CourseForge serves it `no-cache` and stamps every asset with the release, so a normal reload fixes it; if a proxy cached it, a hard reload does.

## Browsers

### Diagrams do not render in the preview on Safari

Mermaid 12 needs Safari 17.4 or later. The rest of the application works from Safari 16.4.

### I am locked out after failed sign-ins

Five failures in fifteen minutes lock that address for fifteen minutes. Wait, or sign in from the address you last signed in from, which stays reachable.
