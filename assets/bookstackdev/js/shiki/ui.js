/**
 * ui.js - block chrome: toolbar, copy button, preferences, collapse, gutter.
 */

import {opts, store} from './config.js';

export let wrapPref = store.get('wrap', opts.wrap);
export let numbersPref = store.get('numbers', opts.lineNumbers);

const ICONS = {
  copy: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></svg>',
  check: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>',
  wrap: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h18v2H3V5zm0 12h6v2H3v-2zm0-6h13a3.5 3.5 0 0 1 0 7h-2.6l1.3 1.3-1.4 1.4L9.6 17l3.7-3.7 1.4 1.4L13.4 16H16a1.5 1.5 0 0 0 0-3H3v-2z"/></svg>',
  hash: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 3 9 8H5v2h3.6l-.8 4H4v2h3.4L6.6 21h2l.8-5h4l-.8 5h2l.8-5H20v-2h-4.2l.8-4H20V8h-3.2L17.6 3h-2l-.8 5h-4l.8-5h-2zm-.4 7h4l-.8 4h-4l.8-4z"/></svg>',
};

const ZWSP = '\u200B';

function makeBtn(label, icon, title) {
  const b = document.createElement('button');
  b.type = 'button';
  b.className = 'shiki-block__btn';
  b.title = title || label;
  b.innerHTML = icon + '<span>' + label + '</span>';
  return b;
}

const copyTimers = new WeakMap();

async function copyText(text, btn) {
  clearTimeout(copyTimers.get(btn));
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text);
    } else {
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.cssText = 'position:fixed;top:-1000px;opacity:0';
      (document.body || document.documentElement).appendChild(ta);
      ta.select();
      const ok = document.execCommand('copy');
      ta.remove();
      /* execCommand reports failure by returning false, not by throwing - the
         button used to claim "Copied" with an empty clipboard. */
      if (!ok) throw new Error('execCommand("copy") returned false');
    }
    btn.classList.add('is-done');
    btn.innerHTML = ICONS.check + '<span>Copied</span>';
  } catch (e) {
    btn.innerHTML = ICONS.copy + '<span>Failed</span>';
  }
  copyTimers.set(btn, setTimeout(() => {
    btn.classList.remove('is-done');
    btn.innerHTML = ICONS.copy + '<span>Copy</span>';
  }, 1800));
}

/* Newlines between .line spans are removed, so a line that renders nothing
   would collapse to zero height and stack its line number onto the next row;
   ':empty' cannot match `<span class="line"><span></span></span>`, hence the
   explicit zero-width space. Returns the rendered row count. */
function normaliseLines(codeEl) {
  if (!codeEl) return 0;

  Array.prototype.slice.call(codeEl.childNodes)
    .filter((n) => n.nodeType === 3 && !/\S/.test(n.nodeValue || ''))
    .forEach((n) => n.remove());

  const own = codeEl.querySelectorAll(':scope > .line');
  const lines = own.length ? own : codeEl.querySelectorAll('.line');

  Array.prototype.forEach.call(lines, (line) => {
    const text = line.textContent || '';
    if (text.length === 0 || text === ZWSP) line.textContent = ZWSP;
  });

  return lines.length;
}

function applyGutterWidth(block, lineCount) {
  const digits = Math.max(2, String(Math.max(1, lineCount)).length);
  block.style.setProperty('--sk-digits', String(digits));
}

/* Theme colours are lifted onto the block as custom properties only; inline
   background/color would out-rank the dark-mode stylesheet rules. */
function adoptThemeColours(block, pre) {
  if (!pre) return;
  const map = {
    '--sk-bg-light': pre.style.backgroundColor,
    '--sk-fg-light': pre.style.color,
    '--sk-bg-dark': pre.style.getPropertyValue('--shiki-dark-bg'),
    '--sk-fg-dark': pre.style.getPropertyValue('--shiki-dark'),
  };
  Object.keys(map).forEach((k) => {
    if (map[k]) block.style.setProperty(k, map[k].trim());
  });
  pre.style.removeProperty('background-color');
  pre.style.background = 'transparent';
}

/* Collapse detection is batched: all scrollHeight reads happen first, then all
   writes. Interleaving them (the old behaviour) forced a layout per block and
   froze the page - which is why the expand button reacted late. */
const collapseQueue = new Set();
let collapseScheduled = false;

/* The label must follow data-collapsed, which applyCollapse() also rewrites -
   deriving it in one place stops "Show less" from sticking on a re-collapsed
   block after a wrap/line-number toggle. */
function syncExpandLabel(block) {
  const btn = block.querySelector('.shiki-block__expand');
  if (btn) btn.textContent = block.dataset.collapsed === 'true' ? 'Show all lines' : 'Show less';
}

