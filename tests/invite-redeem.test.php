<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Turning an invite code into an account.
 *
 * The Accounts screen has promised this since 4.0 - "an invite is a one-off
 * code that lets a person create their own account, choosing their own
 * password" - and until now there was nowhere to type one: the only route that
 * looked at an invite was the first-run setup form, which refuses outright once
 * an account exists. So the code an administrator handed out could not be
 * redeemed by anybody, ever.
 *
 * Now it can, which makes this the one place in the application where an
 * anonymous request creates an account, so the tests here are mostly about what
 * a stolen code cannot do. Two properties carry it: the role is the one written
 * on the invite row rather than anything the request asks for, and one code is
 * one account even when two requests hold it at the same instant - two
 * simultaneous setups used to produce two administrators from a single one-shot
 * code.
 */

use CourseForge\Api\SetupController;
use CourseForge\Security\Actor;
use CourseForge\Security\Invite;
use CourseForge\Security\Users;
use CourseForge\Support\Db;
use CourseForge\Support\HttpException;
use CourseForge\Support\Request;

/**
 * An open invite of a given role, written straight in.
 *
 * Not through Invite::issue(), which publishes the plain code by writing
 * INVITE-CODE.txt next to index.html: this suite runs inside a real
 * installation and has no business rewriting its invite file. The file the row
 * points at is in the scratch directory instead.
 *
 * @return string the plain code
 */
function redeemOpenInvite(string $role, int $expiresAt = 0, int $maxUses = 1): string
{
    Db::run('UPDATE invites SET used_at = ?, used_by = ? WHERE used_at = 0', [time(), 'superseded by a test']);

    $code = 'RDMA-RDMB-RDMC-RDMD-RDME-RDMF';
    Db::run(
        'INSERT INTO invites (code_hash, role, file_path, created_at, expires_at, issued_by, max_uses) '
            . 'VALUES (?,?,?,?,?,?,?)',
        [
            hash('sha256', 'courseforge-invite:' . Invite::normalise($code)),
            $role,
            CF_DATA . '/' . Invite::FILE,
            time(),
            $expiresAt,
            'a test',
            $maxUses,
        ]
    );
    file_put_contents(CF_DATA . '/' . Invite::FILE, "not the real one\n");
    return $code;
}

/**
 * An installation that already has an account, which is the state redeem() is
 * for - and which every test here but the last one has to establish for itself,
 * since the first account on an empty installation is an administrator whatever
 * its invite says.
 */
function redeemInstallationWithAccounts(): void
{
    Db::run("DELETE FROM users WHERE username LIKE 'redeem-%'");
    if (Db::row('SELECT id FROM users LIMIT 1') !== null) {
        return;
    }
    Db::run(
        'INSERT INTO users (username, display_name, password_hash, role, disabled, created_at, updated_at)
         VALUES (?,?,?,?,0,?,?)',
        ['redeem-owner', 'redeem-owner', password_hash('a-password-here', PASSWORD_BCRYPT, ['cost' => 4]), 'admin', time(), time()]
    );
}

/** A Request carrying a body, the shape the front controller hands a handler. */
function redeemRequest(array $body, string $path = 'redeem'): Request
{
    $class = new ReflectionClass(Request::class);
    $request = $class->newInstanceWithoutConstructor();
    foreach (['method' => 'POST', 'path' => $path, 'body' => $body, 'params' => []] as $key => $value) {
        $class->getProperty($key)->setValue($request, $value);
    }
    return $request;
}

/**
 * Redemption, with the two things the command line cannot supply worked around.
 *
 * Auth::establish() regenerates the session id, which needs a session, and
 * Invite::discard() deletes the installation's own INVITE-CODE.txt by design -
 * the plain code must not outlive the account it made. The notice is
 * suppressed, and the file is put back afterwards.
 *
 * @return array<string,mixed>|string the answer, or the message it was refused with
 */
