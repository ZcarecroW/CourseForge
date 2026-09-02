<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * The PHP outline parser still agrees with tools/outline-fixtures.json.
 *
 * That file is what the browser parser is tested against (tools/outline-test.mjs),
 * so a change to Structure::parse() that is not written into it would leave the
 * Structure tab previewing an apply the server no longer performs. This fails
 * first; `php tools/outline-fixtures.php` rewrites the file on purpose; then the
 * Node side fails until the change is ported.
 */

use CourseForge\Domain\Structure;

require_once CF_ROOT . '/tools/outline-fixtures.php';

test('every fixture the browser parser is checked against is still what the PHP parser says', static function (): void {
    $file = CF_ROOT . '/tools/outline-fixtures.json';
    ok(is_file($file), 'tools/outline-fixtures.json exists - run php tools/outline-fixtures.php');
    $stored = json_decode((string)file_get_contents($file), true);
    ok(is_array($stored) && $stored !== [], 'and holds fixtures');

    $byName = [];
    foreach ($stored as $entry) {
        $byName[(string)$entry['name']] = $entry;
    }
    foreach (outlineFixtures() as $name => $markdown) {
        ok(isset($byName[$name]), 'the file knows the fixture "' . $name . '" - run php tools/outline-fixtures.php');
        same($markdown, (string)$byName[$name]['markdown'], 'the input of "' . $name . '" is what the file holds');
        same($byName[$name]['expected'], Structure::parse($markdown), 'and the parser still reads "' . $name . '" the same way');
    }
    same(count(outlineFixtures()), count($stored), 'and the file holds nothing else');
});
