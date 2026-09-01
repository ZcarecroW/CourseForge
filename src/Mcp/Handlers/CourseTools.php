<?php
declare(strict_types=1);

namespace CourseForge\Mcp\Handlers;

use CourseForge\Domain\Details;
use CourseForge\Domain\Profiles;
use CourseForge\Domain\Projects;
use CourseForge\Domain\Transfers;
use CourseForge\Mcp\Args;
use CourseForge\Mcp\Resolve;
use CourseForge\Mcp\Schema;
use CourseForge\Mcp\Scopes;
use CourseForge\Mcp\Tool;
use CourseForge\Security\Access;
use CourseForge\Security\Actor;
use CourseForge\Security\Users;
use CourseForge\Support\Audit;
use CourseForge\Support\HttpException;

/**
 * Courses: what exists, and the shape of one.
 *
 * The entry point to everything else, which is why `list_courses` says more
 * than a list of names: a model that can see how far along each course is can
 * decide what to do next without four more calls. Everything downstream is
 * addressed by the course id these return.
 */
final class CourseTools
{
    /**
     * How much page Markdown one `get_course` answer will carry.
     *
     * Kept below the 400,000 characters the tool asks a client to budget for
     * it, with room left over for the outline itself, which is never truncated
     * - a caller has to be able to see every page that exists in order to ask
     * for the ones this answer did not bring.
     *
     * A course is not bounded by anything: five hundred pages of eight
     * kilobytes is an ordinary finished one and is already twenty times what a
     * client will keep. Without a stop the answer was however large the course
     * was, the client cut it off mid-JSON with no marker, and on a small host
     * PHP ran out of memory building a string nobody could read.
     */
    private const CONTENT_BUDGET = 300000;

