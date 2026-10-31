#!/usr/bin/env node
/**
 * Builds the documentation site: docs/pages/*.md -> docs/dist/.
 *
 *   node docs/build.mjs                      PHP on the PATH, or in $PHP
 *   node docs/build.mjs --data data.json     use a docs-data dump instead of PHP
 *
 * No package to install, in keeping with the application it documents: the
 * Markdown is read by the marked build CourseForge already vendors, code is
 * highlighted at build time by the vendored Shiki (so a page ships no
 * highlighter at all), and the icons are the application's own set. The
 * reference pages - MCP tools, settings, prompt slots, presets - are generated
 * from tools/docs-data.php, which reads the one declaration each of them has in
 * the code.
 *
 * Every page is plain HTML with relative links, so the output works from any
 * base path: GitHub Pages, a subdirectory, or a file server on a laptop.
 */
import { readFileSync, writeFileSync, mkdirSync, readdirSync, rmSync, copyFileSync, existsSync } from 'node:fs';
import { join, dirname, extname, basename } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';

const DOCS = dirname(fileURLToPath(import.meta.url));
const ROOT = join(DOCS, '..');
const OUT = join(DOCS, 'dist');
const SITE_URL = 'https://zcarecrow.github.io/CourseForge/';
const REPO = 'https://github.com/ZcarecroW/CourseForge';

const started = performance.now();
const vendor = (path) => import(pathToFileURL(join(ROOT, 'assets/vendor', path)).href);

/* ------------------------------------------------------------------ the nav */

const NAV = [
  { title: 'Get started', icon: 'rocket', pages: ['introduction', 'installation', 'quick-start', 'interface'] },
  { title: 'Build a course', icon: 'book', pages: ['profiles', 'outline', 'writing-pages', 'runs', 'content-details', 'research', 'tags', 'typography'] },
  { title: 'Publish', icon: 'upload', pages: ['publishing', 'auto-links', 'bookstackdev'] },
  { title: 'AI and prompts', icon: 'sparkles', pages: ['providers', 'token-economy', 'prompt-library'] },
  { title: 'Automate with MCP', icon: 'plug', pages: ['mcp', 'mcp-tools'] },
  { title: 'Administer', icon: 'shield', pages: ['accounts', 'settings', 'scheduler', 'security', 'updates', 'upgrading'] },
  { title: 'Reference', icon: 'list', pages: ['troubleshooting', 'architecture', 'changelog'] },
];

/* ------------------------------------------------------------ the inputs */

const args = process.argv.slice(2);
const dataArg = args.indexOf('--data');
const data = JSON.parse(dataArg >= 0
  ? readFileSync(args[dataArg + 1], 'utf8')
  : execFileSync(process.env.PHP || 'php', [join(ROOT, 'tools/docs-data.php')], { encoding: 'utf8', maxBuffer: 64 << 20 }));

// The application's icon set, read out of the component rather than copied:
// a docs page uses the same glyph for the same thing the screen does.
const ICONS = {};
for (const [, name, markup] of readFileSync(join(ROOT, 'assets/js/components/AppIcon.js'), 'utf8')
  .matchAll(/^\s*"([a-z0-9-]+)": \/\* nucleo \d+ \*\/ "((?:[^"\\]|\\.)*)",?$/gm)) {
  ICONS[name] = JSON.parse(`"${markup}"`);
}
const icon = (name, cls = 'i') => `<svg class="${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONS[name] ?? ICONS.info}</svg>`;

/* ------------------------------------------------------------- highlighting */

const { createHighlighterCore } = await vendor('shiki/core.js');
const { createJavaScriptRegexEngine } = await vendor('shiki/engine-javascript.js');
const LANGS = ['shellscript', 'json', 'php', 'nginx', 'ini', 'toml', 'yaml', 'html', 'markdown', 'javascript', 'python', 'sql', 'powershell', 'apache', 'xml'];
const ALIAS = { bash: 'shellscript', sh: 'shellscript', shell: 'shellscript', console: 'shellscript', cron: 'shellscript', js: 'javascript', md: 'markdown', htaccess: 'apache', ps1: 'powershell', yml: 'yaml' };
const LABEL = { shellscript: 'bash', javascript: 'js', markdown: 'markdown', powershell: 'PowerShell', apache: 'Apache', nginx: 'nginx', caddy: 'Caddyfile', cron: 'crontab', text: 'text' };
const highlighter = await createHighlighterCore({
  themes: [vendor('shiki/themes/github-light-default.mjs'), vendor('shiki/themes/github-dark-default.mjs')],
  langs: LANGS.map((l) => vendor(`shiki/langs/${l}.mjs`)),
  engine: createJavaScriptRegexEngine(),
});

