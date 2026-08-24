/**
 * The application shell: sidebar, active view, account dialog and toasts.
 *
 * Navigation is a single string in the store rather than a router, because the
 * app has a handful of destinations and no shareable URLs - adding history
 * handling would only buy back-button semantics nobody asked for.
 *
 * Because the shell owns navigation it is also what stands between somebody
 * and their unsaved work: the store stops a move away from a screen that has
 * declared unsaved edits, and the dialog below asks whether to make it anyway.
 * A screen never has to guard its own exits, and no screen can forget to.
 *
 * The shell also owns the four gates a person passes through before they see
 * anything: the splash while the first requests are in flight, the setup screen
 * on an installation that has no accounts yet, the sign-in form, and finally
 * the workspace. Each is decided by one fact from the server, never guessed.
 *
 * Administrator screens are loaded on demand. They are the four heaviest views
 * in the application and most accounts can never open them, so they are not
 * part of what a normal sign-in downloads. That also means the files may not be
 * present at all in a partial checkout, which is why each has a loading state
 * and a failure state instead of taking the whole shell down with it.
 */
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount, defineAsyncComponent } from 'vue';
import {
  state, isSignedIn, isAdmin, minPassword, mustChangePassword, ADMIN_VIEWS,
  loadSetup, loadSession, loadWorkspace, probeUpdate, go, stayPut, signOut,
} from '@/core/store.js';
import { toast, attempt, toasts, dismiss } from '@/core/toast.js';
import { post, put } from '@/core/api.js';
import { resolvedTheme, toggleTheme } from '@/core/theme.js';

import AppIcon from '@/components/AppIcon.js';
import AppModal, { anyDialogOpen } from '@/components/AppModal.js';
import LoginView from '@/views/LoginView.js';
import SetupView from '@/views/SetupView.js';
import ProjectsView from '@/views/ProjectsView.js';
import ProjectView from '@/views/ProjectView.js';
import TagsView from '@/views/TagsView.js';
import ProfilesView from '@/views/ProfilesView.js';
import ConnectView from '@/views/ConnectView.js';

/* ------------------------------------------------------- deferred screens */

/** Shown for as long as an administrator screen takes to arrive. */
const ScreenLoading = {
  name: 'ScreenLoading',
  template: `
    <div class="view app-boot">
      <div class="boot-splash"><div class="boot-splash__mark"></div><p>Opening…</p></div>
    </div>`,
};

/**
 * Shown when the screen cannot be fetched at all - a half-finished upload, a
 * file removed by hand, a proxy that swallowed it. The message names the screen
 * and says what to do, because "failed to load module" helps nobody.
 */
const screenUnavailable = (label) => ({
  name: 'ScreenUnavailable',
  components: { AppIcon },
  setup: () => ({ label }),
  template: `
    <div class="view app-boot view-pad">
      <div class="empty">
        <span class="empty__icon"><app-icon name="alert" :size="20"/></span>
        <p class="empty__title">The {{ label }} screen is not available</p>
        <p class="t-sm dim" style="max-width:46ch">
          This part of CourseForge could not be loaded from the server. Reload the page; if it keeps
          happening, the installation is missing files and should be re-uploaded.
        </p>
      </div>
    </div>`,
});

const adminScreen = (label, loader) => defineAsyncComponent({
  loader,
  loadingComponent: ScreenLoading,
  errorComponent: screenUnavailable(label),
  // Long enough that a screen already in the browser cache never flashes a
  // spinner, short enough that a slow one does not look frozen.
  delay: 150,
  timeout: 20000,
});

const UsersView = adminScreen('Accounts', () => import('@/views/admin/UsersView.js'));
const SettingsView = adminScreen('Settings', () => import('@/views/admin/SettingsView.js'));
const PromptsView = adminScreen('Prompts', () => import('@/views/admin/PromptsView.js'));
const UpdatesView = adminScreen('Updates', () => import('@/views/admin/UpdatesView.js'));