    /** @return array<int,Tool> */
    public static function tools(): array
    {
        return [
            new Tool(
                name: 'list_courses',
                scope: Scopes::COURSES,
                title: 'List courses',
                description: 'Every course this account can see, with its progress: how many chapters and pages it '
                    . 'has, how many are written, how many are published, and whether a generation run is open. '
                    . 'An administrator sees every account\'s courses, each marked with its owner.',
                properties: [
                    'owner' => Schema::string('Administrators only: list one account\'s courses instead of all of them.'),
                ],
                required: [],
                handler: static fn(Actor $actor, array $args): array => self::listCourses($actor, Args::of($args)),
                readOnly: true,
                idempotent: true,
            ),

            new Tool(
                name: 'get_course',
                scope: Scopes::COURSES,
                title: 'Read a course',
                description: 'The full outline of one course: every chapter and page with its id, title and status, '
                    . 'plus the book title, the topic, the resolved content details and the publishing state. Use it '
                    . 'to see what still needs writing.',
                properties: [
                    'course_id' => Schema::courseId(),
                    'include_content' => Schema::bool(
                        'Include the Markdown of the written pages. A finished course holds far more text than one '
                        . 'answer can carry, so this fills up to about '
                        . number_format(self::CONTENT_BUDGET) . ' characters of it and then stops, whole pages at '
                        . 'a time; the outline always comes back complete either way. Leave it off unless you need '
                        . 'the text.'
                    ),
                    'content_from_page_id' => Schema::int(
                        'Where the text should start. Omit it for the beginning of the course; to read on past a '
                        . 'previous answer, pass the page id that answer returned as content.next_page_id. Only '
                        . 'meaningful together with include_content.'
                    ),
                ],
                required: ['course_id'],
                handler: static fn(Actor $actor, array $args): array => self::getCourse($actor, Args::of($args)),
                readOnly: true,
                idempotent: true,
                // What this asks the client for, not what the server promises:
                // the annotation raises the size at which a client truncates a
                // tool result, and the answer is built to fit inside it. Whole
                // pages are dropped rather than the last one being cut in half,
                // and `content` says which and where to carry on from.
                maxResultChars: 400000,
            ),

            new Tool(
                name: 'create_course',
                scope: Scopes::COURSES,
                title: 'Create a course',
                description: 'Creates an empty course from a one-line brief. It has no outline yet: follow this with '
                    . 'generate_structure to have CourseForge design one, or with get_structure_brief and '
                    . 'apply_structure to write one yourself. Pass web_research for a subject that moves - a '
                    . 'framework, an API, a product, a standard - and every brief this course hands out afterwards '
                    . 'asks for the current state of it to be established first, starting with the outline.',
                properties: [
                    'name' => Schema::string('A short name for the course, for the course list.', 'Vue.js from scratch'),
                    'topic' => Schema::text(
                        'The brief the outline is designed from. One or two sentences saying what the course covers, '
                        . 'for whom, and anything that must be assumed - tooling, prerequisites, depth.'
                    ),
                    'profile_id' => Schema::int('The profile to generate with. Omit to use the only one, if there is only one.'),
                    'web_research' => Schema::bool(
                        'Switches web research on for the whole course, rather than leaving it to the installation '
                        . 'default. With it on, get_research_brief hands you the assignment for the subject, '
                        . 'get_structure_brief asks for the outline to be designed against what you found, and '
                        . 'every get_page_brief asks the page to be written against it and to cite sources. A '
                        . 'client that can search the web - Claude Code, for instance - does that searching itself, '
                        . 'so it costs nothing here; a course generated by CourseForge\'s own model instead uses '
                        . 'that provider\'s search tool, which does cost. Leave it out to inherit the default.'
                    ),
                    'research_max_searches' => Schema::int(
                        'Roughly how many searches one page may make while it is being written. 0 leaves it to '
                        . 'whoever is doing the searching. Only meaningful with web_research on.',
                        0,
                        20
                    ),
                ],
                required: ['name'],
                handler: static fn(Actor $actor, array $args): array => self::createCourse($actor, Args::of($args)),
            ),

            new Tool(
                name: 'update_course',
                scope: Scopes::COURSES,
                title: 'Change a course',
                description: 'Renames a course, changes its brief, assigns a different profile, or sets the book title '
                    . 'and description that are published to BookStack. Only the fields you give are changed.',
                properties: [
                    'course_id' => Schema::courseId(),
                    'name' => Schema::string('A new name for the course.'),
                    'topic' => Schema::text('A new brief. This does not rewrite an existing outline by itself.'),
                    'profile_id' => Schema::int('A different profile to generate with.'),
                    'book_title' => Schema::string('The title of the published book.'),
                    'book_description' => Schema::text('The description of the published book.'),
                    'bookstack_instance' => Schema::string(
                        "The id of the BookStack instance in the course's profile to publish into. "
                        . 'list_profiles shows which a profile has.'
                    ),
                    'shelf_id' => Schema::int('The BookStack shelf the book belongs on. list_bookstack_shelves finds it.'),
                    'shelf_name' => Schema::string('The name of that shelf, for display.'),
                    'auto_tags' => Schema::bool(
                        'Whether the outline generator may invent tags for the course, written as {{Tag}} markers '
                        . 'in the outline.'
                    ),
                    'tag_pool' => Schema::text(
                        'A comma-separated list of tag names the generator should choose from. Leave empty to let '
                        . 'it choose freely.'
                    ),
                    'tag_pool_strict' => Schema::bool('Whether the generator must stay inside that pool.'),
                ],
                required: ['course_id'],
                handler: static fn(Actor $actor, array $args): array => self::updateCourse($actor, Args::of($args)),
                idempotent: true,
            ),

            new Tool(
                name: 'delete_course',
                scope: Scopes::COURSES,
                title: 'Delete a course',
                description: 'Removes a course and everything in it: chapters, pages, written content, tag links and '
                    . 'run records. This cannot be undone, and it does not remove anything already published to '
                    . 'BookStack. Requires the course name as confirmation.',
                properties: [
                    'course_id' => Schema::courseId(),
                    'confirm_name' => Schema::string(
                        'The exact name of the course, as a confirmation that the right one is being deleted.'
                    ),
                ],
                required: ['course_id', 'confirm_name'],
                handler: static fn(Actor $actor, array $args): array => self::deleteCourse($actor, Args::of($args)),
                destructive: true,
            ),

            new Tool(
                name: 'transfer_course',
                scope: Scopes::ADMIN,
                title: 'Hand a course to another account',
                description: 'Administrators only. Moves a course to another account. Its profile belongs to the old '
                    . 'owner and will not resolve for the new one, so the assignment is cleared and has to be made '
                    . 'again.',
                properties: [
                    'course_id' => Schema::courseId(),
                    'to' => Schema::string('The user name of the account that should own the course.'),
                ],
                required: ['course_id', 'to'],
                handler: static fn(Actor $actor, array $args): array => self::transferCourse($actor, Args::of($args)),
                admin: true,
            ),
        ];
    }

    /* ------------------------------------------------------------- handlers */

