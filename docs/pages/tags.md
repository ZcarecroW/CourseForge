---
title: Tags
description: A reusable tag library for courses, chapters and pages, inherited downwards and published to BookStack - with the AI proposing tags if you let it.
---

Tags are a reusable library (**Tags** in the sidebar) that can be attached to a course, a chapter or a page. What reaches BookStack is each item's **effective** set: its own tags plus the ones it inherits.

## Attaching tags

- On the outline: a `{{Tag, Tag}}` marker behind a title, or on the line below it, tags the book, a chapter or a page when the outline is applied.
- On the Content tab: the inspector's **tags** tab, for the selected chapter or page.
- On the course: **Settings › Course tags**.

A tag can carry a **value** as well as a name, which BookStack shows as `name: value`.

## Inheritance

A tag marked as **inheriting** flows down: from the course to every chapter and page, from a chapter to its pages. Use it for what is true of everything below - the subject, the audience, the version a course covers. Leave it off for tags that describe one page.

## AI tagging

Switch on **AI tagging** on the course's **Settings** tab and the model proposes tags while it designs the outline. They appear in violet, and each can be deactivated individually without being deleted. A deactivated tag is ignored everywhere: in prompts, in the fingerprints that decide whether a page is in sync, and in BookStack. AI tagging never re-enables a tag you deactivated and never downgrades one you attached by hand.

You can restrict the AI to a **tag pool** - the tags it may choose from - optionally strictly, so it may not invent new ones.

## Tags and publishing

A tag's name and value are part of what CourseForge fingerprints to decide whether a page is out of sync with BookStack. Changing a tag, or its value, marks the pages that carry it as changed, and the next push rewrites them.

## Over MCP

`list_tags`, `create_tag`, `update_tag`, `delete_tag`, `get_course_tags`, `attach_tag`, `detach_tag` and `set_tag_link`. For an administrator, `list_tags` returns every account's tags, each marked with its owner. See the [MCP tool reference](mcp-tools.md#tags).
