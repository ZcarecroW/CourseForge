/**
 * A dialog that really does hold the keyboard.
 *
 * A modal makes a promise: while it is on screen, the page behind it is not
 * available. An overlay and a blur keep that promise for a mouse and for
 * nobody else. Everything below is what it takes to keep it for a keyboard and
 * for a screen reader as well - which matters here because the dialogs this
 * application puts up are the ones that must not be walked past: a delete
 * confirmation, and the password an account owes before it may carry on at all.
 *
 * Three separate things are needed, and each one fails differently on its own.
 *
 * Tab and Shift+Tab cycle inside the panel, so focus cannot walk out of the
 * front of the dialog and into the page behind it, where the next Enter would
 * press a button nobody can see.
 *
 * Everything outside the panel is marked `inert`, so a mouse, a screen reader
 * and any script-driven focus cannot reach it either. This is also what turns
 * aria-modal="true" from a claim into a true statement.
 *
 * Focus moves into the panel when it opens and goes back to whatever opened it
 * when it closes, so the keyboard is never dropped at the top of the document
 * with no way back to where it was.
 *
 * The toast layer is deliberately left alone. It is drawn above the dialog
 * rather than behind it, and these dialogs raise toasts of their own ("The two
 * new passwords do not match"), which an inert live region would never
 * announce. The keyboard still never reaches it, because the cycle is closed.
 *
 * Opening focuses the first control in the body - the field somebody came here
 * to fill in - and not the first control in document order, which is the close
 * button in the title bar. Enter on a dialog that has just opened should do
 * the thing the dialog is for, not cancel it.
 *
 * Escape and a click on the scrim both ask the owner to close, by emitting
 * `close`. Whether that is granted is the owner's decision: the shell refuses
 * while a password is owed, which is how one dialog in this application is
 * made un-dismissable without every other one having to know about it.
 */
import { onMounted, onBeforeUnmount, ref, nextTick } from 'vue';
import AppIcon from '@/components/AppIcon.js';

/* Anything that can hold focus. [tabindex] is in the list for the sake of
   custom controls; the tabindex="-1" ones it also matches are dropped below. */
const FOCUSABLE = [
  'a[href]', 'area[href]', 'button', 'input', 'select', 'textarea', 'summary',
  'audio[controls]', 'video[controls]', 'iframe',
  '[contenteditable]:not([contenteditable="false"])', '[tabindex]',
].join(',');

/** Focusable in practice: enabled, reachable by Tab, and actually drawn. */
const canFocus = (el) =>
  el.tabIndex > -1
  && !el.disabled
  && !el.closest('[inert]')
  && (el.offsetWidth > 0 || el.offsetHeight > 0 || el.getClientRects().length > 0);

const focusableIn = (root) => Array.from(root.querySelectorAll(FOCUSABLE)).filter(canFocus);

/**
 * The dialogs that are open, innermost last.
 *
 * Only the innermost one owns the background. A dialog opened from inside
 * another therefore hands the background back when it closes, and nothing is
 * ever left inert with no dialog on screen to explain why it cannot be used.
 */
const openDialogs = [];

/** True while any dialog is on screen. The shell asks before acting on Escape. */
export const anyDialogOpen = () => openDialogs.length > 0;

/* Drawn above the dialog rather than behind it - see the note at the top. */
const ABOVE_THE_DIALOG = '.toasts';

/**
 * Marks everything outside this dialog inert, walking from the scrim up to the
 * document and taking each level's other children as it goes. Anything already
 * inert is left alone and not recorded, so releasing never switches back on
 * something that was switched off before this dialog opened.
 */
function seal(entry) {
  for (let node = entry.scrim; node && node !== document.body && node.parentElement; node = node.parentElement) {
    for (const sibling of node.parentElement.children) {
      if (sibling === node || sibling.inert || sibling.matches(ABOVE_THE_DIALOG)) continue;
      sibling.inert = true;
      entry.sealed.push(sibling);
    }
  }
}

function release(entry) {
  for (const element of entry.sealed) element.inert = false;
  entry.sealed.length = 0;
}

let sequence = 0;

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
    const scrim = ref(null);
    const panel = ref(null);
    const titleId = `modal-title-${++sequence}`;

    /* Read before anything is focused, because this is the control that opened
       the dialog and the one focus has to be handed back to. */
    const opener = document.activeElement;
    const entry = { scrim: null, sealed: [] };

    /** Body first, then the footer, then the title bar, then the panel itself. */
    function focusFirst() {
      if (!panel.value) return;
      for (const part of ['.modal__body', '.modal__foot', '.modal__head']) {
        const region = panel.value.querySelector(part);
        const target = region && focusableIn(region)[0];
        if (target) {
          target.focus();
          return;
        }
      }
      // A dialog with nothing at all to press - a log being read, say. The
      // panel takes the focus so that Escape and the scroll keys still land.
      panel.value.focus();
    }

    function onKeydown(event) {
      if (event.key === 'Escape') {
        emit('close');
        return;
      }
      if (event.key !== 'Tab' || !panel.value) return;

      const stops = focusableIn(panel.value);
      if (stops.length === 0) {
        event.preventDefault();
        panel.value.focus();
        return;
      }

      const first = stops[0];
      const last = stops[stops.length - 1];
      const active = document.activeElement;

      // Forwards off the last stop, or backwards off the first, wraps round to
      // the other end. Focus that has somehow ended up outside the panel is
      // brought back to the edge it was heading for.
      if (!panel.value.contains(active)) {
        event.preventDefault();
        (event.shiftKey ? last : first).focus();
      } else if (event.shiftKey && active === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first.focus();
      }
    }

    onMounted(async () => {
      entry.scrim = scrim.value;

      // The dialog underneath, if there is one, gives the background up first -
      // otherwise this dialog would be sealed inside its predecessor's.
      const underneath = openDialogs[openDialogs.length - 1];
      if (underneath) release(underneath);
      openDialogs.push(entry);
      seal(entry);

      await nextTick();
      focusFirst();
    });

    onBeforeUnmount(() => {
      const index = openDialogs.indexOf(entry);
      if (index > -1) openDialogs.splice(index, 1);
      release(entry);

      const underneath = openDialogs[openDialogs.length - 1];
      if (underneath) seal(underneath);

      // Only if it is still there and still usable: a dialog that deletes a row
      // has just removed the button that opened it, and the browser's own
      // answer - the top of the document - is the right one then.
      if (opener instanceof HTMLElement && opener.isConnected && canFocus(opener)) opener.focus();
    });

    return { scrim, panel, titleId, onKeydown };
  },
  template: `
    <div class="modal-scrim" ref="scrim" @click.self="$emit('close')" @keydown="onKeydown">
      <div class="modal" :class="{ 'modal--wide': wide }" ref="panel" tabindex="-1"
           role="dialog" aria-modal="true" :aria-labelledby="title ? titleId : null">
        <div v-if="title" class="modal__head">
          <app-icon v-if="icon" :name="icon" :size="18" class="c-accent"/>
          <h3 :id="titleId" class="grow">{{ title }}</h3>
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