    /** @return array<string,mixed> */
    private static function listCourses(Actor $actor, Args $args): array
    {
        $owner = Access::listingOwner($actor, $args->str('owner'));
        $courses = Projects::all($owner);

        $out = [];
        foreach ($courses as $course) {
            $row = [
                'course_id' => $course['id'],
                'name' => $course['name'],
                'topic' => $course['topic'],
                'chapters' => $course['chapter_count'],
                'pages' => $course['page_count'],
                'written' => $course['generated_count'],
                'published' => $course['pushed_count'],
                'open_runs' => $course['open_runs'],
                'has_outline' => $course['chapter_count'] > 0,
            ];
            if ($actor->isAdmin()) {
                $row['owner'] = $course['owner'];
            }
            $out[] = $row;
        }

        return [
            'courses' => $out,
            'count' => count($out),
            'hint' => $out === []
                ? 'There are no courses yet. Call create_course to make one.'
                : 'Call get_course with a course_id to see the outline.',
        ];
    }

    /**
     * One course, and as much of its text as an answer can hold.
     *
     * The outline is always whole: every chapter and every page, whatever was
     * asked for. Only the Markdown is windowed, because only the Markdown has
     * no ceiling - and it is windowed a page at a time, never mid-page, so
     * nothing a caller reads is half a sentence with no sign that it stopped.
     * When the window closes early the answer says so and names the page to
     * carry on from, which is the difference between an incomplete result and
     * a misleading one.
     *
     * @return array<string,mixed>
     */
    private static function getCourse(Actor $actor, Args $args): array
    {
        ['project' => $project, 'owner' => $owner] = Resolve::course($actor, $args->id());
        $tree = Projects::tree($owner, (int)$project['id']);
        $withContent = $args->bool('include_content');
        $from = $args->optionalId('content_from_page_id');

        // False until the window opens, so a `content_from_page_id` naming a
        // page this course does not have shows up in the answer as "nothing
        // matched" rather than quietly reading as "start at the beginning".
        $started = $withContent && $from === null;
        $used = 0;
        $included = 0;
        $before = 0;
        $after = 0;
        $nextPageId = null;

        $chapters = [];
        foreach ($tree['chapters'] as $chapter) {
            $pages = [];
            foreach ($chapter['pages'] as $page) {
                $row = [
                    'page_id' => $page['id'],
                    'title' => $page['title'],
                    'status' => $page['status'],
                    'written' => $page['has_content'],
                    'words' => $page['word_count'],
                    'published' => $page['pushed'],
                ];

                if ($withContent && (int)$page['id'] === $from) {
                    $started = true;
                }

                if ($withContent && $page['has_content']) {
                    if (!$started) {
                        $row['content_omitted'] = 'before the window';
                        $before++;
                    } else {
                        // Read only once the window is open: the whole point of
                        // the window is that the pages outside it are never
                        // fetched, let alone serialised.
                        $text = (string)(Resolve::page($project, (int)$page['id'])['content'] ?? '');

                        // The first page of a window always goes in, however
                        // long it is. An answer that carried no text at all and
                        // named itself as the place to resume would leave a
                        // caller looping over the same page for ever.
                        if ($used === 0 || $used + strlen($text) <= self::CONTENT_BUDGET) {
                            $row['content'] = $text;
                            $used += strlen($text);
                            $included++;
                        } else {
                            $row['content_omitted'] = 'no room left in this answer';
                            $after++;
                            $nextPageId ??= (int)$page['id'];
                        }
                    }
                }

                $pages[] = $row;
            }
            $chapters[] = [
                'chapter_id' => $chapter['id'],
                'title' => $chapter['title'],
                'description' => $chapter['description'],
                'pages' => $pages,
            ];
        }

        $result = [
            'course_id' => $tree['id'],
            'name' => $tree['name'],
            'topic' => $tree['topic'],
            'owner' => $owner,
            'profile_id' => $project['profile_id'] === null ? null : (int)$project['profile_id'],
            'book_title' => $tree['book_title'],
            'book_description' => $tree['book_desc'],
            'stats' => $tree['stats'],
            'details' => Details::resolve(Details::decode((string)$project['settings'])),
            'chapters' => $chapters,
        ];

        if ($withContent) {
            $result['content'] = self::contentWindow($started, $from, $used, $included, $before, $after, $nextPageId);
        }
        return $result;
    }