// Shiki writes both palettes into every token as an inline style. The same few
// dozen pairs repeat thousands of times across the site, so each distinct one
// becomes a class in the stylesheet instead.
const tokenClasses = new Map();
const compactTokens = (html) => html
  .replace(/<pre class="shiki[^"]*" style="[^"]*" tabindex="0">/, '<pre class="shiki" tabindex="0">')
  .replace(/ style="([^"]+)"/g, (_, style) => {
    if (!tokenClasses.has(style)) tokenClasses.set(style, `k${tokenClasses.size.toString(36)}`);
    return ` class="${tokenClasses.get(style)}"`;
  });

const escapeHtml = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const stripTags = (s) => s.replace(/<svg[\s\S]*?<\/svg>/g, ' ').replace(/<[^>]+>/g, '')
  .replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&');

function codeBlock(text, info) {
  const asked = (info || '').trim().split(/\s+/)[0].toLowerCase() || 'text';
  const lang = ALIAS[asked] ?? asked;
  const label = LABEL[asked] ?? LABEL[lang] ?? asked;
  const body = LANGS.includes(lang)
    ? compactTokens(highlighter.codeToHtml(text.replace(/\n$/, ''), { lang, themes: { light: 'github-light-default', dark: 'github-dark-default' }, defaultColor: false }))
    : `<pre class="shiki" tabindex="0"><code>${text.replace(/\n$/, '').split('\n').map((l) => `<span class="line">${escapeHtml(l)}</span>`).join('\n')}</code></pre>`;
  return `<div class="code"><div class="code__bar"><span class="code__lang">${escapeHtml(label)}</span>`
    + `<button class="code__copy" type="button" aria-label="Copy to clipboard">${icon('copy')}<span>Copy</span></button></div>${body}</div>\n`;
}

/* ------------------------------------------------------------------ images */

function imageSize(file) {
  const b = readFileSync(file);
  if (b.toString('ascii', 1, 4) === 'PNG') return [b.readUInt32BE(16), b.readUInt32BE(20)];
  if (b.toString('ascii', 8, 12) === 'WEBP') {
    const fmt = b.toString('ascii', 12, 16);
    if (fmt === 'VP8 ') return [b.readUInt16LE(26) & 0x3fff, b.readUInt16LE(28) & 0x3fff];
    if (fmt === 'VP8L') { const v = b.readUInt32LE(21); return [(v & 0x3fff) + 1, ((v >> 14) & 0x3fff) + 1]; }
    if (fmt === 'VP8X') return [1 + b.readUIntLE(24, 3), 1 + b.readUIntLE(27, 3)];
  }
  throw new Error(`cannot read the size of ${file}`);
}

/* ---------------------------------------------------------------- markdown */

const { Marked, Renderer } = await vendor('marked.esm.js');
const slugs = new Set(NAV.flatMap((s) => s.pages));
const problems = [];
let ctx; // the page being rendered: { slug, prefix, toc, ids, links }