function redeemAttempt(array $body, ?Actor $actor = null, string $route = 'redeem'): array|string
{
    $root = CF_ROOT . '/' . Invite::FILE;
    $saved = is_file($root) ? (string)file_get_contents($root) : null;

    try {
        return @SetupController::$route(redeemRequest($body, $route), $actor);
    } catch (HttpException $e) {
        return $e->getMessage();
    } finally {
        if ($saved !== null && !is_file($root)) {
            file_put_contents($root, $saved);
        }
        $_SESSION = [];
    }
}

test('an invite an administrator issued becomes the account it names', static function (): void {
    redeemInstallationWithAccounts();
    $code = redeemOpenInvite(Actor::ROLE_USER);

    $answer = redeemAttempt([
        'invite_code' => $code,
        'username' => 'redeem-newcomer',
        'password' => 'a-password-only-they-know',
        'display_name' => 'The Newcomer',
    ]);

    ok(is_array($answer), 'the code is accepted: ' . (is_string($answer) ? $answer : 'created'));
    $row = Users::require('redeem-newcomer');
    same('user', (string)$row['role'], 'with the role the invite was issued for');
    same('The Newcomer', (string)$row['display_name'], 'and the name they chose to be known by');
    same(0, (int)$row['must_change_password'], 'nobody else has seen this password, so there is nothing to change');
    same('invite', (string)$row['created_by'], 'and the row says where the account came from');
    ok(Users::verify('redeem-newcomer', 'a-password-only-they-know') !== null, 'the password works');
    same(false, Invite::status()['open'], 'the invite is spent');
});

test('the invite decides the role, whatever the request asks for', static function (): void {
    redeemInstallationWithAccounts();
    $code = redeemOpenInvite(Actor::ROLE_USER);

    redeemAttempt([
        'invite_code' => $code,
        'username' => 'redeem-eve',
        'password' => 'a-password-only-they-know',
        'role' => 'admin',
        'is_admin' => true,
    ]);

    same('user', (string)Users::require('redeem-eve')['role'], 'a stolen code cannot ask for more than it was given');
});

test('an invite issued for an administrator does make one', static function (): void {
    redeemInstallationWithAccounts();
    $code = redeemOpenInvite(Actor::ROLE_ADMIN);

    redeemAttempt(['invite_code' => $code, 'username' => 'redeem-deputy', 'password' => 'a-password-only-they-know']);
    same('admin', (string)Users::require('redeem-deputy')['role'], 'because that is what the administrator issued');
});

test('one code is one account', static function (): void {
    redeemInstallationWithAccounts();
    $code = redeemOpenInvite(Actor::ROLE_USER);

    redeemAttempt(['invite_code' => $code, 'username' => 'redeem-first', 'password' => 'a-password-only-they-know']);
    $second = redeemAttempt(['invite_code' => $code, 'username' => 'redeem-second', 'password' => 'a-password-only-they-know']);

    ok(is_string($second), 'the second attempt is refused');
    same(null, Users::find('redeem-second'), 'and no account came of it');

    // The invariant underneath, which is what makes two simultaneous requests
    // safe as well: spending is conditional on the row still being open, so the
    // second writer is told it lost rather than overwriting the first.
    $invite = Db::row('SELECT * FROM invites ORDER BY id DESC LIMIT 1') ?? [];
    same(false, Invite::consume($invite, 'redeem-third'), 'a spent invite cannot be spent again');
    same('redeem-first', (string)(Db::row('SELECT used_by FROM invites WHERE id = ?', [(int)$invite['id']])['used_by'] ?? ''), 'and it still records who spent it');
});

