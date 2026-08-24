<?php
/**
 * CourseForge - the scheduler, over HTTP.
 *
 * Shared hosts almost never give you a real crontab; what they give you is a box
 * in a control panel where you paste a URL and choose "every minute". So this is
 * a URL. Point the host's scheduler at:
 *
 *     https://your-install/cron.php?token=<app.cron_token>
 *
 * and background runs start being written whether or not anyone has the app
 * open. The token comes from data/config.json and is compared in constant time;
 * without one configured the endpoint refuses everything, so an install that has
 * not set it up is not left with an open door.
 *
 * On a host that does give you a crontab, tools/cron.php does the same job
 * without going through the web server at all.
 */
declare(strict_types=1);

// Run from a shell this file answers "Not found." and looks broken, because
// there is no query string to carry the token. Say where the tick actually
// lives instead - on STDERR and with a failing status, because the likely
// reader is a crontab that has been pointed at the wrong file, and a clean exit
// would let it report success every minute while nothing ever ticks.
if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "This is the HTTP endpoint. On the command line use tools/cron.php instead.\n");
    exit(1);
}

require __DIR__ . '/src/bootstrap.php';

use CourseForge\Support\Cron;
use CourseForge\Support\Response;
use CourseForge\Support\Runtime;

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Accept the token in the query string or in a header. The query string is what
// a control-panel scheduler can manage; the header is for anything better.
// Trimmed on both paths: a token pasted into a control panel arrives with a
// trailing space often enough to be worth allowing for.
$token = is_string($_GET['token'] ?? null) ? trim($_GET['token']) : '';
if ($token === '') {
    $header = $_SERVER['HTTP_X_CRON_TOKEN'] ?? null;
    $token = is_string($header) ? trim($header) : '';
}

if (!Cron::tokenValid($token)) {
    // Deliberately terse, and the same answer whether the token is wrong or was
    // never configured at all.
    Response::send(['ok' => false, 'error' => 'Not found.'], 404);
}

try {
    Response::send(Cron::tick());
} catch (Throwable $e) {
    Runtime::log('cron', $e);
    Response::send(['ok' => false, 'error' => 'The scheduler failed. See the server log.'], 500);
}
