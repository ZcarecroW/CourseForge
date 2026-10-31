# Vendored libraries

Every third-party dependency is committed here as a version-pinned ES module and
reached through the import map in `index.html`. There is no package manager and
no build step: application code writes `import { ref } from 'vue'` exactly as it
would in a bundled project, and the browser resolves it to the file below.

Only two things are required when updating one of these:

1. replace the file with the same build from the same source, and
2. bump the version in the table so the next reader knows what is running.

## The application shell

| Specifier   | File                        | Version | Licence      | Source                                                    |
|-------------|-----------------------------|---------|--------------|-----------------------------------------------------------|
| `vue`       | `vue.esm-browser.prod.js`   | 3.5.44  | MIT          | https://unpkg.com/vue@3.5.44/dist/vue.esm-browser.prod.js |
| `marked`    | `marked.esm.js`             | 18.1.2  | MIT          | https://unpkg.com/marked@18.1.2/lib/marked.esm.js         |
| `dompurify` | `purify.es.mjs`             | 3.4.17  | MPL-2.0/Apache-2.0 | https://unpkg.com/dompurify@3.4.17/dist/purify.es.mjs |
| `fuse`      | `fuse.mjs`                  | 7.5.0   | Apache-2.0   | https://unpkg.com/fuse.js@7.5.0/dist/fuse.mjs             |

## The editor — CodeMirror 6

Twenty-four files in `codemirror/`, all of them the package's own published
`dist/index.js`, renamed after the specifier they answer to. Nothing is
transformed: each file still imports its dependencies by bare specifier, which
is exactly what an import map is for.

| Specifier                     | File                    | Version |
|-------------------------------|-------------------------|---------|
| `@codemirror/state`           | `state.js`              | 6.7.6   |
| `@codemirror/view`            | `view.js`               | 6.43.15 |
| `@codemirror/language`        | `language.js`           | 6.13.1  |
| `@codemirror/streamparser`    | `streamparser.js`       | 6.0.0   |
| `@codemirror/commands`        | `commands.js`           | 6.11.1  |
| `@codemirror/search`          | `search.js`             | 6.7.2   |
| `@codemirror/autocomplete`    | `autocomplete.js`       | 6.20.4  |
| `@codemirror/lang-markdown`   | `lang-markdown.js`      | 6.5.2   |
| `@codemirror/lang-html`       | `lang-html.js`          | 6.4.12  |
| `@codemirror/lang-css`        | `lang-css.js`           | 6.3.1   |
| `@codemirror/lang-javascript` | `lang-javascript.js`    | 6.2.5   |
| `@codemirror/lang-php`        | `lang-php.js`           | 6.0.2   |
| `@lezer/common`               | `lezer-common.js`       | 1.5.5   |
| `@lezer/highlight`            | `lezer-highlight.js`    | 1.2.5   |
| `@lezer/lr`                   | `lezer-lr.js`           | 1.4.12  |
| `@lezer/markdown`             | `lezer-markdown.js`     | 1.8.1   |
| `@lezer/html`                 | `lezer-html.js`         | 1.3.13  |
| `@lezer/css`                  | `lezer-css.js`          | 1.3.9   |
| `@lezer/javascript`           | `lezer-javascript.js`   | 1.5.6   |
| `@lezer/php`                  | `lezer-php.js`          | 1.0.6   |
| `@marijn/find-cluster-break`  | `find-cluster-break.js` | 1.0.4   |
| `style-mod`                   | `style-mod.js`          | 4.1.4   |
| `w3c-keyname`                 | `w3c-keyname.js`        | 2.2.8   |
| `crelt`                       | `crelt.js`              | 1.0.7   |

All MIT. Source URL for each: `https://unpkg.com/<specifier>@<version>/dist/index.js`
— except `style-mod` (`src/style-mod.js`), `@marijn/find-cluster-break`
(`src/index.js`), `w3c-keyname` and `crelt` (`index.js`).

`@codemirror/streamparser` arrived with `@codemirror/language` 6.13, which moved
`StringStream` out into it and imports it by specifier. It has no dependencies
of its own. Updating `language.js` without it, or without its line in the import
map, leaves the editor blank with a module-resolution error - which is the
check worth repeating on the next update: every bare specifier a new file
imports has to be in the import map.

