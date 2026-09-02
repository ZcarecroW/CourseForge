/**
 * The inherit / on / off switch behind every content detail.
 *
 * The middle state is the important one: "inherit" is not a value, it is the
 * absence of one, and the label shows what the parent currently resolves to so
 * the user can see the consequence without opening the level above.
 *
 * It is a radio group to a screen reader - three options, one chosen - and it
 * is named after the detail it decides, so "Learning objectives, inherit,
 * currently on" is what gets read out rather than three unnamed buttons.
 */
import AppIcon from '@/components/AppIcon.js';

export const TriToggle = {
  name: 'TriToggle',
  components: { AppIcon },
  props: {
    modelValue: { type: Number, default: 0 },   // -1 off | 0 inherit | 1 on
    inherited: { type: Boolean, default: false }, // what "inherit" resolves to
    disabled: { type: Boolean, default: false },
    /** The course has no parent, so its "inherit" means "use the system default". */
    inheritLabel: { type: String, default: 'Inherit' },
    /** What is being decided, for the accessible name of the group. */
    label: { type: String, default: '' },
  },
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    const set = (value) => {
      if (props.disabled) return;
      emit('update:modelValue', props.modelValue === value ? 0 : value);
    };
    return { set };
  },
  template: `
    <div class="tri" role="radiogroup" :aria-label="label || null">
      <button type="button" class="tri__opt" :class="{ 'is-inherit': modelValue === 0 }"
              :disabled="disabled" role="radio" :aria-checked="modelValue === 0"
              :title="inheritLabel + ' - currently ' + (inherited ? 'on' : 'off')"
              :aria-label="inheritLabel + ', currently ' + (inherited ? 'on' : 'off')"
              @click="set(0)"><app-icon name="inherit" :size="11"/>{{ inherited ? 'on' : 'off' }}</button>
      <button type="button" class="tri__opt" :class="{ 'is-on': modelValue === 1 }"
              :disabled="disabled" role="radio" :aria-checked="modelValue === 1"
              title="Force on for this level and everything below it"
              @click="set(1)">On</button>
      <button type="button" class="tri__opt" :class="{ 'is-off': modelValue === -1 }"
              :disabled="disabled" role="radio" :aria-checked="modelValue === -1"
              title="Force off for this level and everything below it"
              @click="set(-1)">Off</button>
    </div>`,
};

export default TriToggle;
