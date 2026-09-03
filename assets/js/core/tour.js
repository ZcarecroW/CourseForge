/**
 * The guided tour: every screen, every section, one at a time.
 *
 * A step names a screen to stand on, a thing on it to light up, and what to
 * say about it. The overlay component (components/AppTour.js) does the
 * standing and the lighting; this module is the list and the state. Two
 * lists, because an administrator sees four screens and a lock a user never
 * does, and the words for the shared screens differ a little too.
 *
 * A step may say when it applies - the five tabs of a course only exist once
 * there is a course - and a step whose target is not on screen is shown in the
 * middle of the window instead, so nothing ever blocks the tour.
 */
import { reactive, computed } from 'vue';
import { state, isAdmin } from '@/core/store.js';

export const tour = reactive({
  active: false,
  index: 0,
  steps: [],
  kind: 'user',
});

export const currentStep = computed(() => tour.steps[tour.index] ?? null);
export const stepCount = computed(() => tour.steps.length);

/* ------------------------------------------------------------ the shared part */

const WELCOME = (admin) => ({
  id: 'welcome',
  title: admin ? 'Welcome to CourseForge' : 'Welcome to CourseForge',
  body: [
    'CourseForge turns a one-line brief into a complete course - an outline, chapters, written pages, flashcards - and publishes it into BookStack.',
    admin
      ? 'This tour walks through every screen and every setting an administrator has, in the order you will meet them. It takes about five minutes; skip it at any time and open it again from the sidebar.'
      : 'This tour walks through every screen you have, in the order you will use them. It takes a few minutes; skip it at any time and open it again from the sidebar.',
  ],
});

