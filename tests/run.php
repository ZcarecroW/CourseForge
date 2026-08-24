<?php
declare(strict_types=1);

/**
 * CourseForge - the test runner.
 *
 *   php tests/run.php              every test
 *   php tests/run.php openrouter   only files whose name contains that word
 *
 * No framework, and deliberately no dependency on one: CourseForge installs
 * without Composer, so a suite needing PHPUnit could not be run on the machine
 * it is meant to protect. Every *.test.php file beside this one is included,
 * each test() call prints one line, and the exit code is what a pipeline reads.
 *
 * The run works against a scratch database in the system temporary directory,
 * never data/: COURSEFORGE_DATA_DIR is set before the bootstrap fixes CF_DATA.
 */

if (PHP_SAPI !== 'cli') {
    exit("This runner is for the command line only.\n");
}

// A scratch directory per run, not one shared by all of them.
//
// It used to be a single fixed path emptied on the way in, which meant two runs
// at once deleted each other's database mid-test. That produced failures that
// were not real and vanished on a re-run - the worst kind, because it teaches
// people to re-run until the suite is green rather than to read it.
//
// Emptying still happens on the way in rather than the way out, for the reason
// it always did: on Windows an open file cannot be deleted, and the database
// handle is still open when the last test finishes. So the removal below is
// best effort, and anything a killed run left behind is swept by the next one
// that starts more than an hour later.
$root = sys_get_temp_dir() . '/courseforge-tests';
$sandbox = $root . '/run-' . getmypid() . '-' . bin2hex(random_bytes(3));

if (!is_dir($sandbox) && !@mkdir($sandbox, 0770, true) && !is_dir($sandbox)) {
    fwrite(STDERR, 'Could not create the scratch directory ' . $sandbox . "\n");
    exit(1);
}

foreach ((array)glob($root . '/run-*') as $old) {
    if (is_dir($old) && $old !== $sandbox && @filemtime($old) < time() - 3600) {
        array_map(static fn(string $f): bool => @unlink($f), glob($old . '/*') ?: []);
        @rmdir($old);
    }
}

register_shutdown_function(static function () use ($sandbox): void {
    array_map(static fn(string $f): bool => @unlink($f), glob($sandbox . '/*') ?: []);
    @rmdir($sandbox);
});

putenv('COURSEFORGE_DATA_DIR=' . $sandbox);

require __DIR__ . '/../src/bootstrap.php';

/** The tally, kept off the global namespace where a test file could tread on it. */
final class Suite
{
    public static int $passed = 0;
    /** @var array<int,string> */
    public static array $failed = [];
}

/** One test. Whatever it throws is the failure, and the rest of the run carries on. */
function test(string $name, callable $body): void
{
    try {
        $body();
        Suite::$passed++;
        echo '  ok    ' . $name . "\n";
    } catch (Throwable $e) {
        Suite::$failed[] = $name . ' - ' . $e->getMessage();
        echo '  FAIL  ' . $name . "\n        " . $e->getMessage() . "\n";
    }
}

function ok(bool $condition, string $what): void
{
    if (!$condition) {
        throw new RuntimeException($what);
    }
}

/** Compared with ===, and both sides are shown, because that is most of the value. */
function same(mixed $expected, mixed $actual, string $what): void
{
    $show = static fn(mixed $v): string => is_string($v) ? '"' . $v . '"' : (string)json_encode($v);
    ok($expected === $actual, $what . ' - expected ' . $show($expected) . ', got ' . $show($actual));
}

/** Asserts that $body raises, and hands the exception back for a closer look. */
function raises(callable $body, string $what): Throwable
{
    try {
        $body();
    } catch (Throwable $e) {
        return $e;
    }
    throw new RuntimeException($what . ' - nothing was thrown');
}

$filter = (string)($argv[1] ?? '');
$files = glob(__DIR__ . '/*.test.php') ?: [];
sort($files);

foreach ($files as $file) {
    if ($filter !== '' && !str_contains(basename($file), $filter)) {
        continue;
    }
    echo basename($file) . "\n";
    // In a closure, so a stray variable in a test file cannot land on this loop's.
    (static fn(string $path): mixed => require $path)($file);
}

echo "\n" . Suite::$passed . ' passed, ' . count(Suite::$failed) . " failed\n"
    . implode('', array_map(static fn(string $f): string => '  ' . $f . "\n", Suite::$failed));

exit(Suite::$failed === [] ? 0 : 1);
