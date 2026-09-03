<?php
declare(strict_types=1);

namespace CourseForge\Publish;

use CourseForge\Support\Config;
use CourseForge\Support\Http;
use CourseForge\Support\HttpException;

/**
 * The BookStack REST API, reduced to what CourseForge needs.
 *
 * Everything is created once and updated in place afterwards, so a re-push
 * never duplicates a book, chapter or page. Reads distinguish carefully
 * between "the item is gone" (a real 404) and "I could not ask" (anything
 * else), because treating a transport error as "gone" would recreate the whole
 * course as a duplicate.
 */
class BookStackClient
{
    private readonly string $baseUrl;
    private readonly int $timeout;

    public function __construct(string $baseUrl, private readonly string $tokenId, private readonly string $tokenSecret)
    {
        $this->baseUrl = rtrim(trim($baseUrl), '/');
        $this->timeout = max(15, Config::int('app.bookstack_timeout_seconds', 240));
    }

    /** @param array<string,mixed> $profile */
    public static function fromProfile(array $profile, string $instanceId): self
    {
        foreach ((array)($profile['bookstack'] ?? []) as $instance) {
            if ((string)($instance['id'] ?? '') === $instanceId) {
                $client = new self(
                    (string)($instance['base_url'] ?? ''),
                    (string)($instance['token_id'] ?? ''),
                    (string)($instance['token_secret'] ?? '')
                );
                $client->assertConfigured((string)($instance['name'] ?? 'BookStack'));
                return $client;
            }
        }
        throw HttpException::unprocessable('BookStack instance "' . $instanceId . '" is not part of this profile.');
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /* -------------------------------------------------------------- shelves */

    /** @return array<int,array{id:int,name:string}> */
    public function shelves(): array
    {
        $out = [];
        $offset = 0;
        do {
            $data = $this->call('GET', '/shelves?count=100&offset=' . $offset . '&sort=%2Bname');
            foreach ((array)($data['data'] ?? []) as $shelf) {
                $out[] = ['id' => (int)$shelf['id'], 'name' => (string)$shelf['name']];
            }
            $total = (int)($data['total'] ?? count($out));
            $offset += 100;
        } while (count($out) < $total && $offset < 100000);

        return $out;
    }

    public function attachBookToShelf(int $shelfId, int $bookId): void
    {
        $shelf = $this->call('GET', '/shelves/' . $shelfId);

        // A shelf lists its books as objects, but some versions and proxies
        // hand back bare ids – accept either rather than crashing the push.
        $books = [];
        foreach ((array)($shelf['books'] ?? []) as $book) {
            $id = is_array($book) ? (int)($book['id'] ?? 0) : (int)$book;
            if ($id > 0) {
                $books[] = $id;
            }
        }
        if (in_array($bookId, $books, true)) {
            return;
        }

        $books[] = $bookId;
        $this->call('PUT', '/shelves/' . $shelfId, [
            'name' => (string)($shelf['name'] ?? ''),
            'books' => array_values(array_unique($books)),
        ]);
    }

    /* ---------------------------------------------------------------- books */

    /** @return array<string,mixed>|null */
    public function getBook(int $id): ?array
    {
        return $this->get('books', $id);
    }

    /** @param array<int,array{name:string,value:string}> $tags @return array<string,mixed> */
    public function createBook(string $name, string $descriptionHtml, array $tags): array
    {
        return $this->call('POST', '/books', ['name' => $name, 'description_html' => $descriptionHtml, 'tags' => $tags]);
    }

    /** @param array<int,array{name:string,value:string}> $tags @return array<string,mixed> */
    public function updateBook(int $id, string $name, string $descriptionHtml, array $tags): array
    {
        return $this->call('PUT', '/books/' . $id, ['name' => $name, 'description_html' => $descriptionHtml, 'tags' => $tags]);
    }

    /* ------------------------------------------------------------- chapters */

    /** @return array<string,mixed>|null */
    public function getChapter(int $id): ?array
    {
        return $this->get('chapters', $id);
    }

    /** @param array<int,array{name:string,value:string}> $tags @return array<string,mixed> */
    public function createChapter(int $bookId, string $name, string $descriptionHtml, int $priority, array $tags): array
    {
        return $this->call('POST', '/chapters', [
            'book_id' => $bookId,
            'name' => $name,
            'description_html' => $descriptionHtml,
            'priority' => $priority,
            'tags' => $tags,
        ]);
    }

    /** @param array<int,array{name:string,value:string}> $tags @return array<string,mixed> */
    public function updateChapter(int $id, string $name, string $descriptionHtml, int $priority, array $tags): array
    {
        return $this->call('PUT', '/chapters/' . $id, [
            'name' => $name,
            'description_html' => $descriptionHtml,
            'priority' => $priority,
            'tags' => $tags,
        ]);
    }

    /* ---------------------------------------------------------------- pages */

    /** @return array<string,mixed>|null */
    public function getPage(int $id): ?array
    {
        return $this->get('pages', $id);
    }

    /** @param array<int,array{name:string,value:string}> $tags @return array<string,mixed> */
    public function createPage(int $chapterId, string $name, string $markdown, int $priority, array $tags): array
    {
        return $this->call('POST', '/pages', [
            'chapter_id' => $chapterId,
            'name' => $name,
            'markdown' => $markdown,
            'priority' => $priority,
            'tags' => $tags,
        ]);
    }

    /** @param array<int,array{name:string,value:string}> $tags @return array<string,mixed> */
    public function updatePage(int $id, string $name, string $markdown, int $priority, array $tags): array
    {
        return $this->call('PUT', '/pages/' . $id, [
            'name' => $name,
            'markdown' => $markdown,
            'priority' => $priority,
            'tags' => $tags,
        ]);
    }

    /* -------------------------------------------------------------- linking */

    public function bookUrl(string $bookSlug): string
    {
        return $bookSlug === '' ? '' : $this->baseUrl . '/books/' . rawurlencode($bookSlug);
    }

    public function chapterUrl(string $bookSlug, string $chapterSlug): string
    {
        return ($bookSlug === '' || $chapterSlug === '')
            ? ''
            : $this->baseUrl . '/books/' . rawurlencode($bookSlug) . '/chapter/' . rawurlencode($chapterSlug);
    }

    public function pageUrl(string $bookSlug, string $pageSlug): string
    {
        return ($bookSlug === '' || $pageSlug === '')
            ? ''
            : $this->baseUrl . '/books/' . rawurlencode($bookSlug) . '/page/' . rawurlencode($pageSlug);
    }

    /* ------------------------------------------------------------ internals */

    private function assertConfigured(string $name): void
    {
        if ($this->baseUrl === '' || preg_match('#^https?://#i', $this->baseUrl) !== 1) {
            throw HttpException::unprocessable(
                'The BookStack instance "' . $name . '" needs a base URL starting with http:// or https://.'
            );
        }
        if ($this->tokenId === '' || $this->tokenSecret === '') {
            throw HttpException::unprocessable('The BookStack instance "' . $name . '" is missing its API token id or secret.');
        }
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return ['Authorization' => 'Token ' . $this->tokenId . ':' . $this->tokenSecret];
    }

    /*
     * The two methods below are the whole of the wire, and they are protected
     * rather than private so that a test can stand a wiki in memory behind
     * them: the publisher's promise that a book is never created twice is
     * only worth testing against a BookStack that can fail half way.
     */

    /** @return array<string,mixed>|null null only on a genuine 404. */
    protected function get(string $type, int $id): ?array
    {
        $res = Http::json('GET', $this->baseUrl . '/api/' . $type . '/' . $id, $this->headers(), null, $this->timeout);
        if ($res->status === 404) {
            return null;
        }
        if (!$res->ok()) {
            throw HttpException::badRequest(
                'Could not verify ' . $type . ' #' . $id . ' in BookStack (HTTP ' . $res->status
                . ($res->error !== '' ? ', ' . $res->error : '') . ') – aborting to avoid duplicates.'
            );
        }
        return is_array($res->data) ? $res->data : [];
    }

    /** @return array<string,mixed> */
    protected function call(string $method, string $path, mixed $payload = null): array
    {
        $res = Http::json($method, $this->baseUrl . '/api' . $path, $this->headers(), $payload, $this->timeout);
        if (!$res->ok()) {
            // BookStack's own words when it gave any - a validation message is
            // what makes a refused page fixable - and never the raw body, which
            // for a base URL pointed at the wrong thing is somebody else's page.
            throw HttpException::badRequest(
                'BookStack ' . $method . ' ' . $path . ' failed (HTTP ' . $res->status . '): ' . $res->errorMessage()
            );
        }
        return is_array($res->data) ? $res->data : [];
    }
}
