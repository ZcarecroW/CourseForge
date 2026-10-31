---
title: MCP tool reference
nav: MCP tool reference
description: Every tool the MCP endpoint offers, grouped by scope, with its arguments - generated from the tool registry of this release.
toc: 2
---

CourseForge [[version]] offers **[[count:tools]] tools** in [[count:tool-groups]] groups. This page is generated from the tool registry itself, so it lists exactly what `tools/list` returns to a connection with every scope. Each tool also carries protocol annotations - whether it reads or writes, whether it is destructive, whether it spends credit - which clients may use to warn before calling it.

<p><span class="badge badge--read">reads</span> changes nothing &nbsp; <span class="badge badge--write">writes</span> changes data &nbsp; <span class="badge badge--danger">destructive</span> deletes something &nbsp; <span class="badge badge--spend">spends credit</span> calls the account's own AI provider &nbsp; <span class="badge badge--admin">admin</span> administrators only &nbsp; <span class="req">*</span> required argument</p>

## The groups

::tool-groups

How to connect, narrow a connection and choose between writing pages yourself and having CourseForge write them: [Connect an AI client](mcp.md).

::tools
