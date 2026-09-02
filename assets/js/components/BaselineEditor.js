/**
 * The bottom of the content-details chain, on the Settings screen.
 *
 * Every element and every value a course can decide has an installation-wide
 * default, and until 5.0 those defaults were drawn as a plain list of settings
 * rows - a checkbox called "Learning objectives" with no glyph, a number box
 * called "Minimum length" - that looked nothing like the Details tab the same
 * fourteen elements are switched on a course with. This draws them as that tab
 * draws them: the same rows, the same tiles, the same order, the same words.
 *
 * What is different is the control, and it is different for a reason. Every
 * other level has three answers - on, off, or follow the level above - and
 * this level has nothing above it, so its switch has two. And nothing here
 * writes: the rows edit the Settings screen's draft, and that screen's Save
 * button is what saves them, with everything else on the page, in one request.
 */
import { computed } from 'vue';
import { state, featureByKey, paramByKey } from '@/core/store.js';
import AppIcon from '@/components/AppIcon.js';
import AppSwitch from '@/components/AppSwitch.js';
import { paramIcon, REQUIRES } from '@/components/DetailEditor.js';

const FEATURE_KEY = /^details\.features\.([^.]+)\.default$/;
const PARAM_KEY = /^details\.params\.([^.]+)\.default$/;

