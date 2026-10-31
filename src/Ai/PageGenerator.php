<?php
declare(strict_types=1);

namespace CourseForge\Ai;

use CourseForge\Domain\Chapters;
use CourseForge\Domain\Details;
use CourseForge\Domain\Pages;
use CourseForge\Domain\Profiles;
use CourseForge\Domain\Projects;
use CourseForge\Domain\Research;
use CourseForge\Domain\Tags;
use CourseForge\Support\Config;
use CourseForge\Support\HttpException;
use CourseForge\Support\Markdown;
use CourseForge\Support\Typography;
use Throwable;

/**
 * Writes exactly one course page.
 *
 * The prompt is assembled in four layers, in the order they are sent:
 *   1. the context the page shares with others - the course map and what was
 *      researched, then the chapter it belongs to (see CourseContext),
 *   2. the persona and the universal formatting contract,
 *   3. the resolved content details for this page (course → chapter → page),
 *   4. the concrete brief with the page's place in the course.
 *
 * Layer 3 is what makes a page in one chapter carry exercises and diagrams
 * while a page in the next one carries neither.
 *
 * Layer 1 comes first because it is the part that repeats. It is byte-identical
 * for every page of a course (the course segment) or of a chapter (the chapter
 * segment), and it is handed to the provider as separate segments rather than
 * folded into the system text, so that each adapter can put a cache breakpoint
 * behind it: the map is paid for once and read back at a tenth of the price by
 * every page after it. Anything that differs per page - the brief, the page's
 * own tags, the neighbours already written - stays out of it, because one
 * changed byte in a prefix makes everything after it a cache miss.
 *
 * Building the prompt and sending it are separate steps. The live path does
 * both in one breath; the batch runner builds a few dozen prompts, hands them
 * to a provider queue and comes back hours later with the answers – and it
 * needs exactly the prompt the live path would have produced, or a course
 * generated overnight would not match one generated page by page.
 */
final class PageGenerator
{
    /** Enforced in code because the rest of the app depends on it. */
    private const NO_HEADING_RULE =
        'Never output a level-1 heading (`# `). The page title is owned by the platform. '
        . 'Start directly with the content and use `## ` and deeper for internal structure.';

    /** BookStack renders Markdown; raw or interactive HTML must never appear. */
    private const NO_RAW_HTML_RULE =
        'Output plain Markdown only. Never use raw HTML and never use interactive or collapsible elements '
        . 'such as <details>, <summary>, <div>, <span>, <iframe> or HTML tables, and never ask the reader to '
        . 'click or expand anything.';

    /**
     * Generates the page, stores it and returns the full page shape.
     *
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $project
     * @return array<string,mixed>
     */
    public static function run(array $profile, array $project, int $pageId, string $feedback = ''): array
    {
        Pages::update($pageId, ['status' => 'generating', 'error' => '']);

        try {
            $plan = self::plan($profile, $project, $pageId, $feedback);
            $content = Completion::run(
                $profile,
                'page',
                $plan['system'],
                $plan['user'],
                $plan['research'],
                $plan['max_searches'],
                $plan['context'],
            );
            return self::store($project, $plan['page'], $content);
        } catch (Throwable $e) {
            self::fail($pageId, $e->getMessage());
            throw $e;
        }
    }

