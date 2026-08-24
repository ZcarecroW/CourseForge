<?php
declare(strict_types=1);

namespace CourseForge\Mcp\Handlers;

use CourseForge\Domain\Chapters;
use CourseForge\Domain\Pages;
use CourseForge\Domain\Projects;
use CourseForge\Domain\Tags;
use CourseForge\Mcp\Args;
use CourseForge\Mcp\Resolve;
use CourseForge\Mcp\Schema;
use CourseForge\Mcp\Scopes;
use CourseForge\Mcp\Tool;
use CourseForge\Security\Access;
use CourseForge\Security\Actor;
use CourseForge\Support\Audit;
use CourseForge\Support\HttpException;

/**
 * Tags: the account's library, and what is tagged with what.
 *
 * A tag is a name and a value that belongs to an account rather than to a
 * course, and the same tag is attached to as many courses, chapters and pages
 * as the person likes. When a course is pushed, whatever applies to an item
 * becomes that item's BookStack tags, so this group is how a model makes a
 * finished book searchable - "level: beginner" on the whole course, "vue" on
 * one chapter, "deprecated" on the single page that needs it.
 *
 * Two behaviours are worth knowing before calling anything here. The first is
 * inheritance: a link marked as inheriting reaches everything below it, so one
 * tag on the course reaches every page without eight hundred links. The second
 * is that the outline generator writes tags of its own, from `{{Tag}}` markers
 * it puts in the structure - which means a course can arrive already tagged,
 * sometimes wrongly. A person can switch one of those off, and switching it off
 * has to stick: the next regenerated outline is never allowed to turn it back
 * on. That is why there is both a detach and a switch, and why the switch is
 * the right answer for a tag CourseForge keeps proposing.
 *
 * The library itself belongs to an account, but a tag attached to a course
 * belongs to that course's owner. An administrator tagging somebody else's
 * course works in the owner's library throughout - never in their own.
 */
final class TagTools
{
    /** The vocabulary for "what is being tagged". "project" is the stored name for "course". */
    private const TARGETS = ['course', 'project', 'chapter', 'page'];