`codemirror/modes/` holds **all 103** stream modes from
`@codemirror/legacy-modes@6.5.5` (`mode/<name>.js`, MIT, 870 KB together), which
is what gives a fenced Python, SQL, Rust or shell block its colours inside the
editor. They have no dependencies of their own — only `simple-mode.js`, which a
few of them import — and are reached by URL rather than by specifier, because
which one is needed depends on what the author typed. `assets/js/core/languages.js`
says which file and which export each language uses; a hundred and twenty-one of
them are wired up there.

Whole directory rather than a chosen twenty: they are fetched one at a time and
only on demand, so an unused mode costs disk and nothing else, while a missing
one is a block that stays grey. Picking which languages a course might contain
is not a decision worth making at vendoring time.

**`@codemirror/language-data` is deliberately absent.** It is the obvious way to
offer every language at once, and it resolves each one with a dynamic
`import('@codemirror/lang-…')` that no import map can satisfy — the first fenced
block would try to reach npm. The explicit table in
`assets/js/core/languages.js` is the price of staying offline.

## The preview

| Specifier / path                    | Version  | Licence   | Source                                                            |
|-------------------------------------|----------|-----------|-------------------------------------------------------------------|
| `shiki` → `shiki/core.js`           | 4.5.0    | MIT       | https://esm.sh/@shikijs/core@4.5.0/es2022/core.bundle.mjs        |
| `shiki/engine` → `shiki/engine-javascript.js` | 4.5.0 | MIT | https://esm.sh/@shikijs/engine-javascript@4.5.0/es2022/engine-javascript.bundle.mjs |
| `shiki/langs/*.mjs` (360 files)     | 4.5.0    | MIT       | https://unpkg.com/@shikijs/langs@4.5.0/dist/<name>.mjs             |
| `shiki/themes/*.mjs` (2 files)      | 4.5.0    | MIT       | https://unpkg.com/@shikijs/themes@4.5.0/dist/<name>.mjs            |
| `mermaid/mermaid.esm.min.mjs` + `mermaid/chunks/mermaid.esm.min/*.mjs` (104 files) | 12.2.0 | MIT | https://unpkg.com/mermaid@12.2.0/dist/ (same paths) |
| `mathjax/tex-mml-svg.js`            | 3.2.2    | Apache-2.0| https://unpkg.com/mathjax@3.2.2/es5/tex-mml-svg.js                 |

## Notes

**Vue** is the full build, compiler included, because components carry their
templates as strings. Swapping in the runtime-only build would require a
compile step and buys about 40 KB — not a trade worth making here.

**DOMPurify** is not optional. Everything an AI writes is untrusted text and the
Markdown preview renders it with `v-html`; the sanitiser is what stands between
a generated page and script execution in the editor.

**marked** only ever renders the preview. What gets published is the raw
Markdown — BookStack does its own rendering.

From version 16 the published `lib/marked.esm.js` is minified, so this is the
one vendored file that is no longer readable source. It is still the package's
own build from the package's own path — the rule at the top of this file holds —
but a stack trace from inside marked will not be legible, and the map is not
vendored. Worth knowing before debugging the preview.

**Fuse.js** powers the fuzzy inputs (model picker, tag picker, course and tag
search) behind `core/fuzzy.js`, which is the only file that imports it.

**Shiki** is split the way it is on purpose. `core.js` and
`engine-javascript.js` are esm.sh bundles because their own dependency trees are
a dozen packages deep and none of it is shared with anything else. The grammars
and themes are the original files, untransformed, because they are plain data
that the highlighter is handed at runtime — one file per language, fetched the
first time a block in that language appears. The JavaScript regex engine is used
rather than the Oniguruma one so there is no `.wasm` to serve.

`langs/` is the *entire* `@shikijs/langs` distribution: 260 grammars, 100 files
that are nothing but an alias (`bash.mjs` re-exports `shellscript.mjs`), and
`index.mjs` left out because it imports all of them at once. Two hundred and
thirty-nine are offered to an author through `assets/js/core/languages.js`; the
remaining twenty-one exist only because something else imports them — a Vue file
needs `vue-directives`, a C++ file needs `cpp-macro` — and every grammar states
those dependencies as ordinary relative imports, which is the reason to vendor
the set whole rather than pick from it. Nothing is fetched that a page does not
contain, so the cost of the other two hundred is disk.

