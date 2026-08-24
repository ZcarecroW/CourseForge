<?php
declare(strict_types=1);

namespace CourseForge\Mcp;

use CourseForge\Security\Actor;

/**
 * The groups a connection's tools are divided into.
 *
 * CourseForge 4 offers an MCP client everything the browser offers, which is a
 * lot of tools - and a token that only ever writes pages has no business
 * deleting a course or changing the cron secret. So each tool belongs to a
 * group, a connection may be limited to some of them, and a connection with no
 * groups chosen gets everything its account is allowed.
 *
 * Two of the groups are gated on the account rather than on the connection:
 * `admin` needs an administrator whatever the token says, and there is no way
 * to grant it to a connection owned by a normal account. Scopes narrow what a
 * token can do; they never widen it.
 *
 * Exactly one tool escapes the narrowing - `whoami`, which answers "what am I
 * connected as". A connection that cannot answer that is harder to use and no
 * safer, and it gives nothing away that the token did not already prove. That
 * exemption is a property of the one tool, declared on it, and deliberately
 * not of the group it sits in: the account group also holds "change my
 * password" and "revoke my connections", and a token narrowed to writing pages
 * has no business with either.
 */
final class Scopes
{
    public const COURSES = 'courses';
    public const STRUCTURE = 'structure';
    public const PAGES = 'pages';
    public const DETAILS = 'details';
    public const TAGS = 'tags';
    public const RUNS = 'runs';
    public const PROFILES = 'profiles';
    public const PUBLISH = 'publish';
    public const ACCOUNT = 'account';
    public const ADMIN = 'admin';

    /**
     * @return array<int,array{key:string,label:string,description:string,admin:bool,spends:bool}>
     */
    public static function catalogue(): array
    {
        return [
            [
                'key' => self::COURSES,
                'label' => 'Courses',
                'description' => 'List, read, create, rename and delete courses.',
                'admin' => false,
                'spends' => false,
            ],
            [
                'key' => self::STRUCTURE,
                'label' => 'Outlines',
                'description' => 'Read the outline brief, write an outline, and have CourseForge design one itself.',
                'admin' => false,
                'spends' => true,
            ],
            [
                'key' => self::PAGES,
                'label' => 'Pages',
                'description' => 'Read a writing brief, store a finished page, edit a page, and generate one through the profile\'s model.',
                'admin' => false,
                'spends' => true,
            ],
            [
                'key' => self::DETAILS,
                'label' => 'Content details',
                'description' => 'The thirteen switchable elements and seven values, at course, chapter or page level.',
                'admin' => false,
                'spends' => false,
            ],
            [
                'key' => self::TAGS,
                'label' => 'Tags',
                'description' => 'The tag library and what is tagged with what.',
                'admin' => false,
                'spends' => false,
            ],
            [
                'key' => self::RUNS,
                'label' => 'Generation runs',
                'description' => 'Start, watch and cancel background and batch runs. This is the group that spends money at scale.',
                'admin' => false,
                'spends' => true,
            ],
            [
                'key' => self::PROFILES,
                'label' => 'Profiles',
                'description' => 'AI accounts, models and BookStack instances. Keys are never readable, but they can be replaced.',
                'admin' => false,
                'spends' => false,
            ],
            [
                'key' => self::PUBLISH,
                'label' => 'Publishing',
                'description' => 'Push a course into BookStack and resolve cross references.',
                'admin' => false,
                'spends' => false,
            ],
            [
                'key' => self::ACCOUNT,
                'label' => 'This account',
                'description' => 'Who you are connected as, your own password and display name, and your own '
                    . "connections. Never anybody else's.",
                'admin' => false,
                'spends' => false,
            ],
            [
                'key' => self::ADMIN,
                'label' => 'Administration',
                'description' => 'Accounts, settings, the cron token, diagnostics and updates. Only ever available to an administrator.',
                'admin' => true,
                'spends' => false,
            ],
        ];
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_map(static fn(array $g): string => $g['key'], self::catalogue());
    }

    /** @return string[] the groups this account may reach at all */
    public static function forActor(Actor $actor): array
    {
        return array_values(array_filter(
            self::keys(),
            static fn(string $key): bool => $key !== self::ADMIN || $actor->isAdmin()
        ));
    }

    /**
     * What a connection actually gets: what the account allows, narrowed by
     * what the connection asked for.
     *
     * An empty request means "everything this account allows", which is what a
     * connection created without thinking about it should do.
     *
     * @param string[] $requested
     * @return string[]
     */
    public static function effective(Actor $actor, array $requested): array
    {
        $allowed = self::forActor($actor);
        if ($requested === []) {
            return $allowed;
        }

        return array_values(array_intersect($allowed, array_map('strval', $requested)));
    }

    /** @param string[] $requested keeps only groups that exist */
    public static function sanitise(array $requested): array
    {
        $known = self::keys();
        return array_values(array_unique(array_filter(
            array_map(static fn(mixed $v): string => is_scalar($v) ? trim((string)$v) : '', $requested),
            static fn(string $v): bool => in_array($v, $known, true)
        )));
    }
}
