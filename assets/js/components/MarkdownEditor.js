/**
 * The Markdown editor: CodeMirror 6, wired up as a plain `v-model` control.
 *
 * A textarea shows a page as one undifferentiated wall of grey. What an author
 * needs instead is what an IDE gives them — headings that read as headings,
 * emphasis that is actually emphasised, fenced code highlighted in its own
 * language, and the two markers CourseForge itself cares about (`(🔗 Title)`
 * cross references and `{{c1::…}}` cloze deletions) visible at a glance.
 *
 * Two conventions keep this file honest:
 *
 * - **No colours here.** Every token maps to a class name and `editor.css`
 *   resolves it against the design tokens, so the editor themes itself exactly
 *   like the rest of the application and dark ⇄ light needs no re-configuration.
 * - **The document is owned by the parent.** CodeMirror is told about outside
 *   changes through `modelValue`, and reports its own through the same event.
 *   `resetKey` — the id of the page on screen — is what separates "the author
 *   typed" from "a different page was opened": the second builds a fresh state,
 *   so undo can never reach back into a page that is no longer open.
 *
 * The editor is imported lazily by ContentTab. It is the largest dependency in
 * the application and nobody signing in should pay for it before they open a
 * course.
 */
import { ref, onMounted, onBeforeUnmount, watch } from 'vue';
import { EditorState, Compartment } from '@codemirror/state';
import {
  EditorView, keymap, lineNumbers, highlightActiveLine, highlightActiveLineGutter,
  drawSelection, dropCursor, rectangularSelection, crosshairCursor, highlightSpecialChars,
  placeholder as cmPlaceholder, ViewPlugin, Decoration, MatchDecorator,
} from '@codemirror/view';
import { history, defaultKeymap, historyKeymap, indentWithTab, redo } from '@codemirror/commands';
import {
  indentOnInput, bracketMatching, syntaxHighlighting, HighlightStyle,
  LanguageSupport, StreamLanguage, ParseContext,
} from '@codemirror/language';
import { search, searchKeymap, highlightSelectionMatches } from '@codemirror/search';
import { closeBrackets, closeBracketsKeymap } from '@codemirror/autocomplete';
import { markdown, markdownLanguage } from '@codemirror/lang-markdown';
import { javascript } from '@codemirror/lang-javascript';
import { html } from '@codemirror/lang-html';
import { css } from '@codemirror/lang-css';
import { parseMixed } from '@lezer/common';
import { tags as t } from '@lezer/highlight';

import { stamped } from '@/core/assets.js';
import { resolvedTheme } from '@/core/theme.js';
import { resolveLanguage, isPlain, editorMode } from '@/core/languages.js';
import { chooseLanguage } from '@/core/detect.js';

/* -- token styling --------------------------------------------------------- */

/**
 * Markdown structure first, then the tags the embedded code languages produce.
 * `class` rather than inline style, so the palette stays in one stylesheet.
 */
