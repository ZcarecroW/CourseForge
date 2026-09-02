/**
 * The course outline, read the way the server reads it.
 *
 * `Structure::parse()` in src/Domain/Structure.php is the parser that decides
 * what an outline means when it is applied. This is the same grammar in the
 * browser, so the Structure tab can show what Apply would do while the outline
 * is still being typed: which chapters and pages it keeps, which it adds, and
 * which written pages it deletes. It must never disagree with the server, so
 * tools/outline-test.mjs runs it over the outlines in tools/outline-fixtures.json,
 * whose expected answers the PHP parser wrote - and tests/outline-fixtures.test.php
 * fails the moment the PHP parser stops agreeing with that file, which is the
 * signal to regenerate it and re-run the Node side.
 *
 * What this one carries that the server has no use for: the line every
 * chapter and page came from, which is what lets the preview scroll with the
 * editor.
 */

/** Longer than this and a list item is not a title. */
const TITLE_MAX_CHARS = 200;
/** A sentence this long that also ends like a sentence is not a title. */
const TITLE_MAX_WORDS = 10;

const CJK = '\\p{Script=Han}\\p{Script=Hiragana}\\p{Script=Katakana}';
const WORD = new RegExp(`[${CJK}]|(?:(?![${CJK}])[\\p{L}\\p{N}])(?:(?![${CJK}])[\\p{L}\\p{N}'’-])*`, 'gu');

/** Word count that behaves on non-ASCII text - the same rule as Text::words(). */
export const words = (text) => (String(text ?? '').match(WORD) ?? []).length;

/** Text::tidy(): every run of whitespace is one space, and the ends are trimmed. */
const tidy = (text) => String(text ?? '').replace(/\s+/gu, ' ').trim();

/** Union of two name lists, first spelling wins, comparison is case-insensitive. */
export function mergeUnique(a, b) {
  const seen = new Map();
  for (const name of [...a, ...b]) {
    const key = String(name).toLowerCase();
    if (!seen.has(key)) seen.set(key, String(name));
  }
  return [...seen.values()];
}

