/**
 * Markdown → HTML for the preview.
 *
 * Everything the AI writes is untrusted text, so the rendered HTML always goes
 * through DOMPurify before it reaches the DOM – v-html would happily execute an
 * injected script otherwise.
 *
 * Beyond plain Markdown this pipeline prepares three things the preview
 * finishes asynchronously, because each needs a library that is only loaded
 * when a page actually contains one:
 *
 *   a ```mermaid block   → a .cf-diagram div     → core/diagrams.js
 *   any other fence     → a .cf-code pre        → core/highlight.js
 *   \( … \) and $$ … $$ → a .cf-math span or div → core/math.js
 *
 * The fenced block is the one of the three that is already readable before its
 * library arrives — it leaves here as escaped text in the same line-by-line
 * structure Shiki produces, so the header, the line numbers and the wrapping
 * are in place whether or not a grammar was ever found for it.
 *
 * Formulas have to be lifted out during tokenising rather than after: Markdown
 * reads `\(` as an escaped bracket and `x_i` inside `$$` as emphasis, so by the
 * time the HTML exists the LaTeX would already be mangled. The extensions below
 * claim those spans first and carry the source through untouched.
 *
 * Every top-level block also gets a `data-src-line` attribute holding the line
 * it started on. That is what lets the editor and the preview scroll together:
 * the pair is anchored on real positions in the text rather than on a
 * percentage of the scroll height, so a page with one long code block does not
 * drift.
 */
import { Marked } from 'marked';
import DOMPurify from 'dompurify';

/**
 * Links in the preview open in a new tab and cannot reach back into the app.
 * The hook is registered on the shared DOMPurify, so it also covers the two
 * other things that sanitise on their way into the preview — the highlighted
 * blocks and the diagram SVGs — which is what was wanted.
 */
DOMPurify.addHook('afterSanitizeAttributes', (node) => {
  if (node.tagName === 'A' && node.hasAttribute('href')) {
    node.setAttribute('target', '_blank');
    node.setAttribute('rel', 'noopener noreferrer');
  }
});

