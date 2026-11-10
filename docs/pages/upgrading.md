---
title: Upgrading
description: Moving to the current release from 5.x, 4.x, 3.x or 2.x - what migrates by itself and what to check by hand.
---

Every release migrates its own database on first start, guarded by a schema version, and never touches a value you have since edited. Take a copy of `data/` before a major upgrade anyway.

## From 5.x

Use **Administration › Updates** or `php tools/update.php --install`. See [Updates](updates.md).

**5.3 → 5.4:** no database change. A live page that researches now really searches on OpenAI, where it silently did not before, and is billed for those searches. The Nebius preset points at `api.tokenfactory.nebius.com`; an account saved with the old address keeps working.

**5.2 → 5.3:** the database gains one column (`pages.scope`). Existing courses have no page scopes until the outline is revised or **Plan missing scopes** is pressed on the Structure tab; until then they are written as before, with the compact course map. Claude is now asked on API version `2026-10-21`, so the profile's temperature no longer applies to Claude models.

**5.1 → 5.2:** publishing became a background task. Set up [the scheduler](scheduler.md) if you have not, or keep the Publish tab open while a push runs.

**→ 5.1:** the *Claude subscription* AI account type was retired. A profile that still has one keeps it, marked as retired; point it at an API provider, or [connect the Claude app over MCP](mcp.md) instead.

## From 4.6 or later 4.x

Nothing to do, and nothing is republished. Schema 8 moved each course's single destination into the destination list: one row with the same instance, shelf, book and fingerprint, and one record per chapter and page that had been published. The old columns are still written as a mirror of the first destination, so a rollback to 4.6 finds its first book intact; what a rollback loses is any second destination.

## From 3.x

Copy your `data/` directory across, or point the new installation at the old one with `COURSEFORGE_DATA_DIR`. Three things happen on first start, each exactly once:

- the database gains its new tables (`users`, `invites`, `audit_log`, `update_history`, `locks`, and more) and columns;
- `data/users.json` is imported as administrator accounts and renamed to `users.json.imported`;
- `data/config.json` is reduced to the settings you actually changed, so the new defaults apply to everything else.

AI accounts with no type recorded are read as the OpenAI-compatible accounts they were. Connection tokens issued by CourseForge 3 (`cf3_…`) stay valid until revoked.

> [!WARNING]
> `config/` is new since 4.0. A deployment that copies only the files it recognises will leave `config/defaults.json` behind, and nothing starts: the API answers *"the server is not configured correctly"* and the real message goes to the server log. `php tools/diagnose.php` names it outright.

## From 2.x

Point `data/` at your existing installation's data directory, or copy `app.sqlite`, `users.json` and `config.json` across. CourseForge adds the new columns and converts the old per-level `anki` flag into the content-detail system. Projects, chapters, pages, tags, tag links and profiles - including stored secrets and prompt overrides - carry over untouched; anything the 2.x catalogue did not know about falls back to the defaults.

## After any upgrade

- Open **Administration › Security** and check the verdict is still *secure*.
- Open **Settings › Diagnostics** and check the scheduler is ticking.
- Read the [changelog](changelog.md) for what changed in behaviour.
