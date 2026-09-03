<?php
declare(strict_types=1);

namespace CourseForge\Publish;

use CourseForge\Domain\AutoLinker;
use CourseForge\Domain\Chapters;
use CourseForge\Domain\LinkIndex;
use CourseForge\Domain\Pages;
use CourseForge\Domain\Projects;
use CourseForge\Domain\Tags;
use CourseForge\Support\Config;
use CourseForge\Support\Db;
use CourseForge\Support\HttpException;
use CourseForge\Support\Markdown;

/**
 * Publishes a course into one BookStack instance.
 *
 * Everything is idempotent: an item that already exists is updated in place, an
 * item that is byte-identical to what was pushed last time is skipped, and an
 * item that vanished in BookStack is recreated. What gets hashed is exactly
 * what gets sent - including resolved auto links - so the "out of sync" badges
 * in the UI stay honest.
 *
 * Auto links need two passes: while page 3 is written, page 40 may not exist in
 * BookStack yet. The first pass publishes everything, the second pass runs
 * after every URL is known and re-sends only the pages whose links actually
 * changed.
 *
 * Everything this class writes about what a push made - the book, and the id,
 * slug, URL and fingerprint of every chapter and page - belongs to the target
 * rather than to the course, because a course published to two wikis has two
 * books in them and the pages are not even the same text: a cross reference
 * points inside the wiki it was written into. Publisher fans out over the
 * targets and is the only thing that constructs this.
 */
final class TargetPublisher
{
    /** @var string[] */
    private array $log = [];

    /**
     * Where a log line goes the moment it is said, when somebody is listening.
     *
     * A push inside a request collects its log and hands it back at the end.
     * A push worked by the scheduler has nobody to hand it to - the request
     * that asked for it ended minutes ago - so every line is written down as
     * it happens, and the screen reads the record.
     *
     * @var callable(string,string):void|null line, level
     */
    private $emit = null;

    /**
     * @param array<string,mixed> $project
     * @param array<string,mixed> $target a publish_targets row
     * @param string $prefix put in front of every log line, so a push to four
     *        wikis reads as four columns rather than as one confusing list
     */
    public function __construct(
        private readonly array $project,
        private array $target,
        private readonly BookStackClient $client,
        private readonly string $prefix = '',
    ) {
    }

    public function targetId(): int
    {
        return (int)$this->target['id'];
    }

    /** @return string[] */
    public function log(): array
    {
        return $this->log;
    }

    /** The target row as it stands after the push. @return array<string,mixed> */
    public function target(): array
    {
        return $this->target;
    }

    /** @param callable(string,string):void $emit */
    public function onLine(callable $emit): void
    {
        $this->emit = $emit;
    }

    /** What a push has to say about where it stands when nothing has been done yet. */
    private const FRESH = [
        'phase' => 'book',
        'chapter_id' => null,
        'chapter_bs_id' => null,
        'page_id' => null,
        'links_page_id' => null,
        'links' => ['resolved' => 0, 'pending' => 0, 'updated' => 0],
        'links_unknown' => [],
        'links_pages' => 0,
        'links_unpublished' => 0,
        // How far the walk has got, for a screen watching it.
        'chapters_done' => 0,
        'pages_done' => 0,
    ];

    /**
     * Publishes, from the beginning or from where an earlier attempt stopped.
     *
     * `$state` is what a previous call handed back: which phase it was in and
     * the last item it finished, so this one carries on after that item rather
     * than walking the whole course again. An empty array is the beginning.
     * `$budget` says when to stop; a push with no budget runs to the end.
     *
     * The answer says whether it is finished. When it is not, `state` is the
     * place to resume from and nothing in it has been lost: every item is
     * written to the wiki and recorded here before the next is looked at, so
     * a stop between two items costs nothing at all.
     *
     * @param string $scope all | book | chapter | page
     * @param array<string,mixed> $state
     * @return array{log:string[],links:array{resolved:int,pending:int,updated:int},state:array<string,mixed>,done:bool}
     */
    public function push(
        string $scope = 'all',
        ?int $targetId = null,
        bool $force = false,
        array $state = [],
        ?PublishBudget $budget = null,
    ): array {
        $budget ??= PublishBudget::unlimited();
        $state = array_replace(self::FRESH, $state);

        // Whatever stops the walk - a wiki that went away, a host time limit
        // that surfaced as an exception - goes out with the place the walk had
        // reached, so the next attempt can start there.
        try {
            return $this->walk($scope, $targetId, $force, $state, $budget);
        } catch (\Throwable $e) {
            throw PublishFailure::wrap($e, $state);
        }
    }