/** Splits "Tag1, Tag2; Tag3" into a clean, case-insensitively unique list. */
export function splitList(list) {
  let out = [];
  for (const part of String(list ?? '').split(/[,;\n]+/u)) {
    let name = tidy(part);
    name = name.replace(/^[ \t"'`*_#[\]()]+/u, '').replace(/[ \t"'`*_#[\]()]+$/u, '');
    if (name === '') continue;
    out = mergeUnique(out, [name]);
  }
  return out;
}

/** A title in the only shape the outline format can carry unchanged. */
function clean(text) {
  let out = String(text).trim();
  out = out.replace(/^\*\*(.*)\*\*$/u, '$1');
  out = out.replace(/^__(.*)__$/u, '$1');
  out = out.replace(/^#+\s*/u, '');
  // The other half of the escaping toMarkdown() does. After the heading strip
  // so that "\## Something" loses the backslash and keeps its hashes.
  out = out.replace(/^\\(?=(?:\d+[.)]|[-*+]|#+)\s)/u, '');
  return out.trim();
}

/** [text without the marker, tag names] */
function extractTags(text) {
  const match = /\{\{([^{}]*)\}\}\s*$/u.exec(text);
  if (!match) return [text, []];
  const tags = splitList(match[1]);
  const at = text.lastIndexOf(match[0]);
  const rest = at >= 0 ? text.slice(0, at) : text;
  return [rest.trim().replace(/[ \-–—:]+$/u, '').trim(), tags];
}

/** Whether a list item's text is a paragraph rather than a title. */
function isProse(text) {
  if (text === '') return false;
  if ([...text].length > TITLE_MAX_CHARS) return true;
  return /[.!?][)"'”’]?$/u.test(text) && words(text) > TITLE_MAX_WORDS;
}

/** Adds one prose line to a description, keeping paragraphs apart. */
const join = (carried, text, brk) => (carried === '' ? text : `${carried}${brk ? '\n\n' : ' '}${text}`);

function attachTags(state, names) {
  if (!names.length) return;
  const { chapter, target } = state;
  if (target === 'page' && chapter && chapter.pages.length) {
    const last = chapter.pages[chapter.pages.length - 1];
    last.tags = mergeUnique(last.tags, names);
    return;
  }
  if (target === 'chapter' && chapter) {
    chapter.tags = mergeUnique(chapter.tags, names);
    return;
  }
  state.bookTags = mergeUnique(state.bookTags, names);
}

/**
 * @param {string} markdown
 * @returns {{
 *   title: string, titleLine: number|null, description: string, descriptionLine: number|null, tags: string[],
 *   chapters: Array<{ title: string, description: string, tags: string[], line: number,
 *     pages: Array<{ title: string, tags: string[], line: number }> }>
 * }}
 */
export function parseOutline(markdown) {
  let text = String(markdown ?? '').replace(/\r\n?/g, '\n').replace(/\t/g, '   ');

  // A model that wraps its whole answer in a fence must not break parsing.
  // The server strips the fence; here the lines inside it keep their numbers.
  let offset = 0;
  const fenced = /^\s*```[a-zA-Z]*\s*\n([\s\S]*)\n```\s*$/du.exec(text);
  if (fenced) {
    offset = (text.slice(0, fenced.indices[1][0]).match(/\n/g) ?? []).length;
    text = fenced[1];
  }

  const state = { bookTags: [], chapter: null, target: 'book' };
  const chapters = [];
  let title = '';
  let titleLine = null;
  let description = '';
  let descriptionLine = null;
  let seenChapter = false;
  let bulletChapters = false;
  let blank = false;

  text.split('\n').forEach((line, index) => {
    const number = index + offset;
    const raw = line.replace(/\s+$/u, '');
    const trimmed = raw.trim();
    if (trimmed === '') {
      blank = true;
      return;
    }
    const brk = blank;
    blank = false;

    // A tag marker on its own line belongs to the entity written above it.
    const marker = /^(?:[-*+]\s+|\d+[.)]\s+)?\{\{([^{}]*)\}\}$/u.exec(trimmed);
    if (marker) {
      attachTags(state, splitList(marker[1]));
      return;
    }

    // Book title.
    const heading = title === '' ? /^#\s+(.*)$/.exec(trimmed) : null;
    if (heading) {
      const [text2, tags] = extractTags(clean(heading[1]));
      title = text2;
      titleLine = number;
      state.bookTags = mergeUnique(state.bookTags, tags);
      state.target = 'book';
      return;
    }

    const indent = raw.length - raw.replace(/^ +/, '').length;
    const orderedMatch = /^(\d+)[.)]\s+(.*)$/u.exec(trimmed);
    let ordered = orderedMatch !== null;
    const bulletMatch = ordered ? null : /^[-*+]\s+(.*)$/u.exec(trimmed);
    let bullet = bulletMatch !== null;
    const [itemText, itemTags] = extractTags(clean(ordered ? orderedMatch[2] : (bullet ? bulletMatch[1] : '')));

    // A marker in front of a paragraph is not a title.
    if ((ordered || bullet) && isProse(itemText)) {
      ordered = false;
      bullet = false;
    }

    // Chapter: an ordered item at indent 0 - or a top-level bullet when the
    // model refused to number chapters at all.
    if (indent <= 1 && (ordered || (bullet && (bulletChapters || !seenChapter)))) {
      bulletChapters = bulletChapters || bullet;
      if (itemText === '') {
        state.target = 'chapter';
        return;
      }
      if (state.chapter !== null) chapters.push(state.chapter);
      state.chapter = { title: itemText, description: '', tags: itemTags, pages: [], line: number };
      seenChapter = true;
      state.target = 'chapter';
      return;
    }

    // Page: any list item nested below a chapter.
    if ((ordered || bullet) && indent >= 2) {
      if (state.chapter === null) state.chapter = { title: 'Chapter 1', description: '', tags: [], pages: [], line: number };
      seenChapter = true;
      if (itemText !== '') {
        state.chapter.pages.push({ title: itemText, tags: itemTags, line: number });
        state.target = 'page';
      }
      return;
    }

    // Plain prose: the book description before the first chapter, the
    // chapter description afterwards.
    const [prose, proseTags] = extractTags(clean(trimmed));
    if (proseTags.length) attachTags(state, proseTags);
    if (prose === '') return;
    if (!seenChapter) {
      if (description === '') descriptionLine = number;
      description = join(description, prose, brk);
    } else if (state.chapter !== null) {
      state.chapter.description = join(state.chapter.description, prose, brk);
      state.target = 'chapter';
    }
  });

  if (state.chapter !== null) chapters.push(state.chapter);

  return {
    title,
    titleLine,
    description: description.trim(),
    descriptionLine,
    tags: state.bookTags,
    chapters: chapters.filter((chapter) => chapter.title !== ''),
  };
}

/**
 * What applying the outline would do to the course, worked out the way the
 * server works it out: chapters matched by lower-cased title, first come
 * first served; a page kept in the chapter it is in, then whatever is left
 * matched by title wherever it is - which is how a renamed chapter keeps the
 * pages that came with it.
 *
 * @param {ReturnType<typeof parseOutline>} parsed
 * @param {{ chapters: Array<{ id:number, title:string, pages: Array<{ id:number, title:string, chapter_id:number, has_content:boolean }> }> }} project
 */
export function diffOutline(parsed, project) {
  const lower = (text) => String(text ?? '').toLowerCase();

  const chapterQueue = new Map();
  for (const row of project?.chapters ?? []) {
    const key = lower(row.title);
    if (!chapterQueue.has(key)) chapterQueue.set(key, []);
    chapterQueue.get(key).push(row);
  }
  const pageQueue = new Map();
  for (const chapter of project?.chapters ?? []) {
    for (const row of chapter.pages ?? []) {
      const key = lower(row.title);
      if (!pageQueue.has(key)) pageQueue.set(key, []);
      pageQueue.get(key).push({ ...row, chapter_id: row.chapter_id ?? chapter.id });
    }
  }

  const chapters = parsed.chapters.map((chapter) => {
    const queue = chapterQueue.get(lower(chapter.title)) ?? [];
    const row = queue.length ? queue.shift() : null;
    return { ...chapter, row, status: row ? 'kept' : 'new', pages: chapter.pages.map((page) => ({ ...page, row: null, status: 'new' })) };
  });

  // Pass one: a page that is still in the chapter it was in keeps its row.
  for (const chapter of chapters) {
    const chapterId = chapter.row ? chapter.row.id : 0;
    for (const page of chapter.pages) {
      const queue = pageQueue.get(lower(page.title)) ?? [];
      const at = queue.findIndex((row) => row.chapter_id === chapterId);
      if (at >= 0) {
        page.row = queue.splice(at, 1)[0];
        page.status = 'kept';
      }
    }
  }
  // Pass two: what is left goes to the entries that found nothing.
  for (const chapter of chapters) {
    for (const page of chapter.pages) {
      const queue = pageQueue.get(lower(page.title)) ?? [];
      if (page.row === null && queue.length) {
        page.row = queue.shift();
        page.status = 'moved';
      }
    }
  }

  const leftover = (queues) => [...queues.values()].flat();
  const removedPages = leftover(pageQueue);
  const removedChapters = leftover(chapterQueue);

  return {
    chapters,
    removedChapters,
    removedPages,
    atRisk: removedPages.filter((row) => row.has_content),
    counts: {
      chapters: chapters.length,
      pages: chapters.reduce((n, chapter) => n + chapter.pages.length, 0),
      newPages: chapters.reduce((n, chapter) => n + chapter.pages.filter((page) => page.status === 'new').length, 0),
      movedPages: chapters.reduce((n, chapter) => n + chapter.pages.filter((page) => page.status === 'moved').length, 0),
      newChapters: chapters.filter((chapter) => chapter.status === 'new').length,
    },
  };
}
