<?php
declare(strict_types=1);

namespace CourseForge\Mcp\Handlers;

use CourseForge\Ai\Completion;
use CourseForge\Ai\Prompt;
use CourseForge\Ai\StructureGenerator;
use CourseForge\Domain\Chapters;
use CourseForge\Domain\Details;
use CourseForge\Domain\Pages;
use CourseForge\Domain\Projects;
use CourseForge\Domain\Structure;
use CourseForge\Mcp\Args;
use CourseForge\Mcp\Resolve;
use CourseForge\Mcp\Schema;
use CourseForge\Mcp\Scopes;
use CourseForge\Mcp\Tool;
use CourseForge\Security\Actor;
use CourseForge\Support\Audit;
use CourseForge\Support\Config;
use CourseForge\Support\HttpException;
use CourseForge\Support\Runtime;
use CourseForge\Support\Text;

/**
 * The outline: the one document every page of a course is written from.
 *
 * A course is a list of chapter and page titles before it is anything else.
 * The outline decides how many pages there will be, what each of them is
 * allowed to cover, and - because chapters and pages are matched by title -
 * which of them keep the text they already have. Getting it right first and
 * changing it rarely is what makes a fifty-chapter course coherent instead of
 * fifty articles that overlap.
 *
 * There are two ways to produce one, the same split as with pages. The cheap
 * way is `get_structure_brief`: CourseForge hands the client the exact
 * instruction set it would have sent its own model - the persona, the format
 * contract, the topic, the current outline, the requested changes and the
 * tagging rules - the client designs the outline, and `apply_structure` stores
 * it. Nothing is spent and no credential is needed. The other way is
 * `generate_structure`, which has CourseForge call the course's own model and
 * apply the answer straight away; it costs money and it is the right call when
 * the client is not the thing that should be doing the designing.
 *
 * `preview_structure` sits in front of both write paths and needs no course at
 * all. Applying an outline deletes every page the outline leaves out, so a
 * client that has assembled Markdown by hand should check what CourseForge
 * reads out of it before finding out the destructive way.
 *
 * Neither write path will delete written text without being told to. Both work
 * out which pages an outline would remove before they remove anything, and both
 * refuse if any of those pages has text on it and `confirm_removals` is not
 * true. An outline that only adds and renames still applies in one call, which
 * is the ordinary case; the argument exists for the case that is not ordinary,
 * where a regenerated outline would take fifty written pages with it and there
 * is no undo.
 */
final class StructureTools
{
    /**
     * The shape Structure::parse() understands.
     *
     * Restated here rather than left to the prompt library, because a profile
     * may have blanked or rewritten the slot that carries it and the parser's
     * contract does not change when the prompt does.
     */
    private const FORMAT = <<<'MD'
        # Book Title

        A description of the book, roughly 200-400 characters, as plain prose.

        1. Chapter Title
           Chapter description, roughly 300 characters, indented by three spaces and without a list marker.
           1. Page Title
           2. Another Page Title
        2. Next Chapter Title
           Chapter description again
           1. First page of the second chapter
        MD;

