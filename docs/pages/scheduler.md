---
title: The scheduler
description: One call a minute is what lets background runs, batch collection, publishing and unattended updates carry on with the browser closed.
---

Background runs, batch collection, publish tasks, the security re-check and unattended updates all need something to call CourseForge **once a minute**. Without it, CourseForge still works - but only while a browser tab is open.

| Without a scheduler | With one |
|---|---|
| Pages are written only by an open tab | Background runs and batches, with the tab closed |
| A push is worked by the open tab, one slice per request | A push carries on with the laptop shut |
| Batches are never collected | Batches are polled and collected |
| No unattended updates | Daily update checks and optional installs |

## Setting it up

<div class="steps">

1. Open **Administration › Settings › Scheduler** and click **Generate** next to the cron token. The card shows the finished URL and the crontab line, with the token and the absolute path filled in.
2. **On a host with a control panel** (all-inkl/KAS, Plesk, cPanel, DirectAdmin…), add a cron job that calls the URL every minute:

   ```text
   https://your-install/cron.php?token=<your cron token>      every minute
   ```

   The token can also be sent as an `X-Cron-Token` header, for a scheduler that supports headers.

3. **On a host with a real crontab**, skip the web server and the token entirely:

   ```cron
   * * * * * php /path/to/courseforge/tools/cron.php --quiet
   ```

4. Watch the card: it shows **how long ago the last tick was**. That number is the one to check - a scheduler configured six months ago that stopped calling last week looks exactly like one that was never set up.

</div>

`php tools/diagnose.php` and the Scheduler row of **Diagnostics** say whether it is actually running.

## What a tick does

In this order, and for a reason:

1. gives back any page or task whose worker died (an expired lease);
2. collects finished provider batches;
3. works publish tasks, then writes pages, until its time is up;
4. only then considers an unattended update - because an update replaces the very files the tick is running, everything still owed to a course is done first.

The scheduler also re-takes the [security check](security.md) every six hours. Nothing is unbounded. A tick cut short by a host time limit loses nothing, because every claim is a lease with an expiry and every page is stored the moment it is finished.

## Parallelism

`app.cron_workers` decides how many ticks may work side by side. With two, a tick that arrives while the previous one is still busy takes the second slot instead of waiting, and roughly twice as many pages get written per minute. A tick that finds every slot busy exits immediately, so calling once a minute is safe at any setting. Raise it only as far as your provider's rate limits allow.

The other scheduler settings - how long a tick works, how often a failed page is retried, how long a worker's claim lasts, how many attempts a publish task gets - are listed on the [Settings reference](settings.md#scheduler).

## Security of the endpoint

`cron.php` in the document root is *meant* to be reachable: it is the only thing a control-panel scheduler can call. It compares the token in constant time and answers **404** to everything else, including when no token is configured - so an installation that never set one has no open endpoint. Leading and trailing spaces are trimmed, because a token pasted into a control panel often arrives with one.

Run from a shell, `cron.php` refuses and points at `tools/cron.php`, with a failing exit status - the likely reader is a crontab pointed at the wrong file, and a clean exit would let it report success every minute while nothing ticks.

Rotating the token invalidates the old URL immediately: update the control panel in the same sitting.
