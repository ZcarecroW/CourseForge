import { computed } from 'vue';
import { state, openCourse, currentProfile, closeProject, refreshProject } from '@/core/store.js';
import { attempt } from '@/core/toast.js';

import AppIcon from '@/components/AppIcon.js';
import ViewHeader from '@/components/ViewHeader.js';
import StructureTab from './project/StructureTab.js';
import ContentTab from './project/ContentTab.js';
import DetailsTab from './project/DetailsTab.js';
import PublishTab from './project/PublishTab.js';
import SettingsTab from './project/SettingsTab.js';

const TABS = [
  { key: 'structure', label: 'Structure', icon: 'sitemap' },
  { key: 'content', label: 'Content', icon: 'file-text' },
  { key: 'details', label: 'Details', icon: 'sliders' },
  { key: 'publish', label: 'Publish', icon: 'upload' },
  { key: 'settings', label: 'Settings', icon: 'cog' },
];

export const ProjectView = {
  name: 'ProjectView',
  components: { AppIcon, ViewHeader, StructureTab, ContentTab, DetailsTab, PublishTab, SettingsTab },
  setup() {
    const project = openCourse;
    const stats = computed(() => project.value?.stats ?? { pages: 0, generated: 0, pushed: 0, dirty: 0, errors: 0, links: {} });
    /* The course's own name, and nothing else. `book_title` is the title of the
       published book, which the outline sets and which is allowed to differ
       from it; preferring it here gave one course two names on screen, because
       the course list shows `name` - the header said one thing and the card
       just clicked said another. The Structure tab names the book title
       wherever the two differ. */
    const title = computed(() => project.value?.name || 'Course');

    const tabs = computed(() => TABS.map((tab) => ({
      ...tab,
      badge: tab.key === 'content' && stats.value.errors ? String(stats.value.errors) : '',
    })));

    const activeTab = computed(() => `${state.projectTab}-tab`);

    return {
      state, stats, title, tabs, activeTab, currentProfile,
      closeProject,
      refresh: () => attempt(refreshProject, 'Reload'),
    };
  },
  template: `
    <view-header :title="title">
      <template #lead>
        <button class="btn btn--ghost btn--icon" title="Back to all courses" @click="closeProject">
          <app-icon name="arrow-left" :size="17"/>
        </button>
      </template>

      <template #actions>
        <div class="row gap-3 t-xs dim hide-sm nums">
          <span :title="stats.generated + ' of ' + stats.pages + ' pages written'">
            <app-icon name="file-text" :size="12"/>
            {{ stats.generated }}/{{ stats.pages }}
          </span>
          <span v-if="stats.pushed" class="c-success" :title="stats.pushed + ' pages published'">
            <app-icon name="upload" :size="12"/> {{ stats.pushed }}
          </span>
          <span v-if="stats.dirty" class="c-warning" :title="stats.dirty + ' pages changed since the last publish'">
            <app-icon name="alert-circle" :size="12"/> {{ stats.dirty }}
          </span>
          <span v-if="stats.errors" class="c-danger" :title="stats.errors + ' pages failed to generate'">
            <app-icon name="x-circle" :size="12"/> {{ stats.errors }}
          </span>
          <span v-if="stats.links && stats.links.markers" class="c-accent"
                :title="stats.links.resolved + ' of ' + stats.links.markers + ' cross references resolve to a real link'">
            <app-icon name="link" :size="12"/> {{ stats.links.resolved }}/{{ stats.links.markers }}
          </span>
        </div>
        <span class="divider hide-sm" style="width:1px;height:20px"></span>
        <span class="badge hide-sm">{{ currentProfile ? currentProfile.name : 'no profile' }}</span>
        <button class="btn btn--ghost btn--icon" title="Reload this course" @click="refresh">
          <app-icon name="refresh" :size="15"/>
        </button>
      </template>
    </view-header>

    <nav class="tabbar">
      <button v-for="tab in tabs" :key="tab.key" class="tab" :class="{ 'is-active': state.projectTab === tab.key }"
              @click="state.projectTab = tab.key">
        <app-icon :name="tab.icon" :size="14"/>{{ tab.label }}
        <span v-if="tab.badge" class="badge badge--danger">{{ tab.badge }}</span>
      </button>
    </nav>

    <!-- keep-alive so switching tabs never discards an unsaved draft,
         a scroll position or a running generation -->
    <keep-alive>
      <component :is="activeTab"/>
    </keep-alive>`,
};

export default ProjectView;
