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
    public const RESEARCH = 'research';
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
                'key' => self::RESEARCH,
                'label' => 'Research',
                'description' => 'Take the research assignment for a course, and store what searching the web '
                    . 'found. The client does the searching, so nothing here spends anything.',
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
                'description' => 'List and read pages, take a writing brief, store a finished page, and generate one '
                    . 'through the profile\'s model. Also retitles pages and chapters and rewrites a chapter '
                    . 'description, which edits the stored outline, because chapters and pages are matched by title.',
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

    /**
     * The groups the connection running the current tool holds.
     *
     * A tool that advises the client what to call next has to know what this
     * connection may actually call, or its advice sends the client into a
     * refusal it cannot diagnose. The handler signature is (Actor, array), and
     * widening it for the one tool that needs this would touch all of them, so
     * the set is left here for the duration of the call instead.
     *
     * Null means "not inside a tool call", which is the honest answer for the
     * catalogue, the tests and anything else that reaches a handler directly.
     * An empty ARRAY is a different thing entirely - a connection that holds no
     * groups at all - and the two deserve opposite answers from holds(). Using
     * one value for both would have made a connection with no scopes look
     * unrestricted, which is the wrong way round to be wrong.
     *
     * @var string[]|null
     */
    private static ?array $current = null;

    /**
     * Installs the allowed set for one call and returns the previous one.
     *
     * The caller restores it in a finally: a tool that throws must not leave
     * its scopes behind for the next one.
     *
     * @param string[]|null $allowed null to leave the call
     * @return string[]|null
     */
    public static function using(?array $allowed): ?array
    {
        $previous = self::$current;
        self::$current = $allowed;
        return $previous;
    }

    /** @return string[]|null */
    public static function currently(): ?array
    {
        return self::$current;
    }

    /** Whether the connection running this call holds one group. */
    public static function holds(string $scope): bool
    {
        // Outside a call nothing is known, and claiming a scope is missing
        // would make get_next_step lie to a direct caller. Say yes.
        if (self::$current === null) {
            return true;
        }
        return in_array($scope, self::$current, true);
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
