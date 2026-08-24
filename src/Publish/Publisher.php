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
            $result = $this->client->createBook($title, Markdown::toHtml($description), $payloadTags);
            $bookId = (int)$result['id'];
            $this->log[] = 'Created book "' . $title . '" (#' . $bookId . ').';
        } elseif ($force || (string)$this->project['pushed_hash'] !== $hash) {
            $result = $this->client->updateBook($bookId, $title, Markdown::toHtml($description), $payloadTags);
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
            $result = $this->client->createChapter($bookId, $title, Markdown::toHtml($description), $priority, $payloadTags);
            $bsId = (int)$result['id'];
            $this->log[] = 'Created chapter "' . $title . '".';
        } elseif ($force || (string)$chapter['pushed_hash'] !== $hash) {
            $result = $this->client->updateChapter($bsId, $title, Markdown::toHtml($description), $priority, $payloadTags);
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
