<?php
declare(strict_types=1);

namespace CourseForge\Mcp;

use CourseForge\Domain\Details;
use CourseForge\Domain\Projects;
use CourseForge\Domain\Research;
use CourseForge\Mcp\Handlers\PublishTools;
use CourseForge\Security\Access;
use CourseForge\Security\Actor;

/**
 * Where a course has got to, and the one call that moves it forward.
 *
 * CourseForge can write a whole course without an AI account of its own: it
 * hands the client the same brief it would have sent a model, the client writes
 * the text, and CourseForge stores it. That has been true since 4.0. What was
 * missing was any way for the client to find out *where in that job it is*.
 *
 * The sequence - create the course, design the outline, apply it, write each
 * page in turn, publish - had to be known in advance. A client that knew it
 * could drive the whole thing; a client that did not had no way to learn it,
 * and an operator had to explain it in prose every time. Worse, a client that
 * stopped half way had nothing to ask on the way back in.
 *
 * So the state is computed from the database on every call, never remembered.
 * That is the whole trick, and it is what makes the flow survive a client that
 * forgets, restarts, loses its context window or is replaced by a different
 * client entirely. There is no handle to lose and nothing to resume, because
 * nothing was ever held: the pages that have text are the progress.
 *
 * Two rules this file keeps:
 *
 *   - **Never name a tool this connection cannot call.** Advice that ends in
 *     "there is no tool called that on this connection" is worse than no
 *     advice, because the client cannot tell whether it was given bad advice or
 *     made a bad call. Scopes::holds() is asked before any tool is suggested.
 *
 *   - **Always say what finished looks like.** A model that cannot tell it is
 *     done either stops early and claims success or loops forever. `done` is a
 *     boolean, and the summary says so in words as well.
 */
final class Guide
{
    /** No course has been made yet, or none was named and none exists. */
    public const STATE_NO_COURSES = 'no_courses';

    /** Several courses exist and the call did not say which. */
    public const STATE_CHOOSE_COURSE = 'choose_course';

    /** The course exists but has no chapters or pages yet. */
    /**
     * The course asks for web research and has none, and nothing is written yet.
     *
     * Before the outline rather than after it, because the outline is the
     * decision every page inherits: a chapter list designed around a version of
     * the subject that no longer exists cannot be repaired by researching the
     * pages underneath it. Only while the course is still empty - once pages
     * have text on them, stopping to research is not the next step, and the
     * research tools are there to be called directly.
     */
    public const STATE_NEEDS_RESEARCH = 'needs_research';

    public const STATE_NEEDS_OUTLINE = 'needs_outline';

    /** The outline is applied and some pages have no text. */
    public const STATE_WRITING = 'writing';

    /** Every page is written and a BookStack destination is set. */
    public const STATE_READY_TO_PUBLISH = 'ready_to_publish';

    /** Every page is written, and there is nowhere to publish to. */
    public const STATE_WRITTEN = 'written';

    /** Written and published, with nothing changed since. */
    public const STATE_DONE = 'done';

