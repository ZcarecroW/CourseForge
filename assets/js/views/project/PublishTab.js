import { ref, reactive, computed, watch } from 'vue';
import { state, openCourse, bookstackInstances } from '@/core/store.js';
import { post } from '@/core/api.js';
import { toast, attempt } from '@/core/toast.js';
import { plural } from '@/core/format.js';
import { busy, saveTargets } from '@/views/project/actions.js';

import AppIcon from '@/components/AppIcon.js';
import AppModal from '@/components/AppModal.js';
import EmptyState from '@/components/EmptyState.js';

/** The fields a person edits here, as one comparable string. */
const shapeOf = (list) => JSON.stringify((list ?? []).map((t) => ({
  instance: t.instance_id ?? '',
  shelf: t.shelf_id ?? null,
  name: t.shelf_name ?? '',
  on: t.enabled !== false,
})));

export const PublishTab = {
  name: 'PublishTab',
  components: { AppIcon, AppModal, EmptyState },
  setup() {
    const project = openCourse;

    /* The working copy of the destination list. A course may publish into
       several BookStack instances at once, each with its own shelf and its own
       book, and the whole list is written in one request - the order is part of
       what it means, because the first entry is the one the course reports as
       its book everywhere a single answer is wanted. */
    const targets = ref([]);
    const savedShape = ref('[]');
    const shelvesFor = reactive({});
    const loadingShelves = ref('');
    const removing = ref(null);
    const switching = ref(null);
    const running = ref(false);
    const log = ref([]);
    const force = ref(false);

    const sync = () => {
      targets.value = (project.value?.targets ?? []).map((t) => ({ ...t }));
      savedShape.value = shapeOf(project.value?.targets);
    };
    watch(() => project.value?.id, sync, { immediate: true });

    const changed = computed(() => shapeOf(targets.value) !== savedShape.value);

    /* A push rewrites the book id and URL of every destination it reached, and
       those have to show up here. Adopting the server's list unconditionally
       would take a half-typed shelf choice with it, so it is adopted only while
       nothing has been typed - the same arrangement the Settings tab has. */
    watch(() => project.value?.targets, () => {
      if (!changed.value) sync();
    }, { deep: true });

    const stats = computed(() => project.value?.stats ?? {});
    const links = computed(() => stats.value.links ?? { markers: 0, resolved: 0, pending: 0 });
    const live = computed(() => targets.value.filter((t) => t.enabled !== false && t.instance_id));
    const ready = computed(() => live.value.length > 0 && !changed.value);

    /** The instances still free to choose, plus whichever this row already holds. */
    const choicesFor = (row) => {
      const taken = new Set(targets.value.filter((t) => t !== row).map((t) => t.instance_id));
      return bookstackInstances.value.filter((i) => !taken.has(i.id));
    };

    const nameOf = (row) => row.instance_name
      || bookstackInstances.value.find((i) => i.id === row.instance_id)?.name
      || row.instance_id
      || 'no instance';

    const add = () => {
      const free = bookstackInstances.value.filter(
        (i) => !targets.value.some((t) => t.instance_id === i.id)
      );
      if (!free.length) {
        toast.error(bookstackInstances.value.length
          ? 'Every BookStack instance of this profile is already a destination of this course.'
          : "This course's profile has no BookStack instance yet. Add one under Profiles → Accounts.");
        return;
      }
      targets.value.push({
        id: 0,
        instance_id: free[0].id,
        instance_name: free[0].name,
        base_url: free[0].base_url ?? '',
        known: true,
        enabled: true,
        shelf_id: null,
        shelf_name: '',
        book_id: null,
        book_url: '',
        dirty: false,
        stats: { chapters: 0, chapters_dirty: 0, pages: 0, pages_dirty: 0 },
      });
    };

    /** Taking a destination off the list also forgets the book it made there. */
    const remove = () => {
      const row = removing.value;
      removing.value = null;
      targets.value = targets.value.filter((t) => t !== row);
    };

    /* Re-seeded from the answer rather than left as it was typed. The watcher
       below only adopts the server's list while nothing is unsaved, and after a
       save something still is by its own reckoning - a row just added has no id
       yet, and the shape it is compared against is the one from before the
       save. Without this the form stayed "unsaved" over a list that had been
       saved, and every button that waits for a saved list stayed disabled.

       Only on success, though. A refused save resolves to nothing - that is
       what attempt() does with an error it has already reported - and
       re-seeding then would throw away the edit the person now has to correct,
       leaving them looking at the list they were trying to change and a toast
       explaining why they could not. */
    const save = async () => {
      const saved = await saveTargets(targets.value.map((t) => ({
        instance_id: t.instance_id,
        shelf_id: t.shelf_id ?? null,
        shelf_name: t.shelf_name ?? '',
        enabled: t.enabled !== false,
      })));
      if (saved) sync();
    };

    const loadShelves = (row) => attempt(async () => {
      if (!project.value.profile_id || !row.instance_id) {
        toast.error('Pick a BookStack instance for this destination first.');
        return;
      }
      loadingShelves.value = row.instance_id;
      try {
        const data = await post(`profiles/${project.value.profile_id}/shelves`, {
          instance_id: row.instance_id,
        });
        shelvesFor[row.instance_id] = data.shelves ?? [];
        toast.success(`${plural(shelvesFor[row.instance_id].length, 'shelf', 'shelves')} loaded from ${nameOf(row)}.`);
      } finally {
        loadingShelves.value = '';
      }
    }, 'Load shelves');

    /* A shelf belongs to one BookStack, and so does a name and a book. Pointing
       a row at a different instance therefore has to drop all three, or the row
       would go on showing the old wiki's name and book over a shelf id that
       means something else entirely in the new one — and the save would send
       it.

       Dropping the book is the same loss that removing the destination is, and
       is confirmed the same way: what the row pointed at stops being a
       destination, and CourseForge forgets what it published there. A row that
       has published nothing has nothing to lose and changes straight away. */
    const applyInstance = (row, instanceId) => {
      const instance = bookstackInstances.value.find((i) => i.id === instanceId);
      row.instance_id = instanceId;
      row.instance_name = instance ? instance.name : instanceId;
      row.base_url = instance ? (instance.base_url ?? '') : '';
      row.known = !!instance;
      row.shelf_id = null;
      row.shelf_name = '';
      row.book_id = null;
      row.book_url = '';
      row.dirty = false;
      row.stats = { chapters: 0, chapters_dirty: 0, pages: 0, pages_dirty: 0 };
    };

    const pickInstance = (row, instanceId) => {
      if (row.instance_id === instanceId) return;
      if (row.book_id) {
        switching.value = { row, was: row.instance_id, wasName: nameOf(row), to: instanceId };
        return;
      }
      applyInstance(row, instanceId);
    };

    const confirmSwitch = () => {
      const { row, to } = switching.value;
      switching.value = null;
      applyInstance(row, to);
    };

    /* The select is bound to the row, so a refused switch redraws itself back
       to what the row still says — nothing has to put the old value back. */
    const cancelSwitch = () => { switching.value = null; };

    const pickShelf = (row, id) => {
      row.shelf_id = id === '' || id === null ? null : Number(id);
      const found = (shelvesFor[row.instance_id] ?? []).find((s) => s.id === row.shelf_id);
      if (found) row.shelf_name = found.name;
      if (row.shelf_id === null) row.shelf_name = '';
    };

    const record = (lines) => {
      log.value = [...(lines ?? []), ...log.value].slice(0, 400);
    };

    /** @param {number[]|null} targetIds null publishes to every destination that is on. */
    const push = (scope, targetId = null, targetIds = null) => attempt(async () => {
      running.value = true;
      try {
        const data = await post(`projects/${project.value.id}/push`, {
          scope,
          target_id: targetId,
          target_ids: targetIds,
          force: force.value,
        });
        state.project = data.project;
        record(data.log);
        const failed = data.failed ?? 0;
        const resolved = data.links?.updated ?? 0;
        if (failed) {
          toast.error(`${plural(failed, 'destination')} could not be published to — see the log.`);
        } else {
          toast.success(resolved
            ? `Published. ${plural(resolved, 'page')} updated with resolved links.`
            : 'Published.');
        }
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
      if (line.includes('Failed:')) return 'log__line--error';
      if (/^(\[[^\]]*\] )?Created/.test(line)) return 'log__line--new';
      if (line.includes('Auto links') || line.includes('no longer exists')) return 'log__line--warn';
      return '';
    };

    return {
      state, project, targets, shelvesFor, loadingShelves, running, log, force, removing, switching,
      stats, links, ready, changed, live, busy, bookstackInstances,
      choicesFor, nameOf, add, remove, save, loadShelves, pickInstance, pickShelf,
      confirmSwitch, cancelSwitch, push, resolveLinks, lineClass, plural,
    };
  },
  template: `
    <div class="view-scroll">
      <div class="view-pad container grid grid-publish" style="gap:var(--s-5)">

        <!-- left column ------------------------------------------------- -->
        <div class="col gap-4">
          <section class="card">
            <div class="card__head">
              <span class="card__title grow">Destinations</span>
              <span v-if="changed" class="badge badge--warning">unsaved</span>
              <span v-else-if="targets.length > 1" class="badge">{{ targets.length }} wikis</span>
            </div>
            <div class="card__body col gap-4">
              <p v-if="!bookstackInstances.length" class="hint c-warning">
                This course's profile has no BookStack instance yet. Add one under Profiles → Accounts.
              </p>

              <div v-for="(row, i) in targets" :key="i" class="col gap-3 target-row">
                <div class="row between gap-2">
                  <label class="check grow">
                    <input type="checkbox" v-model="row.enabled">
                    <span>
                      {{ nameOf(row) }}
                      <span class="hint">
                        <template v-if="!row.known && row.id">
                          This instance is no longer in the profile — publishing here will fail.
                        </template>
                        <template v-else-if="row.book_id">
                          Book #{{ row.book_id }} · {{ row.stats.pages }} page(s) published<template
                            v-if="row.stats.pages_dirty">, {{ row.stats.pages_dirty }} out of sync</template>
                        </template>
                        <template v-else>Nothing published here yet.</template>
                      </span>
                    </span>
                  </label>
                  <a v-if="row.book_url" :href="row.book_url" target="_blank" rel="noopener" class="btn btn--ghost btn--sm">
                    <app-icon name="external" :size="12"/>
                  </a>
                  <button class="btn btn--ghost btn--sm" :disabled="busy" @click="removing = row">
                    <app-icon name="trash" :size="12"/>
                  </button>
                </div>

                <div class="grid grid-2">
                  <div class="form-row">
                    <label>BookStack instance</label>
                    <select :value="row.instance_id" @change="pickInstance(row, $event.target.value)">
                      <option v-if="!row.known && row.instance_id" :value="row.instance_id">
                        {{ row.instance_id }} (missing)
                      </option>
                      <option v-for="instance in choicesFor(row)" :key="instance.id" :value="instance.id">
                        {{ instance.name }}
                      </option>
                    </select>
                  </div>
                  <div class="form-row">
                    <label class="row between">
                      <span>Shelf</span>
                      <button class="btn btn--ghost btn--sm" style="padding:0 4px"
                              :disabled="loadingShelves === row.instance_id" @click="loadShelves(row)">
                        <app-icon name="refresh" :size="11" :spin="loadingShelves === row.instance_id"/> fetch list
                      </button>
                    </label>
                    <select :value="row.shelf_id === null ? '' : row.shelf_id"
                            @change="pickShelf(row, $event.target.value)">
                      <option value="">— no shelf —</option>
                      <option v-if="row.shelf_id && !(shelvesFor[row.instance_id] || []).length" :value="row.shelf_id">
                        {{ row.shelf_name || ('Shelf #' + row.shelf_id) }}
                      </option>
                      <option v-for="shelf in (shelvesFor[row.instance_id] || [])" :key="shelf.id" :value="shelf.id">
                        {{ shelf.name }}
                      </option>
                    </select>
                  </div>
                </div>

                <button v-if="targets.length > 1" class="btn btn--sm"
                        :disabled="running || changed || row.enabled === false || !row.id"
                        @click="push('all', null, [row.id])">
                  <app-icon name="upload" :size="13"/> Publish to {{ nameOf(row) }} only
                </button>
              </div>

              <empty-state v-if="!targets.length" icon="upload" title="This course publishes nowhere yet"
                           hint="Add a BookStack instance below to say where it goes."/>

              <div class="row gap-2">
                <button class="btn grow" :disabled="busy" @click="add">
                  <app-icon name="plus" :size="14"/> Add destination
                </button>
                <button class="btn btn--primary" :disabled="busy || !changed" @click="save">
                  <app-icon name="save" :size="14"/> Save
                </button>
              </div>
              <p class="hint">
                Every destination holds its own book, and a page's cross references point inside the wiki it is in.
                A destination switched off keeps everything it already has on record and is left out of a push.
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
                  {{ running ? 'Publishing…' : (live.length > 1 ? 'Publish everything to ' + live.length + ' wikis' : 'Publish everything') }}
                </button>
                <button class="btn btn--block" :disabled="running || !ready" @click="push('book')">
                  Book metadata only
                </button>
              </div>

              <p v-if="changed" class="hint c-warning">
                Save the destinations first — a push goes where the server has them, not where this form does.
              </p>
              <p class="hint">
                Existing books, chapters and pages are updated in place — nothing is ever duplicated.
                Pages without content are skipped, and tags travel along. A wiki that cannot be reached
                does not stop the others: the failure is in the log and the rest still go out.
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
    </div>

    <app-modal v-if="removing" title="Remove this destination?" icon="alert" @close="removing = null">
      <p class="t-sm">
        <strong>{{ nameOf(removing) }}</strong> stops being a destination of this course, and CourseForge forgets
        <template v-if="removing.book_id">the book #{{ removing.book_id }} it made there and</template>
        everything it published into it.
      </p>
      <p class="t-sm dim">
        Nothing is deleted in BookStack — what is there stays there. But publishing to this instance again later
        would create a second book beside the first. To stop publishing without losing the record, switch the
        destination off instead.
      </p>
      <template #footer>
        <button class="btn" @click="removing = null">Cancel</button>
        <button class="btn btn--danger" @click="remove"><app-icon name="trash" :size="14"/> Remove</button>
      </template>
    </app-modal>

    <app-modal v-if="switching" title="Point this destination somewhere else?" icon="alert" @close="cancelSwitch">
      <p class="t-sm">
        This course stops publishing to <strong>{{ switching.wasName }}</strong>, and CourseForge forgets the
        book #{{ switching.row.book_id }} it made there and everything it published into it.
      </p>
      <p class="t-sm dim">
        Nothing is deleted in BookStack. To publish to both wikis instead, cancel and use
        <strong>Add destination</strong>.
      </p>
      <template #footer>
        <button class="btn" @click="cancelSwitch">Cancel</button>
        <button class="btn btn--danger" @click="confirmSwitch">Change it</button>
      </template>
    </app-modal>`,
};

export default PublishTab;
