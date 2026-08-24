<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * The three rules that keep an installation reachable by its owner.
 *
 *   - there is always an administrator. The check that says so used to read the
 *     number of administrators and then write, and two administrators disabling
 *     each other inside that gap both passed it: the installation was left with
 *     none, which can only be repaired by editing app.sqlite by hand. A rule
 *     that a second request walks through is not a rule, so the count is part
 *     of the write now - and the only way to test that is with two processes.
 *   - a session belongs to an account, not to a name. Names are recyclable on
 *     purpose; row ids are not.
 *   - a password an administrator chose for somebody else is good for one
 *     thing: replacing itself.
 *
 * And one thing about upgrades: a 3.x installation keeps its accounts in
 * data/users.json, and the import that lifts them into the table has to happen
 * before anything concludes there are no accounts at all.
 */

use CourseForge\Security\Auth;
use CourseForge\Security\Users;
use CourseForge\Support\Db;
use CourseForge\Support\HttpException;

/** A fresh account with a cheap hash - none of this is about bcrypt. */
function guardAccount(string $name, string $role, int $disabled = 0): int
{
    Db::run('DELETE FROM users WHERE username = ? COLLATE NOCASE', [$name]);
    Db::run(
        'INSERT INTO users (username, display_name, password_hash, role, disabled, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?)',
        [$name, $name, password_hash('a-password-here', PASSWORD_BCRYPT, ['cost' => 4]), $role, $disabled, time(), time()]
    );
    return Db::lastId();
}

/** The message a write was refused with, or '' when it went through. */
function guardRefusal(callable $write): string
{
    try {
        $write();
        return '';
    } catch (HttpException $e) {
        return $e->getMessage();
    }
}

/** Only the accounts this file made, so the rest of the suite is left alone. */
function guardOnlyAdmins(): int
{
    return (int)(Db::row("SELECT COUNT(*) AS n FROM users WHERE username LIKE 'guard-%' AND role = 'admin' AND disabled = 0")['n'] ?? 0);
}

test('the last enabled administrator cannot be demoted, disabled or deleted', static function (): void {
    Db::run("DELETE FROM users WHERE username LIKE 'guard-%'");
    guardAccount('guard-zed', 'admin');
    guardAccount('guard-tess', 'user');

    ok(str_contains(guardRefusal(static fn() => Users::setRole('guard-zed', 'user')), 'only administrator'), 'not demoted');
    ok(str_contains(guardRefusal(static fn() => Users::setDisabled('guard-zed', true)), 'only administrator'), 'not disabled');
    ok(str_contains(guardRefusal(static fn() => Users::delete('guard-zed', 'delete')), 'only administrator'), 'not deleted');

    same('admin', (string)Users::require('guard-zed')['role'], 'and the account is exactly as it was');
    same(0, (int)Users::require('guard-zed')['disabled'], 'still enabled');
});

test('with a second administrator each of them goes through, one at a time', static function (): void {
    Db::run("DELETE FROM users WHERE username LIKE 'guard-%'");
    guardAccount('guard-zed', 'admin');
    guardAccount('guard-alice', 'admin');

    same('', guardRefusal(static fn() => Users::setDisabled('guard-alice', true)), 'alice is disabled');
    // Which leaves zed the last one standing, and the rule applies again.
    ok(str_contains(guardRefusal(static fn() => Users::setDisabled('guard-zed', true)), 'only administrator'), 'zed is not');
    same(1, guardOnlyAdmins(), 'one administrator left');

    // A disabled administrator is not one of the ones being counted, so
    // removing that row is allowed.
    same('', guardRefusal(static fn() => Users::delete('guard-alice', 'delete')), 'a disabled administrator can be deleted');
});

test('two administrators disabling each other at the same instant leave one standing', static function (): void {
    if (!function_exists('proc_open')) {
        ok(true, 'skipped: no proc_open on this build, so two processes cannot be started');
        return;
    }

    // In one process this cannot be tested at all. The defect is a gap between
    // reading how many administrators there are and writing, and a single
    // thread has nothing to slip into it - so two real processes spin on a
    // shared wall-clock instant and arrive together.
    $worker = CF_DATA . '/last-admin-worker.php';
    file_put_contents($worker, <<<'PHP'
        <?php
        declare(strict_types=1);
        putenv('COURSEFORGE_DATA_DIR=' . $argv[1]);
        require $argv[2];
        CourseForge\Support\Db::pdo();      // open and migrate before the barrier
        while (microtime(true) < (float)$argv[4]) {
            // spin: sleeping here would blur the instant the test depends on
        }
        try {
            CourseForge\Security\Users::setDisabled($argv[3], true);
            echo 'OK';
        } catch (Throwable $e) {
            echo 'REFUSED';
        }
        PHP);

    try {
        $answers = [];
        for ($run = 1; $run <= 3; $run++) {
            Db::run("DELETE FROM users WHERE username LIKE 'guard-%'");
            guardAccount('guard-zed', 'admin');
            guardAccount('guard-alice', 'admin');

            $at = microtime(true) + 1.5;
            $processes = [];
            foreach (['guard-alice', 'guard-zed'] as $who) {
                $pipes = [];
                $processes[$who] = [
                    proc_open(
                        [PHP_BINARY, $worker, CF_DATA, CF_ROOT . '/src/bootstrap.php', $who, (string)$at],
                        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                        $pipes
                    ),
                    $pipes,
                ];
            }

            $said = [];
            foreach ($processes as $who => [$handle, $pipes]) {
                $said[$who] = trim((string)stream_get_contents($pipes[1]));
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
                proc_close($handle);
            }
            $answers[] = implode('/', $said);

            same(1, guardOnlyAdmins(), 'run ' . $run . ': an administrator is still enabled');
            ok(in_array('REFUSED', $said, true), 'run ' . $run . ': one of the two was told why not');
        }
        ok(in_array('OK/REFUSED', $answers, true) || in_array('REFUSED/OK', $answers, true), 'and the other went through');
    } finally {
        @unlink($worker);
    }
});

