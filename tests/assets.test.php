<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * The release stamp on every asset URL.
 *
 * CourseForge has no build step, so nothing renames a file when its contents
 * change: `assets/vendor/vue.esm-browser.prod.js` is that path in every release.
 * The caching rules meanwhile promise `immutable, max-age=31536000`, and
 * `immutable` is honoured through a reload and a hard reload alike.
 *
 * 4.4.0 is what that combination costs. It reached people as new application
 * code running against the previous release's Vue, marked and Shiki - some
 * screens new, some old, errors where the two met - and no amount of Ctrl+F5
 * could clear it, because a browser holding an immutable entry does not ask.
 *
 * The stamp is what makes the promise true, so these tests are about the one
 * way it can silently stop being applied: somebody bumps CF_VERSION, tags a
 * release, and forgets to run `php tools/assets.php`. Then every URL still
 * carries the *previous* version and the upgrade is invisible all over again.
 */

/** index.html, read once. */
function assetsHtml(): string
{
    static $html = null;
    return $html ??= (string)file_get_contents(CF_ROOT . '/index.html');
}

/** The import map, decoded. @return array<string,string> */
function assetsImportMap(): array
{
    if (preg_match('#<script type="importmap">\s*(\{.*?\})\s*</script>#s', assetsHtml(), $m) !== 1) {
        return [];
    }
    $map = json_decode($m[1], true);
    return is_array($map['imports'] ?? null) ? $map['imports'] : [];
}

test('index.html is stamped for the version this release actually is', function (): void {
    ok(
        preg_match('#<meta name="cf-assets" content="([^"]*)">#', assetsHtml(), $m) === 1,
        'index.html declares the asset version'
    );
    same(CF_VERSION, $m[1], 'and it is the version in bootstrap.php');
});

test('every module the application can import carries the stamp', function (): void {
    $imports = assetsImportMap();
    ok($imports !== [], 'the import map parses');

    // Every .js under assets/js has an entry, so nothing falls through to the
    // bare `@/` prefix, which cannot carry a query string.
    $missing = [];
    $unstamped = [];
    $walk = static function (string $dir, string $prefix) use (&$walk, $imports, &$missing, &$unstamped): void {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $walk($path, $prefix . $entry . '/');
                continue;
            }
            if (!str_ends_with($entry, '.js')) {
                continue;
            }
            $key = '@/' . $prefix . $entry;
            if (!isset($imports[$key])) {
                $missing[] = $key;
            } elseif (!str_contains((string)$imports[$key], '?v=' . CF_VERSION)) {
                $unstamped[] = $key;
            }
        }
    };
    $walk(CF_ROOT . '/assets/js', '');

    same([], $missing, 'no module is missing from the import map');
    same([], $unstamped, 'and every one of them is stamped for this version');
});

test('the vendored libraries are stamped too', function (): void {
    $imports = assetsImportMap();
    $bare = [];
    foreach ($imports as $specifier => $target) {
        // The trailing `@/` prefix is the deliberate fallback and carries no
        // stamp: a module added but not yet stamped must still load.
        if ($specifier === '@/') {
            continue;
        }
        if (!str_contains((string)$target, '?v=' . CF_VERSION)) {
            $bare[] = $specifier;
        }
    }
    same([], $bare, 'every specifier in the map resolves to a stamped URL');
});

test('the stylesheets and the entry point are stamped', function (): void {
    $html = assetsHtml();

    $unstamped = [];
    foreach (['#<link rel="stylesheet" href="([^"]+)"#', '#<script type="module" src="([^"]+)"#',
              '#<link rel="modulepreload" href="([^"]+)"#'] as $pattern) {
        preg_match_all($pattern, $html, $found);
        foreach ($found[1] ?? [] as $url) {
            if (!str_contains($url, '?v=' . CF_VERSION)) {
                $unstamped[] = $url;
            }
        }
    }
    same([], $unstamped, 'no stylesheet, preload or entry script is left unstamped');
});

test('nothing imports another module by a relative path', function (): void {
    // A query string is not inherited: `import './actions.js'` from a module
    // loaded as `ContentTab.js?v=4.4.1` resolves without any query at all, and
    // that file is then served from whatever the browser cached last year. Every
    // cross-module import therefore has to go through the import map.
    $offenders = [];
    $walk = static function (string $dir) use (&$walk, &$offenders): void {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $walk($path);
                continue;
            }
            if (!str_ends_with($entry, '.js')) {
                continue;
            }
            $src = (string)file_get_contents($path);
            if (preg_match("#(?:from\s+'|import\(\s*')\.\.?/#", $src) === 1) {
                $offenders[] = substr($path, strlen(CF_ROOT) + 1);
            }
        }
    };
    $walk(CF_ROOT . '/assets/js');

    same([], $offenders, 'every cross-module import goes through the import map');
});

test('tools/assets.php agrees that index.html is already correct', function (): void {
    // The belt to the braces above: run the generator in --check mode and let it
    // have the last word, so a shape these tests do not think about still fails.
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(CF_ROOT . '/tools/assets.php') . ' --check';
    exec($command . ' 2>&1', $output, $status);
    same(0, $status, 'php tools/assets.php --check is clean: ' . implode(' ', $output));
});