    /** @return array<int,Tool> */
    public static function tools(): array
    {
        return [
            new Tool(
                name: 'list_tags',
                scope: Scopes::TAGS,
                title: 'List the tag library',
                description: 'One account\'s tag library: every tag with its name, its value, and how many courses, '
                    . 'chapters and pages it is attached to. Tags belong to an account, not to a course, so the same '
                    . 'tag is reused everywhere. An administrator sees every account\'s tags, each marked with its '
                    . 'owner. Costs nothing.',
                properties: [
                    'owner' => Schema::string('Administrators only: read one account\'s library instead of all of them.'),
                ],
                required: [],
                handler: static fn(Actor $actor, array $args): array => self::listTags($actor, Args::of($args)),
                readOnly: true,
                idempotent: true,
            ),

            new Tool(
                name: 'create_tag',
                scope: Scopes::TAGS,
                title: 'Add a tag to the library',
                description: 'Adds a tag to your library. It is attached to nothing yet, so this is only worth calling '
                    . 'when you want the tag to exist before it is used - attach_tag creates a missing tag itself. A '
                    . 'name already in the library is refused; change that one with update_tag instead. Costs nothing.',
                properties: [
                    'name' => Schema::string('The tag name, as it will appear in BookStack.', 'level'),
                    'value' => Schema::string(
                        'The tag value. BookStack shows a tag as "name: value"; leave it out for a bare label.',
                        'beginner'
                    ),
                ],
                required: ['name'],
                handler: static fn(Actor $actor, array $args): array => self::createTag($actor, Args::of($args)),
            ),

            new Tool(
                name: 'update_tag',
                scope: Scopes::TAGS,
                title: 'Rename or revalue a tag',
                description: 'Changes a tag\'s name or value in the library, which changes it everywhere it is '
                    . 'attached at once. Anything already in BookStack keeps the old tag until that course is pushed '
                    . 'again, so follow this with publish_course for any course that is live. Only the fields you '
                    . 'give are changed. Costs nothing.',
                properties: [
                    'tag_id' => Schema::int('The tag to change, as returned by list_tags.'),
                    'name' => Schema::string('A new name.'),
                    'value' => Schema::string('A new value. Send an empty string to clear it.'),
                ],
                required: ['tag_id'],
                handler: static fn(Actor $actor, array $args): array => self::updateTag($actor, Args::of($args)),
                idempotent: true,
            ),

            new Tool(
                name: 'delete_tag',
                scope: Scopes::TAGS,
                title: 'Delete a tag',
                description: 'Removes a tag from the library and from every course, chapter and page it is attached '
                    . 'to. This cannot be undone, and it does not remove the tag from anything already published - '
                    . 'push those courses again afterwards. Requires the tag name as confirmation. To stop one tag '
                    . 'being published from one place without losing it everywhere, use set_tag_link with enabled '
                    . 'false instead. Costs nothing.',
                properties: [
                    'tag_id' => Schema::int('The tag to delete, as returned by list_tags.'),
                    'confirm_name' => Schema::string(
                        'The exact name of the tag, as a confirmation that the right one is being deleted.'
                    ),
                ],
                required: ['tag_id', 'confirm_name'],
                handler: static fn(Actor $actor, array $args): array => self::deleteTag($actor, Args::of($args)),
                destructive: true,
            ),

            new Tool(
                name: 'get_course_tags',
                scope: Scopes::TAGS,
                title: 'Read every tag in a course',
                description: 'Every tag in play anywhere in one course: what is attached at the course, at each '
                    . 'chapter and at each page, which of those inherit downwards, which CourseForge created itself '
                    . 'from {{Tag}} markers in the outline, and which are switched off. Each item also carries its '
                    . 'effective tags - what would actually be published for it, its own plus everything inherited '
                    . 'from above. Items with no tags at all are left out. Costs nothing: no model is called.',
                properties: [
                    'course_id' => Schema::courseId(),
                ],
                required: ['course_id'],
                handler: static fn(Actor $actor, array $args): array => self::courseTags($actor, Args::of($args)),
                readOnly: true,
                idempotent: true,
            ),

            new Tool(
                name: 'attach_tag',
                scope: Scopes::TAGS,
                title: 'Attach a tag',
                description: 'Attaches a tag to the course, to one chapter or to one page, adding it to the owner\'s '
                    . 'library first if it is not there yet. Set inherit to have it apply to everything below as '
                    . 'well - from the course to every chapter and page, from a chapter to its own pages - which is '
                    . 'how a course-wide tag is done without one link per page. Attaching by hand also turns an '
                    . 'automatic tag into a manual one and switches it back on. Call publish_course afterwards to '
                    . 'push the change to BookStack. Costs nothing.',
                properties: [
                    'course_id' => Schema::courseId(),
                    'name' => Schema::string('The tag name. A tag of this name is created if the library has none.', 'level'),
                    'value' => Schema::string(
                        'The tag value. This is the value in the library, so setting it here changes the tag '
                        . 'everywhere it is used. Leave it out to keep whatever the tag already has.',
                        'beginner'
                    ),
                    'target' => Schema::enum(
                        'What to attach it to: the whole course (the default), one chapter, or one page.',
                        self::TARGETS
                    ),
                    'target_id' => Schema::int('The chapter id or page id, when target is chapter or page.'),
                    'inherit' => Schema::bool(
                        'Also apply this tag to everything below the item. Ignored on a page, which has nothing below it.'
                    ),
                ],
                required: ['course_id', 'name'],
                handler: static fn(Actor $actor, array $args): array => self::attachTag($actor, Args::of($args)),
                idempotent: true,
            ),

            new Tool(
                name: 'detach_tag',
                scope: Scopes::TAGS,
                title: 'Detach a tag',
                description: 'Removes one link between a tag and a course, chapter or page. The tag stays in the '
                    . 'library and stays attached to everything else. A tag CourseForge created from a {{Tag}} marker '
                    . 'comes back the next time the outline is generated, so for one of those use set_tag_link with '
                    . 'enabled false - that decision is kept. Name the tag with exactly one of tag_id and name, '
                    . 'whichever you have to hand. Costs nothing.',
                properties: [
                    'course_id' => Schema::courseId(),
                    'tag_id' => Schema::int(
                        'The tag to detach, as returned by get_course_tags. Give this or name, and not both.'
                    ),
                    'name' => Schema::string(
                        'The tag to detach, by name, looked up in the library of the account that owns the course - '
                        . 'the same name attach_tag takes. Give this or tag_id, and not both.',
                        'level'
                    ),
                    'target' => Schema::enum(
                        'Where to detach it from: the whole course (the default), one chapter, or one page.',
                        self::TARGETS
                    ),
                    'target_id' => Schema::int('The chapter id or page id, when target is chapter or page.'),
                ],
                required: ['course_id'],
                handler: static fn(Actor $actor, array $args): array => self::detachTag($actor, Args::of($args)),
                idempotent: true,
            ),

            new Tool(
                name: 'set_tag_link',
                scope: Scopes::TAGS,
                title: 'Change how a tag is attached',
                description: 'Changes an existing link without removing it: whether it inherits downwards, and '
                    . 'whether it is switched on. A switched-off link is kept but ignored everywhere - it reaches no '
                    . 'writing brief and no BookStack push - which is how an automatic tag is stopped from being '
                    . 'published without deleting it, and unlike a detach it survives a regenerated outline. Give at '
                    . 'least one of inherit and enabled. The tag has to be attached to that item already; use '
                    . 'attach_tag if it is not. Name the tag with exactly one of tag_id and name, whichever you have '
                    . 'to hand. Costs nothing.',
                properties: [
                    'course_id' => Schema::courseId(),
                    'tag_id' => Schema::int(
                        'The tag whose link is being changed, as returned by get_course_tags. Give this or name, and '
                        . 'not both.'
                    ),
                    'name' => Schema::string(
                        'The tag whose link is being changed, by name, looked up in the library of the account that '
                        . 'owns the course. Give this or tag_id, and not both.',
                        'level'
                    ),
                    'target' => Schema::enum(
                        'Which link: the one on the course (the default), on one chapter, or on one page.',
                        self::TARGETS
                    ),
                    'target_id' => Schema::int('The chapter id or page id, when target is chapter or page.'),
                    'inherit' => Schema::bool(
                        'Whether the tag also applies to everything below. Cannot be set on a page.'
                    ),
                    'enabled' => Schema::bool(
                        'Whether the link counts at all. False keeps it but hides it from every brief and every push.'
                    ),
                ],
                required: ['course_id'],
                handler: static fn(Actor $actor, array $args): array => self::setTagLink($actor, Args::of($args)),
                idempotent: true,
            ),
        ];
    }

