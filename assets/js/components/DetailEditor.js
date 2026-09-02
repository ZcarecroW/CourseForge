/**
 * Edits the content details of one level - a profile, the course, a chapter or
 * a page.
 *
 * Reads the three views the API computes (own / inherited / effective) and
 * emits partial patches only, so nothing this level has not explicitly decided
 * is ever written. That is what keeps "inherit" genuinely empty in the database
 * instead of a copy of the parent's value.
 *
 * The same rows, the same glyphs and the same three-way switch at every level,
 * so that a person who has learned the Details tab of a course already knows
 * the Content tab of a profile and the Course defaults on the Settings screen.
 * The Settings screen renders one level lower still, where there is nothing to
 * inherit from - see BaselineEditor - and draws the same rows with a plain
 * switch in place of the three-way one.
 */
import { computed, ref } from 'vue';
import { state } from '@/core/store.js';
import AppIcon from '@/components/AppIcon.js';
import TriToggle from '@/components/TriToggle.js';

/**
 * The glyph beside each value. Elements carry their own in the catalogue;
 * values do not, and a value is easier to find in a list of eight by what it
 * is about than by reading eight labels.
 */
export const PARAM_ICONS = {
  min_length: 'text',
  max_length: 'text',
  diagram_max: 'diagram',
  exercise_count: 'dumbbell',
  anki_cards: 'layers',
  link_count: 'link',
  audience: 'users',
  research_max_searches: 'search',
};

export const paramIcon = (key) => PARAM_ICONS[key] ?? 'hash';

/** A parameter only matters while its feature is on; the rest are dimmed. */
export const REQUIRES = {
  diagram_max: 'mermaid',
  exercise_count: 'exercises',
  anki_cards: 'anki',
  link_count: 'auto_links',
  research_max_searches: 'web_research',
};

export const DetailEditor = {
  name: 'DetailEditor',
  components: { AppIcon, TriToggle },
  props: {
    level: { type: String, required: true },        // profile | course | chapter | page
    details: { type: Object, required: true },
    busy: { type: Boolean, default: false },
    columns: { type: String, default: 'auto' },     // auto | one
    /** What "inherit" follows at this level, in words: "the profile Anthropic". */
    parentLabel: { type: String, default: '' },
    /** What the inherit position of the switch is called at this level. */
    inheritLabel: { type: String, default: '' },
  },
  emits: ['change'],
  setup(props, { emit }) {
    const drafts = ref({});                          // param key -> in-flight text

    const parentName = computed(() => props.parentLabel || ({
      profile: 'the installation defaults',
      course: 'the installation defaults',
      chapter: 'the course',
      page: 'the chapter',
    }[props.level] ?? 'the level above'));

    const inheritWord = computed(() => props.inheritLabel || ({
      profile: 'Installation default',
      course: 'Default',
      chapter: 'Inherit',
      page: 'Inherit',
    }[props.level] ?? 'Inherit'));

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
      return value === '' || value === undefined || value === null ? '-' : String(value);
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

    const paramInactive = (key) => {
      const feature = REQUIRES[key];
      return feature ? !isEffective(feature) : false;
    };

    return {
      state, parentName, inheritWord, overrideCount,
      featureState, isInherited, isEffective, setFeature,
      paramValue, paramPlaceholder, paramIsOwn, onParamInput, commitParam, clearParam, paramInactive,
      paramIcon, resetAll,
    };
  },
  template: `
    <div class="col gap-4">
      <div class="row between wrap gap-2">
        <div class="row gap-2">
          <span v-if="overrideCount" class="badge badge--accent">
            <app-icon name="pencil" :size="10"/> {{ overrideCount }} decided here
          </span>
          <span v-else class="badge"><app-icon name="inherit" :size="10"/> follows {{ parentName }}</span>
        </div>
        <button class="btn btn--ghost btn--sm" :disabled="busy || !overrideCount" @click="resetAll"
                :title="'Drop every decision on this level and follow ' + parentName + ' again'">
          <app-icon name="undo" :size="13"/> Reset this level
        </button>
      </div>

      <div class="legend" aria-label="What the three positions of a switch mean">
        <span class="legend__item">
          <span class="legend__swatch legend__swatch--inherit"><app-icon name="inherit" :size="10"/></span>
          follows {{ parentName }}, and shows what that currently is
        </span>
        <span class="legend__item"><span class="legend__swatch legend__swatch--on">On</span> decided here, on</span>
        <span class="legend__item"><span class="legend__swatch legend__swatch--off">Off</span> decided here, off</span>
        <span class="legend__item">A decision here passes down to everything below.</span>
      </div>

      <div class="detail-grid" :class="{ 'detail-grid--one': columns === 'one' }">
        <section>
          <div class="row gap-2 mb-2">
            <span class="tile tile--sm tile--accent"><app-icon name="list-check" :size="14"/></span>
            <span class="eyebrow">Elements</span>
            <span class="hint">what a page is made of</span>
          </div>
          <div class="col" style="gap:2px">
            <div v-for="feature in state.features" :key="feature.key"
                 class="detail-row" :class="[isEffective(feature.key) ? 'is-on' : 'is-off', { 'is-own': featureState(feature.key) !== 0 }]">
              <span class="detail-row__icon"><app-icon :name="feature.icon" :size="15"/></span>
              <div class="detail-row__text">
                <div class="detail-row__label">{{ feature.label }}</div>
                <div class="detail-row__note truncate" :title="feature.description">{{ feature.description }}</div>
              </div>
              <tri-toggle :model-value="featureState(feature.key)"
                          :inherited="isInherited(feature.key)"
                          :disabled="busy"
                          :inherit-label="inheritWord"
                          :label="feature.label"
                          @update:model-value="setFeature(feature.key, $event)"/>
            </div>
          </div>
        </section>

        <section>
          <div class="row gap-2 mb-2">
            <span class="tile tile--sm tile--accent"><app-icon name="sliders" :size="14"/></span>
            <span class="eyebrow">Values</span>
            <span class="hint">how much of each</span>
          </div>
          <div class="col" style="gap:2px">
            <div v-for="param in state.params" :key="param.key" class="param-row param-row--iconed"
                 :class="{ 'is-own': paramIsOwn(param.key) }"
                 :style="paramInactive(param.key) ? 'opacity:.45' : ''">
              <span class="detail-row__icon"><app-icon :name="paramIcon(param.key)" :size="15"/></span>
              <div class="detail-row__text">
                <div class="detail-row__label">
                  {{ param.label }}
                  <span v-if="paramIsOwn(param.key)" class="badge badge--accent" style="margin-left:6px">decided here</span>
                </div>
                <div class="detail-row__note truncate" :title="param.description">{{ param.description }}</div>
              </div>
              <div class="param-input">
                <input :type="param.type === 'text' ? 'text' : 'number'"
                       :min="param.min" :max="param.max" :step="param.step"
                       :value="paramValue(param.key)"
                       :placeholder="paramPlaceholder(param.key)"
                       :disabled="busy"
                       :aria-label="param.label + (param.unit ? ' in ' + param.unit : '')"
                       @input="onParamInput(param.key, $event)"
                       @change="commitParam(param.key)"
                       @keydown.enter="$event.target.blur()"
                       @blur="commitParam(param.key)">
                <span v-if="param.unit" class="param-input__unit">{{ param.unit }}</span>
                <button v-if="paramIsOwn(param.key)" class="btn btn--ghost btn--sm btn--icon"
                        :disabled="busy" title="Follow the level above again"
                        :aria-label="'Stop deciding ' + param.label + ' here'"
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
