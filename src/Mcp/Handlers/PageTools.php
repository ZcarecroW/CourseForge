<?php
declare(strict_types=1);

namespace CourseForge\Mcp\Handlers;

use CourseForge\Ai\PageGenerator;
use CourseForge\Domain\Chapters;
use CourseForge\Domain\Pages;
use CourseForge\Mcp\Args;
use CourseForge\Mcp\Resolve;
use CourseForge\Mcp\Schema;
use CourseForge\Mcp\Scopes;
use CourseForge\Mcp\Tool;
use CourseForge\Security\Actor;
use CourseForge\Support\HttpException;
use CourseForge\Support\Runtime;
use CourseForge\Support\Text;

/**
 * Pages: the two ways one gets written, and everything about reading them.
 *
 * The first way is the one CourseForge 3 was built around and is still the
 * cheapest: `get_page_brief` hands the client the *same* prompt CourseForge
 * would have sent a model itself - the persona, the formatting contract, the
 * content details resolved for that page, the course structure, the page's
 * place in it, what came before and what comes next - the client writes the
 * page, and `write_page` stores it. The writing happens inside the Claude
 * application, on somebody's own subscription, and the server never holds a
 * credential. A course can be half written this way and half by the API and
 * stay coherent, because both carry the same rules.
 *
 * The second is `generate_page`, which has CourseForge call the course's own
 * model. It costs money and it is the right answer when the client is not the
 * thing that should be doing the writing - a page being regenerated in the
 * middle of a batch run, say.
 */
final class PageTools
{
    /** @return array<int,Tool> */
    public static function tools(): array
    {
        return [
            new Tool(
                name: 'get_page_brief',
                scope: Scopes::PAGES,
                title: 'Get the writing brief for a page',
                description: 'The complete writing brief for one page: the system instructions and the user prompt '
                    . 'CourseForge would send a model, including the course structure, the page\'s place in it, what '
                    . 'comes before and after, and the content details resolved for it. Write the page from this '
                    . 'brief and send the result to write_page. Omit page_id to be given the next page that has not '
                    . 'been written yet. Costs nothing: no model is called.',
                properties: [
                    'course_id' => Schema::courseId(),
                    'page_id' => Schema::int('A specific page. Omit for the next unwritten one.'),
                    'feedback' => Schema::text('Revision instructions, when rewriting a page that already has text.'),
                ],
                required: ['course_id'],
                handler: static fn(Actor $actor, array $args): array => self::pageBrief($actor, Args::of($args)),
                readOnly: true,
                idempotent: true,
            ),

            new Tool(
                name: 'write_page',
                scope: Scopes::PAGES,
                title: 'Store a written page',
                description: 'Stores the Markdown of one page. The text must follow the brief: GitHub-flavoured '
                    . 'Markdown, no level-1 heading, no raw HTML. Returns how many pages are still unwritten, so a '
                    . 'loop of brief - write - store knows when it is finished.',
                properties: [
                    'course_id' => Schema::courseId(),
                    'page_id' => Schema::int('The page this text belongs to.'),
                    'content' => Schema::text('The finished page, in Markdown.'),
                ],
                required: ['course_id', 'page_id', 'content'],
                handler: static fn(Actor $actor, array $args): array => self::writePage($actor, Args::of($args)),
                idempotent: true,
            ),

            new Tool(
                name: 'list_pages',
                scope: Scopes::PAGES,
                title: 'List the pages of a course',
                description: 'Every page of a course in reading order, with its chapter, status, word count and '
                    . 'whether it has been published. Lighter than get_course when all you need is what to write next.',
                properties: [
                    'course_id' => Schema::courseId(),
                    'only' => Schema::enum(
                        'Which pages to return.',
                        ['all', 'written', 'unwritten', 'failed', 'unpublished']
                    ),
                ],
                required: ['course_id'],
                handler: static fn(Actor $actor, array $args): array => self::listPages($actor, Args::of($args)),
                readOnly: true,
                idempotent: true,
            ),

            new Tool(
                name: 'get_page',
                scope: Scopes::PAGES,
                title: 'Read one page',
                description: 'One page in full: its title, its Markdown, its extra context, its status and where it '
                    . 'sits in the course.',
                properties: [
                    'course_id' => Schema::courseId(),
                    'page_id' => Schema::int('The page to read.'),
                ],
                required: ['course_id', 'page_id'],
                handler: static fn(Actor $actor, array $args): array => self::getPage($actor, Args::of($args)),
                readOnly: true,
                idempotent: true,
            ),

            new Tool(
                name: 'update_page',
                scope: Scopes::PAGES,
                title: 'Edit a page',
                description: 'Changes a page\'s title, its Markdown, or the extra context that is added to its brief '
                    . 'the next time it is written. Only the fields you give are changed. Renaming a page also '
                    . 'rewrites the stored outline, because chapters and pages are matched by title.',
                properties: [
                    'course_id' => Schema::courseId(),
                    'page_id' => Schema::int('The page to change.'),
                    'title' => Schema::string('A new title.'),
                    'content' => Schema::text('New Markdown for the page.'),
                    'extra_context' => Schema::text(
                        'Notes that are added to this page\'s brief - things this page in particular must cover, or '
                        . 'must not. They are instructions to the writer, not text that appears on the page.'
                    ),
                ],
                required: ['course_id', 'page_id'],
                handler: static fn(Actor $actor, array $args): array => self::updatePage($actor, Args::of($args)),
                idempotent: true,
            ),

            new Tool(
                name: 'update_chapter',
                scope: Scopes::PAGES,
                title: 'Edit a chapter',
                description: 'Changes a chapter\'s title or its description. Renaming a chapter rewrites the stored '
                    . 'outline, because chapters are matched by title.',
                properties: [
                    'course_id' => Schema::courseId(),
                    'chapter_id' => Schema::int('The chapter to change.'),
                    'title' => Schema::string('A new title.'),
                    'description' => Schema::text('A new description. This is part of what the pages are written from.'),
                ],
                required: ['course_id', 'chapter_id'],
                handler: static fn(Actor $actor, array $args): array => self::updateChapter($actor, Args::of($args)),
                idempotent: true,
            ),

            new Tool(
                name: 'generate_page',
                scope: Scopes::PAGES,
                title: 'Have CourseForge write a page',
                description: 'CourseForge calls the course\'s own model and writes one page, start to finish, and '
                    . 'stores it. This is the opposite of get_page_brief: the work happens on the server and is '
                    . 'billed to the profile\'s AI account. It blocks until the page is finished, which on a long '
                    . 'page can take minutes - to write a whole course, use start_run instead.',
                properties: [
                    'course_id' => Schema::courseId(),
                    'page_id' => Schema::int('The page to write. Omit for the next unwritten one.'),
                    'feedback' => Schema::text('Revision instructions, when rewriting a page that already has text.'),
                ],
                required: ['course_id'],
                handler: static fn(Actor $actor, array $args): array => self::generatePage($actor, Args::of($args)),
                spends: true,
            ),
        ];
    }

