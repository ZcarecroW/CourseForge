import { reactive, computed, watch, ref } from 'vue';
import { state, openCourse, bookstackInstances, loadProjects } from '@/core/store.js';
import { del } from '@/core/api.js';
import { toast, attempt } from '@/core/toast.js';
import { formatDateTime } from '@/core/format.js';
import { busy, saveSettings, tagAdd, tagRemove, tagInherit, tagToggle } from './actions.js';

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
      name: '', topic: '', profile_id: null, bs_instance_id: '',
      auto_tags: false, tag_pool: '', tag_pool_strict: false,
    });

    const sync = () => {
      const p = project.value;
      if (!p) return;
      form.name = p.name;
      form.topic = p.topic;
      form.profile_id = p.profile_id;
      form.bs_instance_id = p.bs_instance_id;
      form.auto_tags = p.auto_tags;
      form.tag_pool = p.tag_pool;
      form.tag_pool_strict = p.tag_pool_strict;
    };
    watch(() => project.value?.id, sync, { immediate: true });

    const changed = computed(() => {
      const p = project.value;
      if (!p) return false;
      return form.name !== p.name || form.topic !== p.topic || form.profile_id !== p.profile_id
        || form.bs_instance_id !== p.bs_instance_id || form.auto_tags !== p.auto_tags
        || form.tag_pool !== p.tag_pool || form.tag_pool_strict !== p.tag_pool_strict;
    });

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

    return {
      state, project, form, changed, save, busy, confirmDelete, remove,
      bookstackInstances, tagAdd, tagRemove, tagInherit, tagToggle, tagMarker, formatDateTime,
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
              <p class="hint">The brief the outline is designed from. Editing it does not regenerate anything by itself.</p>
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
                <label>BookStack instance</label>
                <select v-model="form.bs_instance_id">
                  <option value="">— none —</option>
                  <option v-for="instance in bookstackInstances" :key="instance.id" :value="instance.id">
                    {{ instance.name }}
                  </option>
                </select>
                <p class="hint">Where this course gets published.</p>
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
          <div class="card__head"><span class="card__title grow">Facts</span></div>
          <div class="card__body grid grid-2 t-sm">
            <div class="row between"><span class="dim">Chapters</span><span class="nums">{{ project.stats.chapters }}</span></div>
            <div class="row between"><span class="dim">Pages</span><span class="nums">{{ project.stats.pages }}</span></div>
            <div class="row between"><span class="dim">Book</span>
              <span class="nums">{{ project.book_id ? '#' + project.book_id : 'not created yet' }}</span></div>
            <div class="row between"><span class="dim">Shelf</span><span>{{ project.shelf_name || '—' }}</span></div>
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

    <app-modal v-if="confirmDelete" title="Delete this course?" icon="alert" @close="confirmDelete = false">
      <p class="t-sm"><strong>{{ project.name }}</strong> and its {{ project.stats.pages }} page(s) will be removed.</p>
      <template #footer>
        <button class="btn" @click="confirmDelete = false">Cancel</button>
        <button class="btn btn--danger" @click="remove"><app-icon name="trash" :size="14"/> Delete</button>
      </template>
    </app-modal>`,
};

export default SettingsTab;
