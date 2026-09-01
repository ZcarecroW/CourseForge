<?php
declare(strict_types=1);

namespace CourseForge\Mcp\Handlers;

use CourseForge\Domain\AutoLinker;
use CourseForge\Domain\Chapters;
use CourseForge\Domain\LinkIndex;
use CourseForge\Domain\Pages;
use CourseForge\Domain\Projects;
use CourseForge\Mcp\Args;
use CourseForge\Mcp\Resolve;
use CourseForge\Mcp\Schema;
use CourseForge\Mcp\Scopes;
use CourseForge\Mcp\Tool;
use CourseForge\Publish\Publisher;
use CourseForge\Publish\Targets;
use CourseForge\Security\Actor;
use CourseForge\Support\Audit;
use CourseForge\Support\HttpException;
use CourseForge\Support\Runtime;
use CourseForge\Support\Text;

/**
 * Publishing: moving a finished course out of CourseForge and into a wiki.
 *
 * A published course is a BookStack book, usually sitting on a shelf, holding
 * one chapter per chapter and one page per page. The push is idempotent by
 * construction: every item is created once and updated in place afterwards, an
 * item whose fingerprint matches what was last sent is skipped, and an item
 * that somebody deleted in BookStack is recreated rather than duplicated. That
 * is what makes it safe to publish a course twenty times while it is being
 * written - each run only moves what actually changed.
 *
 * The second half of the job is cross references. While page three is written,
 * page forty may not exist yet, so the writer leaves a plain-text marker rather
 * than a link. Once every item has a URL those markers become real links, which
 * needs no model and no money - only the index of what has been published. A
 * full push does it automatically; a partial push does not, which is why
 * resolve_links exists on its own.
 *
 * This is the only group that writes to a server CourseForge does not own, and
 * nothing it does can be undone from here: a page overwritten in BookStack is
 * overwritten there, and a book created there outlives the course. So the tools
 * are built to be looked at before they are used - get_publish_status answers
 * "what would a push actually do" without contacting the wiki at all, and
 * list_unresolved_links answers the question people ask afterwards, which is
 * which of the cross references silently did not happen.
 */
final class PublishTools
{
    /** Below this a "closest title" is a guess not worth showing. */
    private const SUGGESTION_FLOOR = 0.45;