    /**
     * What the caller got, what it did not, and how to ask for the rest.
     *
     * Present on every answer that asked for text, complete or not. A block
     * that only appeared when something had been left out would be one a
     * caller learns to stop looking for.
     *
     * @return array<string,mixed>
     */
    private static function contentWindow(
        bool $started,
        ?int $from,
        int $characters,
        int $included,
        int $before,
        int $after,
        ?int $nextPageId,
    ): array {
        if (!$started) {
            return [
                'complete' => false,
                'pages_included' => 0,
                'characters' => 0,
                'note' => 'No page in this course has the id ' . (int)$from . ', so no text was included. '
                    . 'Call get_course again without content_from_page_id to start at the beginning.',
            ];
        }

        $window = [
            'complete' => $after === 0,
            'pages_included' => $included,
            'characters' => $characters,
            'limit' => self::CONTENT_BUDGET,
            'pages_before_window' => $before,
            'pages_after_window' => $after,
            'next_page_id' => $nextPageId,
        ];

        if ($after > 0) {
            $window['note'] = 'This answer holds ' . $included . ' written page(s) and stopped there - '
                . $after . ' more have text that did not fit. Every page is still listed in the outline above, '
                . 'marked content_omitted. Call get_course again with content_from_page_id ' . (int)$nextPageId
                . ' to carry on, or get_page for one page on its own.';
        } elseif ($characters > self::CONTENT_BUDGET) {
            // Only the first page of a window can push the count past the
            // limit, and it is let through on purpose - see getCourse(). Saying
            // so beats leaving a caller to wonder which of the two numbers is
            // wrong.
            $window['note'] = 'Every written page in this course, in full. This one is over the usual limit '
                . 'because a single page is larger than the whole of it, and half a page is worse than a long '
                . 'one - your client may cut the end of this answer off.';
        } elseif ($before > 0) {
            $window['note'] = 'The last ' . $included . ' written page(s) of this course, starting at the page '
                . 'asked for. The ' . $before . ' before it are marked content_omitted and were not read.';
        } else {
            $window['note'] = 'Every written page in this course, in full.';
        }
        return $window;
    }

    /** @return array<string,mixed> */
    private static function createCourse(Actor $actor, Args $args): array
    {
        $name = $args->requiredStr('name');
        $topic = $args->raw('topic');

        $profileId = $args->optionalId('profile_id');
        if ($profileId === null) {
            // A course with no profile cannot generate anything, and an account
            // with exactly one profile always meant that one - so choosing it
            // saves a round trip without ever guessing between two.
            $profiles = Profiles::all($actor->username);
            if (count($profiles) === 1) {
                $profileId = (int)$profiles[0]['id'];
            }
        } else {
            $profile = Access::profile($actor, $profileId);
            $actor->requireReach((string)$profile['username'], 'that profile');
        }

        $project = Projects::create($actor->username, $name, $topic, $profileId);
        Audit::record($actor->username, 'course.create', $name, 'via MCP', 'mcp');

        // Written through the same detail patch the browser uses, so the value
        // lands in the same place with the same inheritance and shows up on the
        // Details tab exactly as if somebody had ticked the box there. Only
        // what was actually asked for is written: an argument that was not sent
        // leaves the setting inheriting, which is a different state from being
        // switched off.
        $research = $args->has('web_research') ? $args->bool('web_research') : null;
        $searches = $args->has('research_max_searches') ? max(0, $args->int('research_max_searches', 0)) : null;

        if ($research !== null || $searches !== null) {
            Projects::patchDetails(
                $actor->username,
                (int)$project['id'],
                $research === null ? [] : ['web_research' => $research ? Details::ON : Details::OFF],
                $searches === null ? [] : ['research_max_searches' => $searches],
            );
            $project = Projects::require($actor->username, (int)$project['id']);
        }

        return [
            'course_id' => (int)$project['id'],
            'name' => (string)$project['name'],
            'profile_id' => $profileId,
            'web_research' => (bool)(Details::resolve(Projects::settings($project))['features']['web_research'] ?? false),
            'research' => $research === true
                ? 'Web research is on for this course. Call get_research_brief to be handed the assignment for the '
                    . 'subject, search the web with your own tools, and send the findings to store_research. That is '
                    . 'researched once and then read by the outline and by every page, so a course about something '
                    . 'that moves stays current without a search per page.'
                : '',
            // A course with no profile used to be told to go and get one
            // "before generating anything", which read as though nothing could
            // happen without it - and then the very next call, get_structure_brief,
            // refused for the same reason. Both halves of that are now wrong:
            // the brief tools need no profile, and writing the course yourself
            // is the cheap path rather than the fallback. Say what to do next
            // instead of what is missing.
            'next' => 'Call get_structure_brief to be handed the instructions and design the outline yourself, '
                . 'then apply_structure to store it. That needs no AI account and spends nothing.'
                . ($profileId === null
                    ? ' This course has no profile, which it only needs if you want CourseForge to do the '
                        . 'generating itself (generate_structure, generate_page, start_run) or to publish into '
                        . 'BookStack. Assign one later with update_course if you do.'
                    : ' Or call generate_structure to have CourseForge design it with the course profile\'s own '
                        . 'AI account, which spends credit on it.'),
            'next_tool' => 'get_next_step',
        ];
    }