    /** @return array<int,Tool> */
    public static function tools(): array
    {
        return [
            new Tool(
                name: 'get_structure_brief',
                scope: Scopes::STRUCTURE,
                title: 'Get the brief for designing an outline',
                description: 'The complete brief for designing a course outline, or for revising the one that already '
                    . 'exists: the system instructions and user message CourseForge would send its own model, the '
                    . 'course topic, the current outline, the requested changes, and the exact Markdown format that '
                    . 'apply_structure parses. Pass feedback to be given the revision brief instead of the design '
                    . 'brief - a revision must reproduce every unaffected title character for character, because '
                    . 'pages are matched by title. Design the outline from this brief and send it to apply_structure. '
                    . 'Costs nothing: no model is called.',
                properties: [
                    'course_id' => Schema::courseId(),
                    'feedback' => Schema::text(
                        'The changes you want made to the existing outline. Omit to design one from scratch.'
                    ),
                ],
                required: ['course_id'],
                handler: static fn(Actor $actor, array $args): array => self::structureBrief($actor, Args::of($args)),
                readOnly: true,
                idempotent: true,
            ),

            new Tool(
                name: 'apply_structure',
                scope: Scopes::STRUCTURE,
                title: 'Replace the outline',
                description: 'Replaces a course\'s outline with the Markdown you give it. Chapters and pages are '
                    . 'matched by title: a page whose title is unchanged keeps its text and its details, a page with '
                    . 'a new title starts empty, and a page that is no longer in the outline is deleted along with '
                    . 'everything written on it. Always send the complete outline - anything left out is removed. '
                    . 'Run preview_structure first when the Markdown did not come from get_structure_brief. If the '
                    . 'outline would remove a page that has text on it, this refuses and names those pages unless '
                    . 'confirm_removals is true, so an outline that only adds and renames applies in one call. '
                    . 'Returns what was added, what was kept and what was removed. Costs nothing.',
                properties: [
                    'course_id' => Schema::courseId(),
                    'structure' => Schema::text(
                        'The complete outline in CourseForge\'s Markdown format, as described by get_structure_brief.'
                    ),
                    'confirm_removals' => Schema::bool(
                        'Set true only when you mean to delete written pages that this outline leaves out. Without '
                        . 'it the call is refused and the pages at stake are named.'
                    ),
                ],
                required: ['course_id', 'structure'],
                handler: static fn(Actor $actor, array $args): array => self::applyStructure($actor, Args::of($args)),
                destructive: true,
                idempotent: true,
            ),

            new Tool(
                name: 'generate_structure',
                scope: Scopes::STRUCTURE,
                title: 'Have CourseForge design the outline',
                description: 'CourseForge calls the course\'s own model and designs a complete outline. Existing '
                    . 'chapters and pages are matched by title, so a new outline over a course that has already been '
                    . 'written would delete every page it does not name. When it would take a page that has text on '
                    . 'it, the outline is handed back for you to look at instead of being applied, and applying it '
                    . 'then costs nothing - so the model is never called twice over the same decision. Pass '
                    . 'confirm_removals to have it applied whatever it removes. Pass feedback to revise the existing '
                    . 'outline rather than design a new one. It blocks while the model works, which on a large '
                    . 'course takes a minute or two. To design an outline without spending anything at all, use '
                    . 'get_structure_brief and apply_structure instead.',
                properties: [
                    'course_id' => Schema::courseId(),
                    'feedback' => Schema::text(
                        'The changes you want made to the existing outline. Omit to design one from scratch.'
                    ),
                    'confirm_removals' => Schema::bool(
                        'Set true only when you mean to delete written pages the new outline leaves out. Without it '
                        . 'the designed outline is returned unapplied and the pages at stake are named.'
                    ),
                ],
                required: ['course_id'],
                handler: static fn(Actor $actor, array $args): array => self::generateStructure($actor, Args::of($args)),
                destructive: true,
                spends: true,
            ),

            new Tool(
                name: 'get_structure',
                scope: Scopes::STRUCTURE,
                title: 'Read the outline',
                description: 'The stored outline of a course exactly as it is, plus what CourseForge reads out of it: '
                    . 'the book title and description, how many chapters and pages there are, and every title. Read '
                    . 'this before revising an outline, so that the titles you mean to keep stay byte-identical and '
                    . 'their pages keep their text. Costs nothing.',
                properties: [
                    'course_id' => Schema::courseId(),
                ],
                required: ['course_id'],
                handler: static fn(Actor $actor, array $args): array => self::getStructure($actor, Args::of($args)),
                readOnly: true,
                idempotent: true,
            ),

            new Tool(
                name: 'preview_structure',
                scope: Scopes::STRUCTURE,
                title: 'Check outline Markdown before applying it',
                description: 'Parses outline Markdown and reports what CourseForge understood from it - the book '
                    . 'title, the description, every chapter with its pages, and any {{Tag}} markers - without '
                    . 'touching any course. It takes no course_id and changes nothing. Use it whenever an outline was '
                    . 'assembled by hand, because apply_structure deletes every page the outline leaves out and there '
                    . 'is no undo. Reports duplicate titles, chapters with no pages and anything the parser could not '
                    . 'read. Costs nothing.',
                properties: [
                    'structure' => Schema::text('The outline Markdown to check.'),
                ],
                required: ['structure'],
                handler: static fn(Actor $actor, array $args): array => self::previewStructure(Args::of($args)),
                readOnly: true,
                idempotent: true,
            ),
        ];
    }

    /* ------------------------------------------------------------- handlers */

