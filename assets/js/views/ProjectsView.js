/**
 * Courses - the inventory of everything being written on this installation.
 *
 * A normal account sees its own courses and nothing else, which is the whole of
 * what it needs. An administrator is shown every account's, because this is the
 * one listing that answers a question about the installation rather than about
 * a person: what is being written on it, what is stuck, how much of it there
 * is. Each row carries its owner, so a shared list stays readable - somebody
 * else's course is marked as theirs, and the filter narrows the list to one
 * account when only one account is the point.
 */
import { ref, reactive, computed, watch } from 'vue';
import { state, isAdmin, loadProjects, openProject } from '@/core/store.js';
import { post, del } from '@/core/api.js';
import { toast, attempt } from '@/core/toast.js';
import { useFuzzy } from '@/core/fuzzy.js';
import { relativeTime, percent, plural } from '@/core/format.js';

import AppIcon from '@/components/AppIcon.js';
import AppModal from '@/components/AppModal.js';
import EmptyState from '@/components/EmptyState.js';
import ViewHeader from '@/components/ViewHeader.js';

const SORTS = {
  updated: (a, b) => b.updated_at - a.updated_at,
  created: (a, b) => b.created_at - a.created_at,
  name: (a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }),
  progress: (a, b) => percent(b.generated_count, b.page_count) - percent(a.generated_count, a.page_count),
};

