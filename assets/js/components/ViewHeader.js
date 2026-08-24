/**
 * The bar at the top of every view: a title, optional breadcrumbs, an actions
 * slot and – below 1024px – the button that opens the navigation drawer.
 */
import { state } from '@/core/store.js';
import AppIcon from './AppIcon.js';

export const ViewHeader = {
  name: 'ViewHeader',
  components: { AppIcon },
  props: {
    title: { type: String, default: '' },
    icon: { type: String, default: '' },
  },
  setup() {
    return { state };
  },
  template: `
    <header class="topbar">
      <button class="btn btn--ghost btn--icon menu-toggle none" aria-label="Open navigation"
              @click="state.sidebarOpen = true">
        <app-icon name="menu" :size="18"/>
      </button>

      <slot name="lead"/>

      <div class="row gap-2 grow" style="min-width:0">
        <app-icon v-if="icon" :name="icon" :size="17" class="c-accent none hide-sm"/>
        <h1 v-if="title" class="topbar__title truncate">{{ title }}</h1>
        <slot name="title"/>
      </div>

      <div class="row gap-2 none"><slot name="actions"/></div>
    </header>`,
};

export default ViewHeader;
