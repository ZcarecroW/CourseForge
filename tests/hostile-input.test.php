<?php
/**
 * Input shapes that made the application answer with a 500.
 *
 * Every case here was found the same way: agents drove the running application
 * for an hour, and the server logs afterwards held the exceptions they had
 * provoked. None of it came from reading the code, and none of it is exotic - a
 * JSON number that is too large, a list where a scalar belongs, a null byte in
 * a password. What they had in common was reaching a cast or a library call
 * that assumed its input had already been checked.
 *
 * A 500 is the wrong answer to bad input. It tells the caller nothing, it goes
 * in the log as though the application were at fault, and on a shared host it
 * is indistinguishable from a real outage.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use CourseForge\Domain\Details;
use CourseForge\Mcp\Args;
use CourseForge\Support\Request;
use CourseForge\Security\Users;
use CourseForge\Support\Settings;

/* ------------------------------------------------------------ INF and NAN */

test('a JSON number too large for an integer is refused, not cast', function () {
    // json_decode('1e400') is the float INF. is_numeric(INF) is true, which is
    // how it got past every "if it is numeric" guard, and (int)INF raises.
    $infinity = json_decode('1e400');
    ok(is_numeric($infinity), 'INF is numeric, which is the trap');
    ok(!is_finite($infinity), 'and not finite, which is the answer');

    raises(
        static fn() => Settings::coerce('app.cron_workers', $infinity),
        'a setting given INF must be refused'
    );
    raises(
        static fn() => Settings::coerce('app.cron_workers', -$infinity),
        'and -INF with it'
    );
});

test('a content-detail value of INF is ignored rather than crashing', function () {
    $base = Details::decode('{}');
    $patched = Details::patch($base, [], ['min_length' => json_decode('1e400')]);

    ok(!isset($patched['params']['min_length']), 'the impossible value is not stored');
});

test('an MCP argument of INF is refused', function () {
    raises(
        static fn() => Args::of(['limit' => json_decode('1e400')])->intOrNull('limit'),
        'intOrNull must refuse INF'
    );
    raises(
        static fn() => Args::of(['pages' => [json_decode('1e400')]])->ids('pages'),
        'a list of ids must refuse INF'
    );
    same(7, Args::of(['limit' => json_decode('1e400')])->int('limit', 7), 'int() falls back to its default');
});

/* --------------------------------------------------- lists where scalars go */

test('a content detail sent as a list is refused with a message naming it', function () {
    $base = Details::decode('{}');

    // This one was a 500: (string)$value on an array is a warning, and the
    // front controller turns a warning into an exception.
    $e = raises(
        static fn() => Details::patch($base, [], ['audience' => ['a', 'b']]),
        'a list for a text value must be refused'
    );
    ok(str_contains($e->getMessage(), 'audience'), 'and the message names the key');

    // This one was silent: is_numeric() is false for a list, so it was dropped.
    raises(
        static fn() => Details::patch($base, [], ['min_length' => [500]]),
        'a list for a numeric value must be refused rather than dropped'
    );

    // And this one was silently ON, because (int)[] is 1.
    raises(
        static fn() => Details::patch($base, ['summary' => ['on' => true]], []),
        'an object for a tri-state feature must be refused'
    );
});

test('ordinary content details still work', function () {
    $base = Details::decode('{}');
    $patched = Details::patch($base, ['summary' => 1, 'exercises' => -1], ['min_length' => 800, 'audience' => ' pros ']);

    same(1, $patched['features']['summary'], 'a feature switched on');
    same(-1, $patched['features']['exercises'], 'a feature switched off');
    same(800, $patched['params']['min_length'], 'a number stored');
    same('pros', $patched['params']['audience'], 'and text trimmed');

    $clamped = Details::patch($base, [], ['min_length' => 999999]);
    ok($clamped['params']['min_length'] < 999999, 'an out-of-range number is still clamped rather than refused');
});

/* ----------------------------------------------------------- passwords */

test('a null byte in a password is refused rather than thrown by bcrypt', function () {
    // password_hash() raises a ValueError on a null byte. Nothing caught it.
    $e = raises(
        static fn() => Users::validatePassword("abc\0defghijkl"),
        'a null byte must be refused'
    );
    ok(str_contains($e->getMessage(), 'null'), 'and the message says why');
});

test('a password longer than the hash reads is refused rather than truncated', function () {
    // bcrypt uses the first 72 bytes and discards the rest without a word, so
    // two different long passwords are the same password. Proven here, because
    // it is the reason for the rule.
    $a = str_repeat('x', 72) . 'AAAAAAAA';
    $b = str_repeat('x', 72) . 'BBBBBBBB';
    ok(
        password_verify($b, password_hash($a, PASSWORD_DEFAULT)),
        'bcrypt really does treat these two different passwords as one'
    );

    raises(static fn() => Users::validatePassword($a), 'so an over-long password is refused');
    Users::validatePassword(str_repeat('x', Users::MAX_PASSWORD_BYTES));
    ok(true, 'and one of exactly the limit is accepted');
});

