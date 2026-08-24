<?php
declare(strict_types=1);

namespace CourseForge\Domain;

use CourseForge\Support\Config;
use CourseForge\Support\Db;
use CourseForge\Support\HttpException;
use CourseForge\Support\Text;

/** Courses: persistence, structure application and the tree the UI renders. */
final class Projects
{
    private const WRITABLE = [
        'name', 'topic', 'profile_id', 'structure_md', 'book_title', 'book_desc', 'settings',
        'bs_instance_id', 'shelf_id', 'shelf_name', 'book_id', 'book_slug', 'book_url', 'pushed_hash',
        'auto_tags', 'tag_pool', 'tag_pool_strict',
    ];

    /* -------------------------------------------------------------- basics */

    /**
     * Every course of one account, or of all of them.
     *
     * `null` lists the whole installation, which is what an administrator gets.
     * The owner travels with each row, so a shared listing can say whose it is
     * without a second query per course.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function all(?string $username): array
    {
        $where = $username === null ? '' : 'WHERE p.username = ?';
        $args = $username === null ? [] : [$username];

        $rows = Db::rows(
            "SELECT p.id, p.name, p.topic, p.profile_id, p.book_id, p.book_url, p.shelf_name,
                    p.settings, p.updated_at, p.created_at, p.username,
                    (SELECT COUNT(*) FROM chapters WHERE project_id = p.id) AS chapter_count,
                    (SELECT COUNT(*) FROM pages    WHERE project_id = p.id) AS page_count,
                    (SELECT COUNT(*) FROM pages    WHERE project_id = p.id AND TRIM(content) <> '') AS generated_count,
                    (SELECT COUNT(*) FROM pages    WHERE project_id = p.id AND bs_id IS NOT NULL) AS pushed_count,
                    (SELECT COUNT(*) FROM batch_jobs b
                      WHERE b.project_id = p.id
                        AND b.status NOT IN ('completed', 'failed', 'canceled')) AS open_runs
               FROM projects p {$where} ORDER BY p.updated_at DESC",
            $args
        );

        return array_map(static function (array $row): array {
            $effective = Details::resolve(Details::decode((string)$row['settings']));
            return [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'topic' => (string)$row['topic'],
                'owner' => (string)$row['username'],
                'profile_id' => $row['profile_id'] !== null ? (int)$row['profile_id'] : null,
                'book_id' => $row['book_id'] !== null ? (int)$row['book_id'] : null,
                'book_url' => (string)$row['book_url'],
                'shelf_name' => (string)$row['shelf_name'],
                'chapter_count' => (int)$row['chapter_count'],
                'page_count' => (int)$row['page_count'],
                'generated_count' => (int)$row['generated_count'],
                'pushed_count' => (int)$row['pushed_count'],
                'open_runs' => (int)$row['open_runs'],
                'auto_links' => (bool)($effective['features']['auto_links'] ?? false),
                'created_at' => (int)$row['created_at'],
                'updated_at' => (int)$row['updated_at'],
            ];
        }, $rows);
    }

    /** @return array<string,mixed>|null */
    public static function find(string $username, int $id): ?array
    {
        return Db::row('SELECT * FROM projects WHERE username = ? AND id = ?', [$username, $id]);
    }

    /** @return array<string,mixed> */
    public static function require(string $username, int $id): array
    {
        return self::find($username, $id) ?? throw HttpException::notFound('Project not found.');
    }

    /** @return array<string,mixed> */
    public static function create(string $username, string $name, string $topic, ?int $profileId): array
    {
        $now = time();
        Db::run(
            'INSERT INTO projects (username, profile_id, name, topic, created_at, updated_at) VALUES (?,?,?,?,?,?)',
            [$username, $profileId, $name, $topic, $now, $now]
        );
        return self::require($username, Db::lastId());
    }