const WORKSPACE = [
  {
    id: 'nav', view: 'projects', target: '[data-tour="nav-workspace"]',
    title: 'The workspace',
    body: [
      'Five screens hold everything you write: Courses, Tags, Profiles, BookStackDev and Connect. The counts beside them are live.',
      'Below them, when you are an administrator, is a second group for the installation itself.',
    ],
  },
  {
    id: 'courses', view: 'projects', target: '[data-tour="view-header"]',
    title: 'Courses',
    body: [
      'A course starts as a brief - "Vue.js, beginner to professional" - and grows an outline, then pages, then a book in BookStack. This list is every course on the installation, with how far each has got.',
      'New course creates one. A course needs a profile before anything can be generated, so the first thing to make is usually a profile, one screen down.',
    ],
  },
  {
    id: 'course-open', view: 'project', tab: 'structure', target: '[data-tour="tab-structure"]',
    when: () => state.projects.length > 0,
    title: 'Structure',
    body: [
      'The outline: chapters and pages as Markdown, with the AI beside it. Describe the course, let the model design the outline, refine it, and Apply turns it into rows.',
      'Applying again later keeps what has been written under unchanged titles and warns before it would delete a page that has text on it.',
    ],
  },
  {
    id: 'course-content', view: 'project', tab: 'content', target: '[data-tour="tab-content"]',
    when: () => state.projects.length > 0,
    title: 'Content',
    body: [
      'The pages. Write them by hand in the editor, generate one at a time while you watch, or start a run: a batch queue at the provider, or the background worker that keeps writing after the laptop is shut.',
      'The preview renders code, diagrams and formulas exactly as BookStack will.',
    ],
  },
  {
    id: 'course-details', view: 'project', tab: 'details', target: '[data-tour="tab-details"]',
    when: () => state.projects.length > 0,
    title: 'Details',
    body: [
      'What every page of this course is made of: exercises, flashcards, summaries, cross references, length - each on, off, or inherited from the profile and the installation.',
      'A chapter or a page can decide differently for itself; the value closest to the page wins.',
    ],
  },
  {
    id: 'course-publish', view: 'project', tab: 'publish', target: '[data-tour="publish-all"]',
    when: () => state.projects.length > 0,
    title: 'Publish - the whole course',
    body: [
      'Publishing sends the book, its chapters and every written page to BookStack. The card at the top speaks for every destination at once: how much is written, how much is published everywhere, how much has changed since.',
      'A push is queued and worked by the scheduler, so it carries on after this tab is closed - and a wiki that stops answering is tried again from the page it stopped at.',
    ],
  },
  {
    id: 'course-publish-each', view: 'project', tab: 'publish', target: '[data-tour="publish-destination"]',
    when: () => state.projects.length > 0 && (state.projects[0]?.target_count ?? 0) > 0,
    title: 'Publish - each destination',
    body: [
      'A course can publish into several wikis, and each gets a card of its own: its book, its shelf, how many pages it holds, what is out of sync, its auto links, and what last happened to it.',
      'Publish here pushes to that wiki alone - the one that was behind, or the one that failed.',
    ],
  },
  {
    id: 'course-publish-log', view: 'project', tab: 'publish', target: '[data-tour="publish-log"]',
    when: () => state.projects.length > 0,
    title: 'The publish log',
    body: [
      'Every push is a task, and everything it said is written down on the server as it is said. Open this tab a day later and the log is here, including what happened while nobody was watching.',
      'A task that fails is retried by itself, with a growing pause between attempts; Stop and Retry are yours.',
    ],
  },
  {
    id: 'course-settings', view: 'project', tab: 'settings', target: '[data-tour="tab-settings"]',
    when: () => state.projects.length > 0,
    title: 'Course settings',
    body: [
      'The name, the brief, the profile the course is written with, its tags and the punctuation pass. Changing the profile changes which AI account and which BookStack instances the course uses from now on.',
    ],
  },
  {
    id: 'course-none', view: 'projects', target: '[data-tour="view-header"]',
    when: () => state.projects.length === 0,
    title: 'A course has five tabs',
    body: [
      'Structure for the outline, Content for the pages, Details for what every page is made of, Publish for sending it to BookStack, and Settings for the name, the profile and the tags.',
      'There is no course yet, so the tour cannot show them; create one afterwards and open it.',
    ],
  },
  {
    id: 'tags', view: 'tags', target: '[data-tour="view-header"]',
    title: 'Tags',
    body: [
      'Tags travel with a course into BookStack - on the book, on chapters, on pages - and a tag put on a course is inherited by everything under it unless something says otherwise.',
      'The AI can propose tags while it designs the outline, from a pool you decide.',
    ],
  },
  {
    id: 'profiles', view: 'profiles', target: '[data-tour="view-header"]',
    title: 'Profiles',
    body: [
      'A profile is everything a course needs to be written and published: AI accounts with their keys, BookStack instances with their tokens, which model writes what, the language, the content defaults and the prompt wording.',
      'Make one profile per way of working - one provider, one wiki, one audience - and point courses at it.',
    ],
  },
  {
    id: 'profile-accounts', view: 'profiles', target: '[data-tour="profile-tab-accounts"]',
    when: () => state.profiles.length > 0,
    title: 'Accounts',
    body: [
      'BookStack instances on the left, AI accounts on the right. A key or a token is stored once and never shown again - the box says "stored" afterwards.',
      'Until this server has passed the security check, the key fields are greyed out with a red mark: a secret must not be written to a database anybody can download.',
    ],
  },
  {
    id: 'profile-models', view: 'profiles', target: '[data-tour="profile-tab-models"]',
    when: () => state.profiles.length > 0,
    title: 'Models and output',
    body: [
      'Which account and which model design the outline, and which write the pages. A ":batch" suffix sends the pages through the provider\'s batch queue, usually at half price.',
      'The language, how many pages are written at once, and whether punctuation is corrected afterwards live here too.',
    ],
  },
  {
    id: 'profile-content', view: 'profiles', target: '[data-tour="profile-tab-content"]',
    when: () => state.profiles.length > 0,
    title: 'Content defaults',
    body: [
      'What every course on this profile starts from: the same list a course\'s Details tab shows, one level up. A course, a chapter or a page can still decide otherwise.',
    ],
  },
  {
    id: 'profile-prompts', view: 'profiles', target: '[data-tour="profile-tab-prompts"]',
    when: () => state.profiles.length > 0,
    title: 'Prompts',
    body: [
      'Every AI request is built from a library of prompt slots. The installation holds the base wording; a profile may say any of it differently for its own courses, and reset to follow the installation again.',
    ],
  },
  {
    id: 'bookstackdev', view: 'bookstackdev', target: '[data-tour="view-header"]',
    title: 'BookStackDev',
    body: [
      'The look a BookStack wiki wears: code highlighting in every language, Mermaid diagrams, formulas, link embeds, an audio player, a dark mode toggle. Every feature is a card with a switch.',
      'A look gives you one line for BookStack\'s custom head, and that line works only on the wikis the look allows.',
    ],
  },
  {
    id: 'connect', view: 'connect', target: '[data-tour="view-header"]',
    title: 'Connect',
    body: [
      'Everything the browser can do, an AI client can do: Claude Code, the Claude desktop app, Cursor, Codex and others speak to CourseForge over MCP. This screen mints a connection token - shown once - with the scopes you choose.',
    ],
  },
  {
    id: 'theme', view: null, target: '[data-tour="theme"]',
    title: 'Light, dark, or the system',
    body: ['The theme follows your system unless you choose one. It is remembered in this browser.'],
  },
  {
    id: 'account', view: null, target: '[data-tour="account"]',
    title: 'Your account',
    body: ['Your display name and your password. An account handed a password by an administrator is asked to choose its own the first time it signs in.'],
  },
  {
    id: 'tour-button', view: null, target: '[data-tour="tour-button"]',
    title: 'This tour',
    body: ['Open it again from here whenever you like - after an update, or to look up a screen you have not used for a while.'],
  },
];

