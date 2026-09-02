/**
 * The table that maps source lines to rendered positions, for a scroll link.
 *
 * Both halves of a split view answer "which line is at the top?" and "put this
 * line at the top", and the rendered half answers from a table of matching
 * points: every element carrying `data-src-line` says where it starts, and
 * anything between two of them is interpolated. `MarkdownPreview` has always
 * measured that table for a page; the outline preview needs the same one and
 * nothing else, which is why the measuring lives here.
 */

/**
 * Measures every `[data-src-line]` under `root`, inside the scroller `box`.
 *
 * @param {HTMLElement|null} box the scrolling element
 * @param {HTMLElement|null} root the rendered content inside it
 * @param {number} lines how many lines the source has
 * @returns {Array<{line:number, top:number}>} sorted, with both ends of the document present
 */
export function measureAnchors(box, root, lines) {
  if (!root || !box) return [];

  const base = box.getBoundingClientRect().top - box.scrollTop;
  const found = [...root.querySelectorAll('[data-src-line]')]
    .map((element) => ({
      line: Number(element.getAttribute('data-src-line')),
      top: element.getBoundingClientRect().top - base,
    }))
    .filter((anchor) => Number.isFinite(anchor.line))
    .sort((a, b) => a.line - b.line || a.top - b.top);

  // Whatever sits above the first block is padding, not content, so line zero
  // is the top of the scroller rather than the top of that block.
  if (found.length && found[0].line === 0) found[0].top = 0;
  else found.unshift({ line: 0, top: 0 });

  // The closing row is the foot of the rendered body, measured the same way
  // every other row is. Asking to scroll past the end is harmless: the browser
  // clamps it.
  found.push({ line: Math.max(1, lines), top: root.getBoundingClientRect().bottom - base });

  return found;
}

/** Reads one column of the anchor table off the other, interpolating. */
export function between(anchors, value, from, to) {
  if (!anchors.length) return 0;
  if (value <= from(anchors[0])) return to(anchors[0]);
  for (let i = 1; i < anchors.length; i += 1) {
    if (value <= from(anchors[i])) {
      const span = from(anchors[i]) - from(anchors[i - 1]);
      const share = span > 0 ? (value - from(anchors[i - 1])) / span : 0;
      return to(anchors[i - 1]) + share * (to(anchors[i]) - to(anchors[i - 1]));
    }
  }
  return to(anchors[anchors.length - 1]);
}

/** The scroll position a line maps to. */
export const topFor = (anchors, line) => Math.max(0, between(anchors, line, (a) => a.line, (a) => a.top));

/** The fractional line at a scroll position. */
export const lineAt = (anchors, top) => between(anchors, top, (a) => a.top, (a) => a.line);
