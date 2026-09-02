/**
 * languages.js - alias tables, language hint extraction, Shiki id resolution.
 */

import {opts} from './config.js';

/* BookStack / CodeMirror / highlight.js mode names -> Shiki language ids */
export const ALIASES = {
  js: 'javascript', jsx: 'jsx', ts: 'typescript', tsx: 'tsx', mjs: 'javascript', cjs: 'javascript',
  node: 'javascript', nodejs: 'javascript', ecmascript: 'javascript',
  html: 'html', xhtml: 'html', htmlmixed: 'html', markup: 'html', htm: 'html',
  xml: 'xml', svg: 'xml', rss: 'xml', xsl: 'xml', plist: 'xml',
  css: 'css', scss: 'scss', sass: 'sass', less: 'less', stylus: 'stylus', styl: 'stylus', postcss: 'css',
  sh: 'bash', shell: 'bash', shellsession: 'bash', console: 'bash', bash: 'bash', zsh: 'bash',
  fish: 'fish', batch: 'bat', cmd: 'bat', dos: 'bat', bat: 'bat',
  powershell: 'powershell', pwsh: 'powershell', ps: 'powershell', ps1: 'powershell',
  py: 'python', python2: 'python', python3: 'python', ipython: 'python', 'python-repl': 'python',
  rb: 'ruby', erb: 'erb', gemfile: 'ruby', golang: 'go', rs: 'rust',
  'c++': 'cpp', cpp: 'cpp', cc: 'cpp', cxx: 'cpp', c_cpp: 'cpp', hpp: 'cpp', hxx: 'cpp', h: 'c',
  cs: 'csharp', 'c#': 'csharp', csharp: 'csharp', razor: 'razor', cshtml: 'razor',
  objectivec: 'objective-c', objc: 'objective-c', 'obj-c': 'objective-c', mm: 'objective-cpp',
  yml: 'yaml', yaml: 'yaml', md: 'markdown', markdown: 'markdown', mdx: 'mdx', mdc: 'markdown',
  json5: 'json5', jsonc: 'jsonc', jsonl: 'json', json: 'json',
  text: 'plaintext', plain: 'plaintext', plaintext: 'plaintext', txt: 'plaintext', none: 'plaintext', '': 'plaintext',
  docker: 'docker', dockerfile: 'docker', 'docker-compose': 'yaml',
  vue: 'vue', vuehtml: 'vue-html', svelte: 'svelte', astro: 'astro', angular: 'angular-ts',
  php: 'php', 'php-template': 'php', phtml: 'php', blade: 'blade',
  sql: 'sql', mysql: 'sql', pgsql: 'sql', postgres: 'sql', postgresql: 'sql', plsql: 'plsql', sqlite: 'sql',
  ini: 'ini', cfg: 'ini', conf: 'ini', properties: 'ini', toml: 'toml',
  diff: 'diff', patch: 'diff', git: 'diff',
  make: 'make', makefile: 'make', cmake: 'cmake', nginx: 'nginx', apache: 'apache', apacheconf: 'apache',
  kt: 'kotlin', kts: 'kotlin', java: 'java', groovy: 'groovy', gradle: 'groovy', scala: 'scala',
  dart: 'dart', swift: 'swift', obj: 'objective-c',
  tex: 'latex', latex: 'latex', bibtex: 'bibtex',
  r: 'r', rscript: 'r', lua: 'lua', perl: 'perl', pl: 'perl', raku: 'raku',
  graphql: 'graphql', gql: 'graphql', hbs: 'handlebars', handlebars: 'handlebars',
  twig: 'twig', liquid: 'liquid', jinja: 'jinja', j2: 'jinja', pug: 'pug', jade: 'pug', haml: 'haml',
  ex: 'elixir', exs: 'elixir', erl: 'erlang', hs: 'haskell', clj: 'clojure', cljs: 'clojure',
  elm: 'elm', fs: 'fsharp', 'f#': 'fsharp', fsharp: 'fsharp', ml: 'ocaml', nim: 'nim',
  zig: 'zig', v: 'v', vim: 'viml', viml: 'viml', vimscript: 'viml',
  tf: 'terraform', terraform: 'terraform', hcl: 'hcl', ansible: 'yaml',
  proto: 'proto', protobuf: 'proto', solidity: 'solidity', sol: 'solidity',
  asm: 'asm', wasm: 'wasm', vb: 'vb', vbnet: 'vb', vbscript: 'vb',
  matlab: 'matlab', octave: 'matlab',
  prisma: 'prisma', graphviz: 'dot', dot: 'dot', csv: 'csv', http: 'http', regex: 'regexp',
  log: 'log', env: 'dotenv', dotenv: 'dotenv', editorconfig: 'ini', gitignore: 'ignore',
};

