<?php
declare(strict_types=1);

/**
 * CourseForge - the scheduler, on the command line.
 *
 *   * * * * * php /path/to/courseforge/tools/cron.php --quiet
 *
 * Identical work to cron.php over HTTP, minus the web server and minus the
 * token: anyone who can run this already has the files, so there is nothing a
 * shared secret would add here. Use whichever your host gives you, not both.
 *
 * One tick is the default, which is what a crontab wants. --loop is for the two
 * cases a crontab cannot cover: a development machine with no scheduler at all,
 * and a control panel that refuses to go below five minutes - a batch polled
 * every five minutes is written home five minutes late.
 *
 *   --once          one tick, then exit. The default; say it if you like.
 *   --loop[=60]     keep ticking, every N seconds, until interrupted.
 *   --quiet         print nothing about a tick that had nothing to do. A tick
 *                   that wrote a page, hit an error, installed an update or
 *                   could not check for one still prints.
 *   --json          one JSON object per tick, one per line.
 *   --help
 */

if (PHP_SAPI !== 'cli') {
    exit("This tool is for the command line only.\n");
}

require __DIR__ . '/../src/bootstrap.php';

use CourseForge\Support\Cron;

/**
 * The notes the update half of a tick leaves on an ordinary, quiet day.
 *
 * Listed the quiet way round on purpose. Updater::scheduled() reports in prose
 * and never writes to the tick's `errors` array, so a note this file has never
 * seen is printed rather than swallowed: a new kind of trouble should arrive as
 * noise, which somebody fixes, rather than as silence, which nobody notices.
 */
const ROUTINE_UPDATE_NOTES = [
    'updates are switched off',
    'automatic installation is off',
    'nothing newer than',
    'the unattended slot for',
    'due at',
    'deferred:',
];

/**
 * What the update half of a tick has to say, and whether it is bad news.
 *
 * Two things have to get past --quiet, and neither of them reaches `errors`: a
 * check that could not ask GitHub, which means this installation has stopped
 * hearing about releases, and a completed unattended update, which means the
 * files this very process is running from have just been replaced. Both belong
 * in the mail a crontab sends; the first also belongs in the exit code, because
 * it goes on failing until somebody looks at it.
 *
 * @param array<string,mixed> $report
 * @return array{lines:array<int,string>,trouble:bool}
 */
function updateNews(array $report): array
{
    $update = is_array($report['update'] ?? null) ? $report['update'] : [];
    if ($update === []) {
        return ['lines' => [], 'trouble' => false];
    }

    if (($update['installed'] ?? false) === true) {
        $latest = is_string($update['latest'] ?? null) ? $update['latest'] : '';
        return [
            'lines' => ['update installed: ' . ($latest !== '' ? $latest : 'a new release')],
            'trouble' => false,
        ];
    }

    $note = is_string($update['note'] ?? null) ? $update['note'] : '';
    if ($note === '') {
        return ['lines' => [], 'trouble' => false];
    }

    foreach (ROUTINE_UPDATE_NOTES as $routine) {
        if (str_starts_with($note, $routine)) {
            return ['lines' => [], 'trouble' => false];
        }
    }

    return ['lines' => ['update: ' . $note], 'trouble' => true];
}

/**
 * One tick, printed.
 *
 * The uneventful tick is the common one - most minutes have nothing queued -
 * and a crontab entry that prints on every run mails the administrator on every
 * run, which is how people end up deleting the crontab entry. So --quiet means
 * "say nothing when nothing happened" rather than "say nothing": a tick that
 * wrote a page, hit an error or has something to report about updates is
 * printed whatever the flag says.
 *
 * @param array<string,mixed> $report
 * @param array<int,string> $updateLines
 */
function announce(array $report, array $updateLines, bool $quiet, bool $json): void
{
    $didSomething = $report['pages_written'] > 0
        || $report['pages_failed'] > 0
        || $report['batches_polled'] > 0
        || $report['released'] > 0
        || $updateLines !== [];

    if ($quiet && !$didSomething && $report['errors'] === []) {
        return;
    }

    if ($json) {
        // One line per tick rather than a pretty document: the output of a
        // scheduler is a log, and a log wants to be greppable.
        echo json_encode(
            ['at' => time()] + $report,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        ), "\n";
        return;
    }

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

    foreach ($updateLines as $line) {
        echo '  ' . $line . "\n";
    }

    foreach ($report['errors'] as $error) {
        fwrite(STDERR, '  ' . $error . "\n");
    }
}

$options = getopt('', ['once', 'loop::', 'quiet', 'json', 'help']);

if (isset($options['help'])) {
    echo "Usage: php tools/cron.php [--once|--loop[=seconds]] [--quiet] [--json]\n\n"
        . "Writes background pages and collects finished batch runs.\n"
        . "Run it once a minute. Nothing happens if there is nothing to do.\n\n"
        . "  --once           one tick, then exit (the default)\n"
        . "  --loop[=60]      keep ticking every N seconds until interrupted\n"
        . "  --quiet          say nothing about a tick that did nothing. An update installed,\n"
        . "                   an update check that failed and any error still print\n"
        . "  --json           one JSON object per tick, one per line\n"
        . "  --help           this\n\n"
        . "Exit code 0 when the last tick was clean, 1 when it reported an error or could not\n"
        . "finish an update it had started.\n";
    exit(0);
}

$quiet = isset($options['quiet']);
$json = isset($options['json']);

// --once wins over --loop, so a crontab line that inherited a stray --loop from
// somebody's shell history still behaves like a crontab line.
$looping = array_key_exists('loop', $options) && !isset($options['once']);

// getopt hands back false for a long option given without its optional value,
// which is exactly what --loop on its own looks like.
$interval = 60;
if ($looping && is_string($options['loop']) && $options['loop'] !== '') {
    $interval = max(5, min(3600, (int)$options['loop']));
}

if ($looping) {
    // On STDERR so that --json on STDOUT stays a clean stream of objects.
    fwrite(STDERR, 'Ticking every ' . $interval . "s. Ctrl-C to stop.\n");
}

$clean = true;

do {
    $started = microtime(true);

    try {
        $report = Cron::tick();
        $news = updateNews($report);
        $clean = $report['errors'] === [] && !$news['trouble'];
        announce($report, $news['lines'], $quiet, $json);
    } catch (Throwable $e) {
        // A tick that throws is a bug rather than a busy provider: Cron::tick
        // catches its own trouble and reports it in the array. In a loop that
        // must not end the process, because the next minute may well work.
        fwrite(STDERR, 'The scheduler failed: ' . $e->getMessage() . "\n");
        $clean = false;
        if (!$looping) {
            exit(1);
        }
    }

    if (!$looping) {
        break;
    }

    // Measured from the start of the tick, so the period stays a period even
    // when a tick spends its whole budget writing pages.
    $rest = $interval - (microtime(true) - $started);
    if ($rest > 0) {
        usleep((int)round($rest * 1000000));
    }
} while (true);

exit($clean ? 0 : 1);