    /** @param array<string,mixed> $fields @return array<string,mixed> */
    public static function update(string $username, int $id, array $fields): array
    {
        $set = [];
        $args = [];
        foreach ($fields as $key => $value) {
            if (in_array($key, self::WRITABLE, true)) {
                $set[] = $key . ' = ?';
                $args[] = $value;
            }
        }
        if ($set !== []) {
            $set[] = 'updated_at = ?';
            $args[] = time();
            $args[] = $username;
            $args[] = $id;
            Db::run('UPDATE projects SET ' . implode(', ', $set) . ' WHERE username = ? AND id = ?', $args);
        }
        return self::require($username, $id);
    }

    public static function delete(string $username, int $id): void
    {
        Db::run('DELETE FROM projects WHERE username = ? AND id = ?', [$username, $id]);
    }

    public static function touch(int $id): void
    {
        Db::run('UPDATE projects SET updated_at = ? WHERE id = ?', [time(), $id]);
    }

    /** @param array<string,mixed> $project */
    public static function bookTitle(array $project): string
    {
        $title = trim((string)$project['book_title']);
        return $title !== '' ? $title : (string)$project['name'];
    }

    /** @return array{features:array<string,int>,params:array<string,int|string>} */
    public static function settings(array $project): array
    {
        return Details::decode((string)($project['settings'] ?? '{}'));
    }

    /** @param array<string,mixed> $features @param array<string,mixed> $params */
    public static function patchDetails(string $username, int $id, array $features, array $params): void
    {
        $project = self::require($username, $id);
        $patched = Details::patch(self::settings($project), $features, $params);
        self::update($username, $id, ['settings' => Details::encode($patched)]);
    }

    /** @param array<int,array<string,mixed>> $effectiveTags */
    public static function pushHash(string $title, string $description, array $effectiveTags): string
    {
        return Text::hash($title, $description, Tags::signature($effectiveTags));
    }

    /* ---------------------------------------------------------- structure */