    /** @return array<int,Tool> */
    public static function tools(): array
    {
        return [
            new Tool(
                name: 'get_publish_status',
                scope: Scopes::PUBLISH,
                title: 'What has been published',
                description: 'The publishing state of one course, worked out from CourseForge\'s own records without '
                    . 'contacting BookStack: every BookStack instance the course publishes to with its shelf, its '
                    . 'book and how much of the course is in each of them, how many chapters and pages are in sync, '
                    . 'how many have changed since the last push, how many have never been pushed, how many pages '
                    . 'have no text to push yet, and how many cross-reference markers would still fail to become '
                    . 'links. A course may publish to several instances at once; the counts are folded across the '
                    . 'ones that are switched on, so "in sync" means in sync everywhere and "changed" means at least '
                    . 'one of them would be written to. Read this before publish_course to see what a push would do. '
                    . 'It is counts and a summary rather than a list, so it stays small whatever the size of the '
                    . 'course, and it changes nothing. Costs nothing.',
                properties: [
                    'course_id' => Schema::courseId(),
                ],
                required: ['course_id'],
                handler: static fn(Actor $actor, array $args): array => self::publishStatus($actor, Args::of($args)),
                readOnly: true,
                idempotent: true,
            ),

            new Tool(
                name: 'publish_course',
                scope: Scopes::PUBLISH,
                title: 'Publish to BookStack',
                description: 'Pushes the course into every BookStack instance the course points at. For each of them '
                    . 'it creates the book if it is not there yet, puts it on that instance\'s chosen shelf, then '
                    . 'creates or updates every chapter and every page, carrying the effective tags with them. Items '
                    . 'that have not changed since the last push to that instance are skipped and an item that was '
                    . 'deleted there is recreated, so re-publishing never duplicates anything. Pages with no text yet '
                    . 'are skipped. Each instance holds its own book with its own ids, and a page\'s cross references '
                    . 'point inside the wiki it is in, so the same page is written slightly differently to each. An '
                    . 'instance that cannot be reached does not stop the others: its failure is reported and the push '
                    . 'carries on. This is a long sequence of API calls against somebody else\'s live wiki, times the '
                    . 'number of instances, and can take several minutes on a large course; existing chapters and '
                    . 'pages are overwritten in place, and none of it can be undone from CourseForge. part "all" '
                    . 'publishes the whole course and resolves cross references afterwards; part "chapter" or "page" '
                    . 'publishes one item and needs target_id, creates the book and the parent chapter around it, and '
                    . 'leaves cross references alone - call resolve_links after those. force re-sends items whose '
                    . 'fingerprint says they are unchanged, which also overwrites edits made in BookStack by hand. '
                    . 'The course has to say where it publishes first: set_publish_targets sets the whole list, and '
                    . 'update_course still sets a single one as bookstack_instance. No model is called - this reaches '
                    . 'the BookStack server but buys nothing.',
                properties: [
                    'course_id' => Schema::courseId(),
                    'part' => Schema::enum(
                        'How much of the course to publish. "all" is the whole course and is the usual answer; '
                        . '"chapter" is one chapter with its pages; "page" is a single page. Both of the last two '
                        . 'need target_id.',
                        ['all', 'chapter', 'page']
                    ),
                    'target_id' => Schema::int(
                        'The chapter id or page id to publish, required when part is "chapter" or "page" and not '
                        . 'allowed otherwise. Ids come from get_course or list_pages.'
                    ),
                    'instances' => Schema::strings(
                        'Publish to these BookStack instances only, named by their instance id. Leave it out to '
                        . 'publish to every instance the course has switched on, which is the usual answer; name one '
                        . 'when a single wiki was behind or failed and the others are already up to date. '
                        . 'get_publish_status lists the ids.'
                    ),
                    'force' => Schema::bool(
                        'Re-send every item even when its fingerprint says it is unchanged. Use this to repair a book '
                        . 'somebody has edited in BookStack; their edits are replaced.'
                    ),
                ],
                required: ['course_id'],
                handler: static fn(Actor $actor, array $args): array => self::publishCourse($actor, Args::of($args)),
                destructive: true,
                idempotent: true,
                openWorld: true,
                // One log line per chapter and per page, and a five-hundred-page
                // course produces every one of them.
                maxResultChars: 300000,
            ),

            new Tool(
                name: 'resolve_links',
                scope: Scopes::PUBLISH,
                title: 'Turn cross references into links',
                description: 'Rewrites the ( 🔗 Title ) markers the writer left in the text into real BookStack links, '
                    . 'using the URLs of what has already been published, and re-sends only the pages whose text '
                    . 'actually changed. It calls no model and can be run as often as you like - the stored page '
                    . 'always keeps the raw marker, so resolving is repeatable and never corrupts the source text. A '
                    . 'reference whose target is not published yet keeps its marker and resolves on a later run; a '
                    . 'reference that matches no chapter or page in the course goes out as plain text, and '
                    . 'list_unresolved_links says which ones those are. A full publish_course already does this, so '
                    . 'run it after publishing a single chapter or page, or after a page was rewritten. Each rewritten '
                    . 'page is written back to the live wiki - this reaches the BookStack server but buys nothing.',
                properties: [
                    'course_id' => Schema::courseId(),
                    'instances' => Schema::strings(
                        'Resolve into these BookStack instances only, named by their instance id. Leave it out for '
                        . 'every instance the course has switched on. Each wiki gets links pointing inside itself.'
                    ),
                    'force' => Schema::bool(
                        'Re-send every page that holds a marker, even one whose text has not changed.'
                    ),
                ],
                required: ['course_id'],
                handler: static fn(Actor $actor, array $args): array => self::resolveLinks($actor, Args::of($args)),
                idempotent: true,
                openWorld: true,
            ),

            new Tool(
                name: 'set_publish_targets',
                scope: Scopes::PUBLISH,
                title: 'Where a course publishes',
                description: 'Sets the whole list of BookStack instances a course publishes to, in one call. A course '
                    . 'may have several: the same book written into a staging wiki and a live one, or into the wikis '
                    . 'of two departments. Each entry names an instance the course\'s profile defines - list_profiles '
                    . 'shows them - and may carry a shelf and a switch. The first entry is the one the course reports '
                    . 'as its own book and shelf everywhere a single answer is wanted. An entry left off the list is '
                    . 'forgotten together with the record of what was published into it, so the next push there '
                    . 'creates a second book rather than updating the first: to stop publishing to a wiki without '
                    . 'losing that record, send it with enabled false instead. Nothing here reaches BookStack and '
                    . 'nothing already published is changed or deleted - this only says where the next push goes. '
                    . 'Costs nothing.',
                properties: [
                    'course_id' => Schema::courseId(),
                    'targets' => Schema::objects(
                        'Every instance this course publishes to, in order. An empty list means the course publishes '
                        . 'nowhere, which clears its book and shelf.',
                        [
                            'instance' => Schema::string('The BookStack instance id, from list_profiles.'),
                            'shelf_id' => Schema::int('The shelf the book belongs on in that instance, or omit for none.'),
                            'shelf_name' => Schema::string('The name of that shelf, for display.'),
                            'enabled' => Schema::bool(
                                'Whether a push reaches this instance. Default true. False keeps everything already '
                                . 'published there on record while leaving it out of the next push.'
                            ),
                        ],
                        ['instance']
                    ),
                ],
                required: ['course_id', 'targets'],
                handler: static fn(Actor $actor, array $args): array => self::setTargets($actor, Args::of($args)),
                // Nothing is deleted in BookStack, but an instance left off the
                // list takes CourseForge's record of the book it made there
                // with it, and that record cannot be got back: the next push to
                // that wiki makes a second book beside the first. That is a
                // loss worth a client asking about.
                destructive: true,
                idempotent: true,
            ),

            new Tool(
                name: 'list_unresolved_links',
                scope: Scopes::PUBLISH,
                title: 'Cross references that will not become links',
                description: 'Every cross-reference marker that does not turn into a link, and the page it sits on. '
                    . 'Two kinds: markers whose title matches no chapter or page in this course, each with the '
                    . 'closest existing title as a suggestion - those go out as plain text and are the ones people '
                    . 'never notice - and markers whose target does exist but has not been published yet, which '
                    . 'resolve by themselves once it is. This is what to check after a publish. It lists one row per '
                    . 'affected page, so on a large course with many cross references the answer is long. Reads the '
                    . 'stored text only: no model, no BookStack, nothing changed. Costs nothing.',
                properties: [
                    'course_id' => Schema::courseId(),
                ],
                required: ['course_id'],
                handler: static fn(Actor $actor, array $args): array => self::listUnresolvedLinks($actor, Args::of($args)),
                readOnly: true,
                idempotent: true,
                // One row per page holding an unresolved marker. A five-hundred-page
                // course whose outline was regenerated can put every page in here.
                maxResultChars: 200000,
            ),
        ];
    }

