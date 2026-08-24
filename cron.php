<?php
/**
 * CourseForge 3 - the scheduler, over HTTP.
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

require __DIR__ . '/src/bootstrap.php';

use CourseForge\Support\Cron;
use CourseForge\Support\Response;
use CourseForge\Support\Runtime;

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Accept the token in the query string or in a header. The query string is what
// a control-panel scheduler can manage; the header is for anything better.
$token = (string)($_GET['token'] ?? '');
if ($token === '') {
    $header = (string)($_SERVER['HTTP_X_CRON_TOKEN'] ?? '');
    $token = trim($header);
}

if (!Cron::tokenValid($token)) {
    // Deliberately terse, and the same answer whether the token is wrong or
    // simply not configured.
    Response::send(['ok' => false, 'error' => 'Not found.'], 404);
}

try {
    Response::send(Cron::tick());
} catch (Throwable $e) {
    Runtime::log('cron', $e);
    Response::send(['ok' => false, 'error' => 'The scheduler failed. See the server log.'], 500);
}
