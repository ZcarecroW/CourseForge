---
title: Settings
nav: Settings reference
description: Every setting an administrator can change, what it does and its default - generated from the one declaration the Settings screen, the API and the MCP tools all read.
toc: 2
---

**Administration › Settings** holds everything the installation can be told to do differently, one card per group. There are [[count:settings]] settings in [[count:setting-groups]] groups. Each field carries its own description, its range and whether this installation has changed it.

![Settings: the scheduler card](images/settings.webp "Settings, one card per group. The scheduler card writes the cron line out for you.")

## How settings are stored

```text
config/defaults.json   ships with the release: settings, the security policy, the content
                       details and the whole prompt library. Replaced by every update.
                       Never written to.
data/config.json       what this installation changed, and only that. Written from the
                       admin screens. No update touches it.
```

Splitting them is what makes an update safe: a new release brings new defaults and new prompt slots, and your decisions live in a file the update may not write. It also makes **Reset** a delete rather than a copy - setting a value back to the shipped default removes it from `data/config.json`, which stays a short, readable list of what this installation actually decided.

Every setting is declared once in the code (`Support\Settings`). The Settings screen draws a field from that declaration, the API validates against it, and the MCP tools describe and change settings from it - so a setting added in a release appears in all three at once. The reference below is generated from the same declaration.

## The ones with consequences

**`app.cron_token`** - the secret in the URL your host calls once a minute. Without one, `cron.php` answers 404 to everybody and CourseForge offers no background runs at all. At least sixteen characters; the screen generates one and hands you the finished URL. Rotating it invalidates the old URL immediately. See [The scheduler](scheduler.md).

**`app.cron_workers`** - how many scheduler ticks may work side by side, which is where parallelism in background generation comes from. Raise it only as far as your provider's rate limits allow; too high gives a run full of 429 errors, not a faster course.

**The timeouts** - `app.ai_timeout_seconds` defaults to 1800, because a long page on a reasoning model takes minutes; below about 600 you will lose work the provider was about to deliver. Your web server has its own limit too, and the smallest one wins.

**`updates.auto_install`** - off by default. On, the installation replaces its own files unattended at the configured hour. It backs up first and rolls back if the new version fails to start, but it is still an unattended change to a production system. See [Updates](updates.md).

**`mcp.allowed_origins`** - empty by default, which refuses every *browser-based* MCP client and affects nothing else. Add an exact origin only when you have such a client.

**`app.debug`** - puts exception details and stack traces into API responses and MCP errors. Leave it off on anything reachable from the internet.

**`app.task_max_attempts`** - how often a publish that ran into trouble is resumed before it is given up (twenty by default). Each attempt waits longer, up to a quarter of an hour.

Two settings hold secrets - `app.cron_token` and `updates.github_token`. Neither is ever sent to a browser: the screen is told whether one is stored, not what it is, and saving an empty box leaves the stored value alone. Neither can be stored until [Security](security.md) has passed.

## Diagnostics and Set up PHP

The Settings screen also carries **Diagnostics** - the same rows `php tools/diagnose.php` prints: PHP version and extensions, writable directories, the database, the scheduler's last tick, open invites - and **Set up PHP**, which raises PHP limits that are too low through `.user.ini` ([Installation](installation.md#php-limits-set-up-php)).

## Over MCP

`list_settings`, `set_settings` and `reset_setting` in the `admin` group reach every setting by its key, with the same validation as the screen. `generate_cron_token` rotates the cron token; `get_cron_url` reads the URL.

## Every setting

::settings
