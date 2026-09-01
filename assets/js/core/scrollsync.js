/**
 * Keeping the two halves of the split view on the same passage.
 *
 * Matching scroll percentages is the obvious approach and the wrong one: a page
 * with one long code block or a tall diagram renders to a completely different
 * height than its source, so the two halves drift apart exactly where an author
 * is most likely to be looking. This works on positions instead. Every
 * top-level block carries the line it started on (`data-src-line`, put there by
 * `core/markdown.js`), which gives a table of matching points; anything between
 * two of them is interpolated. Both sides can answer "which line is at the
 * top?" and "put this line at the top", and that is the whole protocol.
 *
 * The other half of the problem is the feedback loop — scrolling one pane
 * scrolls the other, whose scroll event would scroll the first one back. There
 * is no timer and no lock here: exactly one pane is the driver at any moment,
 * chosen by what the reader last touched, and only the driver's scroll events
 * are acted on. A programmatic scroll therefore cannot answer back.
 */
import { ref } from 'vue';

const STORAGE_KEY = 'cf.syncScroll';

/**
 * Linked or not, for the whole application rather than per split view.
 *
 * It is a preference about how a split behaves and not about which document is
 * open, so the Content tab and the Details tab share it - and they have to
 * share the ref rather than only the stored value, because keep-alive means
 * both are mounted at once: a per-instance ref would have left the other tab's
 * button showing the opposite of what it does until something remounted it.
 */
const enabled = ref(localStorage.getItem(STORAGE_KEY) !== 'off');

export function useScrollSync(editor, preview) {
  let driver = 'editor';
  let queued = false;

  /** Scroll handlers fire far faster than the screen refreshes. */
  const onFrame = (action) => {
    if (queued) return;
    queued = true;
    requestAnimationFrame(() => { queued = false; action(); });
  };

  /**
   * Whichever half the reader last acted on leads. Pointing, wheeling or
   * focusing claims it, and so does a keystroke: the caret can be in the editor
   * while the pointer is over the preview, and typing has to win that.
   */
  const claim = (side) => { driver = side; };

  // Both halves are `v-if`ed on the view mode, so either ref can be null. The
  // half that is missing is the one being followed as often as the one doing
  // the following, and "there is no position to read" must not be mistaken for
  // "the position is the top" — that would throw the surviving half to line 0
  // every time the other one is unmounted or the pane is resized.
  //
  // Present is not the same as ready, which is the third state and the one that
  // used to throw. The editor arrives through defineAsyncComponent, so until
  // CodeMirror has been fetched the ref holds the loading stand-in — an
  // ordinary component that answers to neither method. Asking whether the pair
  // of methods is there covers both that window and a null ref, so a scroll
  // during the fetch is simply a scroll that nothing follows.
  const ready = (half) => typeof half.value?.topLine === 'function'
    && typeof half.value?.scrollToLine === 'function';

  const toEditor = () => { if (ready(preview) && ready(editor)) editor.value.scrollToLine(preview.value.topLine()); };
  const toPreview = () => { if (ready(editor) && ready(preview)) preview.value.scrollToLine(editor.value.topLine()); };

  const fromEditor = () => {
    if (enabled.value && driver === 'editor') onFrame(toPreview);
  };

  const fromPreview = () => {
    if (enabled.value && driver === 'preview') onFrame(toEditor);
  };

  /** Re-align after something changed the geometry rather than the position. */
  const realign = () => {
    if (!enabled.value) return;
    onFrame(driver === 'preview' ? toEditor : toPreview);
  };

  const toggle = () => {
    enabled.value = !enabled.value;
    localStorage.setItem(STORAGE_KEY, enabled.value ? 'on' : 'off');
    if (enabled.value) realign();
  };

  return { enabled, toggle, claim, fromEditor, fromPreview, realign };
}
