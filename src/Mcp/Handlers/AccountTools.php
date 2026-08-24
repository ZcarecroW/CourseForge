<?php
declare(strict_types=1);

namespace CourseForge\Mcp\Handlers;

use CourseForge\Ai\Run\RunManager;
use CourseForge\Domain\McpClients;
use CourseForge\Domain\Projects;
use CourseForge\Mcp\Args;
use CourseForge\Mcp\Schema;
use CourseForge\Mcp\Scopes;
use CourseForge\Mcp\Tool;
use CourseForge\Security\Actor;
use CourseForge\Security\Users;
use CourseForge\Support\Audit;
use CourseForge\Support\Config;
use CourseForge\Support\HttpException;

/**
 * The account on the other end of the connection.
 *
 * The browser lets anybody who is signed in look after themselves - change the
 * name shown at the top of the screen, change the password, see which machines
 * they have connected and cut one off. None of that was reachable over MCP, so
 * an account driving CourseForge entirely from Claude had to open a web browser
 * for the smallest change to itself. This group closes that.
 *
 * Nothing here takes an account as an argument. Every tool acts on the actor
 * the token resolved to and on nothing else, which is why the ownership checks
 * read the way they do: `Users::setDisplayName($actor->username, ...)` and
 * `McpClients::delete($actor->username, $id)` cannot be pointed at somebody
 * else even by an administrator, because there is no argument that would say
 * whom. An administrator who genuinely wants to change another account uses the
 * admin group, where that intention is written down.
 *
 * This is also where the surface opens - `whoami` is the first tool in the
 * registry, and `Scopes::effective()` always grants this group whatever a
 * connection asked for, because a connection that cannot answer "who am I" is
 * harder to use for no gain in safety.
 *
 * One thing is deliberately absent: there is no tool that CREATES a connection.
 * A token able to mint another token could mint one carrying scopes wider than
 * its own, and every narrowing on the Connect screen would become a formality -
 * the way past it would be one tool call. Issuing a connection stays in the
 * browser, where a person is present to decide what it may do and to take the
 * token, which is shown exactly once.
 */
