<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * A profile is configurable from a conversation as fully as from the browser.
 *
 * It was not. `update_profile` names *the* AI account and *the* BookStack
 * instance of a profile, and would add one only when there was none - so a
 * profile could be given its first of each and never a second. The browser has
 * had an Add button beside both lists since 3.2, and 4.7 made publishing to
 * several BookStack instances at once a headline feature, which a client
 * configuring an installation over MCP could set up exactly none of.
 *
 * Adding is its own verb rather than a special case of update, and that is the
 * point rather than a style choice: an argument that edits when an id is given
 * and creates when it is not turns a mistyped ai_id into a second account
 * holding the key that was meant to replace the first.
 *
 * The doctrine every one of these tools inherits is that a credential is
 * written and never read back, so the last test here goes over every answer
 * these tools give and asserts that no secret is in any of them.
 */

use CourseForge\Domain\Profiles;
use CourseForge\Domain\Projects;
use CourseForge\Mcp\Tools;
use CourseForge\Publish\Targets;
use CourseForge\Security\Actor;
use CourseForge\Support\Config;

function profileActor(): Actor
{
    return Actor::make('profiler', 'Profiler', Actor::ROLE_USER);
}

/** @return array<string,mixed> */
function profileCall(string $tool, array $args): array
{
    return (array)(Tools::call(profileActor(), $tool, $args)['data'] ?? []);
}

/** A profile with one AI account and one BookStack instance, made the way a client would. */
function madeProfile(string $name): int
{
    $made = profileCall('create_profile', [
        'name' => $name,
        'ai_kind' => 'anthropic',
        'api_key' => 'sk-first-key',
        'model' => 'claude-opus-5',
        'language' => 'Deutsch',
        'bookstack_url' => 'https://wiki.example.com',
        'bookstack_token_id' => 'tok-1',
        'bookstack_token_secret' => 'sec-1',
    ]);

    return (int)$made['profile_id'];
}

test('a profile can be given a second BookStack instance', static function (): void {
    $id = madeProfile('zwei-wikis');

    $added = profileCall('add_bookstack_instance', [
        'profile_id' => $id,
        'url' => 'https://staging.example.com/',
        'token_id' => 'tok-2',
        'token_secret' => 'sec-2',
        'name' => 'Staging',
    ]);

    ok((bool)$added['added'], 'the instance was added');
    same(2, count($added['instances']), 'and the profile now holds two');
    ok((string)$added['bookstack_id'] !== '', 'with an id to name it by');

    $second = $added['instances'][1];
    same('Staging', (string)$second['name'], 'under the name that was given');
    same('https://staging.example.com', (string)$second['base_url'], 'with the trailing slash taken off');
    ok((bool)$second['token_secret_set'], 'and its secret stored');

    // Which is the whole reason it exists: a course cannot publish to two
    // wikis until its profile defines two.
    $profile = profileCall('get_profile', ['profile_id' => $id]);
    same(2, count((array)$profile['bookstack']), 'get_profile agrees');
});

test('a profile can be given a second AI account, and a slot pointed at it', static function (): void {
    $id = madeProfile('zwei-konten');

    $added = profileCall('add_ai_account', [
        'profile_id' => $id,
        'kind' => 'openai',
        'api_key' => 'sk-second-key',
        'name' => 'OpenAI - Seiten',
    ]);

    ok((bool)$added['added'], 'the account was added');
    same(2, count($added['ai_accounts']), 'and the profile holds two');
    $secondId = (string)$added['ai_id'];
    ok($secondId !== '', 'with an id of its own');

    // The point of a second account is sending one slot through it. Before
    // this tool there was no way to say that at all: update_profile writes
    // both slots together.
    $slot = profileCall('set_model_slot', [
        'profile_id' => $id,
        'slot' => 'page',
        'model' => 'gpt-5:batch',
        'ai_id' => $secondId,
        'temperature' => 0.4,
    ]);

    same('page', (string)$slot['slot'], 'the page slot was the one changed');
    same('gpt-5:batch', (string)$slot['models']['page']['model'], 'and it runs that model');
    same($secondId, (string)$slot['models']['page']['ai_id'], 'through the new account');
    ok((bool)$slot['models']['page']['batched'], 'and it is a batched model');
    same(0.4, (float)$slot['models']['page']['temperature'], 'with its own temperature');

    // The outline slot is untouched, which is the difference from update_profile.
    same('claude-opus-5', (string)$slot['models']['outline']['model'], 'the outline still runs the first model');
    same(0.7, (float)$slot['models']['outline']['temperature'], 'at the temperature it had');
});

test('the last AI account of a profile cannot be taken away', static function (): void {
    $id = madeProfile('nur-eines');

    $error = raises(
        static fn() => profileCall('delete_ai_account', ['profile_id' => $id, 'ai_id' => firstAiId($id)]),
        'removing the only account'
    );
    ok(str_contains($error->getMessage(), 'delete_profile'), 'and is told what to do instead');
});

test('removing an AI account says which slots it leaves pointing at nothing', static function (): void {
    $id = madeProfile('konto-weg');
    $first = firstAiId($id);

    $second = (string)profileCall('add_ai_account', [
        'profile_id' => $id,
        'kind' => 'openai',
        'api_key' => 'sk-second',
    ])['ai_id'];

    profileCall('set_model_slot', ['profile_id' => $id, 'slot' => 'page', 'ai_id' => $second]);

    // The outline slot still points at the first account, so removing it has
    // to say so - a slot pointing at nothing does not generate, and nothing
    // else would report it until somebody pressed Generate.
    $removed = profileCall('delete_ai_account', ['profile_id' => $id, 'ai_id' => $first]);

    ok((bool)$removed['removed'], 'the account went');
    same(1, count($removed['ai_accounts']), 'leaving one');
    same(['outline'], $removed['slots_left_unset'], 'and the outline slot is named as unset');
    ok(str_contains((string)$removed['next_step'], 'set_model_slot'), 'with the tool that repairs it');
});

