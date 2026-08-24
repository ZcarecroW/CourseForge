/**
 * Mermaid diagrams for the preview.
 *
 * The AI is told to draw flows, state machines and timelines as ```mermaid
 * blocks (see the "Mermaid diagrams" content detail), and BookStack renders
 * them once published. This module renders the same blocks while the page is
 * still being written, so a broken diagram is caught here rather than after a
 * publish.
 *
 * Mermaid is vendored as its UMD build and loaded through a script tag rather
 * than an import: the ES build is a stub that pulls three dozen chunks over the
 * network at runtime, which would defeat the point of vendoring. The UMD file
 * is one self-contained 3.5 MB payload that is fetched on the first diagram and
 * never again.
 *
 * Rendering is not incremental — a diagram is laid out from scratch every time
 * — so results are cached by source and theme. Retyping a line above a diagram
 * therefore does not redraw it.
 */
import DOMPurify from 'dompurify';

const SCRIPT_URL = new URL('../../vendor/mermaid.min.js', import.meta.url).href;
const CACHE_LIMIT = 60;

const cache = new Map();     // theme and source → sanitised SVG, or the parse error
let loading = null;
let appliedTheme = null;
let counter = 0;

function load() {
  loading ??= new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = SCRIPT_URL;
    script.onload = () => (globalThis.mermaid
      ? resolve(globalThis.mermaid)
      : reject(new Error('Mermaid loaded but did not register itself.')));
    script.onerror = () => {
      loading = null;
      reject(new Error('Mermaid could not be loaded.'));
    };
    document.head.append(script);
  });
  return loading;
}

/**
 * Mermaid resolves its palette at initialise time, so a theme change means
 * re-initialising and redrawing. Both built-in themes are close enough to the
 * CourseForge surfaces that only the text and line colours need overriding.
 */
function configure(mermaid, theme) {
  if (appliedTheme === theme) return;

  // Read the palette out of the document rather than restating it: tokens.css
  // has already resolved the right value for whichever theme is stamped on
  // <html>, and nothing outside it is allowed to name a colour.
  const styles = getComputedStyle(document.documentElement);
  const token = (name) => styles.getPropertyValue(name).trim();

  mermaid.initialize({
    startOnLoad: false,
    securityLevel: 'strict',           // labels are sanitised, no click handlers
    suppressErrorRendering: true,      // a failed parse must not inject its own SVG
    theme: theme === 'light' ? 'default' : 'dark',
    fontFamily: token('--font'),
    themeVariables: {
      background: 'transparent',
      primaryTextColor: token('--text'),
      lineColor: token('--text-dim'),
    },
  });
  appliedTheme = theme;
}

/**
 * SVG for one diagram. Throws with Mermaid's own message when the source does
 * not parse, which is what the preview shows the author.
 */
export async function renderDiagram(source, theme) {
  const key = `${theme}\n${source}`;
  const hit = cache.get(key);
  if (hit) {
    if (hit.error) throw new Error(hit.error);
    return hit.svg;
  }

  const mermaid = await load();
  configure(mermaid, theme);

  const remember = (entry) => {
    if (cache.size >= CACHE_LIMIT) cache.delete(cache.keys().next().value);
    cache.set(key, entry);
    return entry;
  };

  let svg;
  try {
    counter += 1;
    ({ svg } = await mermaid.render(`cf-diagram-${counter}`, source));
  } catch (error) {
    // A half-written diagram fails on nearly every keystroke, so failures are
    // remembered too; otherwise Mermaid would re-parse the same broken source
    // on every render.
    throw new Error(remember({ error: reason(error) }).error);
  }

  // Mermaid sanitises the labels it puts into the SVG; this is the second pass,
  // because the source is AI-written text that reaches the DOM as markup.
  //
  // Mermaid writes node labels as real HTML inside <foreignObject>, which is
  // how it wraps and styles them — and exactly what BookStack will show. Both
  // have to be allowed through by name: DOMPurify drops the tag by default, and
  // treats the HTML inside it as a namespace escape unless foreignObject is
  // named as an integration point. What is inside is still sanitised as HTML,
  // so an `onerror`, a `javascript:` href or a <script> does not survive.
  return remember({
    svg: DOMPurify.sanitize(svg, {
      USE_PROFILES: { svg: true, svgFilters: true, html: true },
      ADD_TAGS: ['foreignObject'],
      HTML_INTEGRATION_POINTS: { foreignobject: true, 'annotation-xml': true },
    }),
  }).svg;
}

/** Mermaid reports a parse failure as `str`, not as a message. */
const reason = (error) => String(error?.str ?? error?.message ?? error).trim()
  || 'This diagram could not be drawn.';
