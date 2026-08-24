/**
 * How code blocks are shown in the preview: wrapped or scrolling, numbered or
 * not. Two preferences, and both are the reader's rather than the page's — a
 * block does not know whether the person reading it wants line numbers, so the
 * choice is made once, applies to every block, and is remembered.
 *
 * Both default to on. Wrapping, because the preview lives in half a split view
 * where a sideways scrollbar hides the end of every long line; numbers, because
 * the whole point of a code listing in a course is being able to say "line 12".
 *
 * The controls sit in the header of each block, which is the only place they
 * can be found without a settings screen. They are stored the way the scroll
 * link is (`core/scrollsync.js`): a key that is absent or anything other than
 * `off` means on, so a first-time reader gets the default without a write.
 */
import { ref } from 'vue';

const WRAP_KEY = 'cf.codeWrap';
const NUMBERS_KEY = 'cf.codeNumbers';

const stored = (key) => localStorage.getItem(key) !== 'off';

export const codeWrap = ref(stored(WRAP_KEY));
export const codeNumbers = ref(stored(NUMBERS_KEY));

const write = (key, value) => localStorage.setItem(key, value ? 'on' : 'off');

export function toggleCodeWrap() {
  codeWrap.value = !codeWrap.value;
  write(WRAP_KEY, codeWrap.value);
}

export function toggleCodeNumbers() {
  codeNumbers.value = !codeNumbers.value;
  write(NUMBERS_KEY, codeNumbers.value);
}

/**
 * Puts a block's source on the clipboard.
 *
 * `navigator.clipboard` is only defined in a secure context, and CourseForge is
 * self-hosted — plenty of installations are reached over plain HTTP on a local
 * network, where it simply is not there. The old `execCommand` route still
 * works everywhere and is what stands behind it.
 */
export async function copyText(text) {
  if (navigator.clipboard?.writeText) {
    try {
      await navigator.clipboard.writeText(text);
      return true;
    } catch {
      /* denied or unavailable — fall through to the other way */
    }
  }

  const area = document.createElement('textarea');
  area.value = text;
  area.setAttribute('readonly', '');
  // Off screen rather than hidden: a `display: none` textarea cannot be selected.
  area.style.cssText = 'position:fixed;top:0;left:-9999px;opacity:0';
  document.body.append(area);
  area.select();
  let copied = false;
  try {
    copied = document.execCommand('copy');
  } catch {
    copied = false;
  }
  area.remove();
  return copied;
}
