<?php
/**
 * Defects an adversarial audit of 4.4.1 confirmed, and the fixes for them.
 *
 * Each of these was found by a reviewer, then survived a panel of verifiers
 * whose instructions were to refute it — so each one is a thing that really
 * happened, not a thing that could. They have nothing in common except that,
 * which is why they are together: a file per finding would say less than the
 * list does.
 *
 * The pattern worth noticing across four of them is the same mistake in four
 * places: deciding something irreversible on evidence that does not support it.
 * A chapter with no title discarded pages it had already collected. A cancel
 * that could not reach the provider closed the run anyway. A truncated answer
 * was stored as a finished page. A page id of 12.3 became page 12. In each
 * case the code had the information it needed and did not look at it.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use CourseForge\Domain\Structure;
use CourseForge\Mcp\Args;
use CourseForge\Support\HttpException;

/* ------------------------------------------------- an untitled chapter --- */

test('a chapter whose title cleans away to nothing does not take its pages with it', function () {
    // '****' is the one that turns up: clean() strips the bold wrapper and is
    // left with the empty string, so the chapter opened, collected two pages,
    // and was then dropped by the title filter at the end of parse() - pages
    // and all, silently. Applying such an outline deleted the text on them.
    $parsed = Structure::parse(
        "# My Course\n\n1. Getting Started\n   1. Setup\n2. ****\n   1. Introduction to Widgets\n   2. Advanced Widgets\n"
    );

    $titles = [];
    foreach ($parsed['chapters'] as $chapter) {
        foreach ($chapter['pages'] as $page) {
            $titles[] = $page['title'];
        }
    }

    same(3, count($titles), 'all three pages survived');
    ok(in_array('Introduction to Widgets', $titles, true), 'including the ones under the untitled chapter');
    ok(in_array('Advanced Widgets', $titles, true), 'both of them');
    foreach ($parsed['chapters'] as $chapter) {
        ok($chapter['title'] !== '', 'and no untitled chapter was stored');
    }
});

test('an untitled chapter before any real one still keeps its pages', function () {
    $parsed = Structure::parse("# My Course\n\n1. ****\n   1. Orphan Page\n2. Real Chapter\n   1. Real Page\n");

    $titles = [];
    foreach ($parsed['chapters'] as $chapter) {
        foreach ($chapter['pages'] as $page) {
            $titles[] = $page['title'];
        }
    }
    ok(in_array('Orphan Page', $titles, true), 'the page with nowhere to go was given somewhere');
    ok(in_array('Real Page', $titles, true), 'and the ordinary one is untouched');
});

/* ---------------------------------------------------- Args::ids rounding --- */

test('a page id that is not a whole number is refused, not rounded', function () {
    // start_run takes this argument to say which pages to write. 12.3 used to
    // pass is_numeric, cast to 12, and regenerate a page nobody named - over
    // the top of text somebody already had.
    $e = raises(
        static fn() => Args::of(['pages' => [4, 12.3]])->ids('pages'),
        'a fractional id is refused'
    );
    ok($e instanceof HttpException, 'and refused the way every other bad argument is');
    ok(str_contains($e->getMessage(), 'whole numbers'), 'saying what was wrong with it');
});

test('a whole number written as a string is still a whole number', function () {
    // The refusal must not become stricter than it was: a JSON encoder that
    // emits "12" rather than 12 is ordinary, and every other accessor here
    // takes it.
    same([4, 12], Args::of(['pages' => [4, '12']])->ids('pages'), 'both forms are accepted');
});

test('ids still refuses the things it always refused', function () {
    foreach ([[0], [-3], ['abc'], [null], [[1]]] as $bad) {
        raises(
            static fn() => Args::of(['pages' => $bad])->ids('pages'),
            'refused ' . json_encode($bad)
        );
    }
    same([], Args::of([])->ids('pages'), 'and an absent argument is still an empty list');
});

/* ------------------------------------------- the description clamp ------- */