    /* ------------------------------------------------------------- handlers */

    /** @return array<string,mixed> */
    private static function publishStatus(Actor $actor, Args $args): array
    {
        ['project' => $project, 'owner' => $owner] = Resolve::course($actor, $args->id());
        $tree = Projects::tree($owner, (int)$project['id']);

        $chapters = ['total' => 0, 'in_sync' => 0, 'changed' => 0, 'never_published' => 0];
        $pages = ['total' => 0, 'written' => 0, 'in_sync' => 0, 'changed' => 0, 'never_published' => 0, 'nothing_to_push' => 0];

        // "changed" is asked before "never published", and that order is the
        // whole of it once a course can publish to several wikis. An item that
        // is in one of them and not in another is not in sync everywhere, so
        // `pushed` is false - but it has certainly been published, and putting
        // it in "never published" would say the opposite of what happened.
        // `dirty` is the honest bucket for it: a push would write it.
        foreach ($tree['chapters'] as $chapter) {
            $chapters['total']++;
            if ($chapter['dirty']) {
                $chapters['changed']++;
            } elseif (!$chapter['pushed']) {
                $chapters['never_published']++;
            } else {
                $chapters['in_sync']++;
            }

            foreach ($chapter['pages'] as $page) {
                $pages['total']++;
                if (!$page['has_content']) {
                    $pages['nothing_to_push']++;
                    continue;
                }
                $pages['written']++;
                if ($page['dirty']) {
                    $pages['changed']++;
                } elseif (!$page['pushed']) {
                    $pages['never_published']++;
                } else {
                    $pages['in_sync']++;
                }
            }
        }

        $links = $tree['stats']['links'];
        $blocking = self::blockingReasons($project, $owner);
        $warnings = self::warnings($project);
        $bookId = $tree['book_id'];
        $changed = $chapters['changed'] + $pages['changed'] + ($bookId !== null && $tree['dirty'] ? 1 : 0);
        $missing = $chapters['never_published'] + $pages['never_published'];

        return [
            'course_id' => (int)$project['id'],
            'name' => (string)$project['name'],
            'owner' => $owner,
            // The first destination on its own, because most courses have
            // exactly one and a single answer reads better than a list of one.
            'target' => self::targetSummary($project, $owner),
            // And all of them, each with the book it holds and how much of the
            // course is in it. `enabled` false means a push skips it.
            'targets' => $tree['targets'],
            'book' => [
                'published' => $bookId !== null,
                'book_id' => $bookId,
                'title' => (string)$tree['book_title'],
                'url' => (string)$tree['book_url'],
                'in_sync' => $bookId !== null && !$tree['dirty'],
            ],
            'chapters' => $chapters,
            'pages' => $pages,
            'links' => [
                'markers' => (int)$links['markers'],
                'would_resolve' => (int)$links['resolved'],
                'unresolved' => (int)$links['pending'] + (int)$links['dropped'],
                'waiting_for_publication' => (int)$links['pending'],
                // Markers that match nothing, plus the occasional page that
                // refers to itself. list_unresolved_links separates them.
                'not_linkable' => (int)$links['dropped'],
            ],
            'can_publish' => $blocking === [],
            'blocking' => $blocking,
            'warnings' => $warnings,
            'next_step' => self::statusNextStep($blocking, $bookId, $changed, $missing, (int)$links['pending'] + (int)$links['dropped']),
        ];
    }