    /** @return array<string,mixed> */
    private static function structureBrief(Actor $actor, Args $args): array
    {
        ['project' => $project] = Resolve::course($actor, $args->id());
        // The raw profile carries the provider key. It is read here for the
        // prompt library and the output language, and never leaves this method.
        $profile = Resolve::profile($project);

        $feedback = trim($args->raw('feedback'));
        $brief = self::brief($profile, $project, $feedback);
        $autoTags = (int)$project['auto_tags'] === 1;

        return [
            'course_id' => (int)$project['id'],
            'course_name' => (string)$project['name'],
            'mode' => $brief['mode'],
            'topic' => (string)$project['topic'],
            'language' => (string)$brief['vars']['language'],
            'current_structure' => trim((string)$project['structure_md']),
            'requested_changes' => $feedback,
            'system_instructions' => $brief['system'],
            'design_brief' => $brief['user'],
            'required_format' => self::FORMAT,
            'format_rules' => self::formatRules($autoTags),
            'auto_tagging' => $autoTags,
            'next_step' => 'Write the complete outline in the required format, then call apply_structure with '
                . 'course_id ' . (int)$project['id'] . '. preview_structure checks the Markdown first without '
                . 'changing anything.',
        ];
    }

    /** @return array<string,mixed> */
    private static function applyStructure(Actor $actor, Args $args): array
    {
        ['project' => $project, 'owner' => $owner] = Resolve::course($actor, $args->id());
        $markdown = $args->requiredRaw('structure');

        // Projects::applyStructure refuses an unparsable outline too, but it
        // cannot say what the client should do about it.
        if (Structure::parse($markdown)['chapters'] === []) {
            throw HttpException::unprocessable(
                'No chapters could be read out of that Markdown, so nothing was changed. Chapters are a top-level '
                . 'ordered list written as "1. Chapter Title", pages a nested ordered list indented by three spaces. '
                . 'Call preview_structure with the same text to see exactly what the parser read.'
            );
        }

        // Worked out before anything is written, because afterwards the only
        // honest thing left to say is which pages have already been lost.
        $atRisk = self::wouldLose($project, $markdown);
        if ($atRisk !== [] && !$args->bool('confirm_removals')) {
            throw HttpException::unprocessable(self::removalRefusal($atRisk));
        }

        $before = self::snapshot($project);
        Projects::applyStructure($project, $markdown);
        $diff = self::diff($before, self::snapshot($project));

        Audit::record(
            $actor->username,
            'structure.apply',
            (string)$project['name'],
            'removed ' . count($diff['removed']['pages']) . ' pages, '
                . count($diff['removed']['chapters']) . ' chapters via MCP',
            'mcp'
        );

        return self::applied($project, $owner, $diff, 'apply_structure');
    }

    /** @return array<string,mixed> */
    private static function generateStructure(Actor $actor, Args $args): array
    {
        ['project' => $project, 'owner' => $owner] = Resolve::course($actor, $args->id());
        $profile = Resolve::profile($project);

        if (trim((string)$project['topic']) === '' && trim((string)$project['structure_md']) === '') {
            throw HttpException::unprocessable(
                'Course "' . $project['name'] . '" has no topic, so there is nothing to design an outline from. Set '
                . 'one with update_course - one or two sentences saying what the course covers and for whom.'
            );
        }

        // The model will hold this request open for a minute or more. Releasing
        // the session lock and the time limit stops it from blocking every
        // other request the same account has in flight.
        Runtime::beginLongRequest();

        $markdown = StructureGenerator::run($profile, $project, $args->raw('feedback'));

        // The model has already been paid for at this point, so a refusal hands
        // back what it designed rather than throwing it away. Applying that
        // Markdown with apply_structure costs nothing, which means the decision
        // can be looked at without the answer having to be bought twice.
        $atRisk = self::wouldLose($project, $markdown);
        if ($atRisk !== [] && !$args->bool('confirm_removals')) {
            return [
                'applied' => false,
                'tool' => 'generate_structure',
                'course_id' => (int)$project['id'],
                'owner' => $owner,
                'structure' => trim($markdown),
                'would_remove_written_pages' => $atRisk,
                'reason' => self::removalRefusal($atRisk),
                'next_step' => 'Nothing has been changed. Read the outline above, then call apply_structure with it '
                    . 'and confirm_removals true, or revise it first - apply_structure costs nothing either way.',
            ];
        }

        $before = self::snapshot($project);
        Projects::applyStructure($project, $markdown);
        $diff = self::diff($before, self::snapshot($project));

        Audit::record(
            $actor->username,
            'structure.generate',
            (string)$project['name'],
            'removed ' . count($diff['removed']['pages']) . ' pages, '
                . count($diff['removed']['chapters']) . ' chapters via MCP',
            'mcp'
        );

        $result = self::applied($project, $owner, $diff, 'generate_structure');
        $result['structure'] = trim($markdown);
        return $result;
    }

