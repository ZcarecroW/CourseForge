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
                        'Include the Markdown of every written page. This can be very large on a finished course; '
                        . 'leave it off unless you need the text.'
                    ),
                ],
                required: ['course_id'],
                handler: static fn(Actor $actor, array $args): array => self::getCourse($actor, Args::of($args)),
                readOnly: true,
                idempotent: true,
                // With include_content this is a whole book. Without the hint a
                // client truncates it silently, half way through a page.
                maxResultChars: 400000,
            ),

            new Tool(
                name: 'create_course',
                scope: Scopes::COURSES,
                title: 'Create a course',
                description: 'Creates an empty course from a one-line brief. It has no outline yet: follow this with '
                    . 'generate_structure to have CourseForge design one, or with get_structure_brief and '
                    . 'apply_structure to write one yourself.',
                properties: [
                    'name' => Schema::string('A short name for the course, for the course list.', 'Vue.js from scratch'),
                    'topic' => Schema::text(
                        'The brief the outline is designed from. One or two sentences saying what the course covers, '
                        . 'for whom, and anything that must be assumed - tooling, prerequisites, depth.'
                    ),
                    'profile_id' => Schema::int('The profile to generate with. Omit to use the only one, if there is only one.'),
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

    /** @return array<string,mixed> */
    private static function getCourse(Actor $actor, Args $args): array
    {
        ['project' => $project, 'owner' => $owner] = Resolve::course($actor, $args->id());
        $tree = Projects::tree($owner, (int)$project['id']);
        $withContent = $args->bool('include_content');

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
                if ($withContent && $page['has_content']) {
                    $row['content'] = (string)(Resolve::page($project, (int)$page['id'])['content'] ?? '');
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

        return [
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

        return [
            'course_id' => (int)$project['id'],
            'name' => (string)$project['name'],
            'profile_id' => $profileId,
            'next' => $profileId === null
                ? 'This course has no profile yet. Call list_profiles and then update_course before generating anything.'
                : 'Call generate_structure to have CourseForge design the outline, or get_structure_brief to design it yourself.',
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
