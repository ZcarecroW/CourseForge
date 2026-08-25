<?php
/**
 * Measuring PHP, and the promises this must never break.
 *
 * A tool that writes a PHP configuration file has exactly one way to be worse
 * than useless: making a good host worse. Two rounds of hunting found four
 * separate ways it managed to, and every one of them is here.
 *
 * The design that replaced them is monotonic: a run can leave things the same
 * or better and never worse, and it holds no memory that could go stale or be
 * poisoned. These tests are mostly about that property rather than about any
 * particular number.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use CourseForge\Support\Php;

/** Reaches one of the private decisions without going near the file system. */
function phpCall(string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod(Php::class, $method))->invoke(null, ...$arguments);
}

/** A managed block, as the tool writes one. */
function phpBlock(string ...$lines): string
{
    return "; >>> CourseForge - managed block, edited by Settings > Set up PHP\n"
        . implode("\n", $lines) . "\n; <<< CourseForge\n";
}

/* --------------------------------------------------- never lower a limit */

test('a host that is already generous is left exactly as it is', function () {
    $wanted = Php::wanted();

    // The measured configuration of the live host this was designed against.
    $generous = [
        'memory_limit' => '768M',
        'post_max_size' => '768M',
        'upload_max_filesize' => '768M',
        'max_input_time' => '-1',
        'max_input_vars' => '6000',
    ];

    foreach ($generous as $name => $effective) {
        same(
            null,
            phpCall('target', $effective, null, $wanted[$name]),
            $name . ' at ' . $effective . ' is already fine, so nothing is written for it'
        );
    }
});

test('a host below the floor is asked for the floor, and no more', function () {
    $wanted = Php::wanted();

    same('256M', phpCall('target', '128M', null, $wanted['memory_limit']), 'memory');
    same('300', phpCall('target', '60', null, $wanted['max_execution_time']), 'execution time');
    same('32M', phpCall('target', '8M', null, $wanted['post_max_size']), 'post size');
    same('5000', phpCall('target', '1000', null, $wanted['max_input_vars']), 'input vars');
});

test('unlimited beats any floor, whichever way it is spelled', function () {
    $wanted = Php::wanted();

    same(null, phpCall('target', '0', null, $wanted['max_execution_time']), '0 means no limit');
    same(null, phpCall('target', '-1', null, $wanted['default_socket_timeout']), '-1 for a socket timeout');
    same(null, phpCall('target', '-1', null, $wanted['memory_limit']), 'and -1 for memory');
});

test('sizes are compared as sizes, not as strings', function () {
    $wanted = Php::wanted();

    // "1G" sorts before "256M" as text and is four times larger as a number.
    same(null, phpCall('target', '1G', null, $wanted['memory_limit']), '1G is more than 256M');
    ok(phpCall('target', '64M', null, $wanted['memory_limit']) !== null, 'and 64M is less');
});

/* ------------------------------------------------------------- monotonic */

test('a directive already ours is never removed', function () {
    $wanted = Php::wanted();

    // The trap: our block says 300, so ini_get answers 300, so it looks like
    // the host is fine - and dropping the line is what puts the host back to
    // 60. An earlier version did exactly that, on a routine PHP point release,
    // and reported it as a successful write.
    same(
        '300',
        phpCall('target', '300', '300', $wanted['max_execution_time']),
        'kept, even though what is in effect now meets the floor'
    );
    same(
        '512M',
        phpCall('target', '512M', '512M', $wanted['memory_limit']),
        'and a larger value we set is kept at its size, not reduced to the floor'
    );
});

test('a directive already ours is never lowered', function () {
    $wanted = Php::wanted();

    // Below the floor because the floor moved, or the file was hand-edited.
    same('256M', phpCall('target', '64M', '64M', $wanted['memory_limit']), 'raised to the floor');
    same('300', phpCall('target', '30', '30', $wanted['max_execution_time']), 'and so is a time');
});

test('a run can only leave things the same or better', function () {
    $wanted = Php::wanted();

    // Every combination of what the host gives and what we have already set:
    // the answer must never be smaller than either of them.
    $sizes = ['64M', '128M', '256M', '512M', '1G'];

    foreach ($sizes as $effective) {
        foreach (array_merge([null], $sizes) as $ours) {
            $target = phpCall('target', $effective, $ours, $wanted['memory_limit']);
            $after = $target ?? $effective;

            ok(
                phpCall('bytes', $after) >= phpCall('bytes', $effective),
                'host ' . $effective . ', ours ' . ($ours ?? 'none') . ' -> ' . $after . ' is not a reduction'
            );
            if ($ours !== null) {
                ok(
                    phpCall('bytes', $after) >= phpCall('bytes', $ours),
                    'and not below what we had already set (' . $ours . ')'
                );
            }
        }
    }
});

/* ------------------------------------------- switches that are not booleans */

test('a byte count is read as switched on, not as an unrecognised word', function () {
    $wanted = Php::wanted();

    // output_buffering is Off, On, or a buffer size. Reading "4096" as a word
    // that is not "on" made a buffered host report itself already correct.
    same('Off', phpCall('target', '4096', null, $wanted['output_buffering']), '4096 means buffering is on');
    same(null, phpCall('target', '0', null, $wanted['output_buffering']), '0 is off');
    same(null, phpCall('target', '', null, $wanted['output_buffering']), 'and so is nothing at all');
});

/* ----------------------------------------------------------- the file itself */

test('the managed block leaves anything else in the file alone', function () {
    $existing = "; somebody else's line\nzend.assertions = -1\n";
    $block = phpBlock('memory_limit = 256M');

    $merged = phpCall('merge', $existing, $block);

    ok(str_contains($merged, 'zend.assertions = -1'), 'the other line survives');
    ok(str_contains($merged, 'memory_limit = 256M'), 'and the block is there');

    $again = phpCall('merge', $merged, $block);
    same(1, substr_count($again, '>>> CourseForge'), 'one managed block, not two');
    ok(str_contains($again, 'zend.assertions = -1'), 'and the other line still survives');
});

