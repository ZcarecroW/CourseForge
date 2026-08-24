<?php
declare(strict_types=1);

namespace CourseForge\Mcp;

use CourseForge\Ai\PageGenerator;
use CourseForge\Ai\Prompt;
use CourseForge\Domain\Pages;
use CourseForge\Domain\Profiles;
use CourseForge\Domain\Projects;
use CourseForge\Support\HttpException;

/**
 * What CourseForge offers an MCP client.
 *
 * This is the other way round from everything else in src/Ai. There, CourseForge
 * holds the credentials and calls a model. Here the model is the client: Claude
 * Code or the Claude desktop app connects to CourseForge, asks what needs
 * writing, is handed the exact prompt CourseForge would have sent itself, and
 * writes the page back.
 *
 * That inversion is the point. The writing happens inside the Claude
 * application, on the person's own subscription, and CourseForge never sees a
 * credential of any kind - it only needs to be able to describe the work and
 * store the result.
 *
 * The prompt handed out is the same one PageGenerator::plan() builds for the
 * live path, so a page written this way carries the same content details,
 * the same length rules and the same cross-reference markers as one written by
 * the API. A course can be half written each way and stay coherent.
 */
final class Tools
{
    /**
     * The tool catalogue, in the shape tools/list returns.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function catalogue(): array
    {
        $courseId = ['type' => 'integer', 'description' => 'The course id, as returned by list_courses.'];

        return [
            [
                'name' => 'list_courses',
                'description' => 'List every course in CourseForge with its progress: how many chapters and pages '
                    . 'it has, how many are written, and how many are published.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            ],
            [
                'name' => 'get_course',
                'description' => 'The full outline of one course: every chapter and page, each with its id, title '
                    . 'and status. Use it to see what still needs writing.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['course_id' => $courseId],
                    'required' => ['course_id'],
                ],
            ],
            [
                'name' => 'get_page_brief',
                'description' => 'The complete writing brief for one page: the system instructions and the user '
                    . 'prompt CourseForge would send to a model, including the course structure, the page\'s place '
                    . 'in it, and the content details resolved for it. Write the page from this brief and send the '
                    . 'result to write_page. Omit page_id to be given the next page that has not been written yet.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'course_id' => $courseId,
                        'page_id' => ['type' => 'integer', 'description' => 'A specific page. Omit for the next unwritten one.'],
                        'feedback' => ['type' => 'string', 'description' => 'Optional revision instructions for a rewrite.'],
                    ],
                    'required' => ['course_id'],
                ],
            ],
            [
                'name' => 'write_page',
                'description' => 'Store the Markdown of one page. The text must follow the brief: GitHub-flavoured '
                    . 'Markdown, no level-1 heading, no raw HTML.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'course_id' => $courseId,
                        'page_id' => ['type' => 'integer', 'description' => 'The page to store this text on.'],
                        'content' => ['type' => 'string', 'description' => 'The finished page, in Markdown.'],
                    ],
                    'required' => ['course_id', 'page_id', 'content'],
                ],
            ],
            [
                'name' => 'get_structure_brief',
                'description' => 'The brief for designing or revising a course outline, including the required '
                    . 'Markdown format. Send the result to apply_structure.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'course_id' => $courseId,
                        'feedback' => ['type' => 'string', 'description' => 'Optional changes to make to the existing outline.'],
                    ],
                    'required' => ['course_id'],
                ],
            ],
            [
                'name' => 'apply_structure',
                'description' => 'Replace a course outline with new Markdown. Chapters and pages are matched by '
                    . 'title, so pages that keep their title keep their text.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'course_id' => $courseId,
                        'structure' => ['type' => 'string', 'description' => 'The outline, in CourseForge\'s structure format.'],
                    ],
                    'required' => ['course_id', 'structure'],
                ],
            ],
        ];
    }

    /**
     * Runs one tool and returns the text an MCP client shows the model.
     *
     * @param array<string,mixed> $arguments
     */
    public static function call(string $username, string $name, array $arguments): string
    {
        return match ($name) {
            'list_courses' => self::listCourses($username),
            'get_course' => self::getCourse($username, $arguments),
            'get_page_brief' => self::pageBrief($username, $arguments),
            'write_page' => self::writePage($username, $arguments),
            'get_structure_brief' => self::structureBrief($username, $arguments),
            'apply_structure' => self::applyStructure($username, $arguments),
            default => throw HttpException::notFound('There is no tool called "' . $name . '".'),
        };
    }

    /* ------------------------------------------------------------- handlers */

    private static function listCourses(string $username): string
    {
        $courses = [];
        foreach (Projects::all($username) as $project) {
            $courses[] = [
                'course_id' => (int)$project['id'],
                'name' => (string)$project['name'],
                'topic' => (string)$project['topic'],
                'chapters' => (int)$project['chapter_count'],
                'pages' => (int)$project['page_count'],
                'written' => (int)$project['generated_count'],
                'published' => (int)$project['pushed_count'],
            ];
        }
        if ($courses === []) {
            return 'There are no courses yet. Create one in the CourseForge web interface first.';
        }
        return self::json(['courses' => $courses]);
    }

    /** @param array<string,mixed> $arguments */
    private static function getCourse(string $username, array $arguments): string
    {
        $project = Projects::tree($username, self::courseId($arguments));

        $chapters = [];
        foreach ($project['chapters'] as $chapter) {
            $pages = [];
            foreach ($chapter['pages'] as $page) {
                $pages[] = [
                    'page_id' => $page['id'],
                    'title' => $page['title'],
                    'status' => $page['status'],
                    'written' => $page['has_content'],
                    'words' => $page['word_count'],
                    'published' => $page['pushed'],
                ];
            }
            $chapters[] = [
                'chapter_id' => $chapter['id'],
                'title' => $chapter['title'],
                'description' => $chapter['description'],
                'pages' => $pages,
            ];
        }

        return self::json([
            'course_id' => $project['id'],
            'name' => $project['name'],
            'topic' => $project['topic'],
            'book_title' => $project['book_title'],
            'book_description' => $project['book_desc'],
            'stats' => $project['stats'],
            'chapters' => $chapters,
        ]);
    }

