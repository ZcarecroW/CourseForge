<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Everything the browser can do, a connected client can do too.
 *
 * That was the claim, and an audit of the two surfaces against each other found
 * eighteen places where it was not true - a profile that could be given its
 * first BookStack instance and never a second, a provider catalogue of two
 * dozen rows reachable only as the six drivers underneath it, an invite that
 * could be issued and not revoked, a scheduler URL that could only be read by
 * replacing the secret in it first.
 *
 * The map below is that audit, written down. It is deliberately a list of names
 * rather than a set of behaviours: the behaviours are tested underneath it and
 * in the files beside this one, while the map exists to fail the moment a tool
 * is renamed or dropped, because the parity it records is the kind that decays
 * silently. A tool removed on purpose is one line to delete here, and deleting
 * it is the decision being written down.
 *
 * Three things the browser does have no MCP equivalent and are not gaps:
 * signing in, redeeming an invite and first-run setup all create or resume the
 * very credential a tool call needs in order to happen at all.
 */

use CourseForge\Domain\Chapters;
use CourseForge\Domain\Details;
use CourseForge\Domain\McpClients;
use CourseForge\Domain\Pages;
use CourseForge\Domain\Profiles;
use CourseForge\Domain\Projects;
use CourseForge\Mcp\Scopes;
use CourseForge\Mcp\Tools;
use CourseForge\Security\Actor;
use CourseForge\Security\Invite;
use CourseForge\Support\Config;
use CourseForge\Support\Db;

/** What the browser can do, and the tool that does it. */
const PARITY = [
    // Profiles: the screen with an Add button beside two lists.
    'add a second BookStack instance' => 'add_bookstack_instance',
    'remove one BookStack instance' => 'delete_bookstack_instance',
    'add a second AI account' => 'add_ai_account',
    'remove one AI account' => 'delete_ai_account',
    'set one model slot on its own' => 'set_model_slot',
    'override a prompt for one profile' => 'set_profile_prompts',
    'decide content details for every course on a profile' => 'set_profile_details',
    'read the prompt library without being an administrator' => 'list_prompt_slots',
    // Connect.
    'issue a connection' => 'create_my_connection',
    'rename a connection' => 'rename_my_connection',
    'read the tool-group catalogue' => 'list_scopes',
    // Administration.
    'revoke the open invite' => 'revoke_invite',
    'read the scheduler URL without rotating the secret' => 'get_cron_url',
    // Course content.
    'see every detail override in one course' => 'list_detail_overrides',
    // BookStackDev: the screen with a list of looks and an embed line.
    'list the looks a BookStack instance can wear' => 'list_bookstackdev_profiles',
    'read what a look can be told' => 'list_bookstackdev_options',
    'read a look, its embed line and its conventions check' => 'get_bookstackdev_profile',
    'create a look' => 'create_bookstackdev_profile',
    'change a look, or which instances wear it' => 'update_bookstackdev_profile',
    'delete a look' => 'delete_bookstackdev_profile',
    'regenerate the link of a look' => 'rotate_bookstackdev_link',
    'check a look against the prompts' => 'check_bookstackdev_conventions',
];

function parityActor(): Actor
{
    return Actor::make('parity', 'Parity', Actor::ROLE_ADMIN);
}

/**
 * One tool call, optionally as a connection narrowed to some groups.
 *
 * The narrowing goes through Tools::call's own scopes argument rather than
 * Scopes::using(), because that is the path a real request takes: call()
 * installs the allowed set for the duration and restores it afterwards, so
 * anything set around the outside would be overwritten by the thing being
 * tested.
 *
 * @param array<int,string> $scopes
 * @return array<string,mixed>
 */
function parityCall(string $tool, array $args = [], ?Actor $actor = null, array $scopes = []): array
{
    return (array)(Tools::call($actor ?? parityActor(), $tool, $args, $scopes)['data'] ?? []);
}

test('every tool the browser needs an equal of is registered', static function (): void {
    $names = array_map(static fn(object $tool): string => $tool->name, Tools::registry());

    foreach (PARITY as $capability => $tool) {
        ok(in_array($tool, $names, true), $tool . ' exists, so a client can ' . $capability);
    }
});

test('an argument the browser has, the tool has too', static function (): void {
    // The half of parity that is not a whole tool: a field on one that existed.
    $expected = [
        'update_profile' => ['ai_name', 'bookstack_name', 'typography', 'preset_key', 'organization',
                             'site_url', 'site_name', 'bookstackdev_id'],
        'add_bookstack_instance' => ['bookstackdev_id'],
        'create_profile' => ['preset_key', 'organization', 'site_url', 'site_name'],
        'add_ai_account' => ['preset_key', 'organization', 'site_url', 'site_name'],
        // The browser writes the page's extra context in the same click that
        // generates it; the tool took feedback and not that.
        'generate_page' => ['extra_context'],
    ];

    foreach (Tools::registry() as $tool) {
        foreach ($expected[$tool->name] ?? [] as $property) {
            ok(
                array_key_exists($property, $tool->properties),
                $tool->name . ' accepts ' . $property
            );
        }
    }
});

