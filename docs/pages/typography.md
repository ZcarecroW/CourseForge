---
title: Punctuation
description: Quotation marks, apostrophes, dashes and spacing set the way the course language sets them - automatically for new pages, on a button for finished ones.
---

A model writing German opens a quotation with `„` and closes it with `"`. The mistake is too small to notice on one page and it is on every page of a five-hundred-page book. CourseForge fixes it with code rather than with a sentence in a prompt, because a rule that runs beats a rule that is asked for.

## What it does

A generated page has its punctuation set before it is stored:

| Language | Written | Stored |
|---|---|---|
| German | `„Wort"`, `**"Wort"**`, `Wort—Wort` | `„Wort“`, `**„Wort“**`, `Wort – Wort` |
| French | `"mot"`, `« mot »`, `mot ;` | `« mot »` with narrow spaces, `mot ;` |
| Spanish, Italian, Greek | `"parola"` | `«parola»` |
| Polish, Hungarian | `"słowo"` | `„słowo”` |
| Czech, Slovak | `"slovo"` | `„slovo“` |
| Russian, Ukrainian | `"слово"` | `«слово»` |
| Swedish, Finnish | `"ord"` | `”ord”` |
| English and the rest | `"word"`, `don't`, `'90s` | `“word”`, `don’t`, `’90s` |
| every language | `...`, `a - b`, `1990-2000`, `5"` | `…`, `a – b`, `1990–2000`, `5″` |
| every language | `zwei  Wörter ,` and three blank lines | one space, `,` against the word, one blank line |

A language CourseForge does not recognise is set the English way. The course language is free text, so it is recognised rather than validated; a two-letter code only matches exactly (`es` is Spanish, *Estonian* is not).

## What it never touches

Anything that is not prose: fenced code blocks, inline code, links and their targets, bare addresses, HTML tags and comments, formulas, a table's alignment row, characters escaped on purpose, cross-reference markers and cloze deletions. They are lifted out before the rules run and put back exactly as they were. A curly quote inside a JSON example is not typography, it is a broken example.

It reads a quotation mark the way a reader does: by **position** first (a mark glued to text on one side has said which side it is on, stepping over Markdown emphasis), by **depth** second, and by the glyph last. Nested quotations come out as `„… ‚…‘ …“` whatever was typed.

It is **idempotent** - a page set twice is byte-for-byte the page set once - and **bounded**: quotation depth is forgotten at every block boundary, so one unbalanced mark can confuse its own paragraph and nothing after it.

It runs where a page is stored, which is where all three roads meet: the in-tab generator, a background or batch run, and a page an MCP client stored with `write_page`. It never touches the outline's titles, because titles are matched byte for byte when an outline is applied.

## Switching it off

Each profile decides: **Profiles › Models & output › Correct the punctuation of a generated page**. A profile that has never decided follows `app.typography` on **Administration › Settings**, which is on.

## Correcting a course that is already written

Pages stored before this existed, with the setting off, or under older and worse rules, can be corrected on a button, at three scopes:

- **the whole course**: Course › **Settings** › **Punctuation**;
- **a chapter and its pages**: **Punctuation** beside **Publish chapter** in the chapter editor;
- **one page**: **Punctuation** in the inspector.

It asks first. The button runs the whole pass with writing switched off and reports the exact number that would change - *"of 41 pages and 6 chapters, this would change 12 pages and 1 chapter"*, naming the first forty - and only then offers to do it.

It runs whatever the profile's switch says: pressing the button is an instruction, not a preference.

It touches every page's Markdown and title, every chapter's title and goal, and the book's title and description, then rewrites the outline from the corrected titles. A page's extra context and the research briefing are left alone - they are instructions to the writer, not text anybody reads.

It costs nothing: no model is called. A corrected page differs from the published one, so it shows as out of sync until the next push. A page that needed nothing is not written at all and does not go out of sync.

## Over MCP

`fix_typography` with `course_id`, an optional `chapter_id` or `page_id` (a page wins over a chapter, which wins over the course), an optional `language`, and `preview` to see what would change without changing it.