    /**
     * The push itself. `$state` is taken by reference so that push() can hand
     * it out with the exception when the walk is cut short.
     *
     * @param array<string,mixed> $state
     * @return array{log:string[],links:array{resolved:int,pending:int,updated:int},state:array<string,mixed>,done:bool}
     */
    private function walk(string $scope, ?int $targetId, bool $force, array &$state, PublishBudget $budget): array
    {
        $projectId = (int)$this->project['id'];
        $effectiveTags = Tags::resolved($projectId)['effective'];

        $chapterFilter = null;
        $pageFilter = null;
        if ($scope === 'chapter') {
            Chapters::require($projectId, (int)$targetId);
            $chapterFilter = (int)$targetId;
        } elseif ($scope === 'page') {
            $page = Pages::require($projectId, (int)$targetId);
            $chapterFilter = (int)$page['chapter_id'];
            $pageFilter = (int)$page['id'];
        }

        if ($state['phase'] === 'book') {
            $bookId = $this->ensureBook($effectiveTags['project'][$projectId] ?? [], $force);
            $state['phase'] = $scope === 'book' ? 'done' : 'items';
        } else {
            // ensureBook() wrote the book onto the target row before it
            // returned, so a later slice reads it back from there.
            $bookId = (int)$this->target['book_id'];
        }

        if ($state['phase'] === 'items') {
            $index = LinkIndex::forTarget($projectId, $this->targetId());
            $chapters = array_values(array_filter(
                Chapters::ordered($projectId),
                static fn(array $c): bool => $chapterFilter === null || (int)$c['id'] === $chapterFilter
            ));

            // The chapter the last slice stopped in. A chapter that has since
            // gone from the outline is not a place to resume from, so the walk
            // starts again - every item is idempotent, and a repeated "already
            // up to date" costs one request rather than a duplicate page.
            $resumeAt = null;
            if ($state['chapter_id'] !== null) {
                foreach ($chapters as $i => $chapter) {
                    if ((int)$chapter['id'] === (int)$state['chapter_id']) {
                        $resumeAt = $i;
                        break;
                    }
                }
                if ($resumeAt === null) {
                    $state['chapter_id'] = null;
                    $state['chapter_bs_id'] = null;
                    $state['page_id'] = null;
                }
            }

            foreach ($chapters as $i => $chapter) {
                if ($resumeAt !== null && $i < $resumeAt) {
                    continue;
                }
                $chapterId = (int)$chapter['id'];
                $resuming = $resumeAt === $i && $state['chapter_bs_id'] !== null;

                if (!$resuming) {
                    if ($budget->exhausted()) {
                        return $this->paused($state);
                    }
                    $chapterBsId = $this->ensureChapter($bookId, $chapter, $effectiveTags['chapter'][$chapterId] ?? [], $force);
                    $state['chapter_id'] = $chapterId;
                    $state['chapter_bs_id'] = $chapterBsId;
                    $state['page_id'] = null;
                    $state['chapters_done']++;
                } else {
                    $chapterBsId = (int)$state['chapter_bs_id'];
                }

                $pages = Db::rows('SELECT * FROM pages WHERE chapter_id = ? ORDER BY idx, id', [$chapterId]);
                $skipping = $resuming && $state['page_id'] !== null;
                foreach ($pages as $page) {
                    $pageId = (int)$page['id'];
                    if ($skipping) {
                        if ($pageId === (int)$state['page_id']) {
                            $skipping = false;
                        }
                        continue;
                    }
                    if ($pageFilter !== null && $pageId !== $pageFilter) {
                        continue;
                    }
                    if ($budget->exhausted()) {
                        return $this->paused($state);
                    }
                    $this->ensurePage($chapterBsId, $page, $effectiveTags['page'][$pageId] ?? [], $index, $force);
                    $state['page_id'] = $pageId;
                    $state['pages_done']++;
                }
                // A page that was the last of its chapter and then removed
                // from the outline would otherwise be waited for for ever.
                $skipping = false;
            }

            $state['phase'] = $scope === 'all' ? 'links' : 'done';
        }

        if ($state['phase'] === 'links') {
            // The whole course is now in BookStack, so every link target has a URL.
            $this->linkPass($effectiveTags, $force, $state, $budget);
            if ($state['phase'] !== 'done') {
                return $this->paused($state);
            }
        }

        return ['log' => $this->log, 'links' => $state['links'], 'state' => $state, 'done' => true];
    }

