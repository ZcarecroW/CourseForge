<?php
declare(strict_types=1);

/**
 * CourseForge - stamps the release version onto every asset URL in index.html.
 *
 *     php tools/assets.php          rewrite index.html for the current version
 *     php tools/assets.php --check  say whether it would change anything (exit 1 if so)
 *
 * Run it after bumping CF_VERSION, before tagging. `tests/assets.test.php`
 * fails if it was forgotten.
 *
 * Why this exists
 * ---------------
 * There is no build step, so nothing renames a file when its contents change:
 * `assets/vendor/vue.esm-browser.prod.js` is that path in every release, and
 * 4.4.0 changed what is inside it. `assets/vendor/.htaccess` meanwhile promises
 * `Cache-Control: immutable, max-age=31536000`, which tells a browser it need
 * never ask again - and `immutable` is honoured through a reload AND through a
 * hard reload, which is why Ctrl+F5 does not rescue an installation that has
 * already cached the old files.
 *
 * The upgrade to 4.4.0 therefore reached people as new application code running
 * against last release's Vue, marked and Shiki: some screens new, some screens
 * old, and errors where the two met.
 *
 * A cache entry can only be bypassed by asking for a different URL, so every
 * asset URL now carries `?v=<version>`. The promise in that .htaccess becomes
 * true rather than merely convenient - `App.js?v=4.4.1` really is immutable -
 * and an upgrade is a clean break rather than a negotiation with whatever each
 * visitor happens to be holding.
 *
 * Why every module is listed rather than reached through the `@/` prefix
 * ---------------------------------------------------------------------
 * A query string is not inherited. `import './actions.js'` from a module loaded
 * as `ContentTab.js?v=4.4.1` resolves to `actions.js` with no query at all, and
 * an import-map *prefix* mapping cannot carry one either - only the remainder of
 * the path is appended to it. So the map names every module explicitly, an exact
 * key beats the prefix, and the cross-module imports were changed to `@/…` so
 * that they all go through it. The bare `@/` prefix stays at the end as a
 * fallback: a module added and not yet stamped still loads, just uncached.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$check = in_array('--check', array_slice($argv, 1), true);

/* ------------------------------------------------------------- the version */

$bootstrap = (string)file_get_contents($root . '/src/bootstrap.php');
if (preg_match("/const\s+CF_VERSION\s*=\s*'([^']+)'/", $bootstrap, $m) !== 1) {
    fwrite(STDERR, "Could not read CF_VERSION out of src/bootstrap.php.\n");
    exit(2);
}
$version = $m[1];

/* --------------------------------------------------------- the module list */

/** Every application module, as the `@/` specifier that reaches it. */
function modules(string $dir, string $prefix = ''): array
{
    $out = [];
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            $out = array_merge($out, modules($path, $prefix . $entry . '/'));
        } elseif (str_ends_with($entry, '.js')) {
            $out[] = $prefix . $entry;
        }
    }
    sort($out);
    return $out;
}

$modules = modules($root . '/assets/js');

/* ------------------------------------------------------------- index.html */

$file = $root . '/index.html';
$html = (string)file_get_contents($file);
$before = $html;

/** Adds or replaces `?v=` on one URL, leaving any other query alone. */
$stamp = static function (string $url) use ($version): string {
    $url = preg_replace('/[?&]v=[^&"\']*/', '', $url) ?? $url;
    return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . rawurlencode($version);
};

/* the import map: keep whatever vendor specifiers are declared, restamp them,
   then state every application module explicitly */
if (preg_match('#(<script type="importmap">\s*)(\{.*?\})(\s*</script>)#s', $html, $m) !== 1) {
    fwrite(STDERR, "Could not find the import map in index.html.\n");
    exit(2);
}
$map = json_decode($m[2], true);
if (!is_array($map) || !isset($map['imports']) || !is_array($map['imports'])) {
    fwrite(STDERR, "The import map in index.html is not the shape this tool expects.\n");
    exit(2);
}

$imports = [];
foreach ($map['imports'] as $specifier => $target) {
    // The prefix mapping and the generated module entries are rebuilt below.
    if ($specifier === '@/' || str_starts_with($specifier, '@/')) {
        continue;
    }
    $imports[$specifier] = $stamp((string)$target);
}
foreach ($modules as $module) {
    $imports['@/' . $module] = $stamp('./assets/js/' . $module);
}
// Last, and deliberately unstamped: anything this tool has not been run for
// still resolves rather than 404-ing.
$imports['@/'] = './assets/js/';

$json = json_encode(['imports' => $imports], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$json = preg_replace('/^/m', '    ', (string)$json) ?? (string)$json;
$html = str_replace($m[0], $m[1] . ltrim($json) . $m[3], $html);

/* the stylesheets, the preloads and the entry point */
$html = preg_replace_callback(
    '#(<link rel="stylesheet" href=")([^"]+)(")#',
    static fn(array $h): string => $h[1] . $stamp($h[2]) . $h[3],
    $html
) ?? $html;

$html = preg_replace_callback(
    '#(<link rel="modulepreload" href=")([^"]+)(")#',
    static fn(array $h): string => $h[1] . $stamp($h[2]) . $h[3],
    $html
) ?? $html;

$html = preg_replace_callback(
    '#(<script type="module" src=")([^"]+)(")#',
    static fn(array $h): string => $h[1] . $stamp($h[2]) . $h[3],
    $html
) ?? $html;

/* the version, for the three libraries that are reached by URL rather than by
   specifier - Shiki's grammars, Mermaid and MathJax - which the import map
   cannot stamp because their names are decided at runtime */
$meta = '<meta name="cf-assets" content="' . htmlspecialchars($version, ENT_QUOTES) . '">';
if (preg_match('#<meta name="cf-assets" content="[^"]*">#', $html) === 1) {
    $html = (string)preg_replace('#<meta name="cf-assets" content="[^"]*">#', $meta, $html);
} else {
    $html = (string)preg_replace('#(<link rel="icon"[^>]*>)#', "$1\n  " . $meta, $html, 1);
}

/* ------------------------------------------------------------------ finish */

if ($html === $before) {
    echo "index.html is already stamped for {$version}.\n";
    exit(0);
}

if ($check) {
    fwrite(STDERR, "index.html is not stamped for {$version}. Run: php tools/assets.php\n");
    exit(1);
}

file_put_contents($file, $html);
echo 'index.html stamped for ' . $version . ' (' . count($modules) . " modules, "
    . (count($imports) - count($modules) - 1) . " vendor specifiers).\n";
