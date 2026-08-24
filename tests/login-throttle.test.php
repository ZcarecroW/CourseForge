<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * What the login throttle counts, and what a sign-in is evidence of.
 *
 * The throttle is the only thing between an administrator's password and
 * somebody typing at it, so what it counts matters more than that it counts.
 * It used to count by address alone and, on a success, forget every failure
 * from that address - so anybody holding any ordinary account could guess four
 * times at the administrator, sign in as themselves to wipe the slate, and
 * repeat for ever. Twenty-four wrong guesses, no lockout. The same call also
 * meant one colleague signing in normally reset the counter for everyone behind
 * an office NAT.
 *
 * So there are two counters now and they are tested as two: the account, which
 * has to hold however many addresses the guesses arrive from, and the address,
 * which is looser because an address is shared. The property that carries all
 * of it is in the first two tests - a success under one name must not be
 * evidence about another.
 */

use CourseForge\Security\Auth;
use CourseForge\Security\LoginThrottle;
use CourseForge\Support\Db;

/**
 * An account with a deliberately cheap hash.
 *
 * These tests spend their time on the counters, and a suite that pays for a
 * cost-12 bcrypt on every wrong guess is a suite nobody runs.
 */
function throttleAccount(string $name, string $password): void
{
    Db::run('DELETE FROM users WHERE username = ? COLLATE NOCASE', [$name]);
    Db::run(
        'INSERT INTO users (username, display_name, password_hash, role, disabled, created_at, updated_at)
         VALUES (?,?,?,?,0,?,?)',
        [$name, $name, password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]), 'user', time(), time()]
    );
}

/** An empty attempt log and a known address, so each test starts from nothing. */
function throttleFrom(string $ip): void
{
    $_SERVER['REMOTE_ADDR'] = $ip;
    Db::run('DELETE FROM login_attempts');
}

/**
 * A sign-in attempt.
 *
 * Auth::establish() regenerates the session id on success, and the runner is a
 * command line with no session and no request to have one - so the notice that
 * produces is suppressed here rather than printed into the middle of the
 * results. Nothing else about the call is suppressed.
 *
 * @return array{ok:bool,error?:string,locked_for?:int,user?:array<string,mixed>}
 */
function throttleLogin(string $username, string $password): array
{
    return @Auth::login($username, $password);
}

test('five wrong guesses lock the account, and the sixth is not even looked at', static function (): void {
    throttleFrom('203.0.113.9');
    throttleAccount('throttle-zed', 'the-real-password');

    for ($i = 1; $i <= 4; $i++) {
        $result = throttleLogin('throttle-zed', 'guess-' . $i);
        same(0, (int)($result['locked_for'] ?? -1), 'guess ' . $i . ' is answered, not locked');
    }

    $fifth = throttleLogin('throttle-zed', 'guess-5');
    ok((int)($fifth['locked_for'] ?? 0) > 0, 'the fifth failure locks the account');

    $sixth = throttleLogin('throttle-zed', 'the-real-password');
    same(false, $sixth['ok'], 'and the sixth attempt is refused');
    ok(str_contains((string)$sixth['error'], 'Too many failed attempts'), 'with the lockout message');
    ok(
        (int)(Db::row('SELECT COUNT(*) AS n FROM login_attempts WHERE ok = 1')['n'] ?? 0) === 0,
        'the correct password was never checked, so a lockout cannot be probed with one'
    );
});

test('signing in as one account does not clear the guesses made at another', static function (): void {
    throttleFrom('203.0.113.9');
    throttleAccount('throttle-zed', 'the-real-password');
    throttleAccount('throttle-tess', 'tess-own-password');

    // The loop somebody holding any ordinary account would run.
    for ($round = 1; $round <= 3; $round++) {
        for ($i = 1; $i <= 4; $i++) {
            throttleLogin('throttle-zed', 'round-' . $round . '-guess-' . $i);
        }
        same(true, throttleLogin('throttle-tess', 'tess-own-password')['ok'], 'tess signs in for real');
    }

    ok(
        LoginThrottle::lockoutRemaining('203.0.113.9', 'throttle-zed') > 0,
        'twelve guesses at the administrator have locked the administrator, whoever else signed in'
    );
    same(
        0,
        LoginThrottle::lockoutRemaining('203.0.113.9', 'throttle-tess'),
        'and tess, who has done nothing wrong, is not locked out by them'
    );
});

test('signing in does forget that account\'s own failures at that address', static function (): void {
    throttleFrom('203.0.113.10');
    throttleAccount('throttle-tess', 'tess-own-password');

    for ($i = 1; $i <= 4; $i++) {
        throttleLogin('throttle-tess', 'nearly-' . $i);
    }
    same(4, LoginThrottle::failuresInWindow('203.0.113.10', 'throttle-tess'), 'four false starts');

    same(true, throttleLogin('throttle-tess', 'tess-own-password')['ok'], 'then she remembers it');
    same(
        0,
        LoginThrottle::failuresInWindow('203.0.113.10', 'throttle-tess'),
        'and is not one wrong password away from a lockout for the next quarter of an hour'
    );
});