    /* ------------------------------------------------------------- handlers */

    /** @return array<string,mixed> */
    private static function listTags(Actor $actor, Args $args): array
    {
        $owner = Access::listingOwner($actor, $args->str('owner'));

        $tags = [];
        foreach (Tags::all($owner) as $tag) {
            $row = [
                'tag_id' => (int)$tag['id'],
                'name' => (string)$tag['name'],
                'value' => (string)$tag['value'],
                'attached_to' => (int)$tag['usage_count'],
            ];
            if ($actor->isAdmin()) {
                $row['owner'] = (string)$tag['owner'];
            }
            $tags[] = $row;
        }

        return [
            // Null where an administrator is looking at every account at once,
            // which is what an omitted owner now means. The rows carry theirs.
            'owner' => $owner,
            'tags' => $tags,
            'count' => count($tags),
            'hint' => $tags === []
                ? 'There are no tags yet. create_tag adds one, or attach_tag creates and attaches in a single call.'
                : 'attach_tag attaches one of these to a course, a chapter or a page; get_course_tags shows what a '
                    . 'course already carries.',
        ];
    }

    /** @return array<string,mixed> */
    private static function createTag(Actor $actor, Args $args): array
    {
        $tag = Tags::create($actor->username, $args->requiredStr('name'), $args->str('value'));

        return [
            'tag_id' => (int)$tag['id'],
            'name' => (string)$tag['name'],
            'value' => (string)$tag['value'],
            'owner' => $actor->username,
            'next' => 'The tag exists but is attached to nothing. Call attach_tag with a course_id to use it.',
        ];
    }

