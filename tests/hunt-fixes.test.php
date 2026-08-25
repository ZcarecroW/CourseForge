<?php
/**
 * The findings from the second hunt, each held down by a test.
 *
 * Every one of these was reproduced against a running instance and then
 * verified a second time by somebody trying to prove it was not real. What is
 * here is the smallest thing that fails if the fix is undone.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use CourseForge\Domain\Structure;
use CourseForge\Mcp\Args;
use CourseForge\Security\Actor;
use CourseForge\Security\Users;
use CourseForge\Support\Config;
use CourseForge\Support\Db;
use CourseForge\Support\Settings;
use CourseForge\Update\GitHub;

/* ------------------------------------------ 1 & 2: titles the outline can hold */

test('a stored title is a fixed point of the outline format', function () {
    // applyStructure matches existing pages BY TITLE. If writing a title and
    // reading it back give different strings, the page is seen as removed on
    // the next apply and its text goes with it - which is what these titles did.
    $nasty = [
        'Title with {{braces}}',
        '# Leading hash',
        '## Two hashes',
        '**Bold title**',
        '__Underscored title__',
        "A title\nwith a newline\n2. and a fake chapter",
        'Ordinary title',
        'Colons: dashes - and (brackets)',
    ];

    foreach ($nasty as $raw) {
        $canonical = Structure::canonicalTitle($raw);

        $markdown = Structure::toMarkdown('Course', 'A description.', [[
            'title' => 'Chapter',
            'description' => 'A chapter.',
            'pages' => [['title' => $canonical, 'tags' => []]],
        ]]);

        $parsed = Structure::parse($markdown);
        same(
            $canonical,
            (string)($parsed['chapters'][0]['pages'][0]['title'] ?? ''),
            'round-trips unchanged: ' . str_replace("\n", '\\n', $raw)
        );
    }
});

test('a title cannot invent chapters that do not exist', function () {
    $canonical = Structure::canonicalTitle("Real page\n2. Ghost chapter\n   Ghost.\n   1. Ghost page");

    ok(!str_contains($canonical, "\n"), 'no line breaks survive: ' . $canonical);

    $parsed = Structure::parse(Structure::toMarkdown('Course', 'd.', [[
        'title' => 'Chapter',
        'description' => 'A chapter.',
        'pages' => [['title' => $canonical, 'tags' => []]],
    ]]));

    same(1, count($parsed['chapters']), 'one chapter in, one chapter out');
    same(1, count($parsed['chapters'][0]['pages']), 'and one page');
});

/* -------------------------------------------------- 18: whole means whole */

test('an id that is not a whole number is refused rather than truncated', function () {
    // 2.9 addressed course 2, silently. The message always promised otherwise.
    foreach ([1.9, 2.5, '3.7', 1e308, '1e400', 'abc', [1], ['a' => 1]] as $value) {
        raises(
            static fn() => (new Args(['course_id' => $value]))->intOrNull('course_id'),
            'refused: ' . (is_scalar($value) ? (string)$value : gettype($value))
        );
    }

    same(7, (new Args(['course_id' => 7]))->intOrNull('course_id'), 'a whole number is fine');
    same(7, (new Args(['course_id' => '7']))->intOrNull('course_id'), 'and so is one written as a string');
    same(7, (new Args(['course_id' => 7.0]))->intOrNull('course_id'), 'and 7.0, which is whole');
    same(null, (new Args([]))->intOrNull('course_id'), 'and an absent one is null');
});

/* ------------------------------------------------------- 23: bool means bool */

test('a boolean setting refuses what it cannot read, rather than storing false', function () {
    // filter_var without FILTER_NULL_ON_FAILURE answers false for everything it
    // does not recognise, so a typo silently turned a setting OFF with a 200.
    foreach (['banana', 'enabled', 2, -1, [], 'maybe'] as $value) {
        raises(
            static fn() => Settings::coerce('updates.enabled', $value),
            'refused: ' . (is_scalar($value) ? (string)$value : gettype($value))
        );
    }

    foreach ([[true, true], [false, false], ['true', true], ['false', false], ['1', true], ['0', false],
              ['yes', true], ['no', false], ['on', true], ['off', false]] as [$given, $expected]) {
        same($expected, Settings::coerce('updates.enabled', $given), 'reads ' . var_export($given, true));
    }
});