    /** @return array<string,mixed> */
    private static function publishCourse(Actor $actor, Args $args): array
    {
        ['project' => $project, 'owner' => $owner] = Resolve::course($actor, $args->id());
        $projectId = (int)$project['id'];

        $part = self::part($args);
        $targetId = $args->optionalId('target_id');
        $force = $args->bool('force');
        $target = self::resolveTarget($project, $part, $targetId);

        // Everything the push needs from the owner's profile, checked before a
        // single request leaves the building - a refusal three API calls in
        // would already have created a book.
        self::assertPublishable($project, $owner);
        $instances = self::instanceTargets($project, $args);

        // Dozens to hundreds of round trips against a wiki that may be slow,
        // once per wiki.
        Runtime::beginLongRequest();

        $result = Publisher::open($owner, $projectId, $instances)->push($part, $targetId, $force);
        $items = self::classify($result['log']);
        $after = Projects::tree($owner, $projectId);

        $counts = [
            'created' => count($items['created']),
            'updated' => count($items['updated']),
            'unchanged' => count($items['unchanged']),
            'skipped' => count($items['skipped']),
            'recreated' => count($items['recreated']),
        ];

        Audit::record(
            $actor->username,
            'course.publish',
            (string)$project['name'],
            sprintf(
                'part=%s%s to %d instance(s): %d created, %d updated, %d unchanged, %d skipped%s',
                $part . ($target === null ? '' : ' "' . $target . '"'),
                $force ? ', forced' : '',
                count($result['targets']),
                $counts['created'],
                $counts['updated'],
                $counts['unchanged'],
                $counts['skipped'],
                $result['failed'] > 0 ? ', ' . $result['failed'] . ' instance(s) failed' : ''
            ),
            'mcp'
        );

        return [
            'published' => true,
            'course_id' => $projectId,
            'part' => $part,
            'target_id' => $targetId,
            'target' => $target,
            'forced' => $force,
            'book' => [
                'book_id' => $after['book_id'],
                'title' => (string)$after['book_title'],
                'url' => (string)$after['book_url'],
                'shelf' => $after['shelf_id'] === null ? null : (string)$after['shelf_name'],
            ],
            // One entry per wiki this push covered, in the order it ran. `ok`
            // false is a wiki that refused the push; the others still happened.
            'instances' => self::instanceResults($result['targets'], $after['targets']),
            'instances_failed' => (int)$result['failed'],
            'counts' => $counts,
            'created' => $items['created'],
            'updated' => $items['updated'],
            'unchanged' => $items['unchanged'],
            'skipped' => $items['skipped'],
            'recreated' => $items['recreated'],
            'links' => $result['links'],
            'now' => [
                'pages_published' => (int)$after['stats']['pushed'],
                'pages_out_of_sync' => (int)$after['stats']['dirty'],
                'pages_total' => (int)$after['stats']['pages'],
            ],
            'log' => $result['log'],
            'warnings' => self::warnings($project),
            'next_step' => self::publishNextStep($part, (int)$result['links']['pending'], $after),
        ];
    }

    /** @return array<string,mixed> */
    private static function resolveLinks(Actor $actor, Args $args): array
    {
        ['project' => $project, 'owner' => $owner] = Resolve::course($actor, $args->id());
        $projectId = (int)$project['id'];

        self::assertPublishable($project, $owner);
        Runtime::beginLongRequest(); // one BookStack write per page whose links changed, per wiki

        $result = Publisher::open($owner, $projectId, self::instanceTargets($project, $args))
            ->resolveLinks($args->bool('force'));
        $scan = self::scanLinks($projectId);

        Audit::record(
            $actor->username,
            'course.resolve_links',
            (string)$project['name'],
            sprintf('%d resolved, %d page(s) re-published', (int)$result['links']['resolved'], (int)$result['links']['updated']),
            'mcp'
        );

        $stillUnmatched = (int)$scan['unmatched'];
        $stillWaiting = (int)$scan['waiting'];

        return [
            'resolved' => true,
            'course_id' => $projectId,
            'links_resolved' => (int)$result['links']['resolved'],
            // Summed across the wikis: the same page rewritten in three of them
            // is three writes, which is what this number is counting.
            'pages_republished' => (int)$result['links']['updated'],
            'still_waiting_for_publication' => $stillWaiting,
            'still_unmatched' => $stillUnmatched,
            'instances_failed' => (int)$result['failed'],
            'log' => $result['log'],
            'next_step' => match (true) {
                $stillUnmatched > 0 => 'Call list_unresolved_links to see which references match no chapter or page - '
                    . 'those went out as plain text and only an edit to the page or to the target\'s title will fix them.',
                $stillWaiting > 0 => 'Some references point at items that are not published yet. Call publish_course, '
                    . 'then resolve_links again.',
                default => 'Every cross reference in this course is resolved. get_publish_status confirms the rest.',
            },
        ];
    }