const slugify = (text) => stripTags(text).toLowerCase().replace(/[`'’"“”]/g, '').replace(/[^a-z0-9_]+/g, '-').replace(/^-+|-+$/g, '') || 'section';
const uniqueId = (base) => {
  let id = base;
  for (let n = 2; ctx.ids.has(id); n++) id = `${base}-${n}`;
  ctx.ids.add(id);
  return id;
};

const marked = new Marked({ gfm: true });
marked.use({
  renderer: {
    heading({ tokens, depth }) {
      const html = this.parser.parseInline(tokens);
      const id = uniqueId(slugify(html));
      if (depth === 2 || (depth === 3 && ctx.tocDepth >= 3)) ctx.toc.push({ depth, id, text: stripTags(html) });
      return `<h${depth} id="${id}">${html}<a class="anchor" href="#${id}" aria-label="Link to this section">#</a></h${depth}>\n`;
    },
    code({ text, lang }) {
      return codeBlock(text, lang);
    },
    blockquote({ tokens }) {
      const body = this.parser.parse(tokens);
      const m = body.match(/^<p>\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\]\s*/);
      if (!m) return `<blockquote>${body}</blockquote>\n`;
      const kind = m[1].toLowerCase();
      const glyph = { note: 'info', tip: 'lightbulb', important: 'flag', warning: 'triangle-warning', caution: 'alert-circle' }[kind];
      const rest = body.slice(m[0].length).replace(/^<\/p>\s*/, '');
      const content = rest.startsWith('<') && !rest.startsWith('<code') && !rest.startsWith('<strong') && !rest.startsWith('<a ') && !rest.startsWith('<em') ? rest : `<p>${rest}`;
      return `<div class="callout callout--${kind}" role="note"><p class="callout__title">${icon(glyph)}${kind[0].toUpperCase() + kind.slice(1)}</p>${content}</div>\n`;
    },
    table(token) {
      return `<div class="table-wrap">${Renderer.prototype.table.call(this, token)}</div>\n`;
    },
    link({ href, title, tokens }) {
      const text = this.parser.parseInline(tokens);
      const t = title ? ` title="${escapeHtml(title)}"` : '';
      const page = href.match(/^([a-z0-9-]+)\.md(#[\w-]+)?$/);
      if (page) {
        const [, slug, hash = ''] = page;
        if (!slugs.has(slug) && slug !== 'index') problems.push(`${ctx.slug}: link to unknown page ${href}`);
        ctx.links.push({ slug, hash: hash.slice(1) });
        return `<a href="${ctx.prefix}${slug === 'index' ? '' : slug + '/'}${hash}"${t}>${text}</a>`;
      }
      if (href.startsWith('#')) {
        ctx.links.push({ slug: ctx.slug, hash: href.slice(1) });
        return `<a href="${href}"${t}>${text}</a>`;
      }
      const external = /^https?:/.test(href);
      return `<a href="${escapeHtml(href)}"${t}${external ? ' rel="noopener"' : ''}>${text}</a>`;
    },
    image({ href, title, text }) {
      if (!href.startsWith('images/')) return `<img src="${escapeHtml(href)}" alt="${escapeHtml(text)}">`;
      const [w, h] = imageSize(join(DOCS, href));
      return `<img src="${ctx.prefix}${href}" alt="${escapeHtml(text)}" width="${w}" height="${h}" loading="lazy" decoding="async">`
        + (title ? `<!--caption:${escapeHtml(title)}-->` : '');
    },
    paragraph({ tokens }) {
      const html = this.parser.parseInline(tokens);
      const figure = html.match(/^<img ([^>]+)>(?:<!--caption:([\s\S]*?)-->)?$/);
      if (figure) {
        const src = figure[1].match(/src="([^"]+)"/)[1];
        return `<figure class="shot"><a href="${src}" class="shot__link"><img ${figure[1]}></a>${figure[2] ? `<figcaption>${figure[2]}</figcaption>` : ''}</figure>\n`;
      }
      return `<p>${html}</p>\n`;
    },
  },
});

/* -------------------------------------------------------------- directives */

const md = (s) => String(s ?? '').replace(/\|/g, '\\|').replace(/\n+/g, ' ');
const inline = (s) => marked.parseInline(String(s ?? ''));
const counts = {
  tools: data.tools.length,
  'tool-groups': data.tool_groups.length,
  settings: data.settings.length,
  'setting-groups': data.setting_groups.length,
  slots: data.prompt_slots.length,
  presets: data.presets.filter((p) => p.key !== 'custom').length,
  elements: Object.keys(data.details.features).length,
  values: Object.keys(data.details.params).length,
};

