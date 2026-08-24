/**
 * The language registry — one table, read by both halves of the editor.
 *
 * A fenced block names its language in an info string, and three different
 * things then need to agree about what that name meant: the preview asks Shiki
 * for a TextMate grammar, the editor asks CodeMirror for a stream mode, and the
 * header above the block prints a name a human recognises. Before this file
 * each of them kept its own list, which is how ```c# ended up highlighted in
 * one half and plain in the other.
 *
 * Nothing here imports anything. The preview must not drag CodeMirror in, and
 * the editor must not drag Shiki in, so this module is data and string
 * matching only; each side turns the table into loaders of its own.
 *
 * Three tables:
 *
 * - `SHIKI` — every grammar in `assets/vendor/shiki/langs`, as
 *   `id: 'Display Name'` or `id: 'Display Name|alias,alias'`. Generated from
 *   `@shikijs/langs` 3.23.0, aliases and all; see `VENDOR.md`.
 * - `EXTRA` — aliases the grammars do not declare but an author (or a language
 *   model) will write anyway: `golang`, `mysql`, `htaccess`, `c++`. Applied
 *   after `SHIKI`, so it can also correct one — Shiki hands `cmd` to Visual
 *   Basic, which is not what anybody writing ```cmd means.
 * - `MODES` — the CodeMirror stream mode that colours the same language inside
 *   the editor, as `'file'` or `'file#export'` under
 *   `assets/vendor/codemirror/modes`. Far fewer, because CodeMirror's legacy
 *   modes cover about a hundred languages against Shiki's two hundred; a
 *   language missing here is still highlighted in the preview.
 */

/* -- what a fence may be called -------------------------------------------- */

/** Names that mean "show this as it is", not "I have no grammar for this". */
export const PLAIN = new Set([
  '', 'text', 'txt', 'plain', 'plaintext', 'none', 'raw', 'output', 'ansi', 'nohighlight',
]);

