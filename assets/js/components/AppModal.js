/** A focus-trapping dialog. Escape and a click on the scrim both close it. */
import { onMounted, onBeforeUnmount, ref, nextTick } from 'vue';
import AppIcon from './AppIcon.js';

export const AppModal = {
  name: 'AppModal',
  components: { AppIcon },
  props: {
    title: { type: String, default: '' },
    icon: { type: String, default: '' },
    wide: { type: Boolean, default: false },
  },
  emits: ['close'],
  setup(props, { emit }) {
    const panel = ref(null);

    const onKey = (event) => {
      if (event.key === 'Escape') emit('close');
    };

    onMounted(async () => {
      document.addEventListener('keydown', onKey);
      await nextTick();
      panel.value?.querySelector('input, textarea, button')?.focus();
    });
    onBeforeUnmount(() => document.removeEventListener('keydown', onKey));

    return { panel };
  },
  template: `
    <div class="modal-scrim" @click.self="$emit('close')">
      <div class="modal" :class="{ 'modal--wide': wide }" ref="panel" role="dialog" aria-modal="true">
        <div v-if="title" class="modal__head">
          <app-icon v-if="icon" :name="icon" :size="18" class="c-accent"/>
          <h3 class="grow">{{ title }}</h3>
          <button class="btn btn--ghost btn--sm btn--icon" @click="$emit('close')" aria-label="Close">
            <app-icon name="x" :size="15"/>
          </button>
        </div>
        <div class="modal__body"><slot/></div>
        <div class="modal__foot"><slot name="footer"/></div>
      </div>
    </div>`,
};

export default AppModal;