    /** @return array<string,mixed> */
    private static function getStructure(Actor $actor, Args $args): array
    {
        ['project' => $project] = Resolve::course($actor, $args->id());
        $markdown = trim((string)$project['structure_md']);
        $courseId = (int)$project['id'];

        if ($markdown === '') {
            return [
                'course_id' => $courseId,
                'name' => (string)$project['name'],
                'has_outline' => false,
                'structure' => '',
                'next' => 'This course has no outline yet. Call generate_structure to have CourseForge design one, or '
                    . 'get_structure_brief to design it yourself.',
            ];
        }

        $parsed = Structure::parse($markdown);
        $chapters = [];
        $pageCount = 0;
        foreach ($parsed['chapters'] as $chapter) {
            $titles = array_map(static fn(array $p): string => $p['title'], $chapter['pages']);
            $pageCount += count($titles);
            $chapters[] = [
                'title' => $chapter['title'],
                'description' => $chapter['description'],
                'pages' => $titles,
            ];
        }

        // The stored Markdown and the stored rows are kept in step by
        // resyncStructure, so a disagreement means something wrote around it.
        $liveChapters = count(Chapters::ordered($courseId));
        $livePages = count(Pages::ordered($courseId));
        $inStep = $liveChapters === count($chapters) && $livePages === $pageCount;

        return [
            'course_id' => $courseId,
            'name' => (string)$project['name'],
            'has_outline' => true,
            'structure' => $markdown,
            'summary' => [
                'book_title' => $parsed['title'],
                'book_description' => $parsed['description'],
                'chapter_count' => count($chapters),
                'page_count' => $pageCount,
                'tags' => $parsed['tags'],
                'chapters' => $chapters,
            ],
            'matches_course' => $inStep,
            'note' => $inStep
                ? null
                : 'The stored outline describes ' . count($chapters) . ' chapters and ' . $pageCount . ' pages, but '
                    . 'the course holds ' . $liveChapters . ' chapters and ' . $livePages . ' pages. Read the course '
                    . 'itself with get_course before revising.',
            'next' => 'Call get_structure_brief with feedback to revise this outline, or apply_structure to replace '
                . 'it outright. Titles you want to keep must be reproduced exactly.',
        ];
    }

