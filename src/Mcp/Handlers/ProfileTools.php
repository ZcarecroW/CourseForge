<?php
declare(strict_types=1);

namespace CourseForge\Mcp\Handlers;

use CourseForge\Ai\ModelId;
use CourseForge\Ai\Provider\ClaudeCliProvider;
use CourseForge\Ai\Provider\Providers;
use CourseForge\Domain\Profiles;
use CourseForge\Domain\Projects;
use CourseForge\Mcp\Args;
use CourseForge\Mcp\Schema;
use CourseForge\Mcp\Scopes;
use CourseForge\Mcp\Tool;
use CourseForge\Publish\BookStackClient;
use CourseForge\Security\Access;
use CourseForge\Security\Actor;
use CourseForge\Support\Audit;
use CourseForge\Support\HttpException;
use CourseForge\Support\Runtime;

/**
 * Profiles: the bundle a course generates and publishes with.
 *
 * A profile holds one or more AI accounts - an API key and a base URL, or the
 * Claude CLI already signed in on this machine - one or more BookStack
 * instances, the model chosen for the outline slot and the model chosen for the
 * page slot, the language, the temperature, the token ceiling and any
 * per-profile prompt overrides. Every course points at exactly one, and a
 * course with no profile cannot generate a word. So this group is what a person
 * uses to set an installation up from Claude rather than from the browser: make
 * a profile, prove the credentials work, ask the provider which models the
 * account can actually use, and point a course at it.
 *
 * **An API key is never returned to a client, in any form, by any tool here.**
 * A key can be written - that is how an installation gets configured from a
 * conversation - and it can be reported as set or not set. It can never be read
 * back, not in a listing, not in an error message, not as a masked fragment.
 * `Profiles::data()` hands back the raw profile including its secrets and is
 * server-side only; everything that leaves this file goes through
 * `Profiles::redact()` and then through a second pass that blanks any field
 * whose name looks like a credential, so that a provider added later cannot
 * leak a new kind of secret through a tool written before it existed.
 *
 * The same care applies to whose profile it is. A profile is authorised against
 * the actor through Access, and then every call downstream is made in the name
 * of the OWNER the row reports - an administrator opening somebody else's
 * profile is opening that person's credentials, and a lookup made in the
 * administrator's own name would not find the row at all.
 */
final class ProfileTools
{
    /** The two model slots, and the words a person uses for them. */
    private const SLOTS = ['overview' => 'outline', 'page' => 'page'];

    /**
     * Field names that must never be sent to a client.
     *
     * A pattern rather than a list because the provider catalogue is growing:
     * an account kind added tomorrow may carry a credential under a name this
     * file has never heard of, and the safe default is to blank anything that
     * reads like one. `token_id` deliberately does not match - a BookStack
     * token id identifies the instance and is not the secret half.
     */
    private const SECRET_PATTERN = '/api[_-]?key|secret|password|passphrase|credential|bearer|(^|_)token$/i';

