/**
 * Code highlighting for the preview, on top of Shiki.
 *
 * Shiki runs the same TextMate grammars and themes VS Code uses, so a fenced
 * block looks in the preview exactly like it will look to whoever reads the
 * published page. Four decisions shape this module:
 *
 * 1. **Nothing loads until a code block asks for it.** The engine is imported
 *    on the first highlight, and each grammar on the first block written in
 *    that language. A course about Python never downloads the Rust grammar —
 *    which is what makes two hundred grammars affordable in the first place.
 * 2. **Both themes are baked into one render.** Shiki emits `--shiki-light`
 *    and `--shiki-dark` custom properties per token and `editor.css` picks the
 *    pair that matches `data-theme`, so switching the theme costs no re-render.
 * 3. **What language a block is in is decided once, here.** `planBlock` reads
 *    the fence, asks `core/detect.js` when it says nothing useful, and returns
 *    the answer along with what to print above the block — so the header and
 *    the colours can never disagree.
 * 4. **A grammar we do not have is not an error.** Unknown languages fall back
 *    to plain, unhighlighted text.
 *
 * The JavaScript RegExp engine is used rather than the Oniguruma one: it needs
 * no WebAssembly, which keeps the vendored payload smaller and avoids a `.wasm`
 * MIME type that not every PHP host serves correctly.
 */
import { createHighlighterCore } from 'shiki';
import { createJavaScriptRegexEngine } from 'shiki/engine';
import DOMPurify from 'dompurify';

import { resolveLanguage, isPlain, languageLabel, normaliseInfo } from '@/core/languages.js';
import { chooseLanguage } from '@/core/detect.js';

const THEMES = { light: 'github-light-default', dark: 'github-dark-default' };

// Grammars are picked by name at runtime, so they cannot go through the import
// map the way every other dependency does; they are resolved against this
// module's own URL instead, which keeps them correct under any deploy path.
const langsUrl = (file) => new URL(`../../vendor/shiki/langs/${file}.mjs`, import.meta.url).href;
const themeUrl = (name) => new URL(`../../vendor/shiki/themes/${name}.mjs`, import.meta.url).href;

const CACHE_LIMIT = 120;

let highlighter = null;                  // resolves to the Shiki core instance
const grammarsLoading = new Map();       // id → promise, so a language loads once
const cache = new Map();                 // language and code → highlighted HTML

async function core() {
  highlighter ??= (async () => {
    const [light, dark] = await Promise.all([
      import(themeUrl(THEMES.light)),
      import(themeUrl(THEMES.dark)),
    ]);
    return createHighlighterCore({
      themes: [light.default, dark.default],
      langs: [],
      // `forgiving` degrades a pattern the JS engine cannot express instead of
      // throwing, which is the right trade for a preview.
      engine: createJavaScriptRegexEngine({ forgiving: true }),
    });
  })().catch((error) => {
    highlighter = null;      // a failed fetch must not disable highlighting for good
    throw error;
  });
  return highlighter;
}

async function loadGrammar(shiki, id) {
  if (shiki.getLoadedLanguages().includes(id)) return;
  if (!grammarsLoading.has(id)) {
    grammarsLoading.set(id, import(langsUrl(id))
      .then((module) => shiki.loadLanguage(module.default))
      .catch((error) => {
        grammarsLoading.delete(id);      // a failed fetch may succeed next time
        throw error;
      }));
  }
  await grammarsLoading.get(id);
}

/**
 * Everything the preview needs to know about one fenced block, decided in one
 * place so the header and the colours cannot disagree:
 *
 *   id        the grammar to highlight with, or `null` for plain text
 *   label     what to print above the block
 *   detected  the language was worked out from the code, not read off the fence
 *   replaced  the label the fence asked for, when it was overruled
 *
 * A fence naming something nobody has a grammar for keeps its own word as the
 * label — ```pseudocode still says "pseudocode" — because the author wrote it
 * on purpose and it is more use to a reader than "text".
 */
export function planBlock(code, info) {
  const declared = resolveLanguage(info);
  const plain = isPlain(info);
  const written = normaliseInfo(info);

  const choice = chooseLanguage(code, declared, { plain });
  if (choice.id) {
    return {
      id: choice.id,
      label: languageLabel(choice.id),
      detected: choice.detected,
      replaced: choice.replaced ? languageLabel(choice.replaced) : (choice.detected && written ? written : null),
    };
  }

  // Nothing to highlight with: say what the fence said, or nothing at all.
  return { id: null, label: plain || !written ? '' : written, detected: false, replaced: null };
}

/**
 * Highlighted `<pre class="shiki">…</pre>` for one fenced block, or `null` when
 * there is no grammar or the grammar failed to load — in which case the caller
 * keeps the plain block it already rendered.
 */
export async function highlightCode(code, id) {
  if (!id) return null;

  // Tokenising is synchronous and the preview re-renders on every keystroke, so
  // a block that has not changed must not be tokenised again.
  const key = `${id}\n${code}`;
  const hit = cache.get(key);
  if (hit !== undefined) return hit;

  try {
    const shiki = await core();
    await loadGrammar(shiki, id);
    const markup = shiki.codeToHtml(code, {
      lang: id,
      themes: THEMES,
      defaultColor: false,             // both palettes as custom properties
      structure: 'classic',
    });
    // Shiki escapes what it tokenises, but this is generated text on its way
    // into the DOM, so it takes the same route as everything else. The one
    // option is pinned to say where the boundary is: `core/diagrams.js` has
    // good reason to widen it for its own SVG, and this is not that place.
    // (Under DOMPurify 3.2.4 the pin was also a repair, because the option
    // carried over between calls; 3.4.14 resets it and the pin now agrees
    // with the default rather than correcting it.)
    const clean = DOMPurify.sanitize(markup, { HTML_INTEGRATION_POINTS: { 'annotation-xml': true } });

    if (cache.size >= CACHE_LIMIT) cache.delete(cache.keys().next().value);
    cache.set(key, clean);
    return clean;
  } catch (error) {
    console.warn(`[CourseForge] no highlighting for "${id}":`, error);
    return null;
  }
}