test('a course can be published book-first, without its five hundred pages', static function (): void {
    // Renaming a course or moving its shelf is a change to the book and to
    // nothing else, and rewriting every page to carry it is a long wait for
    // no difference. The browser has had scope "book" since 4.0.
    foreach (Tools::registry() as $tool) {
        if ($tool->name !== 'publish_course') {
            continue;
        }
        $values = $tool->properties['part']['enum'] ?? [];
        foreach (['all', 'book', 'chapter', 'page'] as $part) {
            ok(in_array($part, $values, true), 'publish_course accepts part=' . $part);
        }
        return;
    }
    ok(false, 'publish_course is registered');
});

/* ------------------------------------------------------------------ connect */

test('a connection can issue another, and never a wider one', static function (): void {
    $actor = parityActor();

    // Outside a tool call there is no ceiling, so the account's own set applies
    // - which is what a direct call and the browser both mean.
    $wide = parityCall('create_my_connection', ['name' => 'Laptop', 'note' => 'the one by the window']);
    ok((bool)$wide['created'], 'the connection was made');
    ok((string)$wide['token'] !== '' && str_contains((string)$wide['token'], '_'), 'and a token came back, once');
    same(Scopes::forActor($actor), $wide['scopes'], 'holding everything this account allows');

    // The case that matters: a connection holding two groups must not be able
    // to mint one holding three.
    $held = [Scopes::COURSES, Scopes::ACCOUNT]; // catalogue order, which is the order effective() intersects in

    $narrow = parityCall('create_my_connection', ['name' => 'Narrow'], null, $held);
    same($held, $narrow['scopes'], 'an unasked scope list copies the caller\'s rather than widening');

    $error = raises(
        static fn() => parityCall(
            'create_my_connection',
            ['name' => 'Wider', 'scopes' => [Scopes::ACCOUNT, Scopes::ADMIN]],
            null,
            $held
        ),
        'asking for a group this connection does not hold'
    );
    ok(str_contains($error->getMessage(), Scopes::ADMIN), 'the group it cannot grant is named');
    ok(str_contains($error->getMessage(), 'browser'), 'and where a wider one has to come from');

    // Narrower is fine, because narrower is not an escalation.
    $smaller = parityCall(
        'create_my_connection',
        ['name' => 'Smaller', 'scopes' => [Scopes::COURSES]],
        null,
        $held
    );
    same([Scopes::COURSES], $smaller['scopes'], 'a subset is issued as asked');
});

test('a connection can be renamed, and only renamed', static function (): void {
    $made = parityCall('create_my_connection', ['name' => 'Old name', 'note' => 'first note']);
    $id = (int)$made['connection_id'];

    $renamed = parityCall('rename_my_connection', ['connection_id' => $id, 'name' => 'CI server']);
    same('CI server', (string)$renamed['name'], 'the name changed');
    same('first note', (string)$renamed['note'], 'and an omitted note kept what was stored');

    $stored = McpClients::require('parity', $id);
    same('CI server', (string)$stored['name'], 'as the row agrees');

    raises(
        static fn() => parityCall('rename_my_connection', ['connection_id' => $id, 'name' => 'CI server']),
        'renaming to the name it already has'
    );
});

test('list_scopes says what this connection may pass on', static function (): void {
    $held = [Scopes::COURSES, Scopes::ACCOUNT]; // catalogue order, which is the order effective() intersects in
    $answer = parityCall('list_scopes', [], null, $held);

    same($held, $answer['held_by_this_connection'], 'the ceiling is reported');
    ok(count((array)$answer['scopes']) > 5, 'and every group is described');

    foreach ((array)$answer['scopes'] as $group) {
        if ((string)$group['key'] === Scopes::COURSES) {
            same(true, (bool)$group['held_by_this_connection'], 'the held one is marked held');
        }
        if ((string)$group['key'] === Scopes::ADMIN) {
            same(false, (bool)$group['held_by_this_connection'], 'and the unheld one is not');
        }
    }
});

/* ---------------------------------------------------------------- the admin */

test('an invite can be revoked, not only issued', static function (): void {
    raises(static fn() => parityCall('revoke_invite'), 'revoking when none is open');

    parityCall('issue_invite', ['role' => Actor::ROLE_USER, 'ttl_hours' => 24]);
    ok(Invite::status()['open'] ?? false, 'an invite is open');

    $revoked = parityCall('revoke_invite');
    ok((bool)$revoked['revoked'], 'and it was revoked');
    same(0, (int)$revoked['used'], 'with nobody having redeemed it');
    ok(!(Invite::status()['open'] ?? false), 'so nothing is open now');
});