    /** @return array<int,Tool> */
    public static function tools(): array
    {
        return [
            new Tool(
                name: 'list_profiles',
                scope: Scopes::PROFILES,
                title: 'List profiles',
                description: 'The profiles this account can generate with: the id to pass to update_course, the AI '
                    . 'accounts each one holds and what kind they are, whether each account has a key stored, the '
                    . 'models chosen for the outline and the page slot, the BookStack instances, and the language. '
                    . 'API keys are never included - only whether one is set. An administrator sees every account\'s '
                    . 'profiles, each marked with its owner - a working set stays your own unless you widen it. '
                    . 'Costs nothing.',
                properties: [
                    'owner' => Schema::string("Administrators only: one other account's profiles."),
                    'all' => Schema::bool(
                        'Administrators only: widen to every account on the installation. Without it a listing is your own.'
                    ),
                ],
                required: [],
                handler: static fn(Actor $actor, array $args): array => self::listProfiles($actor, Args::of($args)),
                readOnly: true,
                idempotent: true,
            ),

            new Tool(
                name: 'get_profile',
                scope: Scopes::PROFILES,
                title: 'Read a profile',
                description: 'One profile in full: every AI account with its kind, base URL and whether a key is '
                    . 'stored, every BookStack instance, both model slots with their temperature and token ceiling, '
                    . 'the language, the concurrency, the courses using it, and the prompt slots this profile '
                    . 'overrides - so you can see how it differs from the installation defaults. No credential is '
                    . 'ever returned. Costs nothing.',
                properties: [
                    'profile_id' => Schema::int('The profile id, as returned by list_profiles.'),
                    'include_prompt_text' => Schema::bool(
                        'Include the full text of each prompt override rather than only which slots are overridden. '
                        . 'These can be long.'
                    ),
                ],
                required: ['profile_id'],
                handler: static fn(Actor $actor, array $args): array => self::getProfile($actor, Args::of($args)),
                readOnly: true,
                idempotent: true,
            ),

            new Tool(
                name: 'create_profile',
                scope: Scopes::PROFILES,
                title: 'Create a profile',
                description: 'Builds a working profile in one call: one AI account, both model slots pointed at it, '
                    . 'the language, and optionally a BookStack instance. Call list_providers first to see which '
                    . 'account kinds this installation speaks and which of them need a key. The key is stored on the '
                    . 'server and can never be read back - only replaced. The profile belongs to the account that '
                    . 'creates it. Follow with check_profile to prove the credentials work and list_models to choose '
                    . 'a model. Costs nothing.',
                properties: [
                    'name' => Schema::string('A short name for the profile, for the picker.', 'Anthropic - production'),
                    'ai_kind' => Schema::enum(
                        'The kind of AI account. Call list_providers for what each one is and whether it needs a key.',
                        Providers::kinds()
                    ),
                    'api_key' => Schema::string(
                        'The API key for the account. Required for every kind whose needs_key is true. Written only: '
                        . 'no tool will ever hand it back.'
                    ),
                    'base_url' => Schema::string(
                        'The endpoint. Omit to use the default list_providers gives for this kind, which is right for '
                        . 'everything but a self-hosted or proxied gateway.',
                        'https://api.openai.com/v1'
                    ),
                    'model' => Schema::string(
                        'The model that writes the pages - where the budget goes. Add ":batch" to route a whole '
                        . 'course through the provider\'s queue at about half price.',
                        'claude-opus-5'
                    ),
                    'structure_model' => Schema::string(
                        'The model that designs the outline. One call per course, so it can be the expensive one. '
                        . 'Omit to use the same model as the pages.'
                    ),
                    'language' => Schema::string('The language the course is written in.', 'English'),
                    'temperature' => [
                        'type' => 'number',
                        'description' => 'Sampling temperature for both slots. 0.7 unless you have a reason.',
                        'minimum' => 0,
                        'maximum' => 2,
                    ],
                    'max_tokens' => Schema::int(
                        'The token ceiling for one reply. 0 means the provider\'s own default, which is usually right.',
                        0
                    ),
                    'concurrency' => Schema::int('How many pages a run writes at once.', 1, 12),
                    'bookstack_url' => Schema::string(
                        'The BookStack instance this course publishes to, without a trailing slash.',
                        'https://docs.example.com'
                    ),
                    'bookstack_token_id' => Schema::string('The BookStack API token id. Not a secret; it is readable back.'),
                    'bookstack_token_secret' => Schema::string(
                        'The BookStack API token secret. Written only: no tool will ever hand it back.'
                    ),
                ],
                required: ['name', 'ai_kind'],
                handler: static fn(Actor $actor, array $args): array => self::createProfile($actor, Args::of($args)),
            ),

            new Tool(
                name: 'update_profile',
                scope: Scopes::PROFILES,
                title: 'Change a profile',
                description: 'Changes one profile. Only the fields you give are touched, and everything else - the '
                    . 'other accounts, the prompt overrides - is left exactly as it was. An omitted or empty api_key '
                    . 'keeps the stored key: passing "" does not clear it, and there is no way to blank a stored key '
                    . 'at all, so an account whose key must go has to be replaced with a new one or the profile '
                    . 'deleted and made again. Give ai_id when the profile holds more than one AI account, and '
                    . 'bookstack_id when it holds more than one instance. Costs nothing.',
                properties: [
                    'profile_id' => Schema::int('The profile to change.'),
                    'name' => Schema::string('A new name for the profile.'),
                    'ai_id' => Schema::string(
                        'Which AI account to change, when the profile holds more than one. get_profile lists the ids.'
                    ),
                    'ai_kind' => Schema::enum(
                        'Change the account to another kind. The base URL follows the new kind\'s default unless you '
                        . 'gave one yourself or had typed one.',
                        Providers::kinds()
                    ),
                    'api_key' => Schema::string('A new key, replacing the stored one. Empty or omitted keeps what is stored.'),
                    'base_url' => Schema::string('A new endpoint for the account.'),
                    'model' => Schema::string('The model that writes the pages. Add ":batch" to queue the run at about half price.'),
                    'structure_model' => Schema::string('The model that designs the outline.'),
                    'language' => Schema::string('The language the course is written in.'),
                    'temperature' => [
                        'type' => 'number',
                        'description' => 'Sampling temperature. Applies to both model slots.',
                        'minimum' => 0,
                        'maximum' => 2,
                    ],
                    'max_tokens' => Schema::int('The token ceiling for one reply, for both slots. 0 is the provider default.', 0),
                    'concurrency' => Schema::int('How many pages a run writes at once.', 1, 12),
                    'bookstack_id' => Schema::string('Which BookStack instance to change, when the profile holds more than one.'),
                    'bookstack_url' => Schema::string('The BookStack base URL.'),
                    'bookstack_token_id' => Schema::string('The BookStack API token id.'),
                    'bookstack_token_secret' => Schema::string('A new BookStack token secret. Empty or omitted keeps the stored one.'),
                ],
                required: ['profile_id'],
                handler: static fn(Actor $actor, array $args): array => self::updateProfile($actor, Args::of($args)),
                idempotent: true,
            ),

            new Tool(
                name: 'delete_profile',
                scope: Scopes::PROFILES,
                title: 'Delete a profile',
                description: 'Removes a profile and the credentials in it. The keys are gone and cannot be recovered, '
                    . 'because nothing here can read them back to copy them elsewhere first. A profile a course still '
                    . 'points at is refused, with the names of the courses - move them to another profile with '
                    . 'update_course before deleting this one. Requires the profile name as confirmation. '
                    . 'Costs nothing.',
                properties: [
                    'profile_id' => Schema::int('The profile to delete.'),
                    'confirm_name' => Schema::string(
                        'The exact name of the profile, as a confirmation that the right one is being deleted.'
                    ),
                ],
                required: ['profile_id', 'confirm_name'],
                handler: static fn(Actor $actor, array $args): array => self::deleteProfile($actor, Args::of($args)),
                destructive: true,
            ),

            new Tool(
                name: 'list_models',
                scope: Scopes::PROFILES,
                title: 'List the models an account can use',
                description: 'Asks the provider which models this account may actually call, and marks which of them '
                    . 'its batch queue accepts. Writing a model as "claude-opus-5:batch" in a profile routes the '
                    . 'whole course through that queue at about half the price, at the cost of waiting - up to a day '
                    . 'for the answer, which is why the suffix belongs on the page slot and not on the outline. An '
                    . 'empty batch list means the provider would not say, not that nothing can be queued: '
                    . 'supports_batch is the answer then. This is a live call to the provider and can take a few '
                    . 'seconds, but no provider charges for a model listing. Pass the chosen id back to '
                    . 'update_profile as model or structure_model. Reaches the provider but buys nothing.',
                properties: [
                    'profile_id' => Schema::int('The profile the account belongs to.'),
                    'ai_id' => Schema::string('Which AI account to ask, when the profile holds more than one.'),
                    'refresh' => Schema::bool(
                        'Accepted and ignored: the list is fetched from the provider on every call, so it is never stale.'
                    ),
                ],
                required: ['profile_id'],
                handler: static fn(Actor $actor, array $args): array => self::listModels($actor, Args::of($args)),
                readOnly: true,
                openWorld: true,
            ),

            new Tool(
                name: 'check_profile',
                scope: Scopes::PROFILES,
                title: 'Check that an account works',
                description: 'Answers "does this account work" before a whole course depends on it, by making one '
                    . 'live call and reporting what came back. For a key-based account that call is a model listing, '
                    . 'which is the cheapest thing that proves the base URL and the key are both right, and no '
                    . 'provider bills for it; for the Claude subscription account, which has no model listing to '
                    . 'fetch, it reports whether the CLI is installed, whether it is signed in, and whether an API '
                    . 'key in the server environment has quietly taken over the billing. Use list_models when the '
                    . 'question is which models to choose rather than whether the credentials are good. Reaches the '
                    . 'provider but buys nothing.',
                properties: [
                    'profile_id' => Schema::int('The profile the account belongs to.'),
                    'ai_id' => Schema::string('Which AI account to check, when the profile holds more than one.'),
                ],
                required: ['profile_id'],
                handler: static fn(Actor $actor, array $args): array => self::checkProfile($actor, Args::of($args)),
                openWorld: true,
            ),

            new Tool(
                name: 'list_providers',
                scope: Scopes::PROFILES,
                title: 'List the account kinds',
                description: 'Every kind of AI account this installation can speak: its key for ai_kind, its label, '
                    . 'the base URL it defaults to, whether it needs an API key, whether it has a batch queue, and a '
                    . 'line of guidance on what belongs there. Call this before create_profile - the catalogue is '
                    . 'what create_profile validates ai_kind against, and it grows. Read from this installation, so '
                    . 'nothing is contacted. Costs nothing.',
                properties: [],
                required: [],
                handler: static fn(Actor $actor, array $args): array => self::listProviders(),
                readOnly: true,
                idempotent: true,
            ),

            new Tool(
                name: 'list_bookstack_shelves',
                scope: Scopes::PROFILES,
                title: 'List BookStack shelves',
                description: 'The shelves the profile\'s BookStack instance offers, with their ids, so a course can '
                    . 'be filed onto one when it is published. This is a network call to the BookStack server and '
                    . 'also proves the token works. A course carries the shelf on its own publishing settings, not '
                    . 'on the profile: pass the id you choose to update_course as shelf_id, with the name as '
                    . 'shelf_name. Reaches the BookStack server but buys nothing.',
                properties: [
                    'profile_id' => Schema::int('The profile the instance belongs to.'),
                    'bookstack_id' => Schema::string(
                        'Which BookStack instance to ask, when the profile holds more than one. get_profile lists the ids.'
                    ),
                ],
                required: ['profile_id'],
                handler: static fn(Actor $actor, array $args): array => self::listShelves($actor, Args::of($args)),
                readOnly: true,
                openWorld: true,
            ),
        ];
    }

