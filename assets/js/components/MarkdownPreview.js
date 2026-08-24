/**
 * The rendered half of the editor.
 *
 * `core/markdown.js` produces the HTML synchronously and leaves three kinds of
 * placeholder behind — a fenced block, a Mermaid diagram, a formula — each of
 * which needs a library this component loads on first use. Everything here is
 * about making that finishing pass cheap enough to survive continuous typing:
 *
 * - the Markdown is re-rendered on a short debounce, not per character;
 * - every library caches by source, so a placeholder that did not change is
 *   refilled from memory rather than laid out again;
 * - each pass carries a token, and a pass whose token has been superseded drops
 *   its result instead of writing into a document that no longer exists.
 *
 * A fenced block also gets a header built here rather than in the Markdown:
 * the sanitiser that stands between a generated page and the DOM forbids
 * `<button>`, and rightly so. Everything that arrives as text is sanitised;
 * the three controls above a block are constructed, so they are not text at any
 * point and there is nothing to sanitise. One delegated listener on the body
 * serves all of them, which also means the header costs nothing per block.
 *
 * The component also answers where each source line ended up on screen, which
 * is what lets the split view keep both halves on the same passage.
 */
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { renderMarkdown } from '@/core/markdown.js';
import { planBlock, highlightCode } from '@/core/highlight.js';
import { renderDiagram } from '@/core/diagrams.js';
import { renderMath } from '@/core/math.js';
import { resolvedTheme } from '@/core/theme.js';
import { codeWrap, codeNumbers, toggleCodeWrap, toggleCodeNumbers, copyText } from '@/core/codeview.js';
import { toast } from '@/core/toast.js';

import { ICONS } from '@/components/AppIcon.js';
import EmptyState from '@/components/EmptyState.js';

const DEBOUNCE = 180;
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