    /**
     * Resolves auto links on their own, without re-publishing anything else.
     *
     * @param array<string,mixed> $state see push()
     * @return array{log:string[],links:array{resolved:int,pending:int,updated:int},state:array<string,mixed>,done:bool}
     */
    public function resolveLinks(bool $force = false, array $state = [], ?PublishBudget $budget = null): array
    {
        $budget ??= PublishBudget::unlimited();
        $state = array_replace(self::FRESH, $state, ['phase' => 'links']);
        $projectId = (int)$this->project['id'];

        try {
            $this->linkPass(Tags::resolved($projectId)['effective'], $force, $state, $budget);
        } catch (\Throwable $e) {
            throw PublishFailure::wrap($e, $state);
        }
        if ($state['phase'] !== 'done') {
            return $this->paused($state);
        }
        return ['log' => $this->log, 'links' => $state['links'], 'state' => $state, 'done' => true];
    }

    /**
     * The answer for a push that ran out of budget between two items.
     *
     * @param array<string,mixed> $state
     * @return array{log:string[],links:array{resolved:int,pending:int,updated:int},state:array<string,mixed>,done:bool}
     */
    private function paused(array $state): array
    {
        return ['log' => $this->log, 'links' => $state['links'], 'state' => $state, 'done' => false];
    }

    /* --------------------------------------------------------------- steps */

    private function say(string $line, string $level = 'info'): void
    {
        $this->log[] = $this->prefix . $line;
        if ($this->emit !== null) {
            ($this->emit)($line, $level);
        }
    }

    /**
     * A description rendered for BookStack, cut down to what BookStack accepts.
     *
     * BookStack validates `description_html` at 2000 characters, for books and
     * for chapters alike, and answers 422 for anything longer. CourseForge
     * descriptions are roughly six hundred words, which is comfortably past
     * that – so without this, turning on the long descriptions would turn every
     * publish into a validation error.
     *
     * What is shortened is only the copy on the BookStack cover page. The full
     * text stays whole in the database, which is where it matters: it is what
     * `{{book_description}}` carries into the context of every single page, and
     * what the outline itself is written from.
     *
     * Whole paragraphs are dropped from the end rather than the text being cut
     * at 2000 characters, because half a sentence on a cover page reads as a
     * bug rather than as a limit, and because cutting rendered HTML mid-tag
     * produces markup BookStack would have to repair. If even the first
     * paragraph does not fit, that one paragraph is cut at a sentence.
     *
     * The limit is a setting for the reason it exists: it is BookStack's
     * number, not CourseForge's, and BookStack may raise it. An installation
     * that has raised it should not be held to the old one.
     */
    private function describe(string $markdown, string $what): string
    {
        $limit = max(0, Config::int('app.bookstack_description_max', 2000));
        $html = Markdown::toHtml($markdown);
        if ($limit === 0 || mb_strlen($html) <= $limit) {
            return $html;
        }

        $paragraphs = array_values(array_filter(
            array_map('trim', preg_split('/\n{2,}/', trim($markdown)) ?: []),
            static fn(string $p): bool => $p !== ''
        ));

        $kept = [];
        foreach ($paragraphs as $paragraph) {
            $candidate = Markdown::toHtml(implode("\n\n", [...$kept, $paragraph]));
            if (mb_strlen($candidate) > $limit) {
                break;
            }
            $kept[] = $paragraph;
        }

        if ($kept === []) {
            // One paragraph longer than the whole allowance. Take sentences off
            // it until the rendered result fits.
            $sentences = preg_split('/(?<=[.!?])\s+/u', $paragraphs[0] ?? '') ?: [];
            while ($sentences !== []) {
                array_pop($sentences);
                if ($sentences !== [] && mb_strlen(Markdown::toHtml(implode(' ', $sentences))) <= $limit) {
                    break;
                }
            }

            // A paragraph can have no sentence boundary to cut at - one long
            // run-on joined by commas, or a single sentence with its only full
            // stop at the very end - and then the loop above empties the list
            // and the cover page would get nothing at all. An excerpt that
            // stops mid-thought is a poor description; no description is a
            // worse one, and it also loses the only clue that there is more
            // text in CourseForge. So fall back to trimming words off the end.
            $kept = $sentences !== [] ? [implode(' ', $sentences)] : [self::clampWords($paragraphs[0] ?? '', $limit)];
        }

        $short = Markdown::toHtml(implode("\n\n", $kept));
        $this->say('The description of ' . $what . ' is longer than the ' . $limit
            . ' characters BookStack accepts, so ' . (count($paragraphs) - count($kept))
            . ' of its ' . count($paragraphs) . ' paragraphs were left off the cover page. '
            . 'The full text is unchanged in CourseForge and is still what the pages are written from.', 'warn');

        return $short;
    }