test('the limit is bytes, not characters', function () {
    // Thirty emoji is thirty characters and a hundred and twenty bytes. Counting
    // characters would have let it through to be silently halved.
    $emoji = str_repeat("\u{1F600}", 30);
    same(30, mb_strlen($emoji), 'thirty characters');
    same(120, strlen($emoji), 'and a hundred and twenty bytes');

    raises(static fn() => Users::validatePassword($emoji), 'so it is refused');
    Users::validatePassword("\u{1F600}" . str_repeat('x', 10));
    ok(true, 'while a short password with an emoji in it is fine');
});

/* ------------------------------------------------- the updater call guard */

test('the updater guard asks whether a method can be called, not whether it exists', function () {
    $updater = 'CourseForge\\Update\\Updater';

    // Updater::check() is a private helper that builds one precondition row.
    // method_exists() says true, which is how every check_for_update over MCP
    // came to call a private method and fatal.
    ok(method_exists($updater, 'check'), 'method_exists is true for the private helper');
    ok(!is_callable([$updater, 'check']), 'is_callable is false, which is the question worth asking');

    ok(is_callable([$updater, 'status']), 'the real entry point is callable');
    ok(is_callable([$updater, 'install']), 'as is install');
    ok(is_callable([$updater, 'rollback']), 'and rollback');
});

test('install and rollback take a user name, which is what the tools now pass', function () {
    foreach (['install', 'rollback'] as $method) {
        $first = (new ReflectionMethod('CourseForge\\Update\\Updater', $method))->getParameters()[0];
        same('string', (string)$first->getType(), $method . '() takes a string, not an Actor');
    }
});

/* --------------------------------------------- a body of the wrong shape */

test('a request body that is a populated JSON list is refused', function () {
    // PHP decodes a JSON array and a JSON object into the same PHP type, so the
    // old is_array() check accepted both while its message said "must be a JSON
    // object". A client that sent a list got a 200 and a junk row.
    raises(
        static fn() => Request::decodeBody('[1,2,3]'),
        'a list is not an object, whatever PHP decodes it into'
    );

    raises(
        static fn() => Request::decodeBody('["name", "Vue"]'),
        'including one that looks like it was meant to be a pair'
    );
});

test('an empty body is still allowed, whichever brace it was written with', function () {
    same([], Request::decodeBody(''), 'no body at all');
    same([], Request::decodeBody('   '), 'whitespace only');
    same([], Request::decodeBody('{}'), 'an explicit empty object means use every default');
    same([], Request::decodeBody('[]'), 'and an empty list is indistinguishable from it once decoded');
});

test('an ordinary object body still reaches the accessors', function () {
    same(
        ['name' => 'Vue.js from scratch', 'topic' => 'A complete Vue 3 course.'],
        Request::decodeBody('{"name":"Vue.js from scratch","topic":"A complete Vue 3 course."}'),
        'the fields arrive as sent'
    );
});

test('a body that is not JSON at all is refused', function () {
    raises(
        static fn() => Request::decodeBody('name=Vue&topic=x'),
        'a form-encoded body sent with a JSON content type'
    );

    raises(
        static fn() => Request::decodeBody('42'),
        'and a bare scalar'
    );
});

test('malformed JSON is named as such, not reported as the wrong shape', function () {
    // A latin-1 body is a well-formed object with one byte that is not UTF-8.
    // Telling its author "Request body must be a JSON object" is an answer
    // about structure given to somebody whose structure was never wrong - it
    // sends them to read their own braces instead of their Content-Type.
    $latin1 = '{"username":"m' . chr(0xFC) . 'ller"}';

    $e = raises(
        static fn() => Request::decodeBody($latin1),
        'a latin-1 body must be refused'
    );
    ok(
        str_contains($e->getMessage(), 'not valid JSON'),
        'and the message says the JSON is malformed: ' . $e->getMessage()
    );
    ok(
        !str_contains($e->getMessage(), 'must be a JSON object'),
        'rather than blaming the shape'
    );

    $truncated = raises(
        static fn() => Request::decodeBody('{"name": "Vue"'),
        'and so is a body that stops mid-object'
    );
    ok(str_contains($truncated->getMessage(), 'not valid JSON'), 'named as malformed too');
});

test('the same character as valid UTF-8 is accepted either way it is written', function () {
    $expected = ['username' => 'm' . "\u{00FC}" . 'ller'];

    same(
        $expected,
        Request::decodeBody('{"username":"m\u00fcller"}'),
        'written as an escaped code point'
    );
    same(
        $expected,
        Request::decodeBody('{"username":"m' . "\u{00FC}" . 'ller"}'),
        'and sent as raw UTF-8 bytes'
    );
});
