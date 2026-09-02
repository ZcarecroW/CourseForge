/**
 * loader.js - Shiki module / regex engine / highlighter loading and rendering.
 */

import {opts, log, CDN_URLS, ENGINE_URLS} from './config.js';

let shikiPromise = null;

export function loadShiki() {
  if (!shikiPromise) {
    shikiPromise = (async () => {
      let lastErr = null;
      for (const url of CDN_URLS) {
        try {
          const mod = await import(/* @vite-ignore */ url);
          if (mod && typeof mod.createHighlighter === 'function') return mod;
          if (mod && typeof mod.getHighlighter === 'function') return mod;
          lastErr = new Error('Unexpected module shape from ' + url);
        } catch (err) {
          lastErr = err;
          console.warn('[shiki-highlight] CDN failed:', url, err);
        }
      }
      throw lastErr || new Error('Shiki could not be loaded');
    })();
  }
  return shikiPromise;
}

let enginePromise = null;

function loadEngine() {
  if (!enginePromise) {
    enginePromise = (async () => {
      let lastErr = null;
      for (const url of ENGINE_URLS) {
        try {
          const mod = await import(/* @vite-ignore */ url);
          if (mod && typeof mod.createJavaScriptRegexEngine === 'function') {
            return mod.createJavaScriptRegexEngine({forgiving: true, target: 'auto'});
          }
          lastErr = new Error('Unexpected engine module shape from ' + url);
        } catch (err) {
          lastErr = err;
          console.warn('[shiki-highlight] engine CDN failed:', url, err);
        }
      }
      throw lastErr || new Error('Shiki JS regex engine could not be loaded');
    })();
  }
  return enginePromise;
}

let highlighterPromise = null;

export function getHighlighter() {
  if (!highlighterPromise) {
    highlighterPromise = (async () => {
      const shiki = await loadShiki();
      const create = shiki.createHighlighter || shiki.getHighlighter;
      const themes = [opts.themes.light, opts.themes.dark];

      let engine = null;
      try {
        engine = await loadEngine();
      } catch (err) {
        console.warn('[shiki-highlight] JS regex engine unavailable; trying default (WASM) engine.', err);
      }

      const attempts = engine
        ? [{themes, langs: ['plaintext'], engine}, {themes, langs: ['plaintext']}]
        : [{themes, langs: ['plaintext']}];

      let lastErr = null;
      for (const cfg of attempts) {
        try {
          const hl = await create(cfg);
          hl.codeToHtml('test', {lang: 'plaintext', theme: themes[0]});
          log('highlighter ready', cfg.engine ? '(javascript engine)' : '(default/wasm engine)');
          return hl;
        } catch (err) {
          lastErr = err;
          console.warn('[shiki-highlight] highlighter creation failed', err);
        }
      }
      throw lastErr || new Error('Shiki highlighter could not be created');
    })();
  }
  return highlighterPromise;
}

export async function ensureLang(hl, lang) {
  if (!lang || lang === 'plaintext' || lang === 'text' || lang === 'txt') return 'plaintext';
  try {
    const loaded = hl.getLoadedLanguages ? hl.getLoadedLanguages() : [];
    if (loaded.indexOf(lang) !== -1) return lang;
    await hl.loadLanguage(lang);
    return lang;
  } catch (err) {
    console.warn('[shiki-highlight] could not load grammar "' + lang + '"', err);
    return 'plaintext';
  }
}

export function highlight(hl, code, lang) {
  const settings = {
    lang,
    themes: opts.themes,
    defaultColor: 'light',        // light = inline colours, dark = CSS vars
    cssVariablePrefix: '--shiki-',
  };
  try {
    return {html: hl.codeToHtml(code, settings), lang};
  } catch (err) {
    console.warn('[shiki-highlight] "' + lang + '" failed, retrying as plaintext', err);
    settings.lang = 'plaintext';
    return {html: hl.codeToHtml(code, settings), lang: 'plaintext'};
  }
}

export function plainHtml(code) {
  const esc = code.replace(/[&<>]/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;'}[c]));
  const lines = esc.split('\n').map((l) => '<span class="line">' + l + '</span>').join('');
  return '<pre class="shiki"><code>' + lines + '</code></pre>';
}