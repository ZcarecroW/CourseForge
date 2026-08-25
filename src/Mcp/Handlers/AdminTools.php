<?php
declare(strict_types=1);

namespace CourseForge\Mcp\Handlers;

use CourseForge\Ai\Run\RunManager;
use CourseForge\Domain\McpClients;
use CourseForge\Mcp\Args;
use CourseForge\Mcp\Schema;
use CourseForge\Mcp\Scopes;
use CourseForge\Mcp\Tool;
use CourseForge\Security\Access;
use CourseForge\Security\Actor;
use CourseForge\Security\Invite;
use CourseForge\Security\Users;
use CourseForge\Support\Audit;
use CourseForge\Support\Config;
use CourseForge\Support\Cron;
use CourseForge\Support\HttpException;
use CourseForge\Support\Php;
use CourseForge\Support\Runtime;
use CourseForge\Support\Settings;
use Throwable;

/**
 * Running the installation itself, rather than a course.
 *
 * This is the group that makes "administer CourseForge from Claude Code" a true
 * sentence. Everything the Accounts, Settings and Updates screens can do is
 * here: create somebody an account and hand them the password once, issue an
 * invite so they can create their own, change a setting after reading what it
 * means and what it defaults to, generate the cron secret that makes background
 * runs possible at all, read the installation check when something is wrong,
 * see who has connected what and cut a connection off, and pull a new release
 * from GitHub. A person who has just deployed CourseForge onto a shared host
 * should be able to finish setting it up in a conversation, without opening the
 * web interface at all.
 *
 * Every tool here is guarded twice. Each one declares `admin: true`, so the
 * registry never even lists it for a normal account, and each handler calls
 * `$actor->requireAdmin()` as its first statement, because a handler that is
 * safe on its own cannot be made unsafe by a routing mistake made later. The
 * two guards are deliberately redundant - the day somebody changes how tools
 * are filtered, the second one is what stops that mistake from becoming an
 * account takeover.
 *
 * Two things never leave this class. A secret setting - the cron token, the
 * GitHub token - goes out as a flag saying whether one is stored, never as a
 * value; the one exception is a token this connection has just generated, which
 * is returned once and then behaves like every other secret. And the audit log
 * records the keys of the settings that were changed, never what they were
 * changed to. The updater and the installation check are written elsewhere and
 * own the shape of what they answer with, so what they hand back is scrubbed on
 * the way out rather than trusted - see pass().
 */
final class AdminTools
{
    /**
     * The updater, addressed by name.
     *
     * The update feature is a separate class that an installation may not have
     * - a build stripped down for a host that forbids writing to its own
     * directory, for instance - so every call into it is guarded rather than
     * imported, and a missing updater is reported as an answer instead of
     * bringing the tool call down.
     */
    private const UPDATER = 'CourseForge\\Update\\Updater';

    private const DIAGNOSTICS = 'CourseForge\\Support\\Diagnostics';

    /**
     * Field names that hold a credential rather than describe one.
     *
     * Every alternative is anchored between non-alphanumerics, so `api_key`
     * and `secret_key` are caught while `keywords` and `monkey` are not. `key`
     * on its own is missing on purpose - see pass().
     */
    private const SECRET_NAME = '/(?<![a-z0-9])(api[_-]?keys?|[a-z0-9]+[_-]keys?|keys?[_-][a-z0-9]+|tokens?'
        . '|secrets?|passwords?|passphrases?|authorization|auth|credentials?|bearer|cookies?|sessions?)'
        . '(?![a-z0-9])/i';

    /**
     * Values that are a credential whatever they are called.
     *
     * The prefixes providers actually issue, each of which has to start a word
     * so that a path like "task-runner-configuration" is not mistaken for an
     * OpenAI key.
     */
    private const SECRET_VALUE = '/(?<![A-Za-z0-9])(sk-ant-|sk-|github_pat_|ghp_|cf4_|cf3_|AQ\.)[A-Za-z0-9_\-]{20,}/';

