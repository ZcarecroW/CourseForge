/**
 * MathJax formulas for the preview.
 *
 * The delimiter contract is the one the prompt library hands the AI and the one
 * BookStack renders: `\( … \)` inline, `$$ … $$` as a block. Markdown would
 * destroy both — CommonMark treats `\(` as an escaped bracket and `_` inside
 * `$$` as emphasis — so `core/markdown.js` lifts every formula out of the text
 * before parsing and leaves a placeholder carrying the untouched LaTeX. This
 * module turns those placeholders into SVG.
 *
 * Three deliberate choices:
 *
 * - **SVG output, not CHTML.** The SVG component carries its glyph outlines
 *   inside the one vendored file, so there are no web fonts to ship and no font
 *   requests at runtime.
 * - **`require` and `autoload` are switched off.** Both fetch extension files
 *   on demand, which cannot work from a vendored single file, and `autoload` is
 *   what would otherwise pull in `\href` — the one TeX macro that can put a
 *   `javascript:` URL into the page.
 * - **Formulas are converted one at a time** rather than by typesetting the
 *   container, so nothing that merely looks like a delimiter inside a code
 *   block is ever picked up.
 */
const SCRIPT_URL = new URL('../../vendor/mathjax/tex-mml-svg.js', import.meta.url).href;
const CACHE_LIMIT = 400;

const cache = new Map();     // 'b' or 'i' plus the LaTeX → rendered HTML
let loading = null;

function load() {
  loading ??= new Promise((resolve, reject) => {
    globalThis.MathJax = {
      tex: {
        packages: { '[-]': ['require', 'autoload'] },
        inlineMath: [['\\(', '\\)']],
        displayMath: [['$$', '$$']],
        processEscapes: false,
      },
      svg: { fontCache: 'local', displayAlign: 'left', displayIndent: '0' },
      options: { enableMenu: false },
      startup: { typeset: false },
    };

    const script = document.createElement('script');
    script.src = SCRIPT_URL;
    script.async = true;
    script.onload = () => {
      const mathjax = globalThis.MathJax;
      if (!mathjax?.startup?.promise) { reject(new Error('MathJax did not start.')); return; }
      mathjax.startup.promise
        .then(() => {
          // The SVG output needs one stylesheet in the document; it is the same
          // for every formula, so it is added once and left there. The lookup
          // is scoped to the head because an `id` survives sanitising, and a
          // page claiming that one would otherwise leave every formula unstyled.
          if (!document.head.querySelector('#MJX-SVG-styles')) {
            document.head.append(mathjax.svgStylesheet());
          }
          resolve(mathjax);
        })
        .catch(reject);
    };
    script.onerror = () => {
      loading = null;
      reject(new Error('MathJax could not be loaded.'));
    };
    document.head.append(script);
  });
  return loading;
}

/**
 * Rendered HTML for one formula. TeX that does not compile comes back as
 * MathJax's own error markup, which names the offending macro — more useful to
 * an author than a silent blank.
 */
export async function renderMath(tex, display) {
  const key = `${display ? 'b' : 'i'}${tex}`;
  const hit = cache.get(key);
  if (hit !== undefined) return hit;

  const mathjax = await load();
  const node = await mathjax.tex2svgPromise(tex, { display });

  // MathJax output is not markup the author wrote, so it does not go through
  // DOMPurify — the sanitiser would strip the custom elements it is made of.
  // Disabling `autoload` already removes `\href`; this closes the one remaining
  // way TeX can name a URL, `\mmlToken` with an attribute list.
  for (const element of node.querySelectorAll('[href], [*|href]')) {
    for (const { name } of [...element.attributes]) {
      if (name === 'href' || name.endsWith(':href')) element.removeAttribute(name);
    }
  }

  const html = node.outerHTML;      // the <mjx-container> itself carries the styling

  if (cache.size >= CACHE_LIMIT) cache.delete(cache.keys().next().value);
  cache.set(key, html);
  return html;
}