final class AccountTools
{
    /** @return array<int,Tool> */
    public static function tools(): array
    {
        return [
            new Tool(
                name: 'whoami',
                scope: Scopes::ACCOUNT,
                title: 'Who am I connected as',
                description: 'The account this connection belongs to, its role, and what this installation of '
                    . 'CourseForge can currently do - whether the scheduler is running, so background runs are '
                    . 'possible, and how many courses the account can see.',
                properties: [],
                required: [],
                handler: static fn(Actor $actor, array $args): array => self::whoami($actor),
                readOnly: true,
                idempotent: true,
            ),

            new Tool(
                name: 'get_my_account',
                scope: Scopes::ACCOUNT,
                title: 'Read my account',
                description: 'Everything this installation records about the account this connection belongs to - its '
                    . 'user name, display name, role, when it was made and when it last signed in - together with '
                    . 'every MCP connection it owns and the tool groups each of those carries. Passwords are stored '
                    . 'only as hashes and tokens only as digests, so neither appears here. Costs nothing.',
                properties: [],
                required: [],
                handler: static fn(Actor $actor, array $args): array => self::getMyAccount($actor),
                readOnly: true,
                idempotent: true,
            ),

            new Tool(
                name: 'set_my_display_name',
                scope: Scopes::ACCOUNT,
                title: 'Rename myself',
                description: 'Changes the name shown for this account in the interface and against its courses. The '
                    . 'user name is the key everything is filed under and cannot be changed; this is only the '
                    . 'friendly name beside it. An empty value falls back to the user name. Costs nothing.',
                properties: [
                    'display_name' => Schema::string('The name to show for this account.', 'Ada Lovelace'),
                ],
                required: ['display_name'],
                handler: static fn(Actor $actor, array $args): array => self::setMyDisplayName($actor, Args::of($args)),
                idempotent: true,
            ),

            new Tool(
                name: 'change_my_password',
                scope: Scopes::ACCOUNT,
                title: 'Change my password',
                description: 'Sets a new password for this account. The current one has to be given and has to be '
                    . 'right, so a connection that has been handed round cannot lock the owner out of their own '
                    . 'installation. A password needs at least ' . Users::MIN_PASSWORD . ' characters and has to '
                    . 'differ from the current one. This does not sign anything out and does not revoke any '
                    . 'connection: open browser sessions and every MCP connection, this one included, carry on '
                    . 'working. Costs nothing.',
                properties: [
                    'current_password' => Schema::text(
                        'The password this account has now. Ask the person for it rather than guessing - a wrong '
                        . 'value changes nothing and is refused.'
                    ),
                    'new_password' => Schema::text(
                        'The password to set, at least ' . Users::MIN_PASSWORD . ' characters. '
                        . 'Leading and trailing spaces are kept, because they are part of a password.'
                    ),
                ],
                required: ['current_password', 'new_password'],
                handler: static fn(Actor $actor, array $args): array => self::changeMyPassword($actor, Args::of($args)),
            ),

            new Tool(
                name: 'list_my_connections',
                scope: Scopes::ACCOUNT,
                title: 'List my MCP connections',
                description: 'The MCP connections this account owns: the name each was given, the tool groups it is '
                    . 'limited to, when it was made, when it was last used, how often, and whether it has expired. '
                    . 'Only connections belonging to this account are ever listed, whatever its role. Tokens are '
                    . 'stored as hashes and can never be shown again. Costs nothing.',
                properties: [],
                required: [],
                handler: static fn(Actor $actor, array $args): array => self::listMyConnections($actor),
                readOnly: true,
            ),

            new Tool(
                name: 'revoke_my_connection',
                scope: Scopes::ACCOUNT,
                title: 'Revoke one of my connections',
                description: 'Deletes the token of one connection belonging to this account, so the next request it '
                    . 'makes is refused. Revoking the connection you are talking through ends this conversation '
                    . 'immediately and no further tool call will work - and this tool cannot tell you whether that is '
                    . 'the one, because a handler is told which account is calling and never which connection. Call '
                    . 'list_my_connections first and match the name against the client you are running in. This '
                    . 'cannot be undone: a replacement has to be made from the Connect screen in the browser. '
                    . 'Requires the connection name as confirmation.',
                properties: [
                    'connection_id' => Schema::int('The connection to revoke, as returned by list_my_connections.'),
                    'confirm_name' => Schema::string(
                        'The exact name of that connection, as a confirmation that the right one is being cut off.'
                    ),
                ],
                required: ['connection_id', 'confirm_name'],
                handler: static fn(Actor $actor, array $args): array => self::revokeMyConnection($actor, Args::of($args)),
                destructive: true,
            ),
        ];
    }

    /* ------------------------------------------------------------- handlers */

    /** @return array<string,mixed> */
    private static function whoami(Actor $actor): array
    {
        $cron = RunManager::cronStatus();

        return [
            'username' => $actor->username,
            'display_name' => $actor->displayName,
            'role' => $actor->role,
            'is_admin' => $actor->isAdmin(),
            'courses_visible' => count(Projects::all($actor->isAdmin() ? null : $actor->username)),
            'installation' => [
                'name' => Config::str('app.name', 'CourseForge'),
                'version' => CF_VERSION,
                'scheduler_configured' => (bool)($cron['configured'] ?? false),
                'scheduler_healthy' => (bool)($cron['healthy'] ?? false),
            ],
            'note' => ($cron['configured'] ?? false)
                ? 'Background and batch runs are available: work started here carries on after you disconnect.'
                : 'No cron token is configured, so background runs are not available. An administrator can set one '
                    . 'in Settings, or with set_settings if this connection has the admin scope.',
        ];
    }

    /** @return array<string,mixed> */
    private static function getMyAccount(Actor $actor): array
    {
        $account = Users::publicView(Users::require($actor->username));

        $connections = [];
        foreach (McpClients::all($actor->username) as $client) {
            $connections[] = self::connection($client);
        }

        return [
            'account' => $account,
            'created' => self::when((int)$account['created_at']),
            'last_login' => self::when((int)$account['last_login_at']),
            'connections' => $connections,
            'connection_count' => count($connections),
            // Worth saying rather than leaving a model to infer it from the
            // list: a handler is handed the Actor and the arguments, and the
            // connection the call arrived on is not among them. A model that
            // guesses "it must be the one used most recently" will eventually
            // revoke the wrong one.
            'which_connection_is_this' => 'Unknown, and unknowable from here. A tool is told which account is '
                . 'calling, never which connection it came in on, so nothing below is marked as the current one.',
            'next' => 'set_my_display_name and change_my_password change this account. revoke_my_connection cuts '
                . 'off one of the connections listed here.',
        ];
    }

