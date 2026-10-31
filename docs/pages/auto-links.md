---
title: Auto links
description: Cross references the AI marks while it writes, resolved into real BookStack links after publishing - with no second AI call.
---

Switch the **Auto links** element on for a course ([Content details](content-details.md)) and the AI marks cross references to other chapters and pages while it writes:

```markdown
The reactive system (🔗 Reactive state with ref and reactive) does this for you.
```

At that moment it is plain text, and it needs to be: when page 3 is written, page 40 may not exist in BookStack yet. The **Auto links per page** value caps how many a page may contain.

## Resolving

After a course has been published, CourseForge walks every page and rewrites the markers into real links - **programmatically, with no second AI call**:

```markdown
The reactive system ([🔗 Reactive state with ref and reactive](https://wiki.example.com/books/vue/page/reactive-state-with-ref-and-reactive)) does this for you.
```

It runs automatically at the end of every full publish, and on demand from **Publish › Resolve auto links** (or **Resolve links here** on one destination). Only pages whose text actually changed are re-sent. Each destination resolves links against its own book, because the same page has a different address in every wiki.

## How a marker is matched

1. the title exactly as written;
2. a normalised comparison - case, punctuation and spacing ignored;
3. a unique prefix - `(🔗 Reactivity)` finds *Reactivity in depth*;
4. the closest match above a similarity floor.

## What happens in each case

| Situation | Result |
|---|---|
| The target exists and is published | a real link, with the target's **own** spelling |
| The target exists but is not published yet | the marker stays, and resolves on the next publish |
| No such chapter or page | published as plain text `(Title)`, and reported in the log |
| The page refers to itself | published as plain text |
| The marker is inside a code block or inline code | left completely alone |

## Why it is safe to run as often as you like

- **The stored page keeps the raw marker.** Resolution happens on the way out, so it is repeatable, survives a regeneration and can never corrupt the source text. Renaming a target turns its stale references back into plain text rather than pointing them at the wrong page.
- **What is fingerprinted is what is sent.** The out-of-sync badges account for resolved links, so a link change is detected exactly once and never loops.

The Content tab shows a link icon on every page that carries markers; the course header and the Publish tab show *resolved / written* for the whole course. Over MCP, `list_unresolved_links` lists the markers that did not resolve.
