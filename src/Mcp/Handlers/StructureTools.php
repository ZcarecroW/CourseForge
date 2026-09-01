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
use CourseForge\Domain\Research;
use CourseForge\Domain\Structure;
use CourseForge\Mcp\Args;
use CourseForge\Mcp\Ask;
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
 * is no undo. The refusal itself is Projects::applyStructure's, not this file's
 * - the browser is held to the same rule by the same code, and what is said
 * here is only the same decision put in words a model can act on.
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

        A description of the book: roughly 600 words of plain prose, three to five
        paragraphs, blank line between them, no list markers and no headings.

        Second paragraph of the book description, and so on.

        1. Chapter Title
           A description of the chapter: roughly 600 words, three to five paragraphs,
           every line of it indented by three spaces, no list markers.

           Second paragraph of the chapter description, indented the same way.
           1. Page Title
           2. Another Page Title
        2. Next Chapter Title
           Chapter description again, same length, same indentation
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
        // Read for the prompt library and the output language, and never left
        // this method even when it carried a key. A course with no profile gets
        // the installation's own prompts and language rather than a refusal:
        // building a brief spends nothing and needs no AI account, which is the
        // whole point of handing it to the client.
        $profile = Resolve::profileForBrief($project);

        $feedback = trim($args->raw('feedback'));
        $brief = self::brief($profile, $project, $feedback);
        $autoTags = (int)$project['auto_tags'] === 1;

        // The same inversion `get_page_brief` does, moved one level up. A page
        // researched after the outline is written can correct a sentence; it
        // cannot correct a chapter list that was designed around a version of
        // the subject that no longer exists. So the client is told to look
        // before it designs, and told what is already known so it does not go
        // and find it a second time.
        $details = Details::resolve(Projects::settings($project));
        $research = (bool)($details['features']['web_research'] ?? false);
        $searches = Research::searchBudget($details['params']);
        $stored = Research::of($project);
        $courseId = (int)$project['id'];

        return [
            'course_id' => $courseId,
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
            'web_research' => $research,
            'max_searches' => $research ? $searches : 0,
            'stored_research' => $stored,
            'research_freshness' => Research::freshness($project),
            'research_brief' => $research && $stored === '' ? Research::assignment($project, $searches) : '',
            'next_step' => self::nextStep($courseId, $research, $stored !== '', $project),
        ];
    }

    /**
     * What to do after reading the brief, which depends on what is known.
     *
     * Three states, and they are genuinely different pieces of advice: research
     * is off and the outline can be designed now; research is on and nothing
     * has been found yet, so go and look first; research is on and a briefing
     * already exists, so use it and only refresh it if it has gone stale.
     *
     * @param array<string,mixed> $project
     */
    private static function nextStep(int $courseId, bool $research, bool $hasResearch, array $project): string
    {
        $design = 'Write the complete outline in the required format, then call apply_structure with course_id '
            . $courseId . '. preview_structure checks the Markdown first without changing anything.';

        if (!$research) {
            return $design;
        }

        if (!$hasResearch) {
            return 'This course asks for web research, and none is stored yet. Search the web from research_brief '
                . 'above with your own tools first - the current stable version, what changed recently, what has '
                . 'been deprecated, where the documentation now lives - and send what you find to store_research, '
                . 'so the outline is designed against what is true today rather than against what the model '
                . 'remembers. Then ' . lcfirst($design);
        }

        return 'This course asks for web research and already has findings, ' . Research::freshness($project)
            . '. They are in stored_research above: design the outline against them. If the subject has moved '
            . 'since then, research again and call store_research before designing. Then ' . lcfirst($design);
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
        // honest thing left to say is which pages have already been lost. The
        // domain refuses this too - what is gained by asking first is a refusal
        // written for a model rather than for somebody reading a dialog.
        $atRisk = Projects::pagesLosingContent($project, $markdown);
        $confirmed = $args->bool('confirm_removals');

        if ($atRisk !== [] && !$confirmed) {
            // Deleting written pages is the one thing in this surface that
            // cannot be undone, so it is the one place worth stopping to ask a
            // person rather than only telling the model which flag to set.
            //
            // The order is the one that matters: work out what would be lost,
            // ask, and only then write. A confirmation that arrives after the
            // delete is a notification.
            //
            // On a client that cannot ask anybody this is exactly the refusal
            // it always was - same sentence, same flag - because that sentence
            // is what gets handed back instead of the question.
            $confirmed = Ask::confirm(
                'apply_structure_removals',
                'Applying this outline to "' . $project['name'] . '" would delete '
                    . count($atRisk) . ' page' . (count($atRisk) === 1 ? '' : 's')
                    . ' that already have text written on them:' . "\n\n"
                    . implode("\n", array_map(
                        static fn(string $title): string => '  - ' . $title,
                        array_slice($atRisk, 0, 20)
                    ))
                    . (count($atRisk) > 20 ? "\n  - and " . (count($atRisk) - 20) . ' more' : '')
                    . "\n\nThis cannot be undone.",
                self::removalRefusal($atRisk),
                // The pages this question is about. If that set changes while
                // the question is outstanding - somebody writes a page in the
                // browser, a background run finishes - the answer no longer
                // covers what would happen, and the question is put again.
                self::fingerprint($atRisk)
            );

            if (!$confirmed) {
                throw HttpException::unprocessable(
                    'Nothing was changed: the deletion of ' . count($atRisk)
                    . ' written page' . (count($atRisk) === 1 ? '' : 's') . ' was not confirmed.'
                );
            }
        }

        $before = self::snapshot($project);
        Projects::applyStructure($project, $markdown, $confirmed);
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
        $atRisk = Projects::pagesLosingContent($project, $markdown);
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
        Projects::applyStructure($project, $markdown, $args->bool('confirm_removals'));
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
        //
        // Compared by title and in order, not by counting. Counting answered
        // the wrong question: applyStructure matches existing content BY TITLE,
        // so an outline naming the same number of pages under different names
        // is the disagreement that destroys work - and it was the one case this
        // field called "in step".
        $liveChapterRows = Chapters::ordered($courseId);
        $livePageRows = Pages::ordered($courseId);
        $liveChapters = count($liveChapterRows);
        $livePages = count($livePageRows);

        $live = [];
        foreach ($liveChapterRows as $chapterRow) {
            $chapterId = (int)$chapterRow['id'];
            $pages = [];
            foreach ($livePageRows as $pageRow) {
                if ((int)$pageRow['chapter_id'] === $chapterId) {
                    $pages[] = (string)$pageRow['title'];
                }
            }
            $live[] = ['title' => (string)$chapterRow['title'], 'pages' => $pages];
        }

        $outlined = array_map(
            static fn(array $c): array => ['title' => $c['title'], 'pages' => $c['pages']],
            $chapters
        );

        $inStep = $live === $outlined;

        $differences = [];
        foreach ($outlined as $i => $chapter) {
            $there = $live[$i] ?? null;
            if ($there === null) {
                $differences[] = 'the outline has a chapter "' . $chapter['title'] . '" the course does not';
                continue;
            }
            if ($there['title'] !== $chapter['title']) {
                $differences[] = 'chapter ' . ($i + 1) . ' is "' . $there['title']
                    . '" in the course and "' . $chapter['title'] . '" in the outline';
            }
            foreach ($chapter['pages'] as $j => $pageTitle) {
                $therePage = $there['pages'][$j] ?? null;
                if ($therePage === null) {
                    $differences[] = 'the outline has a page "' . $pageTitle . '" the course does not';
                } elseif ($therePage !== $pageTitle) {
                    $differences[] = 'a page is "' . $therePage . '" in the course and "'
                        . $pageTitle . '" in the outline';
                }
            }
        }
        foreach (array_slice($live, count($outlined)) as $extra) {
            $differences[] = 'the course has a chapter "' . $extra['title'] . '" the outline does not name';
        }

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
                : 'The stored outline and the course itself disagree. The outline describes ' . count($chapters)
                    . ' chapters and ' . $pageCount . ' pages; the course holds ' . $liveChapters . ' chapters and '
                    . $livePages . ' pages'
                    . ($differences === []
                        ? '.'
                        : ', and: ' . implode('; ', array_slice($differences, 0, 8))
                            . (count($differences) > 8 ? '; and ' . (count($differences) - 8) . ' more' : '') . '.')
                    . ' Read the course itself with get_course before revising - applying an outline matches pages by '
                    . 'title, so a title that differs is a page that would be replaced rather than kept.',
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
                $problems[] = 'Two chapters are called "' . $chapter['title'] . '". They stay two chapters - the '
                    . 'first one in the outline is matched to the first stored one of that name and the second to '
                    . 'the second - but a reader cannot tell them apart, and neither can you when you revise this.';
            }
            $seenChapters[$key] = true;

            if ($chapter['pages'] === []) {
                $emptyChapters[] = $chapter['title'];
            }

            $pages = [];
            foreach ($chapter['pages'] as $page) {
                $pageKey = mb_strtolower($page['title']);
                if (isset($seenPages[$pageKey])) {
                    $problems[] = 'Two pages are called "' . $page['title'] . '". Both are kept, and each is matched '
                        . 'to the stored page of that name in the same position - but which of them holds which text '
                        . 'is decided by position alone, so reordering them swaps their content. Distinct titles are '
                        . 'safer, and they are what the cross-reference markers need in order to resolve.';
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
        if ($parsed['title'] === '') {
            $problems[] = 'No "# " title line was read, so this outline says nothing about what the book is called '
                . 'and applying it would leave the stored title alone. The first line of the outline must be "# " '
                . 'followed by the book title.';
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
            // The same block StructureGenerator puts in front of CourseForge's
            // own model. Both halves of this file exist so that a client and
            // the server are asked for the same thing in the same words; a
            // brief that left the researched facts out would be asking the
            // client to design from memory while the server designed from
            // what was found.
            Research::has($project)
                ? Prompt::slotOrDefault($library, 'research_block', $vars, '{{research_block}}')
                : '',
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
            'research_findings' => Research::of($project),
            'research_block' => Research::block($project),
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
            'The prose directly below the title is the book description: roughly 600 words, three to five paragraphs, '
                . 'a blank line between them.',
            'Chapters are a top-level ordered list at indentation 0, written as "1. Chapter Title".',
            'A chapter description sits directly below its chapter title: roughly 600 words of plain prose, three to '
                . 'five paragraphs, every line indented three spaces, a blank line between paragraphs, no list marker.',
            'No paragraph of a description may begin with a digit and "." or ")", with "-", "*" or "+", or with "#". '
                . 'Indented three spaces, a line that starts that way is exactly the shape of a page entry and will '
                . 'be read as one. Escape it with a backslash ("\\3. Install ...") or reword it to begin with a word.',
            'Descriptions at that length are the course prospectus, not a restatement of the titles under them: what '
                . 'the reader will be able to do, which ideas arrive in which order, what is assumed, what is '
                . 'commonly got wrong, and how it joins to the chapters either side.',
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
     * Why an outline was not applied, written for the model that sent it.
     *
     * @param string[] $atRisk
     */
    /**
     * A stable fingerprint of the pages a question was asked about.
     *
     * Order must not matter: the same pages arriving in a different order are
     * the same pages, and asking again for that would be noise.
     *
     * @param array<int,string> $atRisk
     */
    private static function fingerprint(array $atRisk): string
    {
        $sorted = $atRisk;
        sort($sorted);
        return hash('sha256', (string)json_encode($sorted));
    }

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

        // An outline with chapters but no pages parses cleanly and is useless:
        // there is nothing to write and nothing to publish. It is nearly always
        // the same mistake - the nesting was lost, so what were meant to be
        // pages became chapters - and saying that is more use than a count.
        if ((int)($result['pages'] ?? 0) === 0) {
            $result['warning'] = trim((string)($result['warning'] ?? '') . ' ' . ((int)($result['chapters'] ?? 0) > 0
                ? 'This outline has chapters but no pages at all, so there is nothing to write. Pages are the '
                    . 'nested numbers indented inside a chapter - call get_structure_brief for the exact format, '
                    . 'or preview_structure to see what CourseForge reads from a draft before you apply it.'
                : 'This outline has nothing in it.'));
        }

        $result['next_step'] = match (true) {
            (int)($result['pages'] ?? 0) === 0 => 'Fix the outline and apply it again. preview_structure shows '
                . 'what CourseForge understands from a draft without changing anything.',
            $unwritten > 0 => 'Call get_page_brief with course_id ' . $courseId . ' for the next unwritten page, '
                . 'or start_run to write the whole course through the model the profile names.',
            default => 'Every page of this course already has text. publish_course pushes it to BookStack.',
        };

        return $result;
    }
}