    /**
     * The prompt for one page, without sending anything.
     *
     * `context` is the shared part, as segments in the order they are sent:
     * the course, then the chapter. A provider places them ahead of `system`
     * and caches behind each; a consumer with no use for the distinction -
     * a token estimate, a connected client - can join them.
     *
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $project
     * `shared` is the same two segments by name, for a consumer that hands them
     * out separately - get_page_brief, which lets a client skip the ones it holds.
     *
     * @return array{page:array<string,mixed>,context:string[],shared:array{course:string,chapter:string},system:string,user:string,research:bool,max_searches:int}
     */
    public static function plan(array $profile, array $project, int $pageId, string $feedback = ''): array
    {
        $projectId = (int)$project['id'];
        $pages = Pages::outline($projectId);
        $page = self::locate($pages, $pageId);
        // The one page whose text the prompt can quote back: its own, for a
        // rewrite. Read alone rather than with the whole course.
        $page['content'] = Pages::contents($projectId, [$pageId])[$pageId] ?? '';

        $library = Prompt::library($profile);
        $details = Details::resolve(...Projects::chain(
            $project,
            Details::decode((string)$page['chapter_settings']),
            Pages::settings($page),
        ));
        $features = $details['features'];
        $params = $details['params'];

        $vars = self::vars($profile, $project, $page, $pages, $params, $feedback);

        $wantsLength = (int)($params['min_length'] ?? 0) > 0 || (int)($params['max_length'] ?? 0) > 0;

        // A brief that still quotes the chapter goal itself - a prompt library
        // customised before the chapter had a segment of its own - would carry
        // it twice. The brief wins, because it is the one somebody wrote.
        $briefTemplate = trim((string)($library['page_user'] ?? ''));
        $chapterInBrief = str_contains($briefTemplate, '{{chapter_description}}');

        $shared = [
            'course' => Prompt::join(
                Prompt::slotOrDefault($library, 'page_context_block', $vars,
                    "Course: {{book_title}}\nBrief: {{topic}}\n{{book_description}}\n\nCourse map:\n\n{{course_structure}}"),
                // Only when somebody actually researched the course. Part of
                // the course segment because it is about the course: read by
                // every page, and cached with the map.
                Research::has($project)
                    ? Prompt::slotOrDefault($library, 'research_block', $vars, '{{research_block}}')
                    : '',
            ),
            'chapter' => $chapterInBrief ? '' : Prompt::slot($library, 'page_chapter_block', $vars),
        ];
        $context = array_values(array_filter($shared, static fn(string $segment): bool => $segment !== ''));

        $system = Prompt::join(
            Prompt::slot($library, 'global_system', $vars),
            (string)($vars['audience'] ?? '') !== '' ? Prompt::slot($library, 'audience_block', $vars) : '',
            Prompt::slotOrDefault($library, 'page_system', $vars,
                'You are an expert instructor and technical writer. You write one page of the course '
                . '"{{book_title}}": precise, practical, example-driven and didactically sound.'),
            Prompt::slotOrDefault($library, 'page_rules', $vars,
                "Formatting rules:\n"
                . "- Output GitHub-flavoured Markdown only, never wrapped in a code fence.\n"
                . "- Structure the page with '## ' sections; keep paragraphs to two to four sentences.\n"
                . '- No filler, no padding, no restating of the page title.'),
            $wantsLength ? Prompt::slot($library, 'length_rules', $vars) : '',
            Prompt::detailRules($library, $features, $vars),
            self::NO_HEADING_RULE,
            self::NO_RAW_HTML_RULE,
            Prompt::slotOrDefault($library, 'language_instruction', $vars, 'Write the entire page in {{language}}.')
        );

        $user = Prompt::join(
            Prompt::slotOrDefault($library, 'page_user', $vars,
                "Write page {{page_ref}} of the course map: {{page_title}}\n"
                . "What this page alone teaches: {{page_scope}}\n"
                . "The page before it: {{previous_page}}\n"
                . "The page after it: {{next_page}}\n\n"
                . "Cover exactly this page's scope and do not duplicate other pages."),
            // What the neighbours turned out to contain, which is not always
            // what the map planned. Only when one of them has been written.
            (string)$vars['written_pages'] !== ''
                ? Prompt::slotOrDefault($library, 'page_written_block', $vars,
                    "Already written in this chapter - do not repeat it:\n\n{{written_pages}}")
                : '',
            trim((string)$page['extra_context']) !== ''
                ? Prompt::slotOrDefault($library, 'extra_context_block', $vars,
                    "Additional context supplied by the author, which must be respected:\n\n{{extra_context}}")
                : '',
            trim($feedback) !== ''
                ? Prompt::slotOrDefault($library, 'page_regenerate_user', $vars,
                    "Existing version of this page:\n\n{{existing_content}}\n\nRewrite it, applying this feedback:\n{{feedback}}")
                : ''
        );

        // The two research facts travel beside the prompt rather than inside
        // it, because they have two audiences. The prompt text tells whoever
        // writes the page to look things up; these say who is doing the looking.
        // CourseForge's own path turns them into the provider's search tool, and
        // `get_page_brief` hands them to a connected client that has a search
        // tool of its own - which is the cheaper of the two, since that client
        // is already paying for its searches.
        return [
            'page' => $page,
            'context' => $context,
            'shared' => $shared,
            'system' => $system,
            'user' => $user,
            'research' => (bool)($features['web_research'] ?? false),
            'max_searches' => max(0, (int)($params['research_max_searches'] ?? 0)),
        ];
    }

