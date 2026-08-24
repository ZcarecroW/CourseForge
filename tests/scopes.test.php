<?php
/**
 * What a narrowed connection may and may not do.
 *
 * This file exists because the rule was got wrong once, in a way that was
 * invisible from the code and obvious the moment somebody drove it: the
 * account group was exempted from narrowing wholesale, on the reasoning that
 * "who am I" is harmless - and the group also holds "change my password" and
 * "revoke my connections". A token handed to somebody else could change its
 * owner's password. Exactly one tool is exempt, it says so on itself, and
 * these tests are what keep that true.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use CourseForge\Mcp\Scopes;
use CourseForge\Mcp\Tools;
use CourseForge\Security\Actor;

$admin = Actor::make('anne', 'Anne', Actor::ROLE_ADMIN);
$user = Actor::make('ulf', 'Ulf', Actor::ROLE_USER);

/** @return string[] */
$names = static fn(Actor $actor, array $scopes): array
    => array_column(Tools::catalogue($actor, $scopes), 'name');

test('a connection given no groups gets everything its account may do', function () use ($names, $admin, $user) {
    $all = $names($admin, []);
    ok(count($all) > 60, 'an administrator with no narrowing sees the whole surface');
    ok(in_array('list_users', $all, true), 'including the admin group');

    $theirs = $names($user, []);
    ok(!in_array('list_users', $theirs, true), 'a normal account never sees the admin group');
});

test('a narrowed connection keeps whoami and nothing else outside its groups', function () use ($names, $admin) {
    $narrow = $names($admin, [Scopes::PAGES]);

    ok(in_array('whoami', $narrow, true), 'whoami survives the narrowing');
    ok(in_array('write_page', $narrow, true), 'the group that was asked for is there');

    foreach (['change_my_password', 'revoke_my_connection', 'get_my_account', 'list_my_connections'] as $tool) {
        ok(!in_array($tool, $narrow, true), $tool . ' must not come with whoami');
    }
    foreach (['list_courses', 'list_tags', 'list_profiles', 'list_users', 'publish_course'] as $tool) {
        ok(!in_array($tool, $narrow, true), $tool . ' is outside the granted group');
    }
});

test('the account group is reachable, but only by asking for it', function () use ($names, $admin) {
    $account = $names($admin, [Scopes::ACCOUNT]);
    ok(in_array('change_my_password', $account, true), 'asking for the group grants the group');
    ok(in_array('revoke_my_connection', $account, true), 'including the one that ends a connection');
    ok(!in_array('write_page', $account, true), 'and nothing else');
});

test('a tool outside the granted groups cannot be called either', function () use ($admin) {
    $threw = false;
    try {
        Tools::call($admin, 'change_my_password', ['current_password' => 'x', 'new_password' => 'y'], [Scopes::PAGES]);
    } catch (Throwable $e) {
        $threw = true;
        ok(
            str_contains($e->getMessage(), 'no tool called'),
            'a tool the connection cannot see is reported as unknown, not as forbidden'
        );
    }
    ok($threw, 'calling outside the granted groups must be refused');
});

test('the admin group cannot be granted to an account that is not one', function () use ($names, $user) {
    $asked = $names($user, [Scopes::ADMIN]);

    // Scopes::effective intersects with what the account allows, so asking for
    // a group the account cannot have leaves nothing but the exempt tool.
    same(['whoami'], $asked, 'a normal account asking for admin gets only the exempt tool');

    $threw = false;
    try {
        Tools::call($user, 'list_users', [], []);
    } catch (Throwable $e) {
        $threw = true;
    }
    ok($threw, 'and cannot call an admin tool with no narrowing at all');
});

test('every scope in the catalogue holds at least one tool', function () {
    foreach (Tools::scopes() as $group) {
        ok(
            (int)$group['tools'] > 0,
            'the ' . $group['key'] . ' group is advertised on the Connect screen and must not be empty'
        );
    }
});
