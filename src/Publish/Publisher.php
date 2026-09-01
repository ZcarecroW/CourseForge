<?php
declare(strict_types=1);

namespace CourseForge\Publish;

use CourseForge\Domain\AutoLinker;
use CourseForge\Domain\Chapters;
use CourseForge\Domain\LinkIndex;
use CourseForge\Domain\Pages;
use CourseForge\Domain\Profiles;
use CourseForge\Domain\Projects;
use CourseForge\Domain\Tags;
use CourseForge\Support\Config;
use CourseForge\Support\Db;
use CourseForge\Support\HttpException;
use CourseForge\Support\Markdown;

/**
 * Publishes a course into BookStack.
 *
 * Everything is idempotent: an item that already exists is updated in place, an
 * item that is byte-identical to what was pushed last time is skipped, and an
 * item that vanished in BookStack is recreated. What gets hashed is exactly
 * what gets sent – including resolved auto links – so the "out of sync" badges
 * in the UI stay honest.
 *
 * Auto links need two passes: while page 3 is written, page 40 may not exist in
 * BookStack yet. The first pass publishes everything, the second pass runs
 * after every URL is known and re-sends only the pages whose links actually
 * changed.
 */
final class Publisher
{
    /** @var string[] */
    private array $log = [];

    /** @param array<string,mixed> $project */
    private function __construct(
        private readonly string $username,
        private array $project,
        private readonly BookStackClient $client,
    ) {
    }

    public static function open(string $username, int $projectId): self
    {
        $project = Projects::require($username, $projectId);

        if ((string)$project['bs_instance_id'] === '') {
            throw HttpException::unprocessable('Choose a BookStack instance for this course first.');
        }
        if ($project['profile_id'] === null) {
            throw HttpException::unprocessable('This course has no profile assigned.');
        }

        $profile = Profiles::data($username, (int)$project['profile_id']);
        return new self($username, $project, BookStackClient::fromProfile($profile, (string)$project['bs_instance_id']));
    }

    /**
     * @param string $scope all | book | chapter | page
     * @return array{log:string[],links:array{resolved:int,pending:int,updated:int}}
     */
    public function push(string $scope = 'all', ?int $targetId = null, bool $force = false): array
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

        $bookId = $this->ensureBook($effectiveTags['project'][$projectId] ?? [], $force);

        if ($scope === 'book') {
            Projects::touch($projectId);
            return ['log' => $this->log, 'links' => ['resolved' => 0, 'pending' => 0, 'updated' => 0]];
        }

        $index = LinkIndex::forProject($projectId);

        foreach (Chapters::ordered($projectId) as $chapter) {
            if ($chapterFilter !== null && (int)$chapter['id'] !== $chapterFilter) {
                continue;
            }
            $chapterBsId = $this->ensureChapter($bookId, $chapter, $effectiveTags['chapter'][(int)$chapter['id']] ?? [], $force);

            foreach (Db::rows('SELECT * FROM pages WHERE chapter_id = ? ORDER BY idx', [(int)$chapter['id']]) as $page) {
                if ($pageFilter !== null && (int)$page['id'] !== $pageFilter) {
                    continue;
                }
                $this->ensurePage($chapterBsId, $page, $effectiveTags['page'][(int)$page['id']] ?? [], $index, $force);
            }
        }

        // The whole course is now in BookStack, so every link target has a URL.
        $links = $scope === 'all'
            ? $this->linkPass($effectiveTags, $force)
            : ['resolved' => 0, 'pending' => 0, 'updated' => 0];