    /** @return array<int,Tool> */
    public static function tools(): array
    {
        return [
            new Tool(
                name: 'list_users',
                scope: Scopes::ADMIN,
                title: 'List accounts',
                description: 'Every account on this installation: user name, role, whether it is disabled, when it '
                    . 'last signed in, and how much it owns - courses, pages, profiles, tags and connections. Read '
                    . 'this before changing or deleting an account, because those content counts are what a deletion '
                    . 'is actually about. Costs nothing.',
                properties: [],
                required: [],
                handler: static fn(Actor $actor, array $args): array => self::listUsers($actor),
                readOnly: true,
                idempotent: true,
                admin: true,
            ),

            new Tool(
                name: 'create_user',
                scope: Scopes::ADMIN,
                title: 'Create an account',
                description: 'Creates an account. Leave password out and CourseForge generates one and returns it in '
                    . 'this answer exactly once - only a hash is stored, so it cannot be read again afterwards. The '
                    . 'account is asked to choose its own password at the first sign-in unless must_change_password '
                    . 'is false. To let somebody create their own account instead of being handed a password, use '
                    . 'issue_invite. Costs nothing.',
                properties: [
                    'username' => Schema::string(
                        'The user name to sign in with. Letters, digits, spaces and . _ @ + - are allowed.',
                        'martha'
                    ),
                    'role' => Schema::enum(
                        'user owns its own courses and nothing else. admin can also manage accounts, settings and '
                        . 'updates. Defaults to user.',
                        [Actor::ROLE_USER, Actor::ROLE_ADMIN]
                    ),
                    'password' => Schema::text(
                        'A password of at least ' . Users::MIN_PASSWORD . ' characters. Omit it to have one generated '
                        . 'and returned here.'
                    ),
                    'display_name' => Schema::string('The name shown in the interface. Defaults to the user name.'),
                    'must_change_password' => Schema::bool(
                        'Ask this account to choose a new password at the first sign-in. Defaults to true.'
                    ),
                ],
                required: ['username'],
                handler: static fn(Actor $actor, array $args): array => self::createUser($actor, Args::of($args)),
                admin: true,
            ),

            new Tool(
                name: 'update_user',
                scope: Scopes::ADMIN,
                title: 'Change an account',
                description: 'Changes one account: its role, its display name, whether it is disabled, or its '
                    . 'password. Only the fields you give are changed. Setting a password here replaces the old one '
                    . 'without knowing it, and asks the account to choose a new one at the next sign-in. The last '
                    . 'enabled administrator cannot be demoted or disabled, and this connection\'s own account cannot '
                    . 'demote or disable itself. Costs nothing.',
                properties: [
                    'username' => Schema::string('The account to change, as returned by list_users.'),
                    'role' => Schema::enum('A new role.', [Actor::ROLE_USER, Actor::ROLE_ADMIN]),
                    'display_name' => Schema::string('A new display name.'),
                    'disabled' => Schema::bool(
                        'true refuses every sign-in and every connection token this account owns, without deleting '
                        . 'anything. false lets it back in.'
                    ),
                    'password' => Schema::text(
                        'A new password, of at least ' . Users::MIN_PASSWORD . ' characters. Setting one cuts off '
                        . 'every MCP connection the account made before now, because a reset is how somebody who '
                        . 'has held this password is cut off.'
                    ),
                ],
                required: ['username'],
                handler: static fn(Actor $actor, array $args): array => self::updateUser($actor, Args::of($args)),
                idempotent: true,
                admin: true,
            ),

            new Tool(
                name: 'delete_user',
                scope: Scopes::ADMIN,
                title: 'Delete an account',
                description: 'Removes an account. content says what happens to everything it owns: "delete" destroys '
                    . 'its courses, pages, profiles, tags and connections, and "transfer" hands them to transfer_to, '
                    . 'or to you if that is left out. Deleted content cannot be recovered, so confirm_username has to '
                    . 'match the account name exactly. Call list_users first to see how much is at stake. Costs '
                    . 'nothing.',
                properties: [
                    'username' => Schema::string('The account to delete.'),
                    'content' => Schema::enum(
                        'What happens to the courses, profiles, tags and connections this account owns.',
                        ['delete', 'transfer']
                    ),
                    'transfer_to' => Schema::string(
                        'The account that inherits the content when content is "transfer". Defaults to the account '
                        . 'this connection belongs to.'
                    ),
                    'confirm_username' => Schema::string(
                        'The exact user name again, as a confirmation that the right account is being deleted.'
                    ),
                ],
                required: ['username', 'content', 'confirm_username'],
                handler: static fn(Actor $actor, array $args): array => self::deleteUser($actor, Args::of($args)),
                destructive: true,
                admin: true,
            ),

            new Tool(
                name: 'issue_invite',
                scope: Scopes::ADMIN,
                title: 'Issue an invite code',
                description: 'Writes a fresh INVITE-CODE.txt on the server and returns the code here exactly once, so '
                    . 'somebody can create their own account from the setup screen rather than being handed a '
                    . 'password over a chat. The database keeps only a hash, so the code cannot be read back later - '
                    . 'pass it on now, or read the file on the server. Only one invite is ever open, so this cancels '
                    . 'any earlier one, and it is good for a single account. Costs nothing.',
                properties: [
                    'role' => Schema::enum(
                        'The role the account created with this code gets. Defaults to user.',
                        [Actor::ROLE_USER, Actor::ROLE_ADMIN]
                    ),
                    'ttl_hours' => Schema::int(
                        'How long the code stays valid. Defaults to ' . Invite::DEFAULT_TTL_HOURS . ' hours.',
                        1,
                        720
                    ),
                ],
                required: [],
                handler: static fn(Actor $actor, array $args): array => self::issueInvite($actor, Args::of($args)),
                admin: true,
            ),

            new Tool(
                name: 'list_settings',
                scope: Scopes::ADMIN,
                title: 'List settings',
                description: 'Every setting this installation has: key, label, description, type, the current value, '
                    . 'the value the release ships, and whether this installation has overridden it. The value of a '
                    . 'secret - the cron token, the GitHub token - is never returned; you are told only whether one '
                    . 'is stored. Use the keys from here with set_settings. Costs nothing.',
                properties: [
                    'group' => Schema::string(
                        'One group only: general, scheduler, batch, updates, mcp, security or timeouts. Omit for all '
                        . 'of them.'
                    ),
                ],
                required: [],
                handler: static fn(Actor $actor, array $args): array => self::listSettings($actor, Args::of($args)),
                readOnly: true,
                idempotent: true,
                admin: true,
            ),

            new Tool(
                name: 'set_settings',
                scope: Scopes::ADMIN,
                title: 'Change settings',
                description: 'Writes one or more settings. values is an object of setting key to new value, and every '
                    . 'value is validated against the catalogue before anything is written, so one bad value changes '
                    . 'nothing at all. An unknown key is refused and the valid ones are listed. An empty string for a '
                    . 'secret leaves the stored secret alone rather than clearing it - reset_setting clears one. Call '
                    . 'list_settings first for the keys, their types and their ranges. Costs nothing.',
                properties: [
                    'values' => Schema::object(
                        'Setting key to new value, for instance {"app.name": "Team CourseForge", "app.cron_workers": 4}.'
                    ),
                ],
                required: ['values'],
                handler: static fn(Actor $actor, array $args): array => self::setSettings($actor, Args::of($args)),
                idempotent: true,
                admin: true,
            ),

            new Tool(
                name: 'reset_setting',
                scope: Scopes::ADMIN,
                title: 'Reset a setting',
                description: 'Drops this installation\'s override for one setting, so the value the release ships '
                    . 'applies again. Use it to undo a change without having to know what the default was. Resetting '
                    . 'app.cron_token clears the cron secret and stops background runs until generate_cron_token '
                    . 'makes a new one. Costs nothing.',
                properties: [
                    'key' => Schema::string('The setting to reset, as returned by list_settings.', 'app.cron_workers'),
                ],
                required: ['key'],
                handler: static fn(Actor $actor, array $args): array => self::resetSetting($actor, Args::of($args)),
                idempotent: true,
                admin: true,
            ),

            new Tool(
                name: 'generate_cron_token',
                scope: Scopes::ADMIN,
                title: 'Generate the cron token',
                description: 'Makes a new cron secret, stores it, and returns the finished URL to paste into a '
                    . 'hosting control panel as a job that runs once a minute. This URL is what makes background and '
                    . 'batch runs possible at all: with no token, cron.php answers 404 to everybody and long runs are '
                    . 'not offered. The token is returned here once and cannot be read back afterwards, and any '
                    . 'scheduler still calling the old URL stops working the moment this returns - so only generate a '
                    . 'new one when you are able to update the scheduler as well. Costs nothing.',
                properties: [],
                required: [],
                handler: static fn(Actor $actor, array $args): array => self::generateCronToken($actor),
                admin: true,
            ),

            new Tool(
                name: 'set_up_php',
                scope: Scopes::ADMIN,
                title: 'Measure PHP and raise what is too low',
                description: 'Reads the PHP configuration this host gives CourseForge, works out which limits are '
                    . 'below what it needs, and writes a .user.ini raising exactly those. Every number is a floor: a '
                    . 'limit the host is already generous about is left alone, never lowered. Reports what it could '
                    . 'not change, because some directives are fixed by the host and a line for them would be '
                    . 'ignored in silence. Idempotent - running it twice writes nothing the second time. Pass '
                    . 'dry_run to see the plan without writing anything. Costs nothing.',
                properties: [
                    'dry_run' => Schema::bool('Report what would change without writing the file.'),
                ],
                required: [],
                handler: static fn(Actor $actor, array $args): array => Args::of($args)->bool('dry_run')
                    ? Php::plan()
                    : Php::apply($actor->username),
                readOnly: false,
                idempotent: true,
                admin: true,
                maxResultChars: 40000,
            ),

            new Tool(
                name: 'get_diagnostics',
                scope: Scopes::ADMIN,
                title: 'Run the installation check',
                description: 'The installation check: the PHP runtime and its extensions, directory permissions, the '
                    . 'database, the scheduler, updates, the MCP endpoint and the AI providers, each as a row with a '
                    . 'status and a hint about what to do. This is the first thing to call when something is not '
                    . 'working. It reads only: nothing here is changed. Costs nothing.',
                properties: [],
                required: [],
                handler: static fn(Actor $actor, array $args): array => self::getDiagnostics($actor),
                readOnly: true,
                idempotent: true,
                admin: true,
                maxResultChars: 120000,
            ),

            new Tool(
                name: 'get_audit_log',
                scope: Scopes::ADMIN,
                title: 'Read the audit log',
                description: 'Recent administrative actions - accounts created, roles changed, settings saved, '
                    . 'connections revoked, updates installed - newest first, with who did it, when, from where, and '
                    . 'whether it came through the web interface or through MCP. action matches the start of the '
                    . 'action name, so "user" covers user.create, user.role and user.delete. The values of settings '
                    . 'are never recorded here, only their keys. Costs nothing.',
                properties: [
                    'action' => Schema::string(
                        'Keep only actions starting with this, for instance "user", "settings" or "update".',
                        'settings'
                    ),
                    'limit' => Schema::int('How many entries to return. Defaults to 100.', 1, 1000),
                ],
                required: [],
                handler: static fn(Actor $actor, array $args): array => self::getAuditLog($actor, Args::of($args)),
                readOnly: true,
                idempotent: true,
                admin: true,
            ),

            new Tool(
                name: 'list_connections',
                scope: Scopes::ADMIN,
                title: 'List MCP connections',
                description: 'Every MCP connection on this installation: its name, the account it belongs to, the '
                    . 'tool groups it is limited to, when it was made, when it was last used and how often. Tokens '
                    . 'are stored only as hashes and can never be shown again. Use the connection_id from here with '
                    . 'revoke_connection. Your own unless you name another account or ask for all of them. '
                    . 'Costs nothing.',
                properties: [
                    'owner' => Schema::string("One other account's connections."),
                    'all' => Schema::bool(
                        'Administrators only: widen to every account on the installation. Without it a listing is your own.'
                    ),
                ],
                required: [],
                handler: static fn(Actor $actor, array $args): array => self::listConnections($actor, Args::of($args)),
                readOnly: true,
                idempotent: true,
                admin: true,
            ),

            new Tool(
                name: 'revoke_connection',
                scope: Scopes::ADMIN,
                title: 'Revoke an MCP connection',
                description: 'Deletes one connection\'s token, so the next request it makes is refused. Revoking the '
                    . 'connection you are talking through ends this conversation immediately, and no further tool '
                    . 'call will work - call list_connections first and be sure which one you have, and check the '
                    . 'name against the one this conversation is using. This cannot be undone: a new connection has '
                    . 'to be made from the Connect screen. Costs nothing.',
                properties: [
                    'connection_id' => Schema::int('The connection to revoke, as returned by list_connections.'),
                    'confirm_name' => Schema::string(
                        'The exact name of that connection, as a confirmation that the right one is being cut off.'
                    ),
                ],
                required: ['connection_id', 'confirm_name'],
                handler: static fn(Actor $actor, array $args): array => self::revokeConnection($actor, Args::of($args)),
                destructive: true,
                admin: true,
            ),

            new Tool(
                name: 'check_for_update',
                scope: Scopes::ADMIN,
                title: 'Check for a new version',
                description: 'Asks GitHub whether a newer release exists and reports what is installed, what is '
                    . 'available and whether the two differ. force ignores the cached answer and asks again, which is '
                    . 'worth doing straight after a release and not much otherwise, because GitHub allows anonymous '
                    . 'callers only sixty requests an hour per address. Nothing is downloaded or replaced here - '
                    . 'install_update does that. Costs nothing.',
                properties: [
                    'force' => Schema::bool('Ignore the cached answer and ask GitHub again.'),
                ],
                required: [],
                handler: static fn(Actor $actor, array $args): array => self::checkForUpdate($actor, Args::of($args)),
                idempotent: true,
                admin: true,
                openWorld: true,
            ),

            new Tool(
                name: 'get_update_status',
                scope: Scopes::ADMIN,
                title: 'Update status',
                description: 'What the updater knows: the installed version, when it last checked, whether an update '
                    . 'is waiting, whether automatic updates are switched on, and which backups are on disk to roll '
                    . 'back to. It answers from the cached check where it can and asks GitHub only when that cache '
                    . 'has gone stale, so it is the cheap way to ask; check_for_update is the one that always goes '
                    . 'out. Costs nothing.',
                properties: [],
                required: [],
                handler: static fn(Actor $actor, array $args): array => self::getUpdateStatus($actor),
                readOnly: true,
                idempotent: true,
                admin: true,
                // Cached most of the time, but an expired cache sends this
                // straight to GitHub, so it reaches off the machine like
                // check_for_update does.
                openWorld: true,
            ),

            new Tool(
                name: 'install_update',
                scope: Scopes::ADMIN,
                title: 'Install the update',
                description: 'Downloads the latest release and replaces the application\'s own files with it. A backup '
                    . 'of the current version is taken first, so a version that fails to start can be put back with '
                    . 'rollback_update. This takes minutes and rewrites the code that is answering you, so do not run '
                    . 'it while a generation run is writing pages, and expect this call to be slow. Call '
                    . 'check_for_update first to see what would be installed, because confirm_version has to name it '
                    . 'exactly. Settings and data are never touched: they live outside the release directory. Costs '
                    . 'nothing.',
                properties: [
                    'confirm_version' => Schema::string(
                        'The version that would be installed, exactly as check_for_update or get_update_status '
                        . 'reports it under latest.version. The install is refused unless the two agree.',
                        '4.1.0'
                    ),
                ],
                required: ['confirm_version'],
                handler: static fn(Actor $actor, array $args): array => self::installUpdate($actor, Args::of($args)),
                destructive: true,
                admin: true,
                openWorld: true,
            ),

            new Tool(
                name: 'rollback_update',
                scope: Scopes::ADMIN,
                title: 'Roll an update back',
                description: 'Puts the previously installed version back from the backup the last update took. Use it '
                    . 'when a new version does not start or breaks something. With no backup on disk there is nothing '
                    . 'to restore and the call reports that rather than doing anything. get_update_status lists what '
                    . 'backups exist. confirm has to be true, because this replaces the running application with an '
                    . 'older one and anything the newer version wrote to the database stays as it is. Costs nothing.',
                properties: [
                    'confirm' => Schema::bool(
                        'Must be true. Without it nothing is restored and the call reports what it would have done.'
                    ),
                ],
                required: ['confirm'],
                handler: static fn(Actor $actor, array $args): array => self::rollbackUpdate($actor, Args::of($args)),
                destructive: true,
                admin: true,
            ),

            new Tool(
                name: 'get_update_history',
                scope: Scopes::ADMIN,
                title: 'Read the update log',
                description: 'Every update and rollback this installation has attempted, newest first: which version '
                    . 'it came from and went to, who asked for it, whether it was a manual or an automatic attempt, '
                    . 'how it ended, the log it wrote and the error if it failed. This is where to look when a '
                    . 'version is not the one somebody expected. Alongside it: the backups on disk and whether an '
                    . 'update is running right now. Costs nothing.',
                properties: [],
                required: [],
                handler: static fn(Actor $actor, array $args): array => self::getUpdateHistory($actor),
                readOnly: true,
                idempotent: true,
                admin: true,
                // A failed update logs everything it tried, so a handful of rows
                // can carry a great deal of text.
                maxResultChars: 120000,
            ),

            new Tool(
                name: 'get_prompts',
                scope: Scopes::ADMIN,
                title: 'Read the prompt library',
                description: 'The prompt library: every instruction CourseForge sends a model, as a named slot you '
                    . 'can read and rewrite. A slot is one piece of the eventual prompt - the persona the model '
                    . 'writes as, the format contract an outline has to obey, the audience block, the tagging rules, '
                    . 'the language instruction - and they are joined together for each job. Each slot comes back '
                    . 'with its group, its label, what it is for, the placeholders it may use, the text this '
                    . 'installation currently sends, the text the release ships, and whether the two differ. These '
                    . 'are the installation-wide base layer: a profile may still override any slot for its own '
                    . 'courses, and get_profile shows what a profile has changed. Costs nothing.',
                properties: [
                    'group' => Schema::string(
                        'One group of slots only, as returned in groups. Omit for the whole library.'
                    ),
                ],
                required: [],
                handler: static fn(Actor $actor, array $args): array => self::getPrompts($actor, Args::of($args)),
                readOnly: true,
                idempotent: true,
                admin: true,
                // The whole library is every prompt CourseForge ships, twice
                // over - the current text and the shipped default.
                maxResultChars: 200000,
            ),

            new Tool(
                name: 'set_prompts',
                scope: Scopes::ADMIN,
                title: 'Rewrite the prompt library',
                description: 'Rewrites one or more prompt slots for the whole installation, which is how the house '
                    . 'style of everything CourseForge writes gets tuned. prompts is an object of slot key to new '
                    . 'text, and only the slots you name are touched. An empty string is not an empty prompt: it '
                    . 'drops this installation\'s override and puts the shipped default back. An unknown slot key is '
                    . 'refused and nothing is written. Keep the placeholders the slot declares - they are filled in '
                    . 'at generation time and a slot that loses one silently loses the thing it named. Read '
                    . 'get_prompts first for the keys, the current text and the defaults. This is the base layer '
                    . 'only: a profile can still override any of these for its own courses. Costs nothing.',
                properties: [
                    'prompts' => Schema::object(
                        'Slot key to new text, for instance {"global_system": "You are a patient technical writer."}. '
                        . 'An empty string resets that slot to the shipped default.'
                    ),
                ],
                required: ['prompts'],
                handler: static fn(Actor $actor, array $args): array => self::setPrompts($actor, Args::of($args)),
                idempotent: true,
                admin: true,
            ),
        ];
    }