    /* ------------------------------------------------------------- handlers */

    /** @return array<string,mixed> */
    private static function listProfiles(Actor $actor, Args $args): array
    {
        $owner = Access::workingSet($actor, $args->str('owner'), $args->bool('all'));

        $out = [];
        foreach (Profiles::all($owner) as $profile) {
            $data = (array)($profile['data'] ?? []);
            $models = self::modelSummary($data);

            $row = [
                'profile_id' => (int)$profile['id'],
                'name' => (string)$profile['name'],
                'language' => (string)($data['language'] ?? ''),
                'ai_accounts' => array_map(
                    static fn(array $account): array => self::accountBrief($account),
                    self::entries($data, 'ai')
                ),
                'bookstack' => array_map(
                    static fn(array $instance): array => [
                        'id' => (string)($instance['id'] ?? ''),
                        'name' => (string)($instance['name'] ?? ''),
                        'base_url' => (string)($instance['base_url'] ?? ''),
                    ],
                    self::entries($data, 'bookstack')
                ),
                'outline_model' => $models['outline']['model'],
                'page_model' => $models['page']['model'],
                'ready' => $models['outline']['configured'] && $models['page']['configured'],
            ];
            if ($actor->isAdmin()) {
                $row['owner'] = (string)($profile['owner'] ?? '');
            }
            $out[] = $row;
        }

        return [
            'profiles' => $out,
            'count' => count($out),
            'next' => $out === []
                ? 'There are no profiles yet. Call list_providers, then create_profile.'
                : 'Call get_profile for one in full, or update_course to point a course at one.',
        ];
    }

