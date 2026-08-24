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

  /* first run - true until the very first account exists */
  needsSetup: false,
  /** What the setup screen needs before anybody is signed in: where the invite
   *  file was written, whether an invite is open, how long a password must be. */
  setupInfo: { min_password: 10, invite_file: '', invite_open: false },

  /* administration - fetched when an admin screen opens, never at sign-in */
  settings: [],
  settingGroups: [],
  settingsFiles: { defaults: '', overrides: '' },
  scheduler: null,
  users: [],
  userRoles: [],
  invite: null,
  updateInfo: null,

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

/** Drives the second navigation group and every admin-only fetch. */
export const isAdmin = computed(() => state.user?.role === 'admin');

/**
 * The shortest password this installation accepts.
 *
 * It is a server setting, not a constant, so every screen that asks for a
 * password reads it from here rather than repeating a number that can drift.
 */
export const minPassword = computed(() => Number(state.setupInfo.min_password) || 10);

/**
 * True when the account was handed its password by somebody else and has not
 * chosen its own yet. The shell refuses to let the dialog be dismissed until
 * that is done.
 */
export const mustChangePassword = computed(() => state.user?.must_change_password === true);

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

/**
 * Asks the server whether this installation has any accounts yet.
 *
 * This is the first request the application makes, before the session and
 * before anything else, for two reasons: the answer decides which screen is
 * drawn at all, and on a brand-new installation this request is what publishes
 * the invite code to INVITE-CODE.txt. Nothing else writes that file.
 */
export async function loadSetup() {
  const data = await api('setup', { soft: true });
  state.needsSetup = data?.needs_setup === true;
  state.setupInfo = {
    min_password: data?.min_password ?? 10,
    invite_file: data?.invite_file ?? '',
    invite_open: data?.invite_open === true,
    // Publishing the code fails on a directory PHP cannot write to, and that
    // is the one failure the setup screen has to be able to explain.
    error: data?.ok === true ? '' : (data?.error ?? ''),
  };
  if (data?.app) state.app = data.app;
}

export async function loadSession() {
  const data = await api('session', { soft: true });
  setCsrf(data?.csrf);
  state.user = data?.user ?? null;
  state.lockedFor = data?.locked_for ?? 0;
  if (data?.app) state.app = data.app;
  // The session endpoint answers the same question, and it still answers it
  // when the setup endpoint could not - see loadSetup().
  if (typeof data?.needs_setup === 'boolean') state.needsSetup = data.needs_setup;
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

/* ----------------------------------------------------------- administration
 *
 * None of this is fetched at sign-in. A normal account must never fire an
 * admin request - the server would refuse it, but a 403 in the console is a
 * bug report waiting to happen - and an administrator should not pay for four
 * extra round trips to reach a screen they may not open today. Each screen
 * calls its own loader when it mounts; the only exception is the update badge
 * below, which is one request and has to happen before the screen is opened.
 */

export async function loadSettings() {
  const data = await get('admin/settings');
  state.settingGroups = data.groups ?? [];
  state.settings = data.settings ?? [];
  state.scheduler = data.scheduler ?? null;
  state.settingsFiles = { defaults: data.defaults_file ?? '', overrides: data.overrides_file ?? '' };
  return data;
}

/** Applies the response of a settings write, which ships the fresh catalogue. */
export function applySettings(payload) {
  if (payload?.settings) state.settings = payload.settings;
  if (payload?.scheduler) state.scheduler = payload.scheduler;
  return payload;
}

export async function loadUsers() {
  const data = await get('admin/users');
  state.users = data.users ?? [];
  state.userRoles = data.roles ?? [];
  state.invite = data.invite ?? null;
  // The accounts screen is the other place that knows the password floor, and
  // it is authoritative there too.
  if (data.min_password) state.setupInfo.min_password = data.min_password;
  return data;
}

/** Applies a response that carries the whole account list. */
export function applyUsers(payload) {
  if (payload?.users) state.users = payload.users;
  if (payload?.invite) state.invite = payload.invite;
  return payload;
}

export async function loadUpdateInfo() {
  state.updateInfo = await get('admin/update');
  return state.updateInfo;
}

/** The same loader under the name the Updates screen reads more naturally with. */
export const loadUpdate = loadUpdateInfo;

let updateProbed = false;

/**
 * Fills in the update badge, once per session and never on a timer.
 *
 * A failure here is swallowed on purpose. Asking GitHub can time out, and an
 * error toast at sign-in about a check nobody asked for would be noise; the
 * Updates screen shows the same failure properly, to somebody who went looking.
 */
export async function probeUpdate() {
  if (updateProbed) return state.updateInfo;
  updateProbed = true;
  try {
    return await loadUpdateInfo();
  } catch {
    return null;
  }
}

/* ------------------------------------------------------------- navigation */

/** Leaving mid-generation would orphan the requests still in flight. */
function generationBlocks() {
  if (!state.generating) return false;
  toast.error('Wait for the generation to finish, or stop it first.');
  return true;
}

/** The screens the second navigation group in App.js leads to. Keep in step. */
export const ADMIN_VIEWS = new Set(['users', 'settings', 'prompts', 'updates']);

export function go(view) {
  if (view !== state.view && generationBlocks()) return;
  // A demotion takes effect on the next request, so the client refuses the
  // destination as well rather than drawing a screen that cannot load.
  if (ADMIN_VIEWS.has(view) && !isAdmin.value) return;
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

  // Administration is somebody's data too: the next account to sign in here
  // must not find the previous one's list of users on screen.
  state.settings = [];
  state.settingGroups = [];
  state.settingsFiles = { defaults: '', overrides: '' };
  state.scheduler = null;
  state.users = [];
  state.userRoles = [];
  state.invite = null;
  state.updateInfo = null;
  updateProbed = false;
}

setUnauthorizedHandler(() => {
  if (state.user) {
    resetSession();
    toast.error('Your session expired. Please sign in again.');
  }
});
