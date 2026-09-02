/**
 * The bar at the top of every view: a glyph on a tile, a title, one line on
 * what the screen is for, an actions slot and - below 1024px - the button that
 * opens the navigation drawer.
 *
 * The subtitle is the sentence a person needs before they read anything else
 * on the screen. It is one line, it is cut short rather than wrapped, and it
 * goes on a phone, where the header has room for the title alone.
 */
import { state } from '@/core/store.js';
import AppIcon from '@/components/AppIcon.js';

export const ViewHeader = {
  name: 'ViewHeader',
  components: { AppIcon },
  props: {
    title: { type: String, default: '' },
    icon: { type: String, default: '' },
    subtitle: { type: String, default: '' },
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

      <div class="row gap-3 grow" style="min-width:0">
        <span v-if="icon" class="tile tile--accent none hide-sm"><app-icon :name="icon" :size="17"/></span>
        <div class="topbar__titles grow">
          <h1 v-if="title" class="topbar__title truncate">{{ title }}</h1>
          <slot name="title"/>
          <span v-if="subtitle" class="topbar__sub">{{ subtitle }}</span>
        </div>
      </div>

      <div class="row gap-2 none"><slot name="actions"/></div>
    </header>`,
};

export default ViewHeader;
