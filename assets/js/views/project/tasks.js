/**
 * The tasks of the open course - a publish, a link pass - and what they said.
 *
 * A task is worked by the scheduler, not by this tab: pressing Publish writes
 * it down and returns, and the page is free to be closed. What this module
 * does is watch. While a task is open it polls, folds every new log line into
 * the record, and refreshes the course tree when a task moves on, so the
 * badges follow the work. The log itself lives on the server, so opening the
 * tab a day later shows exactly what happened while nobody was looking.
 *
 * One more thing, for the installation with no scheduler: when nothing is
 * calling cron.php, the browser works the task itself - one bounded slice per
 * request, under the same lease a tick would take - so a publish still happens.
 * It is slower and it needs the tab open, and the tab says so.
 */
import { reactive, computed } from 'vue';
import { openCourse, applyProject } from '@/core/store.js';
import { get, post, del } from '@/core/api.js';
import { toast, attempt } from '@/core/toast.js';

const EMPTY_CRON = { configured: false, last_at: 0, seconds_ago: 0, healthy: false };

export const tasks = reactive({
  loaded: false,
  busy: false,
  polling: false,
  pumping: false,
  list: [],
  log: [],
  cursor: 0,
  cron: { ...EMPTY_CRON },
});

let timer = null;
let loadedFor = 0;
let live = false;
let pumpTimer = null;

export const openTasks = computed(() => tasks.list.filter((task) => !task.terminal));
export const doneTasks = computed(() => tasks.list.filter((task) => task.terminal));

/** True while the browser has to do the scheduler's job. */
export const schedulerAbsent = computed(() => !tasks.cron.configured || !tasks.cron.healthy);

const projectId = () => openCourse.value.id;

/* ------------------------------------------------------------------ apply */

function applyList(list) {
  if (!Array.isArray(list)) return;
  tasks.list = list;
}

function appendLog(lines) {
  if (!Array.isArray(lines) || !lines.length) return;
  const known = new Set(tasks.log.map((line) => line.id));
  for (const line of lines) {
    if (!known.has(line.id)) tasks.log.push(line);
  }
  tasks.cursor = Math.max(tasks.cursor, ...lines.map((line) => line.id));
  // A course that has been published a hundred times has thousands of lines;
  // the tab shows the recent ones and the rest is still on the server.
  if (tasks.log.length > 1500) tasks.log.splice(0, tasks.log.length - 1500);
}

/* ---------------------------------------------------------------- loading */

export function resetTasks() {
  stopTasksPolling();
  tasks.list = [];
  tasks.log = [];
  tasks.cursor = 0;
  tasks.loaded = false;
  tasks.busy = false;
  tasks.cron = { ...EMPTY_CRON };
  loadedFor = 0;
}

export async function loadTasks() {
  const id = projectId();
  if (!id) return;
  if (loadedFor !== id) resetTasks();

  live = true;
  const payload = await get(`projects/${id}/tasks`);
  if (projectId() !== id) return;

  applyList(payload.tasks);
  tasks.log = [];
  tasks.cursor = 0;
  appendLog(payload.log);
  if (payload.cron) tasks.cron = payload.cron;
  loadedFor = id;
  tasks.loaded = true;
  syncTimer();
  pump();
}

/* ---------------------------------------------------------------- polling */

/** The tasks as they were, so a poll can tell what moved. */
function snapshot() {
  return new Map(tasks.list.map((task) => [task.id, `${task.status}:${task.updated_at}`]));
}

async function pollOnce() {
  if (tasks.busy || !projectId()) return;
  tasks.busy = true;
  try {
    const before = snapshot();
    const id = projectId();
    // The route travels in ?r=, so a second query parameter joins with &.
    const payload = await get(`projects/${id}/tasks&after=${tasks.cursor}`);
    if (projectId() !== id) return;

    applyList(payload.tasks);
    appendLog(payload.log);
    if (payload.cron) tasks.cron = payload.cron;

    let moved = false;
    for (const task of tasks.list) {
      const was = before.get(task.id);
      if (was !== `${task.status}:${task.updated_at}`) moved = true;
      if (was && task.terminal && !was.startsWith(task.status)) announce(task);
    }
    // The badges on the course follow the work: a task that moved has
    // written something, and the tree is where that shows.
    if (moved) await refreshTree();
  } catch (error) {
    console.warn('[CourseForge] task poll failed:', error.message);
  } finally {
    tasks.busy = false;
    syncTimer();
    pump();
  }
}

function announce(task) {
  const what = task.kind === 'resolve_links' ? 'Link pass' : 'Publish';
  if (task.status === 'done') toast.success(`${what} finished.`);
  else if (task.status === 'failed') toast.error(`${what} gave up: ${task.error || 'see the log.'}`);
  else if (task.status === 'canceled') toast.info(`${what} stopped.`);
}

async function refreshTree() {
  const id = projectId();
  if (!id) return;
  try {
    const data = await get(`projects/${id}`);
    if (projectId() === id) applyProject(data);
  } catch (error) {
    console.warn('[CourseForge] course refresh failed:', error.message);
  }
}

/** Runs the timer exactly while there is something to wait for. */
function syncTimer() {
  const wanted = live && openTasks.value.length > 0;
  if (wanted && timer === null) {
    timer = setInterval(pollOnce, 3000);
    tasks.polling = true;
  } else if (!wanted && timer !== null) {
    clearInterval(timer);
    timer = null;
    tasks.polling = false;
  }
}