    /* ---------------------------------------------------------- accounts */

    /** @return array<string,mixed> */
    private static function listUsers(Actor $actor): array
    {
        $actor->requireAdmin();

        $users = [];
        foreach (Users::all() as $user) {
            $username = (string)$user['username'];
            $users[] = [
                'username' => $username,
                'display_name' => (string)$user['display_name'],
                'role' => (string)$user['role'],
                'disabled' => (bool)$user['disabled'],
                'must_change_password' => (bool)$user['must_change_password'],
                'created_at' => (int)$user['created_at'],
                'created' => self::when((int)$user['created_at']),
                'last_login_at' => (int)$user['last_login_at'],
                'last_login' => self::when((int)$user['last_login_at']),
                'is_you' => strcasecmp($username, $actor->username) === 0,
                'content' => Users::contentSummary($username),
            ];
        }

        return [
            'users' => $users,
            'count' => count($users),
            'min_password' => Users::MIN_PASSWORD,
            'invite' => Invite::status(),
            'hint' => 'create_user makes an account and returns a generated password once; issue_invite lets '
                . 'somebody create their own.',
        ];
    }

    /** @return array<string,mixed> */
    private static function createUser(Actor $actor, Args $args): array
    {
        $actor->requireAdmin();

        // A generated password is the normal case: it goes back in this one
        // answer and is stored only as a hash, exactly as a connection token is.
        $password = $args->raw('password');
        $generated = $password === '';
        if ($generated) {
            $password = self::suggestPassword();
        }

        $user = Users::create(
            $args->requiredStr('username'),
            $password,
            $args->enum('role', [Actor::ROLE_USER, Actor::ROLE_ADMIN], Actor::ROLE_USER),
            $args->str('display_name'),
            $actor->username,
            $args->bool('must_change_password', true),
        );

        Audit::record($actor->username, 'user.create', (string)$user['username'], 'role=' . $user['role'], 'mcp');

        return [
            'created' => true,
            'user' => $user,
            'password' => $generated ? $password : '',
            'password_note' => $generated
                ? 'This password is shown here once and cannot be read again. Pass it on now.'
                : 'The password you gave was used and is not repeated here.',
            'next' => 'The account can sign in at once. list_users shows the installation as it now stands.',
        ];
    }

