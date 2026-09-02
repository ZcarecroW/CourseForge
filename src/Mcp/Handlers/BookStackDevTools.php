<?php
declare(strict_types=1);

namespace CourseForge\Mcp\Handlers;

use CourseForge\Domain\BookStackDev;
use CourseForge\Mcp\Args;
use CourseForge\Mcp\Schema;
use CourseForge\Mcp\Scopes;
use CourseForge\Mcp\Tool;
use CourseForge\Security\Access;
use CourseForge\Security\Actor;
use CourseForge\Support\Audit;
use CourseForge\Support\HttpException;

/**
 * BookStackDev over MCP: the looks a BookStack instance can wear, and the link
 * that puts one on.
 *
 * Everything the BookStackDev screen does, under the profiles group, because
 * a look is assigned to a BookStack instance and instances live on profiles.
 * A client can make a look, set any of its forty-odd options by the same keys
 * the screen uses, say which instances and which other addresses it works on,
 * read the embed line to hand to a wiki administrator, regenerate that line,
 * and ask whether the prompts agree with what the look renders.
 */
final class BookStackDevTools
{
    /** @return array<int,Tool> */
    public static function tools(): array
    {
        return [
            new Tool(
                name: 'list_bookstackdev_profiles',
                scope: Scopes::PROFILES,
                title: 'List BookStackDev looks',
                description: 'The looks this account has made for its BookStack instances: name, which instances '
                    . 'wear it, which other addresses the link is allowed on, and the embed line. A look is a '
                    . 'BookStackDev configuration - Shiki code highlighting, Mermaid, MathJax, link embeds, an audio '
                    . 'player, external-link marks, a light/dark button, page styling - served to a wiki by one '
                    . 'script tag. Costs nothing.',
                properties: [
                    'owner' => Schema::string('Administrators only: the account whose looks to list.'),
                    'all' => Schema::bool('Administrators only: every account\'s looks.'),
                ],
                required: [],
                handler: static fn(Actor $actor, array $args): array => self::listLooks($actor, Args::of($args)),
                readOnly: true,
                idempotent: true,
            ),

            new Tool(
                name: 'list_bookstackdev_options',
                scope: Scopes::PROFILES,
                title: 'What a look can be told',
                description: 'Every option a BookStackDev look has, grouped by feature, with its type, its default '
                    . 'and its range - the keys create_bookstackdev_profile and update_bookstackdev_profile take in '
                    . '`settings`. Also the Shiki themes the code highlighter knows. Costs nothing.',
                properties: [],
                required: [],
                handler: static fn(Actor $actor, array $args): array => [
                    'groups' => BookStackDev::catalogue(),
                    'shiki_themes' => BookStackDev::SHIKI_THEMES,
                    'mermaid_themes' => BookStackDev::MERMAID_THEMES,
                    'settings_shape' => 'An object keyed by group, then by field: {"math": {"inlineDollar": true}}. '
                        . 'Only what you give changes; every other field keeps its value.',
                ],
                readOnly: true,
                idempotent: true,
            ),

            new Tool(
                name: 'get_bookstackdev_profile',
                scope: Scopes::PROFILES,
                title: 'Read one look',
                description: 'One look in full: every setting, the instances wearing it, every address the link '
                    . 'works on, the embed line for a wiki\'s custom head, and the conventions check - whether the '
                    . 'prompts of the profiles publishing into it agree with what it renders. Costs nothing.',
                properties: [
                    'bookstackdev_id' => Schema::int('The look, as returned by list_bookstackdev_profiles.'),
                ],
                required: ['bookstackdev_id'],
                handler: static fn(Actor $actor, array $args): array => self::getLook($actor, Args::of($args)),
                readOnly: true,
                idempotent: true,
            ),

            new Tool(
                name: 'create_bookstackdev_profile',
                scope: Scopes::PROFILES,
                title: 'Create a look',
                description: 'Makes a look with every feature switched on the way the shipped BookStackDev is, '
                    . 'changed by whatever settings you give. Name the BookStack instances that should wear it '
                    . '(get_profile lists their ids) and any other address the link should work on - a wiki '
                    . 'CourseForge holds no credentials for. The answer carries the embed line. Costs nothing.',
                properties: [
                    'name' => Schema::string('What to call it, for the picker.', 'Company wiki'),
                    'settings' => Schema::object(
                        'Settings to change from the defaults, keyed by group then field - see '
                        . 'list_bookstackdev_options. {"codeBlocks": {"themeDark": "github-dark"}}'
                    ),
                    'instance_ids' => Schema::strings('BookStack instance ids, from get_profile, that wear this look.'),
                    'origins' => Schema::strings(
                        'Other addresses the link may be loaded on, as origins: https://wiki.example.com. '
                        . 'The instances above are allowed without being listed here.'
                    ),
                ],
                required: ['name'],
                handler: static fn(Actor $actor, array $args): array => self::createLook($actor, Args::of($args)),
            ),

            new Tool(
                name: 'update_bookstackdev_profile',
                scope: Scopes::PROFILES,
                title: 'Change a look',
                description: 'Changes one look. Only what you give is touched: a settings object changes the '
                    . 'fields it names and no other, instance_ids replaces the list of instances wearing it, '
                    . 'origins replaces the list of extra addresses. Costs nothing.',
                properties: [
                    'bookstackdev_id' => Schema::int('The look to change.'),
                    'name' => Schema::string('A new name.'),
                    'settings' => Schema::object('Fields to change, keyed by group then field - see list_bookstackdev_options.'),
                    'instance_ids' => Schema::strings('The complete list of instance ids that wear this look afterwards.'),
                    'origins' => Schema::strings('The complete list of extra origins afterwards.'),
                ],
                required: ['bookstackdev_id'],
                handler: static fn(Actor $actor, array $args): array => self::updateLook($actor, Args::of($args)),
                idempotent: true,
            ),

            new Tool(
                name: 'delete_bookstackdev_profile',
                scope: Scopes::PROFILES,
                title: 'Delete a look',
                description: 'Removes a look. Its link stops answering at once, and every instance wearing it goes '
                    . 'back to plain BookStack. Requires the name as confirmation. Costs nothing.',
                properties: [
                    'bookstackdev_id' => Schema::int('The look to delete.'),
                    'confirm_name' => Schema::string('The exact name of the look, as a confirmation that the right one is being deleted.'),
                ],
                required: ['bookstackdev_id', 'confirm_name'],
                handler: static fn(Actor $actor, array $args): array => self::deleteLook($actor, Args::of($args)),
                destructive: true,
            ),

            new Tool(
                name: 'rotate_bookstackdev_link',
                scope: Scopes::PROFILES,
                title: 'Regenerate the link of a look',
                description: 'Gives a look a new key, and so a new embed line. The old line is refused from this '
                    . 'moment wherever it was pasted, which is how a link that got copied somewhere it should not '
                    . 'have been is taken back. Every wiki using it has to paste the new line. Costs nothing.',
                properties: [
                    'bookstackdev_id' => Schema::int('The look whose link to regenerate.'),
                ],
                required: ['bookstackdev_id'],
                handler: static fn(Actor $actor, array $args): array => self::rotateLook($actor, Args::of($args)),
                destructive: true,
            ),

            new Tool(
                name: 'check_bookstackdev_conventions',
                scope: Scopes::PROFILES,
                title: 'Check a look against the prompts',
                description: 'Whether the prompts of every profile publishing into an instance wearing this look '
                    . 'agree with what the look renders: formulas written for delimiters it does not typeset, '
                    . 'diagrams or formulas asked for while the feature is off. A finding about a prompt carries '
                    . 'the recommended wording, which set_profile_prompts can write. Costs nothing.',
                properties: [
                    'bookstackdev_id' => Schema::int('The look to check.'),
                ],
                required: ['bookstackdev_id'],
                handler: static fn(Actor $actor, array $args): array => self::checkLook($actor, Args::of($args)),
                readOnly: true,
                idempotent: true,
            ),
        ];
    }

