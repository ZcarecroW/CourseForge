<?php
declare(strict_types=1);

namespace CourseForge\Mcp\Handlers;

use CourseForge\Domain\AutoLinker;
use CourseForge\Domain\Chapters;
use CourseForge\Domain\LinkIndex;
use CourseForge\Domain\Pages;
use CourseForge\Domain\Profiles;
use CourseForge\Domain\Projects;
use CourseForge\Mcp\Args;
use CourseForge\Mcp\Resolve;
use CourseForge\Mcp\Schema;
use CourseForge\Mcp\Scopes;
use CourseForge\Mcp\Tool;
use CourseForge\Publish\Publisher;
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
                    . 'contacting BookStack: which instance and shelf the course points at, the book and its URL, '
                    . 'how many chapters and pages are in sync, how many have changed since the last push, how many '
                    . 'have never been pushed, how many pages have no text to push yet, and how many cross-reference '
                    . 'markers would still fail to become links. Read this before publish_course to see what a push '
                    . 'would do. It is counts and a summary rather than a list, so it stays small whatever the size '
                    . 'of the course, and it changes nothing. Costs nothing.',
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
                description: 'Pushes the course into the BookStack instance the course points at. It creates the book '
                    . 'if it is not there yet, puts it on the chosen shelf, then creates or updates every chapter and '
                    . 'every page, carrying the effective tags with them. Items that have not changed since the last '
                    . 'push are skipped and an item that was deleted in BookStack is recreated, so re-publishing never '
                    . 'duplicates anything. Pages with no text yet are skipped. This is a long sequence of API calls '
                    . 'against somebody else\'s live wiki and can take several minutes on a large course; existing '
                    . 'chapters and pages are overwritten in place, and none of it can be undone from CourseForge. '
                    . 'part "all" publishes the whole course and resolves cross references afterwards; part '
                    . '"chapter" or "page" publishes one item and needs target_id, creates the book and the parent '
                    . 'chapter around it, and leaves cross references alone - call resolve_links after those. force '
                    . 're-sends items whose fingerprint says they are unchanged, which also overwrites edits made in '
                    . 'BookStack by hand. The course has to say where it publishes first: update_course sets that, '
                    . 'as bookstack_instance, with shelf_id and shelf_name for the shelf. No model is called - this '
                    . 'reaches the BookStack server but buys nothing.',
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

        foreach ($tree['chapters'] as $chapter) {
            $chapters['total']++;
            if (!$chapter['pushed']) {
                $chapters['never_published']++;
            } elseif ($chapter['dirty']) {
                $chapters['changed']++;
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
                if (!$page['pushed']) {
                    $pages['never_published']++;
                } elseif ($page['dirty']) {
                    $pages['changed']++;
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
            'target' => self::targetSummary($project, $owner),
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

        // Dozens to hundreds of round trips against a wiki that may be slow.
        Runtime::beginLongRequest();

        $result = Publisher::open($owner, $projectId)->push($part, $targetId, $force);
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
                'part=%s%s: %d created, %d updated, %d unchanged, %d skipped',
                $part . ($target === null ? '' : ' "' . $target . '"'),
                $force ? ', forced' : '',
                $counts['created'],
                $counts['updated'],
                $counts['unchanged'],
                $counts['skipped']
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
        Runtime::beginLongRequest(); // one BookStack write per page whose links changed

        $result = Publisher::open($owner, $projectId)->resolveLinks($args->bool('force'));
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
            'pages_republished' => (int)$result['links']['updated'],
            'still_waiting_for_publication' => $stillWaiting,
            'still_unmatched' => $stillUnmatched,
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
    private static function blockingReasons(array $project, string $owner): array
    {
        $name = (string)$project['name'];
        $reasons = [];

        if ($project['profile_id'] === null) {
            $reasons[] = 'Course "' . $name . '" has no profile, so there are no BookStack credentials to publish '
                . 'with. list_profiles shows the options and update_course assigns one.';
        }
        if ((string)$project['bs_instance_id'] === '') {
            $reasons[] = 'Course "' . $name . '" has no BookStack instance chosen, so there is nowhere to publish it. '
                . 'list_profiles shows the instances the course\'s profile defines; point the course at one by '
                . 'calling update_course with bookstack_instance set to that id.';
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

        if ($project['shelf_id'] === null && (string)$project['bs_instance_id'] !== '') {
            // A shelf is optional in BookStack, so this is a warning rather
            // than a refusal: the book is created either way, it is only
            // harder to find.
            $warnings[] = 'No shelf is chosen, so the book is created outside every shelf. list_bookstack_shelves '
                . 'gives the ids; update_course takes shelf_id and shelf_name to place the book on one.';
        }

        return $warnings;
    }

    /**
     * Where this course publishes to, named rather than numbered.
     *
     * The profile holds the credentials, so it is read from the course's owner
     * whoever is asking - and only the name and the address of the instance
     * ever come back out of it.
     *
     * @param array<string,mixed> $project
     * @return array<string,mixed>
     */
    private static function targetSummary(array $project, string $owner): array
    {
        $instanceId = (string)$project['bs_instance_id'];
        $summary = [
            'instance_id' => $instanceId === '' ? null : $instanceId,
            'instance_name' => null,
            'base_url' => null,
            'shelf_id' => $project['shelf_id'] === null ? null : (int)$project['shelf_id'],
            'shelf_name' => (string)$project['shelf_name'] !== '' ? (string)$project['shelf_name'] : null,
            'profile_id' => $project['profile_id'] === null ? null : (int)$project['profile_id'],
        ];

        if ($instanceId === '' || $project['profile_id'] === null) {
            return $summary;
        }

        $profile = Profiles::find($owner, (int)$project['profile_id']);
        foreach ((array)($profile['data']['bookstack'] ?? []) as $instance) {
            if ((string)($instance['id'] ?? '') === $instanceId) {
                $summary['instance_name'] = (string)($instance['name'] ?? '');
                $summary['base_url'] = (string)($instance['base_url'] ?? '');
                break;
            }
        }

        return $summary;
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
            $bucket = match (true) {
                str_starts_with($line, 'Created ') => 'created',
                str_starts_with($line, 'Updated ') => 'updated',
                str_starts_with($line, 'Skipped ') => 'skipped',
                str_contains($line, 'no longer exists in BookStack') => 'recreated',
                str_contains($line, 'is already up to date') => 'unchanged',
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