    /** @return array<string,mixed> */
    private static function updateUser(Actor $actor, Args $args): array
    {
        $actor->requireAdmin();

        $target = (string)Users::require($args->requiredStr('username'))['username'];
        $isSelf = strcasecmp($target, $actor->username) === 0;
        $changed = [];

        if ($args->has('role')) {
            $role = $args->enum('role', [Actor::ROLE_USER, Actor::ROLE_ADMIN], Actor::ROLE_USER);
            if ($isSelf && $role !== Actor::ROLE_ADMIN) {
                throw HttpException::unprocessable(
                    'You cannot take administrator rights away from the account this connection belongs to. '
                    . 'Have another administrator do it.'
                );
            }
            Users::setRole($target, $role);
            Audit::record($actor->username, 'user.role', $target, 'role=' . $role, 'mcp');
            $changed[] = 'role';
        }

        if ($args->has('display_name')) {
            $displayName = $args->str('display_name');
            Users::setDisplayName($target, $displayName);
            Audit::record($actor->username, 'user.display_name', $target, 'now "' . $displayName . '"', 'mcp');
            $changed[] = 'display_name';
        }

        if ($args->has('disabled')) {
            $disabled = $args->bool('disabled');
            if ($disabled && $isSelf) {
                throw HttpException::unprocessable(
                    'You cannot disable the account this connection belongs to - it would refuse this connection '
                    . 'on its next request.'
                );
            }
            Users::setDisabled($target, $disabled);
            Audit::record($actor->username, $disabled ? 'user.disable' : 'user.enable', $target, 'via MCP', 'mcp');
            $changed[] = 'disabled';
        }

        if ($args->has('password')) {
            Users::setPassword($target, $args->requiredRaw('password'), true);
            Audit::record($actor->username, 'user.password', $target, 'via MCP', 'mcp');
            $changed[] = 'password';
        }

        if ($changed === []) {
            throw HttpException::unprocessable(
                'Nothing to change. Give at least one of role, display_name, disabled or password.'
            );
        }

        return [
            'updated' => true,
            'user' => Users::publicView(Users::require($target)),
            'changed' => $changed,
            'note' => in_array('password', $changed, true)
                ? 'The account has to choose a new password before it can do anything else, in the browser and '
                . 'over MCP alike, and every connection it made before now has stopped working.'
                : '',
        ];
    }