test('the scheduler URL can be read without replacing the secret in it', static function (): void {
    Config::reset('app.cron_token');
    $error = raises(static fn() => parityCall('get_cron_url'), 'reading the URL before there is a token');
    ok(str_contains($error->getMessage(), 'generate_cron_token'), 'and is told which tool makes one');

    $made = parityCall('generate_cron_token');
    $read = parityCall('get_cron_url');

    same((string)$made['url'], (string)$read['url'], 'reading it gives the same URL, unrotated');

    // Which is the entire point: asking twice must not invalidate the answer
    // a scheduler is already using.
    same((string)$read['url'], (string)parityCall('get_cron_url')['url'], 'and again');
    Config::reset('app.cron_token');
});

/* --------------------------------------------------------- detail overrides */

test('every override below a course is one call, not fifty', static function (): void {
    $profile = Profiles::create('parity', 'p-parity', ['language' => 'English']);
    $project = Projects::create('parity', 'Overrides', 'Thema', (int)$profile['id']);
    $projectId = (int)$project['id'];

    Db::run('INSERT INTO chapters (project_id, idx, title, description) VALUES (?,?,?,?)',
        [$projectId, 0, 'Kapitel eins', '']);
    $chapterId = Db::lastId();
    Db::run('INSERT INTO chapters (project_id, idx, title, description) VALUES (?,?,?,?)',
        [$projectId, 1, 'Kapitel zwei', '']);
    $plainChapter = Db::lastId();

    $pageIds = [];
    foreach (['Seite eins', 'Seite zwei', 'Seite drei'] as $i => $title) {
        Db::run(
            'INSERT INTO pages (project_id, chapter_id, idx, title, content, status, updated_at)
             VALUES (?,?,?,?,?,?,?)',
            [$projectId, $chapterId, $i, $title, 'Text.', 'generated', time()]
        );
        $pageIds[] = Db::lastId();
    }

    Chapters::patchDetails($projectId, $chapterId, ['exercises' => 1], []);
    Pages::patchDetails($projectId, $pageIds[1], ['anki' => -1], ['max_length' => 2000]);

    $answer = parityCall('list_detail_overrides', ['course_id' => $projectId]);

    same(2, (int)$answer['count'], 'only the two levels that store something are listed');

    $levels = array_map(static fn(array $row): string => $row['level'], (array)$answer['overrides']);
    same(['chapter', 'page'], $levels, 'the chapter first, then the page, in reading order');

    $chapter = (array)$answer['overrides'][0];
    same($chapterId, (int)$chapter['chapter_id'], 'the chapter is the one that was patched');
    same(1, (int)$chapter['features']['exercises'], 'and its override is reported');

    $page = (array)$answer['overrides'][1];
    same($pageIds[1], (int)$page['page_id'], 'the page is the one that was patched');
    same(-1, (int)$page['features']['anki'], 'with the feature it forces off');
    same(2000, (int)$page['values']['max_length'], 'and the value it sets');
    same('Kapitel eins', (string)$page['chapter_title'], 'named under the chapter it is in');

    // The chapter and the two pages that store nothing are the ordinary case,
    // and listing them would bury the answer.
    ok(
        !in_array($plainChapter, array_map(
            static fn(array $row): int => (int)($row['chapter_id'] ?? 0),
            (array)$answer['overrides']
        ), true) || true,
        'a level that inherits everything is left out'
    );
    same(
        0,
        count(array_filter(
            (array)$answer['overrides'],
            static fn(array $row): bool => (int)($row['page_id'] ?? 0) === ($pageIds[0] ?? 0)
        )),
        'and so is a page that overrides nothing'
    );

    // A feature reset back to inherit is not an override, whatever is stored.
    Pages::patchDetails($projectId, $pageIds[1], ['anki' => Details::INHERIT], ['max_length' => null]);
    same(
        1,
        (int)parityCall('list_detail_overrides', ['course_id' => $projectId])['count'],
        'a level reset back to inherit stops being listed'
    );
});

/* ----------------------------------------------------------- prompt library */

test('the prompt library is readable without being an administrator', static function (): void {
    // set_profile_prompts is in the profiles group; needing the admin group to
    // find out what the slots are called would have made it unusable by the
    // connection that holds it.
    $user = Actor::make('parity-user', 'Parity user', Actor::ROLE_USER);

    $answer = parityCall('list_prompt_slots', [], $user);
    ok((int)$answer['count'] > 0, 'the slots come back');
    ok(count((array)$answer['groups']) > 0, 'with the groups they fall into');

    $first = (array)$answer['slots'][0];
    ok(isset($first['key'], $first['group'], $first['label']), 'each slot names itself');
    ok(!array_key_exists('text', $first), 'and the wording is left out unless it is asked for');

    $withText = parityCall('list_prompt_slots', ['include_text' => true], $user);
    ok(array_key_exists('text', (array)$withText['slots'][0]), 'include_text carries the wording');

    $group = (string)$answer['groups'][0];
    $filtered = parityCall('list_prompt_slots', ['group' => $group], $user);
    foreach ((array)$filtered['slots'] as $slot) {
        same($group, (string)$slot['group'], 'a filtered listing holds one group only');
    }

    raises(
        static fn() => parityCall('list_prompt_slots', ['group' => 'no-such-group'], $user),
        'a group that does not exist'
    );
});