    /**
     * The next step for a course, or for the account when none is named.
     *
     * @return array<string,mixed>
     */
    public static function nextStep(Actor $actor, ?int $courseId): array
    {
        if ($courseId !== null) {
            return self::forCourse($actor, $courseId);
        }

        $courses = Projects::all(Access::listingOwner($actor, ''));

        if ($courses === []) {
            return self::step(
                self::STATE_NO_COURSES,
                'There are no courses yet. Making one is the first step.',
                'create_course',
                Scopes::COURSES,
                ['name' => 'A short name for the course', 'topic' => 'One sentence: what it teaches, to whom, and to what level'],
                'create_course takes a name and a topic. The topic is the brief the outline gets designed from, so '
                    . 'it is worth a full sentence rather than a keyword - name the subject, the audience and the '
                    . 'level. Ask the person you are working for if you do not already know.',
                ['pages' => 0, 'written' => 0, 'published' => 0, 'remaining' => 0],
                null,
                'Then call get_next_step again with the course_id it gives you.'
            );
        }

        if (count($courses) === 1) {
            return self::forCourse($actor, (int)$courses[0]['id']);
        }

        $list = [];
        foreach ($courses as $course) {
            $row = [
                'course_id' => (int)$course['id'],
                'name' => (string)$course['name'],
                'pages' => (int)$course['page_count'],
                'written' => (int)$course['generated_count'],
            ];
            // An administrator sees everybody's courses, and two people can
            // easily name one the same thing. list_courses marks the owner for
            // exactly this reason; a list you are being asked to choose from
            // needs it more, not less.
            if ($actor->isAdmin()) {
                $row['owner'] = (string)$course['owner'];
            }
            $list[] = $row;
        }

        return self::step(
            self::STATE_CHOOSE_COURSE,
            'There are ' . count($courses) . ' courses. Say which one to work on.',
            'get_next_step',
            Scopes::COURSES,
            ['course_id' => 'The id of the course to work on'],
            'Call get_next_step again with one of the course_ids below. If you are working for somebody, show them '
                . 'this list and let them pick rather than guessing.',
            ['pages' => 0, 'written' => 0, 'published' => 0, 'remaining' => 0],
            null,
            null,
            ['courses' => $list]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function forCourse(Actor $actor, int $courseId): array
    {
        $project = Access::project($actor, $courseId);
        $owner = (string)$project['username'];
        $name = (string)$project['name'];

        $row = null;
        foreach (Projects::all($owner) as $candidate) {
            if ((int)$candidate['id'] === $courseId) {
                $row = $candidate;
                break;
            }
        }

        $pages = (int)($row['page_count'] ?? 0);
        $written = (int)($row['generated_count'] ?? 0);
        $published = (int)($row['pushed_count'] ?? 0);
        $remaining = max(0, $pages - $written);
        $progress = [
            'pages' => $pages,
            'written' => $written,
            'published' => $published,
            'remaining' => $remaining,
        ];
        $course = ['course_id' => $courseId, 'name' => $name];

        /* ------------------------------------------------------- no research */

        if ($pages === 0
            && !Research::has($project)
            && (Details::resolve(...Projects::chain($project))['features']['web_research'] ?? false)
        ) {
            return self::step(
                self::STATE_NEEDS_RESEARCH,
                '"' . $name . '" asks for web research and has none stored. The outline is designed from it, so '
                    . 'this comes first.',
                'get_research_brief',
                Scopes::RESEARCH,
                ['course_id' => $courseId],
                'get_research_brief hands you the assignment for the whole subject: the current stable version and '
                    . 'when it shipped, what changed recently enough that a model would get it wrong, what has been '
                    . 'deprecated and what replaced it, and where the documentation now lives. Search the web for '
                    . 'it with your own tools - which costs nothing here, because you are the one searching - and '
                    . 'send what you find to store_research. It is established once and then read by the outline '
                    . 'and by every single page.',
                $progress,
                $course,
                'Then store_research with what you found, and call get_next_step again.'
            );
        }

        /* ------------------------------------------------------- no outline */

        if ($pages === 0 && (int)($row['chapter_count'] ?? 0) > 0) {
            // An outline that names chapters but no pages is accepted and
            // stored - applyStructure warns about it at the time. Calling that
            // "no outline yet" contradicts get_structure on the same course,
            // and sends the client to write one it already has.
            return self::step(
                self::STATE_NEEDS_OUTLINE,
                '"' . $name . '" has an outline, but it names no pages - only '
                    . (int)$row['chapter_count'] . ' chapter'
                    . ((int)$row['chapter_count'] === 1 ? '' : 's') . '. There is nothing to write until it does.',
                'get_structure_brief',
                Scopes::STRUCTURE,
                ['course_id' => $courseId],
                'Read the current outline with get_structure first - it is stored and it is not empty, it just has '
                    . 'no pages under its chapters. get_structure_brief hands you the format and the rules; add '
                    . 'pages to the chapters and store it with apply_structure.',
                $progress,
                $course,
                'Then call get_next_step again.'
            );
        }

        if ($pages === 0) {
            return self::step(
                self::STATE_NEEDS_OUTLINE,
                '"' . $name . '" has no outline yet. Everything else waits on it.',
                'get_structure_brief',
                Scopes::STRUCTURE,
                ['course_id' => $courseId],
                'get_structure_brief hands you the same instructions CourseForge would have sent a model - the '
                    . 'persona, the format the outline has to be in, the course topic and the tagging rules. Design '
                    . 'the outline yourself from that brief, then store it with apply_structure. No model is called '
                    . 'and nothing is spent: you are the one writing it.',
                $progress,
                $course,
                'Then apply_structure with the outline you wrote. After that, call get_next_step again.'
            );
        }

        /* ---------------------------------------------------- pages to write */

        if ($remaining > 0) {
            return self::step(
                self::STATE_WRITING,
                '"' . $name . '": ' . $written . ' of ' . $pages . ' pages written, ' . $remaining . ' to go.',
                'get_page_brief',
                Scopes::PAGES,
                ['course_id' => $courseId],
                'get_page_brief with no page_id hands you the brief for the next page that has no text yet: the '
                    . 'formatting contract, the content details resolved for that page, where it sits in the course '
                    . 'and what the pages either side of it cover. Write that page from the brief, send it back with '
                    . 'write_page, then call get_page_brief again for the next one. Repeat until this tool says '
                    . 'there are none left. Nothing is spent: you are doing the writing.',
                $progress,
                $course,
                'Loop get_page_brief then write_page ' . $remaining . ' more '
                    . ($remaining === 1 ? 'time' : 'times') . '. You do not need to call get_next_step between '
                    . 'pages - come back to it when get_page_brief reports nothing left.'
            );
        }

        /* -------------------------------------------------- ready to publish */

        // Asked of the code that actually publishes rather than worked out
        // again here. The two rules agreed when this was written, which is how
        // every pair of duplicated rules starts.
        $blocking = PublishTools::blockingReasons($project, $owner);

        if ($blocking !== []) {
            return self::step(
                self::STATE_WRITTEN,
                '"' . $name . '" is fully written: all ' . $pages . ' pages have text. '
                    . 'There is no BookStack destination set, so there is nothing left to do.',
                null,
                null,
                [],
                'The course is finished. Publishing is optional, and this course cannot publish yet: '
                    . implode(' ', $blocking)
                    . ' If you do not intend to publish into BookStack, the work is done.',
                $progress,
                $course,
                null,
                [],
                true
            );
        }

        if ($published >= $pages) {
            return self::step(
                self::STATE_DONE,
                '"' . $name . '" is written, and every page is in BookStack.',
                null,
                null,
                [],
                'Every page has text and every page has been pushed. Publishing again is safe but would change '
                    . 'nothing unless a page has been edited since. There is nothing left to do for this course.',
                $progress,
                $course,
                null,
                [],
                true
            );
        }

        return self::step(
            self::STATE_READY_TO_PUBLISH,
            '"' . $name . '" is fully written. ' . ($pages - $published) . ' of ' . $pages
                . ' pages are not in BookStack yet.',
            'publish_course',
            Scopes::PUBLISH,
            ['course_id' => $courseId],
            'publish_course pushes the book, its chapters and every written page into the BookStack instance on the '
                . 'course\'s profile. Existing items are updated in place rather than duplicated, so running it '
                . 'twice is safe.',
            $progress,
            $course,
            'After it finishes, the course is done.'
        );
    }

    /**
     * One step, with the advice suppressed when the connection could not act on it.
     *
     * @param array<string,mixed> $arguments
     * @param array<string,int> $progress
     * @param array<string,mixed>|null $course
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private static function step(
        string $state,
        string $summary,
        ?string $tool,
        ?string $scope,
        array $arguments,
        string $why,
        array $progress,
        ?array $course,
        ?string $then = null,
        array $extra = [],
        bool $done = false
    ): array {
        $out = [
            'state' => $state,
            'done' => $done,
            'summary' => $summary,
            'progress' => $progress,
        ];

        if ($course !== null) {
            $out['course'] = $course;
        }

        if ($tool !== null && $scope !== null && !Scopes::holds($scope)) {
            // Saying "call publish_course" to a connection without the
            // publishing group sends the client at a tool that will tell it no
            // such tool exists, and it has no way to tell that apart from its
            // own mistake. Name the group instead - that is something a person
            // can act on, by issuing a connection that has it.
            $out['next'] = null;
            $out['blocked'] = [
                'needs_scope' => $scope,
                'why' => 'The next step is in the "' . $scope . '" group, which this connection was not given. '
                    . 'Nothing further can be done over this connection. Create one that includes "' . $scope
                    . '" under Connect, or do this step in the browser.',
            ];
            return $out + $extra;
        }

        $out['next'] = $tool === null ? null : [
            'tool' => $tool,
            'arguments' => $arguments === [] ? new \stdClass() : $arguments,
            'why' => $why,
        ];

        if ($tool === null) {
            $out['why'] = $why;
        }
        if ($then !== null) {
            $out['then'] = $then;
        }

        return $out + $extra;
    }
}