/** id → 'Display Name' or 'Display Name|alias,alias'. */
const SHIKI = {
  abap: 'ABAP',
  'actionscript-3': 'ActionScript|actionscript,as3',
  ada: 'Ada',
  'angular-html': 'Angular HTML',
  'angular-ts': 'Angular TypeScript',
  apache: 'Apache Conf',
  apex: 'Apex',
  apl: 'APL',
  applescript: 'AppleScript',
  ara: 'Ara',
  asciidoc: 'AsciiDoc|adoc',
  asm: 'Assembly',
  astro: 'Astro',
  awk: 'AWK',
  ballerina: 'Ballerina',
  bat: 'Batch File|batch',
  beancount: 'Beancount',
  berry: 'Berry|be',
  bibtex: 'BibTeX',
  bicep: 'Bicep',
  bird2: 'BIRD2 Configuration|bird',
  blade: 'Blade',
  bsl: '1C (Enterprise)|1c',
  c: 'C',
  c3: 'C3',
  cadence: 'Cadence|cdc',
  cairo: 'Cairo',
  clarity: 'Clarity',
  clojure: 'Clojure|clj',
  cmake: 'CMake',
  cobol: 'COBOL',
  codeowners: 'CODEOWNERS',
  codeql: 'CodeQL|ql',
  coffee: 'CoffeeScript|coffeescript',
  'common-lisp': 'Common Lisp|lisp',
  coq: 'Coq',
  cpp: 'C++|c++',
  crystal: 'Crystal',
  csharp: 'C#|c#,cs',
  css: 'CSS',
  csv: 'CSV',
  cue: 'CUE',
  cypher: 'Cypher|cql',
  d: 'D',
  dart: 'Dart',
  dax: 'DAX',
  desktop: 'Desktop',
  diff: 'Diff',
  docker: 'Dockerfile|dockerfile',
  dotenv: 'dotEnv',
  'dream-maker': 'Dream Maker',
  edge: 'Edge',
  elixir: 'Elixir',
  elm: 'Elm',
  'emacs-lisp': 'Emacs Lisp|elisp',
  erb: 'ERB',
  erlang: 'Erlang|erl',
  fennel: 'Fennel',
  fish: 'Fish',
  fluent: 'Fluent|ftl',
  'fortran-fixed-form': 'Fortran (Fixed Form)|f,f77,for',
  'fortran-free-form': 'Fortran (Free Form)|f03,f08,f18,f90,f95',
  fsharp: 'F#|f#,fs',
  gdresource: 'GDResource|tres,tscn',
  gdscript: 'GDScript|gd',
  gdshader: 'GDShader',
  genie: 'Genie',
  gherkin: 'Gherkin',
  'git-commit': 'Git Commit Message',
  'git-rebase': 'Git Rebase Message',
  gleam: 'Gleam',
  'glimmer-js': 'Glimmer JS|gjs',
  'glimmer-ts': 'Glimmer TS|gts',
  glsl: 'GLSL',
  gn: 'GN',
  gnuplot: 'Gnuplot',
  go: 'Go',
  graphql: 'GraphQL|gql',
  groovy: 'Groovy',
  hack: 'Hack',
  haml: 'Ruby Haml',
  handlebars: 'Handlebars|hbs',
  haskell: 'Haskell|hs',
  haxe: 'Haxe',
  hcl: 'HashiCorp HCL',
  hjson: 'Hjson',
  hlsl: 'HLSL',
  html: 'HTML',
  http: 'HTTP',
  hurl: 'Hurl',
  hxml: 'HXML',
  hy: 'Hy',
  imba: 'Imba',
  ini: 'INI|properties',
  java: 'Java',
  javascript: 'JavaScript|cjs,js,mjs',
  jinja: 'Jinja',
  jison: 'Jison',
  json: 'JSON',
  json5: 'JSON5',
  jsonc: 'JSON with Comments',
  jsonl: 'JSON Lines',
  jsonnet: 'Jsonnet',
  jssm: 'JSSM|fsl',
  jsx: 'JSX',
  julia: 'Julia|jl',
  just: 'Just',
  kdl: 'KDL',
  kotlin: 'Kotlin|kt,kts',
  kusto: 'Kusto|kql',
  latex: 'LaTeX',
  lean: 'Lean 4|lean4',
  less: 'Less',
  liquid: 'Liquid',
  llvm: 'LLVM IR',
  log: 'Log file',
  logo: 'Logo',
  lua: 'Lua',
  luau: 'Luau',
  make: 'Makefile|makefile',
  markdown: 'Markdown|md',
  marko: 'Marko',
  matlab: 'MATLAB',
  mdc: 'MDC',
  mdx: 'MDX',
  mermaid: 'Mermaid|mmd',
  mipsasm: 'MIPS Assembly|mips',
  mojo: 'Mojo',
  moonbit: 'MoonBit|mbt,mbti',
  move: 'Move',
  narrat: 'Narrat Language|nar',
  nextflow: 'Nextflow|nf',
  nginx: 'Nginx',
  nim: 'Nim',
  nix: 'Nix',
  nushell: 'Nushell|nu',
  'objective-c': 'Objective-C|objc',
  'objective-cpp': 'Objective-C++|objcpp',
  ocaml: 'OCaml',
  odin: 'Odin',
  openscad: 'OpenSCAD|scad',
  pascal: 'Pascal',
  perl: 'Perl',
  php: 'PHP',
  pkl: 'Pkl',
  plsql: 'PL/SQL',
  po: 'Gettext PO|pot,potx',
  polar: 'Polar',
  postcss: 'PostCSS',
  powerquery: 'PowerQuery',
  powershell: 'PowerShell|ps,ps1',
  prisma: 'Prisma',
  prolog: 'Prolog',
  proto: 'Protocol Buffers|protobuf',
  pug: 'Pug|jade',
  puppet: 'Puppet',
  purescript: 'PureScript',
  python: 'Python|py',
  qml: 'QML',
  qmldir: 'QML Directory',
  qss: 'Qt Style Sheets',
  r: 'R',
  racket: 'Racket',
  raku: 'Raku|perl6',
  razor: 'ASP.NET Razor',
  reg: 'Windows Registry Script',
  regexp: 'RegExp|regex',
  rel: 'Rel',
  riscv: 'RISC-V',
  ron: 'RON',
  rosmsg: 'ROS Interface',
  rst: 'reStructuredText',
  ruby: 'Ruby|rb',
  rust: 'Rust|rs',
  sas: 'SAS',
  sass: 'Sass',
  scala: 'Scala',
  scheme: 'Scheme',
  scss: 'SCSS',
  sdbl: '1C (Query)|1c-query',
  shaderlab: 'ShaderLab|shader',
  shellscript: 'Shell|bash,sh,shell,zsh',
  shellsession: 'Shell Session|console',
  smalltalk: 'Smalltalk',
  solidity: 'Solidity',
  soy: 'Closure Templates|closure-templates',
  sparql: 'SPARQL',
  splunk: 'Splunk Query Language|spl',
  sql: 'SQL',
  'ssh-config': 'SSH Config',
  stata: 'Stata',
  stylus: 'Stylus|styl',
  surrealql: 'SurrealQL|surql',
  svelte: 'Svelte',
  swift: 'Swift',
  'system-verilog': 'SystemVerilog',
  systemd: 'Systemd Units',
  talonscript: 'TalonScript|talon',
  tasl: 'Tasl',
  tcl: 'Tcl',
  templ: 'Templ',
  terraform: 'Terraform|tf,tfvars',
  tex: 'TeX',
  toml: 'TOML',
  'ts-tags': 'TypeScript with Tags|lit',
  tsv: 'TSV',
  tsx: 'TSX',
  turtle: 'Turtle',
  twig: 'Twig',
  typescript: 'TypeScript|cts,mts,ts',
  typespec: 'TypeSpec|tsp',
  typst: 'Typst|typ',
  v: 'V',
  vala: 'Vala',
  vb: 'Visual Basic|vb.net,vbnet',
  verilog: 'Verilog',
  vhdl: 'VHDL',
  viml: 'Vim Script|vim,vimscript',
  vue: 'Vue',
  'vue-vine': 'Vue Vine',
  vyper: 'Vyper|vy',
  wasm: 'WebAssembly|wat',
  wenyan: 'Wenyan|文言',
  wgsl: 'WGSL',
  wikitext: 'Wikitext|mediawiki,wiki',
  wit: 'WebAssembly Interface Types',
  wolfram: 'Wolfram|wl,mathematica',
  xml: 'XML',
  xsl: 'XSL',
  yaml: 'YAML|yml',
  zenscript: 'ZenScript',
  zig: 'Zig',
};