test('a request that is refused does not burn the invite', static function (): void {
    redeemInstallationWithAccounts();
    $code = redeemOpenInvite(Actor::ROLE_USER);

    ok(is_string(redeemAttempt(['invite_code' => $code, 'username' => 'redeem-hopeful', 'password' => 'short'])), 'too short');
    ok(is_string(redeemAttempt(['invite_code' => $code, 'username' => '', 'password' => 'a-password-only-they-know'])), 'no name');
    ok(
        is_string(redeemAttempt([
            'invite_code' => $code,
            'username' => 'redeem-hopeful',
            'password' => 'a-password-only-they-know',
            'password_confirm' => 'something-else-entirely',
        ])),
        'two passwords that disagree'
    );
    same(true, Invite::status()['open'], 'and after all that the code still works');

    ok(is_array(redeemAttempt(['invite_code' => $code, 'username' => 'redeem-hopeful', 'password' => 'a-password-only-they-know'])), 'as it should');
    same(false, Invite::status()['open'], 'now it is spent');
});

test('a wrong code says nothing about the installation', static function (): void {
    redeemInstallationWithAccounts();
    redeemOpenInvite(Actor::ROLE_USER);
    Db::run('DELETE FROM login_attempts');

    $refusal = redeemAttempt([
        'invite_code' => 'ZZZZ-ZZZZ-ZZZZ-ZZZZ-ZZZZ-ZZZZ',
        'username' => 'redeem-zed',
        'password' => 'a-password-only-they-know',
    ]);
    // Deliberately NOT the path to INVITE-CODE.txt. That is the right answer on
    // the first-run screen, where the person typing installed the software and
    // can open the file. Here they were sent a code and have no account, no
    // shell and no reason to have heard of it - naming it sends them after
    // something they cannot reach and tells them where a secret lives on a
    // machine they have no business on.
    ok(is_string($refusal), 'the refusal is a message');
    ok(!str_contains((string)$refusal, Invite::FILE), 'it does not point a code holder at a file on the server');
    ok(
        str_contains((string)$refusal, 'not valid'),
        'it says the code is wrong, which is the whole of what the holder can act on'
    );
    same(true, Invite::status()['open'], 'the open invite is untouched');

    $attempt = Db::row('SELECT ip, username FROM login_attempts ORDER BY id DESC LIMIT 1') ?? [];
    same('', (string)($attempt['username'] ?? 'nothing recorded'), 'the guess counts against the address and against no account');
});

test('somebody already signed in is not offered a second account', static function (): void {
    redeemInstallationWithAccounts();
    $code = redeemOpenInvite(Actor::ROLE_ADMIN);

    $refusal = redeemAttempt(
        ['invite_code' => $code, 'username' => 'redeem-alter-ego', 'password' => 'a-password-only-they-know'],
        Actor::make('somebody', 'Somebody', Actor::ROLE_USER)
    );
    ok(is_string($refusal) && str_contains($refusal, 'already signed in'), 'refused');
    same(null, Users::find('redeem-alter-ego'), 'no account');
    same(true, Invite::status()['open'], 'and the invite is still there for whoever it was meant for');
});

