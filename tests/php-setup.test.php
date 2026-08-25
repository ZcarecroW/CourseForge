<?php
/**
 * Measuring PHP, and the one promise this must never break.
 *
 * A tool that writes a PHP configuration file has exactly one way to be worse
 * than useless: making a good host worse. Every number CourseForge asks for is
 * a FLOOR, and the test that matters is that a host already doing better keeps
 * what it has.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use CourseForge\Support\Php;
use CourseForge\Support\Meta;

/** Reaches one of the private decisions without going through the file system. */
function phpCall(string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod(Php::class, $method))->invoke(null, ...$arguments);
}

/* --------------------------------------------------- never lower a limit */

test('a host that is already generous is left exactly as it is', function () {
    $wanted = Php::wanted();

    // The measured configuration of the live host this was designed against:
    // 768M of memory and 768M of post size are both far above the floors, and
    // an unlimited max_input_time beats any number.
    $generous = [
        'memory_limit' => '768M',
        'post_max_size' => '768M',
        'upload_max_filesize' => '768M',
        'max_input_time' => '-1',
        'max_input_vars' => '6000',
    ];

    foreach ($generous as $name => $current) {
        same(
            null,
            phpCall('target', $current, $wanted[$name]),
            $name . ' at ' . $current . ' is already fine, so nothing is written for it'
        );
    }
});

test('a host below the floor is asked for the floor, and no more', function () {
    $wanted = Php::wanted();

    same('256M', phpCall('target', '128M', $wanted['memory_limit']), 'memory');
    same('300', phpCall('target', '60', $wanted['max_execution_time']), 'execution time');
    same('300', phpCall('target', '60', $wanted['default_socket_timeout']), 'socket timeout');
    same('32M', phpCall('target', '8M', $wanted['post_max_size']), 'post size');
    same('5000', phpCall('target', '1000', $wanted['max_input_vars']), 'input vars');
});

test('unlimited beats any floor, whichever way it is spelled', function () {
    $wanted = Php::wanted();

    same(null, phpCall('target', '0', $wanted['max_execution_time']), '0 means no limit for execution time');
    same(null, phpCall('target', '-1', $wanted['default_socket_timeout']), '-1 for a socket timeout');
    same(null, phpCall('target', '-1', $wanted['memory_limit']), 'and -1 for memory');
});

test('sizes are compared as sizes, not as strings', function () {
    $wanted = Php::wanted();

    // "1G" sorts before "256M" as text and is four times larger as a number.
    same(null, phpCall('target', '1G', $wanted['memory_limit']), '1G is more than 256M');
    same(null, phpCall('target', '512K', $wanted['max_input_vars'] + ['kind' => 'count', 'floor' => '1']), 'counts too');
    ok(phpCall('target', '64M', $wanted['memory_limit']) !== null, 'and 64M is less');
});

/* ------------------------------------------------- switches that are not booleans */

test('a byte count is read as switched on, not as an unrecognised word', function () {
    $wanted = Php::wanted();

    // output_buffering is Off, On, or a buffer size. Reading "4096" as a word
    // that is not "on" made a buffered host report itself already correct.
    same('Off', phpCall('target', '4096', $wanted['output_buffering']), '4096 means buffering is on');
    same('Off', phpCall('target', 'On', $wanted['output_buffering']), 'and so does On');
    same(null, phpCall('target', '0', $wanted['output_buffering']), '0 is off');
    same(null, phpCall('target', '', $wanted['output_buffering']), 'and so is nothing at all');
});

test('the two error switches are read the way PHP writes them', function () {
    $wanted = Php::wanted();

    same(null, phpCall('target', '0', $wanted['display_errors']), 'display_errors 0 is already Off');
    same('Off', phpCall('target', '1', $wanted['display_errors']), 'and 1 needs turning off');
    same(null, phpCall('target', '1', $wanted['log_errors']), 'log_errors 1 is already On');
    same('On', phpCall('target', '0', $wanted['log_errors']), 'and 0 needs turning on');
});

/* --------------------------------------------------------- the file itself */

test('the managed block leaves anything else in the file alone', function () {
    $existing = "; somebody else's line\nzend.assertions = -1\n";
    $block = "; >>> CourseForge - managed block, edited by Settings > Set up PHP\nmemory_limit = 256M\n; <<< CourseForge\n";

    $merged = phpCall('merge', $existing, $block);

    ok(str_contains($merged, 'zend.assertions = -1'), 'the other line survives');
    ok(str_contains($merged, 'memory_limit = 256M'), 'and the block is there');

    // Applied twice, the file must not grow a second block.
    $again = phpCall('merge', $merged, $block);
    same(1, substr_count($again, '>>> CourseForge'), 'one managed block, not two');
    ok(str_contains($again, 'zend.assertions = -1'), 'and the other line still survives');
});

