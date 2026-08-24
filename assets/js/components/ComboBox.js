/**
 * A text input with a fuzzy-searched dropdown.
 *
 * Free typing always wins: the list is a shortcut, never a constraint, which
 * matters for model ids and languages the provider list does not know about.
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useFuzzy } from '@/core/fuzzy.js';
import AppIcon from './AppIcon.js';

export const ComboBox = {
  name: 'ComboBox',
  components: { AppIcon },
  props: {
    modelValue: { type: String, default: '' },
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    icon: { type: String, default: '' },
  },
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    const open = ref(false);
    const active = ref(-1);
    const root = ref(null);

    const query = computed({
      get: () => props.modelValue ?? '',
      set: (value) => emit('update:modelValue', value),
    });

    const results = useFuzzy(
      computed(() => props.options),
      query,
      { limit: 80 }
    );

    const onInput = (event) => {
      query.value = event.target.value;
      open.value = true;
      active.value = -1;
    };

    const pick = (value) => {
      query.value = value;
      open.value = false;
      active.value = -1;
    };

    const move = (delta) => {
      if (!open.value) {
        open.value = true;
        return;
      }
      const count = results.value.length;
      if (count === 0) return;
      active.value = (active.value + delta + count) % count;
    };

    const commit = () => {
      if (open.value && active.value > -1 && results.value[active.value] !== undefined) {
        pick(results.value[active.value]);
      } else {
        open.value = false;
      }
    };

    const onOutside = (event) => {
      if (root.value && !root.value.contains(event.target)) open.value = false;
    };
    onMounted(() => document.addEventListener('mousedown', onOutside));
    onBeforeUnmount(() => document.removeEventListener('mousedown', onOutside));

    return { open, active, root, query, results, onInput, pick, move, commit };
  },
  template: `
    <div class="grow" ref="root" style="position:relative">
      <div style="position:relative">
        <app-icon v-if="icon" :name="icon" :size="14"
                  style="position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--text-faint)"/>
        <input :value="query" @input="onInput" @focus="open = true" :disabled="disabled"
               :placeholder="placeholder" spellcheck="false" autocomplete="off"
               :style="icon ? 'padding-left:28px' : ''"
               @keydown.down.prevent="move(1)"
               @keydown.up.prevent="move(-1)"
               @keydown.enter.prevent="commit"
               @keydown.esc="open = false">
      </div>
      <div v-if="open && results.length" class="popover">
        <button type="button" v-for="(option, i) in results" :key="option"
                class="popover__item" :class="{ 'is-active': i === active }"
                @mousedown.prevent="pick(option)" @mouseenter="active = i">
          <span class="truncate">{{ option }}</span>
        </button>
      </div>
      <div v-else-if="open && query && options.length" class="popover">
        <p class="popover__empty">No match — press Enter to keep what you typed.</p>
      </div>
    </div>`,
};

export default ComboBox;
