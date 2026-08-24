/**
 * The application shell: sidebar, active view, account dialog and toasts.
 *
 * Navigation is a single string in the store rather than a router, because the
 * app has four destinations and no shareable URLs – adding history handling
 * would only buy back-button semantics nobody asked for.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { state, isSignedIn, loadSession, loadWorkspace, go, signOut } from '@/core/store.js';
import { toast, attempt, toasts, dismiss } from '@/core/toast.js';
import { post } from '@/core/api.js';
import { resolvedTheme, toggleTheme } from '@/core/theme.js';

import AppIcon from '@/components/AppIcon.js';
import AppModal from '@/components/AppModal.js';
import LoginView from '@/views/LoginView.js';
import ProjectsView from '@/views/ProjectsView.js';
import ProjectView from '@/views/ProjectView.js';
import TagsView from '@/views/TagsView.js';
import ProfilesView from '@/views/ProfilesView.js';
import ConnectView from '@/views/ConnectView.js';

const NAV = [
  { view: 'projects', label: 'Courses', icon: 'book', count: () => state.projects.length },
  { view: 'tags', label: 'Tags', icon: 'tag', count: () => state.tags.length },
  { view: 'profiles', label: 'Profiles', icon: 'sliders', count: () => state.profiles.length },
  { view: 'connect', label: 'Connect', icon: 'link', count: null },
];

export const App = {
  name: 'CourseForge',
  components: { AppIcon, AppModal, LoginView, ProjectsView, ProjectView, TagsView, ProfilesView, ConnectView },
  setup() {
    const showAccount = ref(false);
    const password = reactive({ old: '', next: '', busy: false });

    onMounted(async () => {
      await attempt(async () => {
        await loadSession();
        if (state.user) await loadWorkspace();
      }, 'Startup');
      state.ready = true;
    });

    const activeView = computed(() => {
      if (state.view === 'project' && state.project) return 'project-view';
      return { tags: 'tags-view', profiles: 'profiles-view', connect: 'connect-view' }[state.view] ?? 'projects-view';
    });

    const navItems = computed(() =>
      NAV.map((item) => ({
        ...item,
        total: item.count ? item.count() : null,
        active: item.view === state.view || (item.view === 'projects' && state.view === 'project'),
      }))
    );

    const changePassword = () => attempt(async () => {
      if (password.next.length < 8) {
        toast.error('The new password must be at least 8 characters.');
        return;
      }
      password.busy = true;
      try {
        await post('account/password', { old: password.old, new: password.next });
        password.old = '';
        password.next = '';
        showAccount.value = false;
        toast.success('Password changed.');
      } finally {
        password.busy = false;
      }
    }, 'Change password');

    const logout = () => attempt(async () => {
      await signOut();
      showAccount.value = false;
    }, 'Sign out');

    return {
      state, isSignedIn, activeView, navItems, go, toasts, dismiss,
      showAccount, password, changePassword, logout,
      resolvedTheme, toggleTheme,
      toastIcon: (type) => ({ success: 'check-circle', error: 'alert-circle' }[type] ?? 'info'),
    };
  },
  template: `
    <div v-if="!state.ready" class="app-boot">
      <div class="boot-splash"><div class="boot-splash__mark"></div><p>Loading CourseForge…</p></div>
    </div>

    <login-view v-else-if="!isSignedIn"/>

    <div v-else class="shell">
      <div v-if="state.sidebarOpen" class="scrim" @click="state.sidebarOpen = false"></div>

      <aside class="sidebar" :class="{ 'is-open': state.sidebarOpen }">
        <div class="sidebar__brand">
          <span class="sidebar__mark"><app-icon name="book" :size="16"/></span>
          <span class="sidebar__name grow truncate">{{ state.app.name }}</span>
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
        </nav>

        <div class="sidebar__foot">
          <button class="nav-item" @click="toggleTheme">
            <app-icon :name="resolvedTheme === 'dark' ? 'moon' : 'sun'" :size="16"/>
            <span class="grow truncate">{{ resolvedTheme === 'dark' ? 'Dark' : 'Light' }} theme</span>
          </button>
          <button class="nav-item" @click="showAccount = true">
            <app-icon name="user" :size="16"/>
            <span class="grow truncate">{{ state.user.display_name }}</span>
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

      <app-modal v-if="showAccount" title="Account" icon="user" @close="showAccount = false">
        <div class="col gap-4">
          <p class="hint">Signed in as <strong>{{ state.user.username }}</strong>.</p>
          <div class="form-row">
            <label for="pw-old">Current password</label>
            <input id="pw-old" v-model="password.old" type="password" autocomplete="current-password">
          </div>
          <div class="form-row">
            <label for="pw-new">New password</label>
            <input id="pw-new" v-model="password.next" type="password" autocomplete="new-password"
                   @keydown.enter="changePassword">
            <p class="hint">At least 8 characters, and different from the current one.</p>
          </div>
        </div>
        <template #footer>
          <button class="btn" @click="showAccount = false">Cancel</button>
          <button class="btn btn--primary" :disabled="password.busy || !password.old || !password.next"
                  @click="changePassword">
            {{ password.busy ? 'Saving…' : 'Change password' }}
          </button>
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