    /** @return array<string,mixed> */
    private static function setTargets(Actor $actor, Args $args): array
    {
        ['project' => $project, 'owner' => $owner] = Resolve::course($actor, $args->id());
        $projectId = (int)$project['id'];

        $known = Targets::instancesOf($owner, $project['profile_id'] === null ? null : (int)$project['profile_id']);
        $before = array_map(static fn(array $t): string => (string)$t['instance_id'], Targets::all($projectId));
        // An instance the course already publishes to counts as known even if
        // the profile has stopped defining it - otherwise the only way to edit
        // the list would be to forget that destination, which is exactly what
        // somebody in that position is trying not to do.
        foreach ($before as $instanceId) {
            $known[$instanceId] ??= ['name' => '', 'base_url' => ''];
        }

        $incoming = [];
        foreach ($args->objects('targets') as $entry) {
            $instanceId = trim((string)($entry['instance'] ?? $entry['instance_id'] ?? ''));
            if ($instanceId === '') {
                throw HttpException::unprocessable('Every entry in targets needs an "instance" - the BookStack '
                    . 'instance id, which list_profiles gives for the course\'s profile.');
            }
            if (!isset($known[$instanceId])) {
                throw HttpException::unprocessable(
                    'The profile of course "' . (string)$project['name'] . '" defines no BookStack instance "'
                    . $instanceId . '". list_profiles shows the ones it does; the instance has to be added to the '
                    . 'profile before a course can publish to it.'
                );
            }
            $incoming[] = [
                'instance_id' => $instanceId,
                'shelf_id' => array_key_exists('shelf_id', $entry) ? $entry['shelf_id'] : null,
                'shelf_name' => (string)($entry['shelf_name'] ?? ''),
                'enabled' => $entry['enabled'] ?? true,
            ];
        }

        $stored = Targets::replaceAll($owner, $projectId, $incoming);
        $after = array_map(static fn(array $t): string => (string)$t['instance_id'], $stored);
        $forgotten = array_values(array_diff($before, $after));

        Audit::record(
            $actor->username,
            'course.publish_targets',
            (string)$project['name'],
            count($after) . ' instance(s): ' . (implode(', ', $after) ?: 'none')
            . ($forgotten === [] ? '' : '; forgot ' . implode(', ', $forgotten)),
            'mcp'
        );

        $tree = Projects::tree($owner, $projectId);

        return [
            'course_id' => $projectId,
            'targets' => $tree['targets'],
            'forgotten' => $forgotten,
            'next_step' => match (true) {
                $after === [] => 'This course now publishes nowhere. Give set_publish_targets at least one instance '
                    . 'before calling publish_course.',
                $forgotten !== [] => 'CourseForge has forgotten what it published to ' . implode(', ', $forgotten)
                    . '. Whatever is in those wikis is still there and is no longer tracked; publishing to one of '
                    . 'them again would create a second book beside the first.',
                default => 'Call get_publish_status to see what a push would do, then publish_course.',
            },
        ];
    }

    /** @return array<string,mixed> */
    private static function listUnresolvedLinks(Actor $actor, Args $args): array
    {
        ['project' => $project] = Resolve::course($actor, $args->id());
        $projectId = (int)$project['id'];
        $scan = self::scanLinks($projectId);

        $unpublished = [];
        foreach ($scan['targets'] as $entry) {
            if ($entry['url'] === '') {
                $unpublished[] = ['type' => $entry['type'], 'id' => $entry['id'], 'title' => $entry['title']];
            }
        }

        return [
            'course_id' => $projectId,
            'summary' => [
                'markers' => $scan['markers'],
                'resolved' => $scan['resolved'],
                'waiting_for_publication' => $scan['waiting'],
                'unmatched' => $scan['unmatched'],
                'pages_affected' => count($scan['pages']),
            ],
            'pages' => $scan['pages'],
            // Every "waiting" reference is waiting for one of these, so listing
            // them turns a count into something a person can act on.
            'unpublished_targets' => $scan['waiting'] > 0 ? array_slice($unpublished, 0, 25) : [],
            'unpublished_targets_total' => $scan['waiting'] > 0 ? count($unpublished) : 0,
            'next_step' => match (true) {
                $scan['unmatched'] > 0 => 'Each unmatched marker names something this course does not contain. Either '
                    . 'correct the marker with update_page, or rename the intended target with update_page or '
                    . 'update_chapter, then run resolve_links.',
                $scan['waiting'] > 0 => 'Nothing is mistyped - the targets are not in BookStack yet. Call '
                    . 'publish_course, then resolve_links.',
                default => 'Nothing is unresolved in this course.',
            },
        ];
    }

    /* -------------------------------------------------------------- helpers */