    /** @return array<string,mixed> */
    private static function updateTag(Actor $actor, Args $args): array
    {
        $tagId = $args->id('tag_id');

        // The tag belongs to whoever it belongs to: an administrator editing
        // somebody else's tag edits it in that person's library, not their own.
        $owner = (string)Access::tag($actor, $tagId)['username'];
        $current = self::libraryTag($owner, $tagId);

        if (!$args->has('name') && !$args->has('value')) {
            throw HttpException::unprocessable('Nothing to change. Give a name, a value, or both.');
        }

        $name = $args->has('name') ? $args->requiredStr('name') : (string)$current['name'];
        $value = $args->has('value') ? $args->str('value') : (string)$current['value'];

        $tag = Tags::update($owner, $tagId, $name, $value);
        $usage = (int)$current['attached_to'];

        return [
            'tag_id' => (int)$tag['id'],
            'name' => (string)$tag['name'],
            'value' => (string)$tag['value'],
            'previous_name' => (string)$current['name'],
            'previous_value' => (string)$current['value'],
            'owner' => $owner,
            'attached_to' => $usage,
            'next' => $usage > 0
                ? 'This tag is attached to ' . $usage . ' item(s). Anything already in BookStack keeps the old tag '
                    . 'until publish_course is called for that course.'
                : 'This tag is attached to nothing yet. Call attach_tag to use it.',
        ];
    }

    /** @return array<string,mixed> */
    private static function deleteTag(Actor $actor, Args $args): array
    {
        $tagId = $args->id('tag_id');
        $owner = (string)Access::tag($actor, $tagId)['username'];
        $tag = self::libraryTag($owner, $tagId);

        // The name has to match. Deleting a tag takes it off every course it
        // was ever attached to, and there is no undo behind this.
        if ($args->requiredStr('confirm_name') !== (string)$tag['name']) {
            throw HttpException::unprocessable(
                'confirm_name does not match. Tag ' . $tagId . ' is called "' . $tag['name'] . '".'
            );
        }

        $usage = (int)$tag['attached_to'];
        Tags::delete($owner, $tagId);
        Audit::record($actor->username, 'tag.delete', (string)$tag['name'], $usage . ' link(s), owner ' . $owner, 'mcp');

        return [
            'deleted' => true,
            'tag_id' => $tagId,
            'name' => (string)$tag['name'],
            'owner' => $owner,
            'links_removed' => $usage,
            'next' => $usage > 0
                ? 'Anything already published keeps this tag until publish_course is called for that course.'
                : 'Nothing else to do: the tag was attached to nothing.',
        ];
    }

