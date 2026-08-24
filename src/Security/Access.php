<?php
declare(strict_types=1);

namespace CourseForge\Security;

use CourseForge\Support\Db;
use CourseForge\Support\HttpException;

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
