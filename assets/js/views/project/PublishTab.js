import { ref, reactive, computed, watch } from 'vue';
import { state, openCourse, bookstackInstances } from '@/core/store.js';
import { post } from '@/core/api.js';
import { toast, attempt } from '@/core/toast.js';
import { plural } from '@/core/format.js';
import { busy, saveSettings } from '@/views/project/actions.js';

import AppIcon from '@/components/AppIcon.js';
import EmptyState from '@/components/EmptyState.js';

export const PublishTab = {
  name: 'PublishTab',
  components: { AppIcon, EmptyState },
  setup() {
    const project = openCourse;
    const target = reactive({ bs_instance_id: '', shelf_id: null });
    const shelves = ref([]);
    const loadingShelves = ref(false);
    const running = ref(false);
    const log = ref([]);
    const force = ref(false);

    const sync = () => {
      target.bs_instance_id = project.value?.bs_instance_id ?? '';
      target.shelf_id = project.value?.shelf_id ?? null;
    };
    watch(() => project.value?.id, sync, { immediate: true });

    const stats = computed(() => project.value?.stats ?? {});
    const links = computed(() => stats.value.links ?? { markers: 0, resolved: 0, pending: 0 });
    const ready = computed(() => target.bs_instance_id !== '');

    const saveTarget = async () => {
      const shelf = shelves.value.find((s) => s.id === target.shelf_id);
      await saveSettings({
        bs_instance_id: target.bs_instance_id,
        shelf_id: target.shelf_id,
        shelf_name: shelf ? shelf.name : (project.value.shelf_name ?? ''),
      });
    };

    const loadShelves = () => attempt(async () => {
      if (!project.value.profile_id || !target.bs_instance_id) {
        toast.error('Pick a profile and a BookStack instance first.');
        return;
      }
      loadingShelves.value = true;
      try {
        await saveTarget();
        const data = await post(`profiles/${project.value.profile_id}/shelves`, {
          instance_id: target.bs_instance_id,
        });
        shelves.value = data.shelves ?? [];
        toast.success(`${plural(shelves.value.length, 'shelf', 'shelves')} loaded.`);
      } finally {
        loadingShelves.value = false;
      }
    }, 'Load shelves');

    const record = (lines) => {
      log.value = [...(lines ?? []), ...log.value].slice(0, 400);
    };

    const push = (scope, targetId = null) => attempt(async () => {
      running.value = true;
      try {
        const data = await post(`projects/${project.value.id}/push`, {
          scope,
          target_id: targetId,
          force: force.value,
        });
        state.project = data.project;
        record(data.log);
        const resolved = data.links?.updated ?? 0;
        toast.success(resolved
          ? `Published. ${plural(resolved, 'page')} updated with resolved links.`
          : 'Published.');
      } finally {
        running.value = false;
      }
    }, 'Publish');

    const resolveLinks = () => attempt(async () => {
      running.value = true;
      try {
        const data = await post(`projects/${project.value.id}/links`, { force: force.value });
        state.project = data.project;
        record(data.log);
        const { resolved = 0, updated = 0 } = data.links ?? {};
        toast.success(updated
          ? `${plural(resolved, 'link')} resolved, ${plural(updated, 'page')} re-published.`
          : 'Every cross reference is already up to date.');
      } finally {
        running.value = false;
      }
    }, 'Resolve auto links');

    const lineClass = (line) => {
      if (line.startsWith('Created')) return 'log__line--new';
      if (line.startsWith('Auto links') || line.includes('no longer exists')) return 'log__line--warn';
      return '';
    };

    return {
      state, project, target, shelves, loadingShelves, running, log, force,
      stats, links, ready, busy, bookstackInstances,
      loadShelves, saveTarget, push, resolveLinks, lineClass, plural,
    };
  },
  template: `
    <div class="view-scroll">
      <div class="view-pad container grid grid-publish" style="gap:var(--s-5)">

        <!-- left column ------------------------------------------------- -->
        <div class="col gap-4">
          <section class="card">
            <div class="card__head"><span class="card__title grow">Destination</span></div>
            <div class="card__body col gap-4">
              <div class="form-row">
                <label>BookStack instance</label>
                <select v-model="target.bs_instance_id">
                  <option value="">— none —</option>
                  <option v-for="instance in bookstackInstances" :key="instance.id" :value="instance.id">
                    {{ instance.name }}
                  </option>
                </select>
                <p v-if="!bookstackInstances.length" class="hint c-warning">
                  This course's profile has no BookStack instance yet. Add one under Profiles → Accounts.
                </p>
              </div>

              <div class="form-row">
                <label class="row between">
                  <span>Shelf</span>
                  <button class="btn btn--ghost btn--sm" style="padding:0 4px" :disabled="loadingShelves"
                          @click="loadShelves">
                    <app-icon name="refresh" :size="11" :spin="loadingShelves"/> fetch list
                  </button>
                </label>
                <select v-model.number="target.shelf_id">
                  <option :value="null">— no shelf —</option>
                  <option v-if="project.shelf_id && !shelves.length" :value="project.shelf_id">
                    {{ project.shelf_name || ('Shelf #' + project.shelf_id) }}
                  </option>
                  <option v-for="shelf in shelves" :key="shelf.id" :value="shelf.id">{{ shelf.name }}</option>
                </select>
              </div>

              <button class="btn" :disabled="busy" @click="saveTarget">
                <app-icon name="save" :size="14"/> Save destination
              </button>

              <p v-if="project.book_url" class="t-xs">
                Book: <a :href="project.book_url" target="_blank" rel="noopener">
                  #{{ project.book_id }} <app-icon name="external" :size="10"/>
                </a>
              </p>
            </div>
          </section>

          <section class="card">
            <div class="card__head"><span class="card__title grow">Publish</span>
              <span v-if="project.dirty" class="badge badge--warning">book metadata changed</span>
            </div>
            <div class="card__body col gap-4">
              <div class="grid grid-3">
                <div class="stat">
                  <div class="stat__value nums">{{ stats.generated }}/{{ stats.pages }}</div>
                  <div class="stat__label">written</div>
                </div>
                <div class="stat">
                  <div class="stat__value c-success nums">{{ stats.pushed }}</div>
                  <div class="stat__label">published</div>
                </div>
                <div class="stat">
                  <div class="stat__value nums" :class="stats.dirty ? 'c-warning' : ''">{{ stats.dirty }}</div>
                  <div class="stat__label">out of sync</div>
                </div>
              </div>

              <label class="check">
                <input type="checkbox" v-model="force">
                <span>
                  Force overwrite
                  <span class="hint">Re-send every item even when nothing changed.</span>
                </span>
              </label>

              <div class="col gap-2">
                <button class="btn btn--primary btn--block" :disabled="running || !ready" @click="push('all')">
                  <app-icon :name="running ? 'refresh' : 'upload'" :size="15" :spin="running"/>
                  {{ running ? 'Publishing…' : 'Publish everything' }}
                </button>
                <button class="btn btn--block" :disabled="running || !ready" @click="push('book')">
                  Book metadata only
                </button>
              </div>

              <p class="hint">
                Existing books, chapters and pages are updated in place — nothing is ever duplicated.
                Pages without content are skipped, and tags travel along.
              </p>
            </div>
          </section>

          <section class="card" :class="links.markers ? '' : 'card--flat'">
            <div class="card__head">
              <app-icon name="link" :size="16" :class="links.markers ? 'c-accent' : 'dim'"/>
              <span class="card__title grow">Auto links</span>
            </div>
            <div class="card__body col gap-3">
              <div class="row gap-3 t-sm">
                <span class="nums"><strong>{{ links.markers }}</strong> <span class="dim">written</span></span>
                <span class="nums c-success"><strong>{{ links.resolved }}</strong> <span class="dim">resolved</span></span>
                <span class="nums" :class="links.pending ? 'c-warning' : 'dim'">
                  <strong>{{ links.pending }}</strong> <span class="dim">pending</span>
                </span>
              </div>

              <button class="btn btn--block" :disabled="running || !ready || !links.markers" @click="resolveLinks">
                <app-icon name="link" :size="14"/> Resolve auto links now
              </button>

              <p class="hint">
                Runs after every full publish automatically. Use this button when you published in parts,
                renamed something, or want to re-check after adding pages. It never calls the AI.
              </p>
              <p v-if="links.pending" class="hint c-warning">
                {{ plural(links.pending, 'reference') }} still point at content that has not been published yet —
                publish everything once, then they resolve.
              </p>
            </div>
          </section>
        </div>

        <!-- right column ------------------------------------------------ -->
        <section class="card">
          <div class="card__head">
            <span class="card__title grow">Publish log</span>
            <button class="btn btn--ghost btn--sm" :disabled="!log.length" @click="log = []">Clear</button>
          </div>
          <div class="card__body">
            <div v-if="log.length" class="log">
              <div v-for="(line, i) in log" :key="i" class="log__line" :class="lineClass(line)">{{ line }}</div>
            </div>
            <empty-state v-else icon="upload" title="Nothing published in this session"
                         hint="Every created, updated and skipped item is listed here while a publish runs."/>
          </div>
        </section>
      </div>
    </div>`,
};

export default PublishTab;
