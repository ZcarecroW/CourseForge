<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * How long a page is, answered the same way by everything that answers it.
 *
 * `list_pages` used to run the content through `strip_tags` before counting.
 * Markdown is not HTML: `List<String>`, `Map<String, Object>` and a written-out
 * `<div class="card">` are prose about code, and strip_tags removes each of
 * them along with everything up to the next `>`. Worse, a single `a<b` with no
 * later `>` anywhere on the page is an unterminated tag, and strip_tags
 * discards the rest of the document - so a finished 4,000-word page was
 * reported as 194 words.
 *
 * That is not a cosmetic error. A model reading the list sees seven pages that
 * look like failed generations and rewrites them, which costs money and throws
 * away work that was never lost. So the property tested here is not "the count
 * is right" but "the counts agree": list_pages, get_page and the tree the
 * browser draws all have to be one answer, because a person or a model
 * comparing two of them is entitled to conclude something from the difference.
 */

use CourseForge\Domain\Pages;
use CourseForge\Domain\Profiles;
use CourseForge\Domain\Projects;
use CourseForge\Mcp\Tools;
use CourseForge\Security\Actor;
use CourseForge\Support\Db;
use CourseForge\Support\Text;

/** The page bodies that used to be miscounted, and the ordinary one beside them. */
function countingPages(): array
{
    $filler = str_repeat('Ein ganz normaler Satz mit sieben Wörtern darin. ', 50);

    return [
        'Nichts Besonderes' => $filler,
        // A comparison in prose. No `>` follows it, so strip_tags ate the page.
        'Ein Vergleich' => "Wenn a<b gilt, folgt daraus einiges.\n\n" . $filler,
        // Generics, which every course about a typed language is full of.
        'Generics' => "Der Typ `List<String>` und `Map<String, Object>` sind typisch.\n\n" . $filler,
        // A tag pair, which strip_tags handles - and still must not be counted
        // differently from the way get_page counts it.
        'HTML im Text' => "Man schreibt <div class=\"card\">Inhalt</div> dafür.\n\n" . $filler,
    ];
}

/** @return array{0:int,1:array<int,int>} */
function courseThatCounts(): array
{
    $owner = 'counter';
    $profile = Profiles::create($owner, 'p-count', ['language' => 'Deutsch']);
    $project = Projects::create($owner, 'Zählkurs', 'Thema', (int)$profile['id']);
    $projectId = (int)$project['id'];

    Db::run('INSERT INTO chapters (project_id, idx, title, description) VALUES (?,?,?,?)',
        [$projectId, 0, 'Kapitel', '']);
    $chapterId = Db::lastId();

    $ids = [];
    $idx = 0;
    foreach (countingPages() as $title => $content) {
        Db::run(
            'INSERT INTO pages (project_id, chapter_id, idx, title, content, status, updated_at)
             VALUES (?,?,?,?,?,?,?)',
            [$projectId, $chapterId, $idx++, $title, $content, 'generated', time()]
        );
        $ids[] = Db::lastId();
    }

    return [$projectId, $ids];
}

test('list_pages counts the page, not what an HTML parser left of it', static function (): void {
    [$projectId, $pageIds] = courseThatCounts();
    $actor = Actor::make('counter', 'Counter', Actor::ROLE_USER);

    $listed = (array)(Tools::call($actor, 'list_pages', ['course_id' => $projectId])['data'] ?? []);
    $byId = [];
    foreach ((array)($listed['pages'] ?? []) as $row) {
        $byId[(int)$row['page_id']] = (int)$row['words'];
    }

    same(4, count($byId), 'every page came back');

    foreach ($pageIds as $pageId) {
        $page = Pages::find($projectId, $pageId) ?? [];
        $expected = Text::words((string)$page['content']);
        $title = (string)$page['title'];

        ok($expected > 300, $title . ' really is a long page (' . $expected . ' words)');
        same($expected, $byId[$pageId] ?? -1, $title . ' is counted whole by list_pages');
    }
});

test('list_pages, get_page and the tree give one answer', static function (): void {
    // Three surfaces answering "how long is this page" differently is worse
    // than any one of them being wrong, because the difference reads as
    // information: it said the page was empty, so the page was rewritten.
    [$projectId, $pageIds] = courseThatCounts();
    $actor = Actor::make('counter', 'Counter', Actor::ROLE_USER);

    $listed = (array)(Tools::call($actor, 'list_pages', ['course_id' => $projectId])['data'] ?? []);
    $byId = [];
    foreach ((array)($listed['pages'] ?? []) as $row) {
        $byId[(int)$row['page_id']] = (int)$row['words'];
    }

    foreach ($pageIds as $pageId) {
        $detail = (array)(Tools::call($actor, 'get_page', [
            'course_id' => $projectId,
            'page_id' => $pageId,
        ])['data'] ?? []);
        $fromGet = (int)($detail['page']['word_count'] ?? -1);

        // Pages::summary is what the browser's tree draws from, so this is the
        // third answer as well as the second.
        $fromTree = (int)(Pages::detail($projectId, $pageId)['word_count'] ?? -2);

        same($fromGet, $byId[$pageId] ?? -1, 'get_page and list_pages agree on page ' . $pageId);
        same($fromGet, $fromTree, 'and so does the shape the browser draws');
    }
});

test('an unwritten page is nothing to count', static function (): void {
    [$projectId, ] = courseThatCounts();
    Db::run('INSERT INTO pages (project_id, chapter_id, idx, title, content, status, updated_at)
             VALUES (?,?,?,?,?,?,?)',
        [$projectId, Db::row('SELECT id FROM chapters WHERE project_id = ?', [$projectId])['id'], 9,
         'Noch nichts', '', 'pending', time()]);
    $emptyId = Db::lastId();

    $listed = (array)(Tools::call(
        Actor::make('counter', 'Counter', Actor::ROLE_USER),
        'list_pages',
        ['course_id' => $projectId]
    )['data'] ?? []);

    foreach ((array)($listed['pages'] ?? []) as $row) {
        if ((int)$row['page_id'] === $emptyId) {
            same(0, (int)$row['words'], 'an empty page is nought words');
            same(false, (bool)$row['written'], 'and says it is unwritten');
            return;
        }
    }
    ok(false, 'the empty page was listed at all');
});