    /**
     * Writes a parsed outline into the database while preserving everything
     * that was already generated.
     *
     * Chapters and pages are matched by title, so a refinement that leaves a
     * title untouched keeps its content, its tags and its detail overrides.
     * Titles are not unique, though - two chapters called "Exercises" are a
     * perfectly ordinary outline - so the match is made by position among the
     * entries that share a title: the first "Exercises" of the outline takes
     * the first stored one, the second takes the second. A page is offered the
     * row already sitting in the chapter it is being written into before any
     * other, which is what lets a page that moved chapters keep its text
     * without stealing the place of one that did not move. Applying an
     * unchanged outline therefore matches every row to itself and changes
     * nothing at all.
     *
     * A page the outline stops naming is deleted, and the text on it goes with
     * it, with nothing left holding it afterwards. So when such a page has text,
     * the caller has to say it means it: `$confirmRemovals` false refuses the
     * whole apply and names the pages. Both front doors - the browser and the
     * MCP tool - come through this one guard, so neither of them can delete
     * written work that the other would have refused to touch.
     *
     * @param array<string,mixed> $project
     * @return array{removed_pages:int,removed_chapters:int}
     */
    public static function applyStructure(array $project, string $structureMd, bool $confirmRemovals = false): array
    {
        $parsed = Structure::parse($structureMd);
        // An unparsable answer must never delete existing work.
        if ($parsed['chapters'] === []) {
            throw HttpException::unprocessable(
                'The structure could not be parsed (no chapters found) – nothing was changed. Check the raw Markdown.'
            );
        }

        $projectId = (int)$project['id'];
        $username = (string)$project['username'];

        // Worked out before anything is written, because afterwards the only
        // honest thing left to say is which pages have already been lost.
        $atRisk = self::pagesLosingContent($project, $structureMd);
        if ($atRisk !== [] && !$confirmRemovals) {
            throw new HttpException(self::removalRefusal($atRisk), 422, ['at_risk' => $atRisk]);
        }

        return Db::transaction(static function () use ($parsed, $project, $projectId, $username, $structureMd): array {
            $plan = self::matchOutline($projectId, $parsed['chapters']);
            $autoTags = ['project' => [$projectId => $parsed['tags']], 'chapter' => [], 'page' => []];

            foreach ($parsed['chapters'] as $ci => $chapter) {
                $existingChapter = $plan['chapters'][$ci];
                if ($existingChapter !== null) {
                    $chapterId = (int)$existingChapter['id'];
                    Chapters::update($chapterId, ['idx' => $ci, 'description' => $chapter['description']]);
                } else {
                    Db::run('INSERT INTO chapters (project_id, idx, title, description) VALUES (?,?,?,?)',
                        [$projectId, $ci, $chapter['title'], $chapter['description']]);
                    $chapterId = Db::lastId();
                }
                $autoTags['chapter'][$chapterId] = $chapter['tags'];

                foreach ($chapter['pages'] as $pi => $page) {
                    $existingPage = $plan['pages'][$ci][$pi];
                    if ($existingPage !== null) {
                        $pageId = (int)$existingPage['id'];
                        Pages::update($pageId, ['idx' => $pi, 'chapter_id' => $chapterId]);
                    } else {
                        Db::run('INSERT INTO pages (project_id, chapter_id, idx, title, status, updated_at) VALUES (?,?,?,?,?,?)',
                            [$projectId, $chapterId, $pi, (string)$page['title'], 'pending', time()]);
                        $pageId = Db::lastId();
                    }
                    $autoTags['page'][$pageId] = $page['tags'];
                }
            }

            // Pages before chapters: a chapter row takes its pages with it, and
            // every page worth keeping has already been moved off it.
            $removedPages = self::deleteRows('pages', $projectId, self::rowIds($plan['removed_pages']));
            $removedChapters = self::deleteRows('chapters', $projectId, self::rowIds($plan['removed_chapters']));
            Tags::prune($projectId);

            if ((int)($project['auto_tags'] ?? 0) === 1) {
                Tags::syncAuto($username, $project, $autoTags);
            }

            $title = $parsed['title'];
            $name = (string)$project['name'];
            $fields = [
                'structure_md' => $structureMd,
                'book_desc' => $parsed['description'],
            ];
            // An outline with no "# " line says nothing about what the book is
            // called, so it must not stamp a placeholder over a real title.
            if ($title !== '') {
                $fields['book_title'] = $title;
                // A course still carrying the placeholder name takes the title
                // the outline gives it; one somebody has named keeps that name.
                if ($name === '' || $name === 'Untitled course') {
                    $fields['name'] = $title;
                }
            }
            self::update($username, $projectId, $fields);

            return ['removed_pages' => $removedPages, 'removed_chapters' => $removedChapters];
        });
    }

    /**
     * The written pages an outline would delete, worked out before it is applied.
     *
     * This runs the very matching applyStructure() is about to run, so what it
     * names is exactly what would be lost - not a title-counting approximation
     * that could warn about a page which in fact survives, or stay quiet about
     * one that does not.
     *
     * @param array<string,mixed> $project
     * @return string[] the titles of the pages that have text and would go
     */
    public static function pagesLosingContent(array $project, string $structureMd): array
    {
        $parsed = Structure::parse($structureMd);
        if ($parsed['chapters'] === []) {
            return []; // refused as unparsable, so nothing is at risk
        }

        $lost = [];
        foreach (self::matchOutline((int)$project['id'], $parsed['chapters'])['removed_pages'] as $row) {
            if (trim((string)$row['content']) !== '') {
                $lost[] = (string)$row['title'];
            }
        }
        return $lost;
    }