    /** @return array<string,mixed> */
    private static function deleteUser(Actor $actor, Args $args): array
    {
        $actor->requireAdmin();

        $target = (string)Users::require($args->requiredStr('username'))['username'];

        if (strcasecmp($target, $actor->username) === 0) {
            throw HttpException::unprocessable(
                'You cannot delete the account this connection belongs to. Sign in as another administrator, or '
                . 'connect with one, and delete it from there.'
            );
        }

        // Exact, not case-insensitive: the whole point of the confirmation is
        // that it was typed against what list_users returned.
        if ($args->requiredStr('confirm_username') !== $target) {
            throw HttpException::unprocessable(
                'confirm_username does not match. The account is called "' . $target . '".'
            );
        }

        if (!$args->has('content')) {
            throw HttpException::unprocessable(
                'Say what should happen to this account\'s courses: content must be "delete" or "transfer". '
                . 'list_users shows how much there is.'
            );
        }
        $content = $args->enum('content', ['delete', 'transfer'], 'transfer');

        $transferTo = '';
        if ($content === 'transfer') {
            $transferTo = $args->str('transfer_to') !== ''
                ? (string)Users::require($args->str('transfer_to'))['username']
                : $actor->username;
        }

        // Counted before the delete, because afterwards there is nothing to count.
        $summary = Users::contentSummary($target);
        Users::delete($target, $content, $transferTo);

        $detail = $content === 'transfer'
            ? 'content transferred to ' . $transferTo . ' (' . $summary['courses'] . ' course(s))'
            : 'content deleted (' . $summary['courses'] . ' course(s), ' . $summary['pages'] . ' page(s))';
        Audit::record($actor->username, 'user.delete', $target, $detail, 'mcp');

        return [
            'deleted' => true,
            'username' => $target,
            'content' => $content,
            'transferred_to' => $content === 'transfer' ? $transferTo : null,
            'content_summary' => $summary,
            'message' => $content === 'transfer'
                ? 'The account is gone. Its ' . $summary['courses'] . ' course(s), ' . $summary['profiles']
                    . ' profile(s), ' . $summary['tags'] . ' tag(s) and ' . $summary['connections']
                    . ' connection(s) now belong to ' . $transferTo . '.'
                : 'The account is gone, along with its ' . $summary['courses'] . ' course(s), ' . $summary['pages']
                    . ' page(s), ' . $summary['profiles'] . ' profile(s) and ' . $summary['tags']
                    . ' tag(s). Anything already published to BookStack was not touched.',
        ];
    }

    /** @return array<string,mixed> */
    private static function issueInvite(Actor $actor, Args $args): array
    {
        $actor->requireAdmin();

        $issued = Invite::issue(
            $args->enum('role', [Actor::ROLE_USER, Actor::ROLE_ADMIN], Actor::ROLE_USER),
            max(1, min(720, $args->int('ttl_hours', Invite::DEFAULT_TTL_HOURS))),
            $actor->username,
        );

        Audit::record($actor->username, 'user.invite', $issued['role'], 'written to ' . $issued['path'], 'mcp');

        return [
            'code' => $issued['code'],
            'role' => $issued['role'],
            'expires_at' => $issued['expires_at'],
            'expires' => self::when((int)$issued['expires_at']),
            'file' => $issued['path'],
            'note' => 'The code was written to ' . $issued['path'] . ' on the server and is returned here exactly '
                . 'once - the database keeps only a hash of it. Any invite issued earlier has been cancelled.',
            'next' => 'Whoever holds this code opens the installation in a browser and types it into the setup '
                . 'screen. The code and its file are removed the moment an account is created with it.',
        ];
    }

    /* ---------------------------------------------------------- settings */

    /** @return array<string,mixed> */
    private static function listSettings(Actor $actor, Args $args): array
    {
        $actor->requireAdmin();

        $groups = array_column(Settings::groups(), 'key');
        $group = strtolower($args->str('group'));
        if ($group !== '' && !in_array($group, $groups, true)) {
            throw HttpException::unprocessable(
                'There is no settings group called "' . $group . '". The groups are: ' . implode(', ', $groups) . '.'
            );
        }

        $rows = [];
        foreach (Settings::describe($actor) as $field) {
            if ($group !== '' && (string)$field['group'] !== $group) {
                continue;
            }

            $isSecret = (string)($field['type'] ?? '') === 'secret';
            $row = [
                'key' => (string)$field['key'],
                'group' => (string)$field['group'],
                'label' => (string)$field['label'],
                'description' => (string)($field['description'] ?? ''),
                'type' => (string)$field['type'],
                'is_overridden' => (bool)$field['is_overridden'],
            ];

            // A secret is described, never disclosed. The one thing a caller
            // may know is whether something is stored under this key.
            if ($isSecret) {
                $row['is_secret'] = true;
                $row['is_set'] = (bool)($field['is_set'] ?? false);
                $row['value'] = null;
                $row['default'] = null;
            } else {
                $row['is_secret'] = false;
                $row['value'] = $field['value'];
                $row['default'] = $field['default'];
            }

            foreach (['unit', 'min', 'max', 'options', 'advanced'] as $extra) {
                if (isset($field[$extra])) {
                    $row[$extra] = $field[$extra];
                }
            }
            $rows[] = $row;
        }

        return [
            'settings' => $rows,
            'count' => count($rows),
            'groups' => Settings::groups(),
            'filter' => $group,
            'overrides_file' => Config::file(),
            'note' => 'The value of a secret is never returned. is_set says whether one is stored.',
            'next' => 'set_settings writes a value; reset_setting puts one back to the shipped default.',
        ];
    }

    /** @return array<string,mixed> */
    private static function setSettings(Actor $actor, Args $args): array
    {
        $actor->requireAdmin();

        $values = $args->object('values');
        if ($values === []) {
            throw HttpException::unprocessable(
                'values is required and must be an object of setting key to new value. Call list_settings for the keys.'
            );
        }

        // Everything is coerced before anything is written, so a single bad
        // value leaves the installation exactly as it was.
        $write = [];
        $saved = [];
        $ignored = [];
        foreach ($values as $key => $value) {
            $key = trim((string)$key);
            $field = Settings::field($key);
            if ($field === null) {
                throw HttpException::unprocessable(
                    'There is no setting called "' . $key . '". The settings are: '
                    . implode(', ', array_keys(Settings::byKey())) . '.'
                );
            }

            $isSecret = (string)$field['type'] === 'secret';
            if ($isSecret && trim(is_scalar($value) ? (string)$value : '') === '') {
                // Empty means "leave the stored secret alone", the same way the
                // web form does, so a round trip through list_settings - which
                // returns no secret value - cannot blank one out by accident.
                $ignored[] = $key;
                continue;
            }

            $write[$key] = Settings::coerce($key, $value);
            $saved[] = ['key' => $key, 'value' => $isSecret ? null : $write[$key], 'is_secret' => $isSecret];
        }

        if ($write === []) {
            return [
                'saved' => [],
                'ignored' => $ignored,
                'message' => 'Nothing was written. An empty value for a secret leaves the stored one alone; use '
                    . 'reset_setting to clear it.',
            ];
        }

        Config::setMany($write);
        Audit::record(
            $actor->username,
            'settings.update',
            implode(', ', array_column($saved, 'key')),
            'via MCP',
            'mcp'
        );

        $warnings = [];
        if (array_key_exists('mcp.enabled', $write) && $write['mcp.enabled'] === false) {
            $warnings[] = 'mcp.enabled is now off, so this connection - and every other MCP client - is refused from '
                . 'the next request onwards. It can only be switched back on from the web interface.';
        }
        if (array_key_exists('app.cron_token', $write)) {
            $warnings[] = 'The cron token has changed. Any scheduler still calling the old URL will stop working; '
                . 'generate_cron_token returns the finished URL to paste in.';
        }

        return [
            'saved' => $saved,
            'ignored' => $ignored,
            'warnings' => $warnings,
            'overrides_file' => Config::file(),
            'next' => 'list_settings shows the installation as it now stands. Values that equal the shipped default '
                . 'are removed from the override file rather than written.',
        ];
    }