    /** @return array<string,mixed> */
    private static function getProfile(Actor $actor, Args $args): array
    {
        ['owner' => $owner, 'row' => $row] = self::resolveProfile($actor, $args);

        // redact() first, then the pattern pass: the first blanks the two
        // fields the domain knows are secret, the second catches anything a
        // newer provider kind has brought with it.
        $data = (array)(Profiles::redact($row)['data'] ?? []);

        $accounts = [];
        foreach (self::entries($data, 'ai') as $account) {
            $entry = self::withoutSecrets($account);
            $entry['kind'] = Providers::kindOf($account);
            $accounts[] = $entry;
        }

        $prompts = [];
        $withText = $args->bool('include_prompt_text');
        foreach ((array)($data['prompts'] ?? []) as $slot => $text) {
            $prompts[(string)$slot] = $withText
                ? (string)$text
                : mb_strlen((string)$text) . ' characters - pass include_prompt_text to read it.';
        }

        $courses = self::coursesUsing($owner, (int)$row['id']);

        return [
            'profile_id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'owner' => $owner,
            'language' => (string)($data['language'] ?? ''),
            'concurrency' => (int)($data['concurrency'] ?? 1),
            'ai_accounts' => $accounts,
            'bookstack' => array_map(
                static fn(array $instance): array => self::withoutSecrets($instance),
                self::entries($data, 'bookstack')
            ),
            'models' => self::modelSummary($data),
            'prompt_overrides' => $prompts,
            'prompt_override_note' => $prompts === []
                ? 'This profile uses the installation\'s prompt library unchanged.'
                : 'These slots replace the installation default; every other slot is inherited.',
            'courses' => $courses,
            'next' => $accounts === []
                ? 'This profile has no AI account. Add one with update_profile, giving ai_kind and api_key.'
                : 'Call list_models to see what these accounts can run, or check_profile to prove the credentials work.',
        ];
    }

    /** @return array<string,mixed> */
    private static function createProfile(Actor $actor, Args $args): array
    {
        $name = $args->requiredStr('name');
        $kind = self::requireKind($args);
        $catalogue = self::catalogueEntry($kind);
        $label = (string)($catalogue['label'] ?? $kind);

        $apiKey = $args->str('api_key');
        if (self::needsKey($catalogue) && $apiKey === '') {
            throw HttpException::unprocessable(
                'A "' . $label . '" account needs an API key, so api_key is required. It is stored on the server and '
                . 'can never be read back - only replaced.'
            );
        }

        // Shaped explicitly, key by key, exactly as Profiles::normalise() will
        // shape it again on the way in: an unknown key is dropped rather than
        // stored, and nothing arrives in the blob by accident.
        $data = Profiles::defaults();
        $accountId = self::newId();
        $data['ai'] = [[
            'id' => $accountId,
            'name' => $label,
            'kind' => $kind,
            'base_url' => self::normaliseUrl($args->has('base_url') ? $args->str('base_url') : self::defaultUrl($catalogue)),
            'api_key' => $apiKey,
            'organization' => '',
            'cli_path' => '',
            'site_url' => '',
            'site_name' => '',
        ]];

        $pageModel = $args->str('model');
        // One model unless told otherwise: a profile whose two slots disagree
        // by accident is a surprise nobody asked for.
        $outlineModel = $args->str('structure_model') !== '' ? $args->str('structure_model') : $pageModel;
        $temperature = self::temperature($args, 0.7);
        $maxTokens = max(0, $args->int('max_tokens', 0));

        $data['models']['page'] = [
            'ai_id' => $accountId,
            'model' => $pageModel,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
        ];
        $data['models']['overview'] = [
            'ai_id' => $accountId,
            'model' => $outlineModel,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
        ];

        if ($args->has('language')) {
            $data['language'] = $args->str('language');
        }
        if ($args->has('concurrency')) {
            $data['concurrency'] = $args->int('concurrency', 2);
        }

        if ($args->str('bookstack_url') !== '') {
            $data['bookstack'] = [self::newInstance($args)];
        } elseif ($args->str('bookstack_token_id') !== '' || $args->str('bookstack_token_secret') !== '') {
            throw HttpException::unprocessable(
                'A BookStack token without bookstack_url has nothing to authenticate against. Give all three of '
                . 'bookstack_url, bookstack_token_id and bookstack_token_secret, or none of them.'
            );
        }

        $profile = Profiles::create($actor->username, $name, $data);
        Audit::record($actor->username, 'profile.create', $name, $kind . ' account, via MCP', 'mcp');

        $ready = $pageModel !== '' && $outlineModel !== '';

        return [
            'profile_id' => (int)$profile['id'],
            'name' => (string)$profile['name'],
            'owner' => $actor->username,
            'ai_id' => $accountId,
            'ai_kind' => $kind,
            'api_key_set' => $apiKey !== '',
            'models' => self::modelSummary((array)$profile['data']),
            'bookstack_configured' => $data['bookstack'] !== [],
            'next' => $ready
                ? 'Call check_profile to prove the credentials work, then update_course to point a course at this profile.'
                : 'No model is chosen yet. Call list_models, then update_profile with model and structure_model.',
        ];
    }