    /**
     * Why an outline was refused, for whoever is reading the 422.
     *
     * The MCP tool says the same thing in the words a model can act on, and
     * the browser turns the `at_risk` list into a dialog naming the pages.
     * This is what is left for a client that does neither.
     *
     * @param string[] $atRisk
     */
    private static function removalRefusal(array $atRisk): string
    {
        $named = array_slice($atRisk, 0, 10);
        $rest = count($atRisk) - count($named);

        return 'Nothing was changed. This outline no longer names ' . count($atRisk) . ' page(s) that have text on '
            . 'them, and applying it would delete that text with no way back: "' . implode('", "', $named) . '"'
            . ($rest > 0 ? ' and ' . $rest . ' more' : '') . '. Put those titles back into the outline exactly as '
            . 'they are, or confirm the removal to apply it anyway.';
    }

    /**
     * Which stored row each outline entry claims, and which rows nothing claims.
     *
     * The one place that decides what "the same chapter" and "the same page"
     * mean, so that the write and the warning about what the write would cost
     * can never be answered differently. Rows are queued per lowercased title
     * in the order they are shown, and taken from the front, which is what
     * makes duplicate titles behave: entry n of a title takes stored row n of
     * it. Pages get two passes - everything that can stay where it is takes its
     * place first, and only the leftovers are offered to entries elsewhere in
     * the outline, so a page that moved chapters cannot displace one that did
     * not.
     *
     * @param array<int,array{title:string,pages:array<int,array{title:string}>}> $chapters
     * @return array{
     *   chapters:array<int,array<string,mixed>|null>,
     *   pages:array<int,array<int,array<string,mixed>|null>>,
     *   removed_chapters:array<int,array<string,mixed>>,
     *   removed_pages:array<int,array<string,mixed>>
     * }
     */
    private static function matchOutline(int $projectId, array $chapters): array
    {
        $chapterQueue = [];
        foreach (Chapters::ordered($projectId) as $row) {
            $chapterQueue[mb_strtolower((string)$row['title'])][] = $row;
        }

        // LEFT JOIN, so a page whose chapter has gone missing is still seen -
        // and therefore still counted as removed rather than left behind.
        $pageQueue = [];
        $rows = Db::rows(
            'SELECT p.* FROM pages p LEFT JOIN chapters c ON c.id = p.chapter_id
              WHERE p.project_id = ? ORDER BY c.idx, p.idx, p.id',
            [$projectId]
        );
        foreach ($rows as $row) {
            $pageQueue[mb_strtolower((string)$row['title'])][] = $row;
        }

        $matchedChapters = [];
        foreach ($chapters as $ci => $chapter) {
            $key = mb_strtolower((string)$chapter['title']);
            $matchedChapters[$ci] = ($chapterQueue[$key] ?? []) === [] ? null : array_shift($chapterQueue[$key]);
        }

        // Pass one: a page that is still in the chapter it was in keeps its row.
        $matchedPages = [];
        foreach ($chapters as $ci => $chapter) {
            $chapterId = $matchedChapters[$ci] === null ? 0 : (int)$matchedChapters[$ci]['id'];
            foreach ($chapter['pages'] as $pi => $page) {
                $matchedPages[$ci][$pi] = null;
                $key = mb_strtolower((string)$page['title']);
                foreach ($pageQueue[$key] ?? [] as $slot => $row) {
                    if ((int)$row['chapter_id'] === $chapterId) {
                        $matchedPages[$ci][$pi] = $row;
                        unset($pageQueue[$key][$slot]);
                        break;
                    }
                }
            }
        }

        // Pass two: what is left goes to the entries that found nothing, which
        // is how a renamed chapter keeps the pages that came with it.
        foreach ($chapters as $ci => $chapter) {
            foreach ($chapter['pages'] as $pi => $page) {
                $key = mb_strtolower((string)$page['title']);
                if ($matchedPages[$ci][$pi] === null && ($pageQueue[$key] ?? []) !== []) {
                    $matchedPages[$ci][$pi] = array_shift($pageQueue[$key]);
                }
            }
        }

        $leftover = static function (array $queue): array {
            $left = [];
            foreach ($queue as $rows) {
                foreach ($rows as $row) {
                    $left[] = $row;
                }
            }
            return $left;
        };

        return [
            'chapters' => $matchedChapters,
            'pages' => $matchedPages,
            'removed_chapters' => $leftover($chapterQueue),
            'removed_pages' => $leftover($pageQueue),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return int[]
     */
    private static function rowIds(array $rows): array
    {
        return array_map(static fn(array $row): int => (int)$row['id'], $rows);
    }

    /**
     * Rebuilds structure_md from the current rows.
     *
     * Must run whenever a title changes: applyStructure() matches existing
     * content by title, so a rename would otherwise orphan the content on the
     * next apply.
     */
    public static function resyncStructure(string $username, int $id): void
    {
        $project = self::require($username, $id);
        $auto = Tags::autoNames($id); // keep the {{Tag}} markers alive

        $chapters = [];
        foreach (Chapters::ordered($id) as $chapter) {
            $chapterId = (int)$chapter['id'];
            $pages = [];
            foreach (Db::rows('SELECT id, title FROM pages WHERE chapter_id = ? ORDER BY idx', [$chapterId]) as $page) {
                $pages[] = ['title' => (string)$page['title'], 'tags' => $auto['page'][(int)$page['id']] ?? []];
            }
            $chapters[] = [
                'title' => (string)$chapter['title'],
                'description' => (string)$chapter['description'],
                'tags' => $auto['chapter'][$chapterId] ?? [],
                'pages' => $pages,
            ];
        }

        self::update($username, $id, [
            'structure_md' => Structure::toMarkdown(
                self::bookTitle($project),
                (string)$project['book_desc'],
                $chapters,
                $auto['project'][$id] ?? []
            ),
        ]);
    }

    /**
     * Deletes exactly the rows named, and only within this course.
     *
     * @param int[] $ids
     */
    private static function deleteRows(string $table, int $projectId, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return Db::run(
            'DELETE FROM ' . $table . ' WHERE project_id = ? AND id IN (' . $placeholders . ')',
            [$projectId, ...$ids]
        )->rowCount();
    }

    /* --------------------------------------------------------------- tree */

    /**
     * The complete course as the UI needs it: chapters, page summaries,
     * resolved tags, resolved details, sync flags and auto-link statistics.
     *
     * Page bodies are deliberately excluded – see Pages::summary().
     *
     * @return array<string,mixed>
     */
    public static function tree(string $username, int $id): array
    {
        $project = self::require($username, $id);

        // Self-heal rows whose generation was killed mid-flight (restart, fatal).
        Db::run(
            "UPDATE pages SET status = 'error',
                    error = 'Generation did not finish (server restart or timeout). Please retry.'
              WHERE project_id = ? AND status = 'generating' AND updated_at < ?",
            [$id, time() - max(3600, Config::int('app.ai_timeout_seconds', 1800) * 2)]
        );

        $tags = Tags::resolved($id);
        $index = LinkIndex::forProject($id);
        $projectSettings = self::settings($project);

        $pagesByChapter = [];
        $links = ['markers' => 0, 'resolved' => 0, 'pending' => 0, 'dropped' => 0];
        $counts = ['pages' => 0, 'generated' => 0, 'pushed' => 0, 'dirty' => 0, 'errors' => 0];

        $chapterSettings = [];
        $chapters = Chapters::ordered($id);
        foreach ($chapters as $chapter) {
            $chapterSettings[(int)$chapter['id']] = Chapters::settings($chapter);
        }

        foreach (Db::rows('SELECT * FROM pages WHERE project_id = ? ORDER BY chapter_id, idx', [$id]) as $page) {
            $pageId = (int)$page['id'];
            $chapterId = (int)$page['chapter_id'];

            $applied = AutoLinker::apply((string)$page['content'], $index, $pageId);
            $links['markers'] += AutoLinker::countMarkers((string)$page['content']);
            $links['resolved'] += $applied['resolved'];
            $links['pending'] += $applied['pending'];
            $links['dropped'] += $applied['dropped'];

            $summary = Pages::summary(
                $page,
                $tags['own']['page'][$pageId] ?? [],
                $tags['effective']['page'][$pageId] ?? [],
                $projectSettings,
                $chapterSettings[$chapterId] ?? ['features' => [], 'params' => []],
                $applied['content'],
            );

            $counts['pages']++;
            $counts['generated'] += $summary['has_content'] ? 1 : 0;
            $counts['pushed'] += $summary['pushed'] ? 1 : 0;
            $counts['dirty'] += $summary['dirty'] ? 1 : 0;
            $counts['errors'] += $summary['status'] === 'error' ? 1 : 0;

            $pagesByChapter[$chapterId][] = $summary;
        }

        $chapterList = [];
        foreach ($chapters as $chapter) {
            $chapterId = (int)$chapter['id'];
            $effectiveTags = $tags['effective']['chapter'][$chapterId] ?? [];
            $bsId = $chapter['bs_id'] !== null ? (int)$chapter['bs_id'] : null;

            $chapterList[] = [
                'id' => $chapterId,
                'idx' => (int)$chapter['idx'],
                'title' => (string)$chapter['title'],
                'description' => (string)$chapter['description'],
                'bs_id' => $bsId,
                'bs_url' => (string)$chapter['bs_url'],
                'pushed' => $bsId !== null,
                'dirty' => $bsId !== null && (string)$chapter['pushed_hash'] !== self::pushHash(
                    (string)$chapter['title'],
                    (string)$chapter['description'],
                    $effectiveTags
                ),
                'tags' => $tags['own']['chapter'][$chapterId] ?? [],
                'effective_tags' => $effectiveTags,
                'details' => Details::describe($chapterSettings[$chapterId], $projectSettings),
                'pages' => $pagesByChapter[$chapterId] ?? [],
            ];
        }

        $effectiveTags = $tags['effective']['project'][$id] ?? [];
        $bookId = $project['book_id'] !== null ? (int)$project['book_id'] : null;

        return [
            'id' => $id,
            'name' => (string)$project['name'],
            'topic' => (string)$project['topic'],
            'profile_id' => $project['profile_id'] !== null ? (int)$project['profile_id'] : null,
            'structure_md' => (string)$project['structure_md'],
            'book_title' => (string)$project['book_title'],
            'book_desc' => (string)$project['book_desc'],
            'bs_instance_id' => (string)$project['bs_instance_id'],
            'shelf_id' => $project['shelf_id'] !== null ? (int)$project['shelf_id'] : null,
            'shelf_name' => (string)$project['shelf_name'],
            'book_id' => $bookId,
            'book_url' => (string)$project['book_url'],
            'auto_tags' => (int)$project['auto_tags'] === 1,
            'tag_pool' => (string)$project['tag_pool'],
            'tag_pool_strict' => (int)$project['tag_pool_strict'] === 1,
            'created_at' => (int)$project['created_at'],
            'updated_at' => (int)$project['updated_at'],
            'dirty' => $bookId !== null && (string)$project['pushed_hash'] !== self::pushHash(
                self::bookTitle($project),
                (string)$project['book_desc'],
                $effectiveTags
            ),
            'tags' => $tags['own']['project'][$id] ?? [],
            'effective_tags' => $effectiveTags,
            'details' => Details::describe($projectSettings),
            'stats' => [
                'chapters' => count($chapterList),
                'pages' => $counts['pages'],
                'generated' => $counts['generated'],
                'pushed' => $counts['pushed'],
                'dirty' => $counts['dirty'],
                'errors' => $counts['errors'],
                'links' => $links,
            ],
            'chapters' => $chapterList,
        ];
    }
}
