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
 * registry, and the only one in the whole application that a narrowed
 * connection keeps whatever it was given. That exemption is declared on the
 * tool itself rather than on this group, and the distinction is the point: a
 * connection that cannot say what it is connected as is harder to use and no
 * safer, while the rest of this group changes a password and revokes
 * connections. Exempting the group would have handed both to a token that was
 * given neither.
 *
 * Issuing a connection lives here too, and the rule that makes that safe is
 * worth stating rather than assuming. A token able to mint another token could
 * mint one carrying scopes wider than its own, and every narrowing on the
 * Connect screen would become a formality - the way past it would be one tool
 * call. So a connection issued through `create_my_connection` may hold nothing
 * the connection asking for it does not already hold: the request is checked
 * against `Scopes::currently()`, an empty request means "the same as mine"
 * rather than "everything the account allows", and a request naming a group
 * this connection lacks is refused by name rather than quietly narrowed. A
 * token can therefore make a copy of itself or something smaller, and never
 * something larger, which is the property the Connect screen was protecting.
 *
 * The browser has no such limit, because a person signed in with a password is
 * the account rather than a delegation of it. That difference is why the check
 * is here and not in `McpClients::create()`.
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
                // The one tool a narrowed connection keeps. Answering "what am
                // I connected as" gives away nothing the token did not already
                // prove, and a connection that cannot answer it is harder to
                // use for no gain in safety. Nothing else in this group is
                // exempt - changing a password and revoking a connection are
                // both here, and a token narrowed to writing pages has no
                // business with either.
                alwaysAvailable: true,
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
                    . 'limited to, when it was made, when it was last used, how often, and whether it still works. '
                    . 'Two things stop one working without removing it - passing its expiry date, and being older '
                    . 'than the last password an administrator set for this account. Only connections belonging to '
                    . 'this account are ever listed, whatever its role. Tokens are stored as hashes and can never '
                    . 'be shown again. Costs nothing.',
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

            new Tool(
                name: 'list_scopes',
                scope: Scopes::ACCOUNT,
                title: 'List the tool groups a connection can be limited to',
                description: 'Every tool group this installation has: its key, what it covers, whether it needs an '
                    . 'administrator and whether anything in it spends money on the AI account. It also says which '
                    . 'groups this account may hold at all, and which the connection you are talking through holds '
                    . 'right now - which is the ceiling on what create_my_connection will issue. Costs nothing.',
                properties: [],
                required: [],
                handler: static fn(Actor $actor, array $args): array => self::listScopes($actor),
                readOnly: true,
                idempotent: true,
            ),

            new Tool(
                name: 'create_my_connection',
                scope: Scopes::ACCOUNT,
                title: 'Issue a new MCP connection for this account',
                description: 'Mints a token for another Claude client to connect with, in this account\'s name - the '
                    . 'same thing the Connect screen does, for setting a second machine up without opening a browser. '
                    . 'The token is returned exactly once and is never recoverable: the database keeps only a hash. '
                    . 'A new connection may hold no tool group that the connection asking for it does not already '
                    . 'hold, so a narrowed token can copy itself or make something smaller and never something '
                    . 'larger; naming a group this connection lacks is refused rather than quietly dropped. Leaving '
                    . 'scopes out means the same groups this connection has. Call list_scopes for the keys. '
                    . 'Costs nothing.',
                properties: [
                    'name' => Schema::string('What to call the connection, so it is recognisable in the list.', 'Laptop'),
                    'scopes' => Schema::strings(
                        'Tool groups the new connection may use, by key. Omit for the same groups this connection '
                        . 'holds.'
                    ),
                    'ttl_days' => Schema::int(
                        'Days until it expires by itself. 0 means it never does, which is what the browser offers '
                        . 'by default.',
                        0,
                        365
                    ),
                    'note' => Schema::string('A line about which machine it is for, shown beside it in the list.'),
                ],
                required: ['name'],
                handler: static fn(Actor $actor, array $args): array => self::createMyConnection($actor, Args::of($args)),
            ),

            new Tool(
                name: 'rename_my_connection',
                scope: Scopes::ACCOUNT,
                title: 'Rename one of my connections',
                description: 'Changes the name or the note on a connection belonging to this account. Only those '
                    . 'two: the tool groups and the expiry are what the token was issued to do, and widening them '
                    . 'afterwards would make the record of what was issued a lie - to change either, revoke it and '
                    . 'issue another. An omitted field keeps what is stored. Costs nothing.',
                properties: [
                    'connection_id' => Schema::int('The connection to rename, as returned by list_my_connections.'),
                    'name' => Schema::string('A new name.'),
                    'note' => Schema::string('A new note.'),
                ],
                required: ['connection_id'],
                handler: static fn(Actor $actor, array $args): array => self::renameMyConnection($actor, Args::of($args)),
                idempotent: true,
            ),
        ];
    }

    /* ------------------------------------------------------------- handlers */

    /** @return array<string,mixed> */
    private static function listScopes(Actor $actor): array
    {
        $allowed = Scopes::forActor($actor);
        $held = Scopes::currently();

        $groups = [];
        foreach (Scopes::catalogue() as $entry) {
            $key = (string)($entry['key'] ?? '');
            $groups[] = $entry + [
                'allowed_for_this_account' => in_array($key, $allowed, true),
                // Null outside a tool call, which is the honest answer rather
                // than a claim that everything is held.
                'held_by_this_connection' => $held === null ? null : in_array($key, $held, true),
            ];
        }

        return [
            'scopes' => $groups,
            'allowed_for_this_account' => $allowed,
            'held_by_this_connection' => $held,
            'note' => $held === null
                ? 'This call did not come through a connection, so there is no ceiling to report.'
                : 'create_my_connection will not issue a group that is not in held_by_this_connection.',
        ];
    }

    /** @return array<string,mixed> */
    private static function createMyConnection(Actor $actor, Args $args): array
    {
        $ceiling = Scopes::currently() ?? Scopes::forActor($actor);
        $requested = $args->strings('scopes');

        // An empty request means "everything the account allows" by the time
        // McpClients stores it, so it has to be filled in here rather than
        // passed on - or a narrowed connection would issue an unnarrowed one
        // by saying nothing at all.
        if ($requested === []) {
            $scopes = $ceiling;
        } else {
            $beyond = array_values(array_diff($requested, $ceiling));
            if ($beyond !== []) {
                throw HttpException::forbidden(
                    'This connection does not hold ' . implode(', ', $beyond) . ', so it cannot issue a connection '
                    . 'that does. It holds: ' . (implode(', ', $ceiling) ?: 'nothing')
                    . '. A wider connection has to be made from the Connect screen in the browser, where a person '
                    . 'decides.'
                );
            }
            $scopes = array_values(array_intersect($ceiling, $requested));
        }

        $ttlDays = max(0, min(365, $args->int('ttl_days', 0)));
        $created = McpClients::create(
            $actor->username,
            $args->requiredStr('name'),
            $scopes,
            $ttlDays,
            $args->str('note')
        );

        Audit::record(
            $actor->username,
            'connect.create',
            (string)$created['client']['name'],
            'scopes=' . (implode(' ', $scopes) ?: 'all') . '; ttl_days=' . $ttlDays . ', via MCP',
            'mcp'
        );

        return [
            'created' => true,
            'connection_id' => (int)$created['client']['id'],
            'name' => (string)$created['client']['name'],
            'scopes' => $scopes,
            'expires_at' => (int)$created['client']['expires_at'],
            // Returned once, and the only time it is ever readable.
            'token' => $created['token'],
            'note' => 'The token is shown here and nowhere else, ever - the database keeps only a hash of it. Hand '
                . 'it to the client that needs it now; a lost one is replaced rather than recovered.',
            'next_step' => 'The client connects to this installation\'s /api/mcp.php with the token as its bearer. '
                . 'list_my_connections will show it being used.',
        ];
    }

    /** @return array<string,mixed> */
    private static function renameMyConnection(Actor $actor, Args $args): array
    {
        $id = $args->id('connection_id');

        // Scoped to this account by the lookup itself, not by a check after
        // it: there is no argument here that could name somebody else's row.
        $client = McpClients::require($actor->username, $id);

        $name = $args->has('name') ? $args->requiredStr('name') : (string)$client['name'];
        $note = $args->has('note') ? $args->str('note') : (string)$client['note'];

        if ($name === (string)$client['name'] && $note === (string)$client['note']) {
            throw HttpException::unprocessable('Nothing to change. Give a name or a note that differs.');
        }

        $updated = McpClients::rename($actor->username, $id, $name, $note);

        // Recorded even though nothing is granted, because the name is what a
        // person reads the list by: renaming "old laptop, revoke me" into "CI
        // server" is how a connection nobody trusts comes to look like one
        // everybody does, and the old name is the only trace of it.
        Audit::record(
            $actor->username,
            'connect.rename',
            (string)$updated['name'],
            'was=' . (string)$client['name'] . ', via MCP',
            'mcp'
        );

        return [
            'renamed' => true,
            'connection_id' => $id,
            'name' => (string)$updated['name'],
            'note' => (string)$updated['note'],
        ];
    }

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
        $updated = Users::setDisplayName($actor->username, $args->requiredStr('display_name'));

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
            // The second way a connection can be listed and not work. Said as
            // its own field rather than left out of the list, because a
            // connection that has vanished from a listing looks like one
            // somebody revoked on purpose.
            'cut_off_by_password_reset' => (bool)($client['revoked_by_reset'] ?? false),
        ];
    }

    /** A stored timestamp as something a person can read, or nothing at all. */
    private static function when(int $timestamp): ?string
    {
        return $timestamp > 0 ? gmdate('Y-m-d H:i', $timestamp) . ' UTC' : null;
    }
}
