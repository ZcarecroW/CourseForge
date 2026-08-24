/**
 * Working out what language a fenced block is written in.
 *
 * A generated course is full of blocks whose fence says nothing, says `text`,
 * or says the wrong thing. Highlighting those correctly is worth a lot; getting
 * it wrong is worse than leaving them plain, because a Python block painted as
 * Ruby reads as a bug in the page rather than a bug in the highlighter. So the
 * whole design of this file is about *declining*:
 *
 * 1. **Evidence, not resemblance.** Each language is described by patterns that
 *    are hard to write by accident — `defmodule`, `#include <stdio.h>`,
 *    `let mut`, `{ get; set; }` — each carrying a weight. A pattern that merely
 *    suggests a family (`{`, `;`, `=>`) is worth almost nothing on its own.
 * 2. **A winner has to win clearly.** A guess is only returned when the leader
 *    passes an absolute score *and* is well clear of the runner-up. Java and C#
 *    look alike from a distance; when the evidence does not separate them, the
 *    answer is "no idea", and the block stays plain.
 * 3. **A fence that names a language is believed.** It is overridden only on
 *    overwhelming evidence — enough that ```python holding a JSON document is
 *    corrected, while ```python holding unusual Python is not.
 *
 * The output feeds two places: the preview picks the Shiki grammar with it, and
 * the header above the block says so, marked as a guess. Nothing is silent.
 */

/* -- the evidence ---------------------------------------------------------- */

/**
 * Patterns that settle the question on their own, tried in order and in this
 * order for a reason — a shebang beats everything, and `<?php` inside HTML
 * means the block is PHP rather than the other way round.
 */
