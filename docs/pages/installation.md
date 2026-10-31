---
title: Installation
description: Requirements, putting the files on a server, the first administrator, PHP limits and the three things to do once it runs.
---

CourseForge is a folder of files. There is no installer, no package manager and no build step: copy the folder to a PHP host, make one directory writable and open it in a browser. Everything else - AI accounts, BookStack instances, the scheduler, the security policy, the prompt library - is set from inside the application.

## Requirements

| | Needed | Notes |
|---|---|---|
| **PHP** | 8.1 or newer | 8.4 or 8.5 is faster and is what CourseForge is developed on. An 8.0 interpreter cannot even parse the code and serves a blank page. |
| **Extensions** | cURL, PDO SQLite, mbstring, json, session | All five ship with almost every PHP build. |
| **Web server** | anything that serves files and runs PHP | Apache and LiteSpeed read the shipped `.htaccess`. nginx, Caddy and IIS need [a few lines of configuration](security.md#serving-it-somewhere-other-than-apache). |
| **Network** | outbound HTTPS | To your AI provider and your BookStack instance. |
| **Browser** | import-map support | Chrome/Edge 89+, Firefox 108+, Safari 16.4+. Diagrams in the preview need Safari 17.4+. |

Optional, only for the features that use them:

- **A scheduler** that calls a URL once a minute (a control-panel cron) or a real crontab. Without it, pages are still written, but only while a browser tab is open: no background runs, no batch collection, no unattended updates. See [The scheduler](scheduler.md).
- **`ext-zip`**, to install updates from GitHub.
- **`proc_open`** enabled, so that an update can test the new release's database migration in a child process before accepting it. Where it is disabled, that one check is skipped and the update log says so.

> [!NOTE]
> There is no Composer, no npm and no compile step - in development or in production. The browser loads Vue and every other library as ES modules through an import map, so deploying is copying files.

## Install

<div class="steps">

1. **Download a release.** Take the latest zip from [GitHub Releases](https://github.com/ZcarecroW/CourseForge/releases/latest) ("Source code (zip)"), or clone the repository:

   ```bash
   git clone https://github.com/ZcarecroW/CourseForge.git courseforge
   ```

2. **Copy the files to your web root**, or to a subdirectory of it. The document root is the folder holding `index.html` and `api/`. A subdirectory works as it is: every URL is relative.

3. **Make `data/` writable** by the user PHP runs as. The SQLite database (`data/app.sqlite`, created and migrated on first open), the settings this installation changes, the staged updates and the backups all live there.

   ```bash
   chmod 775 data        # or chown it to the PHP user
   ```

4. **Open the address in a browser.** CourseForge writes a one-time code to `INVITE-CODE.txt` next to `index.html` - or into `data/` if the install root is not writable - and shows the setup screen, which names the path it used.

5. **Create the first administrator.** Copy the code out of that file into the setup screen, with a user name and a password of at least ten characters. That account is the first administrator and is signed in straight away. The file is deleted the moment it is used.

</div>

> [!TIP]
> If you have a shell, `php tools/diagnose.php` checks the installation and prints what is wrong: a read-only data directory, a missing extension, a scheduler that has never ticked. The same rows are on **Administration › Settings › Diagnostics** for hosts with no shell.

### Why a code in a file?

A fresh installation is a web address with no accounts and a form that creates the first one. Whoever reaches that form first would own the installation, and bots scanning for new hosts are faster than people. The code lives only in a file on the server, so only somebody who could put files on the server can use it. It is six groups of four characters from an alphabet without look-alikes (no `I`, `O`, `0` or `1`); case, spaces and hyphens are ignored when you type it.

Once any account exists, the setup route refuses to create anything, for good. If the file is lost before it is used, delete the open row from the `invites` table and reload: a fresh code is written. More on accounts in [Accounts and roles](accounts.md).

## After the first sign-in

A guided tour starts by itself and walks through every screen. Then do these three things, in this order.

<div class="steps">

1. **Pass the security check.** Open **Administration › Security**. CourseForge asks the server for its own private files over HTTP and reports which came back. Until every one is refused, all fields that would store an API key or a token stay locked, with a red mark. On Apache with the shipped `.htaccess` this passes by itself; on nginx, Caddy or IIS paste the configuration block the screen gives you. See [Security](security.md).
2. **Set up the scheduler.** Open **Administration › Settings › Scheduler**, generate a cron token and paste the URL it gives you into your host's cron, every minute. See [The scheduler](scheduler.md).
3. **Create a profile** with an AI account and, if you publish, a BookStack instance. Then create your first course. The [Quick start](quick-start.md) walks through it.

</div>

## PHP limits: Set up PHP

Shared hosting usually hands out limits chosen for a blog: sixty seconds of execution, a socket timeout shorter than a model takes to write a page, 128 MB of memory. **Administration › Settings › Set up PHP** measures what the host gives and writes a `.user.ini` that raises exactly the limits that are too low. It runs by itself the first time an administrator opens the application.

**Every number is a floor, never a value**: a host that gives 768 MB keeps 768 MB; a host that gives 128 MB is asked for 256 MB. Nothing is ever lowered.

| Directive | Floor | Why |
|---|---|---|
| `memory_limit` | 256M | A course tree, its pages and an update archive are held at once. |
| `max_execution_time` | 300 | Writing a page, publishing a book and installing an update all take longer than a minute. |
| `default_socket_timeout` | 300 | A model can take minutes to answer; at sixty seconds the page is cut off and recorded as failed. |
| `max_input_time` | 300 | How long PHP spends reading a request body. |
| `post_max_size` | 32M | A finished page, its outline and its tags arrive in one request. |
| `upload_max_filesize` | 32M | Restoring a backup means uploading the archive an update made. |
| `max_input_vars` | 5000 | A course with hundreds of pages sends more fields than the default thousand. |
| `display_errors` | Off | An error page that prints a file path tells a stranger where the database is. |
| `log_errors` | On | What is not displayed still has to go somewhere. |
| `output_buffering` | Off | A long answer held in a buffer is held in memory twice. |

`.user.ini` is read by the CGI, FastCGI and FPM SAPIs. Under **mod_php** it is ignored; CourseForge then tells you which `php_value` lines to add to `.htaccess` and leaves the adding to you, because one wrong `php_value` takes the whole site down. A directive the host locks at system level is listed as *host decides* rather than written. Anything you write outside the managed block is never touched.

> [!WARNING]
> Your web server has its own time limit too: `fastcgi_read_timeout` on nginx, a proxy timeout, a hosting panel setting. The smallest limit wins. A limit below a few minutes is the most common cause of "generation stops half way" on a new installation. See [Troubleshooting](troubleshooting.md).

## Keep the data directory out of the web root

`data/app.sqlite` holds every password hash and, in plain text, every AI key and BookStack token the installation has been given. The shipped `.htaccess` denies it, and CourseForge rewrites `data/.htaccess` itself if it goes missing - but the arrangement that is safe on **every** server is a data directory that is not under the document root at all:

```apache
SetEnv COURSEFORGE_DATA_DIR /var/lib/courseforge
```

```nginx
fastcgi_param COURSEFORGE_DATA_DIR /var/lib/courseforge;
```

Then only `index.html`, `assets/`, `api/`, `cron.php` and `bs.php` need to be public. `config/` must stay readable by PHP but not by the web. The invite code goes into the data directory in this arrangement, since the install root is usually not writable.

## Trying it on your own machine

PHP's built-in server is enough to evaluate CourseForge on a laptop:

```bash
cd courseforge
php -S 127.0.0.1:8080 -t . tools/router-dev.php
```

Then open `http://127.0.0.1:8080/`. `tools/router-dev.php` reproduces the most important `.htaccess` rules, but not all of them: under `php -S`, `config/defaults.json` and `INVITE-CODE.txt` can be downloaded. That is fine on a laptop and not a way to serve CourseForge to anybody else.

To keep the database away from the code while you try things, point the data directory elsewhere:

```bash
COURSEFORGE_DATA_DIR=/tmp/cf-data php -S 127.0.0.1:8080 -t . tools/router-dev.php
```

## Where things live

| Path | What it is | Who writes it |
|---|---|---|
| `index.html`, `assets/` | The single-page application and its vendored libraries | the release |
| `api/index.php` | The JSON API the browser talks to | the release |
| `api/mcp.php` | The MCP endpoint AI clients connect to | the release |
| `cron.php` | The scheduler endpoint a control-panel cron calls | the release |
| `bs.php` | Serves BookStackDev looks to your wikis | the release |
| `config/defaults.json` | Shipped settings, content details and the whole prompt library | the release, replaced by every update |
| `src/` | The PHP application | the release |
| `tools/` | Command-line helpers: `diagnose.php`, `cron.php`, `update.php` | the release |
| `data/` | `app.sqlite`, `config.json` (your overrides), backups, staged updates | this installation, never an update |

Updates replace everything except `data/`, `INVITE-CODE.txt`, `.git` and `.github`. See [Updates](updates.md).

## Upgrading an existing installation

From any 5.x release, use **Administration › Updates** or `php tools/update.php --install`. The database migrates itself on first start. Coming from 4.x, 3.x or 2.x? See [Upgrading](upgrading.md).