/* ---------------------------------------------------------- administration */

const ADMIN = [
  {
    id: 'admin-nav', view: 'users', target: '[data-tour="nav-admin"]',
    title: 'Administration',
    body: [
      'The second group is the installation itself: who may sign in, what the installation does by default, the words it sends to the model, whether it is current, and whether the server keeps its secrets.',
    ],
  },
  {
    id: 'users-list', view: 'users', target: '[data-tour="users-list"]',
    title: 'Accounts',
    body: [
      'Every account, its role, whether it may sign in, and what it owns. A user owns their courses, profiles and tags; an administrator sees everybody\'s and can change the installation.',
      'Disabling keeps the work; deleting asks whether to hand the work to somebody else or destroy it.',
    ],
  },
  {
    id: 'users-add', view: 'users', target: '[data-tour="users-add"]',
    title: 'Adding somebody',
    body: [
      'Create an account with a password you pass on - shown once - or, better, issue an invite: a code the person turns into an account themselves, with a password nobody else ever sees.',
    ],
  },
  {
    id: 'users-invite', view: 'users', target: '[data-tour="users-invite"]',
    title: 'Invites',
    body: [
      'Several invites may be open at once, each with a label, a role, an expiry and a number of places: one for the marketing team, one for a contractor, one for a colleague who should be an administrator.',
      'A code is shown here exactly once. Revoke an invite that went to the wrong address; it stops working immediately.',
    ],
  },
  {
    id: 'settings', view: 'settings', target: '#setting-group-general',
    title: 'Settings - General',
    body: [
      'Everything this installation can be told to do differently, one card per group. Nothing is written until Save; the "changed" markers show what has moved away from the release.',
      'General holds the installation name, the default course language, the public address and the debug switch - which must stay off on anything reachable from the internet.',
    ],
  },
  {
    id: 'settings-content', view: 'settings', target: '#setting-group-content',
    title: 'Settings - Course defaults',
    body: [
      'The bottom of the chain every page is made from: installation, then profile, then course, chapter, page. Change a default here and every course that has not overridden it follows.',
    ],
  },
  {
    id: 'settings-scheduler', view: 'settings', target: '#setting-group-scheduler',
    title: 'Settings - Scheduler',
    body: [
      'The one card most installations get wrong, and the one that matters most. Your host calls cron.php once a minute with the token from here, and from then on background runs and publishing carry on after the browser is closed.',
      'Generate a token, paste the URL into your hosting panel or a crontab, and watch the "last tick" line come alive.',
    ],
  },
  {
    id: 'settings-batch', view: 'settings', target: '#setting-group-batch',
    title: 'Settings - Batch and runs',
    body: [
      'How often a provider queue is polled, how long finished run records are kept, and the output ceiling used where a provider demands one.',
    ],
  },
  {
    id: 'settings-updates', view: 'settings', target: '#setting-group-updates',
    title: 'Settings - Updates',
    body: [
      'Where releases come from, whether to check daily, and whether to install unattended at a quiet hour. A backup is taken first and restored if the new version fails to start.',
    ],
  },
  {
    id: 'settings-mcp', view: 'settings', target: '#setting-group-mcp',
    title: 'Settings - MCP',
    body: [
      'The endpoint AI clients connect to. Turning it off refuses every token at once; the public URL is for installations behind a proxy that cannot work it out.',
    ],
  },
  {
    id: 'settings-security', view: 'settings', target: '#setting-group-security',
    title: 'Settings - Security',
    body: [
      'Sign-in throttling - how many failures before an account or an address is locked, and for how long - and how long a session lasts without activity.',
    ],
  },
  {
    id: 'settings-timeouts', view: 'settings', target: '#setting-group-timeouts',
    title: 'Settings - Timeouts',
    body: [
      'How long CourseForge waits for somebody else: a model writing a long page, a provider listing its models, BookStack answering a publish. Too low and work is lost mid-page.',
    ],
  },
  {
    id: 'settings-php', view: 'settings', target: '#setting-group-_php',
    title: 'PHP on this host',
    body: [
      'Shared hosting hands out limits chosen for a blog. Set up PHP writes a .user.ini beside the installation that raises only what this host is short of, and never lowers anything.',
    ],
  },
  {
    id: 'settings-check', view: 'settings', target: '#setting-group-_check',
    title: 'The installation check',
    body: [
      'Everything CourseForge can verify about the server without a shell: PHP, permissions, the database, the scheduler, updates, the MCP endpoint. Open it when something is not working.',
    ],
  },
  {
    id: 'prompts', view: 'prompts', target: '[data-tour="view-header"]',
    title: 'Prompts',
    body: [
      'The words this installation sends to the model, slot by slot: the outline design, the page brief, every content element switched on or off. Edit them here for everybody; a profile may still override any of them.',
    ],
  },
  {
    id: 'updates', view: 'updates', target: '[data-tour="view-header"]',
    title: 'Updates',
    body: [
      'Is this installation current, and what if it is not. Check GitHub, install a release in one click, roll back to the version before, and read what every update did.',
    ],
  },
  {
    id: 'security', view: 'security', target: '[data-tour="security-verdict"]',
    title: 'Security - the verdict',
    body: [
      'CourseForge protects its data directory with .htaccess files, which Apache reads and nginx, Caddy and IIS do not. Rather than trust that, it asks the server for its own private files over HTTP and reports which came back.',
      'Until every one is refused, every field that would store a secret is locked.',
    ],
  },
  {
    id: 'security-probes', view: 'security', target: '[data-tour="security-probes"]',
    title: 'Security - what was asked for',
    body: [
      'Each file fetched from this address and what came back. The ones marked "decides" hold secrets - the database, the deny file, the configuration - and any one of them being served is the verdict.',
    ],
  },
  {
    id: 'security-instructions', view: 'security', target: '[data-tour="security-instructions"]',
    title: 'Security - how to fix it',
    body: [
      'Instructions for the server that was detected, with the configuration block to paste, and the one arrangement that is safe everywhere: the data directory outside the web root.',
    ],
  },
  {
    id: 'security-ack', view: 'security', target: '[data-tour="security-acknowledge"]',
    title: 'Security - accepting the risk',
    body: [
      'When the check cannot pass but you have verified the server by hand, an administrator can unlock the secret fields deliberately: type the code shown in the box and press the button. It is recorded with your name.',
    ],
  },
];

