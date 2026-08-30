/**
 * The finishing pass over rendered Markdown.
 *
 * `core/markdown.js` turns Markdown into sanitised HTML synchronously and
 * leaves three kinds of placeholder behind, because each needs a library that
 * is only worth loading when a page actually contains one:
 *
 *   a ```mermaid block   → a .cf-diagram div   → core/diagrams.js
 *   any other fence      → a .cf-code pre      → core/highlight.js
 *   \( … \) and $$ … $$  → a .cf-math element  → core/math.js
 *
 * Filling those in used to live inside `components/MarkdownPreview.js`, which
 * meant the editor was the only place in the application where a fenced block
 * was ever coloured. Release notes rendered their code grey, their diagrams
 * stayed stuck on "drawing diagram…" for ever and their formulas showed as raw
 * LaTeX. The pass is here instead so that anything holding rendered Markdown
 * can finish it, and the two components that do — `MarkdownBlock` for reading,
 * `MarkdownPreview` for the editor — differ only in what they do afterwards.
 *
 * A fenced block also gets a header built here rather than written into the
 * Markdown: the sanitiser that stands between a generated page and the DOM
 * forbids `<button>`, and rightly so. Everything that arrives as text is
 * sanitised; the three controls above a block are constructed, so they are not
 * text at any point and there is nothing to sanitise. One delegated listener
 * per root serves all of them, which also means the header costs nothing per
 * block.
 *
 * Nothing here knows about scroll anchors, debouncing or Vue. `enhance()` takes
 * an element and resolves when it has finished with it; whether that is worth
 * re-measuring afterwards is the caller's business.
 */
import { watch } from 'vue';

import { planBlock, highlightCode } from '@/core/highlight.js';
import { renderDiagram } from '@/core/diagrams.js';
import { renderMath } from '@/core/math.js';
import { resolvedTheme } from '@/core/theme.js';
import { codeWrap, codeNumbers, toggleCodeWrap, toggleCodeNumbers, copyText } from '@/core/codeview.js';
import { toast } from '@/core/toast.js';

import { ICONS } from '@/components/AppIcon.js';

const COPIED_FOR = 1600;

/* -- the header above a fenced block --------------------------------------- */

const SVG_NS = 'http://www.w3.org/2000/svg';

/** One of `AppIcon`'s glyphs, built by hand because this is not a template. */
function glyph(name, className) {
  const svg = document.createElementNS(SVG_NS, 'svg');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('width', '13');
  svg.setAttribute('height', '13');
  svg.setAttribute('fill', 'none');
  svg.setAttribute('stroke', 'currentColor');
  svg.setAttribute('stroke-width', '2');
  svg.setAttribute('stroke-linecap', 'round');
  svg.setAttribute('stroke-linejoin', 'round');
  svg.setAttribute('aria-hidden', 'true');
  if (className) svg.setAttribute('class', className);
  for (const d of ICONS[name] ?? []) {
    const path = document.createElementNS(SVG_NS, 'path');
    path.setAttribute('d', d);
    svg.append(path);
  }
  return svg;
}

/**
 * A control in the header. The two toggles carry both of their glyphs and let
 * the stylesheet decide which one shows, so flipping a preference is a class on
 * the scroller rather than a walk over every block on the page.
 */
function control(action, label, icons) {
  const button = document.createElement('button');
  button.type = 'button';
  button.className = `cf-block__btn cf-block__btn--${action}`;
  button.dataset.codeAction = action;
  button.setAttribute('aria-label', label);
  button.title = label;
  for (const [name, state] of icons) button.append(glyph(name, `cf-block__icon is-${state}`));
  return button;
}

const TOGGLE_TITLES = {
  wrap: ['Long lines wrap — click to let them scroll instead (all code blocks)',
    'Long lines scroll — click to wrap them instead (all code blocks)'],
  numbers: ['Line numbers are shown — click to hide them (all code blocks)',
    'Line numbers are hidden — click to show them (all code blocks)'],
};

const COPY_LABEL = 'Copy this block';