        Projects::touch($projectId);
        return ['log' => $this->log, 'links' => $links];
    }

    /**
     * Resolves auto links on their own, without re-publishing anything else.
     *
     * @return array{log:string[],links:array{resolved:int,pending:int,updated:int}}
     */
    public function resolveLinks(bool $force = false): array
    {
        $projectId = (int)$this->project['id'];
        $links = $this->linkPass(Tags::resolved($projectId)['effective'], $force);
        Projects::touch($projectId);
        return ['log' => $this->log, 'links' => $links];
    }

    /* --------------------------------------------------------------- steps */

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
        $this->log[] = 'The description of ' . $what . ' is longer than the ' . $limit
            . ' characters BookStack accepts, so ' . (count($paragraphs) - count($kept))
            . ' of its ' . count($paragraphs) . ' paragraphs were left off the cover page. '
            . 'The full text is unchanged in CourseForge and is still what the pages are written from.';

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
        $projectId = (int)$this->project['id'];
        $title = Projects::bookTitle($this->project);
        $description = (string)$this->project['book_desc'];
        $hash = Projects::pushHash($title, $description, $tags);
        $payloadTags = Tags::apiPayload($tags);
        $bookId = $this->project['book_id'] !== null ? (int)$this->project['book_id'] : null;

        $existing = $bookId !== null ? $this->client->getBook($bookId) : null;
        if ($bookId !== null && $existing === null) {
            $this->log[] = 'Book #' . $bookId . ' no longer exists in BookStack – recreating it.';
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
            $this->log[] = 'Created book "' . $title . '" (#' . $bookId . ').';
        } elseif ($force || (string)$this->project['pushed_hash'] !== $hash) {
            $result = $this->client->updateBook($bookId, $title, $this->describe($description, 'the book'), $payloadTags);
            $this->log[] = 'Updated book "' . $title . '"'
                . ($payloadTags !== [] ? ' (' . count($payloadTags) . ' tag(s)).' : '.');
        } else {
            $result = $existing ?? [];
            $this->log[] = 'Book "' . $title . '" is already up to date.';
        }

        if ($this->project['shelf_id'] !== null) {
            $this->client->attachBookToShelf((int)$this->project['shelf_id'], $bookId);
        }

        $slug = (string)($result['slug'] ?? $this->project['book_slug']);
        $this->project = Projects::update($this->username, $projectId, [
            'book_id' => $bookId,
            'book_slug' => $slug,
            'book_url' => $this->client->bookUrl($slug),
            'pushed_hash' => $hash,
        ]);

        return $bookId;
    }

    /**
     * @param array<string,mixed> $chapter
     * @param array<int,array<string,mixed>> $tags
     */
    private function ensureChapter(int $bookId, array $chapter, array $tags, bool $force): int
    {
        $title = (string)$chapter['title'];
        $description = (string)$chapter['description'];
        $hash = Projects::pushHash($title, $description, $tags);
        $payloadTags = Tags::apiPayload($tags);
        $priority = (int)$chapter['idx'] + 1;
        $bsId = $chapter['bs_id'] !== null ? (int)$chapter['bs_id'] : null;

        $existing = $bsId !== null ? $this->client->getChapter($bsId) : null;
        if ($bsId !== null && $existing === null) {
            $this->log[] = 'Chapter "' . $title . '" no longer exists in BookStack – recreating it.';
            $bsId = null;
        }

        if ($bsId === null) {
            $result = $this->client->createChapter($bookId, $title, $this->describe($description, 'chapter "' . $title . '"'), $priority, $payloadTags);
            $bsId = (int)$result['id'];
            $this->log[] = 'Created chapter "' . $title . '".';
        } elseif ($force || (string)$chapter['pushed_hash'] !== $hash) {
            $result = $this->client->updateChapter($bsId, $title, $this->describe($description, 'chapter "' . $title . '"'), $priority, $payloadTags);
            $this->log[] = 'Updated chapter "' . $title . '".';
        } else {
            $result = $existing ?? [];
            $this->log[] = 'Chapter "' . $title . '" is already up to date.';
        }

        $slug = (string)($result['slug'] ?? $chapter['bs_slug']);
        Chapters::update((int)$chapter['id'], [
            'bs_id' => $bsId,
            'bs_slug' => $slug,
            'bs_url' => $this->client->chapterUrl((string)$this->project['book_slug'], $slug),
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
        $title = (string)$page['title'];
        $raw = (string)$page['content'];
        if (trim($raw) === '') {
            $this->log[] = 'Skipped "' . $title . '" – nothing generated yet.';
            return false;
        }

        $content = AutoLinker::render($raw, $index, (int)$page['id']);
        $hash = Pages::pushHash($title, $content, $tags);
        $payloadTags = Tags::apiPayload($tags);
        $priority = (int)$page['idx'] + 1;
        $bsId = $page['bs_id'] !== null ? (int)$page['bs_id'] : null;

        $existing = $bsId !== null ? $this->client->getPage($bsId) : null;
        if ($bsId !== null && $existing === null) {
            $this->log[] = 'Page "' . $title . '" no longer exists in BookStack – recreating it.';
            $bsId = null;
        }

        if ($bsId === null) {
            $result = $this->client->createPage($chapterBsId, $title, $content, $priority, $payloadTags);
            $bsId = (int)$result['id'];
            $this->log[] = 'Created page "' . $title . '".';
        } elseif ($force || (string)$page['pushed_hash'] !== $hash) {
            $result = $this->client->updatePage($bsId, $title, $content, $priority, $payloadTags);
            $this->log[] = 'Updated page "' . $title . '".';
        } else {
            $this->log[] = 'Page "' . $title . '" is already up to date.';
            return false;
        }

        $slug = (string)($result['slug'] ?? $page['bs_slug']);
        Pages::update((int)$page['id'], [
            'bs_id' => $bsId,
            'bs_slug' => $slug,
            'bs_url' => $this->client->pageUrl((string)$this->project['book_slug'], $slug),
            'pushed_hash' => $hash,
        ]);

        return true;
    }

    /**
     * Second pass: rewrite the (🔗 Title) markers into real links now that every
     * chapter and page has a URL, and re-send only what actually changed.
     *
     * @param array<string,array<int,array<int,array<string,mixed>>>> $effectiveTags
     * @return array{resolved:int,pending:int,updated:int}
     */
    private function linkPass(array $effectiveTags, bool $force): array
    {
        $projectId = (int)$this->project['id'];
        $pages = Db::rows('SELECT * FROM pages WHERE project_id = ? ORDER BY chapter_id, idx', [$projectId]);

        $withMarkers = array_filter($pages, static fn(array $p): bool => AutoLinker::hasMarkers((string)$p['content']));
        if ($withMarkers === []) {
            return ['resolved' => 0, 'pending' => 0, 'updated' => 0];
        }

        $index = LinkIndex::forProject($projectId);
        $resolved = 0;
        $pending = 0;
        $updated = 0;
        $unknown = [];
        $unpublished = 0;

        foreach ($withMarkers as $page) {
            $pageId = (int)$page['id'];
            $applied = AutoLinker::apply((string)$page['content'], $index, $pageId);
            $resolved += $applied['resolved'];
            $pending += $applied['pending'];
            $unknown = [...$unknown, ...$applied['unknown']];

            if ($page['bs_id'] === null) {
                $unpublished++;
                continue; // not published yet – a normal push will pick it up
            }

            $tags = $effectiveTags['page'][$pageId] ?? [];
            $hash = Pages::pushHash((string)$page['title'], $applied['content'], $tags);
            if (!$force && (string)$page['pushed_hash'] === $hash) {
                continue;
            }

            $result = $this->client->updatePage(
                (int)$page['bs_id'],
                (string)$page['title'],
                $applied['content'],
                (int)$page['idx'] + 1,
                Tags::apiPayload($tags)
            );
            $slug = (string)($result['slug'] ?? $page['bs_slug']);
            Pages::update($pageId, [
                'bs_slug' => $slug,
                'bs_url' => $this->client->pageUrl((string)$this->project['book_slug'], $slug),
                'pushed_hash' => $hash,
            ]);
            $updated++;
        }

        $this->log[] = sprintf(
            'Auto links: %d link(s) resolved across %d page(s), %d page(s) re-published.',
            $resolved,
            count($withMarkers),
            $updated
        );
        if ($pending > 0) {
            $this->log[] = 'Auto links: ' . $pending . ' reference(s) still point at content that has not been published yet.';
        }
        if ($unpublished > 0) {
            $this->log[] = 'Auto links: ' . $unpublished . ' page(s) with references are not published yet and were left alone.';
        }
        $unknown = array_slice(array_values(array_unique($unknown)), 0, 10);
        if ($unknown !== []) {
            $this->log[] = 'Auto links: no chapter or page matches ' . implode(', ', array_map(
                static fn(string $t): string => '"' . $t . '"',
                $unknown
            )) . ' – those references were published as plain text.';
        }

        return ['resolved' => $resolved, 'pending' => $pending, 'updated' => $updated];
    }
}
