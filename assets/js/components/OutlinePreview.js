/**
 * The parsed half of the Structure tab: what Apply would do, while the outline
 * is still being typed.
 *
 * The outline in the editor is text; what the server makes of it is chapters
 * and pages, matched by title against the ones the course already has. This
 * draws that result as it will be - every chapter with its pages, each marked
 * kept, moved or new - and, below, the pages the outline no longer names, with
 * the written ones marked, because those are what Apply deletes and what it
 * asks about first. A rename shows up here as one page going and one arriving,
 * which is exactly what it is to the server.
 *
 * Every row carries the source line it came from, so the split view can keep
 * this half on the same passage as the editor, the way the Content tab's
 * preview does with a page. `core/anchors.js` is the table both read.
 */
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { parseOutline, diffOutline } from '@/core/outline.js';
import { measureAnchors, topFor, lineAt } from '@/core/anchors.js';
import { plural } from '@/core/format.js';

import AppIcon from '@/components/AppIcon.js';
import EmptyState from '@/components/EmptyState.js';

const DEBOUNCE = 150;

/** How a page's standing is drawn: the dot the Content tab uses for the same state, and a word. */
const STATUS = {
  kept: { label: '', tone: '' },
  moved: { label: 'moved', tone: 'badge--accent' },
  new: { label: 'new', tone: 'badge--success' },
};

