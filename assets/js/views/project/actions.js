/**
 * Course-level mutations shared by several tabs.
 *
 * All of them return the fresh project tree, so the store is updated in exactly
 * one place and no tab has to re-fetch after a change.
 */
import { ref } from 'vue';
import { state, applyProject } from '@/core/store.js';
import { post, put, del } from '@/core/api.js';
import { toast, attempt } from '@/core/toast.js';
import { plural } from '@/core/format.js';

/** True while any of these is in flight – tabs use it to disable their controls. */
export const busy = ref(false);

async function run(action, label) {
  busy.value = true;
  try {
    return await attempt(action, label);
  } finally {
    busy.value = false;
  }
}

const projectId = () => state.project?.id;

/* ------------------------------------------------------------------ details */

/** The details of one level, as the server last sent them. */
function detailsOf(target, targetId) {
  const project = state.project;
  if (!project) return null;
  if (target === 'course') return project.details ?? null;

  for (const chapter of project.chapters ?? []) {
    if (target === 'chapter' && chapter.id === targetId) return chapter.details ?? null;
    if (target === 'page') {
      const page = chapter.pages.find((p) => p.id === targetId);
      if (page) return page.details ?? null;
    }
  }
  return null;
}

/**
 * Says so when Minimum length ends up above Maximum length.
 *
 * A warning and not a refusal, on purpose. Each of the two values can be
 * decided at any of three levels, so the one being edited here is only half of
 * the pair - the other half may belong to a chapter or to the course, and
 * refusing this edit would be refusing it on account of a number the user
 * cannot see from this screen. Raising both is also impossible without passing
 * through the crossed state, whichever one is typed first. What must not happen
 * is silence: the two are sent to the model as a single sentence, "the body of
 * this page is between 100000 and 1 words", and it is then asked to obey it.
 */
function warnIfLengthsCross(target, targetId) {
  const params = detailsOf(target, targetId)?.effective?.params ?? {};
  const min = Number(params.min_length ?? 0);
  const max = Number(params.max_length ?? 0);

  // Zero is not a bound - it means "leave the length to the model" - so a pair
  // is only in conflict when both ends are actually set.
  if (!(min > 0 && max > 0 && min > max)) return;

  toast.error(`Minimum length (${plural(min, 'word')}) is above Maximum length (${plural(max, 'word')}), `
    + 'so every page here asks the AI for a length no page can have. '
    + 'Raise the maximum, or lower the minimum.');
}

/** The two values that only make sense as a pair. */
const LENGTH_KEYS = ['min_length', 'max_length'];

/**
 * @param {'course'|'chapter'|'page'} target
 * @param {number|null} targetId
 * @param {{features?:object, params?:object}} patch
 */
export const patchDetails = (target, targetId, patch) => run(
  async () => {
    const params = patch.params ?? {};
    const applied = applyProject(await put(`projects/${projectId()}/details`, {
      target,
      target_id: targetId ?? null,
      features: patch.features ?? {},
      params,
    }));
    // Checked after the write, against what the server resolved: clearing a
    // value can uncover an inherited one that crosses just as badly as a typed
    // one does.
    if (LENGTH_KEYS.some((key) => key in params)) warnIfLengthsCross(target, targetId);
    return applied;
  },
  'Content details'
);

/* --------------------------------------------------------------------- tags */

const tagCall = (method, target, targetId, body) => run(async () => {
  const path = `projects/${projectId()}/tags`;
  const payload = { target, target_id: targetId ?? null, ...body };
  const send = { POST: post, PUT: put, DELETE: del }[method];
  return applyProject(await send(path, payload));
}, 'Tags');

export const tagAdd = (target, targetId, { name, value, inherit }) =>
  tagCall('POST', target, targetId, { name, value: value || '', inherit: !!inherit });

export const tagRemove = (target, targetId, { tag_id: tagId }) =>
  tagCall('DELETE', target, targetId, { tag_id: tagId });

export const tagInherit = (target, targetId, { tag_id: tagId, inherit }) =>
  tagCall('PUT', target, targetId, { tag_id: tagId, inherit: !!inherit });

export const tagToggle = (target, targetId, { tag_id: tagId, enabled }) =>
  tagCall('PUT', target, targetId, { tag_id: tagId, enabled: !!enabled });

/** Chips that arrive through inheritance = effective minus own. */
export function inheritedTags(entity) {
  if (!entity) return [];
  const own = new Set((entity.tags ?? []).map((t) => t.id));
  return (entity.effective_tags ?? []).filter((t) => !own.has(t.id));
}

/* ----------------------------------------------------------------- research */

/**
 * Stores what was found out about the subject, or clears it.
 *
 * The same column an MCP client writes through `store_research`, so a briefing
 * a connected Claude Code researched can be read and edited here, and one typed
 * here reaches every brief that client is handed afterwards. The server stamps
 * the date; nothing is sent for it.
 */
export const saveResearch = (research) => run(async () => {
  const result = applyProject(await put(`projects/${projectId()}/research`, { research }));
  toast.success(research.trim() ? 'Research saved.' : 'Research cleared.');
  return result;
}, 'Save research');

/* ----------------------------------------------------------------- settings */

export const saveSettings = (fields, { silent = false } = {}) => run(async () => {
  const result = applyProject(await put(`projects/${projectId()}`, fields));
  if (!silent) toast.success('Course settings saved.');
  return result;
}, 'Save settings');

/**
 * Writes the whole list of BookStack destinations at once.
 *
 * The list rather than one add or one removal, because the order is part of the
 * meaning: the first entry is the one the course reports as its book and its
 * shelf everywhere a single answer is wanted. A destination that stays on the
 * list keeps the book it made; one taken off it is forgotten, book and all -
 * which is why the caller confirms before removing.
 */
export const saveTargets = (targets, { silent = false } = {}) => run(async () => {
  const result = applyProject(await put(`projects/${projectId()}/targets`, { targets }));
  if (!silent) toast.success('Destinations saved.');
  return result;
}, 'Save destinations');

/* --------------------------------------------------------------- typography */

/**
 * Sets the punctuation of text that is already written, over the course, one
 * chapter or one page.
 *
 * `preview` runs the whole pass server side and writes nothing, which is what
 * lets a button say how much of the course it is about to change before it
 * changes it. A preview answers with no tree, and applyProject leaves the store
 * alone when a payload carries none - so the same call serves both.
 */
export const fixTypography = (target, targetId = null, { preview = false } = {}) => run(async () => {
  const payload = applyProject(await post(`projects/${projectId()}/typography`, {
    target, target_id: targetId, preview,
  }));
  return payload.typography;
}, 'Correct punctuation');

/** "3 pages and 1 chapter", or "nothing" - the same sentence in both tabs. */
export const typographyCount = (result) => {
  const counts = result?.corrected ?? {};
  const parts = [];
  if (counts.pages) parts.push(plural(counts.pages, 'page'));
  if (counts.chapters) parts.push(plural(counts.chapters, 'chapter'));
  if (counts.course) parts.push('the course description');
  if (!parts.length) return 'nothing';
  return parts.length === 1 ? parts[0] : `${parts.slice(0, -1).join(', ')} and ${parts[parts.length - 1]}`;
};

/* ----------------------------------------------------------------- chapters */

export const saveChapter = (chapterId, fields) => run(async () => {
  const result = applyProject(await put(`projects/${projectId()}/chapters/${chapterId}`, fields));
  toast.success('Chapter saved.');
  return result;
}, 'Save chapter');
