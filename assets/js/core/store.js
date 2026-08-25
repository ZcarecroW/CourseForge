/**
 * The single source of truth shared by every view.
 *
 * Rule of thumb: anything two views need lives here, anything one view needs
 * stays in that view's setup(). The store never renders and never imports a
 * component, which keeps the dependency graph one-directional.
 *
 * How fresh the data is
 * ---------------------
 * The three workspace lists - courses, tags, profiles - arrive in one batch at
 * sign-in, which is what makes the counts in the sidebar possible before any
 * screen has been opened. That batch is a starting point, not the truth. A
 * second administrator, a connected MCP client, a scheduled run or another
 * browser tab can change any of it a second later, and this application is
 * built to be driven by all of those at once.
 *
 * So the rule is: opening a screen refetches what that screen shows. Not on a
 * timer, and not after every keystroke - on the one event that means somebody
 * is about to read it. A screen drawing from a snapshot taken twenty minutes
 * ago states things that are no longer so, and the sentence above a delete
 * button ("is not attached to anything") is the worst possible place to be
 * wrong. Screens whose data was never in that batch - Connect and the four
 * administration screens - already fetch when they mount, and so are not
 * listed in VIEW_DATA below.
 *
 * Unsaved work
 * ------------
 * Navigation is a function here rather than a router, so this is also the
 * place where leaving a screen can be refused. Two things refuse it: a
 * generation run still in flight, and a screen that has said it is holding
 * edits nobody has saved. See generationBlocks() and declareUnsaved().
 */
import { reactive, computed, onBeforeUnmount } from 'vue';
import { api, get, setCsrf, setUnauthorizedHandler } from './api.js';
import { toast, attempt } from './toast.js';

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

  /**
   * True while the sign-in screen has stood aside for the form that turns an
   * invite code into an account.
   *
   * Somebody holding a code has no account, so the only screen they can reach
   * is the one asking them to sign in, and the way to the redemption form has
   * to start there. It lives here rather than in a view because the shell is
   * what chooses between the signed-out screens.
   */
  redeeming: false,

  /* administration - fetched when an admin screen opens, never at sign-in */
  settings: [],
  settingsPhp: null,
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

  /**
   * A move to another screen that unsaved work has held up: where it was
   * heading, and what would be lost. The shell draws the dialog that asks.
   * Null whenever nothing is being asked.
   */
  leaving: null,

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
 * that is done, and the server refuses every route but sign-in, sign-out and
 * the change itself - see loadWorkspace().
 */
export const mustChangePassword = computed(() => state.user?.must_change_password === true);

/**
 * Whether there is an invite waiting to be turned into an account.
 *
 * The sign-in screen offers its redemption form only while this holds, so
 * nobody is ever shown a box asking for a code that could not exist. The setup
 * endpoint answers it whether or not the installation still needs setting up,
 * which is what makes the question askable by somebody with no session.
 */
export const inviteOpen = computed(() => state.setupInfo.invite_open === true);

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

/**
 * Everything a signed-in account needs before a screen can be drawn.
 *
 * Nothing in here is fetchable while the account still owes a password change:
 * the server refuses every route but sign-in, sign-out and the change itself,
 * so asking anyway would answer four requests with 403 and put an error toast
 * over the very dialog that fixes it. The three doors into the workspace -
 * boot, sign-in and a redeemed invite - all pass through here, which is why the
 * rule is stated once, here, rather than at each of them. The dialog calls this
 * again the moment the password is chosen, and that is when the workspace fills
 * in.
 */
export async function loadWorkspace() {
  if (mustChangePassword.value) return;
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
  // What PHP is doing on this host, measured on the way in - the settings
  // screen shows it, and the server has already repaired .user.ini if it
  // needed repairing.
  state.settingsPhp = data.php ?? null;
  return data;
}