    /**
     * How much of the course a push covers.
     *
     * The declared name is `part`, because `scope` is already this surface's
     * word for a group of tools and a model reading both in one session has no
     * way to tell that they are unrelated. `scope` was the original name and is
     * still read, silently, so a client that learned it keeps working.
     */
    private static function part(Args $args): string
    {
        $key = !$args->has('part') && $args->has('scope') ? 'scope' : 'part';
        return $args->enum($key, ['all', 'chapter', 'page'], 'all');
    }

    /**
     * Refuses a push that cannot work, in words that say what to do about it.
     *
     * Publisher::open checks the same things, but it answers a person looking
     * at the Publish tab. A model needs to be told which tool fixes it.
     *
     * @param array<string,mixed> $project
     */
    private static function assertPublishable(array $project, string $owner): void
    {
        $reasons = self::blockingReasons($project, $owner);
        if ($reasons !== []) {
            throw HttpException::unprocessable(implode(' ', $reasons));
        }
    }

    /**
     * What stands between this course and BookStack, if anything.
     *
     * @param array<string,mixed> $project
     * @return array<int,string>
     */
    public static function blockingReasons(array $project, string $owner): array
    {
        $name = (string)$project['name'];
        $reasons = [];

        if ($project['profile_id'] === null) {
            $reasons[] = 'Course "' . $name . '" has no profile, so there are no BookStack credentials to publish '
                . 'with. list_profiles shows the options and update_course assigns one.';
        }

        $targets = Targets::all((int)$project['id']);
        if ($targets === []) {
            $reasons[] = 'Course "' . $name . '" has no BookStack instance chosen, so there is nowhere to publish it. '
                . 'list_profiles shows the instances the course\'s profile defines; point the course at one or '
                . 'several by calling set_publish_targets, or at a single one with update_course\'s '
                . 'bookstack_instance.';
        } elseif (Targets::enabled((int)$project['id']) === []) {
            $reasons[] = 'Every BookStack instance of course "' . $name . '" is switched off, so a push would reach '
                . 'nothing. set_publish_targets switches one back on with enabled true.';
        }

        // An instance a course points at that its profile has since stopped
        // defining has no credentials behind it, and the push would fail on it
        // rather than at the door. Saying which one is the whole difference
        // between a fixable answer and a 400 from somebody else's server.
        $known = Targets::instancesOf($owner, $project['profile_id'] === null ? null : (int)$project['profile_id']);
        $orphans = [];
        foreach ($targets as $target) {
            if (!isset($known[(string)$target['instance_id']])) {
                $orphans[] = (string)$target['instance_id'];
            }
        }
        if ($orphans !== [] && $project['profile_id'] !== null) {
            $reasons[] = 'Course "' . $name . '" publishes to ' . implode(', ', array_map(
                static fn(string $id): string => '"' . $id . '"',
                $orphans
            )) . ', which its profile no longer defines - there are no credentials for '
                . (count($orphans) === 1 ? 'it' : 'them') . '. Either add the instance back to the profile, or drop '
                . 'it from the course with set_publish_targets.';
        }

        return $reasons;
    }

    /**
     * Things worth saying out loud that are not reasons to refuse.
     *
     * @param array<string,mixed> $project
     * @return array<int,string>
     */
    private static function warnings(array $project): array
    {
        $warnings = [];

        $shelfless = [];
        foreach (Targets::all((int)$project['id']) as $target) {
            if ($target['shelf_id'] === null) {
                $shelfless[] = (string)$target['instance_id'];
            }
        }
        if ($shelfless !== []) {
            // A shelf is optional in BookStack, so this is a warning rather
            // than a refusal: the book is created either way, it is only
            // harder to find. Each instance has its own shelves and its own
            // choice, so a course with three destinations can be missing one.
            $warnings[] = 'No shelf is chosen for ' . implode(', ', $shelfless) . ', so the book is created there '
                . 'outside every shelf. list_bookstack_shelves gives the ids; set_publish_targets takes a shelf_id '
                . 'and shelf_name per instance.';
        }

        return $warnings;
    }

    /**
     * The targets a request named by instance id, or null for "all of them".
     *
     * Named rather than numbered because an instance id is what a model has
     * already been handed - by list_profiles, and by get_publish_status - and
     * because it survives a course being handed to another account, which a row
     * id of somebody else's target would not.
     *
     * @param array<string,mixed> $project
     * @return array<int,int>|null
     */
    private static function instanceTargets(array $project, Args $args): ?array
    {
        $wanted = $args->strings('instances');
        if ($wanted === []) {
            return null;
        }

        $ids = [];
        foreach ($wanted as $instanceId) {
            $target = Targets::byInstance((int)$project['id'], $instanceId);
            if ($target === null) {
                throw HttpException::unprocessable(
                    'Course "' . (string)$project['name'] . '" does not publish to a BookStack instance called "'
                    . $instanceId . '". get_publish_status lists the ones it does, and set_publish_targets adds one.'
                );
            }
            $ids[] = (int)$target['id'];
        }
        return $ids;
    }

