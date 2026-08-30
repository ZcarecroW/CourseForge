<?php
declare(strict_types=1);

namespace CourseForge\Ai;

use CourseForge\Domain\Chapters;
use CourseForge\Domain\Details;
use CourseForge\Domain\Pages;
use CourseForge\Domain\Projects;
use CourseForge\Domain\Tags;
use CourseForge\Support\Config;
use CourseForge\Support\HttpException;
use CourseForge\Support\Markdown;
use Throwable;

/**
 * Writes exactly one course page.
 *
 * The prompt is assembled in three layers:
 *   1. the persona and the universal formatting contract,
 *   2. the resolved content details for this page (course → chapter → page),
 *   3. the concrete brief with the page's place in the course.
 *
 * Layer 2 is what makes a page in one chapter carry exercises and diagrams
 * while a page in the next one carries neither.
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
            );
            return self::store($project, $plan['page'], $content);
        } catch (Throwable $e) {
            self::fail($pageId, $e->getMessage());
            throw $e;
        }
    }

    /**
     * The two prompt halves for one page, without sending anything.
     *
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $project
     * @return array{page:array<string,mixed>,system:string,user:string,research:bool,max_searches:int}
     */
    public static function plan(array $profile, array $project, int $pageId, string $feedback = ''): array
    {
        $projectId = (int)$project['id'];
        $pages = Pages::ordered($projectId);
        $page = self::locate($pages, $pageId);

        $library = Prompt::library($profile);
        $details = Details::resolve(
            Projects::settings($project),
            Details::decode((string)$page['chapter_settings']),
            Pages::settings($page)
        );
        $features = $details['features'];
        $params = $details['params'];

        $vars = self::vars($profile, $project, $page, $pages, $params, $feedback);

        $wantsLength = (int)($params['min_length'] ?? 0) > 0 || (int)($params['max_length'] ?? 0) > 0;

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
            Prompt::slotOrDefault($library, 'page_context_block', $vars,
                "Course: {{book_title}}\n{{book_description}}\n\nFull course structure:\n\n{{course_structure}}"),
            Prompt::slotOrDefault($library, 'page_user', $vars,
                "Write page {{page_number_global}} of {{total_pages}}.\n"
                . "Chapter {{chapter_index}}: {{chapter_title}} – {{chapter_description}}\n"
                . "Page {{page_index}}: {{page_title}}\n"
                . "Previously covered: {{previous_page_titles}}\n"
                . "Coming next: {{next_page_titles}}\n\n"
                . "Cover exactly this page's scope and do not duplicate other pages."),
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

        Pages::update($pageId, ['content' => $content, 'status' => 'generated', 'error' => '']);
        Projects::touch($projectId);

        return Pages::detail($projectId, $pageId);
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

        $effectiveTags = Tags::resolved($projectId)['effective']['page'][$pageId] ?? [];

        // Offered as {{link_targets}} but not part of any default prompt: the
        // course structure already lists every title, and duplicating it would
        // double the token cost of each page.
        $targets = [];
        foreach (Chapters::ordered($projectId) as $chapter) {
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
            'course_structure' => (string)$project['structure_md'],
            'chapter_index' => (int)$page['chapter_idx'] + 1,
            'chapter_title' => (string)$page['chapter_title'],
            'chapter_description' => (string)$page['chapter_description'],
            'page_index' => (int)$page['idx'] + 1,
            'page_title' => (string)$page['title'],
            'page_number_global' => $global + 1,
            'total_pages' => count($pages),
            'previous_page_titles' => implode(', ', array_slice($titles, max(0, $global - 3), min(3, $global))),
            'next_page_titles' => implode(', ', array_slice($titles, $global + 1, 3)),
            'extra_context' => (string)$page['extra_context'],
            'existing_content' => (string)$page['content'],
            'feedback' => trim($feedback),
            'tags' => Tags::names($effectiveTags),
            'link_targets' => implode("\n", $targets),
        ];
    }
}