test('an account is locked however many addresses the guessing comes from', static function (): void {
    throttleFrom('198.51.100.1');
    throttleAccount('throttle-zed', 'the-real-password');

    // A botnet is exactly this: five guesses, five machines, one password.
    foreach (['198.51.100.1', '198.51.100.2', '198.51.100.3', '198.51.100.4', '198.51.100.5'] as $i => $ip) {
        $_SERVER['REMOTE_ADDR'] = $ip;
        throttleLogin('throttle-zed', 'from-node-' . $i);
    }

    $_SERVER['REMOTE_ADDR'] = '198.51.100.6';
    $next = throttleLogin('throttle-zed', 'the-real-password');
    same(false, $next['ok'], 'the sixth node is refused as well');
    ok(str_contains((string)$next['error'], 'Too many failed attempts'), 'because the account is what is locked');
});

test('one address may not work through the whole account list', static function (): void {
    throttleFrom('198.51.100.77');
    throttleAccount('throttle-tess', 'tess-own-password');

    // Four each at five names: no single account reaches five, so only the
    // address counter can catch this.
    for ($name = 1; $name <= 5; $name++) {
        for ($i = 1; $i <= 4; $i++) {
            throttleLogin('victim-' . $name, 'guess-' . $i);
        }
    }

    ok(LoginThrottle::lockoutRemaining('198.51.100.77') > 0, 'twenty failures from one address lock the address');
    same(
        false,
        throttleLogin('throttle-tess', 'tess-own-password')['ok'],
        'and nothing more is accepted from it, right password or not'
    );
    same(
        0,
        LoginThrottle::lockoutRemaining('203.0.113.99', 'throttle-tess'),
        'while an account nobody guessed at is untouched everywhere else'
    );
});

test('how loose the address counter is, is an administrator\'s decision', static function (): void {
    // It used to be a constant four times the per-account cap, which is a
    // number nobody could see and nobody could reason about: an administrator
    // who raised the account cap to ten silently got forty at the address.
    $shipped = CourseForge\Support\Config::get('security.max_address_attempts');

    try {
        CourseForge\Support\Config::set('security.max_address_attempts', 6);
        throttleFrom('198.51.100.120');
        for ($name = 1; $name <= 3; $name++) {
            for ($i = 1; $i <= 2; $i++) {
                throttleLogin('victim-' . $name, 'guess-' . $i);
            }
        }
        ok(LoginThrottle::lockoutRemaining('198.51.100.120') > 0, 'six failures lock the address when six is the cap');

        CourseForge\Support\Config::set('security.max_address_attempts', 20);
        same(
            0,
            LoginThrottle::lockoutRemaining('198.51.100.120'),
            'and the same six do not when twenty is - the setting is what decided it, not the failures'
        );

        // Below the per-account cap the address would lock first, and the
        // per-account allowance the other setting promises could never be
        // reached. The looser counter stays the looser one.
        CourseForge\Support\Config::set('security.max_login_attempts', 10);
        CourseForge\Support\Config::set('security.max_address_attempts', 2);
        throttleFrom('198.51.100.121');
        for ($i = 1; $i <= 4; $i++) {
            throttleLogin('throttle-zed', 'guess-' . $i);
        }
        same(0, LoginThrottle::lockoutRemaining('198.51.100.121'), 'two is not used as two when the account cap is ten');
    } finally {
        CourseForge\Support\Config::set('security.max_login_attempts', 5);
        $shipped === null
            ? CourseForge\Support\Config::reset('security.max_address_attempts')
            : CourseForge\Support\Config::set('security.max_address_attempts', $shipped);
        Db::run('DELETE FROM login_attempts');
    }
});

test('an attempt recorded against no account cannot lock one out', static function (): void {
    throttleFrom('198.51.100.88');
    throttleAccount('throttle-zed', 'the-real-password');

    // This is how a wrong invite code is recorded: it costs the address, and it
    // names nobody, so typing "zed" into that form is not a way to lock zed out.
    for ($i = 1; $i <= 10; $i++) {
        LoginThrottle::record('198.51.100.88', '', false);
    }

    same(0, LoginThrottle::lockoutRemaining('198.51.100.99', 'throttle-zed'), 'the account is not locked');
    same(0, LoginThrottle::failuresInWindow('198.51.100.88', 'throttle-zed'), 'and none of it counted against it');
});

test('the tidying up', static function (): void {
    Db::run('DELETE FROM login_attempts');
    Db::run("DELETE FROM users WHERE username LIKE 'throttle-%'");
    unset($_SERVER['REMOTE_ADDR']);
    ok(true, 'the accounts and the attempt log this file made are gone');
});
