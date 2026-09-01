import {
  ref, reactive, computed, watch, nextTick, defineAsyncComponent,
  onMounted, onBeforeUnmount, onActivated, onDeactivated,
} from 'vue';
import { state, openCourse, allPages, concurrency, mergePage, markPageStatus, refreshProject } from '@/core/store.js';
import { get, put, post } from '@/core/api.js';
import { toast, attempt } from '@/core/toast.js';
import { useFuzzy } from '@/core/fuzzy.js';
import { useScrollSync } from '@/core/scrollsync.js';
import { compactNumber, plural } from '@/core/format.js';
import {
  busy, patchDetails, tagAdd, tagRemove, tagInherit, tagToggle, inheritedTags, saveChapter,
  fixTypography, typographyCount,
} from '@/views/project/actions.js';
import {
  runs, openRuns, doneRuns, cronStalled, loadRuns, stopPolling, resetRuns, pollNow,
  startRun, cancelRun, forgetRun, runTone, runProgress, runWhere, cronProblem,
} from '@/views/project/runs.js';

import AppIcon from '@/components/AppIcon.js';
import AppModal from '@/components/AppModal.js';
import EmptyState from '@/components/EmptyState.js';
import DetailEditor from '@/components/DetailEditor.js';
import TagPicker from '@/components/TagPicker.js';
import MarkdownPreview from '@/components/MarkdownPreview.js';

// CodeMirror is by some distance the heaviest thing the application loads, and
// only this tab has any use for it. Fetching it when the tab first renders
// keeps the sign-in screen exactly as light as it was. Both stand-ins matter:
// an empty half where the editor should be reads as lost work.
const notice = (text, tone = '') => ({
  inheritAttrs: false,          // Vue hands the error component the error itself
  template: `<div class="cf-editor cf-editor--notice ${tone}">${text}</div>`,
});

const MarkdownEditor = defineAsyncComponent({
  loader: () => import('@/components/MarkdownEditor.js'),
  delay: 250,
  loadingComponent: notice('Loading the editor…'),
  errorComponent: notice('The editor could not be loaded. Reload the page to try again.', 'c-danger'),
});

const FILTERS = {
  all: () => true,
  missing: (page) => !page.has_content,
  written: (page) => page.has_content,
  dirty: (page) => page.dirty,
  errors: (page) => page.status === 'error',
};

