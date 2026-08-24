/**
 * Edits the content details of one level – the course, a chapter or a page.
 *
 * Reads the three views the API computes (own / inherited / effective) and
 * emits partial patches only, so nothing this level has not explicitly decided
 * is ever written. That is what keeps "inherit" genuinely empty in the database
 * instead of a copy of the parent's value.
 */
import { computed, ref } from 'vue';
import { state } from '@/core/store.js';
import AppIcon from './AppIcon.js';
import TriToggle from './TriToggle.js';

export const DetailEditor = {
  name: 'DetailEditor',
  components: { AppIcon, TriToggle },
  props: {
    level: { type: String, required: true },        // course | chapter | page
    details: { type: Object, required: true },
    busy: { type: Boolean, default: false },
    columns: { type: String, default: 'auto' },     // auto | one
  },
  emits: ['change'],
  setup(props, { emit }) {
    const drafts = ref({});                          // param key → in-flight text

    const parentName = computed(() => ({
      course: 'the system defaults',
      chapter: 'the course',
      page: 'the chapter',
    }[props.level] ?? 'the level above'));

    const own = computed(() => props.details?.own ?? { features: {}, params: {} });
    const inherited = computed(() => props.details?.inherited ?? { features: {}, params: {} });
    const effective = computed(() => props.details?.effective ?? { features: {}, params: {} });

    const overrideCount = computed(() =>
      Object.keys(own.value.features ?? {}).length + Object.keys(own.value.params ?? {}).length
    );

    const featureState = (key) => Number(own.value.features?.[key] ?? 0);
    const isInherited = (key) => Boolean(inherited.value.features?.[key]);
    const isEffective = (key) => Boolean(effective.value.features?.[key]);

    const setFeature = (key, value) => emit('change', { features: { [key]: value }, params: {} });

    /** What the box shows: the own value, or nothing plus an inherited placeholder. */
    const paramValue = (key) => {
      if (drafts.value[key] !== undefined) return drafts.value[key];
      const value = own.value.params?.[key];
      return value === undefined || value === null ? '' : String(value);
    };
    const paramPlaceholder = (key) => {
      const value = inherited.value.params?.[key];
      return value === '' || value === undefined || value === null ? '—' : String(value);
    };
    const paramIsOwn = (key) => own.value.params?.[key] !== undefined;

    const onParamInput = (key, event) => { drafts.value = { ...drafts.value, [key]: event.target.value }; };

    const commitParam = (key) => {
      const raw = drafts.value[key];
      if (raw === undefined) return;
      const { [key]: _dropped, ...rest } = drafts.value;
      drafts.value = rest;
      emit('change', { features: {}, params: { [key]: raw.trim() === '' ? null : raw } });
    };

    const clearParam = (key) => {
      const { [key]: _dropped, ...rest } = drafts.value;
      drafts.value = rest;
      emit('change', { features: {}, params: { [key]: null } });
    };

    const resetAll = () => {
      const features = Object.fromEntries(Object.keys(own.value.features ?? {}).map((k) => [k, 0]));
      const params = Object.fromEntries(Object.keys(own.value.params ?? {}).map((k) => [k, null]));
      emit('change', { features, params });
    };

    /** A parameter only matters while its feature is on; dim the rest. */
    const REQUIRES = {
      diagram_max: 'mermaid',
      exercise_count: 'exercises',
      anki_cards: 'anki',
      link_count: 'auto_links',
    };
    const paramInactive = (key) => {
      const feature = REQUIRES[key];
      return feature ? !isEffective(feature) : false;
    };

    return {
      state, parentName, overrideCount,
      featureState, isInherited, isEffective, setFeature,
      paramValue, paramPlaceholder, paramIsOwn, onParamInput, commitParam, clearParam, paramInactive,
      resetAll,
    };
  },
  template: `
    <div class="col gap-4">
      <div class="row between gap-2">
        <div class="row gap-2">
          <span class="eyebrow">Content details</span>
          <span v-if="overrideCount" class="badge badge--accent">{{ overrideCount }} own</span>
          <span v-else class="badge">fully inherited</span>
        </div>
        <button class="btn btn--ghost btn--sm" :disabled="busy || !overrideCount" @click="resetAll"
                :title="'Drop every override on this level and follow ' + parentName + ' again'">
          <app-icon name="inherit" :size="13"/> Reset
        </button>
      </div>

      <p class="hint">
        <strong>↳</strong> follows {{ parentName }},
        <strong>On</strong> and <strong>Off</strong> decide here and pass the decision down.
      </p>

      <div class="grid" :class="columns === 'one' ? '' : 'grid-2'" style="gap:var(--s-6)">
        <section>
          <p class="eyebrow mb-2">Elements</p>
          <div class="col" style="gap:2px">
            <div v-for="feature in state.features" :key="feature.key"
                 class="detail-row" :class="isEffective(feature.key) ? 'is-on' : 'is-off'">
              <span class="detail-row__icon"><app-icon :name="feature.icon" :size="15"/></span>
              <div class="detail-row__text">
                <div class="detail-row__label">{{ feature.label }}</div>
                <div class="detail-row__note truncate" :title="feature.description">{{ feature.description }}</div>
              </div>
              <tri-toggle :model-value="featureState(feature.key)"
                          :inherited="isInherited(feature.key)"
                          :disabled="busy"
                          :inherit-label="level === 'course' ? 'System default' : 'Inherit'"
                          @update:model-value="setFeature(feature.key, $event)"/>
            </div>
          </div>
        </section>

        <section>
          <p class="eyebrow mb-2">Values</p>
          <div class="col" style="gap:2px">
            <div v-for="param in state.params" :key="param.key" class="param-row"
                 :style="paramInactive(param.key) ? 'opacity:.45' : ''">
              <div class="detail-row__text">
                <div class="detail-row__label">
                  {{ param.label }}
                  <span v-if="paramIsOwn(param.key)" class="badge badge--accent" style="margin-left:6px">own</span>
                </div>
                <div class="detail-row__note truncate" :title="param.description">{{ param.description }}</div>
              </div>
              <div class="param-input">
                <input :type="param.type === 'text' ? 'text' : 'number'"
                       :min="param.min" :max="param.max" :step="param.step"
                       :value="paramValue(param.key)"
                       :placeholder="paramPlaceholder(param.key)"
                       :disabled="busy"
                       @input="onParamInput(param.key, $event)"
                       @change="commitParam(param.key)"
                       @keydown.enter="$event.target.blur()"
                       @blur="commitParam(param.key)">
                <span v-if="param.unit" class="param-input__unit">{{ param.unit }}</span>
                <button v-if="paramIsOwn(param.key)" class="btn btn--ghost btn--sm btn--icon"
                        :disabled="busy" title="Inherit this value again"
                        @click="clearParam(param.key)">
                  <app-icon name="x" :size="12"/>
                </button>
              </div>
            </div>
          </div>
        </section>
      </div>
    </div>`,
};

export default DetailEditor;
