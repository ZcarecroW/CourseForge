/**
 * Generation runs, from the Content tab's point of view.
 *
 * A run is the opposite of the tab's own generator in one important way: it
 * survives being ignored. The in-tab generator holds one HTTP request open per
 * page and dies with the window; a run is written down on the server and worked
 * either by the provider's batch queue or by CourseForge's own scheduler. So
 * nothing here sets `state.generating`, and closing the course mid-run is
 * allowed and expected.
 *
 * Polling is therefore a convenience, not the mechanism. It keeps an open tab
 * current; cron.php does the same job for the hours in between.
 */
import { reactive, computed } from 'vue';
import { openCourse, applyProject } from '@/core/store.js';
import { get, post, put, del } from '@/core/api.js';
import { toast, attempt } from '@/core/toast.js';
import { plural } from '@/core/format.js';

const EMPTY_CAPABILITY = {
  mode: 'live',
  batched: false,
  batch_available: false,
  background_available: false,
  model: '',
  provider: '',
  label: '',
  reason: '',
  poll_seconds: 60,
};

const EMPTY_CRON = { configured: false, last_at: 0, seconds_ago: 0, healthy: false };

export const runs = reactive({
  loaded: false,
  busy: false,
  polling: false,
  jobs: [],
  capability: { ...EMPTY_CAPABILITY },
  cron: { ...EMPTY_CRON },
});

let timer = null;
/** Which course the state describes, so another course never sees it. */
let loadedFor = 0;
/** False between unmount and the next mount, so a late poll cannot re-arm the timer. */
let live = false;

export const openRuns = computed(() => runs.jobs.filter((job) => !job.terminal));
export const doneRuns = computed(() => runs.jobs.filter((job) => job.terminal));

/** True when a background run would sit in the queue with nobody to write it. */
export const cronStalled = computed(() =>
  openRuns.value.some((job) => job.mode === 'live') && !runs.cron.healthy
);

const projectId = () => openCourse.value.id;

function apply(payload) {
  if (Array.isArray(payload?.runs)) runs.jobs = payload.runs;
  if (payload?.capability) runs.capability = payload.capability;
  if (payload?.cron) runs.cron = payload.cron;
  applyProject(payload);
  return payload;
}

/* ------------------------------------------------------------------ loading */

export function resetRuns() {
  stopPolling();
  runs.jobs = [];
  runs.loaded = false;
  runs.busy = false;
  runs.capability = { ...EMPTY_CAPABILITY };
  runs.cron = { ...EMPTY_CRON };
  loadedFor = 0;
}

export async function loadRuns() {
  const id = projectId();
  if (!id) return;

  // The state is a module singleton but the runs are per course, so the old
  // course's cards must go before the new course's request is even sent -
  // otherwise a slow or failing load leaves live Stop buttons on screen that
  // belong to somebody else's run.
  if (loadedFor !== id) resetRuns();

  live = true;
  const payload = await get(`projects/${id}/runs`);
  if (projectId() !== id) return;

  apply(payload);
  loadedFor = id;
  runs.loaded = true;
  syncTimer();
}

/* ------------------------------------------------------------------ polling */

async function pollOnce() {
  if (runs.busy || !projectId() || !openRuns.value.length) return;
  runs.busy = true;
  try {
    // Remember whether each run had already finished. Announcing "finished"
    // from `job.terminal` alone would re-announce every completed run on every
    // poll, for as long as one is still open.
    const before = new Map(runs.jobs.map((job) => [job.id, { written: job.pages.written, terminal: job.terminal }]));

    const id = projectId();
    const payload = await put(`projects/${id}/runs`, {});
    if (projectId() !== id) return;

    apply(payload);

    for (const job of runs.jobs) {
      const previous = before.get(job.id);
      if (!previous) continue;
      if (job.terminal && !previous.terminal) {
        toast.success(`Run finished: ${plural(job.pages.written, 'page')} written`
          + (job.pages.failed ? `, ${job.pages.failed} failed.` : '.'));
      } else if (job.pages.written > previous.written) {
        toast.info(`${plural(job.pages.written - previous.written, 'page')} written.`);
      }
    }
  } catch (error) {
    // A poll failing is not worth interrupting the user over; the next one
    // usually succeeds, and a real problem shows up on the run's own error.
    console.warn('[CourseForge] run poll failed:', error.message);
  } finally {
    runs.busy = false;
    syncTimer();
  }
}