test('a description with no sentence boundary is still published as something', function () {
    // TargetPublisher::describe() drops whole paragraphs, then whole sentences. A
    // single run-on paragraph joined by commas has neither, so the sentence
    // loop emptied the list and BookStack was sent an empty description - and
    // with it, no clue that there was more text in CourseForge.
    $method = new ReflectionMethod(CourseForge\Publish\TargetPublisher::class, 'clampWords');
    $method->setAccessible(true);

    $runOn = trim(str_repeat('this clause has no full stop in it at all, ', 200));
    ok(!str_contains($runOn, '. '), 'the fixture really has no sentence boundary');

    $clamped = $method->invoke(null, $runOn, 2000);

    ok($clamped !== '', 'something came back');
    ok(mb_strlen(CourseForge\Support\Markdown::toHtml($clamped)) <= 2000, 'and it fits what BookStack accepts');
    ok(str_ends_with($clamped, '…'), 'and says it was cut');
    ok(!str_ends_with(rtrim($clamped, '…'), 'thi'), 'without stopping mid-word');
});

test('a clamp with room for nothing gives nothing rather than something too long', function () {
    $method = new ReflectionMethod(CourseForge\Publish\TargetPublisher::class, 'clampWords');
    $method->setAccessible(true);

    same('', $method->invoke(null, 'anything at all', 3), 'an impossible limit is answered honestly');
});

/* -------------------------------------------- the MCP origin setting ----- */

test('an allowed origin written the way the field asks for it actually matches', function () {
    // The setting is labelled "origins" and an origin has a scheme on it, which
    // is also exactly what a browser sends. Comparing that against a bare
    // hostname never matched, so the setting silently did nothing.
    $method = new ReflectionMethod(CourseForge\Mcp\Server::class, 'originAllowed');
    $method->setAccessible(true);

    $previousOrigin = $_SERVER['HTTP_ORIGIN'] ?? null;
    $previousHost = $_SERVER['HTTP_HOST'] ?? null;

    try {
        $_SERVER['HTTP_HOST'] = 'courseforge.example.com';
        $_SERVER['HTTP_ORIGIN'] = 'https://mytool.example.com';

        CourseForge\Support\Config::set('mcp.allowed_origins', ['https://mytool.example.com']);
        ok($method->invoke(null), 'a full origin in the setting matches the header');

        CourseForge\Support\Config::set('mcp.allowed_origins', ['mytool.example.com']);
        ok($method->invoke(null), 'and a bare hostname still does, as it always did');

        CourseForge\Support\Config::set('mcp.allowed_origins', ['https://somewhere.else']);
        ok(!$method->invoke(null), 'somewhere else is still refused');

        $_SERVER['HTTP_ORIGIN'] = 'https://courseforge.example.com';
        CourseForge\Support\Config::set('mcp.allowed_origins', []);
        ok($method->invoke(null), 'and the endpoint always trusts its own origin');
    } finally {
        // $_SERVER is global and the next test file gets whatever this one
        // leaves in it.
        foreach (['HTTP_ORIGIN' => $previousOrigin, 'HTTP_HOST' => $previousHost] as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
        CourseForge\Support\Config::set('mcp.allowed_origins', []);
    }
});

/* ------------------------------------------- the private-file deny list --- */

test('the shipped deny lists cover the sidecar files SQLite actually creates', function () {
    // The database is app.sqlite and it is opened in WAL mode, so the files
    // beside it are app.sqlite-wal and app.sqlite-shm - which the deny list's
    // *.db-wal / *.db-shm entries never matched. The directory deny above it
    // is what was really holding the line; this is the second layer that was
    // supposed to be there if the first one ever failed.
    $htaccess = (string)file_get_contents(CF_ROOT . '/.htaccess');
    foreach (['sqlite-wal', 'sqlite-shm'] as $extension) {
        ok(str_contains($htaccess, $extension), '.htaccess denies *.' . $extension);
    }

    // tools/router-dev.php is what somebody checks a rule against, so the two
    // must agree or the check is worthless.
    $router = (string)file_get_contents(CF_ROOT . '/tools/router-dev.php');
    foreach (['sqlite-wal', 'sqlite-shm'] as $extension) {
        ok(str_contains($router, $extension), 'the development router denies *.' . $extension . ' too');
    }
});