    /** @return array<string,mixed> */
    private static function updateProfile(Actor $actor, Args $args): array
    {
        ['owner' => $owner, 'row' => $row] = self::resolveProfile($actor, $args);

        // The raw blob, secrets and all. It is read, patched and written back
        // without ever being returned: the response is built from the saved
        // profile afterwards, through the redacting summary.
        $data = $row['data'];
        $changed = [];

        $name = $args->has('name') ? $args->requiredStr('name') : (string)$row['name'];
        if ($name !== (string)$row['name']) {
            $changed[] = 'name';
        }

        $accounts = self::entries($data, 'ai');
        $target = null;

        $touchesAccount = $args->has('ai_kind') || $args->str('api_key') !== '' || $args->has('base_url');
        if ($touchesAccount || $args->str('ai_id') !== '') {
            if ($accounts === []) {
                if (!$args->has('ai_kind')) {
                    throw HttpException::unprocessable(
                        'This profile has no AI account yet, so ai_kind is required to add one. Call list_providers '
                        . 'to see the kinds.'
                    );
                }
                $kind = self::requireKind($args);
                $catalogue = self::catalogueEntry($kind);
                if (self::needsKey($catalogue) && $args->str('api_key') === '') {
                    throw HttpException::unprocessable(
                        'A "' . (string)($catalogue['label'] ?? $kind) . '" account needs an API key, so api_key is required.'
                    );
                }
                $accounts[] = [
                    'id' => self::newId(),
                    'name' => (string)($catalogue['label'] ?? $kind),
                    'kind' => $kind,
                    'base_url' => self::normaliseUrl($args->has('base_url') ? $args->str('base_url') : self::defaultUrl($catalogue)),
                    'api_key' => $args->str('api_key'),
                    'organization' => '',
                    'cli_path' => '',
                    'site_url' => '',
                    'site_name' => '',
                ];
                $target = 0;
                $changed[] = 'ai_account_added';
            } else {
                $target = self::accountIndex($accounts, $args);
                $account = $accounts[$target];

                if ($args->has('ai_kind')) {
                    $kind = self::requireKind($args);
                    $previous = self::catalogueEntry(Providers::kindOf($account));
                    $account['kind'] = $kind;
                    // The base URL follows the new kind only while it still
                    // holds the old kind's default: a URL somebody typed is
                    // never thrown away, which is what the browser does too.
                    if (!$args->has('base_url')
                        && ((string)$account['base_url'] === '' || (string)$account['base_url'] === self::defaultUrl($previous))) {
                        $account['base_url'] = self::defaultUrl(self::catalogueEntry($kind));
                    }
                    $changed[] = 'ai_kind';
                }
                if ($args->has('base_url')) {
                    $account['base_url'] = self::normaliseUrl($args->str('base_url'));
                    $changed[] = 'base_url';
                }
                if ($args->str('api_key') !== '') {
                    $account['api_key'] = $args->str('api_key');
                    $changed[] = 'api_key';
                }

                $accounts[$target] = $account;
            }
            $data['ai'] = $accounts;
        }

        $slotAccount = '';
        if ($target !== null) {
            $slotAccount = (string)($accounts[$target]['id'] ?? '');
        } elseif (count($accounts) === 1) {
            $slotAccount = (string)($accounts[0]['id'] ?? '');
        }

        foreach (['model' => 'page', 'structure_model' => 'overview'] as $argument => $slot) {
            if (!$args->has($argument)) {
                continue;
            }
            $data['models'][$slot]['model'] = $args->str($argument);
            // A model with no account behind it never runs, so a slot that has
            // never been pointed anywhere is pointed at the obvious account.
            if ($slotAccount !== ''
                && ((string)($data['models'][$slot]['ai_id'] ?? '') === '' || $args->str('ai_id') !== '')) {
                $data['models'][$slot]['ai_id'] = $slotAccount;
            }
            $changed[] = $argument;
        }

        if ($args->has('temperature')) {
            $temperature = self::temperature($args, 0.7);
            foreach (array_keys(self::SLOTS) as $slot) {
                $data['models'][$slot]['temperature'] = $temperature;
            }
            $changed[] = 'temperature';
        }
        if ($args->has('max_tokens')) {
            $maxTokens = max(0, $args->int('max_tokens', 0));
            foreach (array_keys(self::SLOTS) as $slot) {
                $data['models'][$slot]['max_tokens'] = $maxTokens;
            }
            $changed[] = 'max_tokens';
        }
        if ($args->has('language')) {
            $data['language'] = $args->str('language');
            $changed[] = 'language';
        }
        if ($args->has('concurrency')) {
            $data['concurrency'] = $args->int('concurrency', 2);
            $changed[] = 'concurrency';
        }

        $instances = self::entries($data, 'bookstack');
        $touchesBookStack = $args->has('bookstack_url')
            || $args->has('bookstack_token_id')
            || $args->str('bookstack_token_secret') !== ''
            || self::givenInstanceId($args) !== '';

        if ($touchesBookStack) {
            if ($instances === []) {
                if ($args->str('bookstack_url') === '') {
                    throw HttpException::unprocessable(
                        'This profile has no BookStack instance yet, so bookstack_url is required to add one.'
                    );
                }
                $instances[] = self::newInstance($args);
                $changed[] = 'bookstack_added';
            } else {
                $index = self::instanceIndex($instances, $args);
                $instance = $instances[$index];
                if ($args->has('bookstack_url')) {
                    $instance['base_url'] = self::normaliseUrl($args->str('bookstack_url'));
                    $changed[] = 'bookstack_url';
                }
                if ($args->has('bookstack_token_id')) {
                    $instance['token_id'] = $args->str('bookstack_token_id');
                    $changed[] = 'bookstack_token_id';
                }
                if ($args->str('bookstack_token_secret') !== '') {
                    $instance['token_secret'] = $args->str('bookstack_token_secret');
                    $changed[] = 'bookstack_token_secret';
                }
                $instances[$index] = $instance;
            }
            $data['bookstack'] = $instances;
        }

        if ($changed === []) {
            throw HttpException::unprocessable(
                'Nothing to change. Give at least one field - name, ai_kind, api_key, base_url, model, '
                . 'structure_model, language, temperature, max_tokens, concurrency or one of the bookstack_ fields. '
                . 'An empty api_key is treated as "keep the stored one", not as a change.'
            );
        }

        $updated = Profiles::update($owner, (int)$row['id'], $name, $data);
        Audit::record($actor->username, 'profile.update', $name, implode(', ', $changed) . ', via MCP', 'mcp');

        $models = self::modelSummary((array)$updated['data']);

        return [
            'profile_id' => (int)$updated['id'],
            'name' => (string)$updated['name'],
            'owner' => $owner,
            'changed' => $changed,
            'models' => $models,
            'ai_accounts' => array_map(
                static fn(array $account): array => self::accountBrief($account),
                self::entries((array)Profiles::redact($updated)['data'], 'ai')
            ),
            'next' => in_array('api_key', $changed, true) || in_array('ai_kind', $changed, true)
                ? 'Call check_profile to prove the new credentials work.'
                : 'Call get_profile to see the profile in full.',
        ];
    }

