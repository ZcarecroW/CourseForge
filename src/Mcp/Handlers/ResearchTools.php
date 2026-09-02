<?php
declare(strict_types=1);

namespace CourseForge\Mcp\Handlers;

use CourseForge\Domain\Details;
use CourseForge\Domain\Projects;
use CourseForge\Domain\Research;
use CourseForge\Mcp\Args;
use CourseForge\Mcp\Resolve;
use CourseForge\Mcp\Schema;
use CourseForge\Mcp\Scopes;
use CourseForge\Mcp\Tool;
use CourseForge\Security\Actor;
use CourseForge\Support\Audit;
use CourseForge\Support\HttpException;

/**
 * Finding out what is true today, once, before the course is designed.
 *
 * This is the same inversion the rest of the surface is built on - CourseForge
 * hands out the assignment, the client does the work, CourseForge stores the
 * answer - applied to the one job it was missing. `get_page_brief` could
 * already say "research this page first", and a client with a search tool would
 * do it. But that is per page: a two-hundred-page WordPress course researched
 * that way searches the same handful of facts two hundred times, and gets two
 * hundred slightly different answers to "which version are we on".
 *
 * A course-level briefing is researched once and read by everything after it:
 * the outline is designed against it, and every page brief carries it. Three
 * tools, in the order they are used:
 *
 *   - `get_research_brief` hands over the assignment. No model is called and
 *     nothing is spent. For a client with a web search tool - Claude Code is
 *     the obvious one, and searches on its own subscription rather than on
 *     anybody's API credit - this is the whole cost of keeping a course current.
 *   - `store_research` takes the findings back and stamps them with the date.
 *   - `get_research` reads them back, with their age, so a client that finds a
 *     six-month-old briefing can decide to refresh it.
 *
 * The date is not decoration. Findings are stored precisely because they go
 * stale, so every answer here says how old they are, and the block that reaches
 * a prompt says when it was true. Nothing expires on its own: an old briefing
 * still beats a model's recollection, and throwing one away is a decision for
 * whoever can see how fast the subject moves.
 *
 * Who did the research is recorded and never acted on. A client over MCP, the
 * Claude Code CLI provider running on somebody's subscription, a provider with
 * a server-side search tool, and a person pasting in what they already know all
 * write the same column, and everything downstream reads it the same way.
 */
final class ResearchTools
{
    /** @return array<int,Tool> */
    public static function tools(): array
    {
        return [
            new Tool(
                name: 'get_research_brief',
                scope: Scopes::RESEARCH,
                title: 'Get the assignment for researching a course',
                description: 'The research assignment for a whole course: what to establish about the subject '
                    . 'before any of it is designed or written - the current stable version and its release date, '
                    . 'what changed recently enough that a model would get it wrong, what has been deprecated and '
                    . 'what replaced it, where the official documentation now lives, and what practitioners '
                    . 'currently recommend. Search the web from this brief with your own tools, then send what you '
                    . 'found to store_research. It is stored once on the course and then read by the outline and '
                    . 'by every single page, so a course about something that moves - WordPress, a framework, an '
                    . 'API, a standard - is researched one time rather than once per page. Costs nothing: no model '
                    . 'is called and no search is run on this server.',
                properties: [
                    'course_id' => Schema::courseId(),
                ],
                required: ['course_id'],
                handler: static fn(Actor $actor, array $args): array => self::researchBrief($actor, Args::of($args)),
                readOnly: true,
                idempotent: true,
            ),

            new Tool(
                name: 'store_research',
                scope: Scopes::RESEARCH,
                title: 'Store what the research found',
                description: 'Stores the findings from get_research_brief on the course and stamps them with '
                    . 'today\'s date. From then on they travel into the outline brief and into every page brief, '
                    . 'and the model is told when they were established so it can say which version a statement '
                    . 'holds for. Send Markdown: short sections, dense bullets, and a closing "## Sources" list of '
                    . 'what you actually read. Sending an empty string clears the findings. Replaces whatever was '
                    . 'stored before - to add to a briefing rather than replace it, read the old one with '
                    . 'get_research first and send both.',
                properties: [
                    'course_id' => Schema::courseId(),
                    'findings' => Schema::text(
                        'The research findings as Markdown. Anything past '
                        . number_format(Research::MAX_CHARS) . ' characters is cut at a line boundary, because '
                        . 'this text is carried in the context of every page of the course and is therefore paid '
                        . 'for once per page. Send an empty string to clear.'
                    ),
                ],
                required: ['course_id', 'findings'],
                handler: static fn(Actor $actor, array $args): array => self::storeResearch($actor, Args::of($args)),
                idempotent: true,
            ),

            new Tool(
                name: 'get_research',
                scope: Scopes::RESEARCH,
                title: 'Read the stored research',
                description: 'The research findings stored on a course, with the date they were established and '
                    . 'how old that makes them. Nothing here ever expires by itself - old findings still beat a '
                    . 'guess - so this is what a client reads to decide whether the subject has moved far enough '
                    . 'to be worth researching again. Costs nothing.',
                properties: [
                    'course_id' => Schema::courseId(),
                ],
                required: ['course_id'],
                handler: static fn(Actor $actor, array $args): array => self::getResearch($actor, Args::of($args)),
                readOnly: true,
                idempotent: true,
            ),
        ];
    }