/* highlight.js ids that differ from Shiki's. */
export const HLJS_TO_SHIKI = {
  xml: 'html',                // hljs reports HTML as "xml"
  dockerfile: 'docker',
  shell: 'bash',
  console: 'bash',
  dos: 'bat',
  excel: 'plaintext',
  gams: 'plaintext',
  properties: 'ini',
  vbnet: 'vb',
  objectivec: 'objective-c',
  arduino: 'cpp',
  crmsh: 'bash',
  gcode: 'plaintext',
  x86asm: 'asm',
  armasm: 'asm',
  delphi: 'pascal',
  'php-template': 'php',
};

/* hljs languages that are greedy in auto-detection and almost never what a
   wiki page contains - weak matches on these are discarded. */
export const HLJS_LOW_TRUST = new Set([
  '1c', 'abnf', 'accesslog', 'arcade', 'axapta', 'bnf', 'brainfuck', 'cal',
  'ceylon', 'clean', 'dsconfig', 'dts', 'ebnf', 'flix', 'gams', 'gauss',
  'gcode', 'golo', 'hsp', 'inform7', 'irpf90', 'isbl', 'jboss-cli',
  'leaf', 'livecodeserver', 'mercury', 'mizar', 'mojolicious', 'monkey',
  'n1ql', 'nsis', 'openscad', 'parser3', 'pf', 'pony', 'profile', 'qml',
  'roboconf', 'routeros', 'rsl', 'sas', 'scilab', 'smali', 'sqf', 'stan',
  'step21', 'subunit', 'taggerscript', 'tap', 'tcl', 'vala', 'xl',
  'xquery', 'zephir', 'maxima', 'mipsasm', 'nix', 'oxygene', 'purebasic',
  /* Greedy on short, ordinary code: six lines of Python came back as a DNS
     zone file, and the block stayed plain because Shiki has no such grammar. */
  'dns', 'awk', 'angelscript', 'lasso', 'lsl', 'dust', 'django', 'mel',
  'moonscript', 'x86asm', 'armasm', 'avrasm', 'excel', 'basic', 'brainfuck',
  'capnproto', 'coq', 'crmsh', 'dart-ish', 'gml', 'haxe', 'hy', 'julia-repl',
  'leaf', 'llvm', 'lua', 'nginx', 'parser3', 'pgsql', 'processing', 'puppet',
  'q', 'reasonml', 'roboconf', 'ruleslanguage', 'smalltalk', 'sml', 'stata',
  'thrift', 'tp', 'twig', 'vbscript-html', 'verilog', 'vhdl', 'vim', 'wren',
]);

export function normaliseLang(raw) {
  if (!raw) return null;
  let lang = String(raw).trim().toLowerCase();
  lang = lang.replace(/^language-/, '').replace(/^lang-/, '').replace(/\s+.*$/, '');
  return lang || null;
}

/**
 * FIX - `el.className` is an SVGAnimatedString on SVG nodes (and a plain
 * object after some frameworks touch it), which made the regex match nothing.
 * `classList` is used first, with a defensive string coercion as backup.
 */
function classString(el) {
  if (!el) return '';
  if (el.classList && el.classList.length) return Array.prototype.join.call(el.classList, ' ');
  const cn = el.className;
  if (typeof cn === 'string') return cn;
  if (cn && typeof cn.baseVal === 'string') return cn.baseVal;
  return '';
}

