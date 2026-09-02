/**
 * A two-state control that shows which state it is in.
 *
 * A checkbox answers "is this ticked"; a switch answers "is this on", which is
 * the question every boolean setting in this application actually asks. It is
 * a button with `role="switch"`, so a screen reader announces it as one and
 * reads the state off `aria-checked` - the same attribute the stylesheet draws
 * from, so what is seen and what is announced cannot disagree.
 *
 * The label is what the switch is a switch for. It is required rather than
 * optional because a switch with no accessible name is announced as "switch,
 * on", which tells nobody anything.
 */
export const AppSwitch = {
  name: 'AppSwitch',
  props: {
    modelValue: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    label: { type: String, required: true },
    /** '' or 'success' - the colour the on state takes. */
    tone: { type: String, default: '' },
  },
  emits: ['update:modelValue'],
  template: `
    <button type="button" class="switch" :class="tone ? 'switch--' + tone : ''"
            role="switch" :aria-checked="modelValue ? 'true' : 'false'" :aria-label="label"
            :disabled="disabled" @click="$emit('update:modelValue', !modelValue)"></button>`,
};

export default AppSwitch;
