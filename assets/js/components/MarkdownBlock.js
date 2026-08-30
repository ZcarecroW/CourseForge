/**
 * Rendered Markdown, anywhere that is not the editor.
 *
 * `MarkdownPreview` is the editor's half of this: it debounces on typing, keeps
 * a table of where each source line landed, and drives the split view. None of
 * that is wanted when the Markdown is simply being read — a release note, a
 * course description, a chapter summary — and all of it costs something. This
 * component is the same render and the same finishing pass with none of it.
 *
 * The two share `core/enhance.js`, which is what makes a fenced block in a
 * release note look exactly like one in a page: same colours, same header, same
 * copy, wrap and line-number buttons, same Mermaid, same MathJax. The
 * `.cf-reader` class on the wrapper is what carries the reader's two
 * preferences down to the blocks; it has no layout of its own, so this drops
 * into a card, a pane or a table cell without bringing a box with it.
 */
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';

import { renderMarkdown } from '@/core/markdown.js';
import { enhance, bindBlocks, resetDiagrams } from '@/core/enhance.js';
import { resolvedTheme } from '@/core/theme.js';
import { codeWrap, codeNumbers } from '@/core/codeview.js';

export const MarkdownBlock = {
  name: 'MarkdownBlock',
  props: {
    source: { type: String, default: '' },
    /** Tighter leading and no outer margins, for a card or a list row. */
    compact: { type: Boolean, default: false },
  },
  emits: ['rendered'],
  setup(props, { emit }) {
    const body = ref(null);
    const html = ref(renderMarkdown(props.source));

    let pass = 0;
    let unbind = null;

    const has = computed(() => props.source.trim() !== '');

    /**
     * No debounce, unlike the editor's copy: this is not a typing surface, so a
     * change to the source is a real change and there is nothing to wait for.
     * The pass token is still needed — the source can change while a grammar or
     * a diagram is still loading.
     */
    function run() {
      pass += 1;
      const token = pass;
      html.value = renderMarkdown(props.source);
      nextTick(() => enhance(body.value, { alive: () => token === pass })
        .then(() => { if (token === pass) emit('rendered'); }));
    }

    watch(() => props.source, run);

    watch(resolvedTheme, () => {
      resetDiagrams(body.value);
      pass += 1;
      const token = pass;
      enhance(body.value, { alive: () => token === pass });
    });

    onMounted(() => {
      enhance(body.value, { alive: () => pass === 0 });
      unbind = bindBlocks(body.value);
    });

    onBeforeUnmount(() => unbind?.());

    return { body, html, has, codeWrap, codeNumbers };
  },
  template: `
    <div v-if="has" class="cf-reader" :class="{ 'is-wrap': codeWrap, 'is-numbered': codeNumbers }">
      <div class="prose" :class="{ 'prose--compact': compact }" ref="body" v-html="html"></div>
    </div>`,
};

export default MarkdownBlock;