test('a session belongs to the account, not to the name it happens to have', static function (): void {
    $saved = $_SESSION ?? [];

    try {
        Db::run("DELETE FROM users WHERE username LIKE 'guard-%'");
        guardAccount('guard-zed', 'admin');
        $id = guardAccount('guard-tess', 'user');

        // Exactly what Auth::establish() leaves behind.
        $_SESSION = ['user' => 'guard-tess', 'uid' => $id, 'last_seen' => time()];
        same('guard-tess', Auth::current()?->username, 'the session resolves while the account exists');

        Users::delete('guard-tess', 'delete');
        same(null, Auth::current(), 'and stops the moment it is deleted');

        // The name is handed to somebody else - which is what deleting and
        // recreating an account is for - and the old holder must not inherit it.
        Users::create('guard-tess', 'a-completely-different-password', 'admin', 'Someone Else', 'guard-zed');
        same(null, Auth::current(), 'a new account of the same name is not the one this session belongs to');

        $_SESSION = ['user' => 'guard-tess', 'last_seen' => time()];
        same(null, Auth::current(), 'and a session from before the id was stored asks for a sign-in');
    } finally {
        $_SESSION = $saved;
    }
});

test('an account owing a password change says so until it has chosen one', static function (): void {
    Db::run("DELETE FROM users WHERE username LIKE 'guard-%'");
    guardAccount('guard-zed', 'admin');
    Users::setPassword('guard-zed', 'the-one-the-admin-typed', mustChange: true);

    $actor = CourseForge\Security\Actor::make('guard-zed', 'guard-zed', 'admin');
    same(true, Auth::passwordChangeDue($actor), 'the flag is what the front controller reads');

    ok(Users::changePassword('guard-zed', 'the-one-the-admin-typed', 'the-one-only-zed-knows'), 'zed replaces it');
    same(false, Auth::passwordChangeDue($actor), 'and owes nothing after that');
});

test('a 3.x users.json is imported before anything decides there are no accounts', static function (): void {
    // The whole table has to be empty for this, so it is put back afterwards -
    // every other file in this suite shares the database.
    $saved = Db::rows('SELECT * FROM users');
    $file = CF_DATA . '/users.json';
    $imported = $file . '.imported';
    @unlink($imported);

    try {
        Db::run('DELETE FROM users');
        file_put_contents($file, (string)json_encode(['users' => [[
            'username' => 'guard-legacy',
            'display_name' => 'The 3.x administrator',
            'password_hash' => password_hash('legacy-password', PASSWORD_BCRYPT, ['cost' => 4]),
        ]]]));

        same(false, Users::needsSetup(), 'an upgraded installation is not a new one');
        same('admin', (string)Users::require('guard-legacy')['role'], 'the 3.x account could do everything, so it arrives as an administrator');
        ok(Users::verify('guard-legacy', 'legacy-password') !== null, 'and its password still signs it in');
        ok(!is_file($file) && is_file($imported), 'the file is renamed rather than deleted, being the only copy of the hash');
        same(false, Users::needsSetup(), 'asking again imports nothing twice');
    } finally {
        @unlink($file);
        @unlink($imported);
        Db::run('DELETE FROM users');
        foreach ($saved as $row) {
            Db::run(
                'INSERT INTO users (id, username, display_name, password_hash, role, disabled, must_change_password,
                                    created_at, updated_at, last_login_at, created_by, password_reset_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    (int)$row['id'], $row['username'], $row['display_name'], $row['password_hash'], $row['role'],
                    (int)$row['disabled'], (int)($row['must_change_password'] ?? 0), (int)$row['created_at'],
                    (int)$row['updated_at'], (int)$row['last_login_at'], (string)($row['created_by'] ?? ''),
                    (int)($row['password_reset_at'] ?? 0),
                ]
            );
        }
    }
});

test('the tidying up', static function (): void {
    Db::run("DELETE FROM users WHERE username LIKE 'guard-%'");
    ok(true, 'the accounts this file made are gone');
});
