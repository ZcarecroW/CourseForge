<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * What a connection token survives, and what it does not.
 *
 * The browser and the MCP endpoint are two front doors to the same
 * installation, and a rule enforced at one of them is not a rule. Two of them
 * were only at the browser door:
 *
 *   - an account holding a password an administrator chose for it may find out
 *     who it is, replace that password, and nothing else. The Vue app opened a
 *     dialog it would not close; curl and every MCP client walked past it.
 *   - resetting a password is how somebody is cut off, and it did not cut off
 *     the connections they had already made. An account could be locked out of
 *     the browser and carry on doing the whole of CourseForge through a token
 *     minted the day before.
 *
 * The second one turns on a timestamp, and the tests below are mostly about
 * which timestamp. `updated_at` moves when somebody edits their display name,
 * so revoking on that would take every connection on the installation down the
 * first time anybody tidied up their profile; `password_reset_at` moves only
 * when a password is set by somebody other than its owner. The difference
 * between those two is the whole fix, so it is what is measured.
 */

use CourseForge\Domain\McpClients;
use CourseForge\Mcp\Server;
use CourseForge\Security\Actor;
use CourseForge\Security\Users;
use CourseForge\Support\Db;

/**
 * An account with a cheap hash - none of this is about bcrypt.
 *
 * Made an hour ago, so that a connection created "before" something in these
 * tests is still comfortably after the account itself.
 */
function revokeAccount(string $name, string $role = 'user'): void
{
    $made = time() - 3600;
    Db::run('DELETE FROM mcp_clients WHERE username = ? COLLATE NOCASE', [$name]);
    Db::run('DELETE FROM users WHERE username = ? COLLATE NOCASE', [$name]);
    Db::run(
        'INSERT INTO users (username, display_name, password_hash, role, disabled, created_at, updated_at, password_reset_at)
         VALUES (?,?,?,?,0,?,?,?)',
        [$name, $name, password_hash('a-password-here', PASSWORD_BCRYPT, ['cost' => 4]), $role, $made, $made, $made]
    );
}

/** A connection, backdated so "before the reset" and "after it" are tellable apart. */
function revokeConnection(string $owner, int $agoSeconds = 0): string
{
    $made = McpClients::create($owner, 'connection ' . $agoSeconds);
    if ($agoSeconds > 0) {
        Db::run(
            'UPDATE mcp_clients SET created_at = ? WHERE id = ?',
            [time() - $agoSeconds, (int)$made['client']['id']]
        );
    }
    return $made['token'];
}

test('a password an administrator sets revokes the connections made before it', static function (): void {
    revokeAccount('revoke-tess');
    $before = revokeConnection('revoke-tess', 60);

    ok(McpClients::resolve($before) !== null, 'the token works while the password it was made under is current');

    Users::setPassword('revoke-tess', 'the-one-the-admin-typed', mustChange: true);

    same(null, McpClients::resolve($before), 'and stops the moment somebody else sets that password');

    $after = revokeConnection('revoke-tess');
    ok(McpClients::resolve($after) !== null, 'while a connection made since the reset is fine');
});

test('a display name change revokes nothing', static function (): void {
    revokeAccount('revoke-tess');
    $token = revokeConnection('revoke-tess', 60);

    Users::setDisplayName('revoke-tess', 'Tess, with the spelling she prefers');

    $context = McpClients::resolve($token);
    ok($context !== null, 'updated_at moved and the connection did not, which is the point of a separate column');
    same(
        'Tess, with the spelling she prefers',
        $context['actor']->displayName,
        'and the new name is what the connection reports, being read from the account every time'
    );
});

test('an account choosing its own password keeps its connections', static function (): void {
    revokeAccount('revoke-tess');
    $token = revokeConnection('revoke-tess', 60);

    ok(
        Users::changePassword('revoke-tess', 'a-password-here', 'the-one-only-tess-knows'),
        'tess replaces her own password'
    );
    ok(
        McpClients::resolve($token) !== null,
        'and change_my_password keeps the promise it makes in as many words - including not cutting off the '
        . 'connection the call arrived on'
    );
});

test('an installation that has just upgraded loses no connection at all', static function (): void {
    revokeAccount('revoke-tess');
    $token = revokeConnection('revoke-tess', 86400);

    // Every account that existed before the column did carries the ALTER TABLE
    // default. Zero has to mean "nobody has reset this", not "reset at the
    // beginning of 1970", or the upgrade itself would revoke everything.
    Db::run('UPDATE users SET password_reset_at = 0 WHERE username = ? COLLATE NOCASE', ['revoke-tess']);

    ok(McpClients::resolve($token) !== null, 'a token a day older than the upgrade still resolves');
});

test('a connection made in the same second as a reset is not killed by it', static function (): void {
    revokeAccount('revoke-tess');
    Users::setPassword('revoke-tess', 'the-one-the-admin-typed', mustChange: false);
    $token = revokeConnection('revoke-tess');

    // The comparison is strictly-before for this: the reset and the connection
    // that follows it can land inside the same second, and a token that was
    // dead on arrival would be a very hard thing to diagnose.
    Db::run(
        'UPDATE mcp_clients SET created_at = (SELECT password_reset_at FROM users WHERE username = ? COLLATE NOCASE)',
        ['revoke-tess']
    );
    ok(McpClients::resolve($token) !== null, 'same second, later event, still a working connection');
});

test('a flagged account reaches three tools over MCP and no others', static function (): void {
    revokeAccount('revoke-tess');
    Users::setPassword('revoke-tess', 'the-one-the-admin-typed', mustChange: true);

    // callTool is where a tools/call lands after the token has resolved, and it
    // is private because nothing but Server::handle() should be calling it.
    // Reaching it here rather than through handle() is only about the exit():
    // handle() answers the socket and stops the process, which a test runner
    // cannot survive. Everything below this line is the real path.
    $call = new ReflectionMethod(Server::class, 'callTool');
    $context = [
        'actor' => Actor::make('revoke-tess', 'revoke-tess', 'user'),
        'client_id' => 0,
        'client_name' => 'test',
        'scopes' => [],
    ];
    $run = static fn(string $tool): array => $call->invoke(null, $context, ['name' => $tool, 'arguments' => []]);

    foreach (['whoami', 'get_my_account'] as $allowed) {
        same(false, $run($allowed)['isError'], $allowed . ' answers, so the account can see what it is');
    }

    foreach (['list_courses', 'list_profiles', 'list_my_connections', 'set_my_display_name'] as $refused) {
        $result = $run($refused);
        same(true, $result['isError'], $refused . ' is refused');
        ok(
            str_contains((string)$result['content'][0]['text'], 'has to choose its own'),
            'and says why, rather than looking like the tool failing'
        );
    }

    ok(
        Users::changePassword('revoke-tess', 'the-one-the-admin-typed', 'the-one-only-tess-knows'),
        'the account chooses its own password'
    );
    same(false, $run('list_courses')['isError'], 'and the rest of the surface comes back');
});

test('the tidying up', static function (): void {
    Db::run("DELETE FROM mcp_clients WHERE username LIKE 'revoke-%'");
    Db::run("DELETE FROM users WHERE username LIKE 'revoke-%'");
    ok(true, 'the accounts and connections this file made are gone');
});