    /* ------------------------------------------------------------- handlers */

    /** @return array<string,mixed> */
    private static function pageBrief(Actor $actor, Args $args): array
    {
        ['project' => $project] = Resolve::course($actor, $args->id());
        $profile = Resolve::profile($project);

        $page = $args->has('page_id')
            ? Resolve::page($project, $args->id('page_id'))
            : Resolve::nextUnwritten($project);

        if ($page === null) {
            return [
                'done' => true,
                'course_id' => (int)$project['id'],
                'message' => 'Every page of this course has been written. Nothing is pending.',
            ];
        }

        $plan = PageGenerator::plan($profile, $project, (int)$page['id'], $args->raw('feedback'));

        return [
            'course_id' => (int)$project['id'],
            'page_id' => (int)$page['id'],
            'page_title' => (string)$plan['page']['title'],
            'chapter_title' => (string)$plan['page']['chapter_title'],
            'system_instructions' => $plan['system'],
            'writing_brief' => $plan['user'],
            'next_step' => 'Write the page from the brief above, then call write_page with course_id '
                . $project['id'] . ' and page_id ' . $page['id'] . '.',
        ];
    }

    /** @return array<string,mixed> */
    private static function writePage(Actor $actor, Args $args): array
    {
        ['project' => $project] = Resolve::course($actor, $args->id());
        $page = Resolve::page($project, $args->id('page_id'));

        $stored = PageGenerator::store($project, $page, $args->requiredRaw('content'));
        $remaining = self::countPages($project, 'unwritten');

        return [
            'stored' => true,
            'page_id' => (int)$page['id'],
            'title' => $stored['title'],
            'words' => $stored['word_count'],
            'remaining_unwritten' => $remaining,
            'next_step' => $remaining > 0
                ? 'Call get_page_brief again for the next one.'
                : 'The course is fully written. publish_course pushes it to BookStack.',
        ];
    }

    /** @return array<string,mixed> */
    private static function listPages(Actor $actor, Args $args): array
    {
        ['project' => $project] = Resolve::course($actor, $args->id());
        $only = $args->enum('only', ['all', 'written', 'unwritten', 'failed', 'unpublished'], 'all');

        $chapters = [];
        foreach (Chapters::ordered((int)$project['id']) as $chapter) {
            $chapters[(int)$chapter['id']] = (string)$chapter['title'];
        }

        $pages = [];
        foreach (Pages::ordered((int)$project['id']) as $page) {
            $written = trim((string)$page['content']) !== '';
            $failed = (string)$page['status'] === 'error';
            $published = $page['bs_id'] !== null;

            $keep = match ($only) {
                'written' => $written,
                'unwritten' => !$written,
                'failed' => $failed,
                'unpublished' => $written && !$published,
                default => true,
            };
            if (!$keep) {
                continue;
            }

            $pages[] = [
                'page_id' => (int)$page['id'],
                'chapter_id' => (int)$page['chapter_id'],
                'chapter_title' => $chapters[(int)$page['chapter_id']] ?? '',
                'title' => (string)$page['title'],
                'status' => (string)$page['status'],
                'written' => $written,
                // Text::words, not str_word_count: the latter counts only ASCII
                // words, so a Chinese or Cyrillic page reports zero - and a German
                // one reports roughly double, because it splits on the bytes of
                // every accented letter. This is the count a model reads to decide
                // whether a page has been written.
                'words' => $written ? Text::words(strip_tags((string)$page['content'])) : 0,
                'published' => $published,
                'error' => (string)$page['error'],
            ];
        }

        return ['course_id' => (int)$project['id'], 'filter' => $only, 'count' => count($pages), 'pages' => $pages];
    }

