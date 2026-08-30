import { ref, computed, watch } from 'vue';
import { state, openCourse, applyProject } from '@/core/store.js';
import { post, put } from '@/core/api.js';
import { toast, attempt } from '@/core/toast.js';
import { plural } from '@/core/format.js';
import { saveSettings } from './actions.js';

import AppIcon from '@/components/AppIcon.js';
import AppModal from '@/components/AppModal.js';
import EmptyState from '@/components/EmptyState.js';
import MarkdownBlock from '@/components/MarkdownBlock.js';

export const StructureTab = {
  name: 'StructureTab',
  components: { AppIcon, AppModal, EmptyState, MarkdownBlock },
  setup() {
    const project = openCourse;
    const topic = ref(project.value?.topic ?? '');
    const markdown = ref(project.value?.structure_md ?? '');
    const feedback = ref('');
    const generating = ref(false);
    const applying = ref(false);

    /* The titles of pages the outline in the editor would delete, each of them
       with text on it. Non-empty means the dialog is up and nothing has been
       written yet: the server refuses such an apply and answers with this list,
       and the same list arrives when the AI designs an outline that would cost
       written pages, which is handed back rather than applied. */
    const atRisk = ref([]);

    // Watched as one string rather than as an array, because an array getter
    // returns a fresh value every time the effect re-runs and the callback then
    // fires whenever `state.project` is merely replaced - which the batch poll
    // does every minute, and which would silently discard an unsaved edit.
    watch(
      () => `${project.value?.id ?? 0}\u0000${project.value?.structure_md ?? ''}`,
      () => {
        markdown.value = project.value?.structure_md ?? '';
        topic.value = project.value?.topic ?? '';
      }
    );

    const dirty = computed(() => markdown.value !== (project.value?.structure_md ?? ''));
    const hasStructure = computed(() => (project.value?.structure_md ?? '').trim() !== '');
    const canRefine = computed(() => hasStructure.value && feedback.value.trim() !== '');

    const generate = (refine) => attempt(async () => {
      generating.value = true;
      try {
        const body = { topic: topic.value };
        if (refine) body.feedback = feedback.value;
        const data = applyProject(await post(`projects/${project.value.id}/structure`, body));
        feedback.value = '';

        // The model has already been paid for, so an outline that would cost
        // written pages comes back unapplied instead of being thrown away. It
        // goes into the editor, and the dialog asks about the pages; applying
        // it from there costs nothing.
        if (data.applied === false) {
          markdown.value = data.structure_md ?? markdown.value;
          atRisk.value = data.at_risk ?? [];
          return;
        }
        toast.success(refine ? 'Structure revised.' : 'Structure generated.');
      } finally {
        generating.value = false;
      }
    }, refine ? 'Revise structure' : 'Generate structure');

    /* `confirmed` is somebody having read the dialog and pressed the red
       button. The server refuses the apply outright without it, which is the
       same refusal the MCP tool gets, so this is a question being passed on
       rather than a guard of its own that could drift away from it. */
    const apply = (confirmed = false) => attempt(async () => {
      applying.value = true;
      try {
        const data = applyProject(await put(`projects/${project.value.id}/structure`, {
          structure_md: markdown.value,
          confirm_removals: confirmed,
        }));
        atRisk.value = [];
        const removed = data.removed ?? {};
        const note = removed.pages || removed.chapters
          ? ` Removed ${plural(removed.chapters ?? 0, 'chapter')} and ${plural(removed.pages ?? 0, 'page')}.`
          : '';
        toast.success(`Structure applied.${note}`);
      } catch (error) {
        // A refusal is not a failure worth a toast - it is the question the
        // dialog is about to ask, and one event must not raise two notices.
        const pages = error?.payload?.at_risk;
        if (Array.isArray(pages) && pages.length) {
          atRisk.value = pages;
          return;
        }
        throw error;
      } finally {
        applying.value = false;
      }
    }, 'Apply structure');

    const saveTopic = () => saveSettings({ topic: topic.value }, { silent: true })
      .then(() => toast.success('Course prompt saved.'));

    const revert = () => { markdown.value = project.value?.structure_md ?? ''; };

    /* The published book takes its title from the outline, the course keeps the
       name it is filed under here, and the two are allowed to differ. Saying so
       where the outline is edited is what stops the difference from looking
       like one of them having quietly overwritten the other. */
    const publishedAs = computed(() => {
      const bookTitle = (project.value?.book_title ?? '').trim();
      return bookTitle && bookTitle !== (project.value?.name ?? '') ? bookTitle : '';
    });

    return {
      state, project, topic, markdown, feedback, generating, applying, atRisk,
      dirty, hasStructure, canRefine, publishedAs, plural,
      generate, apply, saveTopic, revert,
    };
  },
  template: `
    <div class="workspace workspace--two workspace--stack">
      <!-- editor ------------------------------------------------------- -->
      <section class="pane">
        <div class="pane__head">
          <span class="eyebrow grow">Outline — strict Markdown</span>
          <span v-if="dirty" class="badge badge--warning">unapplied edits</span>
          <button class="btn btn--sm" :disabled="!dirty" @click="revert">Revert</button>
          <!-- apply(false), never bare apply: a method reference is handed the
               click event, which would arrive here as "yes, delete them". -->
          <button class="btn btn--success btn--sm" :disabled="applying || !markdown.trim()" @click="apply(false)">
            <app-icon v-if="applying" name="refresh" :size="13" spin/><app-icon v-else name="check" :size="13"/>
            Apply
          </button>
        </div>

        <textarea v-model="markdown" class="code-area" spellcheck="false"
                  placeholder="# Course title&#10;&#10;A short description of the whole course.&#10;&#10;1. First chapter&#10;   What the learner can do after it.&#10;   1. First page&#10;   2. Second page"></textarea>

        <div class="pane__foot row wrap gap-2">
          <app-icon name="info" :size="13" class="dim none"/>
          <p class="hint grow">
            Applying matches chapters and pages <strong>by title</strong>, so anything already written stays attached.
            A page this outline no longer names is <strong>deleted</strong>, and the text on it goes with it —
            renaming a title is the usual way that happens. CourseForge asks first if the page has text on it.
          </p>
        </div>
      </section>

      <!-- side --------------------------------------------------------- -->
      <aside class="pane pane--aside">
        <div class="pane__body view-pad col gap-4">
          <div class="card card--pad col gap-3">
            <span class="eyebrow">Course prompt</span>
            <textarea v-model="topic" rows="4"
                      placeholder="Vue.js – complete course from beginner to professional"></textarea>
            <div class="row gap-2">
              <button class="btn btn--primary grow" :disabled="generating || !topic.trim()" @click="generate(false)">
                <app-icon v-if="generating" name="refresh" :size="14" spin/><app-icon v-else name="sparkles" :size="14"/>
                {{ generating ? 'Designing…' : (hasStructure ? 'Regenerate' : 'Generate structure') }}
              </button>
              <button class="btn none" @click="saveTopic" title="Save the prompt without generating">
                <app-icon name="save" :size="14"/>
              </button>
            </div>
            <p v-if="hasStructure" class="hint">
              Regenerating designs a brand-new outline. To adjust the current one, use the revision box below —
              it keeps untouched titles byte-identical so their content survives.
            </p>
          </div>

          <div class="card card--pad col gap-3">
            <span class="eyebrow">Request changes</span>
            <textarea v-model="feedback" rows="4"
                      placeholder="Add a chapter about testing, merge chapters 4 and 5, split the routing page…"></textarea>
            <button class="btn btn--primary" :disabled="generating || !canRefine" @click="generate(true)">
              <app-icon :name="generating ? 'refresh' : 'pencil'" :size="14" :spin="generating"/>
              Revise structure
            </button>
            <p class="hint">The AI rewrites the whole outline but changes only what you asked for.</p>
          </div>

          <div class="card">
            <div class="card__head">
              <span class="card__title grow">Parsed result</span>
              <span class="badge">{{ project.stats.chapters }} / {{ project.stats.pages }}</span>
            </div>
            <div class="card__body">
              <p v-if="publishedAs" class="t-xs dim mb-3">
                Published book title: <strong>{{ publishedAs }}</strong>, from the <code>#</code> line of the outline.
                In CourseForge this course is called <strong>{{ project.name }}</strong> — rename it under Settings.
              </p>
              <!-- Written by the model in the same Markdown as every page, so it
                   is rendered like one rather than shown as a flat grey string. -->
              <markdown-block v-if="project.book_desc" :source="project.book_desc" compact class="dim mb-3"/>
              <ol v-if="project.chapters.length" class="col gap-3" style="list-style:none;padding:0">
                <li v-for="chapter in project.chapters" :key="chapter.id">
                  <div class="t-sm semi">{{ chapter.idx + 1 }}. {{ chapter.title }}</div>
                  <ol class="t-xs dim" style="margin:4px 0 0 20px">
                    <li v-for="page in chapter.pages" :key="page.id">{{ page.title }}</li>
                  </ol>
                </li>
              </ol>
              <empty-state v-else icon="sitemap" title="Nothing parsed yet"
                           hint="Generate a structure, or paste one and press Apply."/>
            </div>
          </div>
        </div>
      </aside>
    </div>

    <app-modal v-if="atRisk.length" title="Delete pages that have text?" icon="alert" @close="atRisk = []">
      <p class="t-sm">
        This outline no longer names {{ plural(atRisk.length, 'page') }} that
        {{ atRisk.length === 1 ? 'has' : 'have' }} text written on
        {{ atRisk.length === 1 ? 'it' : 'them' }}. Applying it deletes
        {{ atRisk.length === 1 ? 'that page' : 'those pages' }} and everything written on
        {{ atRisk.length === 1 ? 'it' : 'them' }}. There is no undo.
      </p>
      <ul class="t-sm mt-3" style="padding-left:20px">
        <li v-for="title in atRisk" :key="title">{{ title }}</li>
      </ul>
      <p class="hint mt-3">
        To keep a page, cancel and put its title back into the outline exactly as it was.
      </p>
      <template #footer>
        <button class="btn" @click="atRisk = []">Cancel</button>
        <button class="btn btn--danger" :disabled="applying" @click="apply(true)">
          <app-icon name="trash" :size="14"/> Delete and apply
        </button>
      </template>
    </app-modal>`,
};

export default StructureTab;