test('the first-run door makes an administrator, and closes behind itself', static function (): void {
    // Needs an installation with no accounts at all, so the table is emptied
    // and put back: every other file in this suite shares the database.
    $saved = Db::rows('SELECT * FROM users');

    try {
        Db::run('DELETE FROM users');

        // Even from an invite issued for a plain user: an installation whose
        // only account cannot manage accounts is one nobody can repair.
        $code = redeemOpenInvite(Actor::ROLE_USER);
        $answer = redeemAttempt(
            ['invite_code' => $code, 'username' => 'redeem-installer', 'password' => 'a-password-only-they-know'],
            null,
            'create'
        );
        ok(is_array($answer), 'setup goes through');
        same('admin', (string)Users::require('redeem-installer')['role'], 'and the first account is an administrator');

        $code = redeemOpenInvite(Actor::ROLE_ADMIN);
        $second = redeemAttempt(
            ['invite_code' => $code, 'username' => 'redeem-latecomer', 'password' => 'a-password-only-they-know'],
            null,
            'create'
        );
        ok(is_string($second) && str_contains($second, 'already has accounts'), 'the door has closed');
        same(null, Users::find('redeem-latecomer'), 'so nobody slipped in through it');

        // The other door is open, though, which is the point of having two.
        ok(
            is_array(redeemAttempt(['invite_code' => $code, 'username' => 'redeem-latecomer', 'password' => 'a-password-only-they-know'])),
            'the same code redeems as an ordinary invite'
        );
        same('admin', (string)Users::require('redeem-latecomer')['role'], 'as the administrator it was issued for');

        // And the same rule holds through that door: an installation with no
        // accounts must not end up with one that cannot make the next.
        Db::run('DELETE FROM users');
        $code = redeemOpenInvite(Actor::ROLE_USER);
        ok(
            is_array(redeemAttempt(['invite_code' => $code, 'username' => 'redeem-only-one', 'password' => 'a-password-only-they-know'])),
            'an invite for a plain user, redeemed on an empty installation'
        );
        same('admin', (string)Users::require('redeem-only-one')['role'], 'makes an administrator, because somebody has to be one');
    } finally {
        Db::run('DELETE FROM users');
        foreach ($saved as $row) {
            Db::run(
                'INSERT INTO users (id, username, display_name, password_hash, role, disabled, must_change_password,
                                    created_at, updated_at, last_login_at, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [
                    (int)$row['id'], $row['username'], $row['display_name'], $row['password_hash'], $row['role'],
                    (int)$row['disabled'], (int)($row['must_change_password'] ?? 0), (int)$row['created_at'],
                    (int)$row['updated_at'], (int)$row['last_login_at'], (string)($row['created_by'] ?? ''),
                ]
            );
        }
    }
});

test('the tidying up', static function (): void {
    Db::run("DELETE FROM users WHERE username LIKE 'redeem-%'");
    Db::run('UPDATE invites SET used_at = ?, used_by = ? WHERE used_at = 0', [time(), 'closed by a test']);
    Db::run('DELETE FROM login_attempts');
    @unlink(CF_DATA . '/' . Invite::FILE);
    ok(!is_file(CF_DATA . '/' . Invite::FILE), 'the scratch invite file, the accounts and the open invite are gone');
});

/* ------------------------------------------------- one code, several people */

test('one code makes as many accounts as it was issued for, and no more', static function (): void {
    redeemInstallationWithAccounts();
    $code = redeemOpenInvite(Actor::ROLE_USER, 0, 3);
    $file = CF_DATA . '/' . Invite::FILE;

    foreach (['redeem-one', 'redeem-two', 'redeem-three'] as $index => $username) {
        $answer = redeemAttempt([
            'invite_code' => $code,
            'username' => $username,
            'password' => 'a-long-enough-password',
        ]);
        ok(is_array($answer), $username . ' was let in');
        same($username, (string)Users::require($username)['username'], 'and has an account');
        // The role on the row is what every redeemer gets, not just the first.
        same(Actor::ROLE_USER, (string)Users::require($username)['role'], 'with the role the invite carries');

        $open = Invite::status()['open'];
        same($index < 2, $open, $index < 2 ? 'places are left' : 'and the last one closes it');
    }

    // The file is the only place the plain code exists, so it has to outlive
    // every redemption but the last.
    same(false, is_file($file), 'the file goes with the last place');

    $refused = redeemAttempt([
        'invite_code' => $code,
        'username' => 'redeem-four',
        'password' => 'a-long-enough-password',
    ]);
    ok(is_string($refused), 'a fourth is refused');
    same(null, Users::find('redeem-four'), 'and no account was made for them');
});

test('the file outlives a redemption that leaves places behind', static function (): void {
    redeemInstallationWithAccounts();
    $code = redeemOpenInvite(Actor::ROLE_USER, 0, 2);
    $file = CF_DATA . '/' . Invite::FILE;

    ok(is_array(redeemAttempt([
        'invite_code' => $code,
        'username' => 'redeem-keeps-file',
        'password' => 'a-long-enough-password',
    ])), 'the first of two is let in');

    ok(is_file($file), 'the code is still readable, because somebody else still needs it');
    same(1, (int)Invite::status()['uses_left'], 'and one place is left');
});