const highlightStyle = HighlightStyle.define([
  /* structure */
  { tag: t.heading1, class: 'cm-md-h1' },
  { tag: t.heading2, class: 'cm-md-h2' },
  { tag: t.heading3, class: 'cm-md-h3' },
  { tag: t.heading4, class: 'cm-md-h4' },
  { tag: t.heading5, class: 'cm-md-h5' },
  { tag: t.heading6, class: 'cm-md-h6' },
  { tag: t.heading, class: 'cm-md-heading' },
  { tag: t.processingInstruction, class: 'cm-md-mark' },
  { tag: t.strong, class: 'cm-md-strong' },
  { tag: t.emphasis, class: 'cm-md-em' },
  { tag: t.strikethrough, class: 'cm-md-strike' },
  { tag: t.link, class: 'cm-md-link' },
  { tag: t.url, class: 'cm-md-url' },
  { tag: t.monospace, class: 'cm-md-code' },
  { tag: t.quote, class: 'cm-md-quote' },
  { tag: t.list, class: 'cm-md-list' },
  { tag: t.contentSeparator, class: 'cm-md-rule' },
  { tag: t.labelName, class: 'cm-md-info' },
  { tag: t.escape, class: 'cm-md-escape' },
  { tag: t.character, class: 'cm-md-escape' },

  /* fenced code */
  { tag: [t.keyword, t.controlKeyword, t.moduleKeyword, t.definitionKeyword, t.operatorKeyword], class: 'cm-tk-keyword' },
  { tag: [t.string, t.special(t.string), t.attributeValue], class: 'cm-tk-string' },
  { tag: [t.number, t.bool, t.null, t.atom], class: 'cm-tk-atom' },
  { tag: [t.comment, t.lineComment, t.blockComment, t.docComment], class: 'cm-tk-comment' },
  { tag: [t.typeName, t.className, t.namespace, t.standard(t.tagName)], class: 'cm-tk-type' },
  { tag: [t.function(t.variableName), t.function(t.propertyName)], class: 'cm-tk-function' },
  { tag: [t.definition(t.variableName), t.definition(t.propertyName)], class: 'cm-tk-definition' },
  { tag: [t.variableName, t.propertyName, t.attributeName], class: 'cm-tk-name' },
  { tag: t.tagName, class: 'cm-tk-tag' },
  { tag: t.regexp, class: 'cm-tk-regexp' },
  { tag: [t.meta, t.annotation], class: 'cm-tk-meta' },
  { tag: [t.operator, t.punctuation, t.bracket, t.separator], class: 'cm-tk-punct' },
  { tag: t.invalid, class: 'cm-tk-invalid' },
]);

/* -- the two markers CourseForge writes into a page ------------------------ */

const marker = (regexp, className) => {
  const matcher = new MatchDecorator({ regexp, decoration: Decoration.mark({ class: className }) });
  return ViewPlugin.fromClass(class {
    constructor(view) { this.decorations = matcher.createDeco(view); }

    update(update) { this.decorations = matcher.updateDeco(update, this.decorations); }
  }, { decorations: (plugin) => plugin.decorations });
};

/** `(🔗 Exact Title)` — a cross reference the publisher turns into a real link. */
const crossReferences = marker(/\(\u{1F517}\s*[^()\n]+\)/gu, 'cm-cf-xref');

/** `{{c1::hidden text}}` — an Anki cloze deletion. */
const clozeDeletions = marker(/\{\{c\d+::[^{}\n]*\}\}/g, 'cm-cf-cloze');

/**
 * `{{page_title}}` — a slot the prompt library fills in per request.
 *
 * Only switched on for the prompt screens (`tokens`), because in a page these
 * braces would be a cloze deletion and the two must not be confused. The
 * pattern deliberately will not match `{{c1::…}}`: a cloze has a digit and two
 * colons after the `c`, and a placeholder name never contains a colon.
 */
const placeholderTokens = marker(/\{\{[a-z_][a-z0-9_]*\}\}/g, 'cm-cf-token');

/* -- languages offered inside fenced blocks -------------------------------- */

/**
 * A fenced block is highlighted in its own language, the way an IDE shows an
 * injected fragment. Which language that is comes out of `core/languages.js`,
 * the same table the preview reads, so a fence cannot be understood one way on
 * the left and another on the right.
 *
 * Six languages come from real grammars — HTML, CSS, JavaScript, TypeScript,
 * JSON and PHP — and are listed here because each takes a configured instance
 * rather than a file name. Everything else is one of CodeMirror's stream modes,
 * a few kilobytes apiece, fetched the first time a block in that language is on
 * screen; `core/languages.js` says which file and which export.
 *
 * A language with no mode is not an error: the block stays plain monospace here
 * and is still highlighted in the preview, where Shiki has all two hundred.
 */
// Stamped by hand, like Shiki's grammars: the mode is chosen by what the
// author typed, so the name is not in the import map, and a query string is
// not inherited through new URL(). See core/assets.js.
const modeUrl = (file) => stamped(new URL(`../../vendor/codemirror/modes/${file}.js`, import.meta.url).href);

const ts = () => javascript({ jsx: true, typescript: true });
const phpSupport = async () => (await import('@codemirror/lang-php')).php();

