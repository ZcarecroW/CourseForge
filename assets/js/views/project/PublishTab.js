/**
 * The Publish tab: where a course goes, what each wiki holds of it, and the
 * record of every push.
 *
 * Three things changed in 5.2 and all three are visible here. A push is a
 * task the scheduler works rather than a request this tab holds open, so the
 * button returns at once and the tab can be closed. Each destination is its
 * own card with its own counts, its own auto links and its own last outcome,
 * beside a card that speaks for all of them. And the log is the server's:
 * what a task said is written down as it is said, and shown here whenever the
 * tab is opened - including the pushes that happened while nobody was looking.
 */
import { ref, reactive, computed, watch, onActivated, onDeactivated, onMounted, onBeforeUnmount } from 'vue';
import { state, openCourse, bookstackInstances, declareUnsaved } from '@/core/store.js';
import { post } from '@/core/api.js';
import { toast, attempt } from '@/core/toast.js';
import { plural, relativeTime, formatDateTime } from '@/core/format.js';
import { busy, saveTargets } from '@/views/project/actions.js';
import {
  tasks, openTasks, schedulerAbsent, loadTasks, stopTasksPolling, queueTask, cancelTask, retryTask, clearTasks,
  taskTone, taskStatusLabel, targetProgress, lastOutcomeFor,
} from '@/views/project/tasks.js';

import AppIcon from '@/components/AppIcon.js';
import AppModal from '@/components/AppModal.js';
import AppSwitch from '@/components/AppSwitch.js';
import EmptyState from '@/components/EmptyState.js';

/** The fields a person edits here, as one comparable string. */
const shapeOf = (list) => JSON.stringify((list ?? []).map((t) => ({
  instance: t.instance_id ?? '',
  shelf: t.shelf_id ?? null,
  name: t.shelf_name ?? '',
  on: t.enabled !== false,
})));

const EMPTY_STATS = { chapters: 0, chapters_dirty: 0, chapters_missing: 0, pages: 0, pages_dirty: 0, pages_missing: 0 };
const EMPTY_LINKS = { markers: 0, resolved: 0, pending: 0 };