export function stopTasksPolling() {
  live = false;
  if (timer !== null) clearInterval(timer);
  timer = null;
  tasks.polling = false;
  if (pumpTimer !== null) clearTimeout(pumpTimer);
  pumpTimer = null;
  tasks.pumping = false;
}

export const pollTasksNow = () => attempt(pollOnce, 'Check the tasks');

/* ------------------------------------------------------------------- pump */

/**
 * Works a queued task from the browser when no scheduler is doing it.
 *
 * Only while the scheduler is absent - not configured, or not calling in -
 * and only a task that is queued and due. A task the scheduler holds is left
 * to it; the server refuses the slice and the poll shows the progress.
 */
async function pump() {
  if (!live || tasks.pumping || !schedulerAbsent.value) return;
  const id = projectId();
  const task = tasks.list.find((t) => t.status === 'queued' && t.next_at <= Math.floor(Date.now() / 1000) + 1);
  if (!id || !task) return;

  tasks.pumping = true;
  try {
    const payload = await post(`projects/${id}/tasks/${task.id}/run`, {});
    if (projectId() !== id) return;
    applyList(payload.tasks);
    applyProject(payload);
    if (payload.task && payload.task.terminal) announce(payload.task);
  } catch (error) {
    console.warn('[CourseForge] task slice failed:', error.message);
  } finally {
    tasks.pumping = false;
    // Fetch the lines the slice wrote, then come back for the next slice. A
    // poll may already be in flight - the timer fired while the slice ran -
    // so wait for it rather than skip, or the last lines are never fetched.
    for (let i = 0; i < 50 && tasks.busy; i++) await new Promise((r) => setTimeout(r, 100));
    await attempt(pollOnce, 'Check the tasks');
    if (live && openTasks.value.length) {
      pumpTimer = setTimeout(pump, 400);
    }
  }
}

/* ---------------------------------------------------------------- actions */

/**
 * Queues a task.
 *
 * @param {object} body  kind, scope, target_id, target_ids, force
 */
export const queueTask = (body, label = 'Publish') => attempt(async () => {
  const id = projectId();
  const payload = await post(`projects/${id}/tasks`, body);
  if (projectId() !== id) return payload;
  applyList(payload.tasks);
  if (payload.cron) tasks.cron = payload.cron;
  toast.success(schedulerAbsent.value
    ? `${label} queued. No scheduler is calling in, so this tab works it - keep it open.`
    : `${label} queued. The scheduler works it; you can close this tab.`);
  syncTimer();
  pump();
  return payload;
}, label);

export const cancelTask = (taskId) => attempt(async () => {
  const id = projectId();
  const payload = await post(`projects/${id}/tasks/${taskId}/cancel`, {});
  if (projectId() !== id) return;
  applyList(payload.tasks);
  await pollOnce();
  toast.info('Stopped. What was already written stays.');
}, 'Stop');

export const retryTask = (taskId) => attempt(async () => {
  const id = projectId();
  const payload = await post(`projects/${id}/tasks/${taskId}/retry`, {});
  if (projectId() !== id) return;
  applyList(payload.tasks);
  toast.success('Queued again. It carries on from where it stopped.');
  syncTimer();
  pump();
}, 'Retry');

export const clearTasks = () => attempt(async () => {
  const id = projectId();
  const payload = await del(`projects/${id}/tasks`, {});
  if (projectId() !== id) return;
  applyList(payload.tasks);
  const keep = new Set(tasks.list.map((task) => task.id));
  tasks.log = tasks.log.filter((line) => keep.has(line.task_id));
}, 'Clear the log');

/* ----------------------------------------------------------------- labels */

const STATUS_TONE = {
  queued: 'badge--outline',
  running: 'badge--accent',
  done: 'badge--success',
  failed: 'badge--danger',
  canceled: 'badge--warning',
};

export const taskTone = (task) => STATUS_TONE[task.status] ?? '';

export const taskStatusLabel = (task) => {
  if (task.status === 'running') return task.live ? 'working' : 'working (worker silent)';
  if (task.status === 'queued') {
    if (task.attempts > 0) return 'waiting to retry';
    return 'queued';
  }
  return task.status;
};

/** What one wiki says about itself inside a task. */
export function targetProgress(task, targetId) {
  const entry = task.progress?.targets?.[String(targetId)];
  if (!entry) return { status: 'pending', label: 'waiting', pages: 0, error: '' };
  const pages = entry.work?.pages_done ?? 0;
  const labels = { done: 'finished', partial: 'in progress', failed: 'failed', pending: 'waiting' };
  return {
    status: entry.status,
    label: labels[entry.status] ?? entry.status,
    pages,
    error: entry.error ?? '',
    phase: entry.work?.phase ?? '',
  };
}

/**
 * The last thing that happened to a wiki, across every task - what the
 * destination card shows as its outcome.
 */
export function lastOutcomeFor(targetId) {
  for (const task of tasks.list) {
    const entry = task.progress?.targets?.[String(targetId)];
    if (!entry) continue;
    return {
      task,
      status: entry.status,
      error: entry.error ?? '',
      at: entry.finished_at ?? entry.failed_at ?? task.updated_at,
    };
  }
  return null;
}