    /**
     * Applies one finished answer to a page.
     *
     * Shared by the live path and the batch runner, so a page written overnight
     * is cleaned up, stored and reported exactly like one written just now.
     *
     * @param array<string,mixed> $project
     * @param array<string,mixed> $page
     * @return array<string,mixed>
     */
    public static function store(array $project, array $page, string $content): array
    {
        $projectId = (int)$project['id'];
        $pageId = (int)$page['id'];

        $content = Markdown::stripLeadingHeading($content, (string)$page['title']);
        if ($content === '') {
            throw HttpException::badRequest('The AI returned an empty page.');
        }
        $content = self::setPunctuation($project, $content);

        Pages::update($pageId, ['content' => $content, 'status' => 'generated', 'error' => '']);
        Projects::touch($projectId);

        return Pages::detail($projectId, $pageId);
    }

    /**
     * Sets the page's punctuation the way its language does, if the profile
     * asks for it.
     *
     * Here rather than in the prompt because a rule that runs beats a rule that
     * is asked for: a model told to close a German quotation with `“` does it
     * on most pages, and "most" is the wrong number for a book. Here rather
     * than at publish time because what is stored is what an author reads,
     * edits and re-publishes, and a page that is corrected only on the way out
     * would be corrected again on every way out.
     *
     * It is also why this sits in store() rather than in run(): a page a
     * connected client wrote through `store_page`, and one a batch answered
     * overnight, are the same page arriving by another road.
     *
     * @param array<string,mixed> $project
     */
    private static function setPunctuation(array $project, string $content): string
    {
        $profile = self::profileOf($project);

        // No profile is the MCP path on a course that has not been given one.
        // The installation's answer is the right one to fall back to: it is
        // what a profile would have started from anyway.
        $on = $profile === null
            ? Config::bool('app.typography', true)
            : (bool)($profile['typography'] ?? true);

        return $on ? Typography::apply($content, Completion::language($profile ?? [])) : $content;
    }

    /**
     * The profile a course was written with, or null when it has none.
     *
     * Read rather than passed in, because store() is reached from three places
     * and only one of them was holding a profile - and a page that is stored
     * without one is still a page in a language.
     *
     * @param array<string,mixed> $project
     * @return array<string,mixed>|null
     */
    private static function profileOf(array $project): ?array
    {
        $id = $project['profile_id'] ?? null;
        $owner = (string)($project['username'] ?? $project['owner'] ?? '');
        if ($id === null || $owner === '') {
            return null;
        }

        try {
            return Profiles::data($owner, (int)$id);
        } catch (Throwable) {
            // A profile deleted since the page was queued is not a reason to
            // lose the page the model has already been paid for.
            return null;
        }
    }

    /** Records a failure on the page, so the Content tab can show it. */
    public static function fail(int $pageId, string $message): void
    {
        Pages::update($pageId, ['status' => 'error', 'error' => mb_substr($message, 0, 500)]);
    }

    /* ----------------------------------------------------------- internals */