export const PublishTab = {
  name: 'PublishTab',
  components: { AppIcon, AppModal, AppSwitch, EmptyState },
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
    const force = ref(false);

    const sync = () => {
      targets.value = (project.value?.targets ?? []).map((t) => ({ ...t }));
      savedShape.value = shapeOf(project.value?.targets);
    };
    watch(() => project.value?.id, sync, { immediate: true });

    const changed = computed(() => shapeOf(targets.value) !== savedShape.value);

    // Leaving the course asks about a destinations list that was not saved.
    declareUnsaved(() => (changed.value ? 'unsaved changes to where this course publishes' : ''));

    /* A push rewrites the book id and URL of every destination it reached, and
       those have to show up here. Adopting the server's list unconditionally
       would take a half-typed shelf choice with it, so it is adopted only while
       nothing has been typed - the same arrangement the Settings tab has. */
    watch(() => project.value?.targets, () => {
      if (!changed.value) sync();
    }, { deep: true });

    /* The tasks and the log live on the server; this tab watches them while it
       is on screen. keep-alive keeps the component around between tabs, so the
       watching starts and stops with activation rather than with mounting. */
    const start = () => attempt(loadTasks, 'Load the publish log');
    onMounted(start);
    onActivated(start);
    onDeactivated(stopTasksPolling);
    onBeforeUnmount(stopTasksPolling);
    watch(() => project.value?.id, (id, was) => { if (id && was && id !== was) start(); });

    const stats = computed(() => project.value?.stats ?? {});
    const links = computed(() => stats.value.links ?? EMPTY_LINKS);
    const live = computed(() => targets.value.filter((t) => t.enabled !== false && t.instance_id));
    const ready = computed(() => live.value.length > 0 && !changed.value);
    const working = computed(() => openTasks.value.length > 0);

    /** The server's own row for a destination, which carries the counts. */
    const serverRow = (row) => (project.value?.targets ?? []).find((t) => t.id === row.id) ?? null;
    const statsOf = (row) => serverRow(row)?.stats ?? EMPTY_STATS;
    const linksOf = (row) => serverRow(row)?.links ?? EMPTY_LINKS;
    const bookOf = (row) => serverRow(row) ?? row;

    /** The instances still free to choose, plus whichever this row already holds. */
    const choicesFor = (row) => {
      const taken = new Set(targets.value.filter((t) => t !== row).map((t) => t.instance_id));
      return bookstackInstances.value.filter((i) => !taken.has(i.id));
    };

    const nameOf = (row) => row.instance_name
      || bookstackInstances.value.find((i) => i.id === row.instance_id)?.name
      || row.instance_id
      || 'no instance';

    const targetName = (targetId) => {
      const row = (project.value?.targets ?? []).find((t) => t.id === targetId);
      return row ? (row.instance_name || row.instance_id) : `destination #${targetId}`;
    };

    /* ------------------------------------------------------- per wiki state */

    /** One word for where a wiki stands, and the tone to draw it in. */
    const standing = (row) => {
      if (!row.id) return { key: 'new', label: 'not saved yet', tone: 'badge--outline', tile: '' };
      if (row.enabled === false) return { key: 'off', label: 'switched off', tone: 'badge--outline', tile: '' };
      if (!row.known) return { key: 'orphan', label: 'instance missing', tone: 'badge--danger', tile: 'tile--danger' };
      const s = statsOf(row);
      const server = serverRow(row);
      const inFlight = openTasks.value.some((task) => ['partial', 'pending'].includes(targetProgress(task, row.id).status)
        && task.status !== 'queued' || (task.status === 'queued' && targetProgress(task, row.id).status !== 'done'));
      if (inFlight) return { key: 'working', label: 'publishing', tone: 'badge--accent', tile: 'tile--accent' };
      const last = lastOutcomeFor(row.id);
      if (last && last.status === 'failed' && last.task && !last.task.terminal) {
        return { key: 'retrying', label: 'retrying', tone: 'badge--warning', tile: 'tile--warning' };
      }
      if (last && last.status === 'failed' && last.task?.status === 'failed') {
        return { key: 'failed', label: 'last push failed', tone: 'badge--danger', tile: 'tile--danger' };
      }
      if (!server?.book_id) return { key: 'never', label: 'never published', tone: 'badge--outline', tile: '' };
      if (server.outstanding) {
        const n = s.pages_dirty + s.pages_missing + s.chapters_dirty;
        return { key: 'behind', label: n ? `${n} to push` : 'book changed', tone: 'badge--warning', tile: 'tile--warning' };
      }
      return { key: 'synced', label: 'up to date', tone: 'badge--success', tile: 'tile--success' };
    };

    /** The sentence under a wiki: what last happened to it. */
    const outcomeOf = (row) => {
      if (!row.id) return null;
      const open = openTasks.value.find((task) => {
        const p = targetProgress(task, row.id);
        return p.status === 'partial' || (task.status === 'running' && p.status === 'pending');
      });
      if (open) {
        const p = targetProgress(open, row.id);
        const total = stats.value.generated ?? 0;
        return {
          tone: 'c-accent', icon: 'spinner', spin: true,
          text: p.status === 'partial' && p.pages
            ? `Publishing: ${p.pages} of ${plural(total, 'page')} written so far`
            : 'Publishing…',
        };
      }
      const last = lastOutcomeFor(row.id);
      if (!last) return null;
      if (last.status === 'failed') {
        const again = last.task && !last.task.terminal;
        return {
          tone: again ? 'c-warning' : 'c-danger', icon: again ? 'rotate-right' : 'x-circle', spin: false,
          text: (again ? 'Failed, trying again ' : 'Failed ') + relativeTime(last.at) + (last.error ? ` - ${last.error}` : ''),
          title: formatDateTime(last.at),
        };
      }
      if (last.status === 'done') {
        return {
          tone: 'c-success', icon: 'check-circle', spin: false,
          text: (last.task.kind === 'resolve_links' ? 'Links resolved ' : 'Published ') + relativeTime(last.at),
          title: formatDateTime(last.at),
        };
      }
      return null;
    };

    /* ------------------------------------------------------------- editing */

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
        stats: { ...EMPTY_STATS },
        links: { ...EMPTY_LINKS },
      });
    };

    /** Taking a destination off the list also forgets the book it made there. */
    const remove = () => {
      const row = removing.value;
      removing.value = null;
      targets.value = targets.value.filter((t) => t !== row);
    };

    /* Re-seeded from the answer rather than left as it was typed - see the
       watcher above, which only adopts the server's list while nothing is
       unsaved. Only on success: a refused save resolves to nothing. */
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
       a row at a different instance therefore has to drop all three, and
       dropping the book is confirmed the same way removing the destination is. */
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
      row.stats = { ...EMPTY_STATS };
      row.links = { ...EMPTY_LINKS };
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
    const cancelSwitch = () => { switching.value = null; };

    const pickShelf = (row, id) => {
      row.shelf_id = id === '' || id === null ? null : Number(id);
      const found = (shelvesFor[row.instance_id] ?? []).find((s) => s.id === row.shelf_id);
      if (found) row.shelf_name = found.name;
      if (row.shelf_id === null) row.shelf_name = '';
    };

    /* ------------------------------------------------------------ the push */

    /** @param {number[]|null} targetIds null publishes to every destination that is on. */
    const publish = (scope, targetIds = null, label = 'Publish') => queueTask({
      kind: 'publish',
      scope,
      target_ids: targetIds,
      force: force.value,
    }, label);

    const resolveLinks = (targetIds = null) => queueTask({
      kind: 'resolve_links',
      target_ids: targetIds,
      force: force.value,
    }, 'Resolve auto links');

    /* ---------------------------------------------------------------- log */

    /** The log, grouped under the task each line belongs to, newest task first. */
    const logGroups = computed(() => {
      const byTask = new Map();
      for (const line of tasks.log) {
        if (!byTask.has(line.task_id)) byTask.set(line.task_id, []);
        byTask.get(line.task_id).push(line);
      }
      const groups = tasks.list.map((task) => ({ task, lines: byTask.get(task.id) ?? [] }));
      // Lines whose task has been forgotten still deserve a place.
      for (const [taskId, lines] of byTask) {
        if (!tasks.list.some((task) => task.id === taskId)) {
          groups.push({ task: { id: taskId, kind: 'publish', kind_label: 'Publish', status: 'done', terminal: true, created_at: lines[0]?.ts ?? 0, created_by: '', attempts: 0, error: '', progress: {} }, lines });
        }
      }
      return groups.sort((a, b) => (b.task.created_at - a.task.created_at) || (b.task.id - a.task.id));
    });

    const lineClass = (line) => ({
      new: 'log__line--new',
      warn: 'log__line--warn',
      error: 'log__line--error',
      done: 'log__line--done',
      links: 'log__line--links',
    }[line.level] ?? '');

    const taskIcon = (task) => {
      if (task.status === 'running') return 'spinner';
      if (task.status === 'queued') return task.attempts ? 'rotate-right' : 'clock';
      if (task.status === 'done') return 'check-circle';
      if (task.status === 'failed') return 'x-circle';
      return 'ban';
    };

    const taskLine = (task) => {
      const who = task.created_by ? ` by ${task.created_by}` : '';
      const when = task.created_at ? ` ${relativeTime(task.created_at)}` : '';
      const tries = task.attempts > 1 || (task.attempts === 1 && !task.terminal) ? ` · attempt ${task.attempts + (task.terminal ? 0 : 1)} of ${task.max_attempts}` : '';
      const scope = task.params?.scope && task.params.scope !== 'all' ? ` · ${task.params.scope}` : '';
      const forced = task.params?.force ? ' · forced' : '';
      return `asked${who}${when}${scope}${forced}${tries}`;
    };

    /** The wikis a task covers, each with where it stands. */
    const taskTargets = (task) => (project.value?.targets ?? [])
      .filter((t) => {
        const wanted = task.params?.target_ids ?? [];
        return wanted.length ? wanted.includes(t.id) : t.enabled !== false;
      })
      .map((t) => ({ ...targetProgress(task, t.id), id: t.id, name: t.instance_name || t.instance_id }));

    const progressTone = (status) => ({
      done: 'badge--success', partial: 'badge--accent', failed: 'badge--danger', pending: 'badge--outline',
    }[status] ?? '');

    const cronLine = computed(() => {
      const c = tasks.cron;
      if (!c.configured) return 'No scheduler is set up, so this tab works a queued task itself while it is open.';
      if (!c.healthy) return `The scheduler last called in ${c.last_at ? relativeTime(c.last_at) : 'never'}, so this tab works a queued task itself while it is open.`;
      return `Worked by the scheduler, which last called in ${relativeTime(c.last_at)}. You can close this tab.`;
    });

    return {
      state, project, targets, shelvesFor, loadingShelves, force, removing, switching,
      stats, links, ready, changed, live, busy, working, bookstackInstances,
      tasks, openTasks, schedulerAbsent, cronLine,
      choicesFor, nameOf, targetName, statsOf, linksOf, bookOf, standing, outcomeOf,
      add, remove, save, loadShelves, pickInstance, pickShelf, confirmSwitch, cancelSwitch,
      publish, resolveLinks, cancelTask, retryTask, clearTasks,
      logGroups, lineClass, taskIcon, taskLine, taskTargets, progressTone, taskTone, taskStatusLabel,
      plural, relativeTime, formatDateTime,
    };
  },
  template: `
    <div class="view-scroll">
      <div class="view-pad container col gap-4">

        <div v-if="schedulerAbsent && working" class="note-strip note-strip--warning" data-tour="publish-scheduler">
          <app-icon :name="tasks.pumping ? 'spinner' : 'alert'" :size="15" class="c-warning" :spin="tasks.pumping"/>
          <span class="grow">
            <strong>{{ tasks.pumping ? 'This tab is doing the publishing.' : 'No scheduler is calling in.' }}</strong>
            {{ cronLine }} Set the scheduler up under Administration › Settings and a push carries on with the tab closed.
          </span>
        </div>

        <div class="grid grid-publish" style="gap:var(--s-5)">

          <!-- left column ------------------------------------------------- -->
          <div class="col gap-4">

            <!-- every destination at once ---------------------------------- -->
            <section class="card" data-tour="publish-all">
              <div class="card__head">
                <span class="tile tile--success"><app-icon name="upload" :size="17"/></span>
                <div class="card__heading">
                  <span class="card__title">All destinations</span>
                  <span class="card__desc">The course as a whole, across every wiki that is switched on.</span>
                </div>
                <span v-if="changed" class="badge badge--warning none">unsaved</span>
                <span v-else-if="live.length > 1" class="badge none">{{ live.length }} wikis</span>
                <span v-if="project.dirty" class="badge badge--warning none">book changed</span>
              </div>
              <div class="card__body col gap-4">
                <div class="grid grid-3">
                  <div class="stat">
                    <div class="stat__value nums"><app-icon name="file-text" :size="14" class="dim"/> {{ stats.generated }}/{{ stats.pages }}</div>
                    <div class="stat__label">written</div>
                  </div>
                  <div class="stat">
                    <div class="stat__value c-success nums"><app-icon name="check-circle" :size="14"/> {{ stats.pushed }}</div>
                    <div class="stat__label">published everywhere</div>
                  </div>
                  <div class="stat">
                    <div class="stat__value nums" :class="stats.dirty ? 'c-warning' : ''"><app-icon name="alert-circle" :size="14"/> {{ stats.dirty }}</div>
                    <div class="stat__label">out of sync somewhere</div>
                  </div>
                </div>

                <div class="row wrap gap-3 t-sm">
                  <span class="row gap-1 dim"><app-icon name="link" :size="13"/> Auto links</span>
                  <span class="nums"><strong>{{ links.markers }}</strong> <span class="dim">written</span></span>
                  <span class="nums c-success"><strong>{{ links.resolved }}</strong> <span class="dim">resolve</span></span>
                  <span class="nums" :class="links.pending ? 'c-warning' : 'dim'"><strong>{{ links.pending }}</strong> <span class="dim">pending</span></span>
                </div>

                <label class="check">
                  <input type="checkbox" v-model="force">
                  <span>
                    Force overwrite
                    <span class="hint">Re-send every item even when nothing changed - also what repairs a page somebody edited in BookStack by hand.</span>
                  </span>
                </label>

                <div class="col gap-2">
                  <button class="btn btn--primary btn--block" :disabled="!ready || busy" @click="publish('all', null, 'Publish')">
                    <app-icon name="upload" :size="15"/>
                    {{ live.length > 1 ? 'Publish everything to ' + live.length + ' wikis' : 'Publish everything' }}
                  </button>
                  <div class="grid grid-2" style="gap:var(--s-2)">
                    <button class="btn btn--block" :disabled="!ready || busy" @click="publish('book', null, 'Publish the book')">
                      <app-icon name="book-open" :size="14"/> Book metadata only
                    </button>
                    <button class="btn btn--block" :disabled="!ready || busy || !links.markers" @click="resolveLinks(null)">
                      <app-icon name="link" :size="14"/> Resolve auto links
                    </button>
                  </div>
                </div>

                <p v-if="changed" class="hint c-warning">
                  Save the destinations first - a push goes where the server has them, not where this form does.
                </p>
                <p class="hint">
                  A push is queued and worked by the scheduler, so you can close this tab. Books, chapters and pages
                  are updated in place and never duplicated; a wiki that stops answering is tried again from the
                  page it stopped at, and a wiki that cannot be reached does not stop the others.
                </p>
              </div>
            </section>

            <!-- one card per wiki ------------------------------------------ -->
            <section v-for="(row, i) in targets" :key="row.id || 'new-' + i" class="card destination"
                     :class="{ 'is-off': row.enabled === false }" data-tour="publish-destination">
              <div class="card__head">
                <span class="tile" :class="standing(row).tile"><app-icon name="server" :size="17"/></span>
                <div class="card__heading">
                  <span class="card__title row gap-2">
                    <span class="truncate">{{ nameOf(row) }}</span>
                    <a v-if="row.base_url" :href="row.base_url" target="_blank" rel="noopener" class="btn btn--ghost btn--sm btn--icon"
                       :aria-label="'Open ' + nameOf(row)" title="Open this BookStack">
                      <app-icon name="external" :size="11"/>
                    </a>
                  </span>
                  <span class="card__desc truncate">{{ row.base_url || 'No address - pick an instance below' }}</span>
                </div>
                <span class="badge none" :class="standing(row).tone">{{ standing(row).label }}</span>
                <app-switch :model-value="row.enabled !== false" :label="'Publish to ' + nameOf(row)"
                            @update:model-value="row.enabled = $event"/>
                <button class="btn btn--ghost btn--sm btn--icon none" :disabled="busy" @click="removing = row"
                        :aria-label="'Remove ' + nameOf(row) + ' as a destination'" title="Remove this destination">
                  <app-icon name="trash" :size="13"/>
                </button>
              </div>

              <div class="card__body col gap-3">
                <p v-if="!row.known && row.id" class="note-strip note-strip--danger">
                  <app-icon name="alert" :size="14" class="c-danger"/>
                  <span>This instance is no longer in the course's profile, so there are no credentials for it. Add it back under Profiles → Accounts, or remove the destination.</span>
                </p>

                <template v-if="row.id">
                  <div class="facts">
                    <div class="fact">
                      <app-icon name="book" :size="15" class="dim"/>
                      <div class="fact__text">
                        <div class="fact__label">Book</div>
                        <div class="fact__value">
                          <a v-if="bookOf(row).book_url" :href="bookOf(row).book_url" target="_blank" rel="noopener">#{{ bookOf(row).book_id }}</a>
                          <span v-else class="dim">none yet</span>
                        </div>
                      </div>
                    </div>
                    <div class="fact">
                      <app-icon name="archive" :size="15" class="dim"/>
                      <div class="fact__text">
                        <div class="fact__label">Shelf</div>
                        <div class="fact__value" :class="row.shelf_id ? '' : 'dim'">{{ row.shelf_name || (row.shelf_id ? '#' + row.shelf_id : 'none') }}</div>
                      </div>
                    </div>
                    <div class="fact">
                      <app-icon name="check-circle" :size="15" class="c-success"/>
                      <div class="fact__text">
                        <div class="fact__label">Pages published</div>
                        <div class="fact__value nums">{{ statsOf(row).pages }} <span class="dim">of {{ stats.generated }}</span></div>
                      </div>
                    </div>
                    <div class="fact">
                      <app-icon name="alert-circle" :size="15" :class="statsOf(row).pages_dirty ? 'c-warning' : 'dim'"/>
                      <div class="fact__text">
                        <div class="fact__label">Out of sync</div>
                        <div class="fact__value nums" :class="statsOf(row).pages_dirty ? 'c-warning' : ''">
                          {{ statsOf(row).pages_dirty }}<span v-if="statsOf(row).chapters_dirty" class="dim"> + {{ plural(statsOf(row).chapters_dirty, 'chapter') }}</span>
                        </div>
                      </div>
                    </div>
                    <div class="fact">
                      <app-icon name="circle-dashed" :size="15" :class="statsOf(row).pages_missing ? 'c-warning' : 'dim'"/>
                      <div class="fact__text">
                        <div class="fact__label">Never published</div>
                        <div class="fact__value nums" :class="statsOf(row).pages_missing ? 'c-warning' : ''">{{ statsOf(row).pages_missing }}</div>
                      </div>
                    </div>
                    <div class="fact">
                      <app-icon name="link" :size="15" :class="linksOf(row).pending ? 'c-warning' : 'dim'"/>
                      <div class="fact__text">
                        <div class="fact__label">Auto links</div>
                        <div class="fact__value nums">
                          <span class="c-success">{{ linksOf(row).resolved }}</span>
                          <span class="dim"> of {{ linksOf(row).markers }}</span>
                          <span v-if="linksOf(row).pending" class="c-warning"> · {{ linksOf(row).pending }} pending</span>
                        </div>
                      </div>
                    </div>
                  </div>

                  <p v-if="outcomeOf(row)" class="row gap-2 t-xs" :class="outcomeOf(row).tone" :title="outcomeOf(row).title || ''">
                    <app-icon :name="outcomeOf(row).icon" :size="13" :spin="outcomeOf(row).spin" class="none"/>
                    <span>{{ outcomeOf(row).text }}</span>
                  </p>
                </template>
                <p v-else class="hint c-warning">Save the destinations to give this wiki a place in the list; it can be published to after that.</p>

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

                <div class="row wrap gap-2">
                  <button class="btn btn--sm" :disabled="busy || changed || row.enabled === false || !row.id || !row.known"
                          @click="publish('all', [row.id], 'Publish to ' + nameOf(row))">
                    <app-icon name="upload" :size="13"/> Publish here
                  </button>
                  <button class="btn btn--sm" :disabled="busy || changed || row.enabled === false || !row.id || !row.known"
                          @click="publish('book', [row.id], 'Publish the book to ' + nameOf(row))">
                    <app-icon name="book-open" :size="13"/> Book only
                  </button>
                  <button class="btn btn--sm" :disabled="busy || changed || row.enabled === false || !row.id || !row.known || !linksOf(row).markers"
                          @click="resolveLinks([row.id])">
                    <app-icon name="link" :size="13"/> Resolve links here
                  </button>
                </div>
              </div>
            </section>

            <empty-state v-if="!targets.length" icon="upload" title="This course publishes nowhere yet"
                         :hint="bookstackInstances.length ? 'Add a destination to say where it goes.' : 'This course\\'s profile has no BookStack instance yet. Add one under Profiles → Accounts, then add it here.'"/>

            <div class="row gap-2">
              <button class="btn grow" :disabled="busy" @click="add">
                <app-icon name="plus" :size="14"/> Add destination
              </button>
              <button class="btn btn--primary" :disabled="busy || !changed" @click="save">
                <app-icon name="save" :size="14"/> Save destinations
              </button>
            </div>
            <p class="hint">
              Every destination holds its own book, and a page's cross references point inside the wiki it is in.
              A destination switched off keeps everything it already has on record and is left out of a push.
            </p>
          </div>

          <!-- right column: the tasks and the record ---------------------- -->
          <section class="card" data-tour="publish-log">
            <div class="card__head">
              <span class="tile"><app-icon name="history" :size="17"/></span>
              <div class="card__heading">
                <span class="card__title">Publish log</span>
                <span class="card__desc">Every push, kept on the server - including the ones that ran while this tab was closed.</span>
              </div>
              <span v-if="tasks.polling" class="badge badge--accent none"><app-icon name="spinner" :size="10" spin/> live</span>
              <button class="btn btn--ghost btn--sm none" :disabled="!logGroups.length || working" @click="clearTasks"
                      title="Forgets the finished tasks and their lines. A task still running stays.">
                <app-icon name="trash" :size="12"/> Clear
              </button>
            </div>

            <div class="card__body col gap-3">
              <p class="row gap-2 t-xs" :class="schedulerAbsent ? 'c-warning' : 'dim'">
                <app-icon :name="schedulerAbsent ? 'alert' : 'clock'" :size="13" class="none"/>
                <span>{{ cronLine }}</span>
              </p>

              <div v-if="logGroups.length" class="col gap-3 task-log">
                <div v-for="group in logGroups" :key="group.task.id" class="task-entry" :class="'task-entry--' + group.task.status">
                  <div class="task-entry__head">
                    <app-icon :name="taskIcon(group.task)" :size="14" :spin="group.task.status === 'running'" class="task-entry__icon"/>
                    <div class="col" style="gap:2px;min-width:0" class="grow">
                      <div class="row wrap gap-2">
                        <span class="strong t-sm">{{ group.task.kind_label }}</span>
                        <span class="badge" :class="taskTone(group.task)">{{ taskStatusLabel(group.task) }}</span>
                        <span v-if="group.task.status === 'queued' && group.task.next_at > Math.floor(Date.now() / 1000)" class="t-2xs dim">
                          next try {{ relativeTime(group.task.next_at).replace(' ago', '') === 'just now' ? 'in a moment' : 'at ' + formatDateTime(group.task.next_at) }}
                        </span>
                      </div>
                      <span class="t-2xs dim">{{ taskLine(group.task) }}</span>
                      <div v-if="!group.task.terminal || group.task.status === 'failed'" class="row wrap gap-1 mt-1">
                        <span v-for="t in taskTargets(group.task)" :key="t.id" class="badge" :class="progressTone(t.status)"
                              :title="t.error || t.label">
                          {{ t.name }}: {{ t.label }}<template v-if="t.status === 'partial' && t.pages"> ({{ t.pages }} pages)</template>
                        </span>
                      </div>
                      <p v-if="group.task.error" class="t-xs c-danger mt-1">{{ group.task.error }}</p>
                    </div>
                    <div class="row gap-1 none">
                      <button v-if="!group.task.terminal" class="btn btn--ghost btn--sm" @click="cancelTask(group.task.id)" title="Stop this task. What is already written stays.">
                        <app-icon name="ban" :size="12"/> Stop
                      </button>
                      <button v-if="group.task.status === 'failed' || group.task.status === 'canceled'" class="btn btn--ghost btn--sm"
                              @click="retryTask(group.task.id)" title="Queue it again. It carries on from where it stopped.">
                        <app-icon name="rotate-right" :size="12"/> Retry
                      </button>
                    </div>
                  </div>
                  <div v-if="group.lines.length" class="log task-entry__lines">
                    <div v-for="line in group.lines" :key="line.id" class="log__line" :class="lineClass(line)">
                      <span class="log__stamp" :title="formatDateTime(line.ts)">{{ new Date(line.ts * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}</span>
                      <span v-if="line.target_id" class="log__wiki">{{ targetName(line.target_id) }}</span>
                      {{ line.line }}
                    </div>
                  </div>
                </div>
              </div>

              <empty-state v-else icon="upload" title="Nothing published yet"
                           hint="Every task - a push, a link pass - is listed here with everything it said, and stays here after the browser is closed."/>
            </div>
          </section>
        </div>
      </div>
    </div>

    <app-modal v-if="removing" title="Remove this destination?" icon="alert" @close="removing = null">
      <p class="t-sm">
        <strong>{{ nameOf(removing) }}</strong> stops being a destination of this course, and CourseForge forgets
        <template v-if="removing.book_id">the book #{{ removing.book_id }} it made there and</template>
        everything it published into it.
      </p>
      <p class="t-sm dim">
        Nothing is deleted in BookStack - what is there stays there. But publishing to this instance again later
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
