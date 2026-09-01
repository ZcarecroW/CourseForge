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
use CourseForge\Publish\Targets;
use CourseForge\Security\Access;
use CourseForge\Security\Actor;
use CourseForge\Support\Audit;
use CourseForge\Support\Config;
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
                    'preset_key' => Schema::string(
                        'A row of the provider catalogue rather than a raw driver kind - "groq", "together", '
                        . '"deepseek", "llamacpp" and the rest, as list_providers reports them. Naming one picks the '
                        . 'endpoint and the label with it, which is what the browser\'s provider picker does; '
                        . 'ai_kind is then optional. Every preset runs on the OpenAI-compatible driver.',
                        'groq'
                    ),
                    'organization' => Schema::string('The OpenAI organization id, for an account that belongs to one.'),
                    'cli_path' => Schema::string(
                        'Where the Claude CLI lives on this server, for the subscription account when it is not on '
                        . 'the PATH.'
                    ),
                    'site_url' => Schema::string('OpenRouter only: the site this traffic is attributed to.'),
                    'site_name' => Schema::string('OpenRouter only: the name shown beside that attribution.'),
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
                required: ['name'],
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
                    'ai_name' => Schema::string('A new name for that AI account, for the picker.'),
                    'ai_kind' => Schema::enum(
                        'Change the account to another kind. The base URL follows the new kind\'s default unless you '
                        . 'gave one yourself or had typed one.',
                        Providers::kinds()
                    ),
                    'api_key' => Schema::string('A new key, replacing the stored one. Empty or omitted keeps what is stored.'),
                    'base_url' => Schema::string('A new endpoint for the account.'),
                    'preset_key' => Schema::string(
                        'A row of the provider catalogue rather than a raw driver kind - "groq", "together", '
                        . '"deepseek", "llamacpp" and the rest, as list_providers reports them. Naming one picks the '
                        . 'endpoint and the label with it, which is what the browser\'s provider picker does; '
                        . 'ai_kind is then optional. Every preset runs on the OpenAI-compatible driver.',
                        'groq'
                    ),
                    'organization' => Schema::string('The OpenAI organization id, for an account that belongs to one.'),
                    'cli_path' => Schema::string(
                        'Where the Claude CLI lives on this server, for the subscription account when it is not on '
                        . 'the PATH.'
                    ),
                    'site_url' => Schema::string('OpenRouter only: the site this traffic is attributed to.'),
                    'site_name' => Schema::string('OpenRouter only: the name shown beside that attribution.'),
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
                    'bookstack_name' => Schema::string('A new name for that BookStack instance, for the picker.'),
                    'bookstack_url' => Schema::string('The BookStack base URL.'),
                    'bookstack_token_id' => Schema::string('The BookStack API token id.'),
                    'bookstack_token_secret' => Schema::string('A new BookStack token secret. Empty or omitted keeps the stored one.'),
                    'typography' => Schema::bool(
                        'Whether a page generated with this profile has its punctuation set the way the course '
                        . 'language sets it before it is stored. Omitted leaves the profile as it is; a profile that '
                        . 'has never said follows the installation. This decides nothing about courses that are '
                        . 'already written - fix_typography corrects one of those whatever this says.'
                    ),
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
                name: 'add_ai_account',
                scope: Scopes::PROFILES,
                title: 'Add an AI account to a profile',
                description: 'Adds another AI account to a profile that already has one - an OpenAI key beside an '
                    . 'Anthropic one, a self-hosted gateway beside both, the Claude subscription beside a metered '
                    . 'key. update_profile changes an account a profile already holds; this is how it gets a second. '
                    . 'Call list_providers first for the kinds this installation speaks. Nothing points at the new '
                    . 'account until you say so: use set_model_slot to send the outline or the pages through it. The '
                    . 'key is stored on the server and can never be read back. Costs nothing.',
                properties: [
                    'profile_id' => Schema::int('The profile to add the account to.'),
                    'kind' => Schema::enum(
                        'The kind of AI account. Call list_providers for what each one is and whether it needs a key.',
                        Providers::kinds()
                    ),
                    'name' => Schema::string(
                        'A name for this account, which is how the model slots refer to it. Defaults to the '
                        . 'provider\'s own label.',
                        'OpenAI - fallback'
                    ),
                    'api_key' => Schema::string(
                        'The API key. Required for every kind whose needs_key is true. Written only: no tool will '
                        . 'ever hand it back.'
                    ),
                    'base_url' => Schema::string(
                        'The endpoint. Omit to use the default list_providers gives for this kind.',
                        'https://api.openai.com/v1'
                    ),
                    'preset_key' => Schema::string(
                        'A row of the provider catalogue rather than a raw driver kind - "groq", "together", '
                        . '"deepseek", "llamacpp" and the rest, as list_providers reports them. Naming one picks the '
                        . 'endpoint and the label with it, which is what the browser\'s provider picker does; '
                        . 'ai_kind is then optional. Every preset runs on the OpenAI-compatible driver.',
                        'groq'
                    ),
                    'organization' => Schema::string('The OpenAI organization id, for an account that belongs to one.'),
                    'cli_path' => Schema::string(
                        'Where the Claude CLI lives on this server, for the subscription account when it is not on '
                        . 'the PATH.'
                    ),
                    'site_url' => Schema::string('OpenRouter only: the site this traffic is attributed to.'),
                    'site_name' => Schema::string('OpenRouter only: the name shown beside that attribution.'),
                ],
                required: ['profile_id'],
                handler: static fn(Actor $actor, array $args): array => self::addAiAccount($actor, Args::of($args)),
            ),

            new Tool(
                name: 'delete_ai_account',
                scope: Scopes::PROFILES,
                title: 'Remove an AI account from a profile',
                description: 'Takes one AI account out of a profile. Its key goes with it and cannot be recovered, '
                    . 'because nothing here can read a key back to copy it elsewhere first. A model slot pointing at '
                    . 'the account is left pointing at nothing, which stops that slot generating until it is pointed '
                    . 'somewhere with set_model_slot - so the answer says which slots those were. Removing the last '
                    . 'account of a profile is refused: a profile with no account cannot write a word, and deleting '
                    . 'the profile is the honest way to say that. Costs nothing.',
                properties: [
                    'profile_id' => Schema::int('The profile the account belongs to.'),
                    'ai_id' => Schema::string('The account to remove. get_profile lists the ids.'),
                ],
                required: ['profile_id', 'ai_id'],
                handler: static fn(Actor $actor, array $args): array => self::deleteAiAccount($actor, Args::of($args)),
                destructive: true,
            ),

            new Tool(
                name: 'add_bookstack_instance',
                scope: Scopes::PROFILES,
                title: 'Add a BookStack instance to a profile',
                description: 'Adds another BookStack instance to a profile that already has one - a staging wiki '
                    . 'beside a live one, the wikis of two departments, a customer\'s install beside your own. '
                    . 'update_profile changes an instance a profile already holds; this is how it gets a second, '
                    . 'which is what a course needs before set_publish_targets can send it to more than one wiki. '
                    . 'The token secret is stored on the server and can never be read back. Costs nothing.',
                properties: [
                    'profile_id' => Schema::int('The profile to add the instance to.'),
                    'url' => Schema::string('The BookStack base URL, without a trailing slash.', 'https://docs.example.com'),
                    'token_id' => Schema::string('The BookStack API token id. Not a secret; it is readable back.'),
                    'token_secret' => Schema::string(
                        'The BookStack API token secret. Written only: no tool will ever hand it back.'
                    ),
                    'name' => Schema::string(
                        'A name for this instance, which is how a course names its destination.',
                        'Staging wiki'
                    ),
                ],
                required: ['profile_id', 'url', 'token_id', 'token_secret'],
                handler: static fn(Actor $actor, array $args): array => self::addInstance($actor, Args::of($args)),
            ),

            new Tool(
                name: 'delete_bookstack_instance',
                scope: Scopes::PROFILES,
                title: 'Remove a BookStack instance from a profile',
                description: 'Takes one BookStack instance out of a profile, with its token. A course that publishes '
                    . 'to that instance loses the destination and the record of the book it made there, so publishing '
                    . 'to the same wiki later would create a second book beside the first - the call is refused with '
                    . 'the names of those courses unless you send confirm. Nothing is deleted inside BookStack '
                    . 'itself, here or ever. Costs nothing.',
                properties: [
                    'profile_id' => Schema::int('The profile the instance belongs to.'),
                    'bookstack_id' => Schema::string('The instance to remove. get_profile lists the ids.'),
                    'confirm' => Schema::bool(
                        'Go through with it even though courses publish to this instance. Their destinations and the '
                        . 'record of what was published there are forgotten.'
                    ),
                ],
                required: ['profile_id', 'bookstack_id'],
                handler: static fn(Actor $actor, array $args): array => self::deleteInstance($actor, Args::of($args)),
                destructive: true,
            ),

            new Tool(
                name: 'set_model_slot',
                scope: Scopes::PROFILES,
                title: 'Point one model slot at an account and a model',
                description: 'Sets the outline slot or the page slot on its own: which AI account it runs through, '
                    . 'which model, and that slot\'s own temperature and token ceiling. update_profile sets both '
                    . 'slots together, which is right when a profile has one account and wrong as soon as it has two '
                    . '- the outline is one call and can afford the expensive model, while the pages are hundreds '
                    . 'and may want a cheaper one, a different provider, or ":batch" on the end to go through the '
                    . 'provider\'s queue at about half price. Call list_models for the ids an account will accept. '
                    . 'Costs nothing.',
                properties: [
                    'profile_id' => Schema::int('The profile to change.'),
                    'slot' => Schema::enum(
                        'Which slot: "outline" designs the course structure, "page" writes the pages.',
                        ['outline', 'page']
                    ),
                    'model' => Schema::string(
                        'The model id, as list_models reports it. Add ":batch" to route this slot through the '
                        . 'provider\'s queue.',
                        'claude-opus-5'
                    ),
                    'ai_id' => Schema::string(
                        'The AI account this slot runs through. Omit to leave it where it points, or when the '
                        . 'profile holds only one account.'
                    ),
                    'temperature' => [
                        'type' => 'number',
                        'description' => 'Sampling temperature for this slot alone. 0.7 unless you have a reason.',
                        'minimum' => 0,
                        'maximum' => 2,
                    ],
                    'max_tokens' => Schema::int(
                        'The token ceiling for one reply from this slot. 0 means the provider\'s own default.',
                        0
                    ),
                ],
                required: ['profile_id', 'slot'],
                handler: static fn(Actor $actor, array $args): array => self::setModelSlot($actor, Args::of($args)),
                idempotent: true,
            ),

            new Tool(
                name: 'list_prompt_slots',
                scope: Scopes::PROFILES,
                title: 'List the prompt slots a profile can override',
                description: 'Every prompt slot this installation has: its key, the group it belongs to, what it is '
                    . 'for, the placeholders it may use, and the wording currently in force for the installation - '
                    . 'which is what a profile that overrides nothing uses. This is the read a profile needs before '
                    . 'set_profile_prompts can change one, and unlike get_prompts it needs no administrator, because '
                    . 'overriding a slot for one profile is not the same power as rewriting it for everybody. '
                    . 'Costs nothing.',
                properties: [
                    'group' => Schema::string('One group only, as the group field of a slot reports it.', 'page'),
                    'include_text' => Schema::bool(
                        'Include the full wording of each slot rather than only what it is for. These are long.'
                    ),
                ],
                required: [],
                handler: static fn(Actor $actor, array $args): array => self::listPromptSlots(Args::of($args)),
                readOnly: true,
                idempotent: true,
            ),

            new Tool(
                name: 'set_profile_prompts',
                scope: Scopes::PROFILES,
                title: 'Override prompt slots for one profile',
                description: 'Replaces the wording this profile uses for one or more prompt slots, leaving the '
                    . 'installation\'s own prompts alone for every other profile. This is the per-profile half of '
                    . 'get_prompts and set_prompts, which are the installation-wide pair and need an administrator: '
                    . 'a profile override needs only the profile. Call get_prompts for the slot names and what each '
                    . 'one is for, and get_profile with include_prompt_text to read back what this profile '
                    . 'currently overrides. A slot named in reset goes back to the installation\'s wording; an empty '
                    . 'string is a deliberate override that sends nothing for that slot, which is not the same '
                    . 'thing. Costs nothing.',
                properties: [
                    'profile_id' => Schema::int('The profile to change.'),
                    'prompts' => [
                        'type' => 'object',
                        'description' => 'Slot name to the text this profile should use instead. Unknown slot names '
                            . 'are refused rather than silently stored.',
                        'additionalProperties' => ['type' => 'string'],
                    ],
                    'reset' => [
                        'type' => 'array',
                        'description' => 'Slot names to stop overriding, so the installation\'s wording applies again.',
                        'items' => ['type' => 'string'],
                    ],
                ],
                required: ['profile_id'],
                handler: static fn(Actor $actor, array $args): array => self::setProfilePrompts($actor, Args::of($args)),
                idempotent: true,
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
        ['kind' => $kind, 'preset' => $preset, 'catalogue' => $catalogue] = self::chosenProvider($args, 'ai_kind');
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
        $data['ai'] = [self::accountExtras($args, [
            'id' => $accountId,
            'name' => $label,
            'kind' => $kind,
            'preset_key' => $preset,
            'base_url' => self::normaliseUrl($args->has('base_url') ? $args->str('base_url') : self::defaultUrl($catalogue)),
            'api_key' => $apiKey,
            'organization' => '',
            'cli_path' => '',
            'site_url' => '',
            'site_name' => '',
        ])];

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

        $touchesAccount = $args->has('ai_kind') || $args->str('api_key') !== '' || $args->has('base_url')
            || $args->has('ai_name') || $args->has('preset_key') || self::hasExtras($args);
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

                if ($args->has('ai_kind') || $args->has('preset_key')) {
                    ['kind' => $kind, 'preset' => $preset, 'catalogue' => $chosen]
                        = self::chosenProvider($args, 'ai_kind', Providers::kindOf($account));
                    $previous = Providers::entryFor($account);
                    $account['kind'] = $kind;
                    $account['preset_key'] = $preset;
                    // The base URL follows the new kind only while it still
                    // holds the old kind's default: a URL somebody typed is
                    // never thrown away, which is what the browser does too.
                    if (!$args->has('base_url')
                        && ((string)$account['base_url'] === '' || (string)$account['base_url'] === self::defaultUrl($previous))) {
                        $account['base_url'] = self::defaultUrl($chosen);
                    }
                    $changed[] = $args->has('preset_key') ? 'preset_key' : 'ai_kind';
                }
                if ($args->has('base_url')) {
                    $account['base_url'] = self::normaliseUrl($args->str('base_url'));
                    $changed[] = 'base_url';
                }
                if ($args->str('api_key') !== '') {
                    $account['api_key'] = $args->str('api_key');
                    $changed[] = 'api_key';
                }
                if ($args->has('ai_name')) {
                    $account['name'] = $args->requiredStr('ai_name');
                    $changed[] = 'ai_name';
                }
                foreach (self::ACCOUNT_EXTRAS as $extra) {
                    if ($args->has($extra)) {
                        $changed[] = $extra;
                    }
                }
                $account = self::accountExtras($args, $account);

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
            || $args->has('bookstack_name')
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
                if ($args->has('bookstack_name')) {
                    $instance['name'] = $args->requiredStr('bookstack_name');
                    $changed[] = 'bookstack_name';
                }
                $instances[$index] = $instance;
            }
            $data['bookstack'] = $instances;
        }

        if ($args->has('typography')) {
            $data['typography'] = $args->bool('typography');
            $changed[] = 'typography';
        }

        if ($changed === []) {
            throw HttpException::unprocessable(
                'Nothing to change. Give at least one field - name, ai_kind, ai_name, api_key, base_url, model, '
                . 'structure_model, language, temperature, max_tokens, concurrency, typography or one of the '
                . 'bookstack_ fields. An empty api_key is treated as "keep the stored one", not as a change.'
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


    /**
     * Adds a second AI account, which update_profile deliberately will not do.
     *
     * update_profile's account arguments name *the* account of a profile that
     * has one, and adding through them would mean an argument that edits when
     * an id is given and creates when it is not - so a mistyped ai_id would
     * quietly make a new account holding the key that was meant to replace an
     * old one. Adding is its own verb here for the same reason it is its own
     * button in the browser.
     *
     * @return array<string,mixed>
     */
    private static function addAiAccount(Actor $actor, Args $args): array
    {
        ['owner' => $owner, 'row' => $row] = self::resolveProfile($actor, $args);
        $data = $row['data'];

        ['kind' => $kind, 'preset' => $preset, 'catalogue' => $catalogue] = self::chosenProvider($args, 'kind');
        if (self::needsKey($catalogue) && $args->str('api_key') === '') {
            throw HttpException::unprocessable(
                'A "' . (string)($catalogue['label'] ?? $kind) . '" account needs an API key, so api_key is required.'
            );
        }

        $accounts = self::entries($data, 'ai');
        $accounts[] = self::accountExtras($args, [
            'id' => self::newId(),
            'name' => $args->has('name') ? $args->requiredStr('name') : (string)($catalogue['label'] ?? $kind),
            'kind' => $kind,
            'preset_key' => $preset,
            'base_url' => self::normaliseUrl($args->has('base_url') ? $args->str('base_url') : self::defaultUrl($catalogue)),
            'api_key' => $args->str('api_key'),
            'organization' => '',
            'cli_path' => '',
            'site_url' => '',
            'site_name' => '',
        ]);
        $data['ai'] = $accounts;

        $updated = Profiles::update($owner, (int)$row['id'], (string)$row['name'], $data);
        Audit::record($actor->username, 'profile.update', (string)$row['name'], 'ai_account_added, via MCP', 'mcp');

        $stored = self::entries((array)Profiles::redact($updated)['data'], 'ai');
        $added = $stored[count($stored) - 1] ?? [];

        return [
            'added' => true,
            'profile_id' => (int)$updated['id'],
            'ai_id' => (string)($added['id'] ?? ''),
            'ai_accounts' => array_map(
                static fn(array $account): array => self::accountBrief($account),
                $stored
            ),
            'next_step' => 'check_profile with this ai_id proves the credentials work, list_models says what it can '
                . 'run, and set_model_slot points a slot at it - nothing uses a new account until one does.',
        ];
    }

    /** @return array<string,mixed> */
    private static function deleteAiAccount(Actor $actor, Args $args): array
    {
        ['owner' => $owner, 'row' => $row] = self::resolveProfile($actor, $args);
        $data = $row['data'];

        $accounts = self::entries($data, 'ai');
        if (count($accounts) <= 1) {
            throw HttpException::unprocessable(
                'This profile holds only this one AI account, and a profile with none cannot generate anything. '
                . 'Add another with add_ai_account first, or delete the whole profile with delete_profile.'
            );
        }

        $index = self::accountIndex($accounts, $args);
        $removed = $accounts[$index];
        $removedId = (string)($removed['id'] ?? '');
        array_splice($accounts, $index, 1);
        $data['ai'] = $accounts;

        // A slot left pointing at an account that is gone does not generate,
        // and says so nowhere until somebody presses Generate. Naming the slots
        // here is the only warning there is going to be.
        $orphaned = [];
        foreach (array_keys(self::SLOTS) as $slot) {
            if ((string)($data['models'][$slot]['ai_id'] ?? '') === $removedId) {
                $data['models'][$slot]['ai_id'] = '';
                $orphaned[] = self::SLOTS[$slot];
            }
        }

        $updated = Profiles::update($owner, (int)$row['id'], (string)$row['name'], $data);
        Audit::record(
            $actor->username,
            'profile.update',
            (string)$row['name'],
            'ai_account_removed ' . (string)($removed['name'] ?? $removedId) . ', via MCP',
            'mcp'
        );

        return [
            'removed' => true,
            'profile_id' => (int)$updated['id'],
            'ai_id' => $removedId,
            'name' => (string)($removed['name'] ?? ''),
            'slots_left_unset' => $orphaned,
            'ai_accounts' => array_map(
                static fn(array $account): array => self::accountBrief($account),
                self::entries((array)Profiles::redact($updated)['data'], 'ai')
            ),
            'next_step' => $orphaned === []
                ? 'Nothing pointed at it, so nothing else has to change.'
                : 'set_model_slot has to point the ' . implode(' and ', $orphaned)
                    . ' slot at another account before this profile generates again.',
        ];
    }

    /** @return array<string,mixed> */
    private static function addInstance(Actor $actor, Args $args): array
    {
        ['owner' => $owner, 'row' => $row] = self::resolveProfile($actor, $args);
        $data = $row['data'];

        $url = self::normaliseUrl($args->requiredStr('url'));
        if ($url === '') {
            throw HttpException::unprocessable('url must be the BookStack base URL, such as https://docs.example.com.');
        }

        $instances = self::entries($data, 'bookstack');
        $instances[] = [
            'id' => self::newId(),
            'name' => $args->has('name') ? $args->requiredStr('name') : 'BookStack',
            'base_url' => $url,
            'token_id' => $args->requiredStr('token_id'),
            'token_secret' => $args->requiredStr('token_secret'),
        ];
        $data['bookstack'] = $instances;

        $updated = Profiles::update($owner, (int)$row['id'], (string)$row['name'], $data);
        Audit::record($actor->username, 'profile.update', (string)$row['name'], 'bookstack_added ' . $url . ', via MCP', 'mcp');

        $stored = self::entries((array)Profiles::redact($updated)['data'], 'bookstack');
        $added = $stored[count($stored) - 1] ?? [];

        return [
            'added' => true,
            'profile_id' => (int)$updated['id'],
            'bookstack_id' => (string)($added['id'] ?? ''),
            'instances' => array_map(
                static fn(array $instance): array => self::instanceBrief($instance),
                $stored
            ),
            'next_step' => 'list_bookstack_shelves with this bookstack_id proves the token works, and '
                . 'set_publish_targets adds it as a destination of a course.',
        ];
    }

    /** @return array<string,mixed> */
    private static function deleteInstance(Actor $actor, Args $args): array
    {
        ['owner' => $owner, 'row' => $row] = self::resolveProfile($actor, $args);
        $data = $row['data'];

        $instances = self::entries($data, 'bookstack');
        $index = self::instanceIndex($instances, $args);
        $removed = $instances[$index];
        $removedId = (string)($removed['id'] ?? '');

        // A destination is the record of the book a course made in that wiki.
        // Losing it does not delete the book; it loses the knowledge that the
        // book is ours, so the next publish makes a second one beside it.
        $publishing = [];
        foreach (self::coursesUsing($owner, (int)$row['id']) as $course) {
            if (Targets::byInstance($course['course_id'], $removedId) !== null) {
                $publishing[] = $course;
            }
        }
        if ($publishing !== [] && !$args->bool('confirm')) {
            throw HttpException::unprocessable(
                'These courses publish to "' . (string)($removed['name'] ?? $removedId) . '": '
                . implode(', ', array_map(
                    static fn(array $course): string => $course['name'] . ' (#' . $course['course_id'] . ')',
                    $publishing
                ))
                . '. Removing the instance forgets the book each of them made there, so a later publish to the same '
                . 'wiki would create a second book beside the first. Send confirm to go through with it.'
            );
        }

        array_splice($instances, $index, 1);
        $data['bookstack'] = $instances;

        $updated = Profiles::update($owner, (int)$row['id'], (string)$row['name'], $data);
        Audit::record(
            $actor->username,
            'profile.update',
            (string)$row['name'],
            'bookstack_removed ' . (string)($removed['name'] ?? $removedId) . ', via MCP',
            'mcp'
        );

        return [
            'removed' => true,
            'profile_id' => (int)$updated['id'],
            'bookstack_id' => $removedId,
            'name' => (string)($removed['name'] ?? ''),
            'courses_affected' => $publishing,
            'instances' => array_map(
                static fn(array $instance): array => self::instanceBrief($instance),
                self::entries((array)Profiles::redact($updated)['data'], 'bookstack')
            ),
        ];
    }

    /** @return array<string,mixed> */
    private static function setModelSlot(Actor $actor, Args $args): array
    {
        ['owner' => $owner, 'row' => $row] = self::resolveProfile($actor, $args);
        $data = $row['data'];

        $label = $args->enum('slot', ['outline', 'page'], 'page');
        $slot = array_search($label, self::SLOTS, true);
        if (!is_string($slot)) {
            throw HttpException::unprocessable('slot must be "outline" or "page".');
        }

        $config = (array)($data['models'][$slot] ?? []);
        $changed = [];

        if ($args->has('ai_id')) {
            // accountId() reports an unknown id by name and refuses an empty
            // one on a profile holding several, which is the whole check.
            $config['ai_id'] = self::accountId($data, $args);
            $changed[] = 'ai_id';
        }
        if ($args->has('model')) {
            $config['model'] = $args->str('model');
            $changed[] = 'model';
            // A model with no account behind it never runs. A profile with one
            // account has only one possible answer, so it is filled in rather
            // than left as a failure three calls later.
            if ((string)($config['ai_id'] ?? '') === '') {
                $accounts = self::entries($data, 'ai');
                if (count($accounts) === 1) {
                    $config['ai_id'] = (string)($accounts[0]['id'] ?? '');
                }
            }
        }
        if ($args->has('temperature')) {
            $config['temperature'] = self::temperature($args, 0.7);
            $changed[] = 'temperature';
        }
        if ($args->has('max_tokens')) {
            $config['max_tokens'] = max(0, $args->int('max_tokens', 0));
            $changed[] = 'max_tokens';
        }

        if ($changed === []) {
            throw HttpException::unprocessable(
                'Nothing to change. Give at least one of model, ai_id, temperature or max_tokens.'
            );
        }

        $data['models'][$slot] = $config;
        $updated = Profiles::update($owner, (int)$row['id'], (string)$row['name'], $data);
        Audit::record(
            $actor->username,
            'profile.update',
            (string)$row['name'],
            $label . ' slot: ' . implode(', ', $changed) . ', via MCP',
            'mcp'
        );

        $models = self::modelSummary((array)$updated['data']);

        return [
            'profile_id' => (int)$updated['id'],
            'slot' => $label,
            'changed' => $changed,
            'models' => $models,
            'next_step' => ($models[$label]['configured'] ?? false)
                ? 'This slot is ready. check_profile proves the account behind it still works.'
                : 'This slot has a model or an account but not both, so it will not generate yet.',
        ];
    }

    /** @return array<string,mixed> */
    private static function listPromptSlots(Args $args): array
    {
        $wanted = strtolower(trim($args->str('group')));
        $withText = $args->bool('include_text');

        $slots = [];
        $groups = [];
        foreach (Config::promptSlots() as $key => $slot) {
            $group = (string)($slot['group'] ?? 'global');
            $groups[$group] = true;
            if ($wanted !== '' && $group !== $wanted) {
                continue;
            }

            $row = [
                'key' => (string)$key,
                'group' => $group,
                'label' => (string)($slot['label'] ?? $key),
                'description' => (string)($slot['description'] ?? ''),
                'placeholders' => $slot['placeholders'] ?? [],
            ];
            if ($withText) {
                $row['text'] = (string)($slot['value'] ?? '');
            }
            $slots[] = $row;
        }

        $names = array_keys($groups);
        if ($wanted !== '' && !in_array($wanted, $names, true)) {
            throw HttpException::unprocessable(
                'There is no prompt group called "' . $wanted . '". The groups are: ' . implode(', ', $names) . '.'
            );
        }

        return [
            'slots' => $slots,
            'groups' => $names,
            'count' => count($slots),
            'note' => 'The wording here is the installation\'s. set_profile_prompts replaces it for one profile '
                . 'only; get_profile with include_prompt_text shows which slots a profile has replaced.',
        ];
    }

    /** @return array<string,mixed> */
    private static function setProfilePrompts(Actor $actor, Args $args): array
    {
        ['owner' => $owner, 'row' => $row] = self::resolveProfile($actor, $args);
        $data = $row['data'];

        $known = array_keys(Config::promptSlots());
        $prompts = (array)($data['prompts'] ?? []);
        $set = [];
        $cleared = [];

        foreach ($args->object('prompts') as $slot => $text) {
            $slot = (string)$slot;
            if (!in_array($slot, $known, true)) {
                throw HttpException::unprocessable(
                    'There is no prompt slot called "' . $slot . '". Call get_prompts for the slots this '
                    . 'installation has.'
                );
            }
            if (!is_string($text)) {
                throw HttpException::unprocessable('The override for "' . $slot . '" must be a string.');
            }
            $prompts[$slot] = $text;
            $set[] = $slot;
        }

        foreach ($args->strings('reset') as $slot) {
            if (!in_array($slot, $known, true)) {
                throw HttpException::unprocessable(
                    'There is no prompt slot called "' . $slot . '". Call get_prompts for the slots this '
                    . 'installation has.'
                );
            }
            if (array_key_exists($slot, $prompts)) {
                unset($prompts[$slot]);
                $cleared[] = $slot;
            }
        }

        if ($set === [] && $cleared === []) {
            throw HttpException::unprocessable(
                'Nothing to change. Give prompts with at least one slot in it, or reset naming a slot this profile '
                . 'currently overrides.'
            );
        }

        $data['prompts'] = $prompts;
        $updated = Profiles::update($owner, (int)$row['id'], (string)$row['name'], $data);
        Audit::record(
            $actor->username,
            'profile.update',
            (string)$row['name'],
            'prompts set=' . implode(' ', $set) . ' reset=' . implode(' ', $cleared) . ', via MCP',
            'mcp'
        );

        // normalise() hands back an empty override map as an object rather than
        // an array, so that it survives a JSON round trip as `{}` and not `[]`.
        $after = (array)$updated['data'];

        return [
            'profile_id' => (int)$updated['id'],
            'set' => $set,
            'reset' => $cleared,
            'overridden' => array_keys((array)($after['prompts'] ?? [])),
            'next_step' => 'get_profile with include_prompt_text reads back what this profile now overrides.',
        ];
    }

    /**
     * One BookStack instance, with the token secret reported as set or not.
     *
     * @param array<string,mixed> $instance
     * @return array<string,mixed>
     */
    private static function instanceBrief(array $instance): array
    {
        $entry = self::withoutSecrets($instance);

        return [
            'id' => (string)($instance['id'] ?? ''),
            'name' => (string)($instance['name'] ?? ''),
            'base_url' => (string)($instance['base_url'] ?? ''),
            'token_id' => (string)($instance['token_id'] ?? ''),
            'token_secret_set' => (bool)($entry['token_secret_set'] ?? false),
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


    /**
     * The provider a call is asking for, whether it named a driver or a preset.
     *
     * The browser offers a picker of two dozen rows - Groq, Together,
     * Fireworks, DeepSeek, a local llama.cpp - and all but a handful of them
     * are the same OpenAI-compatible driver pointed at a different endpoint,
     * told apart by `preset_key`. A tool that took only `ai_kind` could reach
     * the six drivers and none of the rows, which meant an installation could
     * be set up from a conversation for Anthropic and not for Groq. Naming a
     * preset settles the kind, the default endpoint and the label together.
     *
     * @param string $fallbackKind the kind to keep when neither argument is given
     * @return array{kind:string,preset:string,catalogue:array<string,mixed>|null}
     */
    private static function chosenProvider(Args $args, string $kindKey, string $fallbackKind = ''): array
    {
        $preset = strtolower(trim($args->str('preset_key')));
        if ($preset !== '') {
            $entry = self::catalogueForPreset($preset);
            if ($entry === null) {
                throw HttpException::unprocessable(
                    'There is no provider preset called "' . $preset . '". Call list_providers: every row it '
                    . 'returns carries the preset_key to send here.'
                );
            }
            return ['kind' => (string)$entry['kind'], 'preset' => $preset, 'catalogue' => $entry];
        }

        if (!$args->has($kindKey) && $fallbackKind !== '') {
            return ['kind' => $fallbackKind, 'preset' => '', 'catalogue' => self::catalogueEntry($fallbackKind)];
        }
        if (!$args->has($kindKey)) {
            throw HttpException::unprocessable(
                'Give either ' . $kindKey . ' for one of the driver kinds, or preset_key for one of the catalogue '
                . 'rows list_providers returns.'
            );
        }

        $kind = self::requireKind($args, $kindKey);
        return ['kind' => $kind, 'preset' => '', 'catalogue' => self::catalogueEntry($kind)];
    }

    /**
     * The catalogue row a preset key names.
     *
     * @return array<string,mixed>|null
     */
    private static function catalogueForPreset(string $preset): ?array
    {
        foreach (Providers::catalogue() as $entry) {
            if (is_array($entry) && (string)($entry['preset_key'] ?? '') === $preset) {
                return $entry;
            }
        }
        return null;
    }

    /** The per-kind fields an account may carry beside its key and its endpoint. */
    private const ACCOUNT_EXTRAS = ['organization', 'cli_path', 'site_url', 'site_name'];

    private static function hasExtras(Args $args): bool
    {
        foreach (self::ACCOUNT_EXTRAS as $extra) {
            if ($args->has($extra)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The fields only some kinds use, written only when they were given.
     *
     * An omitted one keeps what is stored rather than blanking it, which is
     * what every other field on this tool does and what stops a call that only
     * meant to change the key from clearing the OpenAI organization beside it.
     *
     * @param array<string,mixed> $account
     * @return array<string,mixed>
     */
    private static function accountExtras(Args $args, array $account): array
    {
        foreach (self::ACCOUNT_EXTRAS as $extra) {
            if ($args->has($extra)) {
                $account[$extra] = $args->str($extra);
            }
        }
        return $account;
    }

    /** The account kind, checked against whatever the catalogue offers today. */
    private static function requireKind(Args $args, string $key = 'ai_kind'): string
    {
        $kind = strtolower($args->requiredStr($key));
        $known = Providers::kinds();
        if (!in_array($kind, $known, true)) {
            throw HttpException::unprocessable(
                $key . ' must be one of: ' . implode(', ', $known) . '. Call list_providers to see what each one is.'
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