    /**
     * What happened in each wiki, with the book it now holds beside it.
     *
     * @param array<int,array<string,mixed>> $results as Publisher hands them back
     * @param array<int,array<string,mixed>> $targets the course tree's target list
     * @return array<int,array<string,mixed>>
     */
    private static function instanceResults(array $results, array $targets): array
    {
        $byId = [];
        foreach ($targets as $target) {
            $byId[(int)$target['id']] = $target;
        }

        $out = [];
        foreach ($results as $result) {
            $target = $byId[(int)$result['target_id']] ?? [];
            $items = self::classify($result['log']);
            $out[] = [
                'instance_id' => (string)$result['instance_id'],
                'instance_name' => (string)$result['name'],
                'ok' => (bool)$result['ok'],
                'error' => (string)$result['error'],
                'book_id' => $target['book_id'] ?? null,
                'book_url' => (string)($target['book_url'] ?? ''),
                'shelf' => ($target['shelf_id'] ?? null) === null ? null : (string)($target['shelf_name'] ?? ''),
                'counts' => [
                    'created' => count($items['created']),
                    'updated' => count($items['updated']),
                    'unchanged' => count($items['unchanged']),
                    'skipped' => count($items['skipped']),
                    'recreated' => count($items['recreated']),
                ],
                'links' => $result['links'],
            ];
        }
        return $out;
    }

    /**
     * The first place this course publishes to, named rather than numbered.
     *
     * The profile holds the credentials, so it is read from the course's owner
     * whoever is asking - and only the name and the address of the instance
     * ever come back out of it.
     *
     * A course may have several destinations; `targets` beside this one carries
     * all of them. This stays because it is the answer to "where does this
     * course publish" for the many courses where that has one answer, and
     * because a client written against 4.6 reads it.
     *
     * @param array<string,mixed> $project
     * @return array<string,mixed>
     */
    private static function targetSummary(array $project, string $owner): array
    {
        $target = Targets::primary((int)$project['id']);
        $profileId = $project['profile_id'] === null ? null : (int)$project['profile_id'];

        if ($target === null) {
            return [
                'instance_id' => null,
                'instance_name' => null,
                'base_url' => null,
                'shelf_id' => null,
                'shelf_name' => null,
                'profile_id' => $profileId,
                'instance_count' => 0,
            ];
        }

        $known = Targets::instancesOf($owner, $profileId)[(string)$target['instance_id']] ?? null;

        return [
            'instance_id' => (string)$target['instance_id'],
            'instance_name' => $known === null ? null : $known['name'],
            'base_url' => $known === null ? null : $known['base_url'],
            'shelf_id' => $target['shelf_id'] === null ? null : (int)$target['shelf_id'],
            'shelf_name' => (string)$target['shelf_name'] !== '' ? (string)$target['shelf_name'] : null,
            'profile_id' => $profileId,
            'instance_count' => count(Targets::all((int)$project['id'])),
        ];
    }

    /**
     * Checks the target of a partial push and hands back its title.
     *
     * @param array<string,mixed> $project
     */
    private static function resolveTarget(array $project, string $part, ?int $targetId): ?string
    {
        if ($part === 'all') {
            if ($targetId !== null) {
                throw HttpException::unprocessable(
                    'target_id only applies when part is "chapter" or "page". Leave it out to publish everything.'
                );
            }
            return null;
        }

        if ($targetId === null) {
            throw HttpException::unprocessable(
                'target_id is required when part is "' . $part . '". Take the id from get_course.'
            );
        }

        return $part === 'chapter'
            ? (string)Resolve::chapter($project, $targetId)['title']
            : (string)Resolve::page($project, $targetId)['title'];
    }

    /**
     * Sorts the publisher's log into what happened to each item.
     *
     * The log is prose, one line per item, and it is the only record of the
     * individual outcomes - so it is read here rather than handed over as a
     * wall of text.
     *
     * @param array<int,string> $log
     * @return array<string,array<int,string>>
     */
    private static function classify(array $log): array
    {
        $items = ['created' => [], 'updated' => [], 'unchanged' => [], 'skipped' => [], 'recreated' => [], 'other' => []];

        foreach ($log as $line) {
            // A push to more than one wiki names the wiki in front of every
            // line. The verb is what is being read here, so the label comes
            // off first - otherwise a course with two destinations reports
            // nothing created and everything "other".
            $verb = preg_replace('/^\[[^\]]*\] /', '', $line) ?? $line;

            $bucket = match (true) {
                str_starts_with($verb, 'Created ') => 'created',
                str_starts_with($verb, 'Updated ') => 'updated',
                str_starts_with($verb, 'Skipped ') => 'skipped',
                str_contains($verb, 'no longer exists in BookStack') => 'recreated',
                str_contains($verb, 'is already up to date') => 'unchanged',
                default => 'other',
            };
            $items[$bucket][] = $line;
        }

        return $items;
    }