export const ContentTab = {
  name: 'ContentTab',
  components: { AppIcon, AppModal, EmptyState, DetailEditor, TagPicker, MarkdownEditor, MarkdownPreview },
  setup() {
    const project = openCourse;

    /* -- selection ------------------------------------------------------ */
    const selection = reactive({ type: 'page', id: null });
    const draft = reactive({ title: '', content: '', extra_context: '' });
    const loaded = ref(null);            // the page detail last fetched
    const loadingPage = ref(false);
    const savingPage = ref(false);
    const pageFeedback = ref('');
    const viewMode = ref('split');
    const inspectorTab = ref('details');
    const outlineOpen = ref(false);
    const inspectorOpen = ref(false);
    const collapsed = reactive({});
    const search = ref('');
    const filter = ref('all');
    const confirmDiscard = ref(null);

    const chapterDraft = reactive({ title: '', description: '' });

    const selectedPage = computed(() =>
      selection.type === 'page' ? allPages.value.find((p) => p.id === selection.id) ?? null : null
    );
    const selectedChapter = computed(() => {
      if (selection.type === 'chapter') {
        return project.value.chapters.find((c) => c.id === selection.id) ?? null;
      }
      return selectedPage.value
        ? project.value.chapters.find((c) => c.id === selectedPage.value.chapter_id) ?? null
        : null;
    });

    const dirty = computed(() => {
      if (!selectedPage.value || !loaded.value) return false;
      return draft.title !== loaded.value.title
        || draft.content !== (loaded.value.content ?? '')
        || draft.extra_context !== (loaded.value.extra_context ?? '');
    });

    const chapterDirty = computed(() => {
      const chapter = selection.type === 'chapter' ? selectedChapter.value : null;
      if (!chapter) return false;
      return chapterDraft.title !== chapter.title || chapterDraft.description !== chapter.description;
    });

    /* -- tree ----------------------------------------------------------- */
    const term = computed(() => search.value.trim().toLowerCase());

    /**
     * Fuzzy over the page titles, resolved once per search rather than per
     * page: the tree is rebuilt for every chapter, and running a search inside
     * that loop would run it as many times as there are chapters.
     */
    const allPages = computed(() => project.value.chapters.flatMap((chapter) => chapter.pages));
    const found = useFuzzy(allPages, search, { keys: ['title'] });
    const foundIds = computed(() => new Set(found.value.map((page) => page.id)));

    const matches = (page) => FILTERS[filter.value](page)
      && (term.value === '' || foundIds.value.has(page.id));

    const tree = computed(() => project.value.chapters
      .map((chapter) => ({ ...chapter, visible: chapter.pages.filter(matches) }))
      .filter((chapter) => chapter.visible.length > 0 || (term.value === '' && filter.value === 'all')));

    const filtering = computed(() => term.value !== '' || filter.value !== 'all');
    const isCollapsed = (id) => collapsed[id] === true;
    const toggleChapter = (id) => { collapsed[id] = !isCollapsed(id); };
    const collapseAll = () => {
      const anyOpen = project.value.chapters.some((c) => !isCollapsed(c.id));
      project.value.chapters.forEach((c) => { collapsed[c.id] = anyOpen; });
    };

    const pageDotClass = (page) => {
      if (page.status === 'error') return 'dot--error';
      if (page.status === 'generating') return 'dot--generating';
      if (page.status === 'queued') return 'dot--queued';
      if (!page.has_content) return 'dot--pending';
      if (page.dirty) return 'dot--dirty';
      return page.pushed ? 'dot--pushed' : 'dot--generated';
    };

    /* -- loading a page ------------------------------------------------- */

    /**
     * Two clicks in the outline start two requests, and the network decides
     * which answers first. Without the guard below, page A arriving after
     * page B has been selected wrote A's title and text into the editor while
     * the tree still highlighted B — and a save from that state sent A's text
     * to B. The larger page is the slower one, so this got likelier the more
     * there was to lose.
     *
     * The check is the one generateOne() already makes for the same reason:
     * before writing anything, ask whether this answer is still the one being
     * waited for. mergePage stays outside it, because fresher data about a page
     * is worth keeping in the tree whichever page is now open.
     */
    async function loadPage(pageId) {
      loadingPage.value = true;
      try {
        const data = await get(`projects/${project.value.id}/pages/${pageId}`);
        mergePage(data.page);

        if (selection.type !== 'page' || selection.id !== pageId) return;

        loaded.value = data.page;
        draft.title = data.page.title;
        draft.content = data.page.content ?? '';
        draft.extra_context = data.page.extra_context ?? '';
      } finally {
        // Only the request still being waited for owns the spinner: an
        // overtaken one turning it off would clear it while its replacement
        // is still in flight.
        if (selection.type === 'page' && selection.id === pageId) loadingPage.value = false;
      }
    }

    const selectPage = (page) => {
      if (dirty.value) { confirmDiscard.value = { type: 'page', id: page.id }; return; }
      selection.type = 'page';
      selection.id = page.id;
      pageFeedback.value = '';
      outlineOpen.value = false;
      attempt(() => loadPage(page.id), 'Load page');
    };

    const selectChapter = (chapter) => {
      if (dirty.value) { confirmDiscard.value = { type: 'chapter', id: chapter.id }; return; }
      selection.type = 'chapter';
      selection.id = chapter.id;
      chapterDraft.title = chapter.title;
      chapterDraft.description = chapter.description;
      outlineOpen.value = false;
    };

    const discardAndGo = () => {
      const target = confirmDiscard.value;
      confirmDiscard.value = null;
      if (!target) return;
      loaded.value = null;                 // drops the dirty comparison
      if (target.type === 'page') {
        const page = allPages.value.find((p) => p.id === target.id);
        if (page) selectPage(page);
      } else {
        const chapter = project.value.chapters.find((c) => c.id === target.id);
        if (chapter) selectChapter(chapter);
      }
    };

    // Open the first page automatically so the tab is never blank.
    watch(() => project.value?.id, (id, previous) => {
      if (previous !== undefined && id !== previous) {
        // Another course: its batches are not this course's.
        resetBatches();
        attempt(loadRuns, 'Load runs');
      }
      const first = allPages.value[0];
      if (first && selection.id === null) selectPage(first);
    }, { immediate: true });

    /* -- saving --------------------------------------------------------- */
    const savePage = () => attempt(async () => {
      if (!selectedPage.value || !dirty.value) return;
      savingPage.value = true;
      const renamed = draft.title !== loaded.value.title;
      try {
        const data = await put(`projects/${project.value.id}/pages/${selectedPage.value.id}`, {
          title: draft.title,
          content: draft.content,
          extra_context: draft.extra_context,
        });
        loaded.value = data.page;
        mergePage(data.page);
        // A rename rewrites structure_md server side; pull the whole tree back.
        if (renamed) await refreshProject();
        toast.success('Page saved.');
      } finally {
        savingPage.value = false;
      }
    }, 'Save page');

    const saveChapterEdits = async () => {
      const chapter = selectedChapter.value;
      if (!chapter || !chapterDirty.value) return;
      await saveChapter(chapter.id, { title: chapterDraft.title, description: chapterDraft.description });
    };

    /* -- generation ------------------------------------------------------ */
    const gen = reactive({ running: false, stop: false, done: 0, total: 0, errors: 0, mode: 'missing', limit: 5 });
    const confirmRegenerateAll = ref(false);

    async function generateOne(pageId, { extra, feedback } = {}) {
      markPageStatus(pageId, 'generating');
      const body = {};
      if (extra !== undefined) body.extra_context = extra;
      if (feedback) body.feedback = feedback;

      const data = await post(`projects/${project.value.id}/pages/${pageId}/generate`, body);
      mergePage(data.page);
      if (selection.type === 'page' && selection.id === pageId) {
        loaded.value = data.page;
        draft.title = data.page.title;
        draft.content = data.page.content ?? '';
        draft.extra_context = data.page.extra_context ?? '';
      }
      return data.page;
    }

    const regeneratePage = () => attempt(async () => {
      if (!selectedPage.value) return;
      if (selectedPage.value.status === 'queued') {
        toast.error('This page is waiting in a batch. Cancel the batch first, or wait for it to arrive.');
        return;
      }
      const pageId = selectedPage.value.id;
      try {
        await generateOne(pageId, {
          extra: draft.extra_context,
          feedback: pageFeedback.value.trim(),
        });
      } catch (error) {
        // `generateOne` marks the page as generating before the request leaves,
        // and clears the old error with it. A failure has to put both back or
        // the tree keeps an amber dot, and the page a clean slate, for work that
        // is not happening - the repair `runQueue` makes in its own catch.
        markPageStatus(pageId, 'error');
        const page = allPages.value.find((p) => p.id === pageId);
        if (page) page.error = error.message;
        throw error;
      }
      pageFeedback.value = '';
      toast.success('Page written.');
    }, 'Generate page');

    // A page waiting in a batch is excluded from every live selection. Writing
    // it now would race the answer that is already on its way: whichever landed
    // second would win, and the user asked for neither outcome.
    const queue = () => {
      const pages = allPages.value.filter((p) => p.status !== 'queued');
      if (gen.mode === 'all') return pages.map((p) => p.id);
      const missing = pages.filter((p) => !p.has_content);
      if (gen.mode === 'limited') return missing.slice(0, Math.max(1, gen.limit)).map((p) => p.id);
      if (gen.mode === 'errors') return pages.filter((p) => p.status === 'error').map((p) => p.id);
      return missing.map((p) => p.id);
    };

    async function runQueue(ids) {
      gen.running = true;
      state.generating = true;
      gen.stop = false;
      gen.done = 0;
      gen.errors = 0;
      gen.total = ids.length;

      const pending = [...ids];
      const worker = async () => {
        while (pending.length && !gen.stop) {
          const id = pending.shift();
          try {
            await generateOne(id);
          } catch (error) {
            gen.errors += 1;
            markPageStatus(id, 'error');
            const page = allPages.value.find((p) => p.id === id);
            if (page) page.error = error.message;
            toast.error(`Page failed: ${error.message}`);
          }
          gen.done += 1;
        }
      };

      await Promise.all(Array.from({ length: concurrency.value }, worker));
      gen.running = false;
      state.generating = false;
      await attempt(refreshProject, 'Reload');

      const written = gen.done - gen.errors;
      if (gen.stop) toast.info(`Generation stopped after ${gen.done} of ${gen.total} page(s).`);
      else if (gen.errors) toast.error(`Finished with ${plural(gen.errors, 'error')}. ${plural(written, 'page')} written.`);
      else toast.success(`${plural(written, 'page')} written.`);
    }

    const startGeneration = () => {
      const ids = queue();
      if (!ids.length) { toast.info('Nothing to generate with this selection.'); return; }
      if (gen.mode === 'all') { confirmRegenerateAll.value = ids; return; }
      runQueue(ids);
    };

    const stopGeneration = () => {
      gen.stop = true;
      toast.info('Stopping once the running requests come back…');
    };

    /**
     * Hands the current selection to the server instead of writing it here.
     *
     * "The next N pages" has no server-side equivalent, so that one is sent as
     * an explicit list of ids; the other three are named selections the server
     * resolves itself, which keeps them correct even if the tree moved on.
     */
    const sendSelection = (mode = '') => {
      if (gen.mode === 'limited') {
        const ids = queue();
        if (!ids.length) { toast.info('Nothing to start with this selection.'); return; }
        startRun(ids, mode);
        return;
      }
      startRun(gen.mode, mode);
    };

    // Nobody writes a background run except the scheduler, so a scheduler that
    // is not running turns "Run in the background" into a queue that never
    // moves. Ask first, and offer the tab as the way out - the pages can be
    // written here without any scheduler at all.
    const confirmBackground = ref(null);

    const startSelection = (mode = '') => {
      if (mode === 'live' && cronStalled.value) { confirmBackground.value = mode; return; }
      sendSelection(mode);
    };

    const backgroundAnyway = () => {
      const mode = confirmBackground.value;
      confirmBackground.value = null;
      sendSelection(mode);
    };

    const writeHereInstead = () => {
      confirmBackground.value = null;
      startGeneration();
    };

    /* -- details and tags for the selected level ------------------------ */
    const detailTarget = computed(() => (selection.type === 'chapter' ? 'chapter' : 'page'));
    const detailEntity = computed(() => (selection.type === 'chapter' ? selectedChapter.value : selectedPage.value));

    const onDetailChange = (patch) => {
      if (!detailEntity.value) return;
      patchDetails(detailTarget.value, detailEntity.value.id, patch);
    };

    /* -- editor conveniences -------------------------------------------- */

    // A different page means a fresh editor state rather than a replaced
    // document, so undo can never reach back into the page before it. `loaded`
    // is what to key that on: it changes in the same breath as the draft, which
    // `selection` does not — that moves as soon as a page is clicked, a fetch
    // before the text arrives.
    const editorKey = computed(() => loaded.value?.id ?? 0);

    const editor = ref(null);
    const previewPane = ref(null);
    const sync = useScrollSync(editor, previewPane);

    // Leaving and re-entering the split view means the halves have to find each
    // other again. The one that was on screen alone keeps its position and the
    // one being rebuilt starts at the top, so the survivor has to lead — reading
    // the preview and switching back must not scroll the editor to the top.
    watch(viewMode, (mode, previous) => {
      if (mode !== 'split') return;
      sync.claim(previous === 'preview' ? 'preview' : 'editor');
      nextTick(sync.realign);
    });

    const wordCount = computed(() => (draft.content.match(/[\p{L}\p{N}][\p{L}\p{N}'’-]*/gu) ?? []).length);
    const pushScope = (scope, targetId, label) => attempt(async () => {
      const data = await post(`projects/${project.value.id}/push`, { scope, target_id: targetId });
      state.project = data.project;
      toast.success(`${label} published.`);
    }, 'Publish');

    const pushPage = () => pushScope('page', selectedPage.value.id, 'Page');
    const pushChapter = () => pushScope('chapter', selectedChapter.value.id, 'Chapter');

    /* Correcting the punctuation rewrites the stored text, which is the one
       thing the editor is holding its own copy of. Unsaved edits are refused
       rather than reconciled: the request would win and the sentence being
       typed would not, and there is no version of that the author asked for.
       Afterwards the copy is re-read, because what is on disk has moved. */
    const typeset = (target) => attempt(async () => {
      const page = target === 'page';
      if (page ? dirty.value : chapterDirty.value) {
        toast.error('Save your changes first - correcting the punctuation rewrites what is stored.');
        return;
      }
      const id = page ? selectedPage.value?.id : selectedChapter.value?.id;
      if (!id) return;

      const result = await fixTypography(target, id);
      if (!result) return;

      if (page) await loadPage(id);
      else if (selectedChapter.value) {
        chapterDraft.title = selectedChapter.value.title;
        chapterDraft.description = selectedChapter.value.description;
      }

      toast.success(result.total
        ? `Punctuation corrected in ${typographyCount(result)}.`
        : 'Nothing needed correcting.');
    }, 'Correct punctuation');

    const typesetPage = () => typeset('page');
    const typesetChapter = () => typeset('chapter');

    const onKey = (event) => {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
        event.preventDefault();
        if (dirty.value) savePage();
        else if (chapterDirty.value) saveChapterEdits();
      }
    };
    const onLeave = (event) => {
      if (dirty.value || chapterDirty.value) { event.preventDefault(); event.returnValue = ''; }
    };

    // Ctrl+S only belongs to this tab, so it follows the tab in and out;
    // the unload warning stays as long as the workspace is open.
    onMounted(() => {
      window.addEventListener('keydown', onKey);
      window.addEventListener('beforeunload', onLeave);
      // Any batch queued in an earlier session is still out there; asking once
      // on arrival is what makes it reappear instead of looking lost.
      attempt(loadRuns, 'Load runs');
    });
    onActivated(() => window.addEventListener('keydown', onKey));
    onDeactivated(() => window.removeEventListener('keydown', onKey));
    onBeforeUnmount(() => {
      window.removeEventListener('keydown', onKey);
      window.removeEventListener('beforeunload', onLeave);
      state.generating = false;
      stopPolling();
    });

    return {
      state, project, allPages, busy, selection, selectedPage, selectedChapter, draft, chapterDraft,
      dirty, chapterDirty, loadingPage, savingPage, pageFeedback, viewMode, inspectorTab,
      outlineOpen, inspectorOpen, search, filter, tree, filtering, isCollapsed, toggleChapter,
      collapseAll, pageDotClass, selectPage, selectChapter, savePage, saveChapterEdits,
      gen, startGeneration, stopGeneration, regeneratePage, confirmRegenerateAll, runQueue,
      runs, openRuns, doneRuns, cronStalled, pollNow, cancelRun, forgetRun,
      runTone, runProgress, runWhere, cronProblem, startSelection,
      confirmBackground, backgroundAnyway, writeHereInstead,
      onDetailChange, detailTarget, detailEntity, tagAdd, tagRemove, tagInherit, tagToggle, inheritedTags,
      wordCount, pushPage, pushChapter, typesetPage, typesetChapter, confirmDiscard, discardAndGo,
      concurrency, compactNumber,
      editor, previewPane, editorKey,
      syncEnabled: sync.enabled, toggleSync: sync.toggle, claim: sync.claim,
      fromEditor: sync.fromEditor, fromPreview: sync.fromPreview, realign: sync.realign,
    };
  },
  template: `
    <div class="workspace">
      <div v-if="outlineOpen || inspectorOpen" class="scrim"
           @click="outlineOpen = false; inspectorOpen = false"></div>

      <!-- ============================================== outline ========= -->
      <aside class="pane pane--left" :class="{ 'is-open': outlineOpen }">
        <div class="pane__head">
          <div class="grow" style="position:relative">
            <app-icon name="search" :size="13"
                      style="position:absolute;left:8px;top:50%;transform:translateY(-50%);color:var(--text-faint)"/>
            <input v-model="search" placeholder="Filter pages…" spellcheck="false"
                   style="padding-left:26px;font-size:var(--t-xs)">
          </div>
          <button class="btn btn--ghost btn--sm btn--icon none" title="Collapse or expand all chapters"
                  @click="collapseAll"><app-icon name="chevrons-left" :size="14"/></button>
          <button class="btn btn--ghost btn--sm btn--icon none menu-toggle" title="Close"
                  @click="outlineOpen = false"><app-icon name="x" :size="14"/></button>
        </div>

        <div class="pane__head" style="border-bottom:1px solid var(--border-soft)">
          <select v-model="filter" style="font-size:var(--t-xs)">
            <option value="all">All pages</option>
            <option value="missing">Not written yet</option>
            <option value="written">Written</option>
            <option value="dirty">Changed since publish</option>
            <option value="errors">Failed</option>
          </select>
        </div>

        <div class="pane__body">
          <div class="tree">
            <div v-for="chapter in tree" :key="chapter.id" class="tree__chapter">
              <div class="row" style="gap:0">
                <button class="btn btn--ghost btn--sm btn--icon none" style="padding:2px"
                        :title="isCollapsed(chapter.id) ? 'Expand' : 'Collapse'"
                        @click="toggleChapter(chapter.id)">
                  <app-icon :name="isCollapsed(chapter.id) ? 'chevron-right' : 'chevron-down'" :size="12"/>
                </button>
                <button class="tree__chapter-head grow"
                        :class="{ 'is-active': selection.type === 'chapter' && selection.id === chapter.id }"
                        @click="selectChapter(chapter)">
                  <span class="tree__chapter-title grow">{{ chapter.idx + 1 }}. {{ chapter.title }}</span>
                  <span v-if="chapter.dirty" class="dot dot--dirty" title="Changed since the last publish"></span>
                  <span v-if="chapter.effective_tags.length" class="t-2xs faint nums"
                        :title="chapter.effective_tags.map(t => t.name).join(', ')">{{ chapter.effective_tags.length }}</span>
                </button>
              </div>

              <div v-if="!isCollapsed(chapter.id) || filtering">
                <button v-for="page in chapter.visible" :key="page.id" class="tree__page"
                        :class="{ 'is-active': selection.type === 'page' && selection.id === page.id }"
                        @click="selectPage(page)" :title="page.title">
                  <span class="dot" :class="pageDotClass(page)"></span>
                  <span class="tree__page-idx">{{ page.idx + 1 }}</span>
                  <span class="truncate grow">{{ page.title }}</span>
                  <app-icon v-if="page.link_markers" name="link" :size="11" class="faint none"
                            :title="page.link_markers + ' cross reference(s)'"/>
                  <app-icon v-if="page.details.effective.features.anki" name="layers" :size="11" class="faint none"
                            title="Anki cards on"/>
                </button>
                <p v-if="!chapter.visible.length" class="t-2xs faint" style="padding:4px 10px">no matching page</p>
              </div>
            </div>

            <empty-state v-if="!tree.length" icon="sitemap" title="Nothing here"
                         :hint="filtering ? 'No page matches this filter.' : 'Create a structure first.'"/>
          </div>
        </div>

        <!-- generation controls -->
        <div class="pane__foot col gap-2">
          <div class="row gap-2">
            <select v-model="gen.mode" class="grow" style="font-size:var(--t-xs)" :disabled="gen.running">
              <option value="missing">Write all missing pages</option>
              <option value="limited">Write the next N pages</option>
              <option value="errors">Retry the failed pages</option>
              <option value="all">Rewrite everything</option>
            </select>
            <input v-if="gen.mode === 'limited'" v-model.number="gen.limit" type="number" min="1"
                   style="width:60px;flex:none" :disabled="gen.running">
          </div>

          <!-- Three ways to write the same pages, and the headline is whichever
               the course is actually set up for. Writing them in the tab stays
               one click away: it is still the right thing for three pages you
               are watching. -->
          <template v-if="!gen.running">
            <button v-if="runs.capability.batch_available" class="btn btn--primary btn--block"
                    :disabled="runs.busy" @click="startSelection()">
              <app-icon :name="runs.busy ? 'refresh' : 'layers'" :size="14" :spin="runs.busy"/>
              Queue as a batch · half price
            </button>
            <button v-else-if="runs.capability.background_available" class="btn btn--primary btn--block"
                    :disabled="runs.busy" @click="startSelection('live')">
              <app-icon :name="runs.busy ? 'refresh' : 'layers'" :size="14" :spin="runs.busy"/>
              Run in the background
            </button>
            <button v-else class="btn btn--primary btn--block" @click="startGeneration">
              <app-icon name="play" :size="14"/> Generate · {{ concurrency }} at a time
            </button>

            <button v-if="runs.capability.batch_available || runs.capability.background_available"
                    class="btn btn--ghost btn--sm btn--block" @click="startGeneration">
              or write them now, in this tab
            </button>
          </template>
          <button v-else class="btn btn--block" :disabled="gen.stop" @click="stopGeneration">
            <app-icon name="pause" :size="14"/> {{ gen.stop ? 'Finishing…' : 'Stop' }}
          </button>

          <p v-if="runs.capability.reason" class="t-2xs c-warning">{{ runs.capability.reason }}</p>

          <!-- What the provider cannot do and what the scheduler is not doing
               are two separate problems, and the second one is at its most
               likely exactly when the first one has pushed the user onto the
               background path - so this chain starts again rather than
               continuing the one above, which used to hide it. -->
          <p v-if="!runs.capability.batch_available && !runs.capability.background_available"
             class="t-2xs faint">
            Set up the scheduler to run these in the background and close the tab.
          </p>
          <p v-else-if="cronStalled" class="t-2xs c-danger">{{ cronProblem() }}</p>

          <div v-if="gen.running || gen.done">
            <div class="bar"><div class="bar__fill"
                 :style="{ width: (gen.total ? (gen.done / gen.total * 100) : 0) + '%' }"></div></div>
            <p class="t-2xs dim mt-1 nums">
              {{ gen.done }}/{{ gen.total }} done<span v-if="gen.errors" class="c-danger"> · {{ gen.errors }} failed</span>
            </p>
          </div>

          <!-- One card per run. These outlive the tab, so a course reopened
               tomorrow finds its run still here and still counting. -->
          <div v-for="job in openRuns" :key="job.id" class="card card--flat card--pad col gap-1">
            <div class="row gap-2">
              <span class="badge" :class="runTone(job)">{{ job.remote_state || job.status }}</span>
              <span class="t-2xs dim">{{ runWhere(job) }}</span>
              <span class="t-2xs dim truncate grow" :title="job.model">{{ job.model }}</span>
              <button class="btn btn--ghost btn--sm btn--icon none" title="Check now"
                      :disabled="runs.busy" @click="pollNow">
                <app-icon name="refresh" :size="12" :spin="runs.busy"/>
              </button>
            </div>
            <div class="bar"><div class="bar__fill"
                 :style="{ width: (job.pages.total ? ((job.pages.written + job.pages.failed + job.pages.skipped) / job.pages.total * 100) : 0) + '%' }"></div></div>
            <p class="t-2xs dim nums">{{ runProgress(job) }}</p>
            <p v-if="job.error" class="t-2xs c-warning">{{ job.error }}</p>
            <button class="btn btn--ghost btn--sm" :disabled="runs.busy" @click="cancelRun(job.id)">
              <app-icon name="x" :size="12"/> Stop this run
            </button>
          </div>

          <div v-for="job in doneRuns" :key="job.id" class="row gap-2 t-2xs dim">
            <span class="badge" :class="runTone(job)">{{ job.status }}</span>
            <span class="grow truncate">{{ runProgress(job) }}</span>
            <button class="btn btn--ghost btn--sm btn--icon none" title="Remove this record"
                    @click="forgetRun(job.id)"><app-icon name="x" :size="11"/></button>
          </div>
        </div>
      </aside>

      <!-- ============================================== editor ========== -->
      <section class="pane">
        <!-- page editor -->
        <template v-if="selection.type === 'page' && selectedPage">
          <div class="pane__head">
            <button class="btn btn--ghost btn--sm btn--icon none outline-toggle" title="Show the outline"
                    @click="outlineOpen = true"><app-icon name="menu" :size="15"/></button>

            <input v-model="draft.title" class="grow" style="max-width:520px" placeholder="Page title">
            <span v-if="dirty" class="badge badge--warning none">unsaved</span>
            <span v-if="loadingPage" class="badge none">loading…</span>

            <div class="row gap-2 push none">
              <div class="btn-group hide-sm">
                <button v-for="mode in ['edit','split','preview']" :key="mode"
                        :class="{ 'is-active': viewMode === mode }" @click="viewMode = mode">{{ mode }}</button>
              </div>
              <button v-if="viewMode === 'split'" class="btn btn--ghost btn--sm btn--icon cf-sync hide-sm"
                      :class="{ 'is-active': syncEnabled }" :aria-pressed="String(syncEnabled)"
                      :title="syncEnabled ? 'Scrolling is linked — click to unlink' : 'Scrolling is independent — click to link'"
                      @click="toggleSync">
                <app-icon :name="syncEnabled ? 'arrow-down-up' : 'arrow-down-up-off'" :size="14"/>
              </button>
              <button class="btn btn--success btn--sm" :disabled="!dirty || savingPage" @click="savePage"
                      title="Ctrl+S">
                <app-icon name="save" :size="13"/> Save
              </button>
              <button class="btn btn--ghost btn--sm btn--icon drawer-toggle" title="Show the inspector"
                      @click="inspectorOpen = true"><app-icon name="panel-right" :size="15"/></button>
            </div>
          </div>

          <div v-if="selectedPage.error" class="pane__head" style="background:var(--danger-soft);border:0">
            <app-icon name="alert-circle" :size="14" class="c-danger none"/>
            <span class="t-xs c-danger truncate">{{ selectedPage.error }}</span>
          </div>

          <div class="split" :class="{ 'split--both': viewMode === 'split' }">
            <div v-if="viewMode !== 'preview'" class="split__half"
                 @pointerdown="claim('editor')" @wheel.passive="claim('editor')"
                 @focusin="claim('editor')" @keydown="claim('editor')">
              <markdown-editor ref="editor" v-model="draft.content" :reset-key="editorKey"
                               placeholder="Nothing written yet. Type the page here, or open the context tab on the right and press Write this page."
                               @scroll="fromEditor"/>
            </div>
            <div v-if="viewMode !== 'edit'" class="split__half"
                 @pointerdown="claim('preview')" @wheel.passive="claim('preview')">
              <!-- Keyed on the page for the same reason the editor is: a new
                   page must start at the top, not wherever the last one was. -->
              <markdown-preview ref="previewPane" :key="editorKey" :source="draft.content"
                                @scroll="fromPreview" @rendered="realign"/>
            </div>
          </div>

          <div class="pane__foot row wrap gap-3 t-2xs dim">
            <span class="nums">{{ compactNumber(wordCount) }} words</span>
            <span v-if="selectedPage.link_markers" class="nums">
              <app-icon name="link" :size="11"/> {{ selectedPage.link_markers }} cross reference(s)
            </span>
            <span v-if="selectedPage.pushed" class="push">
              <a v-if="selectedPage.bs_url" :href="selectedPage.bs_url" target="_blank" rel="noopener">
                open in BookStack <app-icon name="external" :size="10"/>
              </a>
              <span v-else>published</span>
            </span>
          </div>
        </template>

        <!-- chapter editor -->
        <template v-else-if="selection.type === 'chapter' && selectedChapter">
          <div class="pane__head">
            <button class="btn btn--ghost btn--sm btn--icon none outline-toggle" title="Show the outline"
                    @click="outlineOpen = true"><app-icon name="menu" :size="15"/></button>
            <span class="eyebrow grow">Chapter {{ selectedChapter.idx + 1 }}</span>
            <span v-if="chapterDirty" class="badge badge--warning">unsaved</span>
            <button class="btn btn--success btn--sm" :disabled="!chapterDirty || busy" @click="saveChapterEdits">
              <app-icon name="save" :size="13"/> Save
            </button>
            <button class="btn btn--sm" :disabled="busy || chapterDirty" @click="typesetChapter"
                    title="Set the punctuation of this chapter and its pages the way the course language does">
              <app-icon name="quote" :size="13"/> Punctuation
            </button>
            <button class="btn btn--sm" :disabled="busy" @click="pushChapter"
                    title="Publish this chapter and its pages to BookStack">
              <app-icon name="upload" :size="13"/> Publish chapter
            </button>
            <button class="btn btn--ghost btn--sm btn--icon drawer-toggle" title="Show the inspector"
                    @click="inspectorOpen = true"><app-icon name="panel-right" :size="15"/></button>
          </div>

          <div class="pane__body view-pad container-narrow col gap-4">
            <div class="form-row">
              <label>Chapter title</label>
              <input v-model="chapterDraft.title">
              <p class="hint">Renaming rewrites the outline. Page content stays attached to its own title.</p>
            </div>
            <div class="form-row">
              <label>Chapter goal</label>
              <textarea v-model="chapterDraft.description" rows="4"></textarea>
              <p class="hint">Sent to the AI with every page of this chapter, and published as the chapter description.</p>
            </div>

            <div class="card">
              <div class="card__head"><span class="card__title grow">Pages</span>
                <span class="badge">{{ selectedChapter.pages.length }}</span></div>
              <div class="card__body col" style="gap:2px">
                <button v-for="page in selectedChapter.pages" :key="page.id" class="tree__page"
                        @click="selectPage(page)">
                  <span class="dot" :class="pageDotClass(page)"></span>
                  <span class="tree__page-idx">{{ page.idx + 1 }}</span>
                  <span class="truncate grow">{{ page.title }}</span>
                  <span class="t-2xs faint nums">{{ compactNumber(page.word_count) }}w</span>
                </button>
              </div>
            </div>
          </div>
        </template>

        <!-- Nothing selected. This branch owns the only way back to the outline
             below 1024px, where the outline is a drawer rather than a column:
             without a head of its own it used to be a dead end, and with it the
             page list, the filter and the generation controls were all out of
             reach on a phone. -->
        <template v-else>
          <div class="pane__head">
            <button class="btn btn--ghost btn--sm btn--icon none outline-toggle" title="Show the outline"
                    @click="outlineOpen = true"><app-icon name="menu" :size="15"/></button>
            <span class="eyebrow grow">Content</span>
          </div>

          <div class="pane__body">
            <empty-state v-if="allPages.length" icon="file-text" title="Nothing selected"
                         hint="Pick a page or a chapter in the outline to edit it, give it context, or have it written.">
              <button class="btn outline-toggle" @click="outlineOpen = true">
                <app-icon name="menu" :size="14"/> Open the outline
              </button>
            </empty-state>

            <!-- A course with no pages at all has nothing to pick, so pointing
                 at the outline would be pointing at an empty list. -->
            <empty-state v-else icon="sitemap" title="This course has no pages yet"
                         hint="The outline decides which pages exist. Write one on the Structure tab, or have CourseForge design it, then come back here to fill the pages in.">
              <div class="row wrap gap-2" style="justify-content:center">
                <button class="btn btn--primary" @click="state.projectTab = 'structure'">
                  <app-icon name="sitemap" :size="14"/> Go to Structure
                </button>
                <button class="btn outline-toggle" @click="outlineOpen = true">
                  <app-icon name="menu" :size="14"/> Open the outline
                </button>
              </div>
            </empty-state>
          </div>
        </template>
      </section>

      <!-- ============================================== inspector ======= -->
      <aside class="pane pane--right" :class="{ 'is-open': inspectorOpen }">
        <div class="pane__head">
          <div class="btn-group grow">
            <button v-for="tab in ['details','tags','context']" :key="tab"
                    :class="{ 'is-active': inspectorTab === tab }" @click="inspectorTab = tab"
                    style="flex:1">{{ tab }}</button>
          </div>
          <button class="btn btn--ghost btn--sm btn--icon none drawer-toggle" title="Close"
                  @click="inspectorOpen = false"><app-icon name="x" :size="14"/></button>
        </div>

        <div class="pane__body view-pad">
          <template v-if="detailEntity">
            <!-- details -->
            <div v-if="inspectorTab === 'details'">
              <p class="hint mb-3">
                Applies to
                <strong v-if="detailTarget === 'page'">this page only</strong>
                <strong v-else>this chapter and all its pages</strong>.
              </p>
              <detail-editor :level="detailTarget" :details="detailEntity.details" :busy="busy"
                             columns="one" @change="onDetailChange"/>
            </div>

            <!-- tags -->
            <div v-else-if="inspectorTab === 'tags'" class="col gap-6">
              <tag-picker v-if="selection.type === 'page'" label="Page tags"
                          :tags="selectedPage.tags" :inherited="inheritedTags(selectedPage)"
                          :can-inherit="false" :busy="busy"
                          @add="tagAdd('page', selectedPage.id, $event)"
                          @remove="tagRemove('page', selectedPage.id, $event)"
                          @toggle="tagToggle('page', selectedPage.id, $event)"/>

              <tag-picker v-if="selectedChapter"
                          :label="'Chapter tags — ' + selectedChapter.title"
                          :tags="selectedChapter.tags" :inherited="inheritedTags(selectedChapter)" :busy="busy"
                          @add="tagAdd('chapter', selectedChapter.id, $event)"
                          @remove="tagRemove('chapter', selectedChapter.id, $event)"
                          @inherit="tagInherit('chapter', selectedChapter.id, $event)"
                          @toggle="tagToggle('chapter', selectedChapter.id, $event)"/>

              <tag-picker label="Course tags" :tags="project.tags" :inherited="[]" :busy="busy"
                          @add="tagAdd('course', null, $event)"
                          @remove="tagRemove('course', null, $event)"
                          @inherit="tagInherit('course', null, $event)"
                          @toggle="tagToggle('course', null, $event)"/>
            </div>

            <!-- context and regeneration -->
            <div v-else class="col gap-5">
              <template v-if="selection.type === 'page'">
                <div class="form-row">
                  <label>Extra context for this page</label>
                  <textarea v-model="draft.extra_context" rows="6" class="mono" spellcheck="false"
                            placeholder="Facts, snippets or requirements the AI must respect on this page."></textarea>
                  <p class="hint">Outranks the model's own assumptions. Saved with the page.</p>
                </div>

                <div class="form-row">
                  <label>Feedback for a rewrite</label>
                  <textarea v-model="pageFeedback" rows="4"
                            placeholder="More examples, shorter intro, focus on PhpStorm…"></textarea>
                  <button class="btn btn--primary btn--block mt-2" :disabled="gen.running" @click="regeneratePage">
                    <app-icon name="sparkles" :size="14"/>
                    {{ selectedPage.has_content ? 'Rewrite this page' : 'Write this page' }}
                  </button>
                  <p class="hint">Leaving the box empty writes the page from scratch.</p>
                </div>

                <div class="divider"></div>

                <div class="col gap-2">
                  <span class="eyebrow">Punctuation</span>
                  <button class="btn btn--block" :disabled="busy || dirty || !selectedPage.has_content"
                          @click="typesetPage">
                    <app-icon name="quote" :size="14"/> Correct this page
                  </button>
                  <p class="hint">
                    Quotation marks, apostrophes, ellipses, dashes and spacing, set the way the course
                    language sets them. Code, links and formulas are never touched, and running it twice
                    changes nothing.
                  </p>
                </div>

                <div class="divider"></div>

                <div class="col gap-2">
                  <span class="eyebrow">Publish</span>
                  <button class="btn btn--block" :disabled="!selectedPage.has_content" @click="pushPage">
                    <app-icon name="upload" :size="14"/> Publish just this page
                  </button>
                  <p class="hint">
                    Cross references are turned into real links during a full publish, once every target has a URL.
                  </p>
                </div>
              </template>

              <empty-state v-else icon="info" title="Chapter selected"
                           hint="Extra context and rewrites are page-level. Pick a page to use them."/>
            </div>
          </template>

          <empty-state v-else icon="sliders" title="Nothing selected"
                       hint="Details, tags and context appear once you pick a page or a chapter."/>
        </div>
      </aside>
    </div>

    <app-modal v-if="confirmDiscard" title="Discard unsaved changes?" icon="alert" @close="confirmDiscard = null">
      <p class="t-sm">The current page has edits that were never saved.</p>
      <template #footer>
        <button class="btn" @click="confirmDiscard = null">Keep editing</button>
        <button class="btn btn--danger" @click="discardAndGo">Discard and switch</button>
      </template>
    </app-modal>

    <app-modal v-if="confirmBackground" title="Nothing is collecting background runs" icon="alert"
               @close="confirmBackground = null">
      <p class="t-sm">{{ cronProblem() }}</p>
      <p class="hint mt-2">
        A background run is written down here and picked up by the scheduler, so this one would be queued
        and then wait. Writing the pages in this tab needs no scheduler at all — it keeps the tab busy
        until they are done.
      </p>
      <template #footer>
        <button class="btn" @click="confirmBackground = null">Cancel</button>
        <button class="btn" @click="backgroundAnyway">Queue it anyway</button>
        <button class="btn btn--primary" @click="writeHereInstead">Write them here instead</button>
      </template>
    </app-modal>

    <app-modal v-if="confirmRegenerateAll" title="Rewrite every page?" icon="alert"
               @close="confirmRegenerateAll = null">
      <p class="t-sm">
        All {{ confirmRegenerateAll.length }} pages will be written again and their current content replaced.
      </p>
      <template #footer>
        <button class="btn" @click="confirmRegenerateAll = null">Cancel</button>
        <button class="btn btn--danger"
                @click="runQueue(confirmRegenerateAll); confirmRegenerateAll = null">
          Rewrite everything
        </button>
      </template>
    </app-modal>`,
};

export default ContentTab;