export const ProjectsView = {
  name: 'ProjectsView',
  components: { AppIcon, AppModal, EmptyState, ViewHeader },
  setup() {
    const search = ref('');
    const sort = ref('updated');
    const ownerFilter = ref('');
    const showCreate = ref(false);
    const creating = ref(false);
    const draft = reactive({ name: '', topic: '', profile_id: null });
    const confirmDelete = ref(null);

    watch(() => state.profiles, (profiles) => {
      if (draft.profile_id === null && profiles.length) draft.profile_id = profiles[0].id;
    }, { immediate: true, deep: false });

    /**
     * Every account with a course here, for the filter.
     *
     * Only ever more than one name for an administrator - the server sends a
     * normal account nothing but its own rows - so the filter draws itself only
     * when there is genuinely something to choose between.
     */
    const owners = computed(() => {
      const names = new Set();
      for (const project of state.projects) if (project.owner) names.add(project.owner);
      return [...names].sort((a, b) => a.localeCompare(b, undefined, { sensitivity: 'base' }));
    });

    const showOwners = computed(() => isAdmin.value && owners.value.length > 1);

    /** Somebody else's course, from the point of view of the person looking. */
    const isForeign = (project) =>
      isAdmin.value && Boolean(project.owner) && project.owner !== state.user?.username;

    // The owner filter narrows the pool before the search runs, so a search
    // inside one account's courses stays inside it.
    const pool = computed(() => (ownerFilter.value
      ? state.projects.filter((project) => project.owner === ownerFilter.value)
      : state.projects));

    const found = useFuzzy(pool, search, { keys: ['name', 'topic'], limit: 200 });
    const projects = computed(() => [...found.value].sort(SORTS[sort.value] ?? SORTS.updated));

    // An account whose last course was deleted would otherwise leave the list
    // filtered to a name that no longer appears in it.
    watch(owners, (names) => {
      if (ownerFilter.value && !names.includes(ownerFilter.value)) ownerFilter.value = '';
    });

    const create = () => attempt(async () => {
      if (!draft.topic.trim()) {
        toast.error('Describe the course you want to build.');
        return;
      }
      creating.value = true;
      try {
        const data = await post('projects', {
          name: draft.name.trim() || 'Untitled course',
          topic: draft.topic.trim(),
          profile_id: draft.profile_id,
        });
        draft.name = '';
        draft.topic = '';
        showCreate.value = false;
        await loadProjects();
        state.project = data.project;
        state.view = 'project';
        state.projectTab = 'structure';
      } finally {
        creating.value = false;
      }
    }, 'Create course');

    /**
     * What the confirmation promises to delete.
     *
     * The card behind the dialog says "1/4 pages written", so the dialog has to
     * agree with it: the outline size is how many pages exist, not how many were
     * generated, and calling all four of them "generated pages" overstated what
     * was about to be lost.
     */
    const deleteScope = (project) => {
      if (!project.page_count) return 'will be removed from CourseForge. It has no pages yet.';
      return `and its ${plural(project.page_count, 'page')} (${project.generated_count} written)`
        + ' will be removed from CourseForge.';
    };

    // One click, one deletion, one message: the modal stays open until the
    // server answers, and a second click used to send a second DELETE that
    // could only ever 404 - reporting a failure for something that had just
    // succeeded.
    const deleting = ref(false);

    const remove = (project) => attempt(async () => {
      if (deleting.value) return;
      deleting.value = true;
      try {
        await del(`projects/${project.id}`);
        confirmDelete.value = null;
        await loadProjects();
        toast.success(`"${project.name}" deleted.`);
      } finally {
        deleting.value = false;
      }
    }, 'Delete course');

    const profileName = (id) => state.profiles.find((p) => p.id === id)?.name ?? 'no profile';

    // `openProject` is async, so handing it straight to a click listener let a
    // rejected fetch - a session that expired while the list sat on screen -
    // reach Vue's error handler as an unhandled rejection. The recovery is the
    // same either way; this makes it a toast instead of a console stack trace.
    const open = (id) => attempt(() => openProject(id), 'Open course');

    return {
      state, isAdmin, search, sort, projects, showCreate, creating, draft, confirmDelete,
      ownerFilter, owners, showOwners, isForeign, deleting, deleteScope,
      create, remove, open, profileName,
      relativeTime, percent, plural,
    };
  },
  template: `
    <view-header title="Courses" icon="book">
      <template #actions>
        <button class="btn btn--primary" @click="showCreate = true">
          <app-icon name="plus" :size="15"/> New course
        </button>
      </template>
    </view-header>

    <div class="view-scroll">
      <div class="view-pad container">
        <div class="row wrap gap-3 mb-4" v-if="state.projects.length">
          <div class="grow" style="max-width:420px;position:relative">
            <app-icon name="search" :size="14"
                      style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-faint)"/>
            <input v-model="search" placeholder="Search courses…" style="padding-left:30px" spellcheck="false">
          </div>
          <div v-if="showOwners" class="row gap-2 none">
            <span class="t-xs dim">Belongs to</span>
            <select v-model="ownerFilter" style="width:auto"
                    title="Show only the courses of one account">
              <option value="">Everyone</option>
              <option v-for="owner in owners" :key="owner" :value="owner">
                {{ owner === state.user.username ? owner + ' (you)' : owner }}
              </option>
            </select>
          </div>
          <div class="row gap-2 none">
            <span class="t-xs dim">Sort</span>
            <select v-model="sort" style="width:auto">
              <option value="updated">Last updated</option>
              <option value="created">Newest</option>
              <option value="name">Name</option>
              <option value="progress">Progress</option>
            </select>
          </div>
          <span class="badge push">{{ plural(projects.length, 'course') }}</span>
        </div>

        <div v-if="projects.length" class="grid grid-auto">
          <article v-for="project in projects" :key="project.id" class="card">
            <div class="card__body col gap-3">
              <div class="row-top between gap-2">
                <button class="grow" style="background:none;border:0;padding:0;text-align:left;cursor:pointer;color:inherit"
                        @click="open(project.id)">
                  <h3 class="truncate" style="font-size:var(--t-md)">{{ project.name }}</h3>
                </button>
                <button class="btn btn--ghost btn--sm btn--icon none" title="Delete this course"
                        @click="confirmDelete = project">
                  <app-icon name="trash" :size="14"/>
                </button>
              </div>

              <p class="t-xs dim clamp-2" style="min-height:2.6em">{{ project.topic || 'No topic set.' }}</p>

              <div>
                <div class="bar">
                  <div class="bar__fill" :class="{ 'bar__fill--success': project.pushed_count === project.page_count && project.page_count }"
                       :style="{ width: percent(project.generated_count, project.page_count) + '%' }"></div>
                </div>
                <div class="row between t-2xs dim mt-1">
                  <span class="nums">{{ project.generated_count }}/{{ project.page_count }} pages written</span>
                  <span class="nums">{{ percent(project.generated_count, project.page_count) }}%</span>
                </div>
              </div>

              <div class="row wrap gap-1">
                <!-- Only ever drawn for an administrator, and only for a course
                     that is not theirs: a list of your own courses does not need
                     your own name on every card. -->
                <span v-if="isForeign(project)" class="chip chip--inherited"
                      :title="'This course belongs to ' + project.owner">
                  <app-icon name="user" :size="10"/>{{ project.owner }}
                </span>
                <span class="badge">{{ plural(project.chapter_count, 'chapter') }}</span>
                <span v-if="project.pushed_count" class="badge badge--success">
                  <app-icon name="upload" :size="10"/> {{ project.pushed_count }} published
                </span>
                <span v-if="project.auto_links" class="badge badge--accent">
                  <app-icon name="link" :size="10"/> auto links
                </span>
              </div>

              <div class="row between t-2xs faint">
                <span class="truncate">{{ profileName(project.profile_id) }}</span>
                <span class="none">{{ relativeTime(project.updated_at) }}</span>
              </div>

              <button class="btn btn--block" @click="open(project.id)">
                Open <app-icon name="chevron-right" :size="14"/>
              </button>
            </div>
          </article>
        </div>

        <empty-state v-else-if="state.projects.length" icon="search"
                     title="Nothing matches that"
                     :hint="ownerFilter
                       ? 'No course of ' + ownerFilter + ' matches. Try a different word, or set the owner back to everyone.'
                       : 'Try a different word, or clear the search box.'"/>

        <empty-state v-else icon="book" title="No courses yet"
                     hint="A course starts with one sentence: what should be taught, to whom, and up to which level.">
          <button class="btn btn--primary mt-2" @click="showCreate = true">
            <app-icon name="plus" :size="15"/> Create your first course
          </button>
        </empty-state>
      </div>
    </div>

    <app-modal v-if="showCreate" title="New course" icon="sparkles" @close="showCreate = false">
      <div class="col gap-4">
        <div class="form-row">
          <label for="new-topic">What should this course teach?</label>
          <textarea id="new-topic" v-model="draft.topic" rows="3"
                    placeholder="Vue.js – complete course from beginner to professional; IDE: PhpStorm"></textarea>
          <p class="hint">Name the subject, the audience and the level span. The AI designs the outline from this.</p>
        </div>
        <div class="grid grid-2" style="gap:var(--s-4)">
          <div class="form-row">
            <label for="new-name">Course name</label>
            <input id="new-name" v-model="draft.name" placeholder="Taken from the outline">
          </div>
          <div class="form-row">
            <label for="new-profile">Profile</label>
            <select id="new-profile" v-model="draft.profile_id">
              <option :value="null">— none —</option>
              <option v-for="profile in state.profiles" :key="profile.id" :value="profile.id">{{ profile.name }}</option>
            </select>
          </div>
        </div>
        <p v-if="!state.profiles.length" class="hint c-warning">
          There is no profile yet. You can create the course now, but generating anything needs a profile with an AI account.
        </p>
      </div>
      <template #footer>
        <button class="btn" @click="showCreate = false">Cancel</button>
        <button class="btn btn--primary" :disabled="creating || !draft.topic.trim()" @click="create">
          <app-icon v-if="creating" name="refresh" :size="14" spin/>
          {{ creating ? 'Creating…' : 'Create course' }}
        </button>
      </template>
    </app-modal>

    <app-modal v-if="confirmDelete" title="Delete this course?" icon="alert" @close="confirmDelete = null">
      <p class="t-sm">
        <strong>{{ confirmDelete.name }}</strong> {{ deleteScope(confirmDelete) }}
      </p>
      <p v-if="isForeign(confirmDelete)" class="hint mt-2 c-warning">
        This course belongs to <strong>{{ confirmDelete.owner }}</strong>, not to you. They will not be told.
      </p>
      <p class="hint mt-2">Anything already published stays in BookStack — delete it there separately.</p>
      <template #footer>
        <button class="btn" :disabled="deleting" @click="confirmDelete = null">Cancel</button>
        <button class="btn btn--danger" :disabled="deleting" @click="remove(confirmDelete)">
          <app-icon :name="deleting ? 'refresh' : 'trash'" :size="14" :spin="deleting"/>
          {{ deleting ? 'Deleting…' : 'Delete' }}
        </button>
      </template>
    </app-modal>`,
};

export default ProjectsView;