export function langFromClasses(el) {
  if (!el) return null;
  const m = /(?:^|\s)(?:language|lang|highlight-source)-([\w+#.-]+)/i.exec(classString(el));
  if (m) return normaliseLang(m[1]);
  if (el.getAttribute) {
    return normaliseLang(
      el.getAttribute('data-lang') ||
      el.getAttribute('data-language') ||
      el.getAttribute('data-mode') ||
      el.getAttribute('data-code-language'),
    );
  }
  return null;
}

export function findLangHint(el) {
  if (!el) return null;
  const candidates = [
    el,
    el.parentElement,
    el.parentElement && el.parentElement.parentElement,
    el.previousElementSibling,
    el.closest && el.closest('[class*="language-"], [class*="lang-"], [data-lang], [data-language], [data-mode]'),
    el.querySelector && el.querySelector('[class*="language-"], [data-lang], [data-language]'),
  ];
  for (const c of candidates) {
    /* buildBlock() stamps data-lang on every rendered .shiki-block, so a <pre>
       whose previous sibling is an already-highlighted block would otherwise
       inherit that block's language. Never read a hint off our own chrome. */
    if (c && c.classList && c.classList.contains('shiki-block')) continue;
    const lang = langFromClasses(c);
    if (lang) return lang;
  }
  return null;
}

export function isSkippedLang(lang) {
  if (!lang) return false;
  /* opts.skipLanguages is normalised to lower case in config.js. */
  const base = String(lang).trim().toLowerCase();
  const norm = ALIASES[base] || base;
  return opts.skipLanguages.indexOf(base) !== -1 || opts.skipLanguages.indexOf(norm) !== -1;
}

/* Mermaid blocks lose their language class when BookStack mounts CodeMirror,
   so the first keyword of the diagram is used as a second line of defence.
   Without this the block is auto-detected and rendered as code. */
const MERMAID_START = /^\s*(?:%%\{[\s\S]*?\}%%\s*)?(?:graph\s+(?:TB|TD|BT|RL|LR)\b|flowchart(?:-elk)?\s+(?:TB|TD|BT|RL|LR)\b|sequenceDiagram|classDiagram(?:-v2)?|stateDiagram(?:-v2)?|erDiagram|journey|gantt|pie\b|requirementDiagram|gitGraph|mindmap|timeline|quadrantChart|zenuml|kanban|C4(?:Context|Container|Component|Dynamic|Deployment)|(?:sankey|xychart|block|packet|architecture|radar|treemap)-beta)\b/;

export function looksLikeMermaid(code) {
  return MERMAID_START.test(String(code || ''));
}

/* ------------------------------------------------- Shiki bundle resolution */

let bundledIndex = null;

function buildBundledIndex(shiki) {
  if (bundledIndex) return bundledIndex;
  bundledIndex = new Map();
  const bundled = shiki.bundledLanguages || {};
  Object.keys(bundled).forEach((id) => bundledIndex.set(id, id));
  (shiki.bundledLanguagesInfo || []).forEach((info) => {
    if (!info || !info.id) return;
    bundledIndex.set(info.id, info.id);
    (info.aliases || []).forEach((a) => {
      if (!bundledIndex.has(a)) bundledIndex.set(a, info.id);
    });
  });
  return bundledIndex;
}

export function resolveLang(shiki, raw) {
  const index = buildBundledIndex(shiki);
  const candidates = [];
  const push = (l) => {
    if (l && candidates.indexOf(l) === -1) candidates.push(l);
  };

  const base = normaliseLang(raw);
  push(base);
  if (base) push(HLJS_TO_SHIKI[base]);
  if (base) push(ALIASES[base]);
  if (base) push(base.replace(/[^a-z0-9+#-]/g, ''));

  for (const c of candidates) {
    const hit = index.get(c);
    if (hit) return hit;
  }

  const fb = ALIASES[opts.fallbackLang] || opts.fallbackLang;
  return index.get(fb) || 'plaintext';
}