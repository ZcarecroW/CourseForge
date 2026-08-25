<?php
declare(strict_types=1);

namespace CourseForge\Mcp;

use CourseForge\Domain\Chapters;
use CourseForge\Domain\Pages;
use CourseForge\Domain\Profiles;
use CourseForge\Security\Access;
use CourseForge\Security\Actor;
use CourseForge\Support\HttpException;

/**
 * Turning an id in a tool call into the thing it names, having checked that the
 * caller may have it.
 *
 * Every tool starts the same way, and getting that opening wrong is the one
 * mistake in this whole surface that would actually matter: authorise against
 * the actor, then carry on using the row's OWNER for everything downstream. An
 * administrator writing a page of somebody else's course must resolve that
 * course's profile, that course's tags and that course's BookStack instance -
 * not their own. Putting the two lines in one place is how they stay together.
 */
final class Resolve
{
    /**
     * A course the actor may reach, and its owner.
     *
     * @return array{project:array<string,mixed>,owner:string}
     */
    public static function course(Actor $actor, int $courseId): array
    {
        $project = Access::project($actor, $courseId);
        return ['project' => $project, 'owner' => (string)$project['username']];
    }

    /**
     * The AI account and settings a course generates with.
     *
     * Read from the course's own profile, which belongs to the course's owner.
     * A course with no profile cannot generate anything, and saying so plainly
     * is more use to a model than a null pointer three calls later.
     *
     * @param array<string,mixed> $project
     * @return array<string,mixed>
     */
    public static function profile(array $project): array
    {
        if ($project['profile_id'] === null) {
            throw HttpException::unprocessable(
                'Course "' . $project['name'] . '" has no profile, so there is no AI account for CourseForge to '
                . 'generate with. Assign one with update_course, after listing the options with list_profiles. '
                . 'You do not need one to write the course yourself: get_structure_brief and get_page_brief hand '
                . 'you the instructions, and apply_structure and write_page store what you write, all without a '
                . 'profile.'
            );
        }
        return Profiles::data((string)$project['username'], (int)$project['profile_id']);
    }

    /**
     * The profile a course would generate with, or nothing when it has none.
     *
     * For the paths that only BUILD a brief rather than send it anywhere. They
     * need no credential and no model - the profile reaches Prompt::library(),
     * which overlays the profile's prompt overrides on the shipped defaults,
     * and Completion::language(), which reads the output language. Both treat
     * an empty profile as "use what the installation is configured with", so a
     * course without one gets the default brief rather than an error.
     *
     * This is what makes the provider-free path actually free. A client that
     * does its own writing needs get_structure_brief and get_page_brief, and
     * requiring a profile for those meant requiring an AI account nobody was
     * ever going to call - which was the one thing that path exists to avoid.
     *
     * Anything that SPENDS money must keep using profile() instead: a run with
     * no credential has to fail at the point of asking, not silently proceed.
     *
     * @param array<string,mixed> $project
     * @return array<string,mixed> empty when the course has no profile
     */
    public static function profileForBrief(array $project): array
    {
        if ($project['profile_id'] === null) {
            return [];
        }
        return Profiles::data((string)$project['username'], (int)$project['profile_id']);
    }

    /**
     * One page of a course.
     *
     * @param array<string,mixed> $project
     * @return array<string,mixed>
     */
    public static function page(array $project, int $pageId): array
    {
        return Pages::require((int)$project['id'], $pageId);
    }

    /**
     * One chapter of a course.
     *
     * @param array<string,mixed> $project
     * @return array<string,mixed>
     */
    public static function chapter(array $project, int $chapterId): array
    {
        return Chapters::require((int)$project['id'], $chapterId);
    }

    /**
     * The next page of a course that has not been written yet.
     *
     * @param array<string,mixed> $project
     * @return array<string,mixed>|null
     */
    public static function nextUnwritten(array $project): ?array
    {
        foreach (Pages::ordered((int)$project['id']) as $page) {
            if (trim((string)$page['content']) === '' && (string)$page['status'] !== 'generating') {
                return $page;
            }
        }
        return null;
    }
}