    /**
     * The longest prefix of whole words whose rendered HTML fits the limit.
     *
     * The last resort under describe(), for a paragraph with no sentence
     * boundary in it. Words rather than characters so the excerpt never ends
     * mid-word, and an ellipsis so it reads as "there is more of this" rather
     * than as a description somebody forgot to finish.
     *
     * Measured on the rendered HTML at every step rather than on the Markdown,
     * because the wrapper tags count against BookStack's limit too and a
     * paragraph full of `**bold**` renders far longer than it reads.
     */
    private static function clampWords(string $paragraph, int $limit): string
    {
        $words = preg_split('/\s+/u', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $kept = '';
        foreach ($words as $word) {
            $candidate = $kept === '' ? $word : $kept . ' ' . $word;
            if (mb_strlen(Markdown::toHtml($candidate . '…')) > $limit) {
                break;
            }
            $kept = $candidate;
        }

        // A limit too small for one word plus its markup leaves nothing, and
        // an empty description is what this whole path exists to avoid - but
        // an installation that set the limit that low asked for it, and
        // sending something longer would be sending something BookStack
        // refuses. Empty is then the honest answer.
        return $kept === '' ? '' : $kept . '…';
    }

    /** @param array<int,array<string,mixed>> $tags */
    private function ensureBook(array $tags, bool $force): int
    {
        $title = Projects::bookTitle($this->project);
        $description = (string)$this->project['book_desc'];
        $hash = Projects::pushHash($title, $description, $tags);
        $payloadTags = Tags::apiPayload($tags);
        $bookId = $this->target['book_id'] !== null ? (int)$this->target['book_id'] : null;

        $existing = $bookId !== null ? $this->client->getBook($bookId) : null;
        if ($bookId !== null && $existing === null) {
            $this->say('Book #' . $bookId . ' no longer exists in BookStack – recreating it.', 'warn');
            $bookId = null;
        }

        if ($bookId === null) {
            $result = $this->client->createBook($title, $this->describe($description, 'the book'), $payloadTags);

            // An answer with no id is not a book. Casting it gave 0, which was
            // then stored on the course as the book it lives in - so the course
            // reported itself published to a book that does not exist, and
            // every later push aimed at #0. Over the browser the same line was
            // a 500 instead, because a strict error handler turns the warning
            // into an exception: two doors, two wrong answers, one missing
            // check.
            if (!isset($result['id']) || (int)$result['id'] <= 0) {
                throw HttpException::unprocessable(
                    'BookStack answered the create-book request without a book id, so nothing was stored. That '
                    . 'usually means the base URL is not pointing at a BookStack API - check it under Profiles.'
                );
            }

            $bookId = (int)$result['id'];
            $this->say('Created book "' . $title . '" (#' . $bookId . ').', 'new');
        } elseif ($force || (string)$this->target['pushed_hash'] !== $hash) {
            $result = $this->client->updateBook($bookId, $title, $this->describe($description, 'the book'), $payloadTags);
            $this->say('Updated book "' . $title . '"'
                . ($payloadTags !== [] ? ' (' . count($payloadTags) . ' tag(s)).' : '.'));
        } else {
            $result = $existing ?? [];
            $this->say('Book "' . $title . '" is already up to date.');
        }

        // The book is written down before anything else is asked of BookStack.
        // The shelf call below can fail on its own - a shelf that was deleted,
        // a token that may not edit shelves, a timeout - and when it did, the
        // id of the book just created was thrown away with the exception. The
        // next push then found no book on record, created another, and failed
        // the same way: one orphaned book per attempt until somebody fixed the
        // shelf and deleted the extras by hand. The hash is only written once
        // the shelf is done, so a push that failed at the shelf is repeated in
        // full next time - against the same book.
        $slug = (string)($result['slug'] ?? $this->target['book_slug']);
        $this->rememberBook($bookId, $slug, null);

        if ($this->target['shelf_id'] !== null) {
            $this->client->attachBookToShelf((int)$this->target['shelf_id'], $bookId);
        }

        $this->rememberBook($bookId, $slug, $hash);

        return $bookId;
    }

    /**
     * Stores which book this target lives in, on the row and on the copy this
     * publisher is working from. A null hash leaves the stored one alone.
     */
    private function rememberBook(int $bookId, string $slug, ?string $hash): void
    {
        $fields = [
            'book_id' => $bookId,
            'book_slug' => $slug,
            'book_url' => $this->client->bookUrl($slug),
        ];
        if ($hash !== null) {
            $fields['pushed_hash'] = $hash;
        }
        Targets::update($this->targetId(), $fields);
        $this->target = $fields + $this->target;
    }

    /**
     * @param array<string,mixed> $chapter
     * @param array<int,array<string,mixed>> $tags
     */
    private function ensureChapter(int $bookId, array $chapter, array $tags, bool $force): int
    {
        $chapterId = (int)$chapter['id'];
        $stored = Targets::item($this->targetId(), 'chapter', $chapterId) ?? [];

        $title = (string)$chapter['title'];
        $description = (string)$chapter['description'];
        $hash = Projects::pushHash($title, $description, $tags);
        $payloadTags = Tags::apiPayload($tags);
        $priority = (int)$chapter['idx'] + 1;
        $bsId = ($stored['bs_id'] ?? null) !== null ? (int)$stored['bs_id'] : null;

        $existing = $bsId !== null ? $this->client->getChapter($bsId) : null;
        if ($bsId !== null && $existing === null) {
            $this->say('Chapter "' . $title . '" no longer exists in BookStack – recreating it.', 'warn');
            $bsId = null;
        }

        if ($bsId === null) {
            $result = $this->client->createChapter($bookId, $title, $this->describe($description, 'chapter "' . $title . '"'), $priority, $payloadTags);
            $bsId = (int)$result['id'];
            $this->say('Created chapter "' . $title . '".', 'new');
        } elseif ($force || (string)($stored['pushed_hash'] ?? '') !== $hash) {
            $result = $this->client->updateChapter($bsId, $title, $this->describe($description, 'chapter "' . $title . '"'), $priority, $payloadTags);
            $this->say('Updated chapter "' . $title . '".');
        } else {
            $result = $existing ?? [];
            $this->say('Chapter "' . $title . '" is already up to date.');
        }

        $slug = (string)($result['slug'] ?? ($stored['bs_slug'] ?? ''));
        Targets::saveItem($this->targetId(), 'chapter', $chapterId, [
            'bs_id' => $bsId,
            'bs_slug' => $slug,
            'bs_url' => $this->client->chapterUrl((string)$this->target['book_slug'], $slug),
            'pushed_hash' => $hash,
        ]);

        return $bsId;
    }

    /**
     * @param array<string,mixed> $page
     * @param array<int,array<string,mixed>> $tags
     * @return bool true when something was written to BookStack
     */
    private function ensurePage(int $chapterBsId, array $page, array $tags, LinkIndex $index, bool $force): bool
    {
        $pageId = (int)$page['id'];
        $stored = Targets::item($this->targetId(), 'page', $pageId) ?? [];

        $title = (string)$page['title'];
        $raw = (string)$page['content'];
        if (trim($raw) === '') {
            $this->say('Skipped "' . $title . '" – nothing generated yet.');
            return false;
        }

        $content = AutoLinker::render($raw, $index, $pageId);
        $hash = Pages::pushHash($title, $content, $tags);
        $payloadTags = Tags::apiPayload($tags);
        $priority = (int)$page['idx'] + 1;
        $bsId = ($stored['bs_id'] ?? null) !== null ? (int)$stored['bs_id'] : null;

        $existing = $bsId !== null ? $this->client->getPage($bsId) : null;
        if ($bsId !== null && $existing === null) {
            $this->say('Page "' . $title . '" no longer exists in BookStack – recreating it.', 'warn');
            $bsId = null;
        }

        if ($bsId === null) {
            $result = $this->client->createPage($chapterBsId, $title, $content, $priority, $payloadTags);
            $bsId = (int)$result['id'];
            $this->say('Created page "' . $title . '".', 'new');
        } elseif ($force || (string)($stored['pushed_hash'] ?? '') !== $hash) {
            $result = $this->client->updatePage($bsId, $title, $content, $priority, $payloadTags);
            $this->say('Updated page "' . $title . '".');
        } else {
            $this->say('Page "' . $title . '" is already up to date.');
            return false;
        }

        $slug = (string)($result['slug'] ?? ($stored['bs_slug'] ?? ''));
        Targets::saveItem($this->targetId(), 'page', $pageId, [
            'bs_id' => $bsId,
            'bs_slug' => $slug,
            'bs_url' => $this->client->pageUrl((string)$this->target['book_slug'], $slug),
            'pushed_hash' => $hash,
        ]);

        return true;
    }

    /**
     * Second pass: rewrite the (🔗 Title) markers into real links now that every
     * chapter and page has a URL, and re-send only what actually changed.
     *
     * Resumable like the walk above: `links_page_id` in the state is the last
     * page settled, and the counts are accumulated across slices so that the
     * summary at the end describes the whole pass rather than the last piece
     * of it. The summary is said once, when the pass finishes.
     *
     * @param array<string,array<int,array<int,array<string,mixed>>>> $effectiveTags
     * @param array<string,mixed> $state updated in place: `phase` becomes `done` once the pass is complete
     */
    private function linkPass(array $effectiveTags, bool $force, array &$state, PublishBudget $budget): void
    {
        $projectId = (int)$this->project['id'];
        $pages = Db::rows('SELECT * FROM pages WHERE project_id = ? ORDER BY chapter_id, idx, id', [$projectId]);

        $withMarkers = array_values(array_filter($pages, static fn(array $p): bool => AutoLinker::hasMarkers((string)$p['content'])));
        if ($withMarkers === []) {
            $state['phase'] = 'done';
            return;
        }

        $index = LinkIndex::forTarget($projectId, $this->targetId());
        $items = Targets::items($this->targetId())['page'];

        $skipping = $state['links_page_id'] !== null;
        if ($skipping) {
            $known = false;
            foreach ($withMarkers as $page) {
                if ((int)$page['id'] === (int)$state['links_page_id']) {
                    $known = true;
                    break;
                }
            }
            // The page the last slice stopped at is gone: start the pass over.
            if (!$known) {
                $skipping = false;
                $state['links_page_id'] = null;
            }
        }

        foreach ($withMarkers as $page) {
            $pageId = (int)$page['id'];
            if ($skipping) {
                if ($pageId === (int)$state['links_page_id']) {
                    $skipping = false;
                }
                continue;
            }
            if ($budget->exhausted()) {
                return;
            }

            $applied = AutoLinker::apply((string)$page['content'], $index, $pageId);
            $state['links']['resolved'] += $applied['resolved'];
            $state['links']['pending'] += $applied['pending'];
            $state['links_pages']++;
            $state['links_unknown'] = array_slice(
                array_values(array_unique([...$state['links_unknown'], ...$applied['unknown']])),
                0,
                10
            );

            $stored = $items[$pageId] ?? null;
            if ($stored === null || $stored['bs_id'] === null) {
                $state['links_unpublished']++;
                $state['links_page_id'] = $pageId;
                continue; // not published yet – a normal push will pick it up
            }

            $tags = $effectiveTags['page'][$pageId] ?? [];
            $hash = Pages::pushHash((string)$page['title'], $applied['content'], $tags);
            if ($force || (string)$stored['pushed_hash'] !== $hash) {
                $result = $this->client->updatePage(
                    (int)$stored['bs_id'],
                    (string)$page['title'],
                    $applied['content'],
                    (int)$page['idx'] + 1,
                    Tags::apiPayload($tags)
                );
                $slug = (string)($result['slug'] ?? $stored['bs_slug']);
                Targets::saveItem($this->targetId(), 'page', $pageId, [
                    'bs_slug' => $slug,
                    'bs_url' => $this->client->pageUrl((string)$this->target['book_slug'], $slug),
                    'pushed_hash' => $hash,
                ]);
                $state['links']['updated']++;
            }
            $state['links_page_id'] = $pageId;
        }

        $this->say(sprintf(
            'Auto links: %d link(s) resolved across %d page(s), %d page(s) re-published.',
            $state['links']['resolved'],
            $state['links_pages'],
            $state['links']['updated']
        ), 'links');
        if ($state['links']['pending'] > 0) {
            $this->say('Auto links: ' . $state['links']['pending']
                . ' reference(s) still point at content that has not been published yet.', 'warn');
        }
        if ($state['links_unpublished'] > 0) {
            $this->say('Auto links: ' . $state['links_unpublished']
                . ' page(s) with references are not published yet and were left alone.', 'warn');
        }
        if ($state['links_unknown'] !== []) {
            $this->say('Auto links: no chapter or page matches ' . implode(', ', array_map(
                static fn(string $t): string => '"' . $t . '"',
                $state['links_unknown']
            )) . ' – those references were published as plain text.', 'warn');
        }

        $state['phase'] = 'done';
    }
}
