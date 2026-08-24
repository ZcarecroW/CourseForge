<?php
declare(strict_types=1);

namespace CourseForge\Security;

use CourseForge\Support\Db;
use CourseForge\Support\HttpException;
use CourseForge\Support\Request;

/**
 * The bridge between "who is asking" and "whose data is this".
 *
 * Everything a user owns carries their name in a column, and the domain classes
 * take that name as their first argument - `Projects::require($owner, $id)`.
 * That is deliberately unchanged from CourseForge 3: a course's profile, its
 * tags and its BookStack instance all belong to the person who owns the course,
 * not to whoever happens to be looking at it. An administrator opening somebody
 * else's course must see it exactly as its owner would.
 *
 * So authorisation is a separate step, and this is it. Each method takes the
 * actor and an id, answers "may you?", and hands back the row - from which the
 * caller reads `username` and carries on as before.
 *
 * A row the actor may not reach is reported as missing rather than forbidden.
 * Telling somebody that course 47 exists but is not theirs is a small leak that
 * costs nothing to avoid.
 */
final class Access
{
    /** @return array<string,mixed> */
    public static function project(Actor $actor, int $id): array
    {
        return self::row($actor, 'projects', $id, 'Course not found.');
    }

    /** @return array<string,mixed> */
    public static function profile(Actor $actor, int $id): array
    {
        return self::row($actor, 'profiles', $id, 'Profile not found.');
    }

    /** @return array<string,mixed> */
    public static function tag(Actor $actor, int $id): array
    {
        return self::row($actor, 'tags', $id, 'Tag not found.');
    }

    /** @return array<string,mixed> */
    public static function run(Actor $actor, int $id): array
    {
        return self::row($actor, 'batch_jobs', $id, 'Run not found.');
    }

    /** @return array<string,mixed> */
    public static function connection(Actor $actor, int $id): array
    {
        return self::row($actor, 'mcp_clients', $id, 'Connection not found.');
    }

    /** The owner of a course, having checked that the actor may reach it. */
    public static function ownerOfProject(Actor $actor, int $id): string
    {
        return (string)self::project($actor, $id)['username'];
    }

    /**
     * The account whose data a listing should show.
     *
     * `null` means every account, which is what an administrator gets unless
     * they have asked for one in particular.
     */
    public static function listingOwner(Actor $actor, string $requested = ''): ?string
    {
        $requested = trim($requested);

        if (!$actor->isAdmin()) {
            if ($requested !== '' && strcasecmp($requested, $actor->username) !== 0) {
                throw HttpException::forbidden('You can only list your own content.');
            }
            return $actor->username;
        }
        return $requested === '' ? null : $requested;
    }

    /**
     * The account whose working set a listing should show.
     *
     * Two kinds of listing live in this application and they want opposite
     * defaults. A course listing is the installation's inventory - what is
     * being written on it, what is stuck, how much of it there is - so it
     * widens by itself for an administrator, which is what `listingOwner()`
     * above does. Profiles, tags and connections are working sets: the picker
     * on the course screen, the twelve tags actually in use, the two machines
     * that can reach this install. Pouring every account's rows into those
     * would bury the ones the person came for, and a profile they cannot use
     * is a trap rather than an option - so here widening is opt-in, with
     * `?owner=name` for one other account or `?all=1` for the whole
     * installation. Both of those still go through `listingOwner()`, which is
     * what refuses a non-administrator asking after somebody else.
     *
     * It is asked on writes as well as on the listing route, because the
     * listing a write returns replaces the screen the caller is looking at.
     * An administrator who revokes alice's connection acts on alice's row and
     * must still get their own list back; handing them alice's would swap the
     * screen underneath them for one they never asked to open.
     */
    public static function workingSetOwner(Actor $actor, Request $request): ?string
    {
        return self::workingSet($actor, $request->query('owner'), $request->queryBool('all'));
    }

    /**
     * The same rule, for a caller that has arguments rather than a request.
     *
     * The MCP tools ask it with the arguments a model passed them. Both front
     * doors have to answer this the same way or the same account sees a
     * different tag library depending on which one it came through.
     */
    public static function workingSet(Actor $actor, string $requested = '', bool $all = false): ?string
    {
        if (trim($requested) === '' && !$all) {
            return $actor->username;
        }
        return self::listingOwner($actor, $requested);
    }

    /** @return array<string,mixed> */
    private static function row(Actor $actor, string $table, int $id, string $missing): array
    {
        $row = Db::row('SELECT * FROM ' . $table . ' WHERE id = ?', [$id]);
        if ($row === null || !$actor->mayReach((string)($row['username'] ?? ''))) {
            throw HttpException::notFound($missing);
        }
        return $row;
    }
}