    /** @return array<string,mixed> */
    private static function deleteProfile(Actor $actor, Args $args): array
    {
        ['owner' => $owner, 'row' => $row] = self::resolveProfile($actor, $args);

        // The name has to match. The keys in here cannot be read back before
        // they go, so there is nothing to reconstruct this from afterwards.
        if ($args->requiredStr('confirm_name') !== (string)$row['name']) {
            throw HttpException::unprocessable(
                'confirm_name does not match. The profile is called "' . $row['name'] . '".'
            );
        }

        $courses = self::coursesUsing($owner, (int)$row['id']);
        if ($courses !== []) {
            $names = array_map(static fn(array $c): string => '"' . $c['name'] . '" (id ' . $c['course_id'] . ')', $courses);
            throw HttpException::unprocessable(
                'Profile "' . $row['name'] . '" is still used by ' . count($courses) . ' course(s): '
                . implode(', ', $names) . '. Point them at another profile with update_course, or delete them, '
                . 'before deleting this profile.'
            );
        }

        Profiles::delete($owner, (int)$row['id']);
        Audit::record($actor->username, 'profile.delete', (string)$row['name'], 'via MCP', 'mcp');

        return [
            'deleted' => true,
            'profile_id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'next' => 'Call list_profiles to see what is left.',
        ];
    }

    /** @return array<string,mixed> */
    private static function listModels(Actor $actor, Args $args): array
    {
        ['row' => $row] = self::resolveProfile($actor, $args);
        $data = $row['data'];

        $accountId = self::accountId($data, $args);
        $account = Providers::account($data, $accountId);
        $provider = Providers::fromAccount($account);

        // Off to the provider over the network, which on a slow gateway is
        // tens of seconds.
        Runtime::beginLongRequest();

        $models = $provider->models();
        $batch = $provider->batchModels();
        $supportsBatch = $provider->supportsBatch();

        $out = [];
        foreach ($models as $model) {
            $id = (string)$model;
            $queueable = $supportsBatch && ($batch === [] || in_array($id, $batch, true));
            $out[] = [
                'model' => $id,
                'batch' => $queueable,
                'batch_id' => $queueable ? $id . ModelId::BATCH : null,
            ];
        }

        return [
            'profile_id' => (int)$row['id'],
            'ai_id' => $accountId,
            'kind' => $provider->kind(),
            'provider' => $provider->label(),
            'count' => count($out),
            'models' => $out,
            'supports_batch' => $supportsBatch,
            'batch_reported' => $batch !== [],
            'batch_note' => $supportsBatch
                ? 'Write a model as "' . ($out[0]['model'] ?? 'model') . ':batch" to send the whole course through '
                    . 'the provider\'s queue at about half the price. The answer can take hours, so it belongs on the '
                    . 'page slot; the outline slot should stay live.'
                : 'This provider has no batch queue, so the ":batch" suffix would have nothing to submit to.',
            'next' => 'Pass the chosen id to update_profile as model (the pages) or structure_model (the outline).',
        ];
    }

    /** @return array<string,mixed> */
    private static function checkProfile(Actor $actor, Args $args): array
    {
        ['row' => $row] = self::resolveProfile($actor, $args);
        $data = $row['data'];

        $accountId = self::accountId($data, $args);
        $account = Providers::account($data, $accountId);
        $provider = Providers::fromAccount($account);

        Runtime::beginLongRequest();

        if ($provider instanceof ClaudeCliProvider) {
            // The subscription account is the one with three separate ways to
            // be broken, so it reports them itself rather than being reduced
            // to a yes or a no.
            $status = $provider->status();
            return [
                'profile_id' => (int)$row['id'],
                'ai_id' => $accountId,
                'kind' => $provider->kind(),
                'provider' => $provider->label(),
                'ok' => (bool)($status['ok'] ?? false),
                'check' => $status,
                'next' => ($status['ok'] ?? false)
                    ? 'The account works. Call list_models to choose one, then update_profile.'
                    : 'Fix what the check reports, then call check_profile again.',
            ];
        }

        $models = $provider->models();

        return [
            'profile_id' => (int)$row['id'],
            'ai_id' => $accountId,
            'kind' => $provider->kind(),
            'provider' => $provider->label(),
            'ok' => true,
            'detail' => count($models) . ' model(s) reachable.',
            'supports_batch' => $provider->supportsBatch(),
            'next' => 'The base URL and the key are both good. Call list_models to choose a model, then update_profile.',
        ];
    }

    /** @return array<string,mixed> */
    private static function listProviders(): array
    {
        // Passed through whole. The catalogue grows, and a handler that picked
        // out four known fields would quietly hide everything a new provider
        // brought with it.
        $providers = [];
        foreach (Providers::catalogue() as $entry) {
            if (is_array($entry) && (string)($entry['kind'] ?? '') !== '') {
                $providers[] = $entry;
            }
        }

        return [
            'providers' => $providers,
            'kinds' => Providers::kinds(),
            'count' => count($providers),
            'batch_note' => 'Where an entry reports a batch queue, a model written as "model-id:batch" in a profile '
                . 'goes through that queue at about half the price, in exchange for an answer that can take hours.',
            'key_note' => 'A kind with needs_key false authenticates some other way - the Claude subscription account '
                . 'uses the CLI already signed in on this server and stores no key at all.',
            'next' => 'Call create_profile with the kind you want as ai_kind.',
        ];
    }