    /** @return array<string,mixed> */
    private static function resetSetting(Actor $actor, Args $args): array
    {
        $actor->requireAdmin();

        $key = $args->requiredStr('key');
        $field = Settings::field($key);
        if ($field === null) {
            throw HttpException::unprocessable(
                'There is no setting called "' . $key . '". The settings are: '
                . implode(', ', array_keys(Settings::byKey())) . '.'
            );
        }

        if (!Config::isOverridden($key)) {
            return [
                'reset' => false,
                'key' => $key,
                'message' => 'This installation never overrode "' . $key . '", so it is already at the shipped default.',
            ];
        }

        Config::reset($key);
        Audit::record($actor->username, 'settings.reset', $key, 'via MCP', 'mcp');

        $isSecret = (string)$field['type'] === 'secret';

        return [
            'reset' => true,
            'key' => $key,
            'label' => (string)$field['label'],
            'value' => $isSecret ? null : Config::get($key, $field['default']),
            'default' => $isSecret ? null : $field['default'],
            'is_secret' => $isSecret,
            'warning' => $key === 'app.cron_token'
                ? 'The cron secret is now empty, so cron.php answers 404 to everybody and background runs are no '
                    . 'longer offered. Call generate_cron_token to set a new one.'
                : '',
        ];
    }

    /** @return array<string,mixed> */
    private static function generateCronToken(Actor $actor): array
    {
        $actor->requireAdmin();

        $token = bin2hex(random_bytes(24));
        Config::set('app.cron_token', $token);
        Audit::record($actor->username, 'settings.cron_token', '', 'regenerated via MCP', 'mcp');

        return [
            'token' => $token,
            'url' => Cron::publicUrl($token),
            'cli' => 'php ' . CF_ROOT . '/tools/cron.php --quiet',
            'scheduler' => RunManager::cronStatus(),
            'note' => 'The token is returned here once and is stored as a secret afterwards. Anything still calling '
                . 'the previous URL is refused from now on.',
            'next' => 'Paste the URL into your host\'s scheduler as a job that runs once a minute. Until it has '
                . 'ticked at least once, whoami and get_diagnostics will report the scheduler as unhealthy.',
        ];
    }

    /* ------------------------------------------------------- diagnostics */

    /** @return array<string,mixed> */
    private static function getDiagnostics(Actor $actor): array
    {
        $actor->requireAdmin();

        if (!class_exists(self::DIAGNOSTICS) || !method_exists(self::DIAGNOSTICS, 'run')) {
            return [
                'available' => false,
                'message' => 'The installation check is not available in this build of CourseForge. Everything else '
                    . 'in the admin group still works; whoami and list_settings between them say most of what the '
                    . 'check would.',
            ];
        }

        $class = self::DIAGNOSTICS;

        return [
            'available' => true,
            'report' => self::pass($class::run()),
            'next' => 'Every check carries its own hint. Anything that needs a setting changed is fixed with '
                . 'set_settings; a scheduler that has never ticked usually needs generate_cron_token.',
        ];
    }

    /** @return array<string,mixed> */
    private static function getAuditLog(Actor $actor, Args $args): array
    {
        $actor->requireAdmin();

        $action = $args->str('action');
        $limit = max(1, min(1000, $args->int('limit', 100)));

        $entries = [];
        foreach (Audit::recent($limit, $action) as $row) {
            $entries[] = [
                'at' => self::when((int)$row['ts']),
                'ts' => (int)$row['ts'],
                'actor' => (string)$row['actor'],
                'action' => (string)$row['action'],
                'subject' => (string)$row['subject'],
                'detail' => (string)$row['detail'],
                'source' => (string)$row['source'],
                'ip' => (string)$row['ip'],
            ];
        }

        return [
            'entries' => $entries,
            'count' => count($entries),
            'filter' => $action,
            'limit' => $limit,
            'note' => 'Times are UTC. A settings change records the keys that were written, never their values.',
        ];
    }

    /* ------------------------------------------------------- connections */

    /** @return array<string,mixed> */
    private static function listConnections(Actor $actor, Args $args): array
    {
        $actor->requireAdmin();

        $owner = Access::workingSet($actor, $args->str('owner'), $args->bool('all'));

        $rows = [];
        foreach (McpClients::all($owner) as $client) {
            $rows[] = [
                'connection_id' => (int)$client['id'],
                'name' => (string)$client['name'],
                'owner' => (string)$client['owner'],
                'note' => (string)$client['note'],
                'scopes' => $client['scopes'],
                'scope_note' => $client['scopes'] === []
                    ? 'No groups chosen, so this connection gets everything its account is allowed.'
                    : '',
                'created_at' => (int)$client['created_at'],
                'created' => self::when((int)$client['created_at']),
                'last_used_at' => (int)$client['last_used_at'],
                'last_used' => self::when((int)$client['last_used_at']),
                'uses' => (int)$client['uses'],
                'expires_at' => (int)$client['expires_at'],
                'expires' => self::when((int)$client['expires_at']),
                'expired' => (bool)$client['expired'],
            ];
        }

        return [
            'connections' => $rows,
            'count' => count($rows),
            'owner' => $owner,
            'note' => 'A connection never exceeds what its account may do: the role is read from the account on '
                . 'every request, so demoting somebody narrows their existing connections at once.',
            'next' => 'revoke_connection takes a connection_id from this list.',
        ];
    }

    /** @return array<string,mixed> */
    private static function revokeConnection(Actor $actor, Args $args): array
    {
        $actor->requireAdmin();

        $id = $args->id('connection_id');
        $client = Access::connection($actor, $id);

        // The name has to match. This is the one tool on the surface that can
        // cut off the conversation making the call, and an id transposed out of
        // a list of twelve is how that happens.
        if ($args->requiredStr('confirm_name') !== (string)$client['name']) {
            throw HttpException::unprocessable(
                'confirm_name does not match. Connection ' . $id . ' is called "' . $client['name'] . '", and it '
                . 'belongs to ' . $client['username'] . '.'
            );
        }

        McpClients::deleteById($id);
        Audit::record(
            $actor->username,
            'mcp.revoke',
            (string)$client['name'],
            'owner ' . (string)$client['username'],
            'mcp'
        );

        return [
            'revoked' => true,
            'connection_id' => $id,
            'name' => (string)$client['name'],
            'owner' => (string)$client['username'],
            'note' => 'That token is refused from now on. If it was the one this conversation is using, the next '
                . 'call will fail with an authentication error and a new connection has to be made from the Connect '
                . 'screen.',
        ];
    }

    /* ----------------------------------------------------------- updates */