    /** @return array<string,mixed> */
    private static function previewStructure(Args $args): array
    {
        $markdown = $args->requiredRaw('structure');
        $parsed = Structure::parse($markdown);

        $chapters = [];
        $pageCount = 0;
        $tags = $parsed['tags'];
        $problems = [];
        $seenChapters = [];
        $seenPages = [];
        $emptyChapters = [];

        foreach ($parsed['chapters'] as $index => $chapter) {
            $key = mb_strtolower($chapter['title']);
            if (isset($seenChapters[$key])) {
                $problems[] = 'Two chapters are called "' . $chapter['title'] . '". Chapters are matched by title, so '
                    . 'the second one would be folded into the first and its description would win.';
            }
            $seenChapters[$key] = true;

            if ($chapter['pages'] === []) {
                $emptyChapters[] = $chapter['title'];
            }

            $pages = [];
            foreach ($chapter['pages'] as $page) {
                $pageKey = mb_strtolower($page['title']);
                if (isset($seenPages[$pageKey])) {
                    $problems[] = 'Two pages are called "' . $page['title'] . '". Page titles must be unique across '
                        . 'the whole course, because that is how existing text is matched to a page.';
                }
                $seenPages[$pageKey] = true;
                $pages[] = ['title' => $page['title'], 'tags' => $page['tags']];
                $tags = Text::mergeUnique($tags, $page['tags']);
                $pageCount++;
            }

            $tags = Text::mergeUnique($tags, $chapter['tags']);
            $chapters[] = [
                'number' => $index + 1,
                'title' => $chapter['title'],
                'description' => $chapter['description'],
                'tags' => $chapter['tags'],
                'pages' => $pages,
            ];
        }

        $canApply = $chapters !== [];
        if (!$canApply) {
            $problems[] = 'No chapters were found, so apply_structure would refuse this and change nothing. Chapters '
                . 'are a top-level ordered list written as "1. Chapter Title".';
        }
        if ($pageCount === 0 && $canApply) {
            $problems[] = 'No pages were found. Pages are a nested ordered list indented by three spaces below their '
                . 'chapter, written as "   1. Page Title". A course with no pages has nothing to write.';
        }
        if ($emptyChapters !== []) {
            $problems[] = 'These chapters have no pages: ' . implode(', ', $emptyChapters) . '. They would be created '
                . 'empty and would publish as an empty chapter.';
        }
        if ($parsed['title'] === 'Untitled course') {
            $problems[] = 'No "# " title line was read, so the book title fell back to "Untitled course". The first '
                . 'line of the outline must be "# " followed by the book title.';
        }
        if (trim($parsed['description']) === '') {
            $problems[] = 'There is no book description. It is the plain paragraph directly below the "# " title and '
                . 'it is published as the book\'s description.';
        }

        return [
            'can_apply' => $canApply,
            'book_title' => $parsed['title'],
            'book_description' => $parsed['description'],
            'book_tags' => $parsed['tags'],
            'chapter_count' => count($chapters),
            'page_count' => $pageCount,
            'chapters' => $chapters,
            'tags_found' => $tags,
            'problems' => $problems,
            'next_step' => $canApply
                ? 'If this is the course you meant, call apply_structure with the same Markdown and a course_id. '
                    . 'Every page the outline does not name is deleted, text included.'
                : 'Fix the problems above and call preview_structure again. Nothing has been changed.',
        ];
    }

    /* -------------------------------------------------------------- helpers */

    /**
     * The instruction set StructureGenerator would have sent for this course.
     *
     * This mirrors StructureGenerator::run() slot for slot on purpose. A course
     * that is half designed by the client and half by the server has to be
     * designed to one set of rules, so the brief handed out here is the same
     * text the server would have used - the persona, the format contract, the
     * audience block and, when the course tags itself, the tagging syntax.
     *
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $project
     * @return array{mode:string,system:string,user:string,vars:array<string,scalar>}
     */
    private static function brief(array $profile, array $project, string $feedback): array
    {
        $library = Prompt::library($profile);
        $existing = trim((string)$project['structure_md']);
        $feedback = trim($feedback);
        $refine = $feedback !== '' && $existing !== '';

        $vars = self::vars($profile, $project) + [
            'current_structure' => $existing,
            'feedback' => $feedback,
        ];

        $systemSlot = $refine && trim($library['overview_refine_system'] ?? '') !== ''
            ? 'overview_refine_system'
            : 'overview_system';

        $system = Prompt::join(
            Prompt::slot($library, 'global_system', $vars),
            ((string)($vars['audience'] ?? '')) !== '' ? Prompt::slot($library, 'audience_block', $vars) : '',
            Prompt::slot($library, $systemSlot, $vars),
            (int)$project['auto_tags'] === 1 ? Prompt::slot($library, 'structure_tags_rules', $vars) : '',
            Prompt::slotOrDefault(
                $library,
                'language_instruction',
                $vars,
                'Write every title and description in {{language}}.'
            )
        );

        $user = $refine
            ? Prompt::slotOrDefault($library, 'overview_refine_user', $vars,
                "Course topic: {{topic}}\n\nCurrent structure:\n\n{{current_structure}}\n\n"
                . "Requested changes:\n{{feedback}}\n\n"
                . 'Return the complete, corrected structure in the exact required Markdown format. Language: {{language}}.')
            : Prompt::slotOrDefault($library, 'overview_user', $vars,
                "Design a complete course for the following request:\n\n{{topic}}\n\n"
                . 'Build a didactically sound outline in the exact required Markdown format. Language: {{language}}.');

        return [
            'mode' => $refine ? 'revise' : 'design',
            'system' => $system,
            'user' => $user,
            'vars' => $vars,
        ];
    }

