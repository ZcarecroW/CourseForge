/**
 * Tag chips plus a fuzzy picker, for a course, a chapter or a page.
 *
 * Colour is the whole legend: accent = attached by hand, violet = written by
 * the AI into the structure, dashed = arriving through inheritance, struck
 * through = deactivated but kept.
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { state } from '@/core/store.js';
import { useFuzzy } from '@/core/fuzzy.js';
import AppIcon from './AppIcon.js';

export const TagPicker = {
  name: 'TagPicker',
  components: { AppIcon },
  props: {
    label: { type: String, default: 'Tags' },
    tags: { type: Array, default: () => [] },        // directly assigned
    inherited: { type: Array, default: () => [] },   // arriving from above
    canInherit: { type: Boolean, default: true },    // false for pages
    busy: { type: Boolean, default: false },
  },
  emits: ['add', 'remove', 'inherit', 'toggle'],
  setup(props, { emit }) {
    const name = ref('');
    const value = ref('');
    const inheritNew = ref(false);
    const open = ref(false);
    const root = ref(null);

    const assigned = computed(() => new Set(props.tags.map((t) => t.name.toLowerCase())));
    const matches = useFuzzy(computed(() => state.tags), name, { keys: ['name', 'value'], limit: 40 });
    const suggestions = computed(() =>
      matches.value.filter((t) => !assigned.value.has(t.name.toLowerCase())).slice(0, 10)
    );

    const submit = () => {
      const clean = name.value.trim();
      if (!clean) return;
      emit('add', { name: clean, value: value.value.trim(), inherit: inheritNew.value });
      name.value = '';
      value.value = '';
      open.value = false;
    };

    const pick = (tag) => {
      emit('add', { name: tag.name, value: tag.value || '', inherit: inheritNew.value });
      name.value = '';
      value.value = '';
      open.value = false;
    };

    const isOff = (tag) => tag.enabled === false;
    const chipClass = (tag) => (isOff(tag) ? 'chip--off' : tag.auto ? 'chip--magic' : '');

    const onOutside = (event) => {
      if (root.value && !root.value.contains(event.target)) open.value = false;
    };
    onMounted(() => document.addEventListener('mousedown', onOutside));
    onBeforeUnmount(() => document.removeEventListener('mousedown', onOutside));

    return { name, value, inheritNew, open, root, suggestions, submit, pick, isOff, chipClass };
  },
  template: `
    <div ref="root" class="col gap-2">
      <div class="row between gap-2">
        <span class="eyebrow">{{ label }}</span>
        <label v-if="canInherit" class="row gap-1 t-2xs dim" style="cursor:pointer">
          <input type="checkbox" v-model="inheritNew">
          <app-icon name="sitemap" :size="12"/> new tags inherit
        </label>
      </div>

      <div class="row wrap gap-1">
        <span v-for="tag in tags" :key="'own-' + tag.id" class="chip" :class="chipClass(tag)"
              :title="tag.auto ? (isOff(tag) ? 'Written by the AI — deactivated' : 'Written by the AI into the structure') : 'Attached by hand'">
          <app-icon v-if="tag.auto" name="sparkles" :size="11"/>
          <span class="truncate">{{ tag.name }}<span v-if="tag.value" class="chip__value">={{ tag.value }}</span></span>

          <button v-if="tag.auto" class="chip__btn" :disabled="busy"
                  :title="isOff(tag) ? 'Deactivated — click to use it again' : 'Active — click to deactivate'"
                  @click="$emit('toggle', { tag_id: tag.id, enabled: isOff(tag) })">
            <app-icon :name="isOff(tag) ? 'eye-off' : 'eye'" :size="11"/>
          </button>

          <button v-if="canInherit" class="chip__btn" :disabled="busy"
                  :title="tag.inherit ? 'Inherited by everything below — click to stop' : 'Not inherited — click to pass it down'"
                  :style="tag.inherit ? 'color:var(--success);opacity:1' : ''"
                  @click="$emit('inherit', { tag_id: tag.id, inherit: !tag.inherit })">
            <app-icon :name="tag.inherit ? 'sitemap' : 'unlink'" :size="11"/>
          </button>

          <button class="chip__btn chip__btn--danger" :disabled="busy" title="Remove this tag"
                  @click="$emit('remove', { tag_id: tag.id })">
            <app-icon name="x" :size="11"/>
          </button>
        </span>

        <span v-for="tag in inherited" :key="'inh-' + tag.id" class="chip chip--inherited"
              title="Arrives through inheritance from the course or the chapter">
          <app-icon name="inherit" :size="11"/>
          <span class="truncate">{{ tag.name }}<span v-if="tag.value" class="chip__value">={{ tag.value }}</span></span>
        </span>

        <span v-if="!tags.length && !inherited.length" class="t-xs faint">No tags yet.</span>
      </div>

      <div class="row gap-2" style="position:relative">
        <input v-model="name" @focus="open = true" @keydown.enter.prevent="submit" @keydown.esc="open = false"
               class="grow" placeholder="Add or search a tag…" spellcheck="false" autocomplete="off">
        <input v-model="value" @keydown.enter.prevent="submit" placeholder="Value"
               style="width:96px;flex:none" title="Optional BookStack tag value">
        <button class="btn btn--primary btn--icon none" :disabled="busy || !name.trim()" @click="submit" title="Attach">
          <app-icon name="plus" :size="14"/>
        </button>

        <div v-if="open && suggestions.length" class="popover" style="width:280px">
          <button type="button" v-for="tag in suggestions" :key="tag.id" class="popover__item"
                  @mousedown.prevent="pick(tag)">
            <span class="truncate grow">{{ tag.name }}</span>
            <span v-if="tag.value" class="t-2xs faint">{{ tag.value }}</span>
            <span class="t-2xs faint nums">{{ tag.usage_count }}×</span>
          </button>
        </div>
      </div>

      <p class="t-2xs faint">Enter attaches. An unknown name creates the tag.</p>
    </div>`,
};

export default TagPicker;