    /**
     * @param array<int,array<string,mixed>> $pages
     * @return array<string,mixed>
     */
    private static function locate(array $pages, int $pageId): array
    {
        foreach ($pages as $row) {
            if ((int)$row['id'] === $pageId) {
                return $row;
            }
        }
        throw HttpException::notFound('Page not found.');
    }

    /**
     * Every placeholder a page prompt may use.
     *
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $project
     * @param array<string,mixed> $page
     * @param array<int,array<string,mixed>> $pages
     * @param array<string,int|string> $params
     * @return array<string,scalar>
     */
    private static function vars(array $profile, array $project, array $page, array $pages, array $params, string $feedback): array
    {
        $projectId = (int)$project['id'];
        $pageId = (int)$page['id'];
        $global = (int)$page['global_idx'];
        $titles = array_map(static fn(array $row): string => (string)$row['title'], $pages);
        $chapters = Chapters::ordered($projectId);

        $effectiveTags = Tags::resolved($projectId)['effective']['page'][$pageId] ?? [];
        $tags = Tags::names($effectiveTags);
        $previous = $pages[$global - 1] ?? null;
        $next = $pages[$global + 1] ?? null;

        // Offered as {{link_targets}} but not part of any default prompt: the
        // course map already lists every title, and duplicating it would
        // double the token cost of each page.
        $targets = [];
        foreach ($chapters as $chapter) {
            $targets[] = 'Chapter: ' . $chapter['title'];
        }
        foreach ($pages as $row) {
            if ((int)$row['id'] !== $pageId) {
                $targets[] = 'Page: ' . $row['title'];
            }
        }

        return $params + [
            'language' => Completion::language($profile),
            'app_name' => Config::str('app.name', 'CourseForge'),
            'topic' => (string)$project['topic'],
            'book_title' => Projects::bookTitle($project),
            'book_description' => (string)$project['book_desc'],
            // The map, not the outline: every page with what it alone teaches,
            // and none of the six hundred words per chapter the outline spends
            // on its cover pages. The outline is still there for a prompt
            // library that wants it, under its own name.
            'course_structure' => CourseContext::map($pages, $chapters),
            'course_structure_full' => (string)$project['structure_md'],
            // Empty unless somebody researched the course, which is what makes
            // the research_block slot disappear rather than render a heading
            // over nothing.
            'research_findings' => Research::of($project),
            'research_block' => Research::block($project),
            'chapter_index' => (int)$page['chapter_idx'] + 1,
            'chapter_title' => (string)$page['chapter_title'],
            'chapter_description' => (string)$page['chapter_description'],
            'page_index' => (int)$page['idx'] + 1,
            'page_title' => (string)$page['title'],
            'page_ref' => CourseContext::ref($page),
            // Never blank in a sentence that introduces it: a model told
            // "What this page alone teaches:" and then nothing fills the gap
            // with a guess of its own and believes it.
            'page_scope' => trim((string)$page['scope']) !== ''
                ? (string)$page['scope']
                : '(not planned - work it out from the title and the course map)',
            'page_number_global' => $global + 1,
            'total_pages' => count($pages),
            'previous_page_titles' => implode(', ', array_slice($titles, max(0, $global - 3), min(3, $global))),
            'next_page_titles' => implode(', ', array_slice($titles, $global + 1, 3)),
            'previous_page' => $previous !== null ? CourseContext::line($previous) : '(none - this is the first page)',
            'next_page' => $next !== null ? CourseContext::line($next) : '(none - this is the last page)',
            'written_pages' => CourseContext::writtenNeighbours(
                $pages,
                $page,
                static fn(array $ids): array => Pages::contents($projectId, $ids),
            ),
            'extra_context' => (string)$page['extra_context'],
            'existing_content' => (string)$page['content'],
            'feedback' => trim($feedback),
            'tags' => $tags !== '' ? $tags : '(none)',
            'link_targets' => implode("\n", $targets),
        ];
    }
}