    /** @return array<string,mixed> */
    private static function courseTags(Actor $actor, Args $args): array
    {
        ['project' => $project, 'owner' => $owner] = Resolve::course($actor, $args->id());
        $projectId = (int)$project['id'];
        $resolved = Tags::resolved($projectId);

        $byChapter = [];
        foreach (Pages::ordered($projectId) as $page) {
            $byChapter[(int)$page['chapter_id']][] = $page;
        }

        $untaggedPages = 0;
        $chapters = [];
        foreach (Chapters::ordered($projectId) as $chapter) {
            $chapterId = (int)$chapter['id'];

            $pages = [];
            foreach ($byChapter[$chapterId] ?? [] as $page) {
                $pageId = (int)$page['id'];
                $tags = self::tagsOf($resolved, 'page', $pageId);
                if ($tags['attached'] === [] && $tags['effective'] === []) {
                    $untaggedPages++;
                    continue;
                }
                $pages[] = ['page_id' => $pageId, 'title' => (string)$page['title'], ...$tags];
            }

            $tags = self::tagsOf($resolved, 'chapter', $chapterId);
            if ($tags['attached'] === [] && $tags['effective'] === [] && $pages === []) {
                continue;
            }
            $chapters[] = [
                'chapter_id' => $chapterId,
                'title' => (string)$chapter['title'],
                ...$tags,
                'pages' => $pages,
            ];
        }

        $links = 0;
        $automatic = 0;
        $switchedOff = 0;
        foreach ($resolved['own'] as $entities) {
            foreach ($entities as $list) {
                foreach ($list as $tag) {
                    $links++;
                    $automatic += $tag['auto'] ? 1 : 0;
                    $switchedOff += $tag['enabled'] ? 0 : 1;
                }
            }
        }

        return [
            'course_id' => $projectId,
            'owner' => $owner,
            'course' => [
                'title' => (string)$project['name'],
                ...self::tagsOf($resolved, 'project', $projectId),
            ],
            'chapters' => $chapters,
            'summary' => [
                'links' => $links,
                'automatic' => $automatic,
                'switched_off' => $switchedOff,
                'pages_with_no_tags' => $untaggedPages,
            ],
            'note' => '"attached" is what is linked directly to that item; "effective" is what would be published '
                . 'for it, its own tags plus everything inheriting from above. An automatic tag came from a {{Tag}} '
                . 'marker the outline generator wrote. A switched-off link is kept but ignored everywhere.',
            'next_step' => 'attach_tag adds one, detach_tag removes a link, set_tag_link switches an automatic tag '
                . 'off without deleting it, and publish_course pushes the result to BookStack.',
        ];
    }

    /** @return array<string,mixed> */
    private static function attachTag(Actor $actor, Args $args): array
    {
        // Everything below runs in the course owner's library, whoever is asking.
        ['project' => $project, 'owner' => $owner] = Resolve::course($actor, $args->id());

        $name = $args->requiredStr('name');
        $value = $args->str('value');
        [$type, $entityId] = self::entity($project, $args);
        $inherit = $args->bool('inherit');

        // ensure() is called for its return value - attach() does the same work
        // internally but hands nothing back, and the answer needs the tag id.
        $isNew = Tags::byName($owner, $name) === null;
        $tag = Tags::ensure($owner, $name, $value);

        Tags::attach($owner, $project, $type, $entityId, (string)$tag['name'], $value, $inherit);
        Projects::touch((int)$project['id']);

        $link = self::linkState((int)$project['id'], $type, $entityId, (int)$tag['id']);

        $result = [
            'attached' => true,
            'course_id' => (int)$project['id'],
            'tag_id' => (int)$tag['id'],
            'name' => (string)$tag['name'],
            'value' => (string)$tag['value'],
            'created_in_library' => $isNew,
            'target' => self::label($type),
            'target_id' => $entityId,
            'inherit' => (bool)($link['inherit'] ?? false),
        ];
        if ($inherit && $type === 'page') {
            $result['note'] = 'inherit was ignored: a page has nothing below it.';
        }
        $result['next_step'] = 'get_course_tags shows the whole course; publish_course pushes the change to BookStack.';

        return $result;
    }