/* --------------------------------------- 6: reset is all of it or none of it */

test('resetting settings validates every key before resetting any', function () {
    Config::set('app.cron_workers', 9);
    Config::set('app.cron_max_attempts', 7);

    same(9, Config::int('app.cron_workers', 0), 'the first override is in place');

    // The controller's rule, exercised through the same catalogue it uses.
    $keys = ['app.cron_workers', 'no.such.setting', 'app.cron_max_attempts'];
    $bad = array_values(array_filter($keys, static fn(string $k): bool => Settings::field($k) === null));
    same(['no.such.setting'], $bad, 'the unknown key is found before anything is written');

    same(9, Config::int('app.cron_workers', 0), 'and nothing was reset on the way to finding it');
    same(7, Config::int('app.cron_max_attempts', 0), 'neither of them');

    Config::reset('app.cron_workers');
    Config::reset('app.cron_max_attempts');
});

/* ------------------------------------------- 9: a token dies with its account */

test('deleting an account revokes its connections rather than moving them', function () {
    Users::create('heir', 'correct-horse-battery', Actor::ROLE_ADMIN, 'Heir', 'test');
    Users::create('leaver', 'correct-horse-battery', Actor::ROLE_USER, 'Leaver', 'test');

    Db::run(
        'INSERT INTO mcp_clients (username, name, token_hash, created_at) VALUES (?,?,?,?)',
        ['leaver', 'laptop', hash('sha256', 'not-a-real-token'), time()]
    );
    same(
        1,
        (int)(Db::row('SELECT COUNT(*) n FROM mcp_clients WHERE username = ?', ['leaver'])['n'] ?? 0),
        'the leaver has a connection'
    );

    Users::delete('leaver', 'transfer', 'heir');

    same(
        0,
        (int)(Db::row('SELECT COUNT(*) n FROM mcp_clients WHERE username = ?', ['leaver'])['n'] ?? 0),
        'and it is gone with the account'
    );
    same(
        0,
        (int)(Db::row('SELECT COUNT(*) n FROM mcp_clients WHERE username = ?', ['heir'])['n'] ?? 0),
        'rather than being handed to the heir, where it would have authenticated as them'
    );
});

/* ------------------------- an hour-old cache stated in the present tense */

test('the Updates screen asks for a fresher answer than the scheduler does', function () {
    // Publishing a release and then opening the screen said, flatly, that the
    // newest release was the previous one. It had been true an hour earlier.
    // The cache is right for a scheduler polling every minute and wrong for
    // somebody who has just opened the screen to ask the question.
    ok(
        GitHub::SCREEN_MAX_AGE > 0 && GitHub::SCREEN_MAX_AGE <= 600,
        'the screen window is short enough to be honest: ' . GitHub::SCREEN_MAX_AGE . 's'
    );

    $check = new ReflectionMethod(GitHub::class, 'check');
    $names = array_map(static fn(ReflectionParameter $p): string => $p->getName(), $check->getParameters());
    ok(in_array('maxAge', $names, true), 'check() takes a window rather than only a force flag');

    $status = new ReflectionMethod('CourseForge\Update\Updater', 'status');
    $through = array_map(static fn(ReflectionParameter $p): string => $p->getName(), $status->getParameters());
    ok(in_array('maxAge', $through, true), 'and status() passes one through');

    // The rate limit is the reason the cache exists at all: anonymous GitHub
    // allows sixty an hour, so the screen must not be able to spend it.
    ok(3600 / GitHub::SCREEN_MAX_AGE <= 20, 'a screen left open cannot exhaust the anonymous rate limit');
});
