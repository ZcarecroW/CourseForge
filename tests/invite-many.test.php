<?php
/**
 * Several invites open at once.
 *
 * Until 5.2 one invite was open at a time, because the plain code lived in a
 * file and a file holds one code. An invite an administrator issues from the
 * app is now shown once and written to no file, so there can be one for each
 * group of people being let in - and each has to open its own door and only
 * its own.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use CourseForge\Security\Actor;
use CourseForge\Security\Invite;
use CourseForge\Support\Db;
use CourseForge\Support\HttpException;

/** Closes every open invite, so each test starts with none. */
function closeInvites(): void
{
    Db::run('UPDATE invites SET used_at = ?, used_by = ? WHERE used_at = 0', [time(), 'superseded by a test']);
}

test('two invites issued from the app are both open, and each code opens only its own', static function (): void {
    closeInvites();
    $team = Invite::issue(Actor::ROLE_USER, 24, 'admin', 5, 'the marketing team');
    $boss = Invite::issue(Actor::ROLE_ADMIN, 2, 'admin', 1, 'a second administrator');

    $status = Invite::status();
    same(true, $status['open'], 'invites are open');
    same(2, (int)$status['count'], 'two of them');
    same(['a second administrator', 'the marketing team'], array_map(static fn(array $i): string => $i['label'], $status['invites']), 'newest first, each with its label');

    same((int)$team['id'], (int)Invite::verify($team['code'], Invite::AUDIENCE_HOLDER)['id'], 'the team code opens the team invite');
    same('user', (string)Invite::verify($team['code'], Invite::AUDIENCE_HOLDER)['role'], 'with its role');
    same((int)$boss['id'], (int)Invite::verify($boss['code'], Invite::AUDIENCE_HOLDER)['id'], 'the other code opens the other');
    same('admin', (string)Invite::verify($boss['code'], Invite::AUDIENCE_HOLDER)['role'], 'and carries the role written on it');

    same('', $team['path'], 'neither was written to a file');
    ok(!is_file(CF_DATA . '/' . Invite::FILE) || (string)file_get_contents(CF_DATA . '/' . Invite::FILE) !== '', 'the data directory holds no invite file for them');
});

test('revoking one invite leaves the others open', static function (): void {
    closeInvites();
    $one = Invite::issue(Actor::ROLE_USER, 24, 'admin', 1, 'one');
    $two = Invite::issue(Actor::ROLE_USER, 24, 'admin', 1, 'two');

    $revoked = Invite::revoke((int)$one['id']);
    same((int)$one['id'], (int)$revoked['id'], 'the named one is revoked');
    same(403, raises(static fn(): array => Invite::verify($one['code']), 'the revoked code')->status(), 'and its code is refused');
    same((int)$two['id'], (int)Invite::verify($two['code'])['id'], 'while the other still works');
    same(1, (int)Invite::status()['count'], 'one remains');

    same(null, Invite::revoke((int)$one['id']), 'revoking it again finds nothing');
    same((int)$two['id'], (int)Invite::revoke()['id'], 'and revoking without a name takes the newest');
    same(false, Invite::status()['open'], 'nothing is left');
});

test('spending one invite does not touch another', static function (): void {
    closeInvites();
    $one = Invite::issue(Actor::ROLE_USER, 24, 'admin', 1, 'one');
    $two = Invite::issue(Actor::ROLE_USER, 24, 'admin', 2, 'two');

    ok(Invite::consume(Invite::verify($one['code']), 'alice'), 'alice spends the first');
    same(403, raises(static fn(): array => Invite::verify($one['code']), 'a spent code')->status(), 'which is now closed');
    $second = Invite::verify($two['code']);
    same(2, (int)$second['max_uses'] - (int)$second['uses'], 'the second still has both its places');
});

test('the first-run invite alone goes to a file, and only it is superseded by another first-run one', static function (): void {
    closeInvites();
    $app = Invite::issue(Actor::ROLE_USER, 24, 'admin', 1, 'from the app');
    $first = Invite::issue(Actor::ROLE_ADMIN, 0, 'first start', 1, '', true);
    ok($first['path'] !== '' && is_file($first['path']), 'the first-run invite is written to a file');
    ok(str_contains((string)file_get_contents($first['path']), $first['code']), 'holding its code');

    $again = Invite::issue(Actor::ROLE_ADMIN, 0, 'first start', 1, '', true);
    same(403, raises(static fn(): array => Invite::verify($first['code']), 'the earlier first-run code')->status(), 'a second first-run invite supersedes the first');
    same((int)$app['id'], (int)Invite::verify($app['code'])['id'], 'but the one issued from the app is untouched');

    Invite::revoke((int)$again['id']);
    Invite::revoke((int)$app['id']);
    @unlink($first['path']);
    @unlink($again['path']);
});

test('a label is kept short, and there is a ceiling on how many may be open', static function (): void {
    closeInvites();
    $long = Invite::issue(Actor::ROLE_USER, 24, 'admin', 1, str_repeat('x', 200));
    same(Invite::MAX_LABEL, mb_strlen($long['label']), 'a long label is cut');

    for ($i = 1; $i < Invite::MAX_OPEN; $i++) {
        Invite::issue(Actor::ROLE_USER, 24, 'admin', 1, 'invite ' . $i);
    }
    same(422, raises(static fn(): array => Invite::issue(Actor::ROLE_USER, 24, 'admin', 1, 'one too many'), 'past the ceiling')->status(), 'one more is refused');
    same(Invite::MAX_OPEN, Invite::revokeAll(), 'and they can all be taken back at once');
});

closeInvites();