    /** @param array<string,mixed> $arguments */
    private static function pageBrief(string $username, array $arguments): string
    {
        $projectId = self::courseId($arguments);
        $project = Projects::require($username, $projectId);
        $profile = self::profile($username, $project);

        $pageId = isset($arguments['page_id']) ? (int)$arguments['page_id'] : self::nextPending($projectId);
        if ($pageId === 0) {
            return 'Every page of this course has been written. Nothing is pending.';
        }

        $plan = PageGenerator::plan($profile, $project, $pageId, (string)($arguments['feedback'] ?? ''));

        return self::json([
            'course_id' => $projectId,
            'page_id' => $pageId,
            'page_title' => (string)$plan['page']['title'],
            'chapter_title' => (string)$plan['page']['chapter_title'],
            'system_instructions' => $plan['system'],
            'writing_brief' => $plan['user'],
            'next_step' => 'Write the page from the brief above, then call write_page with course_id ' . $projectId
                . ' and page_id ' . $pageId . '.',
        ]);
    }

    /** @param array<string,mixed> $arguments */
    private static function writePage(string $username, array $arguments): string
    {
        $projectId = self::courseId($arguments);
        $project = Projects::require($username, $projectId);

        $pageId = (int)($arguments['page_id'] ?? 0);
        if ($pageId <= 0) {
            throw HttpException::unprocessable('write_page needs a page_id.');
        }
        $content = (string)($arguments['content'] ?? '');
        if (trim($content) === '') {
            throw HttpException::unprocessable('write_page was given no content.');
        }

        $page = Pages::require($projectId, $pageId);
        $stored = PageGenerator::store($project, $page, $content);

        return self::json([
            'stored' => true,
            'page_id' => $pageId,
            'title' => $stored['title'],
            'words' => $stored['word_count'],
            'remaining_unwritten' => self::pendingCount($projectId),
        ]);
    }

    /** @param array<string,mixed> $arguments */
    private static function structureBrief(string $username, array $arguments): string
    {
        $projectId = self::courseId($arguments);
        $project = Projects::require($username, $projectId);
        $profile = self::profile($username, $project);
        $library = Prompt::library($profile);

        $feedback = trim((string)($arguments['feedback'] ?? ''));
        $existing = trim((string)$project['structure_md']);

        return self::json([
            'course_id' => $projectId,
            'topic' => (string)$project['topic'],
            'current_structure' => $existing,
            'requested_changes' => $feedback,
            'system_instructions' => Prompt::join(
                Prompt::slot($library, 'global_system', []),
                Prompt::slot($library, $feedback !== '' && $existing !== '' ? 'overview_refine_system' : 'overview_system', []),
            ),
            'next_step' => 'Return the complete outline in the required Markdown format, then call apply_structure '
                . 'with course_id ' . $projectId . '.',
        ]);
    }

    /** @param array<string,mixed> $arguments */
    private static function applyStructure(string $username, array $arguments): string
    {
        $projectId = self::courseId($arguments);
        $project = Projects::require($username, $projectId);

        $markdown = (string)($arguments['structure'] ?? '');
        if (trim($markdown) === '') {
            throw HttpException::unprocessable('apply_structure was given no structure.');
        }

        $result = Projects::applyStructure($project, $markdown);
        $tree = Projects::tree($username, $projectId);

        return self::json([
            'applied' => true,
            'chapters' => $tree['stats']['chapters'] ?? 0,
            'pages' => $tree['stats']['pages'] ?? 0,
            'removed_pages' => $result['removed_pages'] ?? 0,
            'removed_chapters' => $result['removed_chapters'] ?? 0,
        ]);
    }

    /* ------------------------------------------------------------ internals */

    /** @param array<string,mixed> $arguments */
    private static function courseId(array $arguments): int
    {
        $id = (int)($arguments['course_id'] ?? 0);
        if ($id <= 0) {
            throw HttpException::unprocessable('This tool needs a course_id. Call list_courses to find one.');
        }
        return $id;
    }

    /**
     * The profile the course generates with.
     *
     * A brief needs one because the content details and the prompt overrides
     * live there - not because anything is going to be sent anywhere.
     *
     * @param array<string,mixed> $project
     * @return array<string,mixed>
     */
    private static function profile(string $username, array $project): array
    {
        if ($project['profile_id'] === null) {
            throw HttpException::unprocessable(
                'This course has no profile, so its prompts and content details cannot be resolved. '
                . 'Assign one in the CourseForge web interface first.'
            );
        }
        return Profiles::data($username, (int)$project['profile_id']);
    }

    private static function nextPending(int $projectId): int
    {
        foreach (Pages::ordered($projectId) as $page) {
            if (trim((string)$page['content']) === '') {
                return (int)$page['id'];
            }
        }
        return 0;
    }

    private static function pendingCount(int $projectId): int
    {
        $count = 0;
        foreach (Pages::ordered($projectId) as $page) {
            if (trim((string)$page['content']) === '') {
                $count++;
            }
        }
        return $count;
    }

    /** @param array<string,mixed> $data */
    private static function json(array $data): string
    {
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        // An empty tool result reads to a model as "there is nothing here",
        // which is a worse answer than saying what went wrong.
        return $json !== false ? $json : '{"error":"This result could not be encoded as JSON."}';
    }
}