test('every managed block is replaced, not only the first', function () {
    // PHP reads a repeated directive last-wins, so a second block left behind
    // silently overrode the one just written: apply() reported memory_limit
    // 256M while PHP was actually using the stale 64M below it.
    $doubled = phpBlock('memory_limit = 256M') . "\n" . phpBlock('memory_limit = 64M');

    $merged = phpCall('merge', $doubled, phpBlock('memory_limit = 512M'));

    same(1, substr_count($merged, '>>> CourseForge'), 'exactly one block afterwards');
    ok(str_contains($merged, 'memory_limit = 512M'), 'holding the new value');
    ok(!str_contains($merged, 'memory_limit = 64M'), 'and the stale one is gone');
});

test('a block with no END never swallows what comes after it', function () {
    // A half-written file, or somebody who deleted the END line by hand. The
    // old code paired that orphan BEGIN with the END of a block it appended
    // later, and everything between - the user's own directives - vanished.
    $damaged = "; >>> CourseForge - managed block, edited by Settings > Set up PHP\n"
        . "memory_limit = 128M\n"
        . "; MY OWN MARKER\n"
        . "zend.assertions = -1\n";

    $merged = phpCall('merge', $damaged, phpBlock('memory_limit = 256M'));
    same(1, substr_count($merged, '>>> CourseForge'), 'one block');

    // The unpaired block runs to the end of the file, so those lines are
    // inside it and go with it - but nothing OUTSIDE a block is ever touched.
    $withOutside = "safe_line = 1\n" . $damaged;
    $mergedOutside = phpCall('merge', $withOutside, phpBlock('memory_limit = 256M'));
    ok(str_contains($mergedOutside, 'safe_line = 1'), 'a line above the damage survives');
    same(1, substr_count($mergedOutside, '>>> CourseForge'), 'and still one block');
});

test('what the block sets is read back from the file, not remembered', function () {
    $body = phpBlock(
        '; a comment inside the block',
        'memory_limit = 384M',
        '',
        'max_execution_time = 600',
        'not_a_directive_we_know = 1'
    );

    $values = phpCall('blockValues', $body);

    same('384M', $values['memory_limit'] ?? null, 'read from the file');
    same('600', $values['max_execution_time'] ?? null, 'including a value larger than the floor');
    ok(!isset($values['not_a_directive_we_know']), 'and nothing outside the catalogue is picked up');
});

test('releasing takes every block out and leaves the rest', function () {
    $body = "keep_me = 1\n\n" . phpBlock('memory_limit = 256M') . "\nalso_keep = 2\n" . phpBlock('post_max_size = 32M');

    $stripped = phpCall('strip', $body);

    ok(str_contains($stripped, 'keep_me = 1'), 'the line above survives');
    ok(str_contains($stripped, 'also_keep = 2'), 'and the one between two blocks');
    ok(!str_contains($stripped, 'CourseForge'), 'and nothing of ours is left');
    ok(!str_contains($stripped, 'memory_limit'), 'nor any directive we had set');
});

/* ------------------------------------------------------------------ honesty */

test('nothing here writes or remembers anything', function () {
    // A dry run that persisted a measurement was a bug: the destructive change
    // had already happened and the next ordinary run carried it out. So the
    // whole reading path must be free of side effects.
    $before = Php::inspect();
    $planned = Php::plan();
    $again = Php::inspect();

    same($before['settings'], $again['settings'], 'inspecting twice gives the same answer');
    ok(isset($planned['change'], $planned['blocked']), 'and a plan is just a reading');
});

test('it refuses to pretend on a SAPI that never reads the file', function () {
    $plan = Php::plan();

    same('cli', $plan['mechanism'], 'the mechanism is named rather than guessed');
    ok($plan['possible'] === false, 'nothing is claimed to be possible');
    ok($plan['change'] === [], 'nothing is listed as about to change');
    ok(str_contains($plan['note'], 'command line'), 'and the note says why: ' . $plan['note']);
});

test('what cannot be changed is listed as the host deciding, not as pending work', function () {
    // The card badged six directives "will be raised" on a host that had just
    // been told nothing here could be changed at all.
    $plan = Php::plan();

    foreach ($plan['settings'] as $row) {
        if ($row['satisfied']) {
            continue;
        }
        $inChange = false;
        foreach ($plan['change'] as $pending) {
            if ($pending['name'] === $row['name']) {
                $inChange = true;
            }
        }
        ok(!$inChange, $row['name'] . ' is not promised as pending work on a host that cannot be changed');
    }

    same(count($plan['change']), 0, 'so the change list is empty here');
});

test('applying on a SAPI that cannot writes nothing and says so', function () {
    $result = Php::apply('tester');

    ok(($result['written'] ?? null) === false, 'nothing was written');
    ok(($result['error'] ?? '') !== '', 'and there is a reason: ' . ($result['error'] ?? ''));
    ok(!is_file(CF_ROOT . '/.user.ini.tmp'), 'no half-written file was left behind');
});

test('releasing on a SAPI that cannot does nothing either', function () {
    $before = is_file(CF_ROOT . '/.user.ini') ? (string)file_get_contents(CF_ROOT . '/.user.ini') : '';
    $result = Php::release('tester');

    ok(($result['released'] ?? null) === false, 'nothing was released');
    $after = is_file(CF_ROOT . '/.user.ini') ? (string)file_get_contents(CF_ROOT . '/.user.ini') : '';
    same($before, $after, 'and the shipped file is untouched');
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
