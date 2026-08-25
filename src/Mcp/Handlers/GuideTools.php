<?php
declare(strict_types=1);

namespace CourseForge\Mcp\Handlers;

use CourseForge\Mcp\Args;
use CourseForge\Mcp\Guide;
use CourseForge\Mcp\Schema;
use CourseForge\Mcp\Scopes;
use CourseForge\Mcp\Tool;
use CourseForge\Security\Actor;

/**
 * The one tool a client can call when it does not know what to do.
 *
 * Everything needed to build a course without CourseForge having an AI account
 * of its own was already here - briefs out, finished text back - but the order
 * of it was not written down anywhere a client could read. `get_next_step`
 * closes that: it looks at what is actually in the database and says which tool
 * to call next, why, and what "finished" will look like.
 *
 * It is deliberately in the Courses group rather than being exempt from scope
 * narrowing. It reports how many courses exist and what they are called, which
 * is exactly what `list_courses` reports, so a connection that was not trusted
 * with `list_courses` must not be handed the same inventory through a side
 * door. The single exemption in this surface is `whoami`, and it stays single.
 */
final class GuideTools
{
    /** @return array<int,Tool> */
    public static function tools(): array
    {
        return [
            new Tool(
                name: 'get_next_step',
                scope: Scopes::COURSES,
                title: 'What to do next',
                description: 'Where a course has got to and the single next tool call that moves it forward, with '
                    . 'the reason and the arguments. Call it with no course_id to be pointed at the right course, '
                    . 'or with one to be told the next step for that course. Start here if you do not know the '
                    . 'sequence, and come back to it whenever you lose track: the answer is worked out from the '
                    . 'database every time, so it is correct even after a restart, and it never depends on '
                    . 'remembering anything. The whole sequence runs without an AI account configured in '
                    . 'CourseForge - you do the writing. Costs nothing.',
                properties: [
                    'course_id' => Schema::int('The course to report on. Omit to be pointed at one.'),
                ],
                required: [],
                handler: static fn(Actor $actor, array $args): array
                    => Guide::nextStep($actor, Args::of($args)->intOrNull('course_id')),
                readOnly: true,
                idempotent: true,
            ),
        ];
    }
}