/** The tick after a copy is a class on the block; the label is what says so. */
function uncopy(figure) {
  figure.classList.remove('is-copied');
  const button = figure.querySelector('[data-code-action="copy"]');
  if (!button) return;
  button.setAttribute('aria-label', COPY_LABEL);
  button.title = COPY_LABEL;
}

/** Keeps the two toggles' labels and pressed state in step with the preference. */
function describeToggles(root) {
  if (!root) return;
  for (const [action, [on, off]] of Object.entries(TOGGLE_TITLES)) {
    const pressed = action === 'wrap' ? codeWrap.value : codeNumbers.value;
    for (const button of root.querySelectorAll(`[data-code-action="${action}"]`)) {
      button.title = pressed ? on : off;
      button.setAttribute('aria-label', pressed ? on : off);
      button.setAttribute('aria-pressed', String(pressed));
    }
  }
}

/**
 * Wraps one plain `<pre class="cf-code">` in its header. The line anchor moves
 * to the wrapper, because the wrapper is now what occupies that place on the
 * page and the scroll link measures real positions.
 */
function dress(block, code, plan) {
  const figure = document.createElement('figure');
  figure.className = 'cf-block';
  figure.cfCode = code;

  const line = block.getAttribute('data-src-line');
  if (line !== null) {
    figure.setAttribute('data-src-line', line);
    block.removeAttribute('data-src-line');
  }
  block.removeAttribute('data-info');

  const bar = document.createElement('figcaption');
  bar.className = 'cf-block__bar';

  const name = document.createElement('span');
  name.className = 'cf-block__lang';
  name.textContent = plan.label;
  if (plan.detected) {
    name.classList.add('is-detected');
    name.title = plan.replaced
      ? `Fenced as “${plan.replaced}”, but this reads as ${plan.label}`
      : `No language on the fence — this reads as ${plan.label}`;
  }
  bar.append(name);

  const tools = document.createElement('span');
  tools.className = 'cf-block__tools';
  tools.append(
    control('wrap', TOGGLE_TITLES.wrap[0], [['wrap-text', 'on'], ['wrap-text-off', 'off']]),
    control('numbers', TOGGLE_TITLES.numbers[0], [['list-ordered', 'on'], ['list-ordered-off', 'off']]),
    control('copy', COPY_LABEL, [['copy', 'on'], ['check', 'done']]),
  );
  bar.append(tools);

  block.replaceWith(figure);
  figure.append(bar, block);
  return figure;
}

/* -- the pass itself -------------------------------------------------------- */

/**
 * The source of a diagram or a formula is the element's own text, which
 * rendering then replaces. It is kept on the node as well, so a second pass
 * over the same element — a theme change redraws diagrams without touching the
 * Markdown — still has the original to work from.
 */
const sourceOf = (element) => {
  element.cfSource ??= element.textContent;
  return element.cfSource;
};

const fill = (element, markup) => {
  element.innerHTML = markup;
  element.classList.add('is-rendered');
  element.classList.remove('is-failed');
};

/** A diagram that does not parse is replaced by what Mermaid said about it. */
const fail = (element, error) => {
  element.textContent = String(error?.message ?? error);
  element.classList.add('is-rendered', 'is-failed');
};

/** Swaps the plain listing inside a dressed block for its highlighted twin. */
function swapCode(figure, markup) {
  const holder = document.createElement('div');
  holder.innerHTML = markup;
  const next = holder.firstElementChild;
  const current = figure.querySelector('pre');
  if (!next || !current) return;
  // Shiki calls its element `shiki`; both share the presentation rules.
  next.classList.add('cf-code');
  current.replaceWith(next);
}

/**
 * Finishes every placeholder under `root`, and resolves when it has.
 *
 * `alive` is how a caller that re-renders on every keystroke throws away the
 * result of a pass that has been superseded: a pass whose token no longer
 * matches drops its work instead of writing into a document that no longer
 * exists. A caller that renders once can leave it alone.
 *
 * @param {Element|null} root
 * @param {{theme?: string, alive?: () => boolean}} [options]
 */