    /** @return array<string,mixed> */
    private static function listShelves(Actor $actor, Args $args): array
    {
        ['row' => $row] = self::resolveProfile($actor, $args);
        $data = $row['data'];

        $instanceId = self::instanceId($data, $args);
        $client = BookStackClient::fromProfile($data, $instanceId);

        Runtime::beginLongRequest();
        $shelves = $client->shelves();

        return [
            'profile_id' => (int)$row['id'],
            'bookstack_id' => $instanceId,
            'base_url' => $client->baseUrl(),
            'count' => count($shelves),
            'shelves' => $shelves,
            'next' => $shelves === []
                ? 'This instance has no shelves. A book can be published without one.'
                : 'A course is filed onto a shelf by its own publishing settings, not by the profile - call '
                    . 'update_course with shelf_id and shelf_name before publishing it.',
        ];
    }

    /* -------------------------------------------------------------- helpers */

    /**
     * A profile the actor may reach, and whose it is.
     *
     * The row is RAW - `Profiles::require()` does not redact - so nothing that
     * comes out of here may be returned to a client without going through
     * `summarise`-style redaction first.
     *
     * @return array{owner:string,row:array<string,mixed>}
     */
    private static function resolveProfile(Actor $actor, Args $args): array
    {
        $id = $args->id('profile_id');
        $owner = (string)Access::profile($actor, $id)['username'];

        return ['owner' => $owner, 'row' => Profiles::require($owner, $id)];
    }

