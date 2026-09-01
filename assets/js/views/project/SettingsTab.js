import { reactive, computed, watch, ref } from 'vue';
import { state, openCourse, loadProjects } from '@/core/store.js';
import { del } from '@/core/api.js';
import { toast, attempt } from '@/core/toast.js';
import { formatDateTime } from '@/core/format.js';
import { busy, saveSettings, tagAdd, tagRemove, tagInherit, tagToggle, fixTypography, typographyCount } from '@/views/project/actions.js';

import AppIcon from '@/components/AppIcon.js';
import AppModal from '@/components/AppModal.js';
import TagPicker from '@/components/TagPicker.js';

export const SettingsTab = {
  name: 'SettingsTab',
  components: { AppIcon, AppModal, TagPicker },
  setup() {
    const project = openCourse;
    const confirmDelete = ref(false);

    const form = reactive({
      name: '', topic: '', profile_id: null,
      auto_tags: false, tag_pool: '', tag_pool_strict: false,
    });

    const sync = () => {
      const p = project.value;
      if (!p) return;
      form.name = p.name;
      form.topic = p.topic;
      form.profile_id = p.profile_id;
      form.auto_tags = p.auto_tags;
      form.tag_pool = p.tag_pool;
      form.tag_pool_strict = p.tag_pool_strict;
    };
    watch(() => project.value?.id, sync, { immediate: true });

    /* The Structure tab edits the same prompt, and keep-alive means this form is
       still mounted and still holding its own copy of it while that happens.
       Only that one field is re-seeded, never the whole form, because sync()
       would take an unsaved course name or tag pool down with it. Without this
       the box here kept whatever it was opened with, "unsaved" lit up over a
       change nobody made, and Save changes pushed the stale prompt back over the
       one just saved next door.

       `wasStored` is the prompt as it was before this change, so an incoming
       one is taken only while the box still holds it - untyped-in, in other
       words. Somebody who has edited this field keeps what they wrote and the
       badge goes on saying it differs; a prompt saved elsewhere is not a reason
       to throw away the sentence they are halfway through. Course switching
       does not come through here at all - sync() above owns that. */
    watch(() => project.value?.topic ?? '', (stored, wasStored) => {
      if (form.topic === wasStored) form.topic = stored;
    });

    const changed = computed(() => {
      const p = project.value;
      if (!p) return false;
      return form.name !== p.name || form.topic !== p.topic || form.profile_id !== p.profile_id
        || form.auto_tags !== p.auto_tags
        || form.tag_pool !== p.tag_pool || form.tag_pool_strict !== p.tag_pool_strict;
    });

    /* Where the course publishes is edited on the Publish tab and only reported
       here. It used to be a second select for the BookStack instance, which was
       fine while a course had exactly one destination and is a lie now that it
       can have several - a single box cannot say "two of these three wikis". */
    const destinations = computed(() => project.value?.targets ?? []);

    const save = async () => { await saveSettings({ ...form }); sync(); };

    const remove = () => attempt(async () => {
      await del(`projects/${project.value.id}`);
      confirmDelete.value = false;
      state.project = null;
      state.view = 'projects';
      await loadProjects();
      toast.success('Course deleted.');
    }, 'Delete course');

    // Vue's parser closes an interpolation at the first `}}`, so the literal
    // marker example is data rather than template text.
    const tagMarker = '{' + '{Vue, Reactivity}' + '}';

    /* -- punctuation ---------------------------------------------------- */

    /* Asked before it is done, because "correct this course" touches every page
       of it and the honest way to offer that is to say how many first. The
       preview is the same pass with the writing turned off, so the number in
       the dialog is the number that will actually change - not an estimate. */
    const typography = ref(null);

    const previewTypography = async () => {
      const result = await fixTypography('course', null, { preview: true });
      if (result) typography.value = result;
    };

    const applyTypography = async () => {
      const result = await fixTypography('course');
      typography.value = null;
      if (!result) return;
      toast.success(result.total ? `Punctuation corrected in ${typographyCount(result)}.` : 'Nothing needed correcting.');
    };

    return {
      state, project, form, changed, save, busy, confirmDelete, remove, destinations,
      tagAdd, tagRemove, tagInherit, tagToggle, tagMarker, formatDateTime,
      typography, previewTypography, applyTypography, typographyCount,
    };
  },
  template: `
    <div class="view-scroll">
      <div class="view-pad container-narrow col gap-5">

        <section class="card">
          <div class="card__head"><span class="card__title grow">Course</span>
            <span v-if="changed" class="badge badge--warning">unsaved</span>
          </div>
          <div class="card__body col gap-4">
            <div class="form-row">
              <label>Course name</label>
              <input v-model="form.name">
              <p class="hint">Shown in CourseForge. The published book uses the title from the outline.</p>
            </div>
            <div class="form-row">
              <label>Course prompt</label>
              <textarea v-model="form.topic" rows="3"></textarea>
              <p class="hint">
                The brief the outline is designed from, and the same box the <strong>Structure</strong> tab shows
                beside the outline. Editing it does not regenerate anything by itself.
              </p>
            </div>
            <div class="grid grid-2">
              <div class="form-row">
                <label>Profile</label>
                <select v-model="form.profile_id">
                  <option :value="null">— none —</option>
                  <option v-for="profile in state.profiles" :key="profile.id" :value="profile.id">{{ profile.name }}</option>
                </select>
                <p class="hint">Supplies the AI account, the models, the language and the prompt overrides.</p>
              </div>
              <div class="form-row">
                <label>Publishes to</label>
                <div class="row wrap gap-2" style="min-height:32px;align-items:center">
                  <span v-for="target in destinations" :key="target.id" class="badge"
                        :class="target.enabled ? '' : 'badge--outline'">
                    {{ target.instance_name || target.instance_id }}<template v-if="!target.enabled"> (off)</template>
                  </span>
                  <span v-if="!destinations.length" class="dim t-sm">— nowhere yet —</span>
                </div>
                <p class="hint">
                  A course can publish into several BookStack instances at once.
                  <button class="btn btn--ghost btn--sm" style="padding:0 4px"
                          @click="state.projectTab = 'publish'">Publish tab</button>
                  is where that list is edited.
                </p>
              </div>
            </div>
          </div>
          <div class="card__foot row end gap-2">
            <button class="btn btn--primary" :disabled="busy || !changed" @click="save">
              <app-icon name="save" :size="14"/> Save changes
            </button>
          </div>
        </section>

        <section class="card">
          <div class="card__head">
            <app-icon name="sparkles" :size="16" class="c-magic"/>
            <span class="card__title grow">AI tagging</span>
            <span class="badge" :class="form.auto_tags ? 'badge--magic' : ''">{{ form.auto_tags ? 'on' : 'off' }}</span>
          </div>
          <div class="card__body col gap-4">
            <label class="check">
              <input type="checkbox" v-model="form.auto_tags">
              <span>
                Let the AI tag the book, every chapter and every page while it designs the structure
                <span class="hint">
                  Markers such as <code>{{ tagMarker }}</code> are written next to each title and become real
                  tags when the structure is applied. Existing tags are reused, never duplicated.
                </span>
              </span>
            </label>

            <template v-if="form.auto_tags">
              <div class="form-row">
                <label>Tag pool</label>
                <textarea v-model="form.tag_pool" rows="3" class="mono" spellcheck="false"
                          placeholder="Vue, Reactivity, Tooling, Testing, Beginner, Advanced"></textarea>
                <p class="hint">Comma separated. Leave empty to let the AI choose its own keywords.</p>
              </div>
              <label class="check">
                <input type="checkbox" v-model="form.tag_pool_strict">
                <span>
                  Use only tags from this pool
                  <span class="hint">The AI may not invent a tag of its own; if nothing fits it uses fewer tags.</span>
                </span>
              </label>
            </template>
          </div>
          <div class="card__foot row end">
            <button class="btn btn--primary" :disabled="busy || !changed" @click="save">
              <app-icon name="save" :size="14"/> Save changes
            </button>
          </div>
        </section>

        <section class="card">
          <div class="card__head">
            <app-icon name="tag" :size="16" class="c-accent"/>
            <span class="card__title grow">Course tags</span>
          </div>
          <div class="card__body">
            <tag-picker label="Attached to the book itself" :tags="project.tags" :inherited="[]" :busy="busy"
                        @add="tagAdd('course', null, $event)"
                        @remove="tagRemove('course', null, $event)"
                        @inherit="tagInherit('course', null, $event)"
                        @toggle="tagToggle('course', null, $event)"/>
            <p class="hint mt-3">
              A tag marked with the inheritance icon also reaches every chapter and page of this course.
            </p>
          </div>
        </section>

        <section class="card">
          <div class="card__head">
            <app-icon name="quote" :size="16"/>
            <span class="card__title grow">Punctuation</span>
          </div>
          <div class="card__body col gap-3">
            <p class="hint">
              Sets the quotation marks, apostrophes, ellipses, dashes and spacing of every page, chapter
              and title of this course the way its language sets them. Code, links, formulas, HTML and
              anything escaped on purpose are never touched, and running it twice changes nothing the
              second time. This works whether or not the profile corrects new pages as they are written.
            </p>
            <div class="row wrap between gap-3">
              <p class="t-xs dim grow">
                Pages generated from now on are corrected on the profile's terms; this is for the ones
                already written.
              </p>
              <button class="btn none" :disabled="busy" @click="previewTypography">
                <app-icon name="quote" :size="14"/> Correct the punctuation
              </button>
            </div>
          </div>
        </section>

        <section class="card">
          <div class="card__head"><span class="card__title grow">Facts</span></div>
          <div class="card__body grid grid-2 t-sm">
            <div class="row between"><span class="dim">Chapters</span><span class="nums">{{ project.stats.chapters }}</span></div>
            <div class="row between"><span class="dim">Pages</span><span class="nums">{{ project.stats.pages }}</span></div>
            <div class="row between"><span class="dim">Book</span>
              <span class="nums">{{ project.book_id ? '#' + project.book_id : 'not created yet' }}</span></div>
            <div class="row between"><span class="dim">Destinations</span>
              <span class="nums">{{ destinations.length }}</span></div>
            <div class="row between"><span class="dim">Created</span><span>{{ formatDateTime(project.created_at) }}</span></div>
            <div class="row between"><span class="dim">Updated</span><span>{{ formatDateTime(project.updated_at) }}</span></div>
          </div>
        </section>

        <section class="card" style="border-color:var(--danger-line)">
          <div class="card__head">
            <app-icon name="alert" :size="16" class="c-danger"/>
            <span class="card__title grow">Danger zone</span>
          </div>
          <div class="card__body row wrap between gap-3">
            <p class="hint grow">
              Deleting removes the course, its structure and every generated page from CourseForge.
              Anything already published stays in BookStack.
            </p>
            <button class="btn btn--danger none" @click="confirmDelete = true">
              <app-icon name="trash" :size="14"/> Delete this course
            </button>
          </div>
        </section>
      </div>
    </div>

    <app-modal v-if="typography" title="Correct the punctuation of this course?" icon="quote"
               @close="typography = null">
      <p class="t-sm" v-if="typography.total">
        Set as <strong>{{ typography.language }}</strong>, which uses
        <code>{{ typography.marks[0] }}…{{ typography.marks[1] }}</code>.
        Of {{ typography.scanned.pages }} page(s) and {{ typography.scanned.chapters }} chapter(s),
        this would change <strong>{{ typographyCount(typography) }}</strong>.
      </p>
      <p class="t-sm" v-else>
        Nothing to do: every page, chapter and title is already set the way
        <strong>{{ typography.language }}</strong> sets it.
      </p>
      <ul v-if="typography.changed.length" class="t-xs dim mt-2" style="padding-left:18px">
        <li v-for="item in typography.changed" :key="item.type + item.id">
          {{ item.type }} · {{ item.title }} <span class="faint">({{ item.fields.join(', ') }})</span>
        </li>
      </ul>
      <p v-if="typography.total > typography.listed" class="hint mt-2">
        …and {{ typography.total - typography.listed }} more.
      </p>
      <p v-if="typography.total" class="hint mt-2">
        A corrected page differs from the one already in BookStack, so it will show as out of sync
        until the next publish.
      </p>
      <template #footer>
        <button class="btn" @click="typography = null">{{ typography.total ? 'Cancel' : 'Close' }}</button>
        <button v-if="typography.total" class="btn btn--primary" :disabled="busy" @click="applyTypography">
          <app-icon name="quote" :size="14"/> Correct {{ typographyCount(typography) }}
        </button>
      </template>
    </app-modal>

    <app-modal v-if="confirmDelete" title="Delete this course?" icon="alert" @close="confirmDelete = false">
      <p class="t-sm"><strong>{{ project.name }}</strong> and its {{ project.stats.pages }} page(s) will be removed.</p>
      <template #footer>
        <button class="btn" @click="confirmDelete = false">Cancel</button>
        <button class="btn btn--danger" @click="remove"><app-icon name="trash" :size="14"/> Delete</button>
      </template>
    </app-modal>`,
};

export default SettingsTab;