export const BaselineEditor = {
  name: 'BaselineEditor',
  components: { AppIcon, AppSwitch },
  props: {
    /** The settings fields of the content group, as the catalogue describes them. */
    fields: { type: Array, required: true },
    /** The Settings screen's draft, key -> value. Edited in place. */
    draft: { type: Object, required: true },
    /** Whether a field holds an unsaved edit. */
    dirty: { type: Function, required: true },
    /** Puts one field back to what the release ships, as an edit. */
    reset: { type: Function, required: true },
    busy: { type: Boolean, default: false },
  },
  setup(props) {
    const byKey = (pattern) => props.fields
      .map((field) => ({ field, match: pattern.exec(field.key) }))
      .filter((entry) => entry.match)
      .map((entry) => ({ field: entry.field, key: entry.match[1] }));

    /* In the catalogue's order, which is the Details tab's order, so the two
       screens list the same things in the same places. */
    const features = computed(() => {
      const order = state.features.map((f) => f.key);
      return byKey(FEATURE_KEY)
        .map((entry) => ({ ...entry, spec: featureByKey.value[entry.key] ?? null }))
        .sort((a, b) => order.indexOf(a.key) - order.indexOf(b.key));
    });
    const params = computed(() => {
      const order = state.params.map((p) => p.key);
      return byKey(PARAM_KEY)
        .map((entry) => ({ ...entry, spec: paramByKey.value[entry.key] ?? null }))
        .sort((a, b) => order.indexOf(a.key) - order.indexOf(b.key));
    });

    const isOn = (field) => props.draft[field.key] === true;
    const setOn = (field, value) => { props.draft[field.key] = value === true; };

    /** Changed from what the release ships, saved or not. */
    const differs = (field) => {
      const value = props.draft[field.key];
      if (field.type === 'bool') return (value === true) !== (field.default === true);
      return String(value ?? '') !== String(field.default ?? '');
    };

    const effectiveOn = (key) => {
      const entry = features.value.find((f) => f.key === key);
      return entry ? isOn(entry.field) : false;
    };
    const inactive = (key) => {
      const feature = REQUIRES[key];
      return feature ? !effectiveOn(feature) : false;
    };

    const shipped = (field) => {
      if (field.type === 'bool') return field.default ? 'on' : 'off';
      if (field.default === '' || field.default === null || field.default === undefined) return 'empty';
      return field.unit ? `${field.default} ${field.unit}` : String(field.default);
    };

    return { features, params, isOn, setOn, differs, inactive, shipped, paramIcon };
  },
  template: `
    <div class="col gap-4">
      <div class="detail-grid">
        <section>
          <div class="row gap-2 mb-2">
            <span class="tile tile--sm tile--accent"><app-icon name="list-check" :size="14"/></span>
            <span class="eyebrow">Elements</span>
            <span class="hint">what every page is made of, unless a profile or a course decides otherwise</span>
          </div>
          <div class="col" style="gap:2px">
            <div v-for="entry in features" :key="entry.field.key"
                 class="detail-row" :class="[isOn(entry.field) ? 'is-on' : 'is-off', { 'is-changed': entry.field.is_overridden, 'is-own': differs(entry.field) }]">
              <span class="detail-row__icon"><app-icon :name="entry.spec ? entry.spec.icon : 'check'" :size="15"/></span>
              <div class="detail-row__text">
                <div class="detail-row__label row wrap gap-2">
                  <span>{{ entry.field.label }}</span>
                  <span v-if="dirty(entry.field)" class="badge badge--warning">unsaved</span>
                  <span v-else-if="entry.field.is_overridden" class="changed-flag">
                    <app-icon name="pencil" :size="10"/> changed
                  </span>
                </div>
                <div class="detail-row__note truncate" :title="entry.spec ? entry.spec.description : entry.field.description">
                  {{ entry.spec ? entry.spec.description : entry.field.description }}
                </div>
              </div>
              <button v-if="differs(entry.field)" class="btn btn--ghost btn--sm btn--icon none"
                      :title="'Back to what the release ships: ' + shipped(entry.field)"
                      :aria-label="entry.field.label + ': back to the shipped default, ' + shipped(entry.field)"
                      :disabled="busy" @click="reset(entry.field)">
                <app-icon name="undo" :size="12"/>
              </button>
              <app-switch :model-value="isOn(entry.field)" :label="entry.field.label" tone="success"
                          :disabled="busy" @update:model-value="setOn(entry.field, $event)"/>
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
            <div v-for="entry in params" :key="entry.field.key" class="param-row param-row--iconed"
                 :class="{ 'is-changed': entry.field.is_overridden, 'is-own': differs(entry.field) }"
                 :style="inactive(entry.key) ? 'opacity:.45' : ''">
              <span class="detail-row__icon"><app-icon :name="paramIcon(entry.key)" :size="15"/></span>
              <div class="detail-row__text">
                <div class="detail-row__label row wrap gap-2">
                  <span>{{ entry.field.label }}</span>
                  <span v-if="dirty(entry.field)" class="badge badge--warning">unsaved</span>
                  <span v-else-if="entry.field.is_overridden" class="changed-flag">
                    <app-icon name="pencil" :size="10"/> changed
                  </span>
                </div>
                <div class="detail-row__note truncate" :title="entry.spec ? entry.spec.description : entry.field.description">
                  {{ entry.spec ? entry.spec.description : entry.field.description }}
                </div>
              </div>
              <div class="param-input">
                <input v-if="entry.field.type === 'int'" v-model.number="draft[entry.field.key]" type="number"
                       :min="entry.field.min" :max="entry.field.max" :disabled="busy"
                       :aria-label="entry.field.label + (entry.field.unit ? ' in ' + entry.field.unit : '')">
                <input v-else v-model="draft[entry.field.key]" type="text" :disabled="busy"
                       :placeholder="entry.field.placeholder || ''" :aria-label="entry.field.label">
                <span v-if="entry.field.unit" class="param-input__unit">{{ entry.field.unit }}</span>
                <button v-if="differs(entry.field)" class="btn btn--ghost btn--sm btn--icon"
                        :title="'Back to what the release ships: ' + shipped(entry.field)"
                        :aria-label="entry.field.label + ': back to the shipped default, ' + shipped(entry.field)"
                        :disabled="busy" @click="reset(entry.field)">
                  <app-icon name="undo" :size="12"/>
                </button>
              </div>
            </div>
          </div>
        </section>
      </div>
    </div>`,
};

export default BaselineEditor;