/**
 * Aliases the grammars do not declare. Applied after `SHIKI`, so an entry here
 * also overrides one — `cmd` belongs to a batch file, not to Visual Basic.
 */
const EXTRA = {
  apache: ['htaccess', 'apacheconf', 'apache2'],
  asm: ['assembly', 'nasm', 'x86', 'x86asm', 'gas'],
  bat: ['cmd', 'dosbatch', 'winbatch'],
  c: ['h'],
  cpp: ['cc', 'cxx', 'hpp', 'hxx', 'c++filt'],
  csharp: ['dotnet'],
  csv: ['comma-separated-values'],
  diff: ['patch', 'udiff'],
  docker: ['containerfile'],
  dotenv: ['env'],
  elixir: ['ex', 'exs'],
  erlang: ['escript'],
  fsharp: ['fsx'],
  go: ['golang'],
  groovy: ['gradle'],
  hcl: ['tfstate'],
  html: ['htm', 'xhtml'],
  ini: ['cfg', 'conf', 'config', 'editorconfig', 'gitconfig'],
  java: ['jsp'],
  javascript: ['node', 'javascript-jsx', 'es6', 'ecmascript'],
  json: ['jsonp'],
  julia: [],
  kotlin: ['kotlinscript'],
  latex: ['ltx'],
  lua: ['luascript'],
  make: ['mk', 'gnumake', 'bsdmake'],
  markdown: ['mkd', 'mdown', 'commonmark'],
  matlab: ['m'],
  nginx: ['nginxconf'],
  'objective-c': ['obj-c', 'obj-c++'],
  perl: ['pl', 'pm'],
  php: ['php3', 'php4', 'php5', 'php8', 'phtml'],
  powershell: ['pwsh', 'posh'],
  python: ['python3', 'py3', 'pyi', 'ipython', 'sage'],
  r: ['rscript', 'rlang'],
  ruby: ['jruby', 'rake', 'gemfile'],
  rust: ['rustscript'],
  scss: ['sass-scss'],
  shellscript: ['ksh', 'dash', 'bash-script', 'shell-script', 'sh-session'],
  shellsession: ['terminal', 'bash-console', 'command-line'],
  solidity: ['sol'],
  sql: ['mysql', 'postgres', 'postgresql', 'pgsql', 'psql', 'sqlite', 'sqlite3',
    'tsql', 'mssql', 'mariadb', 'ddl', 'dql'],
  swift: [],
  toml: ['cargo'],
  typescript: ['tsconfig'],
  vue: ['vuejs'],
  xml: ['svg', 'rss', 'atom', 'xsd', 'wsdl', 'plist', 'pom', 'xaml'],
  yaml: ['yamlfile', 'openapi', 'swagger', 'kubernetes', 'k8s', 'docker-compose'],
};

