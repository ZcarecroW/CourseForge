/**
 * The inherit / on / off switch behind every content detail.
 *
 * The middle state is the important one: "inherit" is not a value, it is the
 * absence of one, and the label shows what the parent currently resolves to so
 * the user can see the consequence without opening the level above.
 */
export const TriToggle = {
  name: 'TriToggle',
  props: {
    modelValue: { type: Number, default: 0 },   // -1 off | 0 inherit | 1 on
    inherited: { type: Boolean, default: false }, // what "inherit" resolves to
    disabled: { type: Boolean, default: false },
    /** The course has no parent, so its "inherit" means "use the system default". */
    inheritLabel: { type: String, default: 'Inherit' },
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
    <div class="tri" role="radiogroup">
      <button type="button" class="tri__opt" :class="{ 'is-inherit': modelValue === 0 }"
              :disabled="disabled" role="radio" :aria-checked="modelValue === 0"
              :title="inheritLabel + ' — currently ' + (inherited ? 'on' : 'off')"
              @click="set(0)">{{ inherited ? '↳ on' : '↳ off' }}</button>
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