test('rewriting the block replaces it rather than appending', function () {
    $first = "; >>> CourseForge - managed block, edited by Settings > Set up PHP\nmemory_limit = 256M\n; <<< CourseForge\n";
    $second = "; >>> CourseForge - managed block, edited by Settings > Set up PHP\nmemory_limit = 512M\n; <<< CourseForge\n";

    $merged = phpCall('merge', $first, $second);

    ok(str_contains($merged, 'memory_limit = 512M'), 'the new value is in');
    ok(!str_contains($merged, 'memory_limit = 256M'), 'and the old one is gone');
    same(1, substr_count($merged, '>>> CourseForge'), 'still one block');
});

/* ------------------------------------------------------------- honesty */

test('it refuses to pretend on a SAPI that never reads the file', function () {
    // This suite runs on the command line, which reads no .user.ini at all.
    $plan = Php::plan();

    same('cli', $plan['mechanism'], 'the mechanism is named rather than guessed');
    ok($plan['possible'] === false, 'and nothing is claimed to be possible');
    ok($plan['change'] === [], 'so nothing is listed as about to change');
    ok(str_contains($plan['note'], 'command line'), 'and the note says why: ' . $plan['note']);
});

test('applying on a SAPI that cannot writes nothing and says so', function () {
    $result = Php::apply('tester');

    ok(($result['written'] ?? null) === false, 'nothing was written');
    ok(($result['error'] ?? '') !== '', 'and there is a reason: ' . ($result['error'] ?? ''));
    ok(!is_file(CF_ROOT . '/.user.ini.tmp'), 'no half-written file was left behind');
});

test('every directive CourseForge asks for explains itself', function () {
    foreach (Php::wanted() as $name => $spec) {
        ok(($spec['why'] ?? '') !== '', $name . ' says why it matters');
        ok(strlen((string)$spec['why']) > 40, $name . ' says it in a sentence rather than a word');
        ok(
            in_array($spec['kind'], ['bytes', 'seconds', 'count', 'fixed'], true),
            $name . ' has a kind this code knows how to compare: ' . $spec['kind']
        );
        if ($spec['kind'] === 'fixed') {
            ok(isset($spec['value']), $name . ' has a value');
        } else {
            ok(isset($spec['floor']), $name . ' has a floor');
        }
    }
});

/* ------------------------------------------- the loop that must not happen */

test('the tool does not undo its own work', function () {
    // The loop: we raise a limit, the next reading sees our own value, decides
    // the host is fine, removes the line, the host drops back, and round we go.
    // It comes from measuring what is IN EFFECT and calling it what the host
    // gives - the same number only until we have changed something.
    $host = [
        'memory_limit' => '768M',
        'max_execution_time' => '60',
        'default_socket_timeout' => '60',
        'max_input_time' => '-1',
        'post_max_size' => '768M',
        'upload_max_filesize' => '768M',
        'max_input_vars' => '6000',
        'display_errors' => '',
        'log_errors' => '1',
        'output_buffering' => '',
    ];

    Meta::set(Php::META_BASELINE, (string)json_encode([
        'values' => $host,
        'php' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'at' => time(),
    ]));

    $answers = [];
    for ($round = 0; $round < 4; $round++) {
        $writes = [];
        foreach (Php::plan()['settings'] as $row) {
            if (!$row['satisfied'] && $row['settable']) {
                $writes[$row['name']] = $row['target'];
            }
        }
        ksort($writes);
        $answers[] = (string)json_encode($writes);

        // The file takes effect: from here ini_get answers with what we wrote.
        foreach ($writes as $name => $value) {
            @ini_set($name, (string)$value);
        }
    }

    same(1, count(array_unique($answers)), 'four rounds, one answer: ' . implode(' then ', array_unique($answers)));

    $decided = json_decode($answers[0], true);
    same('300', $decided['max_execution_time'] ?? null, 'and it is the right one');
    ok(!isset($decided['memory_limit']), '768M of memory is left exactly as the host gave it');
});

test('the baseline survives the file being replaced, and is re-measured on request', function () {
    Meta::set(Php::META_BASELINE, (string)json_encode([
        'values' => ['max_execution_time' => '60'] + array_fill_keys(array_keys(Php::wanted()), '0'),
        'php' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'at' => time(),
    ]));

    $kept = Php::plan();
    $row = null;
    foreach ($kept['settings'] as $entry) {
        if ($entry['name'] === 'max_execution_time') {
            $row = $entry;
        }
    }
    same('60', $row['current'], 'the remembered host value is used, not what is in effect');

    // Asked explicitly, it forgets and looks again.
    $fresh = Php::plan(true);
    foreach ($fresh['settings'] as $entry) {
        if ($entry['name'] === 'max_execution_time') {
            same((string)ini_get('max_execution_time'), $entry['current'], 're-measured on request');
        }
    }
});