    /** @return array<string,mixed> */
    private static function detachTag(Actor $actor, Args $args): array
    {
        ['project' => $project, 'owner' => $owner] = Resolve::course($actor, $args->id());

        $tagId = self::tagId($owner, $args);
        [$type, $entityId] = self::entity($project, $args);
        $link = self::linkState((int)$project['id'], $type, $entityId, $tagId);

        Tags::detach($owner, $project, $type, $entityId, $tagId);
        Projects::touch((int)$project['id']);

        return [
            'detached' => true,
            'course_id' => (int)$project['id'],
            'tag_id' => $tagId,
            'target' => self::label($type),
            'target_id' => $entityId,
            'was_attached' => $link !== null,
            'next_step' => ($link['auto'] ?? false)
                ? 'That link was automatic, so a regenerated outline will recreate it. To keep it away for good, '
                    . 'attach it again and call set_tag_link with enabled false.'
                : 'get_course_tags shows what is left; publish_course pushes the change to BookStack.',
        ];
    }

    /** @return array<string,mixed> */
    private static function setTagLink(Actor $actor, Args $args): array
    {
        ['project' => $project, 'owner' => $owner] = Resolve::course($actor, $args->id());

        $tagId = self::tagId($owner, $args);
        [$type, $entityId] = self::entity($project, $args);

        if (!$args->has('inherit') && !$args->has('enabled')) {
            throw HttpException::unprocessable(
                'Nothing to change. Give inherit, enabled, or both. To remove the link entirely, call detach_tag.'
            );
        }
        // Checked before either write, so a call that asks for both changes on a
        // page cannot leave one of them applied and then fail on the other.
        if ($args->has('inherit') && $type === 'page') {
            throw HttpException::unprocessable(
                'A page has nothing below it, so inherit cannot be set on a page link. Set inherit on the chapter '
                . 'or on the course instead.'
            );
        }

        $changed = [];
        if ($args->has('inherit')) {
            Tags::setInherit($owner, $project, $type, $entityId, $tagId, $args->bool('inherit'));
            $changed[] = 'inherit';
        }
        if ($args->has('enabled')) {
            Tags::setEnabled($owner, $project, $type, $entityId, $tagId, $args->bool('enabled'));
            $changed[] = 'enabled';
        }
        Projects::touch((int)$project['id']);

        $link = self::linkState((int)$project['id'], $type, $entityId, $tagId);

        return [
            'updated' => true,
            'course_id' => (int)$project['id'],
            'tag_id' => $tagId,
            'name' => (string)($link['name'] ?? ''),
            'target' => self::label($type),
            'target_id' => $entityId,
            'changed' => $changed,
            'inherit' => (bool)($link['inherit'] ?? false),
            'enabled' => (bool)($link['enabled'] ?? false),
            'automatic' => (bool)($link['auto'] ?? false),
            'next_step' => ($link['enabled'] ?? true)
                ? 'publish_course pushes the change to BookStack.'
                : 'This link is now ignored everywhere, and a regenerated outline will leave it switched off. '
                    . 'publish_course removes the tag from BookStack.',
        ];
    }

    /* -------------------------------------------------------------- helpers */

    /**
     * Which tag a call is about, by id or by name.
     *
     * attach_tag has always taken a name, because the tag it attaches may not
     * exist yet. Detaching and switching one off had to be done by id, which
     * meant a round trip through get_course_tags to undo what one call had just
     * done. Both are accepted here, and exactly one of them: given both, there
     * is no honest way to choose when they disagree.
     *
     * The lookup runs in the course OWNER's library. An administrator detaching
     * a tag from somebody else's course is naming a tag in that person's
     * library, and the same name in their own library is a different row.
     */
    private static function tagId(string $owner, Args $args): int
    {
        $hasId = $args->has('tag_id');
        $name = $args->str('name');

        if ($hasId && $name !== '') {
            throw HttpException::unprocessable(
                'Give either tag_id or name, not both. They can name different tags, and there is no sensible way to '
                . 'choose between them.'
            );
        }
        if ($hasId) {
            return $args->id('tag_id');
        }
        if ($name === '') {
            throw HttpException::unprocessable(
                'Exactly one of tag_id and name is required. get_course_tags gives the id of everything attached to '
                . 'this course; the name is the one it carries in the library.'
            );
        }

        $tag = Tags::byName($owner, $name);
        if ($tag === null) {
            throw HttpException::unprocessable(
                'There is no tag called "' . $name . '" in the library of ' . $owner . ', who owns this course. '
                . 'list_tags shows what is in it, and get_course_tags shows what this course carries.'
            );
        }
        return (int)$tag['id'];
    }