export function applyCollapse(block) {
  if (!opts.collapseHeight) {
    block.dataset.collapsible = 'false';
    block.dataset.collapsed = 'false';
    syncExpandLabel(block);
    return;
  }
  collapseQueue.add(block);
  if (collapseScheduled) return;
  collapseScheduled = true;

  requestAnimationFrame(() => {
    collapseScheduled = false;
    const blocks = Array.from(collapseQueue);
    collapseQueue.clear();

    const reads = blocks.map((b) => {
      const body = b.querySelector('.shiki-block__body');
      return body ? {b, tall: body.scrollHeight > opts.collapseHeight + 60} : null;
    });

    reads.forEach((r) => {
      if (!r) return;
      r.b.dataset.collapsible = String(r.tall);
      if (r.b.dataset.userExpanded !== 'true') r.b.dataset.collapsed = String(r.tall);
      syncExpandLabel(r.b);
    });
  });
}

export function syncAllBlocks() {
  const blocks = document.querySelectorAll('.shiki-block');
  blocks.forEach((b) => {
    b.dataset.wrap = String(wrapPref);
    b.dataset.numbers = String(numbersPref);
    const wBtn = b.querySelector('[data-role="wrap"]');
    if (wBtn) wBtn.setAttribute('aria-pressed', String(wrapPref));
    const nBtn = b.querySelector('[data-role="numbers"]');
    if (nBtn) nBtn.setAttribute('aria-pressed', String(numbersPref));
  });
  blocks.forEach(applyCollapse);
}

/* Collapse is decided from a measured height, so a viewport change - rotating
   a phone, or crossing one of the responsive font-size breakpoints - can make
   a block that fits start overflowing or the other way round. applyCollapse()
   already batches its reads through collapseQueue, and the userExpanded guard
   keeps a manual "Show all lines" intact. */
let resizeTimer = null;
window.addEventListener('resize', () => {
  clearTimeout(resizeTimer);
  resizeTimer = setTimeout(() => {
    document.querySelectorAll('.shiki-block:not(.shiki-block--loading)').forEach(applyCollapse);
  }, 150);
});

export function setWrapPref(v) {
  wrapPref = !!v;
  store.set('wrap', wrapPref);
  syncAllBlocks();
}

export function setNumbersPref(v) {
  numbersPref = !!v;
  store.set('numbers', numbersPref);
  syncAllBlocks();
}

export function buildBlock(item, html, resolvedLang, detected) {
  const block = item.target;
  if (!block || !block.parentNode) return;

  block.className = 'shiki-block';
  block.dataset.wrap = String(wrapPref);
  block.dataset.numbers = String(numbersPref);
  block.dataset.lang = resolvedLang;
  block.dataset.detected = String(!!detected);
  block.removeAttribute('style');
  block.innerHTML = '';
  if (opts.collapseHeight) block.style.setProperty('--sk-collapse', opts.collapseHeight + 'px');
  applyGutterWidth(block, item.code.split('\n').length);

  /* --- toolbar --- */
  const bar = document.createElement('div');
  bar.className = 'shiki-block__bar';

  const label = document.createElement('span');
  label.className = 'shiki-block__lang';
  const name = (resolvedLang === 'plaintext' || resolvedLang === 'text') ? 'code' : resolvedLang;
  label.appendChild(document.createTextNode(name));
  if (detected && opts.showDetectedHint) {
    const hint = document.createElement('span');
    hint.className = 'shiki-block__auto';
    hint.textContent = 'auto';
    hint.title = 'Language was detected automatically';
    label.appendChild(hint);
  }
  bar.appendChild(label);

  const numBtn = makeBtn('Lines', ICONS.hash, 'Toggle line numbers');
  numBtn.setAttribute('aria-pressed', String(numbersPref));
  numBtn.dataset.role = 'numbers';
  numBtn.addEventListener('click', () => setNumbersPref(!numbersPref));
  bar.appendChild(numBtn);

  const wrapBtn = makeBtn('Wrap', ICONS.wrap, 'Toggle soft line wrapping');
  wrapBtn.setAttribute('aria-pressed', String(wrapPref));
  wrapBtn.dataset.role = 'wrap';
  wrapBtn.addEventListener('click', () => setWrapPref(!wrapPref));
  bar.appendChild(wrapBtn);

  const copyBtn = makeBtn('Copy', ICONS.copy, 'Copy code to clipboard');
  copyBtn.addEventListener('click', () => copyText(item.code, copyBtn));
  bar.appendChild(copyBtn);

  block.appendChild(bar);

  /* --- body --- */
  const body = document.createElement('div');
  body.className = 'shiki-block__body';
  body.innerHTML = html;
  const pre = body.querySelector('pre');
  if (pre) {
    pre.classList.add('shiki');
    pre.setAttribute('tabindex', '0');
    const rows = normaliseLines(pre.querySelector('code'));
    if (rows) applyGutterWidth(block, rows);
    adoptThemeColours(block, pre);
  }
  block.appendChild(body);

  /* --- collapse (state only, height comes from CSS) --- */
  const expand = document.createElement('button');
  expand.type = 'button';
  expand.className = 'shiki-block__expand';
  expand.textContent = 'Show all lines';
  expand.addEventListener('click', () => {
    const collapsed = block.dataset.collapsed === 'true';
    block.dataset.collapsed = String(!collapsed);
    block.dataset.userExpanded = 'true';
    syncExpandLabel(block);
  });
  block.appendChild(expand);
  applyCollapse(block);
}