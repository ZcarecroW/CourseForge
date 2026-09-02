/**
 * detect.js - auto-detection for a block that names no language.
 *
 * Two opinions, asked in order. First cf-detect.js, the evidence-based
 * detector CourseForge uses for its own preview: it scores patterns that are
 * hard to write by accident, and it declines rather than guesses - which is
 * the property that matters, because a Python block painted as something
 * else reads as a bug in the page. Only when it declines is highlight.js
 * asked, and its answer is held to a relevance bar and a low-trust list,
 * since highlightAuto() over every language it knows is happy to call six
 * lines of Python a DNS zone file. highlight.js never renders anything.
 */

import {opts, log, HLJS_URLS} from './config.js';
import {HLJS_LOW_TRUST, HLJS_TO_SHIKI} from './languages.js';
import {detectLanguage} from './cf-detect.js';

let hljsPromise = null;

function loadHljs() {
  if (!opts.detect) return Promise.reject(new Error('detection disabled'));
  if (!hljsPromise) {
    hljsPromise = (async () => {
      let lastErr = null;
      for (const url of HLJS_URLS) {
        try {
          const mod = await import(/* @vite-ignore */ url);
          const hljs = (mod && (mod.default || mod)) || null;
          if (hljs && typeof hljs.highlightAuto === 'function') return hljs;
          lastErr = new Error('Unexpected highlight.js module shape from ' + url);
        } catch (err) {
          lastErr = err;
          console.warn('[shiki-highlight] highlight.js CDN failed:', url, err);
        }
      }
      throw lastErr || new Error('highlight.js could not be loaded');
    })();
  }
  return hljsPromise;
}

const detectCache = new Map();
const CACHE_LIMIT = 500;
let hljsSubset = null;

/* FNV-1a over the full string. */
function cacheKey(code) {
  let h = 2166136261;
  for (let i = 0; i < code.length; i++) {
    h ^= code.charCodeAt(i);
    h = Math.imul(h, 16777619);
  }
  return code.length + '|' + (h >>> 0).toString(36);
}

function buildSubset(hljs) {
  if (Array.isArray(opts.detectSubset) && opts.detectSubset.length) return opts.detectSubset;
  if (hljsSubset) return hljsSubset;
  try {
    const all = hljs.listLanguages ? hljs.listLanguages() : [];
    hljsSubset = all.filter((l) => !HLJS_LOW_TRUST.has(l));
    if (!hljsSubset.length) hljsSubset = null;
  } catch (e) {
    hljsSubset = null;
  }
  return hljsSubset || undefined;
}

export async function detectWithHljs(code) {
  if (!opts.detect) return null;

  /* Keyed on a hash of the WHOLE text: a length + 256-char prefix collides for
     two same-length blocks sharing a header (two similar config files, two
     copies of a licence banner), and the second one silently inherited the
     first one's language. */
  const key = cacheKey(code);
  if (detectCache.has(key)) return detectCache.get(key);

  /* The detector that declines. When it answers, the answer is a Shiki id
     and it is right far more often than a relevance score is. */
  try {
    const sure = detectLanguage(code);
    if (sure && sure.id) {
      log('evidence detected', sure.id, 'score', sure.score);
      if (detectCache.size >= CACHE_LIMIT) detectCache.clear();
      detectCache.set(key, sure.id);
      return sure.id;
    }
  } catch (err) {
    log('evidence detection threw', err);
  }

  let hljs;
  try {
    hljs = await loadHljs();
  } catch (err) {
    log('detection unavailable', err);
    detectCache.set(key, null);
    return null;
  }

  let result = null;
  try {
    result = hljs.highlightAuto(code.slice(0, 20000), buildSubset(hljs));
  } catch (err) {
    log('highlightAuto threw', err);
  }

  const lines = code.split('\n').length;
  const min = lines <= 3
    ? Math.max(opts.detectMinRelevance * 2, 10)
    : opts.detectMinRelevance;

  let lang = null;
  if (result && result.language) {
    const trusted = !HLJS_LOW_TRUST.has(result.language) || result.relevance >= min * 2;
    if (result.relevance >= min && trusted) {
      lang = HLJS_TO_SHIKI[result.language] || result.language;
    }
    log('hljs detected', result.language, 'relevance', result.relevance,
      '(min ' + min + ')', '->', lang);
  }

  if (detectCache.size >= CACHE_LIMIT) detectCache.clear();
  detectCache.set(key, lang);
  return lang;
}