    /**
     * Every cross reference in a course and what would become of it.
     *
     * Nothing here writes: AutoLinker resolves against the stored text on the
     * way out, so asking what a marker would do is the same work as doing it,
     * minus the push.
     *
     * @return array{markers:int,resolved:int,waiting:int,unmatched:int,pages:array<int,array<string,mixed>>,targets:array<int,array<string,mixed>>}
     */
    private static function scanLinks(int $projectId): array
    {
        $index = LinkIndex::forProject($projectId);
        $pages = Pages::ordered($projectId);

        $targets = [];
        foreach (Chapters::ordered($projectId) as $chapter) {
            $targets[] = [
                'type' => 'chapter',
                'id' => (int)$chapter['id'],
                'title' => (string)$chapter['title'],
                'url' => (string)$chapter['bs_url'],
            ];
        }
        foreach ($pages as $page) {
            $targets[] = [
                'type' => 'page',
                'id' => (int)$page['id'],
                'title' => (string)$page['title'],
                'url' => (string)$page['bs_url'],
            ];
        }

        $markers = 0;
        $resolved = 0;
        $waiting = 0;
        $unmatched = 0;
        $rows = [];

        foreach ($pages as $page) {
            $content = (string)$page['content'];
            if (!AutoLinker::hasMarkers($content)) {
                continue;
            }

            $pageId = (int)$page['id'];
            $applied = AutoLinker::apply($content, $index, $pageId);
            $onPage = AutoLinker::countMarkers($content);

            $markers += $onPage;
            $resolved += $applied['resolved'];
            $waiting += $applied['pending'];
            // Titles rather than occurrences: a page naming the same missing
            // target four times is one thing to fix, not four.
            $unmatched += count($applied['unknown']);

            if ($applied['pending'] === 0 && $applied['unknown'] === []) {
                continue;
            }

            $misses = [];
            foreach ($applied['unknown'] as $title) {
                $misses[] = ['marker' => $title, 'closest_title' => self::closest($title, $targets)];
            }

            $rows[] = [
                'page_id' => $pageId,
                'page_title' => (string)$page['title'],
                'chapter_title' => (string)$page['chapter_title'],
                'page_published' => $page['bs_id'] !== null,
                'markers' => $onPage,
                'resolved' => $applied['resolved'],
                'waiting_for_publication' => $applied['pending'],
                'unmatched' => $misses,
            ];
        }

        return [
            'markers' => $markers,
            'resolved' => $resolved,
            'waiting' => $waiting,
            'unmatched' => $unmatched,
            'pages' => $rows,
            'targets' => $targets,
        ];
    }

    /**
     * The title a mistyped marker probably meant.
     *
     * The lookup that failed was already lenient, so anything found here is
     * below its floor - a suggestion for a person to judge, never something to
     * link automatically.
     *
     * @param array<int,array<string,mixed>> $targets
     */
    private static function closest(string $wanted, array $targets): ?string
    {
        $key = Text::key($wanted);
        if ($key === '') {
            return null;
        }

        $best = null;
        $bestScore = self::SUGGESTION_FLOOR;
        foreach ($targets as $target) {
            $score = Text::similarity($key, Text::key((string)$target['title']));
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = (string)$target['title'];
            }
        }

        return $best;
    }

    /** @param array<int,string> $blocking */
    private static function statusNextStep(array $blocking, ?int $bookId, int $changed, int $missing, int $unresolved): string
    {
        return match (true) {
            $blocking !== [] => 'This course cannot be published yet: ' . implode(' ', $blocking),
            $bookId === null => 'Nothing has been published yet. publish_course creates the book, its chapters and '
                . 'its pages in BookStack.',
            $changed + $missing > 0 => $changed . ' item(s) changed since the last push and ' . $missing
                . ' have never been pushed. publish_course brings BookStack up to date.',
            $unresolved > 0 => 'Everything is in sync, but ' . $unresolved . ' cross reference(s) are not links. '
                . 'list_unresolved_links says why.',
            default => 'BookStack matches this course. Nothing to publish.',
        };
    }

    /** @param array<string,mixed> $after */
    private static function publishNextStep(string $part, int $pending, array $after): string
    {
        if ($part !== 'all') {
            return 'A partial push does not touch cross references. Call resolve_links, then get_publish_status.';
        }
        if ($pending > 0) {
            return $pending . ' cross reference(s) still point at items that were not published in this run. Call '
                . 'resolve_links once they are, and list_unresolved_links to see what is left.';
        }
        if ((int)$after['stats']['pages'] > (int)$after['stats']['pushed']) {
            return 'Some pages were skipped because they have no text yet. Write them, then publish again.';
        }
        return 'The course is published. list_unresolved_links shows any cross reference that went out as plain text.';
    }
}