const FINISH = (admin) => ({
  id: 'finish',
  title: 'That is everything',
  body: admin
    ? [
      'The usual order on a new installation: Security first, then the scheduler under Settings, then a profile with an AI account and a BookStack instance, then the first course.',
      'The full documentation - docs.md in the installation - goes deeper on every screen you have just seen.',
    ]
    : [
      'The usual order: a profile with an AI account and a BookStack instance, then a course - outline, pages, publish.',
      'Ask an administrator for anything greyed out, and open this tour again from the sidebar whenever you like.',
    ],
});

/* ------------------------------------------------------------------ control */

function build(kind) {
  const admin = kind === 'admin';
  const all = [WELCOME(admin), ...WORKSPACE, ...(admin ? ADMIN : []), FINISH(admin)];
  return all.filter((step) => !step.when || step.when());
}

/** Starts the tour for the signed-in account's role. */
export function startTour(kind = null) {
  tour.kind = kind ?? (isAdmin.value ? 'admin' : 'user');
  tour.steps = build(tour.kind);
  tour.index = 0;
  tour.active = true;
  state.sidebarOpen = false;
}

export function stopTour() {
  tour.active = false;
  tour.steps = [];
  tour.index = 0;
}

export function nextStep() {
  if (tour.index < tour.steps.length - 1) tour.index += 1;
  else stopTour();
}

export function prevStep() {
  if (tour.index > 0) tour.index -= 1;
}

export function goToStep(index) {
  if (index >= 0 && index < tour.steps.length) tour.index = index;
}