const escapeHtml = (text) => String(text)
  .replace(/&/g, '&amp;')
  .replace(/</g, '&lt;')
  .replace(/>/g, '&gt;')
  .replace(/"/g, '&quot;');

/** The `data-src-line` of a top-level token, or nothing for a nested one. */
const anchor = (token) => (token.cfLine === undefined ? '' : ` data-src-line="${token.cfLine}"`);

/**
 * A diagram and a formula both have to survive to the preview verbatim, and
 * both are carried as element text rather than in an attribute. DOMPurify drops
 * any attribute value containing `-->` or `<x` — a defence against comment
 * breakout, and precisely the two things Mermaid arrows and LaTeX comparisons
 * are made of. Text content has no such problem, and doubles as the placeholder
 * shown while the library that replaces it is still loading.
 */
const mathTag = (tex, display, token) => (display
  ? `<div class="cf-math cf-math--block"${anchor(token)}>${escapeHtml(tex)}</div>`
  : `<span class="cf-math">${escapeHtml(tex)}</span>`);

/* -- the formula extensions ------------------------------------------------ */

const MATH_BLOCK = /^ {0,3}\$\$([\s\S]+?)\$\$[ \t]*(?:\n+|$)/;

const mathBlock = {
  name: 'mathBlock',
  level: 'block',
  /**
   * Where a formula may interrupt a paragraph. Two conditions, and both are
   * needed: the `$$` has to open a line, or a sentence mentioning two prices
   * reads as a formula; and it has to be one that really closes, because marked
   * cuts the paragraph wherever this points and stitches it back together if
   * the tokeniser then declines — leaving a `raw` one newline longer than the
   * text it consumed, which is exactly what the line count is derived from.
   */
  start(src) {
    for (const match of src.matchAll(/(^|\n) {0,3}\$\$/g)) {
      const at = match.index + (match[1] ? 1 : 0);
      if (MATH_BLOCK.test(src.slice(at))) return at;
    }
    return undefined;
  },
  tokenizer(src) {
    const match = MATH_BLOCK.exec(src);
    if (match) return { type: 'mathBlock', raw: match[0], text: match[1].trim() };
    return undefined;
  },
  renderer(token) { return mathTag(token.text, true, token); },
};

const mathInline = {
  name: 'mathInline',
  level: 'inline',
  start: (src) => src.indexOf('\\('),
  tokenizer(src) {
    const match = /^\\\(([\s\S]+?)\\\)/.exec(src);
    if (match) return { type: 'mathInline', raw: match[0], text: match[1].trim() };
    return undefined;
  },
  renderer(token) { return mathTag(token.text, false, token); },
};

/* -- the renderer ---------------------------------------------------------- */

const renderer = {
  /**
   * A fenced block leaves here as plain, escaped text — one `<span class="line">`
   * per line, which is exactly the shape Shiki's own output has. The preview
   * swaps one for the other once the grammar has loaded, and because both have
   * the same structure, line numbers and wrapping are one stylesheet rule
   * rather than two. The whole info string is carried through: what language a
   * block is in is decided in `core/highlight.js`, which needs to see it.
   */
  code(token) {
    const info = (token.lang ?? '').trim();
    const body = token.text.replace(/\n$/, '');
    if (info.split(/\s+/)[0].toLowerCase() === 'mermaid') {
      return `<div class="cf-diagram"${anchor(token)}>${escapeHtml(body)}</div>\n`;
    }
    const lines = body.split('\n')
      .map((line) => `<span class="line">${escapeHtml(line)}</span>`)
      .join('\n');
    return `<pre class="cf-code" data-info="${escapeHtml(info.slice(0, 120))}"${anchor(token)}>`
      + `<code>${lines}\n</code></pre>\n`;
  },
  heading(token) {
    return `<h${token.depth}${anchor(token)}>${this.parser.parseInline(token.tokens)}</h${token.depth}>\n`;
  },
  paragraph(token) {
    return `<p${anchor(token)}>${this.parser.parseInline(token.tokens)}</p>\n`;
  },
  blockquote(token) {
    return `<blockquote${anchor(token)}>\n${this.parser.parse(token.tokens)}</blockquote>\n`;
  },
  hr(token) {
    return `<hr${anchor(token)}>\n`;
  },
  list(token) {
    const tag = token.ordered ? 'ol' : 'ul';
    const start = token.ordered && token.start !== 1 ? ` start="${token.start}"` : '';
    const body = token.items.map((item) => this.listitem(item)).join('');
    return `<${tag}${start}${anchor(token)}>\n${body}</${tag}>\n`;
  },
  table(token) {
    const head = token.header.map((cell) => this.tablecell(cell)).join('');
    const body = token.rows
      .map((row) => this.tablerow({ text: row.map((cell) => this.tablecell(cell)).join('') }))
      .join('');
    return `<table${anchor(token)}>\n<thead>\n${this.tablerow({ text: head })}</thead>\n`
      + (body ? `<tbody>${body}</tbody>` : '') + '</table>\n';
  },
};

const md = new Marked({ gfm: true, breaks: false })
  .use({ extensions: [mathBlock, mathInline], renderer });

/**
 * Marked reports no positions, so the line of every top-level block is counted
 * off the token stream before parsing. Adding up the `raw` lengths is not quite
 * enough, because what the lexer emits is marked's business and it changes:
 * a link reference definition used to be consumed silently and is now a token
 * of its own, and marked 18 trims trailing blank lines out of `raw` where 15
 * left them in. Either way the running total drifts. Finding each `raw` back in
 * the source is what makes the count independent of those decisions — the line
 * is read off the text rather than accumulated — and the running total is only
 * the fallback for a token whose text is not verbatim in the source.
 */
function withLines(tokens, source) {
  let index = 0;
  let line = 0;
  for (const token of tokens) {
    const raw = token.raw ?? '';
    const at = raw === '' ? -1 : source.indexOf(raw, index);
    if (at >= 0) {
      line += (source.slice(index, at).match(/\n/g) ?? []).length;
      index = at;
    }
    if (token.type !== 'space') token.cfLine = line;
    line += (raw.match(/\n/g) ?? []).length;
    index += raw.length;
  }
  return tokens;
}

/**
 * DOMPurify's defaults are written for user content inside a page you control,
 * and two of them do not hold here. This HTML is put into the workspace with
 * `v-html` rather than into an iframe, so an inline `style` is enough for a
 * full-viewport overlay and a `<form action>` is a real, submittable form in
 * the application's own origin — and the author of this text is a language
 * model. The prompt library already instructs it never to emit raw HTML; this
 * is what makes that true rather than hoped for. Everything Markdown itself
 * produces, and the inline HTML that is actually useful in prose — `kbd`,
 * `sub`, `sup`, `abbr`, `details` — is untouched.
 *
 * `HTML_INTEGRATION_POINTS` is pinned for a different reason. It began as a
 * repair: DOMPurify 3.2.4 carried that one option over from the previous call
 * instead of resetting it, so rendering a diagram left `foreignObject`
 * registered here too — the one place it must never be. 3.4.14 resets it per
 * call, so the line no longer fixes anything. It stays because `core/diagrams.js`
 * deliberately widens the same option for its own SVG, and the boundary between
 * the two is worth stating out loud rather than inheriting from a default that
 * has already changed once.
 */
const PROSE_POLICY = {
  FORBID_TAGS: ['style', 'form', 'input', 'button', 'select', 'option', 'textarea'],
  FORBID_ATTR: ['style'],
  HTML_INTEGRATION_POINTS: { 'annotation-xml': true },
};

/** Preview HTML: sanitised, line-anchored, with the async parts still pending. */
export function renderMarkdown(text) {
  // The lexer normalises line endings before it starts, so the source the line
  // count is measured against has to be normalised too.
  const source = String(text ?? '').replace(/\r\n|\r/g, '\n');
  if (source.trim() === '') return '';
  const tokens = withLines(md.lexer(source), source);
  return DOMPurify.sanitize(md.parser(tokens), PROSE_POLICY);
}

/** Plain-text excerpt for cards and tooltips. */
export function excerpt(text, length = 160) {
  const plain = String(text ?? '')
    .replace(/```[\s\S]*?```/g, ' ')
    .replace(/[#*_`>|]/g, '')
    .replace(/\s+/g, ' ')
    .trim();
  return plain.length > length ? `${plain.slice(0, length - 1)}…` : plain;
}
