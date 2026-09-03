<?php
/**
 * The lock on secrets while the server has not been shown to keep them.
 *
 * The verdict itself is taken over HTTP against the running server, which a
 * unit test cannot do; what is tested here is everything around it - what a
 * verdict means for the gate, how an administrator gets past it, and that the
 * doors a secret can arrive through actually ask.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use CourseForge\Domain\Profiles;
use CourseForge\Security\Hardening;
use CourseForge\Security\Users;
use CourseForge\Support\Meta;

/** Pretends a verdict was taken. */
function verdict(string $verdict): void
{
    Meta::set(Hardening::META_CHECK, (string)json_encode([
        'at' => time(),
        'verdict' => $verdict,
        'reason' => 'a test said so',
        'probes' => [],
        'server' => ['family' => 'nginx'],
        'base_url' => 'https://test.example',
    ]));
}

function clearVerdict(): void
{
    Hardening::forget();
    Hardening::revokeAcknowledgement();
}

Hardening::$enforceInCli = true;

test('with no verdict on record the lock holds', static function (): void {
    clearVerdict();
    same(true, Hardening::locked(), 'nothing is known, so nothing may be stored');
    same(Hardening::VERDICT_UNKNOWN, Hardening::status()['verdict'], 'and the status says why');
    $e = raises(static fn() => Hardening::assertSecretsWritable(), 'the gate');
    same(422, $e->status(), 'refused as unprocessable');
    ok(str_contains($e->getMessage(), 'Security'), 'pointing at the screen that takes the verdict');
});

test('a secure verdict opens the gate, an exposed one closes it', static function (): void {
    verdict(Hardening::VERDICT_SECURE);
    same(false, Hardening::locked(), 'secure opens it');
    Hardening::assertSecretsWritable();

    verdict(Hardening::VERDICT_EXPOSED);
    same(true, Hardening::locked(), 'exposed closes it');
    ok(str_contains(raises(static fn() => Hardening::assertSecretsWritable(), 'the gate')->getMessage(), 'hands out'), 'and says the server serves the files');

    verdict(Hardening::VERDICT_UNVERIFIED);
    same(true, Hardening::locked(), 'unverified closes it too');
    same(true, Hardening::status()['stale'] === false, 'a fresh verdict is not stale');
    clearVerdict();
});

test('an acknowledgement opens the gate, is recorded, and can be taken back', static function (): void {
    verdict(Hardening::VERDICT_EXPOSED);
    Hardening::acknowledge('root');
    same(false, Hardening::locked(), 'accepted, so open');
    $ack = Hardening::status()['acknowledged'];
    same('root', (string)$ack['by'], 'by whom');
    same(Hardening::VERDICT_EXPOSED, (string)$ack['verdict'], 'and against which verdict');

    Hardening::revokeAcknowledgement();
    same(true, Hardening::locked(), 'withdrawn, so closed again');
    clearVerdict();
});

test('the confirmation code has to be the one shown, and it goes stale', static function (): void {
    $_SESSION = [];
    same(false, Hardening::codeMatches('abc123'), 'nothing shown, nothing matches');

    $_SESSION['security_code'] = ['code' => 'jG7c4K', 'expires' => time() + 60];
    same(true, Hardening::codeMatches(' jG7c4K '), 'the code, with the whitespace somebody pasted');
    same(false, Hardening::codeMatches('jg7c4k'), 'but not in the wrong case');
    same(false, Hardening::codeMatches('jG7c4'), 'nor cut short');

    $_SESSION['security_code'] = ['code' => 'jG7c4K', 'expires' => time() - 1];
    same(false, Hardening::codeMatches('jG7c4K'), 'nor after it has expired');
    $_SESSION = [];
});

test('a profile with a key is refused while the lock holds, and one without goes through', static function (): void {
    clearVerdict();
    $withKey = ['ai' => [['id' => 'a1', 'name' => 'x', 'base_url' => 'https://api.example/v1', 'api_key' => 'sk-secret']]];
    $e = raises(static fn() => Profiles::create('hard', 'locked', $withKey), 'a key while locked');
    same(422, $e->status(), 'refused');

    $plain = Profiles::create('hard', 'no secret', ['ai' => [['id' => 'a1', 'name' => 'x', 'base_url' => 'https://api.example/v1', 'api_key' => '']]]);
    ok((int)$plain['id'] > 0, 'a profile without a secret is stored');
    raises(static fn() => Profiles::update('hard', (int)$plain['id'], 'no secret', $withKey), 'adding the key later is refused too');

    $token = ['bookstack' => [['id' => 'w', 'name' => 'w', 'base_url' => 'https://wiki.example', 'token_id' => 'i', 'token_secret' => 's']]];
    raises(static fn() => Profiles::update('hard', (int)$plain['id'], 'no secret', $token), 'and so is a BookStack token');

    verdict(Hardening::VERDICT_SECURE);
    $kept = Profiles::update('hard', (int)$plain['id'], 'now allowed', $withKey);
    same(true, (bool)Profiles::redact($kept)['data']['ai'][0]['api_key_set'], 'once the verdict is secure the key is stored');
    clearVerdict();
});

test('the probe list asks for every file that holds a secret', static function (): void {
    $paths = array_map(static fn(array $p): string => $p['path'], Hardening::probeList(true));
    ok(in_array('data/' . Hardening::PROBE_FILE, $paths, true), 'the probe file itself');
    ok(in_array('data/app.sqlite', $paths, true), 'the database');
    ok(in_array('data/.htaccess', $paths, true), 'the deny file');
    ok(in_array('config/defaults.json', $paths, true), 'the configuration');
    $critical = array_filter(Hardening::probeList(true), static fn(array $p): bool => $p['critical']);
    ok(count($critical) >= 4, 'and all of those decide the verdict');
});

test('an account remembers that it has been shown round', static function (): void {
    $user = Users::create('tourist', 'a-long-enough-password', 'user');
    same(false, (bool)$user['tour_seen'], 'not yet');
    Users::markTourSeen('tourist');
    same(true, (bool)Users::publicView(Users::require('tourist'))['tour_seen'], 'and then yes');
    Users::delete('tourist', 'delete');
});

Hardening::$enforceInCli = false;
clearVerdict();