const PACKAGES = {
  javascript: () => javascript({ jsx: true }),
  jsx: () => javascript({ jsx: true }),
  typescript: ts,
  tsx: ts,
  'angular-ts': ts,
  'glimmer-js': () => javascript({ jsx: true }),
  'glimmer-ts': ts,
  'ts-tags': ts,
  json: () => javascript(),
  json5: () => javascript(),
  jsonc: () => javascript(),
  jsonl: () => javascript(),
  hjson: () => javascript(),
  html: () => html(),
  'angular-html': () => html(),
  astro: () => html(),
  erb: () => html(),
  handlebars: () => html(),
  marko: () => html(),
  svelte: () => html(),
  vue: () => html(),
  'vue-vine': () => html(),
  xsl: () => html(),
  css: () => css(),
  postcss: () => css(),
  qss: () => css(),
  blade: phpSupport,
  php: phpSupport,
};

/** id → promise of its `LanguageSupport`, so a language is fetched once. */
const loading = new Map();
/** …and the ones that have arrived, which is what a re-parse actually needs. */
const ready = new Map();

function support(id) {
  if (loading.has(id)) return loading.get(id);

  const build = Object.hasOwn(PACKAGES, id) ? PACKAGES[id] : null;
  const mode = build ? null : editorMode(id);
  if (!build && !mode) return null;

  const pending = (async () => {
    if (build) return build();
    const module = await import(modeUrl(mode.file));
    return new LanguageSupport(StreamLanguage.define(module[mode.export]));
  })()
    .then((loaded) => { ready.set(id, loaded); return loaded; })
    .catch((error) => {
      // Forgotten so the next fence in this language asks again: a fetch that
      // failed once may well succeed next time, and a null memoised here kept
      // the language plain for the rest of the session.
      loading.delete(id);
      console.warn(`[CourseForge] no editor mode for "${id}":`, error);
      return null;
    });

  loading.set(id, pending);
  return pending;
}

/**
 * The parser for a language, once. A language that has not arrived yet gets a
 * parser that consumes the range without looking at it and asks to be run
 * again when the import lands — which is only correct as long as the second
 * run finds the real one, so the resolved support is what is consulted first.
 */
function parserFor(id) {
  if (!id) return null;
  const arrived = ready.get(id);
  if (arrived) return arrived.language.parser;
  const pending = support(id);
  return pending ? ParseContext.getSkippingParser(pending) : null;
}

/**
 * Highlighting inside fenced blocks, replacing what `codeLanguages` would do.
 *
 * `lang-markdown` will resolve a language from a fence's info string, and only
 * from that: its hook is handed the word after the backticks and nothing else.
 * That is enough for ```python and no use at all for the two cases this is
 * here for — a fence that says nothing, and a fence that says the wrong thing.
 * Wrapping the parse directly gives the block's text as well, which is what
 * `core/detect.js` needs, and the editor then colours exactly what the preview
 * colours.
 *
 * A language that has not been fetched yet parses as nothing and is retried
 * when it lands, which is what `getSkippingParser` is for.
 */
function languageOf(code, info) {
  return chooseLanguage(code, resolveLanguage(info), { plain: isPlain(info) }).id;
}

const fencedCode = {
  wrap: parseMixed((node, input) => {
    const fenced = node.type.name === 'FencedCode';
    if (!fenced && node.type.name !== 'CodeBlock') return null;

    const infoNode = fenced ? node.node.getChild('CodeInfo') : null;
    const textNode = node.node.getChild('CodeText');
    if (!textNode) return null;

    const parser = parserFor(languageOf(
      input.read(textNode.from, textNode.to),
      infoNode ? input.read(infoNode.from, infoNode.to) : '',
    ));
    if (!parser) return null;

    return {
      parser,
      overlay: (child) => child.type.name === 'CodeText',
      bracketed: fenced,
    };
  }),
};

/* -- the component --------------------------------------------------------- */