/**
 * shiki id → the CodeMirror stream mode file (and export, after `#`) that
 * colours it in the editor. A language absent from this table is still
 * highlighted in the preview; it is only plain while it is being typed.
 *
 * Some entries are deliberate approximations rather than a real mode: Solidity
 * and Zig get the C++ mode, GDScript the Python one, Racket the Scheme one. A
 * near relative colours strings, comments and numbers correctly and misses a
 * few keywords, which is a great deal better than one undifferentiated block —
 * and the preview, where the real grammar runs, is a click away.
 */
const MODES = {
  apl: 'apl',
  apex: 'clike#java',
  asm: 'gas',
  ballerina: 'clike#java',
  bibtex: 'stex',
  c: 'clike#c',
  c3: 'clike#c',
  cairo: 'clike#cpp',
  clojure: 'clojure',
  cmake: 'cmake',
  cobol: 'cobol',
  coffee: 'coffeescript#coffeeScript',
  'common-lisp': 'commonlisp#commonLisp',
  cpp: 'clike#cpp',
  crystal: 'crystal',
  csharp: 'clike#csharp',
  cypher: 'cypher',
  d: 'd',
  dart: 'clike#dart',
  desktop: 'properties',
  diff: 'diff',
  docker: 'dockerfile#dockerFile',
  dotenv: 'properties',
  elm: 'elm',
  'emacs-lisp': 'commonlisp#commonLisp',
  erlang: 'erlang',
  fennel: 'scheme',
  fish: 'shell',
  'fortran-fixed-form': 'fortran',
  'fortran-free-form': 'fortran',
  fsharp: 'mllike#fSharp',
  gdscript: 'python',
  gdshader: 'clike#shader',
  genie: 'clike#java',
  gherkin: 'gherkin',
  'git-commit': 'diff',
  'git-rebase': 'diff',
  glsl: 'clike#shader',
  go: 'go',
  groovy: 'groovy',
  haskell: 'haskell',
  haxe: 'haxe',
  hlsl: 'clike#shader',
  http: 'http',
  hurl: 'http',
  hxml: 'haxe#hxml',
  hy: 'scheme',
  ini: 'properties',
  java: 'clike#java',
  jinja: 'jinja2',
  julia: 'julia',
  just: 'shell',
  kotlin: 'clike#kotlin',
  latex: 'stex',
  less: 'css#less',
  liquid: 'jinja2',
  lua: 'lua',
  luau: 'lua',
  matlab: 'octave',
  mipsasm: 'gas',
  mojo: 'python',
  move: 'clike#cpp',
  nextflow: 'groovy',
  nginx: 'nginx',
  nushell: 'shell',
  'objective-c': 'clike#objectiveC',
  'objective-cpp': 'clike#objectiveCpp',
  ocaml: 'mllike#oCaml',
  odin: 'clike#c',
  pascal: 'pascal',
  perl: 'perl',
  plsql: 'sql#plSQL',
  powershell: 'powershell#powerShell',
  proto: 'protobuf',
  pug: 'pug',
  puppet: 'puppet',
  purescript: 'haskell',
  python: 'python',
  r: 'r',
  racket: 'scheme',
  raku: 'perl',
  reg: 'properties',
  riscv: 'gas',
  ron: 'rust',
  ruby: 'ruby',
  rust: 'rust',
  sas: 'sas',
  sass: 'sass',
  scala: 'clike#scala',
  scheme: 'scheme',
  scss: 'css#sCSS',
  shaderlab: 'clike#shader',
  shellscript: 'shell',
  shellsession: 'shell',
  smalltalk: 'smalltalk',
  solidity: 'clike#cpp',
  sparql: 'sparql',
  'ssh-config': 'properties',
  sql: 'sql#standardSQL',
  stylus: 'stylus',
  swift: 'swift',
  'system-verilog': 'verilog',
  systemd: 'properties',
  tcl: 'tcl',
  tex: 'stex',
  toml: 'toml',
  turtle: 'turtle',
  twig: 'jinja2',
  v: 'clike#c',
  vala: 'clike#java',
  vb: 'vb',
  verilog: 'verilog',
  vhdl: 'vhdl',
  vyper: 'python',
  wasm: 'wast',
  wgsl: 'clike#shader',
  wolfram: 'mathematica',
  xml: 'xml',
  xsl: 'xml',
  yaml: 'yaml',
  zig: 'clike#cpp',
};