    /**
     * The placeholder values every structure prompt is rendered with.
     *
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $project
     * @return array<string,scalar>
     */
    private static function vars(array $profile, array $project): array
    {
        $details = Details::resolve(Projects::settings($project));
        $pool = Text::splitList((string)$project['tag_pool']);
        $strictPool = (int)$project['tag_pool_strict'] === 1 && $pool !== [];

        return $details['params'] + [
            'language' => Completion::language($profile),
            'app_name' => Config::str('app.name', 'CourseForge'),
            'topic' => (string)$project['topic'],
            'book_title' => Projects::bookTitle($project),
            'book_description' => (string)$project['book_desc'],
            'extra_context' => '',
            'tag_pool' => $pool === []
                ? '(no predefined pool – choose consistent keywords yourself)'
                : implode(', ', $pool),
            'tag_policy' => $strictPool
                ? 'Use ONLY tags from that list. Never invent a tag of your own; if nothing fits, use fewer tags.'
                : ($pool === []
                    ? 'Choose short, reusable keywords yourself and reuse the very same tag across items that belong together.'
                    : 'Prefer the tags from that list and reuse them consistently, but you may add further fitting tags of your own where they add real value.'),
        ];
    }

    /** @return string[] */
    private static function formatRules(bool $autoTags): array
    {
        $rules = [
            'Exactly one level-1 heading: "# " followed by the book title. No other headings anywhere.',
            'The plain paragraph directly below the title is the book description, 200-400 characters.',
            'Chapters are a top-level ordered list at indentation 0, written as "1. Chapter Title".',
            'A chapter description sits directly below its chapter title, indented three spaces, as plain prose with '
                . 'no list marker.',
            'Pages are a nested ordered list indented three spaces, written as "   1. Page Title".',
            'A page entry holds the title and nothing else - no description, no colon, no dash, no prose.',
            'Titles are unique across the whole course. Chapters and pages are matched by title, so a duplicate '
                . 'cannot be matched to the text it already has.',
            'Return the complete outline every time. Anything left out is deleted, written text included.',
            'No bold, italics, code fences, HTML or commentary outside this structure.',
        ];

        if ($autoTags) {
            $rules[] = 'This course tags itself, so add keyword markers: "{{Tag one, Tag two}}" on the line below the '
                . '"# " title for the book, on the line below a chapter title and before its description for a '
                . 'chapter, and directly behind a page title on the same line for a page. Two to five short, reused '
                . 'keywords each, comma separated, nothing else inside the braces.';
        }

        return $rules;
    }

    /**
     * The written pages an outline would delete, worked out before it is applied.
     *
     * Applying an outline matches by lowercased title and deletes whatever is
     * left over, so a page survives exactly when the new outline still names
     * its title. Titles are counted rather than merely looked up, because two
     * outline entries are needed to keep two pages that share a name.
     *
     * Where a title is shared, the unwritten pages are offered the matching
     * places first, so that it is the written one that gets named here. Naming
     * a page that would in fact have survived costs one extra argument; missing
     * one that would not costs the text on it.
     *
     * @param array<string,mixed> $project
     * @return string[] the titles of pages that have text and would be removed
     */
    private static function wouldLose(array $project, string $markdown): array
    {
        $wanted = [];
        foreach (Structure::parse($markdown)['chapters'] as $chapter) {
            foreach ($chapter['pages'] as $page) {
                $key = mb_strtolower((string)$page['title']);
                $wanted[$key] = ($wanted[$key] ?? 0) + 1;
            }
        }

        $rows = self::snapshot($project)['pages'];
        uasort($rows, static fn(array $a, array $b): int => (int)$a['written'] <=> (int)$b['written']);

        $lost = [];
        foreach ($rows as $row) {
            $key = mb_strtolower($row['title']);
            if (($wanted[$key] ?? 0) > 0) {
                $wanted[$key]--;
                continue;
            }
            if ($row['written']) {
                $lost[] = $row['title'];
            }
        }

        return $lost;
    }