    /** @return array<string,mixed> */
    private static function getPage(Actor $actor, Args $args): array
    {
        ['project' => $project, 'owner' => $owner] = Resolve::course($actor, $args->id());
        $detail = Pages::detail((int)$project['id'], $args->id('page_id'));

        return ['course_id' => (int)$project['id'], 'owner' => $owner, 'page' => $detail];
    }

    /** @return array<string,mixed> */
    private static function updatePage(Actor $actor, Args $args): array
    {
        ['project' => $project, 'owner' => $owner] = Resolve::course($actor, $args->id());
        $page = Resolve::page($project, $args->id('page_id'));

        $fields = [];
        if ($args->has('title')) {
            $fields['title'] = $args->requiredStr('title');
        }
        if ($args->has('content')) {
            $fields['content'] = $args->raw('content');
            // Text that arrives from outside the generator still has to leave
            // the page in a state the rest of the application understands.
            $fields['status'] = trim($fields['content']) === '' ? 'pending' : 'done';
            $fields['error'] = '';
        }
        if ($args->has('extra_context')) {
            $fields['extra_context'] = $args->raw('extra_context');
        }
        if ($fields === []) {
            throw HttpException::unprocessable('Nothing to change. Give a title, content or extra_context.');
        }

        Pages::update((int)$page['id'], $fields);

        // A rename changes what the outline says, and the outline is what the
        // next generation reads - so they must not be allowed to disagree.
        if (isset($fields['title'])) {
            \CourseForge\Domain\Projects::resyncStructure($owner, (int)$project['id']);
        }

        return [
            'updated' => true,
            'page_id' => (int)$page['id'],
            'changed' => array_keys($fields),
            'structure_resynced' => isset($fields['title']),
        ];
    }

    /** @return array<string,mixed> */
    private static function updateChapter(Actor $actor, Args $args): array
    {
        ['project' => $project, 'owner' => $owner] = Resolve::course($actor, $args->id());
        $chapter = Resolve::chapter($project, $args->id('chapter_id'));

        $fields = [];
        if ($args->has('title')) {
            $fields['title'] = $args->requiredStr('title');
        }
        if ($args->has('description')) {
            $fields['description'] = $args->raw('description');
        }
        if ($fields === []) {
            throw HttpException::unprocessable('Nothing to change. Give a title or a description.');
        }

        Chapters::update((int)$chapter['id'], $fields);
        \CourseForge\Domain\Projects::resyncStructure($owner, (int)$project['id']);

        return ['updated' => true, 'chapter_id' => (int)$chapter['id'], 'changed' => array_keys($fields)];
    }

    /** @return array<string,mixed> */
    private static function generatePage(Actor $actor, Args $args): array
    {
        ['project' => $project] = Resolve::course($actor, $args->id());
        $profile = Resolve::profile($project);

        $page = $args->has('page_id')
            ? Resolve::page($project, $args->id('page_id'))
            : Resolve::nextUnwritten($project);

        if ($page === null) {
            return [
                'done' => true,
                'message' => 'Every page of this course has been written. Nothing is pending.',
            ];
        }

        // This call is going to sit on a provider for minutes. Releasing the
        // session lock and the time limit is what stops it from blocking every
        // other request the same account has in flight.
        Runtime::beginLongRequest();

        $result = PageGenerator::run($profile, $project, (int)$page['id'], $args->raw('feedback'));

        return [
            'generated' => true,
            'page_id' => (int)$page['id'],
            'title' => $result['title'] ?? (string)$page['title'],
            'words' => $result['word_count'] ?? 0,
            'remaining_unwritten' => self::countPages($project, 'unwritten'),
        ];
    }

    /* -------------------------------------------------------------- helpers */

    /** @param array<string,mixed> $project */
    private static function countPages(array $project, string $which): int
    {
        $count = 0;
        foreach (Pages::ordered((int)$project['id']) as $page) {
            $written = trim((string)$page['content']) !== '';
            if (($which === 'unwritten' && !$written) || ($which === 'written' && $written)) {
                $count++;
            }
        }
        return $count;
    }
}