    /* ------------------------------------------------------------- handlers */

    /** @return array<string,mixed> */
    private static function researchBrief(Actor $actor, Args $args): array
    {
        ['project' => $project] = Resolve::course($actor, $args->id());

        // No profile is resolved and no model is touched: an assignment is
        // instructions, the same as get_structure_brief and get_page_brief.
        $params = Details::resolve(...Projects::chain($project))['params'];
        $searches = Research::searchBudget($params);
        $existing = Research::of($project);

        return [
            'course_id' => (int)$project['id'],
            'course_name' => (string)$project['name'],
            'topic' => (string)$project['topic'],
            'max_searches' => $searches,
            'research_brief' => Research::assignment($project, $searches),
            'existing_research' => $existing,
            'existing_freshness' => Research::freshness($project),
            'max_characters' => Research::MAX_CHARS,
            'next_step' => ($existing !== ''
                    ? 'This course already has findings, ' . Research::freshness($project)
                        . '. Read them above: if the subject has not moved, there is nothing to do here. If it has, '
                        . 'research again and send the replacement. '
                    : '')
                . 'Search the web from the brief above with your own tools, then call store_research with '
                . 'course_id ' . (int)$project['id'] . '. After that, get_structure_brief designs the outline '
                . 'against what you found.',
            'next_tool' => 'store_research',
        ];
    }

    /** @return array<string,mixed> */
    private static function storeResearch(Actor $actor, Args $args): array
    {
        ['project' => $project, 'owner' => $owner] = Resolve::course($actor, $args->id());

        // requiredRaw, not requiredStr: the findings are Markdown with line
        // breaks in them and must not be flattened on the way in. An empty
        // string is a deliberate clear, so it is allowed through where a
        // required argument would normally refuse it.
        $findings = $args->has('findings') ? $args->raw('findings') : null;
        if ($findings === null) {
            throw HttpException::unprocessable(
                'store_research needs a "findings" argument. Send the Markdown you want stored, or an empty '
                . 'string to clear what is there.'
            );
        }

        $result = Research::store($owner, (int)$project['id'], $findings, Research::SOURCE_CLIENT);
        Audit::record(
            $actor->username,
            $result['stored'] ? 'course.research' : 'course.research.clear',
            (string)$project['name'],
            $result['stored'] ? $result['characters'] . ' characters' : 'cleared',
            'mcp'
        );

        if (!$result['stored']) {
            return [
                'course_id' => (int)$project['id'],
                'stored' => false,
                'cleared' => true,
                'next_step' => 'The findings were cleared. Nothing carries research into the briefs any more.',
            ];
        }

        return [
            'course_id' => (int)$project['id'],
            'stored' => true,
            'characters' => $result['characters'],
            'truncated' => $result['truncated'],
            'freshness' => 'stored today',
            'next_step' => ($result['truncated']
                    ? 'The findings were longer than ' . number_format(Research::MAX_CHARS)
                        . ' characters and were cut at a line boundary, because this text is carried by every page '
                        . 'of the course. Send a shorter briefing if the part that was cut mattered. '
                    : '')
                . 'They now travel into the outline brief and into every page brief. Call get_structure_brief to '
                . 'design the outline against them.',
            'next_tool' => 'get_structure_brief',
        ];
    }

    /** @return array<string,mixed> */
    private static function getResearch(Actor $actor, Args $args): array
    {
        ['project' => $project] = Resolve::course($actor, $args->id());

        return [
            'course_id' => (int)$project['id'],
            'course_name' => (string)$project['name'],
            'has_research' => Research::has($project),
            'research' => Research::of($project),
            'freshness' => Research::freshness($project),
            'researched_at' => Research::at($project),
            'age_in_days' => Research::ageInDays($project),
            'source' => (string)($project['research_source'] ?? ''),
            'next_step' => Research::has($project)
                ? 'Nothing expires on its own. If the subject has moved since then, call get_research_brief and '
                    . 'research it again; store_research replaces what is here.'
                : 'This course has no research stored. get_research_brief hands over the assignment.',
        ];
    }
}