    /* ------------------------------------------------------------- handlers */

    /** @return array<string,mixed> */
    private static function listLooks(Actor $actor, Args $args): array
    {
        $owner = Access::workingSet($actor, $args->str('owner'), $args->bool('all'));

        $out = [];
        foreach (BookStackDev::all($owner) as $row) {
            $described = BookStackDev::describe($row, false);
            $entry = [
                'bookstackdev_id' => $described['id'],
                'name' => $described['name'],
                'instances' => $described['instances'],
                'allowed_origins' => $described['allowed_origins'],
                'embed' => $described['embed'],
            ];
            if ($actor->isAdmin()) {
                $entry['owner'] = $described['owner'];
            }
            $out[] = $entry;
        }

        return [
            'looks' => $out,
            'count' => count($out),
            'next' => $out === []
                ? 'There is no look yet. Call list_bookstackdev_options to see what one can be told, then create_bookstackdev_profile.'
                : 'Call get_bookstackdev_profile for one in full, including its settings and the conventions check.',
        ];
    }

    /** @return array<string,mixed> */
    private static function getLook(Actor $actor, Args $args): array
    {
        $row = self::resolve($actor, $args);
        return self::shape(BookStackDev::describe($row));
    }

    /** @return array<string,mixed> */
    private static function createLook(Actor $actor, Args $args): array
    {
        $name = $args->requiredStr('name');
        $row = BookStackDev::create($actor->username, $name, $args->object('settings'), $args->strings('origins'));
        if ($args->has('instance_ids')) {
            self::assign($actor->username, (int)$row['id'], $args->strings('instance_ids'));
        }
        Audit::record($actor->username, 'bookstackdev.create', $name, 'via MCP', 'mcp');

        $described = BookStackDev::describe(BookStackDev::require($actor->username, (int)$row['id']));
        return ['created' => true] + self::shape($described) + [
            'next' => 'Paste embed.snippet into BookStack under Settings > Customization > Custom HTML head '
                . 'of every wiki listed in allowed_origins. Nothing else has to change in BookStack.',
        ];
    }