    /**
     * The item a call is about, validated against the course.
     *
     * `resolveEntity` also checks that the chapter or page really belongs to
     * this course, which is what stops a target_id from another course reaching
     * the link table.
     *
     * @param array<string,mixed> $project
     * @return array{0:string,1:int} [entity type, entity id]
     */
    private static function entity(array $project, Args $args): array
    {
        $target = $args->enum('target', self::TARGETS, 'course');
        $targetId = $args->intOrNull('target_id');

        if (($target === 'chapter' || $target === 'page') && ($targetId === null || $targetId <= 0)) {
            throw HttpException::unprocessable(
                'target_id is required when target is ' . $target . '. Call get_course to find the ' . $target . ' id.'
            );
        }
        return Tags::resolveEntity($project, $target, $targetId);
    }

    /** The caller's word for an entity type; "project" is only ever the storage name. */
    private static function label(string $type): string
    {
        return $type === 'project' ? 'course' : $type;
    }

    /**
     * One link as it stands after a change.
     *
     * Read back rather than echoed from the arguments, because the domain
     * adjusts things: inherit is dropped on a page, and attaching by hand turns
     * an automatic link into a manual one and switches it on again.
     *
     * @return array<string,mixed>|null
     */
    private static function linkState(int $projectId, string $type, int $entityId, int $tagId): ?array
    {
        foreach (Tags::resolved($projectId)['own'][$type][$entityId] ?? [] as $tag) {
            if ((int)$tag['id'] === $tagId) {
                return $tag;
            }
        }
        return null;
    }

    /**
     * What is attached to one item, and what actually applies to it.
     *
     * @param array{own:array<string,mixed>,effective:array<string,mixed>} $resolved
     * @return array{attached:array<int,array<string,mixed>>,effective:array<int,array<string,mixed>>}
     */
    private static function tagsOf(array $resolved, string $type, int $entityId): array
    {
        $own = (array)($resolved['own'][$type][$entityId] ?? []);
        $effective = (array)($resolved['effective'][$type][$entityId] ?? []);

        // resolved() merges the levels by lower-cased name, so a name is what
        // decides whether an effective tag came from here or from above.
        $here = [];
        foreach ($own as $tag) {
            if ($tag['enabled']) {
                $here[mb_strtolower((string)$tag['name'])] = true;
            }
        }

        return [
            'attached' => array_map(static fn(array $tag): array => [
                'tag_id' => (int)$tag['id'],
                'name' => (string)$tag['name'],
                'value' => (string)$tag['value'],
                'inherit' => (bool)$tag['inherit'],
                'automatic' => (bool)$tag['auto'],
                'enabled' => (bool)$tag['enabled'],
            ], array_values($own)),
            'effective' => array_map(static fn(array $tag): array => [
                'tag_id' => (int)$tag['id'],
                'name' => (string)$tag['name'],
                'value' => (string)$tag['value'],
                'inherited' => !isset($here[mb_strtolower((string)$tag['name'])]),
            ], array_values($effective)),
        ];
    }

    /**
     * A library row with its usage count, which `find` does not carry.
     *
     * @return array<string,mixed>
     */
    private static function libraryTag(string $owner, int $tagId): array
    {
        foreach (Tags::all($owner) as $tag) {
            if ((int)$tag['id'] === $tagId) {
                $tag['attached_to'] = (int)$tag['usage_count'];
                return $tag;
            }
        }
        // Unreachable in practice - Access::tag has already found the row - but
        // it keeps the not-found answer identical to every other tag lookup.
        return Tags::require($owner, $tagId) + ['attached_to' => 0];
    }
}