export const MarkdownPreview = {
  name: 'MarkdownPreview',
  components: { EmptyState },
  props: {
    source: { type: String, default: '' },
  },
  emits: ['scroll', 'rendered'],
  setup(props, { emit, expose }) {
    const scroller = ref(null);
    const body = ref(null);
    const html = ref(renderMarkdown(props.source));

    let timer = null;
    let pass = 0;
    let anchors = [];
    let copiedTimer = null;

    const hasContent = computed(() => props.source.trim() !== '');

    /* -- the finishing pass ----------------------------------------------- */

    /**
     * The source of a diagram or a formula is the element's own text, which
     * rendering then replaces. It is kept on the node as well, so a second pass
     * over the same element — a theme change redraws diagrams without touching
     * the Markdown — still has the original to work from.
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
    const swapCode = (figure, markup) => {
      const holder = document.createElement('div');
      holder.innerHTML = markup;
      const next = holder.firstElementChild;
      const current = figure.querySelector('pre');
      if (!next || !current) return;
      // Shiki calls its element `shiki`; both share the presentation rules.
      next.classList.add('cf-code');
      current.replaceWith(next);
    };

    async function enhance(token) {
      const root = body.value;
      if (!root) return;

      const jobs = [];

      // Every block is dressed, whether or not a grammar can be found for it:
      // the header, the numbers and the copy button do not depend on Shiki. A
      // pass that follows a theme change rather than an edit runs over the same
      // elements again, and a block already inside its header is finished —
      // dressing it twice would nest one figure inside another.
      for (const block of root.querySelectorAll('pre.cf-code')) {
        if (block.closest('.cf-block')) continue;
        const code = block.textContent.replace(/\n$/, '');
        const plan = planBlock(code, block.dataset.info ?? '');
        const figure = dress(block, code, plan);
        if (!plan.id) continue;
        jobs.push(highlightCode(code, plan.id).then((markup) => {
          if (token === pass && markup && figure.isConnected) swapCode(figure, markup);
        }));
      }
      describeToggles(root);

      // Both sources are the element's own text, which is also what is on
      // screen until the library that replaces them has loaded.
      for (const slot of root.querySelectorAll('div.cf-diagram:not(.is-rendered)')) {
        jobs.push(renderDiagram(sourceOf(slot), resolvedTheme.value)
          .then((svg) => { if (token === pass && slot.isConnected) fill(slot, svg); })
          .catch((error) => { if (token === pass && slot.isConnected) fail(slot, error); }));
      }

      // A formula that does not compile comes back as MathJax's own error
      // markup, so a rejection here only means the library never arrived —
      // in which case the LaTeX stays on screen and the next pass tries again.
      for (const slot of root.querySelectorAll('.cf-math:not(.is-rendered)')) {
        jobs.push(renderMath(sourceOf(slot), slot.classList.contains('cf-math--block'))
          .then((markup) => { if (token === pass && slot.isConnected) fill(slot, markup); })
          .catch((error) => console.warn('[CourseForge] no formulas:', error)));
      }

      await Promise.all(jobs);
      if (token !== pass) return;
      measure();
      emit('rendered');
    }

    /* -- the block controls ------------------------------------------------ */

    async function copyBlock(button) {
      const figure = button.closest('.cf-block');
      if (!figure) return;
      if (!await copyText(figure.cfCode ?? '')) {
        toast.error('The clipboard is not available in this browser.');
        return;
      }
      clearTimeout(copiedTimer);
      for (const other of body.value?.querySelectorAll('.cf-block.is-copied') ?? []) uncopy(other);

      figure.classList.add('is-copied');
      button.setAttribute('aria-label', 'Copied');
      button.title = 'Copied';
      copiedTimer = setTimeout(() => uncopy(figure), COPIED_FOR);
    }

    const onBodyClick = (event) => {
      const button = event.target.closest?.('[data-code-action]');
      if (!button) return;
      const action = button.dataset.codeAction;
      if (action === 'wrap') toggleCodeWrap();
      else if (action === 'numbers') toggleCodeNumbers();
      else if (action === 'copy') copyBlock(button);
    };

    /* -- where each source line landed ------------------------------------ */

    /**
     * One entry per top-level block, plus the two ends of the document, so the
     * mapping is defined for every line rather than only between the first and
     * the last heading.
     */
    function measure() {
      const root = body.value;
      const box = scroller.value;
      if (!root || !box) { anchors = []; return; }

      const base = box.getBoundingClientRect().top - box.scrollTop;
      const found = [...root.querySelectorAll('[data-src-line]')]
        .map((element) => ({
          line: Number(element.getAttribute('data-src-line')),
          top: element.getBoundingClientRect().top - base,
        }))
        .filter((anchor) => Number.isFinite(anchor.line))
        .sort((a, b) => a.line - b.line || a.top - b.top);

      // Whatever sits above the first block is padding, not content, so line
      // zero is the top of the scroller rather than the top of that block.
      if (found.length && found[0].line === 0) found[0].top = 0;
      else found.unshift({ line: 0, top: 0 });

      // The closing row is the foot of the rendered body, measured the same way
      // every other row is. A maximum scroll position would be the intuitive
      // thing to put here and is a different quantity — mixing the two makes
      // the last stretch of the document map at a different rate to the rest.
      // Asking to scroll past the end is harmless: the browser clamps it.
      const lines = Math.max(1, props.source.split('\n').length);
      found.push({ line: lines, top: root.getBoundingClientRect().bottom - base });

      anchors = found;
    }

    /** Reads one column of the anchor table off the other, interpolating. */
    const between = (value, from, to) => {
      if (!anchors.length) return 0;
      if (value <= from(anchors[0])) return to(anchors[0]);
      for (let i = 1; i < anchors.length; i += 1) {
        if (value <= from(anchors[i])) {
          const span = from(anchors[i]) - from(anchors[i - 1]);
          const share = span > 0 ? (value - from(anchors[i - 1])) / span : 0;
          return to(anchors[i - 1]) + share * (to(anchors[i]) - to(anchors[i - 1]));
        }
      }
      return to(anchors[anchors.length - 1]);
    };

    /* -- the API the split view drives ------------------------------------ */

    const scrollToLine = (line) => {
      const box = scroller.value;
      if (box) box.scrollTop = Math.max(0, between(line, (a) => a.line, (a) => a.top));
    };

    const topLine = () => {
      const box = scroller.value;
      return box ? between(box.scrollTop, (a) => a.top, (a) => a.line) : 0;
    };

    /* -- rendering --------------------------------------------------------- */

    function render() {
      pass += 1;
      const token = pass;
      html.value = renderMarkdown(props.source);
      nextTick(() => enhance(token));
    }

    watch(() => props.source, () => {
      clearTimeout(timer);
      timer = setTimeout(render, DEBOUNCE);
    });

    // A theme change only concerns diagrams: Shiki ships both palettes in one
    // render and MathJax draws in `currentColor`.
    watch(resolvedTheme, () => {
      for (const slot of body.value?.querySelectorAll('div.cf-diagram.is-rendered') ?? []) {
        slot.classList.remove('is-rendered');
      }
      pass += 1;
      enhance(pass);
    });

    /**
     * The anchor table is measured geometry, so it goes stale whenever the
     * layout moves and not only when the text does — the inspector opening, the
     * window being resized, an image arriving late. Watching the rendered body
     * catches all of those, and `rendered` asks the split view to re-align on
     * the new numbers.
     */
    let sizes = null;
    let queued = false;

    const remeasure = () => {
      if (queued) return;
      queued = true;
      requestAnimationFrame(() => {
        queued = false;
        measure();
        emit('rendered');
      });
    };

    // Wrapping and numbering are a class on the scroller, so nothing is
    // re-rendered; only the words on the two toggles have to catch up. Both
    // change the height of every block, so the scroll link is told to re-align.
    watch([codeWrap, codeNumbers], () => {
      describeToggles(body.value);
      nextTick(remeasure);
    });

    onMounted(() => {
      enhance(pass);
      body.value.addEventListener('click', onBodyClick);
      sizes = new ResizeObserver(remeasure);
      sizes.observe(body.value);
    });

    onBeforeUnmount(() => {
      clearTimeout(timer);
      clearTimeout(copiedTimer);
      body.value?.removeEventListener('click', onBodyClick);
      sizes?.disconnect();
    });

    // Only what the scroll link needs: measuring is this component's own business.
    expose({ scrollToLine, topLine });

    return {
      scroller, body, html, hasContent, codeWrap, codeNumbers,
      onScroll: () => emit('scroll'),
    };
  },
  template: `
    <div class="pane__body view-pad cf-preview" ref="scroller" @scroll.passive="onScroll"
         :class="{ 'is-wrap': codeWrap, 'is-numbered': codeNumbers }">
      <div v-show="hasContent" class="prose" ref="body" v-html="html"></div>
      <empty-state v-if="!hasContent" icon="file-text" title="No content yet"
                   hint="Generate this page, or start typing on the left."/>
    </div>`,
};

export default MarkdownPreview;