    /** @return array<string,mixed> */
    private static function checkForUpdate(Actor $actor, Args $args): array
    {
        $actor->requireAdmin();

        $unavailable = self::updaterUnavailable('status');
        if ($unavailable !== null) {
            return $unavailable;
        }

        // status(true) is what forces a fresh look at GitHub. There is a
        // check() on Updater, but it is a private helper that builds one
        // precondition row, and calling it is what this tool used to do.
        $class = self::UPDATER;
        $result = self::pass($class::status(true));

        // The web interface records a check for the same reason: this
        // installation reaching out to GitHub is an act, and the audit log is
        // where somebody looks to find out who went looking for a new version.
        Audit::record(
            $actor->username,
            'update.check',
            (string)($result['repository'] ?? ''),
            (string)($result['error'] ?? '') !== ''
                ? 'failed: ' . (string)$result['error']
                : 'latest is ' . (string)($result['latest']['version'] ?? 'nothing published'),
            'mcp'
        );

        return $result;
    }

    /** @return array<string,mixed> */
    private static function getUpdateStatus(Actor $actor): array
    {
        $actor->requireAdmin();

        $unavailable = self::updaterUnavailable('status');
        if ($unavailable !== null) {
            return $unavailable;
        }

        $class = self::UPDATER;
        return self::pass($class::status());
    }

    /** @return array<string,mixed> */
    private static function installUpdate(Actor $actor, Args $args): array
    {
        $actor->requireAdmin();

        $unavailable = self::updaterUnavailable('install');
        if ($unavailable !== null) {
            return $unavailable;
        }

        // Downloading a release and rewriting the installation is minutes of
        // work, and the request that started it is the one being replaced.
        Runtime::beginLongRequest();

        // Naming the version is what turns "install the update" into a decision
        // about a particular release. It also means a model that has not looked
        // cannot install one, because there is nothing for it to name.
        $pending = self::pendingVersion();
        if ($pending === null) {
            throw HttpException::unprocessable(
                'CourseForge cannot tell which version would be installed, so it will not install one. Call '
                . 'check_for_update to ask GitHub, then pass the version it reports as confirm_version.'
            );
        }
        if ($args->requiredStr('confirm_version') !== $pending) {
            throw HttpException::unprocessable(
                'confirm_version does not match. The release that would be installed is ' . $pending
                . ', and this installation is running ' . CF_VERSION . '. Pass ' . $pending
                . ' as confirm_version, or call check_for_update to see what has changed.'
            );
        }

        // Recorded before the call rather than after it: an update that never
        // returns is exactly the one somebody will need a trace of.
        Audit::record(
            $actor->username,
            'update.install',
            $pending,
            'requested via MCP, from ' . CF_VERSION,
            'mcp'
        );

        $class = self::UPDATER;
        return self::pass($class::install($actor->username, 'mcp'));
    }

    /** @return array<string,mixed> */
    private static function rollbackUpdate(Actor $actor, Args $args): array
    {
        $actor->requireAdmin();

        $unavailable = self::updaterUnavailable('rollback');
        if ($unavailable !== null) {
            return $unavailable;
        }

        // There is nothing to name here the way install_update names a version,
        // because the backup is whatever the last update happened to leave
        // behind. So the confirmation is the plain one: say so explicitly.
        if (!$args->bool('confirm')) {
            return [
                'rolled_back' => false,
                'reason' => 'confirm was not true, so nothing has been restored. A rollback replaces the running '
                    . 'application with the version before it, and anything the newer one wrote to the database is '
                    . 'left as it is.',
                'version' => CF_VERSION,
                'next' => 'Call get_update_status to see which backups exist, then rollback_update again with '
                    . 'confirm true.',
            ];
        }

        Runtime::beginLongRequest();
        Audit::record($actor->username, 'update.rollback', '', 'requested via MCP, from ' . CF_VERSION, 'mcp');

        $class = self::UPDATER;
        return self::pass($class::rollback($actor->username, 'mcp'));
    }

    /** @return array<string,mixed> */
    private static function getUpdateHistory(Actor $actor): array
    {
        $actor->requireAdmin();

        $unavailable = self::updaterUnavailable('history');
        if ($unavailable !== null) {
            return $unavailable;
        }

        $class = self::UPDATER;

        $rows = [];
        foreach ((array)$class::history() as $row) {
            $row = (array)$row;
            $rows[] = self::pass([
                'id' => (int)($row['id'] ?? 0),
                'started_at' => (int)($row['started_at'] ?? 0),
                'started' => self::when((int)($row['started_at'] ?? 0)),
                'finished_at' => (int)($row['finished_at'] ?? 0),
                'finished' => self::when((int)($row['finished_at'] ?? 0)),
                'from_version' => (string)($row['from_version'] ?? ''),
                'to_version' => (string)($row['to_version'] ?? ''),
                'channel' => (string)($row['channel'] ?? ''),
                'status' => (string)($row['status'] ?? ''),
                'trigger' => (string)($row['trigger'] ?? ''),
                'actor' => (string)($row['actor'] ?? ''),
                'backup_path' => (string)($row['backup_path'] ?? ''),
                'log' => (string)($row['log'] ?? ''),
                'error' => (string)($row['error'] ?? ''),
            ]);
        }

        return [
            'available' => true,
            'history' => $rows,
            'count' => count($rows),
            'version' => CF_VERSION,
            // Both of these arrived after history() did, so neither is assumed.
            'backups' => method_exists($class, 'backups') ? self::pass($class::backups()) : [],
            'running' => method_exists($class, 'running') ? (bool)$class::running() : false,
            'note' => 'Newest first. A row with status "running" and no finished time is an attempt that never came '
                . 'back - its log says how far it got.',
        ];
    }

    /* ---------------------------------------------------- prompt library */

    /** @return array<string,mixed> */
    private static function getPrompts(Actor $actor, Args $args): array
    {
        $actor->requireAdmin();

        $groups = Config::promptGroups();
        $group = strtolower($args->str('group'));
        if ($group !== '' && !isset($groups[$group])) {
            throw HttpException::unprocessable(
                'There is no prompt group called "' . $group . '". The groups are: '
                . implode(', ', array_keys($groups)) . '.'
            );
        }

        $defaults = (array)(Config::defaults()['prompts'] ?? []);

        $slots = [];
        $overridden = 0;
        foreach (Config::promptSlots() as $key => $slot) {
            if ($group !== '' && $slot['group'] !== $group) {
                continue;
            }
            $isOverridden = Config::isOverridden('prompts.' . $key . '.value');
            $overridden += $isOverridden ? 1 : 0;
            $shipped = (array)($defaults[$key] ?? []);
            $slots[] = [
                'key' => $key,
                'group' => $slot['group'],
                'label' => $slot['label'],
                'description' => $slot['description'],
                'placeholders' => $slot['placeholders'],
                'value' => $slot['value'],
                'default' => (string)($shipped['value'] ?? ''),
                'is_overridden' => $isOverridden,
            ];
        }

        return [
            'slots' => $slots,
            'count' => count($slots),
            'overridden' => $overridden,
            'groups' => $groups,
            'filter' => $group,
            'note' => 'A placeholder written as {{name}} is filled in when the prompt is used. Rewriting a slot '
                . 'changes what every course on this installation is written from.',
            'next' => 'set_prompts writes a slot; an empty string there puts the shipped default back. A profile can '
                . 'override any of these for its own courses - get_profile shows which it has.',
        ];
    }