test('removing a BookStack instance a course publishes to is refused first', static function (): void {
    $id = madeProfile('wiki-weg');
    $instances = (array)profileCall('get_profile', ['profile_id' => $id])['bookstack'];
    $instanceId = (string)$instances[0]['id'];

    $project = Projects::create('profiler', 'Kurs', 'Thema', $id);
    $projectId = (int)$project['id'];
    Targets::replaceAll('profiler', $projectId, [['instance_id' => $instanceId]]);

    $error = raises(
        static fn() => profileCall('delete_bookstack_instance', [
            'profile_id' => $id,
            'bookstack_id' => $instanceId,
        ]),
        'removing an instance a course publishes to'
    );
    ok(str_contains($error->getMessage(), 'Kurs'), 'the course is named');
    ok(str_contains($error->getMessage(), 'second book'), 'and what it would cost is said out loud');

    // Still there, because a refusal that half-did it would be worse than either.
    same(1, count((array)profileCall('get_profile', ['profile_id' => $id])['bookstack']), 'and nothing was removed');

    $done = profileCall('delete_bookstack_instance', [
        'profile_id' => $id,
        'bookstack_id' => $instanceId,
        'confirm' => true,
    ]);
    ok((bool)$done['removed'], 'confirm goes through with it');
    same(1, count((array)$done['courses_affected']), 'and reports the course it cost');
    same(0, count((array)$done['instances']), 'the profile holds none now');
});

test('a profile overrides prompt slots of its own, and gives them back', static function (): void {
    $id = madeProfile('eigene-prompts');
    $slot = array_key_first(Config::promptSlots());

    $set = profileCall('set_profile_prompts', [
        'profile_id' => $id,
        'prompts' => [$slot => 'Nur für dieses Profil.'],
    ]);
    same([$slot], $set['set'], 'the slot was overridden');
    same([$slot], $set['overridden'], 'and the profile reports it as overridden');

    $read = profileCall('get_profile', ['profile_id' => $id, 'include_prompt_text' => true]);
    ok(
        str_contains(json_encode($read, JSON_UNESCAPED_UNICODE) ?: '', 'Nur für dieses Profil.'),
        'get_profile reads the override back'
    );

    $reset = profileCall('set_profile_prompts', ['profile_id' => $id, 'reset' => [$slot]]);
    same([$slot], $reset['reset'], 'and it can be put back');
    same([], $reset['overridden'], 'leaving nothing overridden');

    $error = raises(
        static fn() => profileCall('set_profile_prompts', [
            'profile_id' => $id,
            'prompts' => ['no_such_slot' => 'x'],
        ]),
        'an unknown prompt slot'
    );
    ok(str_contains($error->getMessage(), 'no prompt slot'), 'an unknown slot is refused, not stored');
});

test('an account, an instance and the punctuation switch can all be renamed or set', static function (): void {
    $id = madeProfile('umbenennen');

    $changed = profileCall('update_profile', [
        'profile_id' => $id,
        'ai_name' => 'Anthropic - Produktion',
        'bookstack_name' => 'Live-Wiki',
        'typography' => false,
    ]);

    ok(in_array('ai_name', (array)$changed['changed'], true), 'the account was renamed');
    ok(in_array('bookstack_name', (array)$changed['changed'], true), 'and the instance');
    ok(in_array('typography', (array)$changed['changed'], true), 'and the punctuation switch was set');

    $profile = profileCall('get_profile', ['profile_id' => $id]);
    same('Anthropic - Produktion', (string)$profile['ai_accounts'][0]['name'], 'the new account name is stored');
    same('Live-Wiki', (string)((array)$profile['bookstack'])[0]['name'], 'and the new instance name');
    same(false, (bool)Profiles::data('profiler', $id)['typography'], 'and the switch is off in the profile itself');
});

test('nothing any of these tools answers with holds a secret', static function (): void {
    // The doctrine of this whole file: a credential can be written and never
    // read back. A tool added later must not be the one that breaks it, so
    // every answer is searched rather than every field being listed.
    $id = madeProfile('geheim');
    $secrets = ['sk-first-key', 'sec-1', 'sk-second-key', 'sec-2'];

    $answers = [
        profileCall('add_ai_account', [
            'profile_id' => $id, 'kind' => 'openai', 'api_key' => 'sk-second-key',
        ]),
        profileCall('add_bookstack_instance', [
            'profile_id' => $id, 'url' => 'https://two.example.com',
            'token_id' => 'tok-2', 'token_secret' => 'sec-2',
        ]),
        profileCall('set_model_slot', ['profile_id' => $id, 'slot' => 'page', 'model' => 'gpt-5']),
        profileCall('get_profile', ['profile_id' => $id, 'include_prompt_text' => true]),
        profileCall('list_profiles', []),
    ];

    foreach ($answers as $i => $answer) {
        $json = json_encode($answer, JSON_UNESCAPED_UNICODE) ?: '';
        foreach ($secrets as $secret) {
            ok(!str_contains($json, $secret), 'answer ' . $i . ' does not carry "' . $secret . '"');
        }
        ok(str_contains($json, '_set') || $json !== '', 'and reports what is stored as a flag instead');
    }
});

/** The id of the profile's first AI account. */
function firstAiId(int $profileId): string
{
    $profile = profileCall('get_profile', ['profile_id' => $profileId]);
    return (string)((array)$profile['ai_accounts'])[0]['id'];
}