    /**
     * The entries of one group, as a clean list.
     *
     * @param array<string,mixed> $data
     * @return array<int,array<string,mixed>>
     */
    private static function entries(array $data, string $group): array
    {
        $out = [];
        foreach ((array)($data[$group] ?? []) as $entry) {
            if (is_array($entry)) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * One AI account, small enough for a listing and with nothing secret in it.
     *
     * @param array<string,mixed> $account
     * @return array<string,mixed>
     */
    private static function accountBrief(array $account): array
    {
        $entry = self::withoutSecrets($account);

        return [
            'id' => (string)($account['id'] ?? ''),
            'name' => (string)($account['name'] ?? ''),
            'kind' => Providers::kindOf($account),
            'base_url' => (string)($account['base_url'] ?? ''),
            'api_key_set' => (bool)($entry['api_key_set'] ?? false),
        ];
    }

    /**
     * Both model slots, in the words a person uses for them.
     *
     * @param array<string,mixed> $data
     * @return array<string,array<string,mixed>>
     */
    private static function modelSummary(array $data): array
    {
        $names = [];
        foreach (self::entries($data, 'ai') as $account) {
            $names[(string)($account['id'] ?? '')] = (string)($account['name'] ?? '');
        }

        $out = [];
        foreach (self::SLOTS as $slot => $label) {
            $config = (array)($data['models'][$slot] ?? []);
            $model = trim((string)($config['model'] ?? ''));
            $aiId = (string)($config['ai_id'] ?? '');

            $out[$label] = [
                'slot' => $slot,
                'model' => $model,
                'batched' => $model !== '' && ModelId::isBatch($model),
                'ai_id' => $aiId,
                'ai_account' => $names[$aiId] ?? '',
                'temperature' => (float)($config['temperature'] ?? 0.7),
                'max_tokens' => (int)($config['max_tokens'] ?? 0),
                'configured' => $model !== '' && $aiId !== '',
            ];
        }
        return $out;
    }

    /**
     * Everything about an entry except the credentials.
     *
     * `Profiles::redact()` has already blanked the two fields the domain knows
     * about and left a `_set` flag behind. This second pass exists for the
     * fields it does not know about: any key that reads like a credential is
     * replaced by whether it holds anything, so a provider kind added after
     * this file was written cannot leak a new sort of secret through it.
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private static function withoutSecrets(array $entry): array
    {
        $out = [];
        foreach ($entry as $key => $value) {
            $name = (string)$key;
            if (str_ends_with($name, '_set')) {
                continue; // folded back in beside the field it describes
            }
            if (preg_match(self::SECRET_PATTERN, $name) === 1) {
                $flag = $entry[$name . '_set'] ?? null;
                $out[$name . '_set'] = $flag === null
                    ? (is_scalar($value) && trim((string)$value) !== '')
                    : (bool)$flag;
                continue;
            }
            $out[$name] = is_array($value) ? self::withoutSecrets($value) : $value;
        }
        return $out;
    }

    /** @return array<int,array{course_id:int,name:string}> */
    private static function coursesUsing(string $owner, int $profileId): array
    {
        $out = [];
        foreach (Projects::all($owner) as $course) {
            if ((int)($course['profile_id'] ?? 0) === $profileId) {
                $out[] = ['course_id' => (int)$course['id'], 'name' => (string)$course['name']];
            }
        }
        return $out;
    }

    /** The account kind, checked against whatever the catalogue offers today. */
    private static function requireKind(Args $args): string
    {
        $kind = strtolower($args->requiredStr('ai_kind'));
        $known = Providers::kinds();
        if (!in_array($kind, $known, true)) {
            throw HttpException::unprocessable(
                'ai_kind must be one of: ' . implode(', ', $known) . '. Call list_providers to see what each one is.'
            );
        }
        return $kind;
    }

    /**
     * The catalogue entry for a kind, or null when the catalogue does not list it.
     *
     * @return array<string,mixed>|null
     */
    private static function catalogueEntry(string $kind): ?array
    {
        foreach (Providers::catalogue() as $entry) {
            if (is_array($entry) && (string)($entry['kind'] ?? '') === $kind) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * Whether a kind needs an API key.
     *
     * Unknown means yes: refusing to store an account without a key is
     * recoverable, and storing one that can never authenticate is a failure
     * three calls later with nothing to point at.
     *
     * @param array<string,mixed>|null $catalogue
     */
    private static function needsKey(?array $catalogue): bool
    {
        if ($catalogue === null || !array_key_exists('needs_key', $catalogue)) {
            return true;
        }
        return (bool)$catalogue['needs_key'];
    }

    /** @param array<string,mixed>|null $catalogue */
    private static function defaultUrl(?array $catalogue): string
    {
        return self::normaliseUrl((string)($catalogue['base_url'] ?? ''));
    }

    private static function normaliseUrl(string $url): string
    {
        return rtrim(trim($url), '/');
    }

    /** Temperature arrives as a JSON number, which Args reads as text. */
    private static function temperature(Args $args, float $default): float
    {
        if (!$args->has('temperature')) {
            return $default;
        }
        return max(0.0, min(2.0, (float)$args->str('temperature')));
    }

    /**
     * Which AI account a call means, when the profile holds more than one.
     *
     * @param array<string,mixed> $data
     */
    private static function accountId(array $data, Args $args): string
    {
        $accounts = self::entries($data, 'ai');
        $given = $args->str('ai_id');

        if ($given !== '') {
            Providers::account($data, $given); // throws by name when it is not there
            return $given;
        }
        if ($accounts === []) {
            throw HttpException::unprocessable(
                'This profile has no AI account. Add one with update_profile, giving ai_kind and api_key.'
            );
        }
        if (count($accounts) === 1) {
            return (string)($accounts[0]['id'] ?? '');
        }
        throw HttpException::unprocessable(
            'This profile holds ' . count($accounts) . ' AI accounts, so ai_id is required. They are: '
            . self::describeEntries($accounts) . '.'
        );
    }

    /**
     * The index of the AI account being edited.
     *
     * @param array<int,array<string,mixed>> $accounts
     */
    private static function accountIndex(array $accounts, Args $args): int
    {
        $given = $args->str('ai_id');

        if ($given === '') {
            if (count($accounts) === 1) {
                return 0;
            }
            throw HttpException::unprocessable(
                'This profile holds ' . count($accounts) . ' AI accounts, so ai_id is required to say which one to '
                . 'change. They are: ' . self::describeEntries($accounts) . '.'
            );
        }

        foreach ($accounts as $index => $account) {
            if ((string)($account['id'] ?? '') === $given) {
                return $index;
            }
        }
        throw HttpException::unprocessable(
            'AI account "' . $given . '" is not part of this profile. It holds: ' . self::describeEntries($accounts) . '.'
        );
    }

    /**
     * Which BookStack instance a call means.
     *
     * @param array<string,mixed> $data
     */
    private static function instanceId(array $data, Args $args): string
    {
        $instances = self::entries($data, 'bookstack');
        $given = self::givenInstanceId($args);

        if ($given === '' && count($instances) === 1) {
            return (string)($instances[0]['id'] ?? '');
        }
        if ($given !== '') {
            return $given; // BookStackClient::fromProfile reports an unknown id itself
        }
        if ($instances === []) {
            throw HttpException::unprocessable(
                'This profile has no BookStack instance. Add one with update_profile, giving bookstack_url, '
                . 'bookstack_token_id and bookstack_token_secret.'
            );
        }
        throw HttpException::unprocessable(
            'This profile holds ' . count($instances) . ' BookStack instances, so bookstack_id is required. They are: '
            . self::describeEntries($instances) . '.'
        );
    }

    /**
     * Which BookStack instance the caller named.
     *
     * `bookstack_id` is the declared name across every tool here, because a
     * profile also holds AI accounts with ids of their own and "instance" says
     * nothing about which sort. `instance_id` was the name list_bookstack_shelves
     * used and is still read, silently, so a client that learned the old one
     * keeps working.
     */
    private static function givenInstanceId(Args $args): string
    {
        $given = $args->str('bookstack_id');
        return $given !== '' ? $given : $args->str('instance_id');
    }

    /**
     * The index of the BookStack instance being edited.
     *
     * @param array<int,array<string,mixed>> $instances
     */
    private static function instanceIndex(array $instances, Args $args): int
    {
        $given = self::givenInstanceId($args);

        if ($given === '') {
            if (count($instances) === 1) {
                return 0;
            }
            throw HttpException::unprocessable(
                'This profile holds ' . count($instances) . ' BookStack instances, so bookstack_id is required to say '
                . 'which one to change. They are: ' . self::describeEntries($instances) . '.'
            );
        }

        foreach ($instances as $index => $instance) {
            if ((string)($instance['id'] ?? '') === $given) {
                return $index;
            }
        }
        throw HttpException::unprocessable(
            'BookStack instance "' . $given . '" is not part of this profile. It holds: '
            . self::describeEntries($instances) . '.'
        );
    }

    /** @param array<int,array<string,mixed>> $entries */
    private static function describeEntries(array $entries): string
    {
        return implode(', ', array_map(
            static fn(array $entry): string => '"' . (string)($entry['id'] ?? '') . '" (' . (string)($entry['name'] ?? '') . ')',
            $entries
        ));
    }

    /**
     * A new BookStack instance from the arguments.
     *
     * @return array<string,mixed>
     */
    private static function newInstance(Args $args): array
    {
        $tokenId = $args->str('bookstack_token_id');
        $tokenSecret = $args->str('bookstack_token_secret');
        if ($tokenId === '' || $tokenSecret === '') {
            throw HttpException::unprocessable(
                'A BookStack instance needs bookstack_token_id and bookstack_token_secret as well as bookstack_url. '
                . 'Both come from Edit Profile - API Tokens in BookStack; the secret is written only and is never '
                . 'returned by any tool here.'
            );
        }

        return [
            'id' => self::newId(),
            'name' => 'BookStack',
            'base_url' => self::normaliseUrl($args->str('bookstack_url')),
            'token_id' => $tokenId,
            'token_secret' => $tokenSecret,
        ];
    }

    /** An opaque id for an entry inside the profile blob, matching what the browser generates. */
    private static function newId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
