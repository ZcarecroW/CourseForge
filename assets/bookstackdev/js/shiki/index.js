/**
 * index.js - find <pre> blocks, resolve/detect the language, render with Shiki.
 * Public API: window.ShikiCode
 */

import {opts, log} from './config.js';
import {findLangHint, isSkippedLang, looksLikeMermaid, resolveLang} from './languages.js';
import {detectWithHljs} from './detect.js';
import {ensureLang, getHighlighter, highlight, loadShiki, plainHtml} from './loader.js';
import {buildBlock, setNumbersPref, setWrapPref} from './ui.js';

const EDITOR_SEL = '.editor-toolbox, .markdown-editor, .code-editor, [contenteditable="true"],' +
  '#markdown-editor, .page-editor, .mce-content-body, .tox';

/* Anything mermaid-ish is owned by mermaid-render.js, never by Shiki. */
const MERMAID_SEL = 'pre.mermaid, .mermaid, .mermaid-container, [data-mermaid],' +
  'code.language-mermaid, code.lang-mermaid, code.language-mmd, [data-lang="mermaid"]';

const seen = new WeakSet();
const items = new WeakMap();   // holder -> {code, lang, target}
const pending = [];

/* Keeps the main thread responsive while many blocks are highlighted. */
const yieldToMain = () => new Promise((r) => {
  /* scheduler.yield() REJECTS when its inherited task signal is aborted. Wired
     with only a fulfilment handler, that promise never settles - the await in
     flush() hangs and `flushing` stays true, so no later block ever renders.
     Resolving on rejection too simply means "carry on". */
  if (window.scheduler && typeof window.scheduler.yield === 'function') window.scheduler.yield().then(r, r);
  else setTimeout(r, 0);
});

const io = (opts.lazy && 'IntersectionObserver' in window)
  ? new IntersectionObserver((entries) => {
    entries.forEach((e) => {
      if (!e.isIntersecting) return;
      io.unobserve(e.target);
      enqueue(e.target);
    });
  }, {rootMargin: '800px 0px'})
  : null;

function enqueue(holder) {
  const item = items.get(holder);
  if (!item) return;
  items.delete(holder);
  pending.push(item);
  flush();
}

/* The two ES modules load in parallel, so mermaid-render.js may not have
   published window.MermaidRender yet. Its own MutationObserver watches for
   *added* nodes and cannot see a class being added to one that is already in
   the document, so the render has to be requested explicitly - retried once the
   page has loaded if the module was not ready the first time. */
function handOffToMermaid() {
  if (window.MermaidRender) {
    window.MermaidRender.render();
    return;
  }
  window.addEventListener('load', () => {
    if (window.MermaidRender) window.MermaidRender.render();
  }, {once: true});
}

function collectPre(pre) {
  if (!pre || seen.has(pre) || pre.classList.contains('shiki')) return;
  if (pre.closest('.shiki-block') || pre.closest(EDITOR_SEL)) return;
  if (pre.matches(MERMAID_SEL) || pre.closest(MERMAID_SEL) || pre.querySelector(MERMAID_SEL)) return;
  if (!pre.closest(opts.containers)) return;

  const codeEl = pre.querySelector('code') || pre;
  /* findLangHint() also checks data-* attributes, the previous sibling and the
     nearest [class*="language-"] ancestor - langFromClasses() alone missed the
     wrapper BookStack puts the hint on, which is what triggered detection. */
  const lang = findLangHint(codeEl) || findLangHint(pre);
  if (isSkippedLang(lang)) {                                 // leave mermaid alone
    seen.add(pre);
    return;
  }

  const code = (codeEl.textContent || '').replace(/\n$/, '');
  /* Deliberately NOT marked seen: an empty <pre> is usually one the parser is
     still streaming into, or one BookStack has not filled in yet. Blacklisting
     it here (the old behaviour) meant no later scan ever came back for it. */
  if (!code.trim()) return;

  if (!lang && looksLikeMermaid(code)) {
    /* Unlabelled mermaid. Shiki must not render it - but mermaid-render.js only
       looks for an explicit class, so without stamping one the diagram was
       claimed by neither renderer and stayed a bare <pre>. */
    seen.add(pre);
    /* mermaid-render.js matches 'pre.mermaid' or 'code.language-mermaid', never
       a <pre> carrying language-mermaid - so which class to stamp depends on
       whether this block actually has a <code> child. */
    if (codeEl === pre) pre.classList.add('mermaid');
    else codeEl.classList.add('language-mermaid');
    handOffToMermaid();
    return;
  }
  seen.add(pre);

  const parent = pre.parentNode;
  if (!parent) return;

  const holder = document.createElement('div');
  holder.className = 'shiki-block shiki-block--loading';
  /* The <pre> is gone from here on. Carrying the code through the placeholder
     keeps it selectable, searchable and printable while Shiki is still on its
     way down from a CDN - and leaves it readable rather than showing an empty
     box if the CDN never answers, or if a lazy block is never scrolled into
     view. buildBlock() clears this before rendering. */
  holder.textContent = code;
  parent.replaceChild(holder, pre);

  items.set(holder, {code, lang: lang || null, target: holder});
  if (io) io.observe(holder);
  else enqueue(holder);
}