/** Runs the timer exactly while there is something to wait for. */
function syncTimer() {
  const wanted = live && openRuns.value.length > 0;
  if (wanted && timer === null) {
    // A background run produces a page every minute or so, a batch run once a
    // day; the faster of the two sets the pace while either is open.
    const background = openRuns.value.some((job) => job.mode === 'live');
    const seconds = background ? 20 : Math.max(15, runs.capability.poll_seconds || 60);
    timer = setInterval(pollOnce, seconds * 1000);
    runs.polling = true;
  } else if (!wanted && timer !== null) {
    clearInterval(timer);
    timer = null;
    runs.polling = false;
  }
}

export function stopPolling() {
  live = false;
  if (timer !== null) clearInterval(timer);
  timer = null;
  runs.polling = false;
}

export const pollNow = () => attempt(pollOnce, 'Check the run');

/* ------------------------------------------------------------------ actions */

/**
 * Starts a run.
 *
 * @param {'missing'|'all'|'errors'|number[]} selection
 * @param {'live'|'batch'|''} mode  '' lets the model decide
 */
export const startRun = (selection, mode = '') => attempt(async () => {
  runs.busy = true;
  try {
    const body = Array.isArray(selection) ? { pages: selection } : { select: selection };
    if (mode) body.mode = mode;

    const payload = await post(`projects/${projectId()}/runs`, body);
    apply(payload);

    const queued = payload.run?.pages.total ?? 0;
    const batched = payload.run?.mode === 'batch';
    toast.success(batched
      ? `${plural(queued, 'page')} queued. They arrive within 24 hours; you can close the tab.`
      : `${plural(queued, 'page')} queued. They are written in the background; you can close the tab.`);
    syncTimer();
  } finally {
    runs.busy = false;
  }
}, 'Start run');

export const cancelRun = (id) => attempt(async () => {
  runs.busy = true;
  try {
    apply(await post(`projects/${projectId()}/runs/cancel`, { run_id: id }));
    toast.info('Run stopped. Pages that had not been written are pending again.');
    syncTimer();
  } finally {
    runs.busy = false;
  }
}, 'Stop run');

export const forgetRun = (id) => attempt(async () => {
  apply(await del(`projects/${projectId()}/runs`, { run_id: id }));
}, 'Remove run');

/* ------------------------------------------------------------------- labels */

const STATE_TONE = {
  preparing: 'badge--outline',
  submitted: 'badge--accent',
  running: 'badge--accent',
  completed: 'badge--success',
  failed: 'badge--danger',
  canceled: 'badge--warning',
};

export const runTone = (job) => STATE_TONE[job.status] ?? '';

export const runWhere = (job) => (job.mode === 'batch' ? 'provider queue' : 'background');

/** "3 of 40 written" reads better than a percentage nobody can act on. */
export function runProgress(job) {
  const { total, written, failed, pending, working = 0, skipped = 0 } = job.pages;

  if (!job.terminal) {
    const busy = working ? `, ${working} being written` : '';
    return `${written} of ${total} written${busy}, ${pending} waiting`;
  }

  const notes = [];
  if (failed) notes.push(`${failed} failed`);
  // Pages written another way while the run was queued: the answer arrived and
  // was deliberately not applied.
  if (skipped) notes.push(`${skipped} already written`);
  return `${written} of ${total} written${notes.length ? `, ${notes.join(', ')}` : ''}`;
}

/** How long ago the scheduler last ran, in words. */
export function cronAge() {
  if (!runs.cron.configured) return 'not set up';
  if (!runs.cron.last_at) return 'never run';
  const s = runs.cron.seconds_ago;
  if (s < 90) return 'just now';
  if (s < 5400) return `${Math.round(s / 60)} min ago`;
  return `${Math.round(s / 3600)} h ago`;
}
