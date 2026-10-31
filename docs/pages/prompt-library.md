---
title: The prompt library
description: Every instruction CourseForge sends is a named, documented slot you can override for the whole installation or for one profile. The full reference, with the shipped text.
toc: 2
---

Every instruction CourseForge sends to a model is a named **slot**. There are [[count:slots]] of them, they ship in `config/defaults.json`, and every one can be overridden. Nothing the AI is told is hidden in code, apart from two rules the application depends on: never emit a level-1 heading, never emit raw HTML.

![The Prompts screen](images/prompts.webp "Administration › Prompts: group, slot and editor, with the placeholders the slot understands as clickable chips.")

## Two layers

| Layer | Where | Who | Applies to |
|---|---|---|---|
| **Installation** | Administration › Prompts | an administrator | every course, unless a profile disagrees |
| **Profile** | Profiles › Prompts | anybody, on their own profiles | the courses that use that profile |

**The profile layer wins.** Both screens say so at the top, show the same slots in the same order under the same labels, and mark the ones that have been changed - they are literally the same component.

The two layers treat an **empty** slot differently, on purpose:

- a **profile** override that is deliberately empty is honoured: that slot sends nothing, which is how you switch a block of instructions off for one profile's courses;
- clearing a slot on the **installation** screen removes the override, so the shipped default applies again.

A handful of slots the pipeline cannot work without (`page_system`, `page_rules`, `page_user`, `overview_user`…) fall back to a compact built-in text rather than sending nothing, whichever layer emptied them.

## Editing

Both screens are three panes: group, slot, editor. The search ignores the groups, because the usual question is *"where is the one about diagrams?"*. Each slot shows its description and the **placeholders** it understands as chips; clicking one inserts it at the cursor. The editor is the same CodeMirror the pages use, with `{{placeholder}}` highlighting.

Placeholders are written `{{name}}`. Substitution only replaces names it knows, so Anki's own cloze syntax `{{c1::…}}` passes through untouched.

If a profile's BookStack instance wears a [BookStackDev look](bookstackdev.md) whose conventions disagree with a slot - formulas switched off, different delimiters - the slot shows a note with a button that applies the recommended wording.

## The groups

| Group | Sent when |
|---|---|
| **Global** | with every request: persona, language reminder, audience |
| **Course structure** | designing or revising an outline, the research briefing, planning page scopes |
| **Page writing** | writing one page: persona, formatting, length, course and chapter context, the brief, written neighbours, feedback |
| **Content details** | one *enabled* and one *disabled* text per [content detail](content-details.md) element |

## How a page prompt is assembled

In the order it is sent:

```text
shared  = page_context_block      the brief, the description, the course map with every page's scope
          + research_block        (only when research is stored)      <- the same for every page of the course
        + page_chapter_block      the chapter and its goal            <- the same for every page of the chapter

system  = global_system
        + audience_block          (only when an audience is set)
        + page_system
        + page_rules
        + length_rules            (only when a minimum or maximum length is set)
        + feature_<element>_on|off   for every element, in catalogue order
        + the two hard rules enforced in code
        + language_instruction

user    = page_user               this page: its place, its scope, its tags, its neighbours
        + page_written_block      (only when pages of its chapter are written: their headings)
        + extra_context_block     (only when the page has extra context)
        + page_regenerate_user    (only on a rewrite with feedback)
```

The shared segments go first and reach the provider separately from the system text, so its prompt cache can stop right behind them ([Tokens and caching](token-economy.md)). Anything that differs from page to page belongs in `page_user`: one changed byte early in a prompt is a cache miss for everything after it.

`{{course_structure}}` is the compact course map; `{{course_structure_full}}` is still offered for a library that wants the whole outline, with every chapter description, sent with every page - at the cost the map was built to avoid.

## Placeholders

The most useful ones:

| Placeholder | Is |
|---|---|
| `{{topic}}` | the course brief |
| `{{book_title}}`, `{{book_description}}` | from the outline |
| `{{course_structure}}` | the course map: every page with its scope |
| `{{chapter_index}}`, `{{chapter_title}}`, `{{chapter_description}}` | the page's chapter |
| `{{page_index}}`, `{{page_title}}`, `{{page_ref}}` | the page; `page_ref` is its `chapter.page` number |
| `{{page_scope}}` | what this page alone teaches (or a note that it was not planned) |
| `{{previous_page}}`, `{{next_page}}` | the neighbours, as map lines |
| `{{written_pages}}` | the headings of the written pages of this chapter |
| `{{tags}}` | the page's effective tags |
| `{{extra_context}}`, `{{feedback}}`, `{{existing_content}}` | per-page steering and the rewrite inputs |
| `{{research_block}}`, `{{research_findings}}` | the research briefing |
| `{{language}}`, `{{audience}}`, `{{app_name}}` | global |
| `{{min_length}}`, `{{max_length}}`, `{{diagram_max}}`, `{{exercise_count}}`, `{{anki_cards}}`, `{{link_count}}`, `{{research_max_searches}}` | content detail values |

Every placeholder, and which slots offer it:

::placeholders

## Over MCP

An administrator's connection reads and writes the installation layer with `get_prompts` and `set_prompts`; anybody can read the catalogue with `list_prompt_slots` and override slots on their own profile with `set_profile_prompts`. The same catalogue and the same validation as the screens.

## Every slot

The reference below is generated from `config/defaults.json` for this release. Expand a slot to read the text it ships with.

::slots
