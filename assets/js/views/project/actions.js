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

/**
 * @param {'course'|'chapter'|'page'} target
 * @param {number|null} targetId
 * @param {{features?:object, params?:object}} patch
 */
export const patchDetails = (target, targetId, patch) => run(
  async () => applyProject(await put(`projects/${projectId()}/details`, {
    target,
    target_id: targetId ?? null,
    features: patch.features ?? {},
    params: patch.params ?? {},
  })),
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

/* ----------------------------------------------------------------- settings */

export const saveSettings = (fields, { silent = false } = {}) => run(async () => {
  const result = applyProject(await put(`projects/${projectId()}`, fields));
  if (!silent) toast.success('Course settings saved.');
  return result;
}, 'Save settings');

/* ----------------------------------------------------------------- chapters */

export const saveChapter = (chapterId, fields) => run(async () => {
  const result = applyProject(await put(`projects/${projectId()}/chapters/${chapterId}`, fields));
  toast.success('Chapter saved.');
  return result;
}, 'Save chapter');