/** Applies the response of a settings write, which ships the fresh catalogue. */
export function applySettings(payload) {
  if (payload?.settings) state.settings = payload.settings;
  if (payload?.scheduler) state.scheduler = payload.scheduler;
  if (payload?.php) state.settingsPhp = payload.php;
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

/* ----------------------------------------------------------- unsaved work */

/**
 * The screen currently on show says here whether it is holding edits nobody
 * has saved, and what they are.
 *
 * One screen is mounted at a time, so there is only ever one of these. It is a
 * function rather than a flag because the shell has to be able to name what
 * would be lost: "2 unsaved changes" is a question somebody can answer, and
 * "you may have unsaved changes" is not.
 *
 * A screen calls this once from setup(), passing a function that returns a
 * short phrase while there is something to lose and an empty string otherwise:
 *
 *     declareUnsaved(() => (dirtyCount.value ? plural(dirtyCount.value, 'unsaved change') : ''));
 *
 * There is nothing to remember and nothing to undo - the registration ends
 * with the screen.
 */
let unsavedProbe = null;

export function declareUnsaved(probe) {
  unsavedProbe = probe;
  onBeforeUnmount(() => {
    if (unsavedProbe === probe) unsavedProbe = null;
  });
}

/** What would be lost right now, as a phrase, or '' when nothing would be. */
export function unsavedWork() {
  try {
    return String(unsavedProbe?.() ?? '').trim();
  } catch {
    // A probe that throws must never be able to trap somebody on a screen.
    return '';
  }
}

/**
 * Closing the tab or reloading throws the same work away, and the browser is
 * the only thing that can ask about that - so it is asked here, in the one
 * place that knows whether there is anything to ask about.
 */
window.addEventListener('beforeunload', (event) => {
  if (!unsavedWork()) return;
  event.preventDefault();
  // Chrome still wants the legacy assignment before it shows its own prompt.
  event.returnValue = '';
});

/* ------------------------------------------------------------- navigation */

/** Leaving mid-generation would orphan the requests still in flight. */
function generationBlocks() {
  if (!state.generating) return false;
  toast.error('Wait for the generation to finish, or stop it first.');
  return true;
}

/**
 * Leaving with unsaved edits would throw them away without saying so. Unlike a
 * generation run this is not a refusal: it is a question, so it puts the
 * destination aside and lets the shell ask it.
 */
function unsavedBlocks(view) {
  const summary = unsavedWork();
  if (!summary) return false;
  state.leaving = { view, summary };
  return true;
}

/** The screens the second navigation group in App.js leads to. Keep in step. */
export const ADMIN_VIEWS = new Set(['users', 'settings', 'prompts', 'updates']);

/**
 * What each screen shows, and how to ask the server for it again. See the note
 * on freshness at the top of this file.
 */
const VIEW_DATA = {
  projects: () => loadProjects(),
  tags: () => loadTags(),
  profiles: () => loadProfiles(),
};

/**
 * Refetches what a screen shows, as the screen opens.
 *
 * Quietly: the screen is already drawn from the previous answer, so a failure
 * leaves that on show and says so in a toast, rather than blanking a working
 * screen because one request did not come back.
 */
function refresh(view) {
  const load = VIEW_DATA[view];
  if (load && state.user) attempt(load, 'Reload');
}

/**
 * @param {string} view
 * @param {object} [options]
 * @param {boolean} [options.discard=false]  the person has been asked about
 *   unsaved work on the screen being left, and chose to lose it
 */
export function go(view, { discard = false } = {}) {
  const leaving = view !== state.view;
  if (leaving && generationBlocks()) return;
  if (leaving && !discard && unsavedBlocks(view)) return;
  // A demotion takes effect on the next request, so the client refuses the
  // destination as well rather than drawing a screen that cannot load.
  if (ADMIN_VIEWS.has(view) && !isAdmin.value) return;
  state.leaving = null;
  state.view = view;
  state.sidebarOpen = false;
  if (view !== 'project') state.project = null;
  refresh(view);
}

/** Called by the shell when the question about unsaved work is answered "stay". */
export function stayPut() {
  state.leaving = null;
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
  refresh('projects');
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
  // What the sign-in screen may offer has changed underneath it while somebody
  // was signed in: an administrator who issues an invite and then signs out has
  // to find the way to redeem it waiting for them.
  await loadSetup();
}

function resetSession() {
  state.user = null;
  state.project = null;
  state.leaving = null;
  // Whoever arrives at the sign-in screen next starts at the sign-in form.
  state.redeeming = false;
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

/**
 * A refused request means the session is gone. Say so once, in words that say
 * what to do about it, and return true so that api.js can mark the error as
 * already announced - the server's own wording for the same refusal is written
 * for a log, and a second toast repeating it in developer language helps
 * nobody. When there was no session to lose there is nothing to announce, and
 * the caller's own message is the only one there is.
 */
setUnauthorizedHandler(() => {
  if (!state.user) return false;
  resetSession();
  toast.error('Your session expired. Please sign in again.');
  return true;
});
