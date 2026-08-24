<?php
declare(strict_types=1);

/**
 * Installation check.
 *
 *   php tools/diagnose.php
 *   php tools/diagnose.php --json
 *
 * Verifies everything CourseForge needs before it can start: PHP version,
 * extensions, writable paths, the two configuration layers, the accounts and
 * the invite that creates the first one, the database, the scheduler and the
 * preconditions for updating in place.
 *
 * The checks themselves live in CourseForge\Support\Diagnostics, because the
 * administrator who most needs to read them usually has a browser and no shell.
 * This file is only the printer: the aligned columns and the exit code, and
 * --json for anything that would rather have the rows than the paragraph.
 *
 * Repairs nothing. When the data directory is missing or unwritable, the
 * sections that would have to open the database - and so create that directory
 * - are reported as unchecked instead of run.
 */

if (PHP_SAPI !== 'cli') {
    exit("This tool is for the command line only.\n");
}

require __DIR__ . '/../src/bootstrap.php';

use CourseForge\Support\Diagnostics;

$options = getopt('', ['json', 'help']);

if (isset($options['help'])) {
    echo "Usage: php tools/diagnose.php [--json]\n\n"
        . "Checks this installation and prints what it found.\n"
        . "  --json   the same report as structured data\n\n"
        . "Exit code 0 when nothing is broken, 1 when something is.\n";
    exit(0);
}

$report = Diagnostics::run();

if (isset($options['json'])) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit($report['summary']['problems'] === 0 ? 0 : 1);
}

/** The badge in the first column. Fixed width, so the labels line up. */
function badge(string $status): string
{
    return match ($status) {
        'fail' => 'FAIL',
        'warn' => 'WARN',
        default => ' OK ',
    };
}

echo "\nCourseForge " . $report['version'] . " - installation check\n";
echo str_repeat('-', 72) . "\n\n";

$first = true;
foreach ($report['sections'] as $section) {
    echo ($first ? '' : "\n") . $section['label'] . "\n";
    $first = false;

    foreach ($section['checks'] as $check) {
        printf("  %-9s %-34s %s\n", '[' . badge($check['status']) . ']', $check['label'], $check['detail']);

        // The hint is what to do about it, so it is only worth the line when
        // there is something to do. Indented to start under the label column.
        if ($check['hint'] !== '' && $check['status'] !== 'ok') {
            echo '            ' . $check['hint'] . "\n";
        }
    }
}

$problems = $report['summary']['problems'];
$warnings = $report['summary']['warnings'];

echo "\n" . str_repeat('-', 72) . "\n";
if ($problems === 0 && $warnings === 0) {
    echo "Everything checks out.\n\n";
} elseif ($problems === 0) {
    echo $warnings . " warning(s), nothing blocking.\n\n";
} else {
    echo $problems . " problem(s) and " . $warnings . " warning(s) - CourseForge will not run correctly.\n\n";
}

exit($problems === 0 ? 0 : 1);
