import { computed, defineAsyncComponent, nextTick, ref, watch } from 'vue';
import { state, openCourse, featureByKey, paramByKey, declareUnsaved } from '@/core/store.js';
import { busy, patchDetails, saveResearch } from '@/views/project/actions.js';
import { plural } from '@/core/format.js';
import { useScrollSync } from '@/core/scrollsync.js';

import AppIcon from '@/components/AppIcon.js';
import EmptyState from '@/components/EmptyState.js';
import DetailEditor from '@/components/DetailEditor.js';
import MarkdownPreview from '@/components/MarkdownPreview.js';

/**
 * The briefing is Markdown - headings, bullets and a list of sources - and it is
 * read and edited here as often as it is written by a client. A textarea showed
 * it as one wall of grey while every other Markdown box in CourseForge is
 * highlighted, so it gets the same editor, fetched the same way the Content tab
 * fetches it: on demand, because it is the largest dependency in the
 * application and nobody who never opens this tab should pay for it.
 */
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

export const DetailsTab = {
  name: 'DetailsTab',
  components: { AppIcon, EmptyState, DetailEditor, MarkdownEditor, MarkdownPreview },
  setup() {
    const project = openCourse;
    const showInherited = ref(false);

    const onChange = (patch) => patchDetails('course', null, patch);

    /** Everything below the course that decided something for itself. */
    const overrides = computed(() => {
      const rows = [];
      const describe = (own) => [
        ...Object.entries(own.features ?? {}).map(([key, value]) => ({
          label: featureByKey.value[key]?.label ?? key,
          value: value === 1 ? 'on' : 'off',
          tone: value === 1 ? 'badge--success' : 'badge--danger',
        })),
        ...Object.entries(own.params ?? {}).map(([key, value]) => ({
          label: paramByKey.value[key]?.label ?? key,
          value: String(value),
          tone: 'badge--accent',
        })),
      ];

      for (const chapter of project.value.chapters) {
        const own = describe(chapter.details.own);
        if (own.length) rows.push({ kind: 'Chapter', name: `${chapter.idx + 1}. ${chapter.title}`, id: `c${chapter.id}`, items: own });
        for (const page of chapter.pages) {
          const pageOwn = describe(page.details.own);
          if (pageOwn.length) rows.push({ kind: 'Page', name: page.title, id: `p${page.id}`, items: pageOwn });
        }
      }
      return rows;
    });

    const clearOverride = (row) => {
      const [type, id] = [row.id[0], Number(row.id.slice(1))];
      const entity = type === 'c'
        ? project.value.chapters.find((c) => c.id === id)
        : project.value.chapters.flatMap((c) => c.pages).find((p) => p.id === id);
      if (!entity) return;
      patchDetails(type === 'c' ? 'chapter' : 'page', id, {
        features: Object.fromEntries(Object.keys(entity.details.own.features ?? {}).map((k) => [k, 0])),
        params: Object.fromEntries(Object.keys(entity.details.own.params ?? {}).map((k) => [k, null])),
      });
    };

    const autoLinksOn = computed(() => project.value.details.effective.features.auto_links === true);
    const linkStats = computed(() => project.value.stats.links ?? { markers: 0, resolved: 0, pending: 0 });

    /* ------------------------------------------------------------ research */

    const researchOn = computed(() => project.value.details.effective.features.web_research === true);
    const research = computed(() => project.value.research ?? { text: '', freshness: 'none stored', source: '' });

    // A local copy, because the box is edited over several keystrokes and the
    // stored value is only replaced when Save is pressed. Re-seeded when the
    // server sends a different text - which is what happens when a connected
    // client researches the course while this tab is open, and is also why the
    // editor is locked while a save of our own is in flight: the answer to it
    // arrives through this watcher and would land on top of the keystroke.
    const draft = ref(research.value.text ?? '');
    watch(() => research.value.text, (stored) => { draft.value = stored ?? ''; });

    const dirty = computed(() => draft.value !== (research.value.text ?? ''));

    // Leaving the course asks about research findings that were edited and not saved.
    declareUnsaved(() => (dirty.value ? 'unsaved research findings' : ''));
    const tooLong = computed(() => draft.value.length > (research.value.max_characters ?? 12000));

    const sourceLabel = computed(() => ({
      client: 'researched by a connected client',
      model: 'researched by the AI account',
      manual: 'entered here',
    }[research.value.source] ?? ''));

    /**
     * True only while a save of the briefing is in flight.
     *
     * Not `busy`, which every action on this tab shares: toggling a course
     * default or clearing an override lit it too, and the editor is locked while
     * it is lit, so an unrelated request half a second long silently swallowed
     * whatever was typed into the briefing during it. What the lock is for is
     * the answer to OUR save landing through the watcher above, and that is the
     * only thing this follows.
     */
    const saving = ref(false);

    const save = async () => {
      saving.value = true;
      try {
        await saveResearch(draft.value);
      } finally {
        saving.value = false;
      }
    };
    const revert = () => { draft.value = research.value.text ?? ''; };

    /* -- the two halves --------------------------------------------------- */

    /**
     * The briefing is read far more often than it is written, and a list of
     * sources is worth seeing as links rather than as brackets - so it gets the
     * same split the Content tab gives a page, with the same scroll link.
     *
     * `useScrollSync` keeps the linked/unlinked choice in one place under one
     * localStorage key, which means the two screens share it. That is the right
     * answer rather than an accident of reuse: it is a preference about how
     * split views behave, not about which document is open.
     */
    const researchView = ref('split');
    const researchEditor = ref(null);
    const researchPreview = ref(null);
    const sync = useScrollSync(researchEditor, researchPreview);

    // Leaving the split unmounts one half, and coming back must not throw the
    // survivor to line 0: the half that is about to appear is the follower, and
    // the one that was on screen keeps the position it had.
    watch(researchView, (mode, previous) => {
      if (mode !== 'split') return;
      sync.claim(previous === 'preview' ? 'preview' : 'editor');
      nextTick(sync.realign);
    });

    return {
      state, project, busy, onChange, overrides, clearOverride, showInherited, autoLinksOn, linkStats, plural,
      researchOn, research, draft, dirty, tooLong, sourceLabel, save, revert, saving,
      researchView, researchEditor, researchPreview,
      syncEnabled: sync.enabled, toggleSync: sync.toggle, claim: sync.claim,
      fromEditor: sync.fromEditor, fromPreview: sync.fromPreview, realign: sync.realign,
    };
  },
  template: `
    <div class="view-scroll">
      <div class="view-pad container col gap-5">

        <section class="card card--pad">
          <div class="row between mb-4">
            <div>
              <h2 class="t-lg">Course defaults</h2>
              <p class="hint">
                What every page of this course contains, unless a chapter or a page decides otherwise.
                These are the baseline the whole course inherits from.
              </p>
            </div>
          </div>
          <detail-editor level="course" :details="project.details" :busy="busy" @change="onChange"/>
        </section>

        <section class="card" :class="autoLinksOn ? '' : 'card--flat'">
          <div class="card__head">
            <app-icon name="link" :size="17" :class="autoLinksOn ? 'c-accent' : 'dim'"/>
            <span class="card__title grow">Auto links</span>
            <span class="badge" :class="autoLinksOn ? 'badge--accent' : ''">{{ autoLinksOn ? 'on' : 'off' }}</span>
          </div>
          <div class="card__body col gap-3">
            <p class="t-sm muted">
              With auto links on, the AI drops a plain-text marker wherever another chapter or page of this
              course is worth pointing at. Nothing is a link yet at that point — it is just text inside the page.
            </p>
            <p class="t-sm muted">
              After the course has been published, CourseForge walks every page and swaps those markers for real
              BookStack links, matching them against the actual chapter and page titles. That step is pure code:
              no second AI call, no tokens, and it can be repeated safely as often as you like.
            </p>

            <div class="grid grid-3 mt-2">
              <div class="stat">
                <div class="stat__value">{{ linkStats.markers }}</div>
                <div class="stat__label">markers written</div>
              </div>
              <div class="stat">
                <div class="stat__value c-success">{{ linkStats.resolved }}</div>
                <div class="stat__label">resolve to a link</div>
              </div>
              <div class="stat">
                <div class="stat__value" :class="linkStats.pending ? 'c-warning' : ''">{{ linkStats.pending }}</div>
                <div class="stat__label">waiting for a publish</div>
              </div>
            </div>

            <p class="hint">
              A marker whose target does not exist is published as plain text, so a hallucinated title never
              becomes a broken link. Resolve them from the <strong>Publish</strong> tab.
            </p>
          </div>
        </section>

        <section class="card" :class="researchOn ? '' : 'card--flat'">
          <div class="card__head">
            <app-icon name="search" :size="17" :class="researchOn ? 'c-accent' : 'dim'"/>
            <span class="card__title grow">Research</span>
            <span class="badge" :class="research.text ? 'badge--success' : ''">{{ research.freshness }}</span>
          </div>
          <div class="card__body col gap-3">
            <p class="t-sm muted">
              What is actually true about this subject today — the current stable version, what changed
              recently, what has been deprecated, where the documentation now lives. It is established
              <strong>once</strong> and then read by the outline and by every single page, so a course about
              something that moves stays current without researching the same facts once per page.
            </p>
            <p class="t-sm muted">
              An MCP client does this best and for nothing: connect Claude Code, call
              <code>get_research_brief</code>, let it search the web with its own tools, and it posts the
              findings back through <code>store_research</code> — they appear here. You can also just write
              them yourself, or edit what it found.
            </p>

            <div class="research-split">
              <div class="research-split__head row gap-2">
                <span class="eyebrow grow">Briefing — Markdown</span>
                <div class="btn-group hide-sm">
                  <button v-for="mode in ['edit','split','preview']" :key="mode"
                          :class="{ 'is-active': researchView === mode }" @click="researchView = mode">{{ mode }}</button>
                </div>
                <button v-if="researchView === 'split'" class="btn btn--ghost btn--sm btn--icon cf-sync hide-sm"
                        :class="{ 'is-active': syncEnabled }" :aria-pressed="String(syncEnabled)"
                        :title="syncEnabled ? 'Scrolling is linked — click to unlink' : 'Scrolling is independent — click to link'"
                        @click="toggleSync">
                  <app-icon :name="syncEnabled ? 'arrow-down-up' : 'arrow-down-up-off'" :size="14"/>
                </button>
              </div>

              <div class="split" :class="{ 'split--both': researchView === 'split' }">
                <div v-if="researchView !== 'preview'" class="split__half"
                     @pointerdown="claim('editor')" @wheel.passive="claim('editor')"
                     @focusin="claim('editor')" @keydown="claim('editor')">
                  <!-- Keyed on the course, like every other document in the
                       application: a different course is a different document,
                       and undo must not reach back into the one before it. -->
                  <markdown-editor ref="researchEditor" v-model="draft" :reset-key="project.id"
                                   :readonly="saving" :markers="false" label="Research briefing, Markdown"
                                   placeholder="## Versions&#10;- ...&#10;&#10;## Recently changed&#10;- ...&#10;&#10;## Sources&#10;- ..."
                                   @scroll="fromEditor"/>
                </div>
                <div v-if="researchView !== 'edit'" class="split__half"
                     @pointerdown="claim('preview')" @wheel.passive="claim('preview')">
                  <markdown-preview ref="researchPreview" :key="project.id" :source="draft"
                                    empty-title="Nothing established yet"
                                    empty-hint="Write the findings on the left, or let a connected client research them."
                                    @scroll="fromPreview" @rendered="realign"/>
                </div>
              </div>
            </div>

            <div class="row between wrap gap-2">
              <p class="hint">
                {{ draft.length.toLocaleString() }} / {{ (research.max_characters ?? 12000).toLocaleString() }}
                characters<span v-if="sourceLabel">, {{ sourceLabel }}</span>.
                This text travels with every page, so it is paid for once per page.
              </p>
              <div class="row gap-2">
                <button class="btn btn--ghost btn--sm" :disabled="busy || !dirty" @click="revert">Revert</button>
                <!-- "Clear" only when there is actually something stored to clear:
                     on an empty panel the button read "Clear research" over an
                     empty box, which describes nothing that could happen. -->
                <button class="btn btn--primary btn--sm" :disabled="busy || !dirty || tooLong" @click="save">
                  {{ !draft.trim() && research.text ? 'Clear research' : 'Save research' }}
                </button>
              </div>
            </div>

            <p v-if="tooLong" class="t-sm c-danger">
              That is longer than the server stores. Shorten it, or it will be cut at a line boundary on save.
            </p>
            <p v-if="!researchOn" class="hint">
              Web research is switched off for this course, so nothing asks for these facts to be found or
              refreshed — but anything stored here is still sent with every page. Turn on
              <strong>Web research</strong> above to have the briefs ask for it.
            </p>
          </div>
        </section>

        <section class="card">
          <div class="card__head">
            <span class="card__title grow">Overrides below the course</span>
            <span class="badge">{{ overrides.length }}</span>
          </div>
          <div class="card__body">
            <div v-if="overrides.length" class="scroll-x">
              <table class="table">
                <thead>
                  <tr><th style="width:90px">Level</th><th>Item</th><th>Decides</th><th style="width:60px"></th></tr>
                </thead>
                <tbody>
                  <tr v-for="row in overrides" :key="row.id">
                    <td><span class="badge">{{ row.kind }}</span></td>
                    <td class="truncate" style="max-width:340px">{{ row.name }}</td>
                    <td>
                      <div class="row wrap gap-1">
                        <span v-for="item in row.items" :key="item.label" class="badge" :class="item.tone">
                          {{ item.label }}: {{ item.value }}
                        </span>
                      </div>
                    </td>
                    <td>
                      <button class="btn btn--ghost btn--sm btn--icon" title="Follow the course again"
                              :disabled="busy" @click="clearOverride(row)">
                        <app-icon name="inherit" :size="13"/>
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <empty-state v-else icon="check-circle" title="Everything follows the course"
                         hint="Chapters and pages can override any of these settings from the Content tab — nothing does right now."/>
          </div>
        </section>
      </div>
    </div>`,
};

export default DetailsTab;