const DECISIVE = [
  [/^#![^\n]*\b(?:bash|zsh|ksh|dash|sh)\b/, 'shellscript'],
  [/^#![^\n]*\bpython[\d.]*\b/, 'python'],
  [/^#![^\n]*\bnode\b/, 'javascript'],
  [/^#![^\n]*\bruby\b/, 'ruby'],
  [/^#![^\n]*\bperl\b/, 'perl'],
  [/^#![^\n]*\bphp\b/, 'php'],
  [/^#![^\n]*\bdeno\b/, 'typescript'],
  [/^#![^\n]*\b(?:pwsh|powershell)\b/, 'powershell'],
  [/^\s*<\?php\b/, 'php'],
  [/^\s*<\?xml\b/, 'xml'],
  [/^\s*<!DOCTYPE\s+html\b/i, 'html'],
  [/^diff --git /m, 'diff'],
  [/^@@ -\d+(?:,\d+)? \+\d+(?:,\d+)? @@/m, 'diff'],
  [/^\s*pragma\s+solidity\b/m, 'solidity'],
  [/^\s*(?:-{3}|={3,})\s*\n?\s*%YAML/m, 'yaml'],
  [/^%YAML\b/m, 'yaml'],
];

/**
 * The two shapes almost every unlabelled block in a course turns out to have:
 * a line of shell, and a line of whichever tool the course is about. They are
 * named once and used twice — once for "this happened", once for "this keeps
 * happening", which is what separates an install snippet from a stray mention.
 */
const UNIX_COMMAND = /^\s*(?:sudo\s+)?(?:echo|cd|ls|pwd|mkdir|rmdir|rm|cp|mv|ln|touch|cat|less|head|tail|grep|sed|awk|find|chmod|chown|export|source|unzip|tar|curl|wget|systemctl|service|kill|ps|df|du)\s+[-\w$"'./]/m;
const TOOL_COMMAND = /^\s*(?:sudo\s+)?(?:npm|npx|yarn|pnpm|bun|deno|node|pip3?|python3?|poetry|conda|git|docker(?:-compose)?|kubectl|helm|composer|cargo|rustup|go|make|mvn|gradle|dotnet|php|ruby|gem|bundle|rails|artisan|terraform|ansible|ssh|scp|rsync|brew|apt|apt-get|yum|dnf|pacman|choco|winget)\s+[\w./-]/m;

/**
 * `[pattern, weight]`, or `[pattern, weight, atLeast]` when a single sighting
 * proves nothing and a habit does. Weights are on one scale across every
 * language, so they can be compared directly:
 *
 *   6–8  only this language writes that
 *   4–5  strongly characteristic
 *   2–3  characteristic, but shared with a relative
 *   1    a family trait — braces, semicolons, arrows
 */
const SIGNATURES = {
  javascript: [
    [/\bconst\s+\w+\s*=|\blet\s+\w+\s*=/, 3],
    [/\bfunction\s*\w*\s*\([^)]*\)\s*\{/, 2],
    [/=>\s*[{(\w'"`]/, 2],
    [/\bconsole\.(?:log|warn|error|info|table)\s*\(/, 4],
    [/\brequire\s*\(\s*['"]/, 4],
    [/\bmodule\.exports\b|\bexports\.\w+\s*=/, 5],
    [/\bdocument\.(?:querySelector|getElementById|addEventListener)\b/, 5],
    [/\bwindow\.\w+|\bnavigator\.\w+/, 3],
    [/===|!==/, 3],
    [/\bimport\s+[\w{},*\s]+\s+from\s+['"]/, 2],
    [/\bexport\s+(?:default|const|function|class)\b/, 2],
    [/\basync\s+function\b|\bawait\s+\w/, 1],
    [/\.then\s*\(|\bPromise\.(?:all|resolve|race)\b/, 3],
    [/\bJSON\.(?:parse|stringify)\s*\(/, 3],
    [/\.(?:map|filter|reduce|forEach)\s*\(\s*(?:\(|\w+\s*=>)/, 2],
    [/`[^`]*\$\{[^}]*\}/, 2],
    /* what would make it TypeScript instead */
    [/\binterface\s+\w+\s*\{|\btype\s+\w+\s*=\s*\{|:\s*(?:string|number|boolean)\b/, -6],
  ],
  typescript: [
    [/\binterface\s+\w+(?:\s+extends\s+[\w<>,\s]+)?\s*\{/, 5],
    [/:[ \t]*(?:string|number|boolean|void|any|unknown|never|object)[ \t]*[;,)={\n]/, 5],
    [/\btype\s+\w+(?:<[^>]*>)?\s*=\s*[\w{'"[(]/, 4],
    [/\b(?:public|private|protected|readonly)\s+\w+\s*[:(]/, 5],
    [/\benum\s+\w+\s*\{/, 4],
    [/\bimplements\s+\w+/, 3],
    [/\bas\s+(?:string|number|const|unknown|any)\b/, 4],
    [/<\w+(?:\[\])?>\s*[({]|\bArray<\w+>|\bPromise<\w+>|\bRecord<\w+/, 3],
    [/\bdeclare\s+(?:module|global|const)\b|\bnamespace\s+\w+\s*\{/, 5],
    [/@(?:Component|Injectable|Input|Output|NgModule)\s*\(/, 5],
    /* the JavaScript underneath, worth little because both share it */
    [/\bconst\s+\w+\s*[:=]|\blet\s+\w+\s*[:=]/, 1],
    [/=>\s*[{(\w'"`]/, 1],
    [/\bimport\s+[\w{},*\s]+\s+from\s+['"]/, 1],
    [/\bexport\s+(?:default|const|function|class|interface|type)\b/, 1],
    [/\bconsole\.(?:log|warn|error)\s*\(/, 1],
  ],
  python: [
    [/^[ \t]*def\s+\w+\s*\([^)]*\)\s*(?:->\s*[\w\[\]., ]+\s*)?:/m, 6],
    [/^[ \t]*class\s+\w+\s*(?:\([\w., ]*\))?\s*:/m, 5],
    [/^[ \t]*(?:from\s+[\w.]+\s+)?import\s+[\w.*,\s]+$/m, 4],
    [/\bself\.\w+/, 4],
    [/^[ \t]*(?:if|elif|else|for|while|try|except|finally|with)\b[^\n]*:[ \t]*$/m, 4],
    [/\b__(?:name|init|main|str|repr|file)__\b/, 5],
    [/\bprint\s*\(/, 1],
    [/\b(?:True|False|None)\b/, 2],
    [/\bf['"][^'"]*\{[^}]*\}/, 4],
    [/^\s*@\w+(?:\.\w+)*\s*(?:\([^)]*\))?\s*$/m, 2],
    [/\b(?:def|lambda)\b[^\n]*:\s*$/m, 1],
    [/\brange\s*\(|\blen\s*\(|\benumerate\s*\(|\bisinstance\s*\(/, 3],
    [/^\s*"""|'''/m, 2],
    [/;\s*$/m, -2],
  ],
  ruby: [
    [/^[ \t]*def\s+\w+[?!=]?(?:\s*\([^)]*\))?[ \t]*$/m, 5],
    [/^[ \t]*end[ \t]*$/m, 4, 2],
    [/\bputs\s+["'\w@:]/, 5],
    [/\bdo\s*\|[\w, ]+\|/, 6],
    [/\brequire(?:_relative)?\s+['"]/, 4],
    [/@\w+\s*=|@@\w+/, 3],
    [/\bnil\b|\bunless\b|\belsif\b/, 3],
    [/^[ \t]*(?:module|class)\s+[A-Z]\w*(?:\s*<\s*[\w:]+)?[ \t]*$/m, 3],
    [/\battr_(?:accessor|reader|writer)\b/, 6],
    [/:\w+\s*=>|\w+:\s+['"\d]/, 1],
    [/\.each\b|\.map\b\s*(?:do|\{)/, 2],
  ],
  java: [
    [/\bpublic\s+static\s+void\s+main\s*\(\s*String\s*(?:\[\]|\.\.\.)\s*\w+\s*\)/, 8],
    [/\bSystem\.(?:out|err)\.print(?:ln|f)?\s*\(/, 6],
    [/^\s*package\s+[\w.]+\s*;/m, 4],
    [/^\s*import\s+(?:static\s+)?[\w.]+(?:\.\*)?\s*;/m, 3],
    [/\bpublic\s+(?:final\s+|abstract\s+)?(?:class|interface|enum)\s+\w+/, 4],
    [/\b(?:public|private|protected)\s+(?:static\s+)?(?:final\s+)?[\w<>,\[\] ]+\s+\w+\s*\(/, 3],
    [/@(?:Override|Test|Entity|Service|Component|Autowired|SpringBootApplication)\b/, 5],
    [/\bnew\s+(?:ArrayList|HashMap|HashSet|StringBuilder|Scanner)\s*[<(]/, 5],
    [/\bthrows\s+\w+Exception\b|\bextends\s+\w+\s*\{/, 2],
    [/\bString\s+\w+\s*=/, 2],
    [/\bstd::|#include|\bnamespace\s+\w+\s*\{|\busing\s+System\b/, -6],
  ],
  csharp: [
    [/^\s*using\s+System(?:\.[\w.]+)?\s*;/m, 7],
    [/\bConsole\.(?:WriteLine|Write|ReadLine)\s*\(/, 7],
    [/\{\s*get;\s*(?:private\s+)?set;\s*\}/, 7],
    [/^\s*namespace\s+[\w.]+\s*[;{]/m, 5],
    [/\bpublic\s+(?:sealed\s+|static\s+|partial\s+|abstract\s+)*(?:class|record|struct|interface)\s+\w+/, 3],
    [/\bvar\s+\w+\s*=\s*new\b/, 3],
    [/\b(?:IEnumerable|IList|ICollection|Task)<[\w<>,\[\] ]+>/, 5],
    [/\[(?:HttpGet|HttpPost|Serializable|Required|Test|Fact)\b/, 5],
    [/\bstring\[\]\s+args\b|\basync\s+Task\b/, 4],
    [/\bnameof\s*\(|\?\?=|=>\s*\w+;/, 2],
  ],
  cpp: [
    [/#include\s*<(?:iostream|vector|string|map|set|algorithm|memory|utility|cstdio)>/, 7],
    [/\bstd::\w+/, 7],
    [/\busing\s+namespace\s+std\s*;/, 8],
    [/\b(?:cout|cerr|cin)\s*(?:<<|>>)/, 6],
    [/\btemplate\s*<\s*(?:typename|class)\s+\w+/, 6],
    [/\bnullptr\b|\bconstexpr\b|\bauto\s+\w+\s*=/, 4],
    [/\bclass\s+\w+\s*(?::\s*(?:public|private|protected)\s+\w+\s*)?\{/, 3],
    [/->\s*\w+\s*[(;]/, 1],
    [/\bnew\s+\w+(?:\[\w*\])?\s*[;(]|\bdelete(?:\[\])?\s+\w+/, 3],
  ],
  c: [
    [/#include\s*<(?:stdio|stdlib|string|math|unistd|stdint|stdbool|errno|time)\.h>/, 7],
    [/\bprintf\s*\(\s*"/, 4],
    [/\bint\s+main\s*\(\s*(?:void|int\s+argc|)\s*[,)]/, 5],
    [/\b(?:malloc|calloc|realloc|free)\s*\(/, 5],
    [/\btypedef\s+struct\b|\bstruct\s+\w+\s*\{/, 3],
    [/\bsizeof\s*\(/, 2],
    [/%[-\d.]*[dsfculx]\b/, 2],
    [/\bstd::|\busing\s+namespace\b|\bclass\s+\w+|\btemplate\s*</, -7],
  ],
  go: [
    [/^\s*package\s+\w+\s*$/m, 5],
    [/\bfunc\s+(?:\(\s*\w+\s+\*?\w+\s*\)\s*)?\w+\s*\(/, 5],
    [/:=/, 4],
    [/\bfmt\.(?:Print|Sprint|Errorf|Fprint)\w*\s*\(/, 7],
    [/\bif\s+err\s*!=\s*nil\s*\{/, 8],
    [/^\s*import\s+\(/m, 4],
    [/\b(?:defer|chan|go\s+func|select)\b/, 4],
    [/\bmake\s*\(\s*(?:\[\]|map\[|chan)/, 5],
    [/\bstruct\s*\{[^}]*`\w+:"/, 5],
  ],
  rust: [
    [/\bfn\s+\w+\s*(?:<[^>]*>)?\s*\(/, 4],
    [/\blet\s+mut\s+\w+/, 7],
    [/\b(?:println|print|format|vec|panic|write|assert_eq)!\s*[([]/, 7],
    [/#\[(?:derive|cfg|test|allow|serde)\b/, 7],
    [/\bimpl\s+(?:<[^>]*>\s*)?\w+|\btrait\s+\w+\s*\{/, 5],
    [/\b(?:Option|Result)\s*<|\bSome\s*\(|\bOk\s*\(|\bErr\s*\(/, 5],
    [/&(?:mut\s+)?(?:str|self)\b|\bString::from\s*\(/, 5],
    [/^\s*use\s+[\w:]+(?:::\{[^}]*\})?\s*;/m, 3],
    [/\bpub\s+(?:fn|struct|enum|mod|const)\b/, 4],
    [/\bmatch\s+\w+\s*\{/, 2],
  ],
  php: [
    [/\$this->\w+/, 7],
    [/^\s*(?:namespace|use)\s+[\w\\]+\s*;/m, 5],
    [/\b(?:public|private|protected)\s+(?:static\s+)?function\s+\w+/, 6],
    [/\becho\s+["'$]/, 4],
    [/\$\w+\s*=\s*/, 2],
    [/\bfunction\s+\w+\s*\([^)]*\)\s*(?::\s*\??\w+\s*)?\{/, 1],
    [/\barray\s*\(|\[\s*['"]\w+['"]\s*=>/, 3],
    [/::class\b|\bnew\s+\\?\w+\s*\(/, 3],
    [/\b(?:isset|empty|var_dump|print_r|json_encode|json_decode)\s*\(/, 5],
    [/->\w+\s*\(/, 1],
  ],
  shellscript: [
    [/^\s*(?:if|while|until)\s+\[{1,2}[^\n]*\]{1,2}\s*;?\s*then\b/m, 7],
    [/^\s*(?:fi|esac|done)\s*$/m, 6],
    [/\bfor\s+\w+\s+in\b[^\n]*;\s*do\b/, 6],
    [UNIX_COMMAND, 5],
    [UNIX_COMMAND, 3, 2],
    [TOOL_COMMAND, 5],
    [TOOL_COMMAND, 3, 2],
    [/\$\{\w+(?:[:#%\-+][^}]*)?\}/, 4],
    [/\$\((?:[^)]+)\)|`[^`]+`/, 3],
    [/\|\s*(?:grep|sed|awk|sort|uniq|head|tail|xargs|wc)\b/, 5],
    [/^\s*\w+\s*\(\)\s*\{/m, 2],
    [/2>&1|>\s*\/dev\/null|\|\||&&\s*\\?$/m, 2],
    [/^\s*#(?!!)[^\n]*$/m, 1],
  ],
  shellsession: [
    [/^\s*[$#]\s+\S/m, 6, 2],
    [/^\s*[\w.-]+@[\w.-]+:[^\n$#]*[$#]\s+\S/m, 8],
    [/^\s*(?:PS )?[A-Z]:\\[^\n>]*>\s*\S/m, 7],
  ],
  powershell: [
    [/\b(?:Write-Host|Write-Output|Get-\w+|Set-\w+|New-\w+|Remove-\w+|Invoke-\w+|Test-Path)\b/, 7],
    [/\|\s*(?:Where-Object|ForEach-Object|Select-Object|Sort-Object)\b/, 8],
    [/\$(?:PSScriptRoot|_|null|true|false|env:\w+)\b/, 6],
    [/\s-(?:eq|ne|gt|lt|ge|le|like|match|contains|not)\b/, 5],
    [/\[(?:string|int|bool|array|hashtable|PSCustomObject)\]/, 5],
    [/^\s*param\s*\(/m, 4],
    [/\$\w+\s*=/, 1],
  ],
  sql: [
    [/\bSELECT\b[\s\S]{0,400}?\bFROM\b/i, 6],
    [/\b(?:INSERT\s+INTO|UPDATE\s+[\w.`"]+\s+SET|DELETE\s+FROM|TRUNCATE\s+TABLE)\b/i, 7],
    [/\bCREATE\s+(?:OR\s+REPLACE\s+)?(?:TABLE|VIEW|INDEX|DATABASE|SCHEMA|PROCEDURE|FUNCTION|TRIGGER)\b/i, 7],
    [/\bALTER\s+TABLE\b|\bDROP\s+(?:TABLE|INDEX|VIEW|DATABASE)\b/i, 7],
    [/\b(?:INNER|LEFT|RIGHT|FULL|CROSS)\s+(?:OUTER\s+)?JOIN\b/i, 5],
    [/\b(?:GROUP|ORDER)\s+BY\b|\bHAVING\b|\bWHERE\b/i, 2],
    [/\b(?:PRIMARY|FOREIGN)\s+KEY\b|\bNOT\s+NULL\b|\bAUTO_INCREMENT\b|\bDEFAULT\s+CURRENT_TIMESTAMP\b/i, 5],
    [/\b(?:VARCHAR|INTEGER|BIGINT|BOOLEAN|TIMESTAMP|DECIMAL)\s*(?:\(\d+\s*(?:,\s*\d+)?\))?/i, 3],
    [/\b(?:COUNT|SUM|AVG|MIN|MAX|COALESCE|DISTINCT)\s*\(/i, 3],
    [/^\s*(?:FROM|WHERE|GROUP\s+BY|ORDER\s+BY|LIMIT|VALUES|SET)\b/im, 3],
    [/\bAS\s+[\w`"]+/i, 1],
  ],
  yaml: [
    [/^[ \t]*[\w.$-]+:\s*(?:$|[^\s:][^\n]*$)/m, 3, 3],
    [/^---\s*$/m, 4],
    [/^[ \t]*-\s+[\w"'{[]/m, 2, 2],
    [/^[ \t]*[\w.-]+:\s*(?:\||>)[-+]?\s*$/m, 5],
    [/^[ \t]*(?:version|name|services|steps|jobs|on|apiVersion|kind|metadata|spec|image|ports|env):/m, 5],
    [/^[ \t]*#[^\n]*$/m, 1],
    [/[;{}]\s*$/m, -3],
    [/^\s*(?:function|class|def|public|const|let|var)\b/m, -4],
  ],
  toml: [
    [/^\s*\[\[[\w.-]+\]\]\s*$/m, 8],
    [/^\s*\[[\w."'-]+\]\s*$/m, 5],
    [/^\s*[\w.-]+\s*=\s*(?:"[^"]*"|'[^']*'|true|false|\d+|\[)/m, 3, 2],
    [/^\s*#[^\n]*$/m, 1],
    [/^\s*\w+\s*=\s*\{\s*\w+\s*=/m, 4],
  ],
  ini: [
    [/^\s*\[[^\]\n=]+\]\s*$/m, 4],
    [/^\s*[\w.\\ -]+\s*=\s*[^\n=]*$/m, 2, 2],
    [/^\s*;[^\n]*$/m, 4],
    [/^\s*\[\[/m, -6],
  ],
  json: [
    [/^\s*[{[]/, 2],
    [/"[\w.$-]+"\s*:\s*(?:"[^"]*"|\d|true|false|null|[{[])/, 4, 2],
    [/[}\]]\s*,?\s*$/m, 1],
    [/^\s*(?:\/\/|#)/m, -4],
    [/'/, -2],
  ],
  html: [
    [/<(?:html|head|body|main|section|article|nav|footer|header|aside)\b[^>]*>/i, 6],
    [/<(?:div|span|p|ul|ol|li|table|tr|td|form|input|button|img|a|h[1-6])\b[^>]*>/i, 3],
    [/<\/(?:div|span|p|ul|li|body|html|head|table|form|a)>/i, 3],
    [/<(?:meta|link|script|style|br|hr|img)\b[^>]*\/?>/i, 4],
    [/\b(?:class|id|href|src|alt|type)\s*=\s*["']/, 3],
    [/<!--[\s\S]*?-->/, 1],
    [/\bxmlns\s*=|<\?xml/, -5],
    /* a component file is not HTML, however much of it is angle brackets */
    [/<template[\s>]|\bv-(?:if|for|model|bind|on|show)\b|[@:]\w+="|\{\{[^}\n]*\}\}|\{#(?:if|each)\b|\bon:\w+=/, -7],
  ],
  xml: [
    [/<\?xml\b/, 8],
    [/\bxmlns(?::\w+)?\s*=\s*["']/, 7],
    [/<\/(?:\w+:)?\w+>/, 2],
    [/<(?:\w+:)\w+\b/, 5],
    [/<!\[CDATA\[/, 6],
    [/<(?:html|body|div|span|p|script|meta|link)\b/i, -5],
  ],
  css: [
    [/^[^{}\n]*[.#]?[\w\->:\s,.[\]="']+\{[^{}]*[\w-]+\s*:\s*[^;{}]+;/m, 5],
    [/@(?:media|import|keyframes|font-face|supports|charset|layer|container)\b/, 6],
    [/[:.]\s*(?:hover|focus|active|first-child|last-child|nth-child|before|after)\b/, 4],
    [/!important\b/, 4],
    [/\b(?:margin|padding|display|position|color|background|font-size|border|width|height|flex|grid)\s*:/, 4],
    [/#[0-9a-fA-F]{3,8}\b|\b\d+(?:px|rem|em|vh|vw|%)\b/, 2],
    [/\bvar\s*\(\s*--[\w-]+/, 4],
    [/[$@][\w-]+\s*:\s*[^;]+;|@(?:mixin|include|extend|use)\b|&[.:&\s]/, -5],
  ],
  scss: [
    [/@(?:mixin|include|extend|use|forward)\b/, 8],
    [/\$[\w-]+\s*:\s*[^;]+;/, 6],
    [/&(?:[.:&_-]|\s*\{)/, 5],
    [/^[^{}\n]*\{[^{}]*\{/m, 3],
    [/@(?:if|else|each|for|while|function|return)\b/, 5],
    [/\b(?:margin|padding|display|color|background|font-size|border)\s*:/, 1],
  ],
  less: [
    [/@[\w-]+\s*:\s*[^;]+;/, 6],
    [/\.[\w-]+\s*\(\s*\)\s*;/, 6],
    [/@(?:import|media)\b[^\n]*;/, 2],
    [/\b(?:margin|padding|display|color|background|font-size|border)\s*:/, 1],
    [/@(?:mixin|include|extend|use)\b|\$[\w-]+\s*:/, -6],
  ],
  markdown: [
    [/^#{1,6}\s+\S/m, 4],
    [/^\s*```/m, 5],
    [/\[[^\]\n]+\]\([^)\n]+\)/, 4],
    [/^\s*(?:[-*+]|\d+\.)\s+\S/m, 2, 2],
    [/^\s*>\s+\S/m, 3],
    [/\*\*[^*\n]+\*\*|__[^_\n]+__/, 3],
    [/^\s*\|[^\n]*\|\s*$/m, 3, 2],
    [/^\s*(?:-{3,}|={3,})\s*$/m, 2],
  ],
  latex: [
    [/\\documentclass(?:\[[^\]]*\])?\{\w+\}/, 8],
    [/\\begin\{\w+\*?\}/, 7],
    [/\\usepackage(?:\[[^\]]*\])?\{[\w,\s]+\}/, 7],
    [/\\(?:section|subsection|chapter|title|author|label|ref|cite)\{/, 5],
    [/\\(?:frac|sum|int|alpha|beta|gamma|mathbb|mathcal)\b/, 3],
    [/\$\$[\s\S]*?\$\$|\\\[[\s\S]*?\\\]/, 2],
  ],
  docker: [
    [/^\s*FROM\s+[\w.\-/]+(?::[\w.-]+)?(?:\s+AS\s+\w+)?\s*$/im, 6],
    [/^\s*(?:RUN|CMD|ENTRYPOINT|COPY|ADD|WORKDIR|EXPOSE|VOLUME|USER|HEALTHCHECK|SHELL|STOPSIGNAL)\s+\S/im, 5],
    [/^\s*(?:ENV|ARG|LABEL)\s+\w+/im, 3],
    [/^\s*#[^\n]*$/m, 1],
  ],
  make: [
    [/^\.PHONY\s*:/m, 8],
    [/^[\w.%$()/@-]+\s*:(?:[^=\n][^\n]*)?$\n^\t/m, 7],
    [/^\t[@-]?\S/m, 4, 2],
    [/^\s*[\w.-]+\s*[:?+]?=\s*/m, 2],
    [/\$\((?:\w+|shell |wildcard |patsubst )/, 4],
  ],
  nginx: [
    [/^\s*(?:server|location|http|upstream|events|map|stream)\b[^\n{]*\{/m, 6],
    [/\b(?:proxy_pass|fastcgi_pass|server_name|try_files|listen)\s+[^\n;]+;/, 7],
    [/\b(?:root|index|access_log|error_log|include)\s+[^\n;]+;/, 3],
    [/\$(?:host|uri|request_uri|remote_addr|scheme|http_\w+)\b/, 5],
  ],
  apache: [
    [/<(?:VirtualHost|Directory|Files|FilesMatch|IfModule|Location|LocationMatch)\b/, 8],
    [/\bRewrite(?:Engine|Rule|Cond|Base)\b/, 7],
    [/\b(?:DocumentRoot|AllowOverride|ServerName|ServerAlias|ErrorLog|CustomLog|Require\s+all)\b/, 6],
    [/\b(?:Options|Order|AddType|Header\s+set)\b/, 3],
  ],
  http: [
    [/^(?:GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)\s+\S+\s+HTTP\/\d/m, 8],
    [/^HTTP\/\d(?:\.\d)?\s+\d{3}\b/m, 8],
    [/^(?:Content-Type|Authorization|Accept|User-Agent|Host|Cache-Control):\s*\S/im, 5],
  ],
  graphql: [
    [/^\s*(?:query|mutation|subscription)\s+\w*\s*(?:\([^)]*\))?\s*\{/m, 7],
    [/^\s*(?:type|input|interface|enum|union|scalar)\s+\w+[^\n{]*\{/m, 5],
    [/^\s*\w+(?:\([^)]*\))?\s*:\s*\[?\w+!?\]?!?\s*$/m, 3, 2],
    [/^\s*(?:schema|fragment)\s+/m, 5],
    [/@(?:deprecated|include|skip|key)\b/, 3],
  ],
  proto: [
    [/^\s*syntax\s*=\s*"proto[23]"\s*;/m, 8],
    [/^\s*message\s+\w+\s*\{/m, 6],
    [/^\s*(?:repeated|optional|required)?\s*[\w.]+\s+\w+\s*=\s*\d+\s*;/m, 5],
    [/^\s*service\s+\w+\s*\{|^\s*rpc\s+\w+\s*\(/m, 6],
    [/^\s*package\s+[\w.]+\s*;/m, 2],
  ],
  kotlin: [
    [/\bfun\s+\w+\s*\(/, 5],
    [/\b(?:val|var)\s+\w+[ \t]*:[ \t]*\w+/, 4],
    [/\bdata\s+class\s+\w+\s*\(/, 8],
    [/\bcompanion\s+object\b|\bobject\s+\w+\s*\{/, 6],
    [/\?\.\w+|\?:\s|\!\!\./, 4],
    [/\bprintln\s*\(/, 2],
    [/\bsuspend\s+fun\b|\bcoroutineScope\b|\blaunch\s*\{/, 6],
    [/\bwhen\s*(?:\(\w+\))?\s*\{/, 4],
  ],
  swift: [
    [/\bimport\s+(?:Foundation|UIKit|SwiftUI|Combine|XCTest)\b/, 8],
    [/\b(?:guard|if)\s+let\s+\w+\s*=/, 7],
    [/\bfunc\s+\w+\s*(?:<[^>]*>)?\s*\([^)]*\)\s*(?:->|\{|throws)/, 4],
    [/@(?:State|Binding|Published|IBOutlet|IBAction|objc|escaping|main)\b/, 6],
    [/\bstruct\s+\w+[ \t]*:[ \t]*(?:View|Codable|Identifiable|Equatable)\b/, 7],
    [/\bvar\s+\w+[ \t]*:[ \t]*\w+(?:\?|!)?[ \t]*(?:=|\{)/, 3],
    [/\bprint\s*\(/, 1],
    [/\bself\.\w+|\?\?\s/, 1],
  ],
  scala: [
    [/\bobject\s+\w+(?:\s+extends\s+\w+)?\s*\{/, 6],
    [/\bcase\s+class\s+\w+\s*\(/, 8],
    [/\bdef\s+\w+[ \t]*(?:\[[^\]]*\])?[ \t]*\([^)]*\)[ \t]*:[ \t]*\w+/, 5],
    [/\bval\s+\w+\s*(?::\s*[\w[\], ]+)?\s*=/, 3],
    [/\b(?:implicit|sealed\s+trait|trait)\s+\w+/, 5],
    [/\bmatch\s*\{[\s\S]*?\bcase\b/, 3],
    [/=>\s*\w/, 1],
  ],
  dart: [
    [/\bimport\s+'(?:package|dart):[^']+'\s*;/, 8],
    [/\bWidget\s+build\s*\(\s*BuildContext\b/, 9],
    [/@override\b/, 5],
    [/\bfinal\s+\w+(?:<[^>]*>)?\s+\w+\s*[=;]/, 3],
    [/\bvoid\s+main\s*\(\s*\)\s*(?:async\s*)?\{/, 4],
    [/\bStatelessWidget\b|\bStatefulWidget\b|\bsetState\s*\(/, 8],
  ],
  elixir: [
    [/^\s*defmodule\s+[\w.]+\s+do\b/m, 9],
    [/^\s*def(?:p|macro)?\s+\w+[?!]?(?:\([^)]*\))?\s+do\b/m, 7],
    [/\|>/, 5],
    [/^\s*(?:use|import|alias|require)\s+[A-Z][\w.]*/m, 4],
    [/%\{[^}]*\}|:\w+\s*=>|\bIO\.puts\b/, 4],
    [/^\s*end\s*$/m, 1],
  ],
  erlang: [
    [/^-module\s*\(\w+\)\s*\./m, 9],
    [/^-(?:export|import|behaviour|spec|record)\s*[(\[]/m, 8],
    [/\bio:format\s*\(/, 7],
    [/^\w+\s*\([^)]*\)\s*->/m, 4],
    [/\bfun\s*\(|\bcase\b[\s\S]*?\bof\b|\bend\.\s*$/m, 2],
  ],
  haskell: [
    [/^\w+\s*::\s*[\w\s(),[\]>-]*->/m, 8],
    [/^module\s+[\w.]+\s+where\b/m, 9],
    [/^import\s+(?:qualified\s+)?(?:Data|Control|System|Text)\.[\w.]+/m, 7],
    [/\bdata\s+\w+(?:\s+\w+)*\s*=\s*\w+/, 4],
    [/\bwhere\b|\blet\b[^\n]*\bin\b|\bdo\b\s*$/m, 2],
    [/<-|>>=|\$\s|<\$>|\.\s*\$/, 2],
  ],
  lua: [
    [/\blocal\s+(?:function\s+)?\w+/, 7],
    [/\bfunction\s+\w+[.:]?\w*\s*\([^)]*\)/, 3],
    [/\b(?:then|elseif)\b/, 5],
    [/^\s*end\s*$/m, 2],
    [/\bnil\b|\bipairs\s*\(|\bpairs\s*\(|\btostring\s*\(/, 4],
    [/^\s*--(?!\[)/m, 3],
    [/\.\.\s|#\w+\b/, 2],
  ],
  perl: [
    [/^\s*use\s+(?:strict|warnings|utf8|POSIX|Data::Dumper)\s*;/m, 9],
    [/\bmy\s+[$@%]\w+/, 8],
    [/^\s*sub\s+\w+\s*\{/m, 5],
    [/=~\s*(?:[ms]|tr)?[/{|!]/, 6],
    [/\$_\b|@ARGV\b|\bqw\s*[(/]/, 6],
    [/\bprint\s+(?:STDERR\s+)?["'$]/, 2],
  ],
  r: [
    [/<-\s*(?:\w|function|c\(|list\()/, 6],
    [/\blibrary\s*\(\s*\w+\s*\)|\brequire\s*\(\s*\w+\s*\)/, 7],
    [/%>%|\|>\s/, 6],
    [/\b(?:data\.frame|ggplot|tibble|mutate|filter|summarise|select)\s*\(/, 7],
    [/\bc\s*\(\s*[\d"']/, 3],
    [/\b(?:TRUE|FALSE|NULL|NA|NaN)\b/, 3],
    [/^\s*#[^\n]*$/m, 1],
  ],
  matlab: [
    [/^\s*function\s+(?:\[[^\]]*\]|\w+)\s*=\s*\w+\s*\(/m, 9],
    [/\b(?:disp|fprintf|zeros|ones|linspace|numel|size)\s*\(/, 6],
    [/^\s*%[^\n]*$/m, 3],
    [/\.\*|\.\/|\.\^|'\s*;/, 4],
    [/^\s*end\s*$/m, 1],
  ],
  julia: [
    [/^\s*(?:function|macro)\s+\w+[!(]/m, 3],
    [/\bprintln\s*\(/, 4],
    [/::\w+(?:\{[^}]*\})?/, 4],
    [/^\s*using\s+\w+(?:,\s*\w+)*\s*$/m, 4],
    [/\bmutable\s+struct\b|\bstruct\s+\w+\b[\s\S]{0,200}?\bend\b/, 5],
    [/\bend\s*$/m, 1],
    [/\.\w+\.\(|\bdo\s+\w+\s*$/m, 3],
  ],
  'objective-c': [
    [/^\s*#import\s+[<"]/m, 8],
    [/@(?:interface|implementation|property|end|synthesize|selector)\b/, 9],
    [/\bNS(?:String|Array|Dictionary|Log|Object|Integer|Number)\b/, 8],
    [/\[\s*\w+\s+\w+(?::\s*\S+)?\s*\]/, 4],
    [/\bnil\b|\bBOOL\b|\bYES\b|\bNO\b/, 2],
  ],
  clojure: [
    [/^\s*\(ns\s+[\w.-]+/m, 9],
    [/\(def(?:n|macro|record|protocol)?-?\s+\w/, 8],
    [/\(let\s*\[|\(fn\s*\[|#\(/, 6],
    [/:\w[\w/-]*\s|\{:\w/, 3],
    [/\(require\s*'?\[|\(println\b/, 5],
  ],
  groovy: [
    [/^\s*(?:implementation|api|testImplementation|classpath)\s+['"]/m, 8],
    [/\b(?:task|plugins|dependencies|repositories|android)\s*\{/, 7],
    [/\bdef\s+\w+\s*=/, 4],
    [/\bprintln\s+["'$]/, 4],
    [/\$\{?\w+\}?["']|\bit\.\w+/, 2],
    [/@Grab\b|\bmavenCentral\s*\(\s*\)/, 7],
  ],
  fsharp: [
    [/^\s*open\s+(?:System|Microsoft|FSharp)[\w.]*/m, 8],
    [/\blet\s+(?:rec\s+|mutable\s+)?\w+\s*(?:\([^)]*\))?\s*=/, 4],
    [/\|>\s*\w/, 5],
    [/^\s*\|\s*\w+(?:\s+\w+)*\s*->/m, 5],
    [/\btype\s+\w+\s*=\s*$/m, 3],
    [/\bmatch\s+\w+\s+with\b/, 5],
  ],
  vb: [
    [/\bDim\s+\w+\s+As\s+\w+/i, 8],
    [/\bEnd\s+(?:Sub|Function|If|Class|Module|Namespace|While)\b/i, 7],
    [/^\s*(?:Public|Private|Protected)?\s*(?:Sub|Function)\s+\w+\s*\(/im, 5],
    [/\bImports\s+System\b/i, 7],
    [/^\s*'[^\n]*$/m, 2],
  ],
  bat: [
    [/^\s*@echo\s+off\b/im, 9],
    [/%(?:~?\w+|%\w+%)%?/, 4],
    [/^\s*(?:set|goto|call|if\s+exist|if\s+errorlevel|pause|rem)\b/im, 4],
    [/^\s*:\w+\s*$/m, 4],
  ],
  asm: [
    [/^\s*section\s+\.(?:text|data|bss|rodata)\b/im, 9],
    [/^\s*global\s+_?(?:start|main)\b/im, 8],
    [/^\s*(?:mov|push|pop|jmp|call|ret|add|sub|xor|cmp|lea|inc|dec|test|int)\s+[\w%$[\]]/im, 5, 2],
    [/^\s*\w+:\s*$/m, 2],
    [/\b(?:eax|ebx|ecx|edx|rax|rbx|rsp|rbp|rdi|rsi)\b/, 6],
  ],
  solidity: [
    [/^\s*pragma\s+solidity\b/m, 9],
    [/\bcontract\s+\w+(?:\s+is\s+[\w, ]+)?\s*\{/, 8],
    [/\bmsg\.(?:sender|value)\b|\bblock\.timestamp\b/, 9],
    [/\bfunction\s+\w+\s*\([^)]*\)\s*(?:public|external|internal|private)\b/, 8],
    [/\b(?:uint256|address|mapping\s*\(|payable|emit\s+\w+)\b/, 6],
  ],
  terraform: [
    [/^\s*(?:resource|data)\s+"[\w-]+"\s+"[\w-]+"\s*\{/m, 9],
    [/^\s*(?:provider|variable|module|output|locals|terraform)\s+(?:"[\w-]+"\s*)?\{/m, 7],
    [/\bvar\.\w+|\blocal\.\w+|\bdata\.\w+\.\w+/, 6],
    [/=\s*"\$\{[^}]*\}"/, 4],
  ],
  gherkin: [
    [/^\s*(?:Feature|Scenario|Scenario Outline|Background|Examples)\s*:/m, 8],
    [/^\s*(?:Given|When|Then|And|But)\s+\S/m, 6, 2],
  ],
  wasm: [
    [/^\s*\(module\b/m, 9],
    [/\(func\s+\$|\(param\s+|\(result\s+|\(export\s+"/, 8],
    [/\b(?:i32|i64|f32|f64)\.(?:const|add|load|store)\b/, 8],
  ],
  prolog: [
    [/^\s*[a-z]\w*\([^)]*\)\s*:-/m, 8],
    [/^\s*:-\s*(?:initialization|dynamic|module|use_module)\b/m, 9],
    [/\?-\s*\w/, 6],
  ],
  cmake: [
    [/^\s*cmake_minimum_required\s*\(/im, 9],
    [/^\s*(?:project|add_executable|add_library|target_link_libraries|find_package|set)\s*\(/im, 6],
    [/\$\{[A-Z_]+\}/, 3],
  ],
  ocaml: [
    [/^\s*let\s+(?:rec\s+)?\w+\s+[\w\s]*=/m, 4],
    [/\bmatch\s+\w+\s+with\b/, 4],
    [/^\s*(?:open|module)\s+[A-Z]\w*/m, 4],
    [/;;\s*$/m, 7],
    [/\bList\.(?:map|iter|fold_left)\b|\bprint_endline\b/, 7],
  ],
  elm: [
    [/^module\s+\w+(?:\.\w+)*\s+exposing\b/m, 9],
    [/^import\s+\w+(?:\.\w+)*(?:\s+exposing\b)?/m, 4],
    [/\bHtml\s+msg\b|\bview\s*:\s*Model\s*->/, 8],
    [/->\s*\w+\s*$/m, 2],
  ],
  crystal: [
    [/^\s*require\s+"[\w/]+"/m, 4],
    [/^[ \t]*def\s+\w+[?!]?(?:\([^)]*\))?[ \t]*:[ \t]*\w+[ \t]*$/m, 7],
    [/\bputs\b|\bend\b/, 1],
    [/\.new\b|\bStruct\b|\bproperty\s+\w+\s*:/, 4],
  ],
  nim: [
    [/^\s*proc\s+\w+\*?\s*\([^)]*\)\s*:\s*\w+\s*=/m, 9],
    [/^\s*(?:import|from)\s+\w+/m, 2],
    [/\becho\s+["'$]/, 5],
    [/\bvar\s+\w+\s*[:=]|\blet\s+\w+\s*=/, 2],
  ],
  zig: [
    [/\bconst\s+std\s*=\s*@import\s*\(\s*"std"\s*\)/, 9],
    [/\bpub\s+fn\s+\w+\s*\(/, 6],
    [/\b(?:try|comptime|defer|errdefer|anytype)\b/, 5],
    [/\bstd\.debug\.print\b/, 8],
  ],
  vue: [
    [/<template>[\s\S]*<\/template>/, 8],
    [/<script(?:\s+setup)?(?:\s+lang="[^"]*")?>/, 4],
    [/\b(?:v-if|v-for|v-model|v-bind|v-on|@click|:class)\b/, 7],
    [/\bdefineProps\b|\bdefineEmits\b|\bexport\s+default\s*\{[\s\S]*\bdata\s*\(\)/, 6],
  ],
  svelte: [
    [/\{#(?:if|each|await|key)\b/, 9],
    [/\bexport\s+let\s+\w+/, 6],
    [/\bon:(?:click|change|submit)\b|\bbind:\w+/, 8],
    [/\$:\s*\w+/, 6],
  ],
};

/* -- scoring --------------------------------------------------------------- */

/**
 * Three bars, because the three situations a guess is used in are not equally
 * forgiving. Filling in a fence that said nothing costs nothing if it is wrong;
 * contradicting one that named a language is a visible mistake on the page.
 *
 *   offered  a guess is worth returning at all
 *   strong   worth acting on where the author wrote `text` and may have meant it
 *   sure     worth contradicting a fence that named a language outright
 */
const OFFERED_SCORE = 7;
const OFFERED_LEAD = 4;
/** …and clear of the runner-up proportionally, not only absolutely. */
const OFFERED_RATIO = 1.5;

const STRONG_SCORE = 10;
const STRONG_LEAD = 6;

const SURE_SCORE = 14;
const SURE_LEAD = 8;

/** Below this there is nothing to go on, whatever the patterns say. */
const MIN_LENGTH = 12;

/** Only the head of a long block is read; the evidence is always at the top. */
const SCAN_LIMIT = 8000;

const cache = new Map();
const CACHE_LIMIT = 240;

function remember(key, value) {
  if (cache.size >= CACHE_LIMIT) cache.delete(cache.keys().next().value);
  cache.set(key, value);
  return value;
}

/**
 * How often a pattern matches, counted no further than `wanted`. The loop
 * advances `lastIndex` itself rather than trusting it: a pattern that can match
 * nothing at all would otherwise sit on the same position for ever.
 */
function occurrences(pattern, text, wanted) {
  const counter = new RegExp(pattern.source, pattern.flags.includes('g') ? pattern.flags : `${pattern.flags}g`);
  let seen = 0;
  let at = 0;
  while (seen < wanted) {
    counter.lastIndex = at;
    const match = counter.exec(text);
    if (!match) break;
    seen += 1;
    at = match.index + Math.max(1, match[0].length);
  }
  return seen;
}

/** Every language's score for this text, highest first. */
function rank(text) {
  const scores = [];
  for (const [id, patterns] of Object.entries(SIGNATURES)) {
    let score = 0;
    for (const [pattern, weight, atLeast] of patterns) {
      if (atLeast === undefined) {
        if (pattern.test(text)) score += weight;
      } else if (occurrences(pattern, text, atLeast) >= atLeast) {
        score += weight;
      }
    }
    if (score > 0) scores.push({ id, score });
  }
  return scores.sort((a, b) => b.score - a.score);
}

/**
 * JSON is the one language worth deciding with a parser rather than with
 * patterns, because the parser is exact and already in the browser. Only a
 * document — an object or an array — counts: a bare `42` or `"hello"` is valid
 * JSON and is not what anybody meant by it.
 */
function looksLikeJson(text) {
  const trimmed = text.trim();
  if (trimmed.length > 200000) return false;             // not worth the parse
  if (!/^[{[]/.test(trimmed) || !/[}\]]$/.test(trimmed)) return false;
  try {
    const value = JSON.parse(trimmed);
    return value !== null && typeof value === 'object';
  } catch {
    return false;
  }
}

/**
 * What this block appears to be written in.
 *
 * Returns `{ id, score, lead, strong, sure }` or `null`; `id` is always a
 * grammar id `core/languages.js` knows.
 */
export function detectLanguage(code) {
  const text = String(code ?? '');
  const hit = cache.get(text);
  if (hit !== undefined) return hit;

  const head = text.length > SCAN_LIMIT ? text.slice(0, SCAN_LIMIT) : text;
  if (head.trim().length < MIN_LENGTH) return remember(text, null);

  const certain = (id) => remember(text, { id, score: 100, lead: 100, strong: true, sure: true });
  for (const [pattern, id] of DECISIVE) {
    if (pattern.test(head)) return certain(id);
  }
  if (looksLikeJson(text)) return certain('json');

  const scores = rank(head);
  const best = scores[0];
  if (!best) return remember(text, null);

  const runnerUp = scores[1]?.score ?? 0;
  const lead = best.score - runnerUp;
  if (best.score < OFFERED_SCORE) return remember(text, null);
  if (lead < OFFERED_LEAD || best.score < runnerUp * OFFERED_RATIO) return remember(text, null);

  return remember(text, {
    id: best.id,
    score: best.score,
    lead,
    strong: best.score >= STRONG_SCORE && lead >= STRONG_LEAD,
    sure: best.score >= SURE_SCORE && lead >= SURE_LEAD,
  });
}

/**
 * The language a block should actually be highlighted in.
 *
 * Three situations, and each is allowed to be contradicted by a different
 * weight of evidence:
 *
 * - **The fence said nothing.** Any guess worth offering is taken. There is
 *   nothing to lose: the alternative is an unhighlighted block.
 * - **The fence said `text`.** The author may have meant it, so a strong guess
 *   is required — enough that a page of Python fenced as `text` is highlighted
 *   while a block of console output stays exactly as it is.
 * - **The fence named a language.** It is believed, unless the guess is one of
 *   the certain ones *and* the named language is one this file can argue about
 *   at all. Nothing here knows how to assess ABAP, so nothing here may overrule
 *   ```abap.
 *
 * Returns `{ id, detected, replaced }`: `detected` marks a guess rather than a
 * reading, `replaced` carries the id the fence asked for when it was overruled.
 */
export function chooseLanguage(code, declared, { plain = false } = {}) {
  const kept = { id: declared, detected: false, replaced: null };
  const guess = detectLanguage(code);
  if (!guess || guess.id === declared) return kept;

  if (!declared) {
    if (plain && !guess.strong) return kept;
    return { id: guess.id, detected: true, replaced: null };
  }

  if (!guess.sure || !Object.hasOwn(SIGNATURES, declared)) return kept;
  return { id: guess.id, detected: true, replaced: declared };
}