    /** @return array<string,mixed> */
    private static function updateCourse(Actor $actor, Args $args): array
    {
        ['project' => $project, 'owner' => $owner] = Resolve::course($actor, $args->id());

        $fields = [];
        if ($args->has('name')) {
            $fields['name'] = $args->requiredStr('name');
        }
        if ($args->has('topic')) {
            $fields['topic'] = $args->raw('topic');
        }
        if ($args->has('book_title')) {
            $fields['book_title'] = $args->str('book_title');
        }
        if ($args->has('book_description')) {
            $fields['book_desc'] = $args->raw('book_description');
        }
        // The publishing and auto-tagging fields. Without them a course created
        // through MCP could never be published through MCP, because
        // publish_course refuses a course with no BookStack instance and there
        // was no tool that could set one.
        if ($args->has('bookstack_instance')) {
            $fields['bs_instance_id'] = $args->str('bookstack_instance');
        }
        if ($args->has('shelf_id')) {
            $fields['shelf_id'] = $args->intOrNull('shelf_id');
        }
        if ($args->has('shelf_name')) {
            $fields['shelf_name'] = $args->str('shelf_name');
        }
        if ($args->has('auto_tags')) {
            $fields['auto_tags'] = $args->bool('auto_tags') ? 1 : 0;
        }
        if ($args->has('tag_pool')) {
            $fields['tag_pool'] = $args->str('tag_pool');
        }
        if ($args->has('tag_pool_strict')) {
            $fields['tag_pool_strict'] = $args->bool('tag_pool_strict') ? 1 : 0;
        }
        if ($args->has('profile_id')) {
            $profileId = $args->id('profile_id');
            // The profile has to belong to the course's owner, not to whoever
            // is making the change: the course generates as its owner.
            $profile = Profiles::find($owner, $profileId);
            if ($profile === null) {
                throw HttpException::unprocessable(
                    'Profile ' . $profileId . ' does not belong to ' . $owner . ', who owns this course.'
                );
            }
            $fields['profile_id'] = $profileId;
        }

        if ($fields === []) {
            throw HttpException::unprocessable('Nothing to change. Give at least one field.');
        }

        $updated = Projects::update($owner, (int)$project['id'], $fields);

        return [
            'course_id' => (int)$updated['id'],
            'name' => (string)$updated['name'],
            'topic' => (string)$updated['topic'],
            'book_title' => (string)$updated['book_title'],
            'profile_id' => $updated['profile_id'] === null ? null : (int)$updated['profile_id'],
            'changed' => array_keys($fields),
        ];
    }

    /** @return array<string,mixed> */
    private static function deleteCourse(Actor $actor, Args $args): array
    {
        ['project' => $project, 'owner' => $owner] = Resolve::course($actor, $args->id());

        // The name has to match. A model that has just listed twelve courses is
        // one transposed digit away from deleting the wrong one, and there is
        // no undo behind this.
        if ($args->requiredStr('confirm_name') !== (string)$project['name']) {
            throw HttpException::unprocessable(
                'confirm_name does not match. The course is called "' . $project['name'] . '".'
            );
        }

        Projects::delete($owner, (int)$project['id']);
        Audit::record($actor->username, 'course.delete', (string)$project['name'], 'via MCP', 'mcp');

        return ['deleted' => true, 'course_id' => (int)$project['id'], 'name' => (string)$project['name']];
    }

    /** @return array<string,mixed> */
    private static function transferCourse(Actor $actor, Args $args): array
    {
        $actor->requireAdmin();
        ['project' => $project] = Resolve::course($actor, $args->id());

        // Domain\Transfers, not two lines of SQL here: a course carries a
        // profile, a run history and a set of tag links that are all owned per
        // account, and a transfer that moves only the course leaves the new
        // owner unable to see their own runs or edit their own tags.
        $result = Transfers::course((int)$project['id'], $args->requiredStr('to'));

        Audit::record(
            $actor->username,
            'course.transfer',
            (string)$project['name'],
            'from ' . $result['from'] . ' to ' . $result['to'],
            'mcp'
        );

        return ['transferred' => true, 'course_id' => (int)$project['id']] + $result;
    }
}