export async function enhance(root, { theme = resolvedTheme.value, alive = () => true } = {}) {
  if (!root) return;

  const jobs = [];

  // Every block is dressed, whether or not a grammar can be found for it: the
  // header, the numbers and the copy button do not depend on Shiki. A pass that
  // follows a theme change rather than an edit runs over the same elements
  // again, and a block already inside its header is finished — dressing it
  // twice would nest one figure inside another.
  for (const block of root.querySelectorAll('pre.cf-code')) {
    if (block.closest('.cf-block')) continue;
    const code = block.textContent.replace(/\n$/, '');
    const plan = planBlock(code, block.dataset.info ?? '');
    const figure = dress(block, code, plan);
    if (!plan.id) continue;
    jobs.push(highlightCode(code, plan.id).then((markup) => {
      if (alive() && markup && figure.isConnected) swapCode(figure, markup);
    }));
  }
  describeToggles(root);

  // Both sources are the element's own text, which is also what is on screen
  // until the library that replaces them has loaded.
  for (const slot of root.querySelectorAll('div.cf-diagram:not(.is-rendered)')) {
    jobs.push(renderDiagram(sourceOf(slot), theme)
      .then((svg) => { if (alive() && slot.isConnected) fill(slot, svg); })
      .catch((error) => { if (alive() && slot.isConnected) fail(slot, error); }));
  }

  // A formula that does not compile comes back as MathJax's own error markup,
  // so a rejection here only means the library never arrived — in which case
  // the LaTeX stays on screen and the next pass tries again.
  for (const slot of root.querySelectorAll('.cf-math:not(.is-rendered)')) {
    jobs.push(renderMath(sourceOf(slot), slot.classList.contains('cf-math--block'))
      .then((markup) => { if (alive() && slot.isConnected) fill(slot, markup); })
      .catch((error) => console.warn('[CourseForge] no formulas:', error)));
  }

  await Promise.all(jobs);
}

/**
 * Marks every rendered diagram as needing another pass.
 *
 * A theme change concerns diagrams and nothing else: Shiki ships both palettes
 * in one render and MathJax draws in `currentColor`.
 */
export function resetDiagrams(root) {
  for (const slot of root?.querySelectorAll('div.cf-diagram.is-rendered') ?? []) {
    slot.classList.remove('is-rendered');
  }
}

/**
 * Makes the three controls above every block in `root` work, and returns the
 * teardown.
 *
 * One listener rather than one per block, and one watcher that keeps the two
 * toggles' wording in step with the preference they read — both of which are
 * per-root rather than global, so a screen showing several rendered documents
 * gets several independent copies and unmounting one takes its own with it.
 */
export function bindBlocks(root) {
  if (!root) return () => {};

  let copiedTimer = null;

  async function copyBlock(button) {
    const figure = button.closest('.cf-block');
    if (!figure) return;
    if (!await copyText(figure.cfCode ?? '')) {
      toast.error('The clipboard is not available in this browser.');
      return;
    }
    clearTimeout(copiedTimer);
    for (const other of root.querySelectorAll('.cf-block.is-copied')) uncopy(other);

    figure.classList.add('is-copied');
    button.setAttribute('aria-label', 'Copied');
    button.title = 'Copied';
    copiedTimer = setTimeout(() => uncopy(figure), COPIED_FOR);
  }

  const onClick = (event) => {
    const button = event.target.closest?.('[data-code-action]');
    if (!button || !root.contains(button)) return;
    const action = button.dataset.codeAction;
    if (action === 'wrap') toggleCodeWrap();
    else if (action === 'numbers') toggleCodeNumbers();
    else if (action === 'copy') copyBlock(button);
  };

  root.addEventListener('click', onClick);
  const stop = watch([codeWrap, codeNumbers], () => describeToggles(root));

  return () => {
    clearTimeout(copiedTimer);
    root.removeEventListener('click', onClick);
    stop();
  };
}