const DIRECTIVES = {
  diagram(arg) {
    const [name, caption = ''] = arg.split('|').map((s) => s.trim());
    // One line: a blank line inside an HTML block ends the block in Markdown,
    // and the rest of the drawing would be read as paragraphs.
    const svg = readFileSync(join(DOCS, 'diagrams', `${name}.svg`), 'utf8').replace(/<\?xml[^>]*>\s*/, '')
      .replace(/>\s+</g, '><').replace(/\s*\n\s*/g, ' ').trim();
    return `<figure class="diagram">${svg}${caption ? `<figcaption>${inline(caption)}</figcaption>` : ''}</figure>`;
  },

  'tool-groups'() {
    const rows = data.tool_groups.map((g) => `| \`${g.key}\` | ${g.tools} | ${md(g.description)} | ${g.admin ? 'administrators' : 'everyone'} | ${g.spends ? 'yes' : 'no'} |`);
    return ['| Group | Tools | What it is for | Who | Spends |', '|---|---:|---|---|---|', ...rows].join('\n');
  },

  tools() {
    const out = [];
    for (const group of data.tool_groups) {
      const tools = data.tools.filter((t) => t.group === group.key);
      if (!tools.length) continue;
      out.push(`## ${group.label}`, '', `${group.description} ${group.admin ? '**Administrators only.**' : ''}`.trim(), '');
      for (const t of tools) {
        const badges = [
          t.read_only ? '<span class="badge badge--read">reads</span>' : '<span class="badge badge--write">writes</span>',
          t.destructive ? '<span class="badge badge--danger">destructive</span>' : '',
          t.spends ? '<span class="badge badge--spend">spends credit</span>' : '',
          t.admin ? '<span class="badge badge--admin">admin</span>' : '',
        ].filter(Boolean).join(' ');
        out.push(`### \`${t.name}\``, '', `<p class="tool-meta"><span class="tool-title">${escapeHtml(t.title)}</span> ${badges}</p>`, '', t.description, '');
        if (t.arguments.length) {
          out.push('| Argument | Type | Description |', '|---|---|---|');
          for (const a of t.arguments) {
            const enumText = Array.isArray(a.enum) ? ` One of ${a.enum.map((v) => `\`${v}\``).join(', ')}.` : '';
            out.push(`| \`${a.name}\`${a.required ? ' <span class="req" title="required">*</span>' : ''} | ${md(a.type)} | ${md(a.description + enumText)} |`);
          }
          out.push('');
        } else {
          out.push('*No arguments.*', '');
        }
      }
    }
    return out.join('\n');
  },

  'mcp-prompts'() {
    const rows = data.mcp_prompts.map((p) => `| \`/mcp__courseforge__${p.name}\` | ${md(p.description)} | ${(p.arguments ?? []).map((a) => `\`${a.name}\`${a.required ? '' : ' (optional)'}`).join(', ') || '-'} |`);
    return ['| Prompt | What it does | Arguments |', '|---|---|---|', ...rows].join('\n');
  },

  settings() {
    const out = [];
    for (const group of data.setting_groups) {
      const fields = data.settings.filter((s) => s.group === group.key);
      if (!fields.length) continue;
      out.push(`## ${group.label}`, '', group.description, '', '| Setting | Default | What it does |', '|---|---|---|');
      for (const f of fields) {
        let def = f.secret ? '*secret*' : JSON.stringify(f.default);
        if (def === '""') def = '*empty*';
        const range = f.min !== undefined || f.max !== undefined ? ` Range ${f.min ?? '…'}-${f.max ?? '…'}${f.unit ? ' ' + f.unit : ''}.` : '';
        const opts = Array.isArray(f.options) && f.options.length ? ` One of ${f.options.map((o) => `\`${typeof o === 'object' ? o.value : o}\``).join(', ')}.` : '';
        out.push(`| **${md(f.label)}**<br>\`${f.key}\` | ${md(def)} | ${md(f.description + range + opts)} |`);
      }
      out.push('');
    }
    return out.join('\n');
  },

  slots() {
    const out = [];
    const groups = Object.entries(data.prompt_groups).sort((a, b) => a[1].order - b[1].order);
    for (const [key, group] of groups) {
      const slots = data.prompt_slots.filter((s) => s.group === key).sort((a, b) => a.order - b.order);
      out.push(`## ${group.label}`, '', group.description, '');
      for (const s of slots) {
        out.push(`### \`${s.key}\``, '', `**${s.label}.** ${s.description}`, '');
        if (s.placeholders.length) out.push(`Placeholders: ${s.placeholders.map((p) => `\`{{${p}}}\``).join(' ')}`, '');
        if (s.value.trim()) {
          out.push(`<details class="slot"><summary>The shipped text</summary>`, '', '```markdown', s.value.replace(/```/g, '``​`'), '```', '', '</details>', '');
        } else {
          out.push('*Empty by default: the slot sends nothing until somebody writes something into it.*', '');
        }
      }
    }
    return out.join('\n');
  },

  placeholders() {
    const used = new Map();
    for (const s of data.prompt_slots) for (const p of s.placeholders) {
      if (!used.has(p)) used.set(p, []);
      used.get(p).push(s.key);
    }
    const rows = [...used].sort((a, b) => a[0].localeCompare(b[0]))
      .map(([p, keys]) => `| \`{{${p}}}\` | ${keys.length > 6 ? `${keys.length} slots` : keys.map((k) => `\`${k}\``).join(', ')} |`);
    return ['| Placeholder | Offered in |', '|---|---|', ...rows].join('\n');
  },

  presets() {
    const queue = (b) => (b === true ? 'yes' : b === 'probe' ? 'probed per key' : 'no');
    const rows = data.presets.map((p) => `| **${md(p.label)}**<br>\`${p.key}\` | ${p.base_url ? `\`${p.base_url}\`` : '*you type it*'} | ${queue(p.batch)} | ${p.requires_key ? 'yes' : 'no'} | ${md(p.hint)} |`);
    return ['| Preset | Base URL | Batch queue | Key | Notes |', '|---|---|---|---|---|', ...rows].join('\n');
  },

  elements() {
    const rows = Object.values(data.details.features).sort((a, b) => a.order - b.order)
      .map((f) => `| ${icon(f.icon)} **${md(f.label)}** | ${f.default ? 'on' : 'off'} | ${md(f.description)} |`);
    return ['| Element | Default | What it adds to a page |', '|---|---|---|', ...rows].join('\n');
  },

  values() {
    const rows = Object.values(data.details.params).sort((a, b) => a.order - b.order)
      .map((p) => `| **${md(p.label)}** | ${p.default === null || p.default === '' ? '-' : md(String(p.default) + (p.unit ? ' ' + p.unit : ''))} | \`{{${p.key}}}\` | ${md(p.description)} |`);
    return ['| Value | Default | Placeholder | What it sizes |', '|---|---|---|---|', ...rows].join('\n');
  },

  changelog() {
    const readme = readFileSync(join(ROOT, 'README.md'), 'utf8').replace(/\r\n/g, '\n');
    const out = [];
    for (const m of readme.matchAll(/^## What (?:is|was) new in ([^\n]+)\n([\s\S]*?)(?=^## )/gm)) {
      const body = m[2].trim()
        .replace(/^### /gm, '#### ')
        .replace(/\]\(docs\.md(#[^)]*)?\)/g, '](architecture.md)')
        .replace(/\]\(docs\/screenshots\/[^)]+\)/g, '](#)');
      out.push(`## ${m[1].trim()}`, '', body, '');
    }
    return out.join('\n');
  },
};

function expandDirectives(source, slug) {
  const lines = source.replace(/\r\n/g, '\n').split('\n');
  let fence = null;
  return lines.map((line) => {
    const f = line.match(/^(\s*)(`{3,}|~{3,})/);
    if (f) {
      if (!fence) fence = f[2];
      else if (line.trim().startsWith(fence)) fence = null;
      return line;
    }
    if (fence) return line;
    const d = line.match(/^::([a-z-]+)\s*(.*)$/);
    if (!d) return line.replace(/\[\[count:([a-z-]+)\]\]/g, (_, k) => counts[k] ?? `[[count:${k}]]`).replace(/\[\[version\]\]/g, data.version);
    if (!DIRECTIVES[d[1]]) { problems.push(`${slug}: unknown directive ::${d[1]}`); return line; }
    return `\n${DIRECTIVES[d[1]](d[2])}\n`;
  }).join('\n');
}

/* ------------------------------------------------------------------ pages */

function frontMatter(raw) {
  const m = raw.match(/^---\n([\s\S]*?)\n---\n/);
  if (!m) return [{}, raw];
  const meta = {};
  for (const line of m[1].split('\n')) {
    const kv = line.match(/^(\w+):\s*(.*)$/);
    if (kv) meta[kv[1]] = kv[2].trim();
  }
  return [meta, raw.slice(m[0].length)];
}

const order = NAV.flatMap((s) => s.pages.map((slug) => ({ slug, section: s })));
const pages = new Map();
for (const { slug, section } of order) {
  const file = join(DOCS, 'pages', `${slug}.md`);
  if (!existsSync(file)) { problems.push(`missing page ${slug}.md`); continue; }
  const [meta, body] = frontMatter(readFileSync(file, 'utf8').replace(/\r\n/g, '\n'));
  ctx = { slug, prefix: '../', toc: [], ids: new Set(), links: [], tocDepth: Number(meta.toc ?? 2) };
  const html = marked.parse(expandDirectives(body, slug));
  pages.set(slug, { slug, section, meta, html, toc: ctx.toc, ids: ctx.ids, links: ctx.links, title: meta.title ?? slug });
}

for (const page of pages.values()) {
  for (const { slug, hash } of page.links) {
    if (hash && pages.has(slug) && !pages.get(slug).ids.has(hash)) problems.push(`${page.slug}: link to missing anchor ${slug}#${hash}`);
  }
}

/* --------------------------------------------------------------- the shell */

// Empty the directory rather than removing it: on Windows a directory that a
// preview server has open cannot be deleted, but its contents can.
mkdirSync(OUT, { recursive: true });
for (const entry of readdirSync(OUT)) rmSync(join(OUT, entry), { recursive: true, force: true });
mkdirSync(join(OUT, 'assets'), { recursive: true });

const hashOf = (s) => createHash('sha256').update(s).digest('hex').slice(0, 10);
const tokenCss = [...tokenClasses].map(([style, cls]) => `.${cls}{${style}}`).join('');
const css = (readFileSync(join(DOCS, 'theme/site.css'), 'utf8') + tokenCss)
  .replace(/\/\*[\s\S]*?\*\//g, '').replace(/\s*\n\s*/g, '\n').replace(/\n+/g, '\n').replace(/\s*([{};,>])\s*/g, '$1').replace(/:\s+/g, ':').replace(/;}/g, '}');
const js = readFileSync(join(DOCS, 'theme/site.js'), 'utf8');
writeFileSync(join(OUT, 'assets/site.css'), css);
writeFileSync(join(OUT, 'assets/site.js'), js);
const assetUrl = { css: `assets/site.css?v=${hashOf(css)}`, js: `assets/site.js?v=${hashOf(js)}` };

copyFileSync(join(ROOT, 'assets/favicon.svg'), join(OUT, 'favicon.svg'));
mkdirSync(join(OUT, 'images'), { recursive: true });
for (const f of readdirSync(join(DOCS, 'images'))) copyFileSync(join(DOCS, 'images', f), join(OUT, 'images', f));

const THEME_BOOT = `<script>try{var t=localStorage.getItem('cf-docs-theme');if(t==='light'||t==='dark')document.documentElement.dataset.theme=t}catch(e){}</script>`;

function sidebar(current, prefix) {
  return NAV.map((s) => `<div class="nav__group"><p class="nav__title">${icon(s.icon)}${s.title}</p><ul>`
    + s.pages.filter((p) => pages.has(p)).map((p) => {
      const page = pages.get(p);
      const here = p === current;
      return `<li><a href="${prefix}${p}/"${here ? ' aria-current="page"' : ''}>${escapeHtml(page.meta.nav ?? page.title)}</a></li>`;
    }).join('') + '</ul></div>').join('');
}

function topbar(prefix, home) {
  return `<header class="topbar">
<button class="topbar__menu" type="button" aria-label="Open the navigation" aria-controls="sidebar" aria-expanded="false"${home ? ' hidden' : ''}>${icon('menu')}</button>
<a class="brand" href="${prefix || './'}"><img src="${prefix}favicon.svg" alt="" width="26" height="26"><span>CourseForge</span><span class="brand__docs">Docs</span></a>
<span class="version" title="The release these pages describe">v${data.version}</span>
<button class="search-button" type="button" aria-label="Search the documentation" aria-keyshortcuts="Control+K">${icon('search')}<span>Search</span><kbd>Ctrl K</kbd></button>
<nav class="topbar__links" aria-label="Elsewhere"><a href="${REPO}" rel="noopener">${icon('git-branch')}<span>GitHub</span></a><a href="${REPO}/releases/latest" rel="noopener">${icon('download')}<span>Download</span></a></nav>
<div class="theme" role="group" aria-label="Colour theme"><button type="button" data-theme-set="light" aria-label="Light theme" title="Light">${icon('sun')}</button><button type="button" data-theme-set="auto" aria-label="Follow the system" title="Auto">${icon('monitor')}</button><button type="button" data-theme-set="dark" aria-label="Dark theme" title="Dark">${icon('moon')}</button></div>
</header>`;
}

const SEARCH_DIALOG = `<dialog class="search" aria-label="Search the documentation"><form method="dialog" class="search__box" role="search">${icon('search')}<input type="search" placeholder="Search the documentation" aria-label="Search" autocomplete="off" spellcheck="false"><button class="search__close" value="close" aria-label="Close search">Esc</button></form><ul class="search__results" role="listbox"></ul><p class="search__hint">Type to search every page. <kbd>↑</kbd> <kbd>↓</kbd> to move, <kbd>Enter</kbd> to open.</p></dialog>`;

function layout({ slug, title, description, body, prefix, home = false, current, base = '' }) {
  const canonical = SITE_URL + (slug && slug !== 'index' ? `${slug}/` : '');
  const fullTitle = home ? 'CourseForge - documentation' : `${title} - CourseForge docs`;
  return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
${base ? `<base href="${base}">\n` : ''}<title>${escapeHtml(fullTitle)}</title>
<meta name="description" content="${escapeHtml(description)}">
<link rel="canonical" href="${canonical}">
<meta name="color-scheme" content="dark light">
<meta name="theme-color" content="#0a0e17" media="(prefers-color-scheme: dark)">
<meta name="theme-color" content="#eef1f7" media="(prefers-color-scheme: light)">
<meta property="og:type" content="website">
<meta property="og:site_name" content="CourseForge">
<meta property="og:title" content="${escapeHtml(home ? 'CourseForge' : title)}">
<meta property="og:description" content="${escapeHtml(description)}">
<meta property="og:url" content="${canonical}">
<meta property="og:image" content="${SITE_URL}images/content.webp">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="${prefix}favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="${prefix}${assetUrl.css}">
${THEME_BOOT}
<script src="${prefix}${assetUrl.js}" defer></script>
<script type="speculationrules">{"prefetch":[{"where":{"selector_matches":".nav a, .pager a, .cards a"},"eagerness":"moderate"}]}</script>
</head>
<body${home ? ' class="home"' : ''} data-root="${prefix || './'}">
<a class="skip" href="#main">Skip to the content</a>
${topbar(prefix, home)}
${body}
${SEARCH_DIALOG}
</body>
</html>
`;
}

function docPage(page, index) {
  const prefix = '../';
  const prev = order[index - 1] && pages.get(order[index - 1].slug);
  const next = order[index + 1] && pages.get(order[index + 1].slug);
  const toc = page.toc.length > 1
    ? `<aside class="toc" aria-label="On this page"><p class="toc__title">On this page</p><ul>${page.toc.map((t) => `<li class="toc--${t.depth}"><a href="#${t.id}">${escapeHtml(t.text)}</a></li>`).join('')}</ul></aside>`
    : '<aside class="toc toc--empty"></aside>';
  const pager = `<nav class="pager" aria-label="Previous and next">`
    + (prev ? `<a class="pager__prev" href="${prefix}${prev.slug}/"><span>${icon('arrow-left')}Previous</span><strong>${escapeHtml(prev.title)}</strong></a>` : '<span></span>')
    + (next ? `<a class="pager__next" href="${prefix}${next.slug}/"><span>Next${icon('arrow-right')}</span><strong>${escapeHtml(next.title)}</strong></a>` : '<span></span>')
    + '</nav>';
  const body = `<div class="shell">
<nav class="nav" id="sidebar" aria-label="Documentation">${sidebar(page.slug, prefix)}</nav>
<div class="nav-scrim" hidden></div>
<main id="main" class="main">
<article class="doc">
<p class="crumbs">${icon(page.section.icon)}${escapeHtml(page.section.title)}</p>
<h1>${escapeHtml(page.title)}</h1>
${page.meta.description ? `<p class="lead">${inline(page.meta.description)}</p>` : ''}
${page.html}
</article>
<footer class="doc-foot"><a href="${REPO}/edit/main/docs/pages/${page.slug}.md" rel="noopener">${icon('pencil')}Edit this page on GitHub</a><span>CourseForge ${data.version} · MIT licence</span></footer>
${pager}
</main>
${toc}
</div>`;
  return layout({ slug: page.slug, title: page.title, description: stripTags(inline(page.meta.description ?? page.title)), body, prefix, current: page.slug });
}

let bytes = 0;
const write = (path, html) => { mkdirSync(dirname(path), { recursive: true }); writeFileSync(path, html); bytes += Buffer.byteLength(html); };

order.forEach(({ slug }, i) => { if (pages.has(slug)) write(join(OUT, slug, 'index.html'), docPage(pages.get(slug), i)); });

/* the home page: an HTML fragment of its own, with the section cards built from the nav */
ctx = { slug: 'index', prefix: '', toc: [], ids: new Set(), links: [], tocDepth: 2 };
const cards = NAV.map((s) => `<a class="card" href="${pages.has(s.pages[0]) ? s.pages[0] + '/' : '#'}">${icon(s.icon)}<strong>${s.title}</strong><span>${s.pages.filter((p) => pages.has(p)).map((p) => escapeHtml(pages.get(p).meta.nav ?? pages.get(p).title)).join(' · ')}</span></a>`).join('');
const homeHtml = expandDirectives(readFileSync(join(DOCS, 'pages/index.html'), 'utf8'), 'index')
  .replace('<!--cards-->', `<div class="cards">${cards}</div>`)
  .replace(/\{\{icon:([a-z0-9-]+)\}\}/g, (_, n) => icon(n))
  .replace(/\{\{version\}\}/g, data.version);
write(join(OUT, 'index.html'), layout({ slug: 'index', title: 'CourseForge', description: 'Self-hosted course generator: turn a one-line brief into a complete course and publish it into BookStack. Installation, guides and reference.', body: `<main id="main" class="landing">${homeHtml}</main>`, prefix: '', home: true }));

/* 404: served at whatever depth the missing address had, so it anchors itself */
const basePath = new URL(SITE_URL).pathname;
write(join(OUT, '404.html'), layout({ slug: '404', title: 'Not found', description: 'That page does not exist.', prefix: '', base: basePath,
  body: `<main id="main" class="landing"><section class="hero hero--small"><h1>That page is not here</h1><p class="hero__lead">The address may have changed when the documentation was reorganised. Search, or start from the beginning.</p><p class="hero__actions"><a class="btn btn--primary" href="./">Documentation home</a><a class="btn" href="introduction/">What CourseForge is</a></p></section></main>` }));

/* the search index, one entry per section */
const index = [];
for (const page of pages.values()) {
  // The shipped text of every prompt slot is reference to read, not to search:
  // it would be a third of the index and match every common word.
  const parts = page.html.replace(/<details class="slot">[\s\S]*?<\/details>/g, '').split(/(?=<h[23] id=")/);
  for (const part of parts) {
    const h = part.match(/^<h[23] id="([^"]+)">([\s\S]*?)<a class="anchor"/);
    const text = stripTags(h ? part.slice(part.indexOf('</h') + 5) : part).replace(/\s+/g, ' ').trim().slice(0, 1600);
    if (!text && !h) continue;
    index.push({ p: page.title, s: page.section.title, h: h ? stripTags(h[2]).trim() : '', u: `${page.slug}/${h ? '#' + h[1] : ''}`, t: text });
  }
}
const searchJson = JSON.stringify(index);
writeFileSync(join(OUT, 'search.json'), searchJson);

writeFileSync(join(OUT, 'sitemap.xml'), `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n`
  + [SITE_URL, ...[...pages.keys()].map((s) => `${SITE_URL}${s}/`)].map((u) => `<url><loc>${u}</loc></url>`).join('\n') + '\n</urlset>\n');
writeFileSync(join(OUT, 'robots.txt'), `User-agent: *\nAllow: /\nSitemap: ${SITE_URL}sitemap.xml\n`);

/* ----------------------------------------------------------------- report */

const kb = (n) => `${(n / 1024).toFixed(1)} KB`;
console.log(`${pages.size + 1} pages, ${kb(bytes)} of HTML, site.css ${kb(css.length)} (${tokenClasses.size} token classes), site.js ${kb(js.length)}, search.json ${kb(searchJson.length)} (${index.length} sections), in ${Math.round(performance.now() - started)} ms`);
if (problems.length) {
  console.error(`\n${problems.length} problem(s):\n  ${problems.join('\n  ')}`);
  process.exitCode = 1;
}