    /** @return array<string,mixed> */
    private static function setMyDisplayName(Actor $actor, Args $args): array
    {
        $updated = Users::setDisplayName($actor->username, $args->str('display_name'));

        return [
            'updated' => true,
            'username' => (string)$updated['username'],
            'display_name' => (string)$updated['display_name'],
            'note' => 'The user name is unchanged, and everything filed against this account still is.',
        ];
    }

    /** @return array<string,mixed> */
    private static function changeMyPassword(Actor $actor, Args $args): array
    {
        // raw(), not str(): a password is whatever was typed, spaces included,
        // and trimming one here would set a password nobody can sign in with.
        $current = $args->requiredRaw('current_password');
        $new = $args->requiredRaw('new_password');

        Users::validatePassword($new);
        if ($current === $new) {
            throw HttpException::unprocessable('The new password must differ from the current one.');
        }

        // changePassword answers false for a wrong current password and writes
        // nothing. Returning that quietly would look to a model exactly like
        // success, so it becomes a refusal that says what went wrong.
        if (!Users::changePassword($actor->username, $current, $new)) {
            throw HttpException::forbidden(
                'The current password is incorrect, so nothing was changed. Ask the person for it again rather '
                . 'than trying another guess.'
            );
        }

        Audit::record($actor->username, 'account.password_changed', $actor->username, 'via MCP', 'mcp');

        return [
            'changed' => true,
            'username' => $actor->username,
            'note' => 'Nothing was signed out and no connection was revoked. Browser sessions and MCP connections, '
                . 'this one included, keep working; use revoke_my_connection to end any of them.',
        ];
    }

    /** @return array<string,mixed> */
    private static function listMyConnections(Actor $actor): array
    {
        $rows = [];
        foreach (McpClients::all($actor->username) as $client) {
            $rows[] = self::connection($client);
        }

        return [
            'connections' => $rows,
            'count' => count($rows),
            'owner' => $actor->username,
            'note' => "These are this account's connections only. A connection never exceeds what its account may "
                . 'do: the role is read from the account on every request, so it narrows the moment the account does.',
            'next' => 'revoke_my_connection takes a connection_id from this list and the matching name as '
                . 'confirmation. It cannot tell whether that connection is the one you are talking through.',
        ];
    }

    /** @return array<string,mixed> */
    private static function revokeMyConnection(Actor $actor, Args $args): array
    {
        $id = $args->id('connection_id');

        // The actor's own name on both calls. An id belonging to another
        // account is reported as not found, which is the truth as far as this
        // group is concerned.
        $client = McpClients::require($actor->username, $id);

        if ($args->requiredStr('confirm_name') !== (string)$client['name']) {
            throw HttpException::unprocessable(
                'confirm_name does not match. Connection ' . $id . ' is called "' . $client['name'] . '".'
            );
        }

        McpClients::delete($actor->username, $id);
        Audit::record($actor->username, 'connect.revoke', (string)$client['name'], 'own connection, via MCP', 'mcp');

        return [
            'revoked' => true,
            'connection_id' => $id,
            'name' => (string)$client['name'],
            'note' => 'That token is refused from now on. If it was the one this conversation is using, the next '
                . 'call will fail to authenticate and a new connection has to be made from the Connect screen.',
        ];
    }

    /* -------------------------------------------------------------- helpers */

    /**
     * One connection as this group reports it.
     *
     * The same fields the Connect screen shows, minus the owner - everything
     * here belongs to the actor, so a column repeating their name on every row
     * would say nothing.
     *
     * @param array<string,mixed> $client
     * @return array<string,mixed>
     */
    private static function connection(array $client): array
    {
        $scopes = is_array($client['scopes'] ?? null) ? $client['scopes'] : [];

        return [
            'connection_id' => (int)$client['id'],
            'name' => (string)$client['name'],
            'note' => (string)$client['note'],
            'scopes' => $scopes,
            'scope_note' => $scopes === []
                ? 'No groups chosen, so this connection gets everything its account is allowed.'
                : 'Limited to these groups.',
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

    /** A stored timestamp as something a person can read, or nothing at all. */
    private static function when(int $timestamp): ?string
    {
        return $timestamp > 0 ? gmdate('Y-m-d H:i', $timestamp) . ' UTC' : null;
    }
}