/* -------------------------------------------------------------- navigation */

const NAV = [
  { view: 'projects', label: 'Courses', icon: 'book', count: () => state.projects.length },
  { view: 'tags', label: 'Tags', icon: 'tag', count: () => state.tags.length },
  { view: 'profiles', label: 'Profiles', icon: 'sliders', count: () => state.profiles.length },
  { view: 'connect', label: 'Connect', icon: 'link', count: null },
];

/**
 * The second group. These are not counted the way the first group is: their
 * data is fetched when the screen opens, so a number in the sidebar would be
 * either stale or an extra request at sign-in for something nobody looked at.
 */
const ADMIN_NAV = [
  { view: 'users', label: 'Accounts', icon: 'users' },
  { view: 'settings', label: 'Settings', icon: 'cog' },
  { view: 'prompts', label: 'Prompts', icon: 'file-text' },
  { view: 'updates', label: 'Updates', icon: 'download' },
];

const COMPONENT_FOR = {
  projects: 'projects-view',
  tags: 'tags-view',
  profiles: 'profiles-view',
  connect: 'connect-view',
  users: 'users-view',
  settings: 'settings-view',
  prompts: 'prompts-view',
  updates: 'updates-view',
};

/* What a destination is called when it has to be named in a sentence. The
   store holds the view a held-up move was heading for; the words for it are
   the sidebar's, and the sidebar is here. */
const NAME_OF = Object.fromEntries(
  [...NAV, ...ADMIN_NAV].map((item) => [item.view, item.label]).concat([['project', 'the course']]),
);

