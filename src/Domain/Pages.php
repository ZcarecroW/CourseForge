<?php
declare(strict_types=1);

namespace CourseForge\Domain;

use CourseForge\Support\Db;
use CourseForge\Support\HttpException;
use CourseForge\Support\Text;

/** Page rows plus the shapes the API hands to the browser. */
final class Pages
{
    private const WRITABLE = [
        'idx', 'chapter_id', 'title', 'content', 'extra_context', 'settings',
        'status', 'error',
    ];

    /** @return array<string,mixed>|null */
    public static function find(int $projectId, int $id): ?array
    {
        return Db::row('SELECT * FROM pages WHERE project_id = ? AND id = ?', [$projectId, $id]);
    }

    /** @return array<string,mixed> */
    public static function require(int $projectId, int $id): array
    {
        return self::find($projectId, $id) ?? throw HttpException::notFound('Page not found.');
    }

    /**
     * Every page of a course in reading order, enriched with its chapter and a
     * running index – the shape the page generator needs.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function ordered(int $projectId): array
    {
        $rows = Db::rows(
            'SELECT p.*, c.title AS chapter_title, c.description AS chapter_description,
                    c.idx AS chapter_idx, c.settings AS chapter_settings
               FROM pages p JOIN chapters c ON c.id = p.chapter_id
              WHERE p.project_id = ? ORDER BY c.idx, p.idx',
            [$projectId]
        );
        foreach ($rows as $i => $row) {
            $rows[$i]['global_idx'] = $i;
        }
        return $rows;
    }

    /** @param array<string,mixed> $fields */
    public static function update(int $id, array $fields): void
    {
        $set = [];
        $args = [];
        foreach ($fields as $key => $value) {
            if (in_array($key, self::WRITABLE, true)) {
                $set[] = $key . ' = ?';
                $args[] = $value;
            }
        }
        if ($set === []) {
            return;
        }
        $set[] = 'updated_at = ?';
        $args[] = time();
        $args[] = $id;
        Db::run('UPDATE pages SET ' . implode(', ', $set) . ' WHERE id = ?', $args);
    }

    /** @return array{features:array<string,int>,params:array<string,int|string>} */
    public static function settings(array $page): array
    {
        return Details::decode((string)($page['settings'] ?? '{}'));
    }

    /** @param array<string,mixed> $features @param array<string,mixed> $params */
    public static function patchDetails(int $projectId, int $id, array $features, array $params): void
    {
        // The read and the write are one transaction. They used to be two
        // separate statements over a whole JSON column, so two overlapping
        // toggles both started from the same stored document and the second
        // wrote the first one away - silently, with a 200 each. SQLite
        // serialises writers, so inside a transaction the second read sees
        // what the first committed.
        Db::transaction(static function () use ($projectId, $id, $features, $params): void {
            $page = self::require($projectId, $id);
            $patched = Details::patch(self::settings($page), $features, $params);
            self::update($id, ['settings' => Details::encode($patched)]);
        });
    }

    /* ------------------------------------------------------------- shaping */

    /**
     * The summary shape used inside the project tree. Deliberately without
     * `content`: a 50-chapter course would otherwise ship megabytes of Markdown
     * on every refresh. The editor loads one page at a time instead.
     *
     * @param array<string,mixed> $page
     * @param array<int,array<string,mixed>> $ownTags
     * @param array<int,array<string,mixed>> $effectiveTags
     * @param array{features:array<string,int>,params:array<string,int|string>} $projectSettings
     * @param array{features:array<string,int>,params:array<string,int|string>} $chapterSettings
     * @return array<string,mixed>
     */
    public static function summary(
        array $page,
        array $ownTags,
        array $effectiveTags,
        array $projectSettings,
        array $chapterSettings,
        string $renderedContent,
    ): array {
        $content = (string)$page['content'];
        $pushedHash = (string)$page['pushed_hash'];
        $bsId = $page['bs_id'] !== null ? (int)$page['bs_id'] : null;

        return [
            'id' => (int)$page['id'],
            'chapter_id' => (int)$page['chapter_id'],
            'idx' => (int)$page['idx'],
            'title' => (string)$page['title'],
            'status' => (string)$page['status'],
            'error' => (string)$page['error'],
            'has_content' => trim($content) !== '',
            'has_context' => trim((string)$page['extra_context']) !== '',
            'word_count' => Text::words($content),
            'link_markers' => AutoLinker::countMarkers($content),
            'bs_id' => $bsId,
            'bs_url' => (string)$page['bs_url'],
            'pushed' => $bsId !== null,
            'dirty' => $bsId !== null && $pushedHash !== self::pushHash((string)$page['title'], $renderedContent, $effectiveTags),
            'updated_at' => (int)$page['updated_at'],
            'tags' => $ownTags,
            'effective_tags' => $effectiveTags,
            'details' => Details::describe(self::settings($page), $projectSettings, $chapterSettings),
        ];
    }

    /**
     * The full shape for the editor: everything from summary() plus the text.
     *
     * @return array<string,mixed>
     */
    public static function detail(int $projectId, int $id): array
    {
        $page = self::require($projectId, $id);
        $project = Db::row('SELECT settings FROM projects WHERE id = ?', [$projectId]) ?? ['settings' => '{}'];
        $chapter = Chapters::require($projectId, (int)$page['chapter_id']);

        $resolved = Tags::resolved($projectId);
        $effectiveTags = $resolved['effective']['page'][(int)$page['id']] ?? [];
        $rendered = AutoLinker::render((string)$page['content'], LinkIndex::forProject($projectId), (int)$page['id']);

        $summary = self::summary(
            $page,
            $resolved['own']['page'][(int)$page['id']] ?? [],
            $effectiveTags,
            Details::decode((string)$project['settings']),
            Chapters::settings($chapter),
            $rendered,
        );

        return $summary + [
            'content' => (string)$page['content'],
            'extra_context' => (string)$page['extra_context'],
            'rendered_links' => $rendered !== (string)$page['content'],
        ];
    }

    /**
     * The fingerprint of what actually gets pushed. Auto links are resolved
     * before hashing, so publishing a link change is detected as "out of sync"
     * exactly once.
     *
     * @param array<int,array<string,mixed>> $effectiveTags
     */
    public static function pushHash(string $title, string $renderedContent, array $effectiveTags): string
    {
        return Text::hash($title, $renderedContent, Tags::signature($effectiveTags));
    }
}
