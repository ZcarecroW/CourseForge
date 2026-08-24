<?php
declare(strict_types=1);

/**
 * CourseForge 3 - the scheduler, on the command line.
 *
 *   * * * * * php /path/to/courseforge/tools/cron.php --quiet
 *
 * Identical work to cron.php over HTTP, minus the web server and minus the
 * token: anyone who can run this already has the files. Use whichever your host
 * gives you.
 */

if (PHP_SAPI !== 'cli') {
    exit("This tool is for the command line only.\n");
}

require __DIR__ . '/../src/bootstrap.php';

use CourseForge\Support\Cron;

$options = getopt('', ['quiet', 'help']);

if (isset($options['help'])) {
    echo "Usage: php tools/cron.php [--quiet]\n\n"
        . "Writes background pages and collects finished batch runs.\n"
        . "Run it once a minute. Nothing happens if there is nothing to do.\n";
    exit(0);
}

$quiet = isset($options['quiet']);

try {
    $report = Cron::tick();
} catch (Throwable $e) {
    fwrite(STDERR, 'The scheduler failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$didSomething = $report['pages_written'] > 0
    || $report['pages_failed'] > 0
    || $report['batches_polled'] > 0
    || $report['released'] > 0;

if (!$quiet || $didSomething || $report['errors'] !== []) {
    printf(
        "%s  %d page(s) written, %d failed, %d batch(es) polled, %d released  [%ss%s]\n",
        date('Y-m-d H:i:s'),
        $report['pages_written'],
        $report['pages_failed'],
        $report['batches_polled'],
        $report['released'],
        $report['seconds'],
        $report['slot'] !== '' ? ', ' . $report['slot'] : ', no free slot'
    );
    foreach ($report['errors'] as $error) {
        fwrite(STDERR, '  ' . $error . "\n");
    }
}

exit($report['errors'] === [] ? 0 : 1);