**Mermaid** is the ES build, with its whole chunk directory vendored beside it,
and `assets/js/core/diagrams.js` imports the entry on the first diagram. Up to
11.x this was the single-file build instead, on the grounds that the ES build is
a stub that pulls its chunks over the network; vendoring the chunks answers that,
and Mermaid 12 made the single file the expensive choice. It now inlines the ELK
layout engine, so it grew from 3.5 MB to 5.5 MB (1.0 to 1.6 MB gzipped) whatever
the diagram. The ES build fetches a 707 KB core (183 KB gzipped) and then the
chunks the diagram on screen needs: a flowchart adds 156 KB (55 KB gzipped), a
sequence diagram 118 KB; ELK's 1.6 MB is fetched only by a diagram whose front
matter asks for `layout: elk`. Measured with a walk of the import graph, not
guessed - the numbers move with every release.

The chunk names carry a content hash (`flowDiagram-KMDKLPNL.mjs`), so the
year-long immutable caching on this directory stays true without a version stamp
on each of them; only the entry is stamped. Every import inside the build is
relative - there is no bare specifier for the import map to answer and nothing
reaches a CDN. Both the preview and BookStackDev pin `layout: 'dagre'` and
`look: 'classic'`, the 11.x defaults, so 12 draws a published diagram the way it
was drawn before. Mermaid 12 needs Safari 17.4 or later; Chrome, Edge and Firefox
have been past its ES2024 floor for longer.

**MathJax** is version 3 rather than 4, and the SVG output rather than CHTML.
Both follow from the same requirement: nothing may be fetched at runtime.
Version 3's SVG component carries every glyph outline it will ever need inside
the single file, so there are no web fonts to vendor.

Version 4 nearly does the same and not quite. Its combined `tex-mml-svg.js` does
inline the common outlines — the claim that it moves the whole font out is
wrong — but the new default font's *extended* ranges were broken out into
separately loaded chunks, and the bundle carries
`dynamicPrefix: "@mathjax/mathjax-newcm-font/js/svg/dynamic"` with a loader that
defaults to jsDelivr. MathJax's own documentation is plain about what happens
then: it pauses and waits for the data to arrive. So on an offline install a
`\mathcal` would hang rather than render. Vendoring v4 properly means also
vendoring that dynamic directory out of a 49 MB font package and repointing
`MathJax.loader.paths` — a project rather than a version bump. 3.2.2 is the last
3.x release, so this pin cannot drift any further.

The trailing `//# sourceMappingURL=` comments were removed from `marked.esm.js`,
`purify.es.mjs` and every file under `mermaid/`: the `.map` files are not
vendored, and leaving the comments in produces a 404 on every page load with
devtools open.

One line was removed from `shiki/core.js` for the same reason —
`import __Process$ from "/node/process.mjs"`, an esm.sh shim for a Node global
that the bundle only reads inside a `typeof __Process$ < "u"` guard. Undeclared,
that guard is simply false, which is the behaviour that was wanted anyway.

Icons are **not** a dependency — they are inline SVG paths in
`assets/js/components/AppIcon.js`, so there is no icon font, no CDN stylesheet
and no flash of unstyled icons.

## Weight

About 20 MB on disk, in 601 files — most of it the two grammar directories and
Mermaid's chunks.
Almost none of it is on the critical path, because everything added for the
editor and the preview is behind a lazy import, and both grammar sets are
fetched one file at a time. Measured on a page that uses all of it
(uncompressed transfer; `mod_deflate` takes roughly two thirds off again):

| Fetched                                   | When                                    | KB    |
|-------------------------------------------|-----------------------------------------|-------|
| the shell — Vue, marked, DOMPurify, Fuse, all application JS and CSS | always     | 627   |
| CodeMirror, plus one mode per language shown | the Content tab is opened            | 1 535 |
| Shiki, plus one file per language shown   | the preview holds a fenced block        | 745   |
| Mermaid core, plus the chunks one diagram type needs (a flowchart) | the preview holds a diagram | 863 |
| MathJax                                   | the preview holds a formula             | 2 071 |

Signing in still costs what it did in 3.0, and opening a course costs what it
did with a fifth as many grammars on disk. A course with no diagrams never
downloads Mermaid, one with no formulas never downloads MathJax, and a course
about Python never downloads the Rust grammar — or the other two hundred and
thirty.
