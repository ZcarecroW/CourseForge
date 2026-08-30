/**
 * The rendered half of the editor.
 *
 * `core/markdown.js` produces the HTML synchronously and leaves three kinds of
 * placeholder behind — a fenced block, a Mermaid diagram, a formula — which
 * `core/enhance.js` fills in. That pass is shared with `MarkdownBlock`, so a
 * code block here and a code block in a release note are the same thing; what
 * is left in this file is only what the *editor* needs on top of it:
 *
 * - the Markdown is re-rendered on a short debounce, not per character;
 * - each pass carries a token, and a pass whose token has been superseded drops
 *   its result instead of writing into a document that no longer exists;
 * - and every top-level block's position is measured afterwards, which is what
 *   lets the split view keep both halves on the same passage.
 */
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { renderMarkdown } from '@/core/markdown.js';
import { enhance, bindBlocks, resetDiagrams } from '@/core/enhance.js';
import { resolvedTheme } from '@/core/theme.js';
import { codeWrap, codeNumbers } from '@/core/codeview.js';

import EmptyState from '@/components/EmptyState.js';

const DEBOUNCE = 180;

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
    let unbind = null;

    const hasContent = computed(() => props.source.trim() !== '');

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

    /**
     * The measuring that used to end the finishing pass now follows it here:
     * `enhance` is shared with every other reader of Markdown in the
     * application, and none of them has a scroll link to re-align.
     */
    const finish = (token) => enhance(body.value, { alive: () => token === pass })
      .then(() => { if (token === pass) { measure(); emit('rendered'); } });

    function render() {
      pass += 1;
      const token = pass;
      html.value = renderMarkdown(props.source);
      nextTick(() => finish(token));
    }

    watch(() => props.source, () => {
      clearTimeout(timer);
      timer = setTimeout(render, DEBOUNCE);
    });

    // A theme change only concerns diagrams: Shiki ships both palettes in one
    // render and MathJax draws in `currentColor`.
    watch(resolvedTheme, () => {
      resetDiagrams(body.value);
      pass += 1;
      finish(pass);
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
    // re-rendered, and `bindBlocks` keeps the words on the two toggles in step.
    // Both change the height of every block, so the scroll link is told to
    // re-align, which is all that is left for this screen to do about it.
    watch([codeWrap, codeNumbers], () => nextTick(remeasure));

    onMounted(() => {
      finish(pass);
      unbind = bindBlocks(body.value);
      sizes = new ResizeObserver(remeasure);
      sizes.observe(body.value);
    });

    onBeforeUnmount(() => {
      clearTimeout(timer);
      unbind?.();
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
    <div class="pane__body view-pad cf-preview cf-reader" ref="scroller" @scroll.passive="onScroll"
         :class="{ 'is-wrap': codeWrap, 'is-numbered': codeNumbers }">
      <div v-show="hasContent" class="prose" ref="body" v-html="html"></div>
      <empty-state v-if="!hasContent" icon="file-text" title="No content yet"
                   hint="Generate this page, or start typing on the left."/>
    </div>`,
};

export default MarkdownPreview;
