<?php
declare(strict_types=1);

namespace CourseForge\Domain;

use CourseForge\Publish\Targets;
use CourseForge\Support\Config;
use CourseForge\Support\Db;
use CourseForge\Support\HttpException;
use CourseForge\Support\Text;

/** Courses: persistence, structure application and the tree the UI renders. */
final class Projects
{
    /**
     * Fields this class will write.
     *
     * `bs_instance_id`, `shelf_id`, `shelf_name`, `book_id`, `book_slug`,
     * `book_url` and `pushed_hash` are deliberately not among them any more.
     * They are still on the row and still read everywhere they always were, but
     * they are now a copy of the course's first publishing destination and
     * `Publish\Targets::mirror()` is the one thing that writes them. A second
     * writer would be a course reporting a book that no destination holds.
     */
    private const WRITABLE = [
        'name', 'topic', 'profile_id', 'structure_md', 'book_title', 'book_desc', 'settings',
        'auto_tags', 'tag_pool', 'tag_pool_strict',
        'research_md', 'research_at', 'research_source',
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
                    (SELECT COUNT(*) FROM publish_targets WHERE project_id = p.id) AS target_count,
                    (SELECT COUNT(*) FROM batch_jobs b
                      WHERE b.project_id = p.id
                        AND b.status NOT IN ('completed', 'failed', 'canceled')) AS open_runs
               FROM projects p {$where} ORDER BY p.updated_at DESC",
            $args
        );

