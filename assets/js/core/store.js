/**
 * The single source of truth shared by every view.
 *
 * Rule of thumb: anything two views need lives here, anything one view needs
 * stays in that view's setup(). The store never renders and never imports a
 * component, which keeps the dependency graph one-directional.
 */
import { reactive, computed } from 'vue';
import { api, get, setCsrf, setUnauthorizedHandler } from './api.js';
import { toast } from './toast.js';

export const state = reactive({
  /* session */
  ready: false,
  user: null,
  lockedFor: 0,
  app: { name: 'CourseForge', version: '' },

  /* catalogue, fetched once after sign-in */
  promptGroups: {},
  promptSlots: {},
  features: [],
  params: [],
  baseline: { features: {}, params: {} },
  profileDefaults: null,
  providers: [],
  canSpawn: false,

  /* data */
  profiles: [],
  projects: [],
  tags: [],
  project: null,

  /* navigation */
  view: 'projects',
  projectTab: 'structure',

  /* chrome */
  sidebarOpen: false,
  busy: false,

  /** Set by the Content tab while a generation run is in flight. */
  generating: false,
});

/* ------------------------------------------------------------- selectors */

/**
 * A structurally complete stand-in used while a course is being closed.
 * Closing sets state.project to null one tick before the tabs unmount, and a
 * pre-flush watcher would otherwise dereference it.
 */
export const EMPTY_PROJECT = Object.freeze({
  id: 0, name: '', topic: '', structure_md: '', book_title: '', book_desc: '',
  profile_id: null, bs_instance_id: '', shelf_id: null, shelf_name: '',
  book_id: null, book_url: '', auto_tags: false, tag_pool: '', tag_pool_strict: false,
  dirty: false, created_at: 0, updated_at: 0, tags: [], effective_tags: [],
  details: { own: { features: {}, params: {} }, inherited: { features: {}, params: {} }, effective: { features: {}, params: {} } },
  stats: { chapters: 0, pages: 0, generated: 0, pushed: 0, dirty: 0, errors: 0, links: { markers: 0, resolved: 0, pending: 0, dropped: 0 } },
  chapters: [],
});

/** The open course, never null – see EMPTY_PROJECT. */
export const openCourse = computed(() => state.project ?? EMPTY_PROJECT);

export const isSignedIn = computed(() => state.user !== null);

export const currentProfile = computed(() =>
  state.profiles.find((p) => p.id === state.project?.profile_id) ?? null
);

export const bookstackInstances = computed(() => currentProfile.value?.data.bookstack ?? []);

export const concurrency = computed(() =>
  Math.max(1, Math.min(12, currentProfile.value?.data.concurrency ?? 2))
);

export const featureByKey = computed(() =>
  Object.fromEntries(state.features.map((f) => [f.key, f]))
);

export const paramByKey = computed(() =>
  Object.fromEntries(state.params.map((p) => [p.key, p]))
);

/** Every page of the open course, flattened into reading order. */
export const allPages = computed(() =>
  (state.project?.chapters ?? []).flatMap((chapter) => chapter.pages)
);

/* ---------------------------------------------------------------- loading */

export async function loadSession() {
  const data = await api('session', { soft: true });
  setCsrf(data?.csrf);
  state.user = data?.user ?? null;
  state.lockedFor = data?.locked_for ?? 0;
  if (data?.app) state.app = data.app;
}

export async function loadCatalogue() {
  const data = await get('config');
  state.app = data.app ?? state.app;
  state.promptGroups = data.prompt_groups ?? {};
  state.promptSlots = data.prompt_slots ?? {};
  state.features = data.details?.features ?? [];
  state.params = data.details?.params ?? [];
  state.baseline = data.details?.baseline ?? { features: {}, params: {} };
  state.profileDefaults = data.profile_defaults ?? null;
  state.providers = data.providers ?? [];
  state.canSpawn = data.can_spawn === true;
}


export const loadProfiles = async () => { state.profiles = (await get('profiles')).profiles ?? []; };
export const loadProjects = async () => { state.projects = (await get('projects')).projects ?? []; };
export const loadTags = async () => { state.tags = (await get('tags')).tags ?? []; };

export async function loadWorkspace() {
  await loadCatalogue();
  await Promise.all([loadProfiles(), loadProjects(), loadTags()]);
}

/* ------------------------------------------------------------- navigation */

/** Leaving mid-generation would orphan the requests still in flight. */
function generationBlocks() {
  if (!state.generating) return false;
  toast.error('Wait for the generation to finish, or stop it first.');
  return true;
}

export function go(view) {
  if (view !== state.view && generationBlocks()) return;
  state.view = view;
  state.sidebarOpen = false;
  if (view !== 'project') state.project = null;
}

export async function openProject(id) {
  const data = await get(`projects/${id}`);
  state.project = data.project;
  state.view = 'project';
  state.projectTab = data.project.stats.pages > 0 ? 'content' : 'structure';
  state.sidebarOpen = false;
}

export function closeProject() {
  if (generationBlocks()) return;
  state.project = null;
  state.view = 'projects';
}

export async function refreshProject() {
  if (!state.project) return;
  const data = await get(`projects/${state.project.id}`);
  state.project = data.project;
}

/** Applies a server response that carries a fresh project tree. */
export function applyProject(payload) {
  if (payload?.project) state.project = payload.project;
  if (payload?.tags) state.tags = payload.tags;
  return payload;
}

/**
 * Merges one updated page summary into the open tree without a full refetch –
 * this is what keeps a 40-page generation run from re-downloading the course
 * after every single page.
 */
export function mergePage(page) {
  if (!page || !state.project) return null;
  for (const chapter of state.project.chapters) {
    const index = chapter.pages.findIndex((p) => p.id === page.id);
    if (index > -1) {
      const { content, extra_context: extraContext, ...summary } = page;
      chapter.pages[index] = { ...chapter.pages[index], ...summary };
      return chapter.pages[index];
    }
  }
  return null;
}

/** Optimistic status change while a generation request is in flight. */
export function markPageStatus(pageId, status) {
  for (const chapter of state.project?.chapters ?? []) {
    const page = chapter.pages.find((p) => p.id === pageId);
    if (page) {
      page.status = status;
      if (status === 'generating') page.error = '';
      return;
    }
  }
}

/* ---------------------------------------------------------------- session */

export async function signOut() {
  await api('session', { method: 'DELETE', soft: true });
  resetSession();
  await loadSession();
}

function resetSession() {
  state.user = null;
  state.project = null;
  state.projects = [];
  state.profiles = [];
  state.tags = [];
  state.view = 'projects';
}

setUnauthorizedHandler(() => {
  if (state.user) {
    resetSession();
    toast.error('Your session expired. Please sign in again.');
  }
});