export const App = {
  name: 'CourseForge',
  components: {
    AppIcon, AppModal, LoginView, SetupView,
    ProjectsView, ProjectView, TagsView, ProfilesView, ConnectView,
    UsersView, SettingsView, PromptsView, UpdatesView,
  },
  setup() {
    const showAccount = ref(false);
    const account = reactive({
      displayName: '', old: '', next: '', confirm: '', savingName: false, savingPassword: false,
    });

    onMounted(async () => {
      await attempt(async () => {
        // Setup goes first, always. Its answer decides which screen is drawn,
        // and on a brand-new installation this request is what publishes the
        // invite code the setup screen then asks for.
        await loadSetup();
        await loadSession();
        // Unguarded on purpose: loadWorkspace() knows to fetch nothing for an
        // account that owes a password change, so the refusals that would
        // otherwise turn into a "Startup" toast never happen.
        if (state.user) await loadWorkspace();
      }, 'Startup');
      state.ready = true;
    });

    /* On a narrow screen the navigation is a drawer over the page, with a
       scrim of its own - the same shape as a dialog, so it closes the same way
       a dialog does. A dialog on top of it answers Escape first, because that
       is the layer somebody is looking at. */
    const onEscape = (event) => {
      if (event.key !== 'Escape') return;
      if (!state.sidebarOpen || anyDialogOpen()) return;
      state.sidebarOpen = false;
    };
    onMounted(() => document.addEventListener('keydown', onEscape));
    onBeforeUnmount(() => document.removeEventListener('keydown', onEscape));

    /* The update badge: one request, the first time an administrator is here,
       and never again. Deliberately not a poll - an installation that checks
       GitHub every few minutes is a rate limit waiting to be hit, and a new
       release is not news that has to arrive within the minute. */
    watch(
      () => state.ready && isAdmin.value,
      (yes) => { if (yes) probeUpdate(); },
      { immediate: true },
    );

    const updateAvailable = computed(() => state.updateInfo?.available === true);

    /**
     * What this installation is called.
     *
     * state.app is answered once at sign-in and never asked for again, so it
     * goes stale the moment app.name is saved on the Settings screen: that
     * response carries the fresh settings catalogue but not the app block. The
     * shell is the only thing that shows the name - in the sidebar and in the
     * browser tab - so it reads whichever of the two sources is fresher, rather
     * than letting the two places it draws disagree with each other.
     *
     * "CourseForge" is the last resort, for an installation whose name has been
     * cleared: a blank sidebar and a blank tab are worse than generic ones.
     */
    const installationName = computed(() => {
      const field = state.settings.find((item) => item.key === 'app.name');
      return String(field?.value ?? state.app.name ?? '').trim() || 'CourseForge';
    });

    /* The Settings screen promises the name is "shown in the header and in the
       browser tab", so the tab is bound to it rather than written once at
       startup. index.html ships "CourseForge" as the pre-boot title, which is
       what the tab reads while the first requests are still in flight. */
    watch(
      installationName,
      (name) => { document.title = name; },
      { immediate: true },
    );

    /* An account handed its password by an administrator has to choose its own
       before it can do anything else. */
    watch(mustChangePassword, (must) => {
      if (must) openAccount();
    }, { immediate: true });

    const activeView = computed(() => {
      if (state.view === 'project' && state.project) return 'project-view';
      // A demotion takes effect on the next request, so an admin screen left
      // open by a session that is no longer an administrator steps aside.
      if (ADMIN_VIEWS.has(state.view) && !isAdmin.value) return 'projects-view';
      return COMPONENT_FOR[state.view] ?? 'projects-view';
    });

    const navItems = computed(() =>
      NAV.map((item) => ({
        ...item,
        total: item.count ? item.count() : null,
        active: item.view === state.view || (item.view === 'projects' && state.view === 'project'),
      }))
    );

    const adminItems = computed(() =>
      ADMIN_NAV.map((item) => ({
        ...item,
        active: item.view === state.view,
        badge: item.view === 'updates' && updateAvailable.value,
      }))
    );

    const roleLabel = computed(() => (isAdmin.value ? 'Administrator' : 'User'));

    /* ------------------------------------------------------ unsaved work */

    /* The store stops a move away from a screen that is holding unsaved edits
       and puts the destination aside; the shell is what asks about it, because
       asking is a dialog and the store never renders one. Leaving is still the
       person's decision - this only makes sure it is one they made. */

    const leavingFor = computed(() => NAME_OF[state.leaving?.view] ?? 'another screen');

    const leaveAnyway = () => {
      const view = state.leaving?.view;
      if (view) go(view, { discard: true });
    };

    /* ------------------------------------------------------------ account */

    function openAccount() {
      account.displayName = state.user?.display_name ?? '';
      account.old = '';
      account.next = '';
      account.confirm = '';
      showAccount.value = true;
    }

    /** Refused while a password change is owed, so the dialog cannot be escaped. */
    const closeAccount = () => {
      if (mustChangePassword.value) return;
      showAccount.value = false;
    };

    const saveDisplayName = () => attempt(async () => {
      account.savingName = true;
      try {
        const data = await put('account', { display_name: account.displayName.trim() });
        state.user = data.user;
        toast.success('Name updated.');
      } finally {
        account.savingName = false;
      }
    }, 'Save name');

    const changePassword = () => attempt(async () => {
      if (account.next.length < minPassword.value) {
        toast.error(`The new password must be at least ${minPassword.value} characters.`);
        return;
      }
      if (account.next !== account.confirm) {
        toast.error('The two new passwords do not match.');
        return;
      }

      // Whether this is the change the account owed decides what happens after
      // it: an account that owed one has been refused every other route since
      // it signed in, so there is an empty workspace waiting behind this dialog.
      const owed = mustChangePassword.value;

      account.savingPassword = true;
      try {
        const data = await post('account/password', { old: account.old, new: account.next });
        // The response carries the account as it now stands, which is what
        // clears the "must change password" flag and unlocks the dialog.
        state.user = data.user ?? state.user;
        account.old = '';
        account.next = '';
        account.confirm = '';
        // Fetched before the dialog goes, so that what is uncovered is the
        // real workspace rather than an empty one that fills in a moment later.
        if (owed) await loadWorkspace();
        showAccount.value = false;
        toast.success('Password changed.');
      } finally {
        account.savingPassword = false;
      }
    }, 'Change password');

    const logout = () => attempt(async () => {
      await signOut();
      showAccount.value = false;
    }, 'Sign out');

    return {
      state, isSignedIn, isAdmin, minPassword, mustChangePassword,
      activeView, navItems, adminItems, updateAvailable, roleLabel, go, installationName,
      leavingFor, leaveAnyway, stayPut,
      toasts, dismiss,
      showAccount, account, openAccount, closeAccount, saveDisplayName, changePassword, logout,
      resolvedTheme, toggleTheme,
      toastIcon: (type) => ({ success: 'check-circle', error: 'alert-circle' }[type] ?? 'info'),
    };
  },
  template: `
    <div v-if="!state.ready" class="app-boot">
      <div class="boot-splash"><div class="boot-splash__mark"></div><p>Loading CourseForge…</p></div>
    </div>

    <setup-view v-else-if="state.needsSetup"/>

    <!-- Signed out, which is two screens rather than one: the sign-in form, and
         the same form the first run uses, for somebody who was sent an invite
         code and so has no account to sign in to yet. -->
    <template v-else-if="!isSignedIn">
      <setup-view v-if="state.redeeming" mode="redeem"/>
      <login-view v-else/>
    </template>

    <div v-else class="shell">
      <div v-if="state.sidebarOpen" class="scrim" @click="state.sidebarOpen = false"></div>

      <aside class="sidebar" :class="{ 'is-open': state.sidebarOpen }">
        <div class="sidebar__brand">
          <span class="sidebar__mark"><app-icon name="book" :size="16"/></span>
          <span class="sidebar__name grow truncate">{{ installationName }}</span>
          <button class="btn btn--ghost btn--sm btn--icon menu-toggle" aria-label="Close navigation"
                  @click="state.sidebarOpen = false">
            <app-icon name="x" :size="15"/>
          </button>
        </div>

        <nav class="sidebar__nav">
          <button v-for="item in navItems" :key="item.view" class="nav-item"
                  :class="{ 'is-active': item.active }" @click="go(item.view)">
            <app-icon :name="item.icon" :size="16"/>
            <span class="grow truncate">{{ item.label }}</span>
            <span v-if="item.count !== null" class="nav-item__count">{{ item.total }}</span>
          </button>

          <div v-if="isAdmin" class="nav-group">
            <p class="eyebrow nav-group__title">Administration</p>
            <button v-for="item in adminItems" :key="item.view" class="nav-item"
                    :class="{ 'is-active': item.active }" @click="go(item.view)">
              <app-icon :name="item.icon" :size="16"/>
              <span class="grow truncate">{{ item.label }}</span>
              <span v-if="item.badge" class="badge badge--accent none" title="A newer version has been published">
                new
              </span>
            </button>
          </div>
        </nav>

        <div class="sidebar__foot">
          <button class="nav-item" @click="toggleTheme">
            <app-icon :name="resolvedTheme === 'dark' ? 'moon' : 'sun'" :size="16"/>
            <span class="grow truncate">{{ resolvedTheme === 'dark' ? 'Dark' : 'Light' }} theme</span>
          </button>
          <button class="nav-item" @click="openAccount">
            <app-icon name="user" :size="16"/>
            <span class="grow truncate">{{ state.user.display_name }}</span>
            <span class="badge none" :class="isAdmin ? 'badge--accent' : 'badge--outline'">{{ roleLabel }}</span>
          </button>
          <button class="nav-item" @click="logout">
            <app-icon name="log-out" :size="16"/>
            <span class="grow truncate">Sign out</span>
          </button>
          <p class="t-2xs faint" style="padding:0 10px">v{{ state.app.version }}</p>
        </div>
      </aside>

      <main class="main">
        <component :is="activeView" :key="state.project ? 'course-' + state.project.id : activeView"/>
      </main>

      <!-- No title while a password change is owed: AppModal draws a close
           button next to a title, and a button that refuses to close would be
           worse than not offering one. -->
      <app-modal v-if="showAccount" :title="mustChangePassword ? '' : 'Account'" icon="user"
                 @close="closeAccount">
        <div class="col gap-4">
          <div v-if="mustChangePassword" class="row-top gap-3">
            <app-icon name="alert" :size="18" class="c-warning none" style="margin-top:2px"/>
            <div class="col gap-1">
              <h3 class="t-md">Choose your own password</h3>
              <p class="hint">
                This account is still using the password somebody else set for it. Choose one only you know
                before you carry on - it is the one thing nobody else can do for you.
              </p>
            </div>
          </div>

          <p v-else class="hint">
            Signed in as <strong>{{ state.user.username }}</strong> ({{ roleLabel }}).
          </p>

          <template v-if="!mustChangePassword">
            <div class="form-row">
              <label for="acc-name">Display name</label>
              <div class="row gap-2">
                <input id="acc-name" v-model="account.displayName" class="grow" autocomplete="name"
                       @keydown.enter="saveDisplayName">
                <button class="btn none" :disabled="account.savingName || !account.displayName.trim()"
                        @click="saveDisplayName">
                  {{ account.savingName ? 'Saving…' : 'Save' }}
                </button>
              </div>
              <p class="hint">
                Shown in the sidebar and against everything you create. Your user name stays
                <strong>{{ state.user.username }}</strong>.
              </p>
            </div>

            <div class="divider"></div>
          </template>

          <div class="form-row">
            <label for="pw-old">Current password</label>
            <input id="pw-old" v-model="account.old" type="password" autocomplete="current-password">
          </div>
          <div class="form-row">
            <label for="pw-new">New password</label>
            <input id="pw-new" v-model="account.next" type="password" autocomplete="new-password">
            <p class="hint">At least {{ minPassword }} characters, and different from the current one.</p>
          </div>
          <div class="form-row">
            <label for="pw-confirm">New password again</label>
            <input id="pw-confirm" v-model="account.confirm" type="password" autocomplete="new-password"
                   @keydown.enter="changePassword">
          </div>
        </div>

        <template #footer>
          <button v-if="mustChangePassword" class="btn btn--ghost" @click="logout">Sign out instead</button>
          <button v-else class="btn" @click="closeAccount">Close</button>
          <button class="btn btn--primary"
                  :disabled="account.savingPassword || !account.old || !account.next || !account.confirm"
                  @click="changePassword">
            {{ account.savingPassword ? 'Saving…' : 'Change password' }}
          </button>
        </template>
      </app-modal>

      <!-- Raised by the store when a screen holding unsaved edits is about to
           be left. "Stay" comes first, so it is what has the focus and what
           Enter does. -->
      <app-modal v-if="state.leaving" title="Leave without saving?" icon="alert" @close="stayPut">
        <p class="hint">
          This screen is holding <strong>{{ state.leaving.summary }}</strong>. Going to
          {{ leavingFor }} now throws that work away, and there is no way to get it back.
        </p>
        <p class="hint">Staying brings you back to it exactly as you left it.</p>

        <template #footer>
          <button class="btn" @click="stayPut">Stay on this screen</button>
          <button class="btn btn--danger" @click="leaveAnyway">Leave and discard</button>
        </template>
      </app-modal>
    </div>

    <div class="toasts" role="status" aria-live="polite">
      <div v-for="item in toasts" :key="item.id" class="toast" :class="'toast--' + item.type">
        <app-icon class="toast__icon" :name="toastIcon(item.type)" :size="16"/>
        <span class="toast__text">{{ item.message }}</span>
        <button class="chip__btn" @click="dismiss(item.id)" aria-label="Dismiss">
          <app-icon name="x" :size="13"/>
        </button>
      </div>
    </div>`,
};

export default App;
