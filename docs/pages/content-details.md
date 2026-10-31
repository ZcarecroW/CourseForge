---
title: Content details
description: The elements a page is made of and the values that size them, decided once and inherited from the installation down to a single page.
---

Every page is assembled from **elements** that can be switched on or off - a summary, exercises, diagrams, formulas, Anki cards - and **values** that size them: minimum and maximum length, how many exercises, for which audience. Both are decided at five levels and inherited downwards.

::diagram inheritance | Each level either decides or inherits. Only the levels that decide store anything.

| Level | Where | Typical use |
|---|---|---|
| **Installation** | `config/defaults.json`, overridden on **Administration › Settings › Course defaults** | The baseline for a whole installation that teaches one subject to one audience. |
| **Profile** | **Profiles › Content defaults** | A house style shared by every course written with that profile. |
| **Course** | The course's **Details** tab | What this course needs. |
| **Chapter** | The Content tab's inspector with a chapter selected | A chapter of exercises, a chapter without code. |
| **Page** | The Content tab's inspector, **details** tab | The one page that needs a diagram budget of five. |

![The Details tab](images/details.webp "The Details tab. Each element row has a three-way switch; each value shows your number or the inherited one, greyed.")

## Deciding, or inheriting

Below the installation, every element has a three-way switch:

| Position | Meaning |
|---|---|
| **↳ on / ↳ off** | inherit - the arrow shows what the level above currently resolves to |
| **On** | force on, here and for everything below |
| **Off** | force off, here and for everything below |

A value box shows your own number, or nothing with the inherited number as a greyed placeholder; the ✕ goes back to inheriting. A value whose element is currently off is dimmed, because it changes nothing.

**Only deviations are stored.** A course with nothing overridden carries an empty settings object, so changing a default reaches every course that never disagreed with it - including new defaults that an update brings. The Details tab says where its "inherit" points: *the profile "House style"* or *the installation defaults*, with a button to open the profile.

The Details tab also lists every chapter and page that overrides something, with a one-click way to make it follow the course again.

## The elements

::elements

Switching an element **off** is not the same as leaving it out: CourseForge sends an explicit instruction not to produce it, because models fall back on their own habits when a topic is simply not mentioned. Each element has two prompt slots - one sent when it is on, one when it is off - in the **Content details** group of the [prompt library](prompt-library.md).

## The values

::values

The length contract asks for a page between the minimum and maximum. A value can be left empty at every level, in which case its instruction is not sent.

## Changing the baseline

**Administration › Settings › Course defaults** shows every element and value, drawn the way the Details tab draws them but with a two-way switch, because there is nothing above the installation to inherit from. Changing one writes an override into `data/config.json` and leaves the shipped `config/defaults.json` alone. **Reset** drops the override and hands the decision back to the release.

> [!WARNING]
> Because only deviations are stored below the installation, changing a course default moves every course, chapter and page that never disagreed with it. That is the point - but it is worth knowing before you change one on an installation with courses already in it.

## Adding an element

The catalogue lives in `config/defaults.json` under `details`, as `features` and `params`. A new element is one entry there plus its two prompt slots - no code change, no migration. Its settings row, its Details row and its MCP support appear by themselves.

## Over MCP

| Tool | Does |
|---|---|
| `get_detail_catalogue` | Every element and value with its default and description. |
| `get_details` | What applies at one level (course, chapter or page) and where each answer comes from. |
| `set_details` / `reset_details` | Decide or go back to inheriting. Features as `1`, `-1` or `0`. |
| `list_detail_overrides` | Every chapter and page that overrides anything, in one call. |
| `set_profile_details` | The same for a profile. |

The installation baseline is reached with `set_settings`, by the keys `details.features.<element>.default` and `details.params.<value>.default`.
