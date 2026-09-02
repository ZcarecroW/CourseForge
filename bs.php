<?php
/**
 * CourseForge - the BookStackDev endpoint.
 *
 * A BookStack administrator pastes one line into Settings -> Customization ->
 * Custom HTML head:
 *
 *     <script src="https://your-install/bs.php?k=<key>" crossorigin="anonymous"></script>
 *
 * and this file answers with the loader of the BookStackDev profile the key
 * belongs to, its configuration written in front. The loader then asks this
 * same file for every stylesheet and module it needs (`&f=js/...`). Nothing is
 * served to a page that is not on an origin the profile allows - the browser
 * says where the page lives, and the answer for anywhere else is a refusal that
 * names the address - so the link is a link to one wiki, not a CDN.
 *
 * No session, no cookie, no CSRF: this is a public endpoint keyed on the
 * profile key, the way cron.php is keyed on its token.
 */
declare(strict_types=1);

if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "This is the HTTP endpoint BookStack loads a look from. It has no command-line use.\n");
    exit(1);
}

require __DIR__ . '/src/bootstrap.php';

use CourseForge\Domain\BookStackDev;
use CourseForge\Support\Runtime;

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

try {
    $response = BookStackDev::respond($_GET, $_SERVER);
} catch (Throwable $e) {
    Runtime::log('bookstackdev', $e);
    $response = [
        'status' => 500,
        'headers' => ['Content-Type' => 'text/javascript; charset=utf-8', 'Cache-Control' => 'no-store'],
        'body' => '/* CourseForge BookStackDev: the look could not be served. See the server log. */',
    ];
}

http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) {
    header($name . ': ' . $value);
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
    echo $response['body'];
}