    /** @return array<string,mixed> */
    private static function updateLook(Actor $actor, Args $args): array
    {
        $row = self::resolve($actor, $args);
        $owner = (string)$row['username'];

        $fields = [];
        $changed = [];
        if ($args->has('name')) {
            $fields['name'] = $args->requiredStr('name');
            $changed[] = 'name';
        }
        if ($args->has('settings')) {
            $settings = $args->object('settings');
            self::refuseUnknownSettings($settings);
            $fields['settings'] = $settings;
            $changed[] = 'settings';
        }
        if ($args->has('origins')) {
            $fields['origins'] = $args->strings('origins');
            $changed[] = 'origins';
        }
        if ($args->has('instance_ids')) {
            $changed[] = 'instance_ids';
        }
        if ($changed === []) {
            throw HttpException::unprocessable(
                'Nothing to change. Give name, settings, origins or instance_ids.'
            );
        }

        $updated = BookStackDev::update($owner, (int)$row['id'], $fields);
        if ($args->has('instance_ids')) {
            self::assign($owner, (int)$row['id'], $args->strings('instance_ids'));
        }
        Audit::record($actor->username, 'bookstackdev.update', (string)$updated['name'], implode(', ', $changed) . ', via MCP', 'mcp');

        return ['changed' => $changed] + self::shape(BookStackDev::describe(BookStackDev::require($owner, (int)$row['id'])));
    }

    /** @return array<string,mixed> */
    private static function deleteLook(Actor $actor, Args $args): array
    {
        $row = self::resolve($actor, $args);
        if ($args->requiredStr('confirm_name') !== (string)$row['name']) {
            throw HttpException::unprocessable(
                'confirm_name does not match. The look is called "' . (string)$row['name'] . '".'
            );
        }
        BookStackDev::delete((string)$row['username'], (int)$row['id']);
        Audit::record($actor->username, 'bookstackdev.delete', (string)$row['name'], 'via MCP', 'mcp');

        return [
            'deleted' => true,
            'bookstackdev_id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'note' => 'The link stopped answering. Every wiki that had it in its custom head now loads nothing from it.',
        ];
    }