/* -- the maps everything else reads ---------------------------------------- */

/** id → display name. */
const LABELS = new Map();
/** any name a fence may use, lowercased → canonical id. */
const NAMES = new Map();

for (const [id, spec] of Object.entries(SHIKI)) {
  const [label, aliases = ''] = spec.split('|');
  LABELS.set(id, label);
  NAMES.set(id, id);
  for (const alias of aliases.split(',')) if (alias) NAMES.set(alias.toLowerCase(), id);
}
for (const [id, aliases] of Object.entries(EXTRA)) {
  for (const alias of aliases) NAMES.set(alias.toLowerCase(), id);
}

/** Every grammar id, for the diagnostics in `tools/`. */
export const GRAMMARS = LABELS;

/**
 * The bare language name inside an info string. Everything after the first
 * word is metadata (```js title="x"), and the three decorations that turn up
 * in generated Markdown are stripped as well: `{.python}`, `language-python`
 * and `python:app.py`.
 */
export function normaliseInfo(info) {
  let name = String(info ?? '').trim().split(/[\s,;]+/)[0].toLowerCase();
  name = name.replace(/^[{[(]+/, '').replace(/[}\])]+$/, '');
  name = name.replace(/^[.#]/, '').replace(/^language-/, '').replace(/^lang-/, '');
  return name.split(':')[0].split('=')[0];
}

/**
 * Whether a fence *asked* for no highlighting. Writing nothing is not asking:
 * an empty info string is silence, and silence is what the detector is there
 * to fill in. ```text is a decision, and is treated as one.
 */
export function isPlain(info) {
  const name = normaliseInfo(info);
  return name !== '' && PLAIN.has(name);
}

/** The grammar id an info string names, or `null` when nothing here matches. */
export function resolveLanguage(info) {
  const name = normaliseInfo(info);
  if (PLAIN.has(name)) return null;
  return NAMES.get(name) ?? null;
}

/** The name to print above a block: 'C++', 'Shell', 'TypeScript'. */
export function languageLabel(id, fallback = '') {
  if (!id) return fallback;
  return LABELS.get(id) ?? id;
}

/** `{ file, export }` for the editor's stream mode, or `null`. */
export function editorMode(id) {
  const spec = Object.hasOwn(MODES, id) ? MODES[id] : null;
  if (!spec) return null;
  const [file, name] = spec.split('#');
  return { file, export: name ?? file };
}

/** Every id the editor can colour, for building its language descriptions. */
export function editorLanguages() {
  return Object.keys(MODES);
}

/** Every name that resolves to `id` — what a language description is matched on. */
export function aliasesOf(id) {
  const found = [];
  for (const [name, target] of NAMES) if (target === id && name !== id) found.push(name);
  return found;
}
