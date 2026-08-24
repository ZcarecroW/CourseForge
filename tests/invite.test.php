<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * The invite code that opens the setup screen.
 *
 * A fresh installation is a web application with no accounts and a form that
 * creates the first administrator, which is a race between the person who
 * installed it and whoever finds it first. The code closes that race: it is
 * written to a file on disk and the database keeps only its hash, so proving
 * you can read the file proves you could already read config/. Two properties
 * carry all of that, and both are tested here - a code that does not match is
 * refused, and a code that has been spent cannot be spent again.
 *
 * The rows are written directly rather than through Invite::issue(), which
 * publishes the plain code by writing INVITE-CODE.txt next to index.html. A
 * test has no business rewriting the installation's own invite file, so it
 * makes its own row and points it at the scratch directory instead.
 */

use CourseForge\Security\Invite;
use CourseForge\Support\Db;
use CourseForge\Support\HttpException;

/** The same hash Invite computes, so a row can be written without issuing one. */
function inviteHash(string $code): string
{
    return hash('sha256', 'courseforge-invite:' . Invite::normalise($code));
}

/** @return array{0:string,1:int} the plain code and the row it was written as */
function openInvite(int $expiresAt = 0): array
{
    Db::run('UPDATE invites SET used_at = ?, used_by = ? WHERE used_at = 0', [time(), 'superseded by a test']);

    $code = 'ABCD-EFGH-JKLM-NPQR-STUV-WXYZ';
    Db::run(
        'INSERT INTO invites (code_hash, role, file_path, created_at, expires_at) VALUES (?,?,?,?,?)',
        [inviteHash($code), 'admin', CF_DATA . '/' . Invite::FILE, time(), $expiresAt]
    );
    return [$code, Db::lastId()];
}

test('a code that does not match the open invite is refused', static function (): void {
    [, $id] = openInvite();

    $e = raises(static fn(): array => Invite::verify('ZZZZ-ZZZZ-ZZZZ-ZZZZ-ZZZZ-ZZZZ'), 'a wrong code');
    ok($e instanceof HttpException, 'refused with an HttpException');
    same(403, $e->status(), 'forbidden');
    ok(str_contains($e->getMessage(), Invite::FILE), 'and it says where the real code is');

    $row = Db::row('SELECT used_at FROM invites WHERE id = ?', [$id]) ?? [];
    same(0, (int)($row['used_at'] ?? -1), 'a failed attempt does not spend the invite');
});

test('the right code is accepted however it was typed', static function (): void {
    [$code] = openInvite();

    same('admin', (string)Invite::verify($code)['role'], 'as written');
    same('admin', (string)Invite::verify(strtolower($code))['role'], 'in lower case');
    same('admin', (string)Invite::verify(str_replace('-', '', $code))['role'], 'without the grouping hyphens');
    same('admin', (string)Invite::verify('  ' . $code . '  ')['role'], 'pasted with whitespace around it');
});

test('a consumed code cannot be used a second time', static function (): void {
    [$code, $id] = openInvite();
    $invite = Invite::verify($code);

    // Spending a code is meant to take its file with it - the plain code must
    // not outlive the account it created - and this suite runs inside a real
    // installation, so the file is put back if anything removes it. These days
    // that is Invite::discard(), called once the account is certain, rather
    // than consume() itself, which is inside the transaction.
    $rootFile = CF_ROOT . '/' . Invite::FILE;
    $saved = is_file($rootFile) ? (string)file_get_contents($rootFile) : null;

    try {
        Invite::consume($invite, 'alice');
    } finally {
        if ($saved !== null && !is_file($rootFile)) {
            file_put_contents($rootFile, $saved);
        }
    }

    $row = Db::row('SELECT used_at, used_by FROM invites WHERE id = ?', [$id]) ?? [];
    ok((int)($row['used_at'] ?? 0) > 0, 'the row records when it was spent');
    same('alice', (string)($row['used_by'] ?? ''), 'and who spent it');

    same(
        403,
        raises(static fn(): array => Invite::verify($code), 'a spent code')->status(),
        'the same code is refused the second time'
    );
    same(null, Invite::open(), 'and there is no open invite left');
});

test('an expired invite is not open, and its code is refused', static function (): void {
    [$code] = openInvite(time() - 60);

    same(null, Invite::open(), 'an invite past its expiry is not offered');
    same(403, raises(static fn(): array => Invite::verify($code), 'an expired code')->status(), 'refused');
});

test('a wrong code is refused the same way when there is no invite at all', static function (): void {
    Db::run('UPDATE invites SET used_at = ?, used_by = ? WHERE used_at = 0', [time(), 'closed by a test']);

    // The comparison is spent either way, so "wrong code" and "no invite open"
    // are one answer from the outside rather than two.
    same(
        403,
        raises(static fn(): array => Invite::verify('ABCD-EFGH-JKLM-NPQR-STUV-WXYZ'), 'no invite')->status(),
        'refused'
    );
    same(false, Invite::status()['open'], 'and the status says so plainly');
});