    /** @return array<string,mixed> */
    private static function rotateLook(Actor $actor, Args $args): array
    {
        $row = self::resolve($actor, $args);
        $rotated = BookStackDev::rotateKey((string)$row['username'], (int)$row['id']);
        Audit::record($actor->username, 'bookstackdev.rotate', (string)$row['name'], 'via MCP', 'mcp');

        return [
            'rotated' => true,
            'bookstackdev_id' => (int)$rotated['id'],
            'name' => (string)$rotated['name'],
            'embed' => BookStackDev::embed($rotated),
            'next' => 'Replace the script line in the custom head of every wiki that uses this look. The old line is refused.',
        ];
    }

    /** @return array<string,mixed> */
    private static function checkLook(Actor $actor, Args $args): array
    {
        $row = self::resolve($actor, $args);
        $audit = BookStackDev::audit($row);
        return [
            'bookstackdev_id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'ok' => $audit['ok'],
            'profiles_checked' => $audit['checked'],
            'issues' => $audit['issues'],
            'next' => $audit['ok']
                ? ($audit['checked'] === 0
                    ? 'No profile publishes into an instance wearing this look, so there is nothing to compare. Assign an instance with update_bookstackdev_profile.'
                    : 'Every checked profile agrees with this look.')
                : 'A finding with a `recommended` text is a prompt: write it with set_profile_prompts for that '
                    . 'profile (slot feature_mathjax_on), or with set_prompt as an administrator where `layer` is '
                    . '"installation". A finding without one is a content decision - see its message.',
        ];
    }

    /* -------------------------------------------------------------- helpers */

    /** @return array<string,mixed> the look, having checked the actor may reach it */
    private static function resolve(Actor $actor, Args $args): array
    {
        $id = $args->id('bookstackdev_id');
        $owner = (string)Access::look($actor, $id)['username'];
        return BookStackDev::require($owner, $id);
    }

    /** The instances named must belong to the owner, and be named by an id that exists. */
    private static function assign(string $owner, int $id, array $instanceIds): void
    {
        $known = [];
        foreach (BookStackDev::instancesOf($owner) as $instance) {
            $known[] = $instance['instance_id'];
        }
        foreach ($instanceIds as $instanceId) {
            if (!in_array($instanceId, $known, true)) {
                throw HttpException::unprocessable(
                    'BookStack instance "' . $instanceId . '" is not on any of this account\'s profiles. '
                    . 'get_profile lists the ids' . ($known === [] ? '.' : ': ' . implode(', ', $known) . '.')
                );
            }
        }
        BookStackDev::assignInstances($owner, $id, $instanceIds);
    }

    /**
     * A settings key nobody knows is refused rather than dropped: a client that
     * mistyped "themeDark" would otherwise be told "changed" and see nothing change.
     *
     * @param array<string,mixed> $settings
     */
    private static function refuseUnknownSettings(array $settings): void
    {
        $catalogue = [];
        foreach (BookStackDev::catalogue() as $group) {
            $catalogue[$group['key']] = array_column($group['fields'], 'key');
        }
        foreach ($settings as $group => $fields) {
            if (!isset($catalogue[$group])) {
                throw HttpException::unprocessable(
                    'There is no settings group called "' . $group . '". The groups are: ' . implode(', ', array_keys($catalogue)) . '.'
                );
            }
            if (!is_array($fields)) {
                throw HttpException::unprocessable('settings.' . $group . ' must be an object of fields.');
            }
            foreach (array_keys($fields) as $field) {
                if (!in_array((string)$field, $catalogue[$group], true)) {
                    throw HttpException::unprocessable(
                        'There is no field "' . $field . '" in the group "' . $group . '". Its fields are: '
                        . implode(', ', $catalogue[$group]) . '.'
                    );
                }
            }
        }
    }

    /** The described look, with the id under the name every tool here uses. @return array<string,mixed> */
    private static function shape(array $described): array
    {
        $described['bookstackdev_id'] = $described['id'];
        unset($described['id']);
        return $described;
    }
}