/* ---------------------------------------------------------------- pipeline */

/* A holder must never stay empty: every failure path renders plain text. */
function renderPlain(item) {
  try {
    buildBlock(item, plainHtml(item.code), 'plaintext', false);
  } catch (err) {
    item.target.className = 'shiki-block';
    item.target.textContent = item.code;
  }
}

let flushing = false;

function flush() {
  if (flushing || !pending.length) return;
  flushing = true;

  Promise.all([loadShiki(), getHighlighter()]).then(async ([shiki, hl]) => {
    try {
      while (pending.length) {
        const item = pending.shift();
        try {
          let raw = item.lang;
          let detected = false;
          if (!raw) {
            raw = await detectWithHljs(item.code);
            detected = !!raw;
            if (!raw) raw = opts.fallbackLang;
          }
          const lang = await ensureLang(hl, resolveLang(shiki, raw));
          const res = highlight(hl, item.code, lang);
          buildBlock(item, res.html, res.lang, detected && res.lang !== 'plaintext');
        } catch (err) {
          log('highlight failed, using plain text', err);
          renderPlain(item);
        }
        if (pending.length) await yieldToMain();
      }
    } finally {
      flushing = false;                        // never latch, whatever escapes
    }
    if (pending.length) flush();
  }).catch((err) => {
    console.warn('[shiki-highlight] Shiki unavailable, rendering plain blocks', err);
    while (pending.length) renderPlain(pending.shift());
    flushing = false;
  });
}

/* ---------------------------------------------------------------- scanning */

function scan(root) {
  const scope = (root && root.querySelectorAll) ? root : document;
  scope.querySelectorAll('pre').forEach(collectPre);
}

let scanTimer = null;

function scheduleScan() {
  clearTimeout(scanTimer);
  scanTimer = setTimeout(() => scan(document), 60);
}

new MutationObserver((mutations) => {
  /* While the document is still streaming, an added <pre> may not hold all of
     its text yet - and collectPre() both reads textContent and detaches the
     element. Defer to the debounced scan until parsing is finished. */
  if (document.readyState === 'loading') {
    scheduleScan();
    return;
  }
  for (const m of mutations) {
    for (const node of m.addedNodes) {
      if (node.nodeType !== 1) continue;
      if (node.tagName === 'PRE') collectPre(node);
      else if (node.querySelector && node.querySelector('pre')) scheduleScan();
    }
  }
}).observe(document.documentElement, {childList: true, subtree: true});

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => scan(document));
} else {
  scan(document);
}
window.addEventListener('load', scheduleScan);
window.addEventListener('bookstack-dom-change', scheduleScan);

window.ShikiCode = {
  config: opts,
  refresh: (root) => scan(root || document),
  detect: (code) => detectWithHljs(String(code || '')),
  ready: () => getHighlighter(),
  setWrap: setWrapPref,
  setLineNumbers: setNumbersPref,
};

export {scan};