    /**
     * Why an outline was not applied, written for the model that sent it.
     *
     * @param string[] $atRisk
     */
    private static function removalRefusal(array $atRisk): string
    {
        $count = count($atRisk);
        $named = array_slice($atRisk, 0, 20);
        $list = implode('", "', $named);
        $rest = $count > count($named) ? ' and ' . ($count - count($named)) . ' more' : '';

        return 'Nothing has been changed. This outline does not name ' . $count . ' page(s) that already have text '
            . 'on them, and applying it would delete that text with no way back: "' . $list . '"' . $rest . '. '
            . 'Either put those titles back into the outline exactly as they are - get_structure shows them, and '
            . 'preview_structure tells you what CourseForge reads out of your Markdown before it is applied - or '
            . 'send the same call again with confirm_removals true if the pages really are meant to go.';
    }

    /**
     * Chapter and page rows keyed by id, so a diff is exact.
     *
     * Keying by title would collapse two pages that share one, which is
     * precisely the case a client needs told about.
     *
     * @param array<string,mixed> $project
     * @return array{chapters:array<int,string>,pages:array<int,array{title:string,written:bool}>}
     */
    private static function snapshot(array $project): array
    {
        $chapters = [];
        foreach (Chapters::ordered((int)$project['id']) as $row) {
            $chapters[(int)$row['id']] = (string)$row['title'];
        }

        $pages = [];
        foreach (Pages::ordered((int)$project['id']) as $row) {
            $pages[(int)$row['id']] = [
                'title' => (string)$row['title'],
                'written' => trim((string)$row['content']) !== '',
            ];
        }

        return ['chapters' => $chapters, 'pages' => $pages];
    }

    /**
     * @param array{chapters:array<int,string>,pages:array<int,array{title:string,written:bool}>} $before
     * @param array{chapters:array<int,string>,pages:array<int,array{title:string,written:bool}>} $after
     * @return array<string,mixed>
     */
    private static function diff(array $before, array $after): array
    {
        $titles = static fn(array $rows): array => array_values(array_map(
            static fn(array $row): string => $row['title'],
            $rows
        ));

        $goneRows = array_diff_key($before['pages'], $after['pages']);
        $lost = $titles(array_filter($goneRows, static fn(array $row): bool => $row['written']));

        return [
            'added' => [
                'chapters' => array_values(array_diff_key($after['chapters'], $before['chapters'])),
                'pages' => $titles(array_diff_key($after['pages'], $before['pages'])),
            ],
            'kept' => [
                'chapters' => count(array_intersect_key($after['chapters'], $before['chapters'])),
                'pages' => count(array_intersect_key($after['pages'], $before['pages'])),
            ],
            'removed' => [
                'chapters' => array_values(array_diff_key($before['chapters'], $after['chapters'])),
                'pages' => $titles($goneRows),
            ],
            'lost_content' => $lost,
            'totals' => [
                'chapters' => count($after['chapters']),
                'pages' => count($after['pages']),
                'unwritten' => count(array_filter($after['pages'], static fn(array $row): bool => !$row['written'])),
            ],
        ];
    }

    /**
     * The shared answer of the two tools that write an outline.
     *
     * @param array<string,mixed> $project
     * @param array<string,mixed> $diff
     * @return array<string,mixed>
     */
    private static function applied(array $project, string $owner, array $diff, string $tool): array
    {
        $courseId = (int)$project['id'];
        $unwritten = (int)$diff['totals']['unwritten'];
        $lost = (array)$diff['lost_content'];

        $result = [
            'applied' => true,
            'tool' => $tool,
            'course_id' => $courseId,
            'owner' => $owner,
            'chapters' => $diff['totals']['chapters'],
            'pages' => $diff['totals']['pages'],
            'added' => $diff['added'],
            'kept' => $diff['kept'],
            'removed' => $diff['removed'],
        ];

        if ($lost !== []) {
            // The removal is already committed. Saying so plainly is the only
            // thing left to do, and it has to be impossible to miss.
            $result['warning'] = count($lost) === 1
                ? 'The removed page "' . $lost[0] . '" had text on it, and that text is gone.'
                : count($lost) . ' of the removed pages had text on them, and that text is gone. Removed with '
                    . 'content: ' . implode(', ', $lost) . '.';
        }

        $result['next_step'] = $unwritten > 0
            ? 'Call get_page_brief with course_id ' . $courseId . ' for the next unwritten page, or start_run to '
                . 'write the whole course through the profile\'s model.'
            : 'Every page of this course already has text. publish_course pushes it to BookStack.';

        return $result;
    }
}