    /** @return array<string,mixed> */
    private static function setPrompts(Actor $actor, Args $args): array
    {
        $actor->requireAdmin();

        $prompts = $args->object('prompts');
        if ($prompts === []) {
            throw HttpException::unprocessable(
                'prompts is required and must be an object of slot key to new text. Call get_prompts for the keys.'
            );
        }

        // Validated in full before anything is written, so one unknown key
        // leaves the library exactly as it was.
        $slots = Config::promptSlots();
        $write = [];
        $saved = [];
        $reset = [];
        foreach ($prompts as $key => $value) {
            $key = trim((string)$key);
            if (!isset($slots[$key])) {
                throw HttpException::unprocessable(
                    'There is no prompt slot called "' . $key . '". Call get_prompts for the keys; nothing has been '
                    . 'written.'
                );
            }
            if (!is_scalar($value)) {
                throw HttpException::unprocessable('Prompt "' . $key . '" must be text.');
            }
            $text = (string)$value;
            // An empty slot would mean a prompt with nothing in it, which is
            // never what somebody meant - so it drops the override instead.
            if (trim($text) === '') {
                $reset[] = $key;
                continue;
            }
            $write['prompts.' . $key . '.value'] = $text;
            $saved[] = $key;
        }

        foreach ($reset as $key) {
            Config::reset('prompts.' . $key . '.value');
        }
        if ($write !== []) {
            Config::setMany($write);
        }

        Audit::record(
            $actor->username,
            'settings.prompts',
            implode(', ', array_merge($saved, $reset)),
            count($saved) . ' written, ' . count($reset) . ' reset via MCP',
            'mcp'
        );

        return [
            'saved' => $saved,
            'reset' => $reset,
            'overrides_file' => Config::file(),
            'note' => $reset === []
                ? 'These slots now hold your text for every course on this installation.'
                : 'The reset slots are back to what the release ships.',
            'next' => 'get_prompts shows the library as it now stands. A profile can still override any slot for its '
                . 'own courses.',
        ];
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * Whether the updater can be called at all, as an answer rather than a
     * failure.
     *
     * A model that is told "not available in this build" stops asking. One that
     * is handed a fatal error tries again.
     *
     * @return array<string,mixed>|null null when the call may be made
     */
    private static function updaterUnavailable(string $method): ?array
    {
        if (!class_exists(self::UPDATER)) {
            return [
                'available' => false,
                'message' => 'This build of CourseForge has no updater, so it cannot check for or install a new '
                    . 'version from here. Update it the way it was installed - by replacing the release files - and '
                    . 'leave the data directory as it is.',
            ];
        }
        // is_callable, not method_exists: method_exists answers true for a
        // private method, and this guard existed precisely to stop a call that
        // cannot be made. It did not - Updater::check() is a private helper for
        // building a precondition row, the guard waved it through, and every
        // check_for_update fatalled. is_callable asks the question that was
        // meant: may THIS scope call it.
        if (!is_callable([self::UPDATER, $method])) {
            return [
                'available' => false,
                'message' => 'The updater in this build does not offer a public ' . $method . '(), so this '
                    . 'particular call cannot be made. get_diagnostics reports what the update feature can do on '
                    . 'this installation.',
            ];
        }
        return null;
    }

    /**
     * The version install_update would put on, or null if nobody knows.
     *
     * Read from the updater's own status rather than from GitHub directly, so
     * the number a caller has to confirm is the number the other update tools
     * report. A build whose updater cannot say - no status(), no release cached,
     * a repository that has never answered - gets null, and the caller is sent
     * to check_for_update rather than allowed to install something unnamed.
     */
    private static function pendingVersion(): ?string
    {
        if (!method_exists(self::UPDATER, 'status')) {
            return null;
        }

        $class = self::UPDATER;
        try {
            $status = self::pass($class::status());
        } catch (Throwable) {
            return null;
        }

        $latest = (array)($status['latest'] ?? []);
        $version = trim((string)($latest['version'] ?? ''));
        return $version === '' ? null : $version;
    }

    /**
     * Somebody else's answer, with anything that reads like a credential taken
     * out of it.
     *
     * The updater and the installation check are written separately and own the
     * shape of what they return, so nothing here is renamed, reordered or
     * dropped - a caller gets the report those classes designed. What does
     * happen is a walk over every value in it. A field whose NAME reads like a
     * credential comes back as "[set]" or "[not set]", which is all a caller
     * ever needs to know about one, and a string that LOOKS like a credential
     * whatever it is called has that part of it replaced. Both matter for the
     * same reason: this class does not own those two report shapes, so the field
     * somebody adds to Diagnostics next year arrives here unannounced, and the
     * guarantee at the top of this file has to hold for it anyway.
     *
     * Three things are deliberately left alone. A value that is a flag or a
     * number is not a credential, and blanking `token_set: true` would remove
     * the very answer the guarantee promises. A field already named `..._set` is
     * a flag by construction. And a field named exactly `key` is an identifier
     * here rather than a secret - the installation check, the settings catalogue
     * and the prompt library all name their rows that way, and blanking those
     * would gut the report this is meant to protect. Compound names like
     * `api_key` and `secret_key` are caught, and so is anything shaped like a
     * real token.
     *
     * @return array<string,mixed>
     */
    private static function pass(mixed $result): array
    {
        if (!is_array($result)) {
            return ['result' => is_string($result) ? self::scrubText($result) : $result];
        }

        $out = [];
        foreach ($result as $key => $value) {
            $name = (string)$key;

            if (str_ends_with($name, '_set')) {
                $out[$key] = $value;
                continue;
            }

            if (preg_match(self::SECRET_NAME, $name) === 1) {
                // Not walked into when it is an array: a credential wrapped in
                // an object would otherwise escape through an innocent-looking
                // inner name such as "value".
                $out[$key] = is_bool($value) || is_int($value) || is_float($value)
                    ? $value
                    : (self::filled($value) ? '[set]' : '[not set]');
                continue;
            }

            if (is_array($value)) {
                $out[$key] = self::pass($value);
                continue;
            }

            $out[$key] = is_string($value) ? self::scrubText($value) : $value;
        }
        return $out;
    }

    /** Whether there is anything under a credential-named field to report as stored. */
    private static function filled(mixed $value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }
        return is_scalar($value) && trim((string)$value) !== '';
    }

    /**
     * One string with any recognisable credential in it replaced.
     *
     * Only the token itself goes, not the sentence around it: an update log
     * line that says which request failed is worth keeping, and the part of it
     * worth hiding is the part that starts with a known prefix.
     */
    private static function scrubText(string $value): string
    {
        return (string)preg_replace(self::SECRET_VALUE, '[redacted]', $value);
    }

    /** An epoch as something a model can read, and null for "never". */
    private static function when(int $timestamp): ?string
    {
        return $timestamp > 0 ? gmdate('Y-m-d H:i', $timestamp) . ' UTC' : null;
    }

    /**
     * Four groups of four from an alphabet with no character that can be
     * mistaken for another - long enough to be a password, typable over the
     * phone. The same generator the Accounts screen uses.
     */
    private static function suggestPassword(): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $groups = [];
        for ($g = 0; $g < 4; $g++) {
            $chunk = '';
            for ($i = 0; $i < 4; $i++) {
                $chunk .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $groups[] = $chunk;
        }
        return implode('-', $groups);
    }
}
