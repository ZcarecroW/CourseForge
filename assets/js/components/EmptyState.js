/** The "nothing here yet" block, so every empty list explains itself the same way. */
import AppIcon from '@/components/AppIcon.js';

export const EmptyState = {
  name: 'EmptyState',
  components: { AppIcon },
  props: {
    icon: { type: String, default: 'file-text' },
    title: { type: String, default: '' },
    hint: { type: String, default: '' },
  },
  template: `
    <div class="empty">
      <span class="empty__icon"><app-icon :name="icon" :size="22" :stroke="1.5"/></span>
      <p v-if="title" class="empty__title">{{ title }}</p>
      <p v-if="hint" class="t-sm dim" style="max-width:46ch">{{ hint }}</p>
      <slot/>
    </div>`,
};

export default EmptyState;