        return array_map(static function (array $row): array {
            $effective = Details::resolve(self::profileSettings($row), Details::decode((string)$row['settings']));
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
                // How many wikis this course publishes to. The counts above are
                // about the first of them, which is what the columns they read
                // mirror; this is the only hint in the listing that there may
                // be more, and the course itself has the detail.
                'target_count' => (int)$row['target_count'],
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

    /**
     * The level above the course: what its profile decided.
     *
     * A profile carries content defaults of its own, so every course written
     * with it starts from the same choices - the chain is
     * config default → profile → course → chapter → page, the value that wins
     * being the one closest to the page. A course without a profile, or whose
     * profile has decided nothing, inherits the installation's defaults
     * directly, which is exactly what an empty layer resolves to.
     *
     * @param array<string,mixed> $project a row from the projects table
     * @return array{features:array<string,int>,params:array<string,int|string>}
     */
    public static function profileSettings(array $project): array
    {
        $profileId = $project['profile_id'] ?? null;
        if ($profileId === null || (string)($project['username'] ?? '') === '') {
            return ['features' => [], 'params' => []];
        }
        return Profiles::details((string)$project['username'], (int)$profileId);
    }

    /**
     * The ancestors of whatever sits under a course, in resolution order: the
     * profile, then the course, then any levels handed in below it. Spread into
     * Details::resolve() - `Details::resolve(...Projects::chain($project))` is
     * the whole answer for the course itself, and the chapter and page settings
     * go on the end for a page.
     *
     * @param array<string,mixed> $project
     * @param array{features:array<string,int>,params:array<string,int|string>} ...$below
     * @return array<int,array{features:array<string,int>,params:array<string,int|string>}>
     */
    public static function chain(array $project, array ...$below): array
    {
        return [self::profileSettings($project), self::settings($project), ...$below];
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
        // The read and the write are one transaction. They used to be two
        // separate statements over a whole JSON column, so two overlapping
        // toggles both started from the same stored document and the second
        // wrote the first one away - silently, with a 200 each. SQLite
        // serialises writers, so inside a transaction the second read sees
        // what the first committed.
        Db::transaction(static function () use ($username, $id, $features, $params): void {
            $project = self::require($username, $id);
            $patched = Details::patch(self::settings($project), $features, $params);
            self::update($username, $id, ['settings' => Details::encode($patched)]);
        });
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
                    // The title goes in too. Matching is case-insensitive so
                    // that a change of capitalisation keeps the content rather
                    // than replacing the row - but the outline is still asking
                    // for that capitalisation, and not writing it left the rows
                    // and the stored outline disagreeing about the same
                    // chapter, which is exactly what breaks the next apply.
                    Chapters::update($chapterId, [
                        'idx' => $ci,
                        'title' => $chapter['title'],
                        'description' => $chapter['description'],
                    ]);
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
                        Pages::update($pageId, [
                            'idx' => $pi,
                            'chapter_id' => $chapterId,
                            'title' => (string)$page['title'],
                        ]);
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
        $profileSettings = self::profileSettings($project);

        /* Where this course publishes.
         *
         * Every count below is asked of each switched-on target and then
         * folded into one answer, because that is the question the screen is
         * really asking: "is there anything left to push?". So an item counts
         * as published when every target has it, and as out of sync when at
         * least one target would be written to - which is what a second wiki
         * added to a finished course should say, and what a single-destination
         * course has always said, unchanged.
         *
         * A course with nothing switched on falls back to the columns on the
         * rows themselves, which mirror whichever target is first. */
        $targetRows = Targets::all($id);
        $instances = Targets::instancesOf($username, $project['profile_id'] === null ? null : (int)$project['profile_id']);
        $enabledTargets = array_values(array_filter($targetRows, static fn(array $t): bool => (int)$t['enabled'] === 1));
        $manyTargets = count($targetRows) > 1;

        // Every target, not only the switched-on ones: a destination that is
        // paused has still been published to, and a row saying "0 pages" about
        // a wiki holding the whole course is the kind of wrong that makes
        // somebody publish it again.
        $targetIndex = [];
        $targetItems = [];
        $targetStats = [];
        foreach ($targetRows as $target) {
            $targetId = (int)$target['id'];
            $targetIndex[$targetId] = LinkIndex::forTarget($id, $targetId);
            $targetItems[$targetId] = Targets::items($targetId);
            $targetStats[$targetId] = ['chapters' => 0, 'chapters_dirty' => 0, 'pages' => 0, 'pages_dirty' => 0];
        }

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
            $content = (string)$page['content'];
            $effectiveTags = $tags['effective']['page'][$pageId] ?? [];

            $applied = AutoLinker::apply($content, $index, $pageId);
            $links['markers'] += AutoLinker::countMarkers($content);
            $links['resolved'] += $applied['resolved'];
            $links['pending'] += $applied['pending'];
            $links['dropped'] += $applied['dropped'];

            $summary = Pages::summary(
                $page,
                $tags['own']['page'][$pageId] ?? [],
                $effectiveTags,
                $projectSettings,
                $chapterSettings[$chapterId] ?? ['features' => [], 'params' => []],
                $applied['content'],
                $profileSettings,
            );

            if ($targetRows !== []) {
                // A page with markers is different text in every wiki, because
                // its links point inside the one it is in - so it is rendered
                // and fingerprinted per target. A page without them is the same
                // everywhere, and rendering it again per target would be work
                // with a guaranteed answer.
                $hasMarkers = AutoLinker::hasMarkers($content);
                $perPage = [];
                foreach ($targetRows as $target) {
                    $targetId = (int)$target['id'];
                    $rendered = $hasMarkers
                        ? AutoLinker::apply($content, $targetIndex[$targetId], $pageId)['content']
                        : $applied['content'];
                    $state = self::itemState(
                        $targetItems[$targetId]['page'][$pageId] ?? null,
                        Pages::pushHash((string)$page['title'], $rendered, $effectiveTags),
                        $summary['has_content'],
                    );
                    $perPage[] = ['target_id' => $targetId, 'enabled' => (int)$target['enabled'] === 1] + $state;
                    $targetStats[$targetId]['pages'] += $state['pushed'] ? 1 : 0;
                    $targetStats[$targetId]['pages_dirty'] += $state['dirty'] ? 1 : 0;
                }
                $summary = self::fold($summary, $perPage, $manyTargets);
            }

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
            $chapterHash = self::pushHash(
                (string)$chapter['title'],
                (string)$chapter['description'],
                $effectiveTags
            );

            $entry = [
                'id' => $chapterId,
                'idx' => (int)$chapter['idx'],
                'title' => (string)$chapter['title'],
                'description' => (string)$chapter['description'],
                'bs_id' => $bsId,
                'bs_url' => (string)$chapter['bs_url'],
                'pushed' => $bsId !== null,
                'dirty' => $bsId !== null && (string)$chapter['pushed_hash'] !== $chapterHash,
                'tags' => $tags['own']['chapter'][$chapterId] ?? [],
                'effective_tags' => $effectiveTags,
                'details' => Details::describe($chapterSettings[$chapterId], $profileSettings, $projectSettings),
                'pages' => $pagesByChapter[$chapterId] ?? [],
            ];

            if ($targetRows !== []) {
                $perChapter = [];
                foreach ($targetRows as $target) {
                    $targetId = (int)$target['id'];
                    $state = self::itemState($targetItems[$targetId]['chapter'][$chapterId] ?? null, $chapterHash, true);
                    $perChapter[] = ['target_id' => $targetId, 'enabled' => (int)$target['enabled'] === 1] + $state;
                    $targetStats[$targetId]['chapters'] += $state['pushed'] ? 1 : 0;
                    $targetStats[$targetId]['chapters_dirty'] += $state['dirty'] ? 1 : 0;
                }
                $entry = self::fold($entry, $perChapter, $manyTargets);
            }

            $chapterList[] = $entry;
        }

        $effectiveTags = $tags['effective']['project'][$id] ?? [];
        $bookId = $project['book_id'] !== null ? (int)$project['book_id'] : null;
        $bookHash = self::pushHash(self::bookTitle($project), (string)$project['book_desc'], $effectiveTags);

        $targets = [];
        foreach ($targetRows as $target) {
            $targetId = (int)$target['id'];
            $described = Targets::describe($target, $instances);
            $described['dirty'] = $described['book_id'] !== null && $described['pushed_hash'] !== $bookHash;
            $described['stats'] = $targetStats[$targetId] ?? ['chapters' => 0, 'chapters_dirty' => 0, 'pages' => 0, 'pages_dirty' => 0];
            unset($described['pushed_hash']); // a fingerprint is server business
            $targets[] = $described;
        }

        // The book counts as changed when any switched-on wiki would be written
        // to. With one target that is exactly the old expression.
        $bookDirty = $bookId !== null && (string)$project['pushed_hash'] !== $bookHash;
        foreach ($enabledTargets as $target) {
            if ($target['book_id'] !== null && (string)$target['pushed_hash'] !== $bookHash) {
                $bookDirty = true;
            }
        }

        return [
            'id' => $id,
            'name' => (string)$project['name'],
            'topic' => (string)$project['topic'],
            'profile_id' => $project['profile_id'] !== null ? (int)$project['profile_id'] : null,
            'structure_md' => (string)$project['structure_md'],
            'book_title' => (string)$project['book_title'],
            'book_desc' => (string)$project['book_desc'],
            // The first target, repeated where a single-destination course has
            // always found it. `targets` below is the whole list.
            'bs_instance_id' => (string)$project['bs_instance_id'],
            'shelf_id' => $project['shelf_id'] !== null ? (int)$project['shelf_id'] : null,
            'shelf_name' => (string)$project['shelf_name'],
            'book_id' => $bookId,
            'book_url' => (string)$project['book_url'],
            'targets' => $targets,
            'auto_tags' => (int)$project['auto_tags'] === 1,
            'tag_pool' => (string)$project['tag_pool'],
            'tag_pool_strict' => (int)$project['tag_pool_strict'] === 1,
            'research' => [
                'text' => Research::of($project),
                'at' => Research::at($project),
                'age_days' => Research::ageInDays($project),
                'freshness' => Research::freshness($project),
                'source' => (string)($project['research_source'] ?? ''),
                'max_characters' => Research::MAX_CHARS,
            ],
            'created_at' => (int)$project['created_at'],
            'updated_at' => (int)$project['updated_at'],
            'dirty' => $bookDirty,
            'tags' => $tags['own']['project'][$id] ?? [],
            'effective_tags' => $effectiveTags,
            'details' => Details::describe($projectSettings, $profileSettings),
            // Whether the level above the course has decided anything, so the
            // Details tab can say "inherits from the profile" only when it does.
            'profile_decides' => $profileSettings['features'] !== [] || $profileSettings['params'] !== [],
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

    /**
     * What one wiki is holding of one chapter or one page.
     *
     * Three answers, because two of them are different questions. `dirty` is
     * the one this course has always shown: it is there and it has changed
     * since. `outstanding` is "a push would write this", which is also true of
     * an item missing from a wiki its siblings are already in - work
     * outstanding just as surely, and the reason a second wiki added to a
     * finished course lights up. A page with nothing written on it is the
     * exception: the publisher skips it, so its absence is not work.
     *
     * @param array<string,mixed>|null $stored the publish_items row, if any
     * @return array{bs_id:int|null,bs_url:string,pushed:bool,dirty:bool,outstanding:bool}
     */
    private static function itemState(?array $stored, string $hash, bool $pushable): array
    {
        $bsId = ($stored['bs_id'] ?? null) !== null ? (int)$stored['bs_id'] : null;
        $dirty = $bsId !== null && (string)($stored['pushed_hash'] ?? '') !== $hash;

        return [
            'bs_id' => $bsId,
            'bs_url' => (string)($stored['bs_url'] ?? ''),
            'pushed' => $bsId !== null,
            'dirty' => $dirty,
            'outstanding' => $bsId === null ? $pushable : $dirty,
        ];
    }

    /**
     * Folds what every wiki says about one item into the two flags the screens
     * draw, and carries the detail alongside when there is more than one wiki
     * to have an opinion.
     *
     * Published means published everywhere; out of sync means at least one wiki
     * would be written to, and only once the item is somewhere - an item that
     * has never been published anywhere is "not published", which is a
     * different thing to say and a different badge.
     *
     * Only the switched-on wikis are folded, because the two flags are answers
     * to "is there anything left to push?" and a paused destination is not
     * going to be pushed to. All of them travel in `targets`, though: what a
     * paused wiki is holding is still worth being able to see.
     *
     * @param array<string,mixed> $entry
     * @param array<int,array<string,mixed>> $states
     * @return array<string,mixed>
     */
    private static function fold(array $entry, array $states, bool $many): array
    {
        $live = array_values(array_filter($states, static fn(array $s): bool => $s['enabled']));

        $anywhere = false;
        $everywhere = $live !== [];
        $outstanding = false;

        foreach ($live as $state) {
            $anywhere = $anywhere || $state['pushed'];
            $everywhere = $everywhere && $state['pushed'];
            $outstanding = $outstanding || $state['outstanding'];
        }

        // Nothing is switched on, so there is nothing to be in sync with. The
        // columns the entry already carries mirror the first destination, and
        // saying what that one holds is better than saying nothing at all.
        if ($live !== []) {
            $entry['pushed'] = $everywhere;
            $entry['dirty'] = $anywhere && $outstanding;
        }
        if ($many) {
            $entry['targets'] = $states;
        }
        return $entry;
    }
}