export const OutlinePreview = {
  name: 'OutlinePreview',
  components: { AppIcon, EmptyState },
  props: {
    source: { type: String, default: '' },
    /** The course as the server last sent it, for the match. */
    project: { type: Object, required: true },
  },
  emits: ['scroll', 'rendered'],
  setup(props, { emit, expose }) {
    const scroller = ref(null);
    const body = ref(null);
    const parsed = ref(parseOutline(props.source));

    let timer = null;
    let anchors = [];
    let sizes = null;
    let queued = false;

    const diff = computed(() => diffOutline(parsed.value, props.project));
    const hasSource = computed(() => props.source.trim() !== '');
    const hasStructure = computed(() => parsed.value.chapters.length > 0);

    /** The description as paragraphs: the parser keeps blank lines as breaks. */
    const paragraphs = (text) => String(text ?? '').split(/\n\s*\n/).map((p) => p.trim()).filter(Boolean);

    const statusOf = (status) => STATUS[status] ?? STATUS.kept;

    /** The dot beside a page: what it is now, or that it is not yet anything. */
    const dotOf = (page) => {
      if (!page.row) return 'dot--pending';
      if (page.row.status === 'error') return 'dot--error';
      if (!page.row.has_content) return 'dot--pending';
      if (page.row.dirty) return 'dot--dirty';
      return page.row.pushed ? 'dot--pushed' : 'dot--generated';
    };

    /** The one sentence over the list: how big, and what changes. */
    const summary = computed(() => {
      const c = diff.value.counts;
      const parts = [plural(c.chapters, 'chapter'), plural(c.pages, 'page')];
      if (c.newPages || c.newChapters) parts.push(`${c.newPages + c.newChapters} new`);
      if (c.movedPages) parts.push(`${c.movedPages} moved`);
      if (diff.value.removedPages.length || diff.value.removedChapters.length) {
        parts.push(`${diff.value.removedPages.length + diff.value.removedChapters.length} removed`);
      }
      return parts.join(' · ');
    });

    /* -- the scroll link ---------------------------------------------------- */

    const measure = () => {
      anchors = measureAnchors(scroller.value, body.value, Math.max(1, props.source.split('\n').length));
    };
    const scrollToLine = (line) => { if (scroller.value) scroller.value.scrollTop = topFor(anchors, line); };
    const topLine = () => (scroller.value ? lineAt(anchors, scroller.value.scrollTop) : 0);

    const remeasure = () => {
      if (queued) return;
      queued = true;
      requestAnimationFrame(() => {
        queued = false;
        measure();
        emit('rendered');
      });
    };

    /* -- rendering ---------------------------------------------------------- */

    const render = () => {
      parsed.value = parseOutline(props.source);
      nextTick(remeasure);
    };

    watch(() => props.source, () => {
      clearTimeout(timer);
      timer = setTimeout(render, DEBOUNCE);
    });
    // A page written or published elsewhere changes the dots, not the text.
    watch(() => props.project, () => nextTick(remeasure), { deep: true });

    onMounted(() => {
      remeasure();
      sizes = new ResizeObserver(remeasure);
      if (body.value) sizes.observe(body.value);
    });
    onBeforeUnmount(() => {
      clearTimeout(timer);
      sizes?.disconnect();
    });

    expose({ scrollToLine, topLine });

    return {
      scroller, body, parsed, diff, hasSource, hasStructure, paragraphs, statusOf, dotOf, summary, plural,
      onScroll: () => emit('scroll'),
    };
  },
  template: `
    <div class="pane__body view-pad cf-preview outline-preview" ref="scroller" @scroll.passive="onScroll">
      <div v-show="hasStructure || parsed.title" ref="body">
        <div class="outline-stats">
          <span class="badge"><app-icon name="sitemap" :size="10"/> {{ summary }}</span>
          <span v-if="diff.atRisk.length" class="badge badge--danger">
            <app-icon name="alert" :size="10"/> {{ plural(diff.atRisk.length, 'written page') }} would be deleted
          </span>
          <span v-else-if="diff.removedPages.length || diff.removedChapters.length" class="badge badge--warning">
            only empty pages go
          </span>
        </div>

        <header class="outline-book" :data-src-line="parsed.titleLine ?? 0">
          <span class="tile tile--accent"><app-icon name="book" :size="17"/></span>
          <div class="col grow" style="gap:3px;min-width:0">
            <h2 class="outline-book__title">{{ parsed.title || project.book_title || project.name || 'Untitled course' }}</h2>
            <p v-if="!parsed.title" class="t-xs dim">No <code>#</code> line: the book keeps the title it has.</p>
            <p v-else-if="project.name && parsed.title !== project.name" class="t-xs dim">
              The published book's title. In CourseForge the course is called <strong>{{ project.name }}</strong>.
            </p>
            <div v-if="parsed.tags.length" class="row wrap gap-1">
              <span v-for="tag in parsed.tags" :key="tag" class="chip"><app-icon name="tag" :size="10"/>{{ tag }}</span>
            </div>
          </div>
        </header>

        <div v-if="parsed.description" class="outline-desc" :data-src-line="parsed.descriptionLine ?? 0">
          <p v-for="(para, i) in paragraphs(parsed.description)" :key="i">{{ para }}</p>
        </div>

        <ol class="outline-chapters">
          <li v-for="(chapter, ci) in diff.chapters" :key="ci" class="outline-chapter" :class="'is-' + chapter.status"
              :data-src-line="chapter.line">
            <div class="outline-chapter__head">
              <span class="outline-num">{{ ci + 1 }}</span>
              <span class="outline-chapter__title grow truncate">{{ chapter.title }}</span>
              <span v-if="chapter.status === 'new'" class="badge badge--success">new chapter</span>
              <span v-if="chapter.tags.length" class="t-2xs faint none" :title="chapter.tags.join(', ')">
                <app-icon name="tag" :size="10"/> {{ chapter.tags.length }}
              </span>
              <span class="t-2xs faint none nums">{{ plural(chapter.pages.length, 'page') }}</span>
            </div>
            <details v-if="chapter.description" class="outline-chapter__desc">
              <summary>{{ paragraphs(chapter.description)[0].slice(0, 120) }}{{ chapter.description.length > 120 ? '…' : '' }}</summary>
              <p v-for="(para, i) in paragraphs(chapter.description)" :key="i">{{ para }}</p>
            </details>
            <p v-else class="outline-chapter__desc t-xs faint" style="border-bottom:1px solid var(--border-soft)">No description - the chapter goal the pages are written from.</p>
            <ol class="outline-pages">
              <li v-for="(page, pi) in chapter.pages" :key="pi" class="outline-page" :class="'is-' + page.status"
                  :data-src-line="page.line">
                <span class="dot" :class="dotOf(page)" :title="page.row ? (page.row.has_content ? 'written' : 'not written yet') : 'not created yet'"></span>
                <span class="outline-num">{{ pi + 1 }}</span>
                <span class="truncate grow">{{ page.title }}</span>
                <span v-if="statusOf(page.status).label" class="badge" :class="statusOf(page.status).tone">{{ statusOf(page.status).label }}</span>
                <span v-if="page.tags.length" class="t-2xs faint none" :title="page.tags.join(', ')"><app-icon name="tag" :size="10"/> {{ page.tags.length }}</span>
              </li>
              <li v-if="!chapter.pages.length" class="outline-page is-empty t-xs faint">No pages under this chapter yet.</li>
            </ol>
          </li>
        </ol>

        <section v-if="diff.removedPages.length || diff.removedChapters.length" class="outline-removed">
          <div class="row gap-2">
            <app-icon name="circle-minus" :size="15" class="c-danger none"/>
            <span class="strong t-sm">Applying deletes what the outline no longer names</span>
          </div>
          <ul>
            <li v-for="chapter in diff.removedChapters" :key="'c' + chapter.id">
              chapter <strong>{{ chapter.title }}</strong>
            </li>
            <li v-for="page in diff.removedPages" :key="'p' + page.id" :class="page.has_content ? 'c-danger' : ''">
              page <strong>{{ page.title }}</strong>
              <span v-if="page.has_content"> - written, so Apply asks first</span>
              <span v-else class="dim"> - empty, nothing lost</span>
            </li>
          </ul>
          <p class="hint mt-2">A page that was only renamed is a page going and a page arriving: put the old title back to keep its text.</p>
        </section>
      </div>

      <empty-state v-if="!hasStructure && !parsed.title" icon="sitemap"
                   :title="hasSource ? 'Nothing parsed yet' : 'No outline yet'"
                   :hint="hasSource
                     ? 'An outline needs at least one chapter as a numbered list, with its pages as a numbered list indented under it.'
                     : 'Have the AI design one on the right, or write one here: a title line, a description, numbered chapters, and their pages indented below.'"/>
    </div>`,
};

export default OutlinePreview;