export const MarkdownEditor = {
  name: 'MarkdownEditor',
  props: {
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    /** Changes when a different document is opened; same value means "same page". */
    resetKey: { type: [String, Number], default: 0 },

    // What follows is what makes this the editor for a prompt and an outline as
    // well as for a page. The defaults are the page, so nothing that already
    // uses this component has to say anything.

    /** Line numbers and the active-line gutter. Off for a short template. */
    gutter: { type: Boolean, default: true },
    /** The cross-reference and cloze markers, which only mean anything in a page. */
    markers: { type: Boolean, default: true },
    /** `{{placeholder}}` highlighting, which only means anything in a prompt. */
    tokens: { type: Boolean, default: false },
    /** What a screen reader calls this box. */
    label: { type: String, default: 'Page content, Markdown' },
    /**
     * Typing switched off, for as long as the parent owns the document.
     *
     * The screens that save a whole box at once used to be textareas, and a
     * textarea has `disabled` - which is what kept a keystroke landing during
     * the round trip from being overwritten by the answer to it. There is no
     * such attribute here, so it is a prop.
     *
     * It takes BOTH facets, and one of them alone is not a lock.
     * `EditorView.editable` only sets `contenteditable` on the content element,
     * which stops what the browser types into it - and nothing else. Every
     * structural command in the keymaps below runs off `keydown` and calls
     * dispatch() itself, so Backspace, Enter, Tab and undo all still edited a
     * document that was supposed to be held still. `EditorState.readOnly` is
     * what those commands consult. Neither of them blocks a dispatch from
     * outside, which is the point: the parent still has to be able to put the
     * saved document into the view when the answer arrives.
     */
    readonly: { type: Boolean, default: false },
  },
  emits: ['update:modelValue', 'scroll'],
  setup(props, { emit, expose }) {
    const host = ref(null);
    const darkness = new Compartment();
    const editing = new Compartment();

    /** Both halves of "nobody may change this", which is one facet short of a lock. */
    const lock = (locked) => [EditorView.editable.of(!locked), EditorState.readOnly.of(locked)];

    let view = null;
    let echoing = false;          // guards the round trip back from the parent

    const extensions = () => [
      ...(props.gutter ? [lineNumbers(), highlightActiveLineGutter()] : []),
      highlightActiveLine(),
      highlightSpecialChars(),
      history(),
      drawSelection(),
      dropCursor(),
      rectangularSelection(),
      crosshairCursor(),
      indentOnInput(),
      bracketMatching(),
      closeBrackets(),
      search({ top: true }),
      highlightSelectionMatches(),
      EditorState.allowMultipleSelections.of(true),
      EditorState.tabSize.of(2),
      EditorView.lineWrapping,
      EditorView.contentAttributes.of({ spellcheck: 'false', 'aria-label': props.label }),
      editing.of(lock(props.readonly)),
      // The line the cursor is on is highlighted, and while text is selected
      // that line is part of the selection — so the highlight would sit on top
      // of exactly the end an author is watching. This is what lets the
      // stylesheet stand it down for as long as anything is selected.
      EditorView.contentAttributes.compute(['selection'], (state) => (
        state.selection.ranges.some((range) => !range.empty) ? { class: 'cm-has-selection' } : {}
      )),
      markdown({ base: markdownLanguage, extensions: [fencedCode], addKeymap: true }),
      syntaxHighlighting(highlightStyle),
      ...(props.markers ? [crossReferences, clozeDeletions] : []),
      ...(props.tokens ? [placeholderTokens] : []),
      cmPlaceholder(props.placeholder),
      darkness.of(EditorView.darkTheme.of(resolvedTheme.value === 'dark')),
      // CodeMirror's own history keymap gives redo Ctrl+Shift+Z on macOS and on
      // Linux, and on Windows nothing but Ctrl+Y - which leaves the shortcut
      // every other editor on the machine has doing nothing at all. Bound here
      // for all three, next to the keymap it completes.
      keymap.of([
        ...closeBracketsKeymap, ...defaultKeymap, ...searchKeymap, ...historyKeymap,
        { key: 'Mod-Shift-z', run: redo, preventDefault: true },
        indentWithTab,
      ]),
      EditorView.updateListener.of((update) => {
        if (!update.docChanged || echoing) return;
        emit('update:modelValue', update.state.doc.toString());
      }),
    ];

    const stateFor = (doc) => EditorState.create({ doc, extensions: extensions() });

    /* -- geometry, for the scroll link ----------------------------------- */

    /** Height inside the document that currently sits at the top of the viewport. */
    const viewportTop = () => view.scrollDOM.getBoundingClientRect().top - view.documentTop;

    /**
     * A line's extent, with the padding above the first one counted as part of
     * it. Heights are measured from below that padding, so without this the two
     * functions below would not be inverses at the top of the document: line
     * zero would mean sixteen pixels down rather than the top, and the preview
     * — where it does mean the top — would sit slightly out of step.
     */
    const spanOf = (block) => {
      const pad = block.from === 0 ? view.documentPadding.top : 0;
      return { top: block.top - pad, height: block.height + pad };
    };

    /**
     * Where the reader is, as a fractional line number: line 12 half scrolled
     * past is `12.5`. Lines rather than a scroll percentage, because that is
     * what the preview can be matched against.
     */
    const topLine = () => {
      if (!view) return 0;
      const height = viewportTop();
      const at = view.lineBlockAtHeight(Math.max(0, height));
      const block = spanOf(at);
      const line = view.state.doc.lineAt(at.from).number - 1;
      const within = block.height > 0 ? (height - block.top) / block.height : 0;
      return line + Math.min(Math.max(within, 0), 1);
    };

    const scrollToLine = (target) => {
      if (!view) return;
      const count = view.state.doc.lines;
      const number = Math.min(Math.max(Math.floor(target) + 1, 1), count);
      const block = spanOf(view.lineBlockAt(view.state.doc.line(number).from));
      const within = Math.min(Math.max(target - Math.floor(target), 0), 1);
      view.scrollDOM.scrollTop += block.top + within * block.height - viewportTop();
    };

    /* -- lifecycle -------------------------------------------------------- */

    const onScroll = () => emit('scroll');

    onMounted(() => {
      view = new EditorView({ state: stateFor(props.modelValue), parent: host.value });
      view.scrollDOM.addEventListener('scroll', onScroll, { passive: true });
    });

    onBeforeUnmount(() => {
      view?.scrollDOM.removeEventListener('scroll', onScroll);
      view?.destroy();
      view = null;
    });

    // One watcher for both, so it never matters which of the two the parent
    // happens to update first when a page is opened.
    watch(() => [props.resetKey, props.modelValue], ([key, value], [previousKey]) => {
      if (!view) return;
      if (key !== previousKey) {
        view.setState(stateFor(value ?? ''));
        view.scrollDOM.scrollTop = 0;
        return;
      }
      const current = view.state.doc.toString();
      if (current === value) return;
      echoing = true;
      view.dispatch({ changes: { from: 0, to: current.length, insert: value ?? '' } });
      echoing = false;
    });

    watch(resolvedTheme, (theme) => {
      view?.dispatch({ effects: darkness.reconfigure(EditorView.darkTheme.of(theme === 'dark')) });
    });

    watch(() => props.readonly, (locked) => {
      view?.dispatch({ effects: editing.reconfigure(lock(locked)) });
    });

    /**
     * Drops text in at the caret, replacing the selection.
     *
     * The prompt screens used to do this by hand against a textarea's
     * selectionStart/selectionEnd; CodeMirror owns its own selection, so it has
     * to be asked. The caret lands after what was inserted, which is where
     * somebody who has just clicked a placeholder chip wants to carry on.
     */
    const insertAtCursor = (text) => {
      if (!view) return;
      const { from, to } = view.state.selection.main;
      view.dispatch({
        changes: { from, to, insert: text },
        selection: { anchor: from + text.length },
      });
      view.focus();
    };

    expose({ topLine, scrollToLine, insertAtCursor, focus: () => view?.focus() });

    return { host };
  },
  template: '<div class="cf-editor" ref="host"></div>',
};

export default MarkdownEditor;
