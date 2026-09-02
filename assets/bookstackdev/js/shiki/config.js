/**
 * config.js - defaults, resolved options, CDN URL lists, localStorage helper.
 */

/* The CDN majors below were checked against the npm registry on 2026-09-02:
   shiki 4.4.3 and highlight.js 11.12.0 were current. A major pin (@4) keeps
   following the minor releases; bump it when the next major is verified. */
export const defaults = {
  shikiUrl: 'https://esm.sh/shiki@4',
  hljsUrl: 'https://esm.sh/highlight.js@11',
  themes: {light: 'one-light', dark: 'one-dark-pro'},
  fallbackLang: 'plaintext',
  wrap: true,
  lineNumbers: true,
  collapseHeight: 560,          // px; 0 disables the collapse feature
  lazy: true,
  skipLanguages: ['mermaid', 'mmd'],
  containers: '.page-content, .page-revision, .comment-container, .comment-body, .description, .book-content, .chapter-content, .markdown-display',
  detect: true,                 // highlight.js highlightAuto() fallback
  detectMinRelevance: 6,
  detectSubset: null,
  showDetectedHint: true,
  debug: false,
};

/* A partial user config must never break the highlighter: nested values are
   merged and type-checked (createHighlighter throws on an undefined theme). */
function normaliseConfig(raw) {
  const user = (raw && typeof raw === 'object') ? raw : {};
  const cfg = Object.assign({}, defaults, user);

  cfg.themes = Object.assign({}, defaults.themes, (user.themes && typeof user.themes === 'object') ? user.themes : {});
  if (typeof cfg.themes.light !== 'string' || !cfg.themes.light) cfg.themes.light = defaults.themes.light;
  if (typeof cfg.themes.dark !== 'string' || !cfg.themes.dark) cfg.themes.dark = defaults.themes.dark;

  cfg.skipLanguages = (Array.isArray(cfg.skipLanguages) ? cfg.skipLanguages : defaults.skipLanguages)
    .filter((l) => typeof l === 'string' && l)
    .map((l) => l.trim().toLowerCase());

  if (typeof cfg.containers !== 'string' || !cfg.containers.trim()) cfg.containers = defaults.containers;

  /* Number(null), Number('') and Number(false) are all 0, and Number.isFinite(0)
     is true - so the old Number()-based guard turned every one of those into a
     literal 0, silently disabling collapsing and accepting every detection
     guess instead of falling back to the default. Check the type first. */
  const num = (v, fallback) => (typeof v === 'number' && Number.isFinite(v) ? v : fallback);
  cfg.collapseHeight = Math.max(0, num(cfg.collapseHeight, defaults.collapseHeight));
  cfg.detectMinRelevance = num(cfg.detectMinRelevance, defaults.detectMinRelevance);

  if (!Array.isArray(cfg.detectSubset) || !cfg.detectSubset.length) cfg.detectSubset = null;
  if (typeof cfg.fallbackLang !== 'string' || !cfg.fallbackLang) cfg.fallbackLang = defaults.fallbackLang;

  ['wrap', 'lineNumbers', 'lazy', 'detect', 'showDetectedHint', 'debug']
    .forEach((k) => {
      cfg[k] = !!cfg[k];
    });

  return cfg;
}

export const opts = normaliseConfig(typeof window !== 'undefined' ? window.SHIKI_CODE_CONFIG : null);

if (typeof window !== 'undefined') window.SHIKI_CODE_CONFIG = opts;

export function log(...args) {
  if (opts.debug) console.log('[shiki-highlight]', ...args);
}

const uniq = (list) => list.filter((v, i, a) => v && a.indexOf(v) === i);

export const CDN_URLS = uniq([
  opts.shikiUrl,
  'https://esm.sh/shiki@4',
  'https://cdn.jsdelivr.net/npm/shiki@4/+esm',
  'https://unpkg.com/shiki@4/dist/index.mjs',
]);

/* WASM-free regex engine (CSP friendly).
   The engine sub-path can only be appended to a bare package root. Deriving it
   from an already-resolved module URL - "…/shiki@3/+esm" on jsDelivr, or
   "…/dist/index.mjs" on unpkg, both of which this file itself offers as
   fallbacks - produces a guaranteed 404 that has to fail before the known-good
   URLs below are even tried. */
const engineFrom = (url) => {
  const u = String(url || '').replace(/\/+$/, '');
  if (!u || /\+esm$/i.test(u) || /\.m?js$/i.test(u)) return null;
  return u + '/engine/javascript';
};

export const ENGINE_URLS = uniq([
  engineFrom(opts.shikiUrl),
  'https://esm.sh/shiki@4/engine/javascript',
  'https://cdn.jsdelivr.net/npm/shiki@4/engine/javascript/+esm',
  'https://unpkg.com/shiki@4/dist/engine-javascript.mjs',
]);

/* highlight.js - detection only, never used for rendering. */
export const HLJS_URLS = uniq([
  opts.hljsUrl,
  'https://esm.sh/highlight.js@11',
  'https://cdn.jsdelivr.net/npm/highlight.js@11/+esm',
  'https://unpkg.com/highlight.js@11/es/index.js',
]);

export const store = {
  get(key, fallback) {
    try {
      const v = localStorage.getItem('shiki-code:' + key);
      if (v === null) return fallback;
      if (v === 'true') return true;
      if (v === 'false') return false;
      return fallback;
    } catch (e) {
      return fallback;
    }
  },
  set(key, val) {
    try {
      localStorage.setItem('shiki-code:' + key, String(val));
    } catch (e) { /* ignore */
    }
  },
};