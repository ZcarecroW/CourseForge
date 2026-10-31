---
title: Updates
description: CourseForge updates itself from GitHub - from the Updates screen, the command line, or unattended - taking a complete backup first and rolling back if the new version cannot prove it works.
---

CourseForge can replace itself with a newer release from GitHub: from **Administration › Updates**, from `php tools/update.php --install`, or unattended at a fixed hour. It is the most dangerous thing the application does - the files being replaced are the files doing the replacing - so every step is arranged around one rule: **a failed update must leave the old version running.**

## Checking

A check reads the latest release from `api.github.com` (or the twenty most recent when the channel includes pre-releases) and caches the answer for an hour; a failed check backs off for fifteen minutes. Both windows exist because GitHub allows sixty unauthenticated requests an hour per address. **Check now** forces a fresh answer, and installing always asks afresh.

A 403 from GitHub is usually the rate limit; the screen reports when it lifts. Set `updates.github_token` to raise the allowance, or to update from a private fork.

## Preconditions

Every condition is checked separately, before anything is downloaded, so the answer is never just "the update failed":

| Check | Blocking | Why |
|---|---|---|
| Updates are switched on | yes | `updates.enabled` off means CourseForge never contacts GitHub |
| A repository is configured | yes | nowhere to fetch from otherwise |
| PHP can open a zip archive | yes, for zip releases | `ext-zip` |
| PHP may write to the installation | yes | it is about to rewrite the document root |
| PHP may write to the data directory | yes | the download, staging and backup live there |
| No update is already running | yes | one at a time, across every tab and worker |
| A newer version is available | yes | reinstalling the same version is not an update |
| No generation run is in flight | warning | pages are stored as they finish; a worker mid-request is retried later |
| Room for the download and a backup | warning | about three times the release size |

`php tools/update.php --status` prints the same rows with `OK`, `WARN` or `FAIL`.

## What an install does

<div class="steps">

1. **Download** the release asset, or GitHub's zipball when there is none, straight to a file - never into memory. Only GitHub's own hosts are ever fetched from.
2. **Verify** the SHA-256 digest if the release publishes one. A published digest that does not match is never allowed through; a missing one is allowed and logged.
3. **Extract** into `data/updates/`, checking every entry name first - a zip may contain `../../etc/passwd`.
4. **Confirm it is CourseForge** by looking for `src/bootstrap.php`, `config/defaults.json` and `api/index.php`.
5. **Warm up**: load every class the rest of the update needs while the old files are still on disk, so nothing from the new release is mixed into the running process.
6. **Back up, completely**, before replacing anything: every file the swap will touch, plus a manifest, in one zip under `data/backups/`.
7. **Swap**, file by file. The install root is never renamed wholesale - the web server is pointed at it, and the running script lives inside it.
8. **Prove it works**, and roll back if it does not.

</div>

`data/` is never touched, whatever the archive contains, and neither are `INVITE-CODE.txt`, `.git` and `.github`.

## The smoke check

A finished update has to prove itself:

- every installed PHP file is parsed - which catches a copy cut short by a full disk;
- the new `config/defaults.json` is read and the database is opened;
- the version is read back out of the installed `src/bootstrap.php` and must be newer than the old one;
- then a **child process** boots the new code from scratch, loads every class and opens the database - which runs the new release's migration - and reports the schema version it arrived at.

The child process is the part that matters: the running process still holds the old classes and the old database handle, so only a fresh PHP can prove the new release starts and migrates. On hosts where PHP may not start programs (`proc_open` disabled, as on most shared hosting) that one check is skipped, and the log says so plainly: *the database migration was NOT tested*.

## Rollback

If the smoke check reports anything - or anything throws after the backup exists - the backup goes straight back, the configuration cache is flushed, and the history records `rolled_back` with the reason. If even the rollback fails, the log says where the archive is so it can be unpacked over the installation by hand.

**Rollback** on the Updates screen, or `php tools/update.php --rollback`, restores the most recent backup on demand: files back, files the update added removed, and the file-level checks run again.

`updates.keep_backups` decides how many backups are kept (two by default). Zero deletes the one just taken - and the log says the update cannot be rolled back. Every attempt is a row in the update history with its full log, and a line in the audit log.

## Unattended updates

- `updates.auto_check` asks GitHub once a day, so the Updates screen is current when you open it.
- `updates.auto_install` installs at `updates.auto_time` in `updates.timezone`. Off by default.

Both need [the scheduler](scheduler.md). A few deliberate details:

- **"Once a day" means a calendar day**, not a minute: a host that runs cron every five minutes, a tick that overran or a server asleep at the chosen hour still gets its update that day - late rather than not at all. A clock put back an hour cannot cause a second run.
- **A busy installation gets two hours' grace.** A run in flight defers the update; after two hours it goes ahead, because a run open all morning is more likely stuck than busy.
- **The day's slot is spent before the install starts**, so an update that crashes the process is not retried a minute later. One that was *refused* - a failed precondition - gives the slot back.

## From the command line

`tools/update.php` does the same work without a browser tab that can be closed half way:

```bash
php tools/update.php --status      # what is installed, what is available, preconditions
php tools/update.php --check       # ask GitHub now, ignoring the cache
php tools/update.php --install     # install the newest release on the channel
php tools/update.php --rollback    # restore the most recent backup
```

Nothing is interactive. Add `--quiet` to print only what went wrong. It is also the way in when the web application has stopped answering.

## Over MCP

`check_for_update`, `get_update_status`, `install_update`, `rollback_update` and `get_update_history`, in the `admin` group.
