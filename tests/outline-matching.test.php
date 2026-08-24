<?php
/**
 * Applying an outline: what "the same page" means, and what being wrong costs.
 *
 * The outline is the only document in CourseForge that decides which rows keep
 * the text on them, and it decides it by title. That leaves two ways to lose
 * work, and an agent driving the browser found both.
 *
 * The first is that titles are not unique. Two chapters called "Exercises" are
 * an ordinary outline, but a map keyed on the lowercased title has one slot for
 * them, so the second chapter was folded onto the first and the leftover was
 * deleted - applying the very same outline a second time removed a chapter and
 * a page each time, and left the survivors with duplicate idx values in an
 * order nobody had asked for. So the first thing asserted here is the thing
 * that should never have needed asserting: applying an outline that has not
 * changed changes nothing.
 *
 * The second is that a page the outline stops naming is deleted along with
 * everything written on it, and renaming a title in the editor is the everyday
 * way to stop naming one. That is allowed to happen - it is what makes the
 * outline the outline - but not silently, so the domain refuses the whole apply
 * and names the pages unless the caller says it means it. The MCP tool had that
 * guard and the browser did not; the tests below are against the one guard both
 * of them now go through.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use CourseForge\Domain\Projects;
use CourseForge\Support\Db;
use CourseForge\Support\HttpException;

/** A course of this file's own, so that no test can see another's rows. */
function outlineCourse(string $name): array
{
    Db::run(
        'INSERT INTO projects (username, name, topic, created_at, updated_at) VALUES (?,?,?,?,?)',
        ['zed', $name, 'testing', time(), time()]
    );
    return Projects::require('zed', Db::lastId());
}

/**
 * The course as these tests care about it: ids, order, titles and text.
 *
 * Ids are in it on purpose. Two shapes that read the same but hold different
 * row ids mean the pages were deleted and rebuilt, which is exactly the failure
 * being tested for - the titles look untouched and the text has gone.
 *
 * @return array<int,array<string,mixed>>
 */
function outlineShape(int $projectId): array
{
    $shape = [];
    foreach (Db::rows('SELECT id, idx, title FROM chapters WHERE project_id = ? ORDER BY idx, id', [$projectId]) as $chapter) {
        $pages = [];
        $rows = Db::rows('SELECT id, idx, title, content FROM pages WHERE chapter_id = ? ORDER BY idx, id', [(int)$chapter['id']]);
        foreach ($rows as $page) {
            $pages[] = [
                'id' => (int)$page['id'],
                'idx' => (int)$page['idx'],
                'title' => (string)$page['title'],
                'text' => (string)$page['content'],
            ];
        }
        $shape[] = [
            'id' => (int)$chapter['id'],
            'idx' => (int)$chapter['idx'],
            'title' => (string)$chapter['title'],
            'pages' => $pages,
        ];
    }
    return $shape;
}

function outlineWrite(int $pageId, string $text): void
{
    Db::run("UPDATE pages SET content = ?, status = 'generated' WHERE id = ?", [$text, $pageId]);
}

/** An outline in which two chapters and two pages share a title. */
const OUTLINE_DUPLICATES = "# Dup test\n\nA course to test duplicates.\n\n"
    . "1. Same chapter\n   1. Same page\n   2. Same page\n2. Same chapter\n   1. Other page\n";

/* ------------------------------------------------- duplicate titles are real */

test('two chapters and two pages of the same name stay two of each', function () {
    $project = outlineCourse('Duplicates');
    $result = Projects::applyStructure($project, OUTLINE_DUPLICATES);

    same(0, $result['removed_chapters'], 'a first apply into an empty course removes no chapter');
    same(0, $result['removed_pages'], 'and no page');

    $shape = outlineShape((int)$project['id']);
    same(2, count($shape), 'both chapters called "Same chapter" exist');
    same([0, 1], [$shape[0]['idx'], $shape[1]['idx']], 'and they are numbered 1 and 2');
    same(2, count($shape[0]['pages']), 'the first chapter kept both of its pages');
    same([0, 1], [$shape[0]['pages'][0]['idx'], $shape[0]['pages'][1]['idx']], 'in the order the outline gave');
    same(1, count($shape[1]['pages']), 'and the second chapter has the one it names');
});

test('applying an unchanged outline is a no-op, repeated titles included', function () {
    // The bug: the second apply answered "Removed 1 chapter and 1 page" for an
    // outline that had changed by nothing at all, and a third would have taken
    // another one. Nothing about the course may move.
    $project = outlineCourse('Unchanged');
    Projects::applyStructure($project, OUTLINE_DUPLICATES);
    $before = outlineShape((int)$project['id']);

    $second = Projects::applyStructure(Projects::require('zed', (int)$project['id']), OUTLINE_DUPLICATES);
    same(0, $second['removed_chapters'], 'the second apply removes no chapter');
    same(0, $second['removed_pages'], 'and no page');
    same($before, outlineShape((int)$project['id']), 'and every row is where it was, with the id it had');

    $third = Projects::applyStructure(Projects::require('zed', (int)$project['id']), OUTLINE_DUPLICATES);
    same(0, $third['removed_chapters'] + $third['removed_pages'], 'and a third apply is a no-op too');
    same($before, outlineShape((int)$project['id']), 'with the course still untouched');
});

test('pages that share a title each keep their own text', function () {
    $project = outlineCourse('Duplicate text');
    Projects::applyStructure($project, OUTLINE_DUPLICATES);

    $shape = outlineShape((int)$project['id']);
    outlineWrite($shape[0]['pages'][0]['id'], 'FIRST');
    outlineWrite($shape[0]['pages'][1]['id'], 'SECOND');

    Projects::applyStructure(Projects::require('zed', (int)$project['id']), OUTLINE_DUPLICATES);

    $after = outlineShape((int)$project['id']);
    same('FIRST', $after[0]['pages'][0]['text'], 'the first "Same page" still holds its own text');
    same('SECOND', $after[0]['pages'][1]['text'], 'and the second holds its own, not the first one\'s');
});

/* ----------------------------------------------- a page that keeps its title */

test('a page keeps its text when the chapter around it is renamed', function () {
    // Matching by title inside the chapter first must not stop a page whose
    // chapter has been renamed from finding its row: the chapter is new, so
    // nothing there matches, and the page falls back to the title match.
    $project = outlineCourse('Chapter rename');
    Projects::applyStructure($project, "# Book\n\n1. Old chapter\n   1. Kept page\n");
    outlineWrite(outlineShape((int)$project['id'])[0]['pages'][0]['id'], 'SURVIVES');

    Projects::applyStructure(Projects::require('zed', (int)$project['id']), "# Book\n\n1. New chapter\n   1. Kept page\n");

    $after = outlineShape((int)$project['id']);
    same('New chapter', $after[0]['title'], 'the chapter was renamed');
    same('SURVIVES', $after[0]['pages'][0]['text'], 'and the page under it still has its text');
});

/* ------------------------------------------- deleting written work is asked */

test('an outline that would delete a written page is refused, and changes nothing', function () {
    $project = outlineCourse('Refusal');
    Projects::applyStructure($project, "# Book\n\n1. Start\n   1. Written page\n   2. Empty page\n");
    outlineWrite(outlineShape((int)$project['id'])[0]['pages'][0]['id'], 'PERSIST-7391');
    $before = outlineShape((int)$project['id']);

    $renamed = "# Book\n\n1. Start\n   1. Written page, revised\n   2. Empty page\n";
    same(
        ['Written page'],
        Projects::pagesLosingContent(Projects::require('zed', (int)$project['id']), $renamed),
        'the page that would go is named before anything is written'
    );

    $error = raises(
        static fn() => Projects::applyStructure(Projects::require('zed', (int)$project['id']), $renamed),
        'renaming a written page is refused'
    );
    ok($error instanceof HttpException, 'the refusal is an HttpException');
    same(422, $error->status(), 'answered as unprocessable');
    same(['Written page'], $error->extra()['at_risk'] ?? [], 'and it hands the client the titles at stake');
    same($before, outlineShape((int)$project['id']), 'and the course is exactly as it was');
});

test('the same outline applies once the removal is confirmed', function () {
    $project = outlineCourse('Confirmed');
    Projects::applyStructure($project, "# Book\n\n1. Start\n   1. Written page\n");
    outlineWrite(outlineShape((int)$project['id'])[0]['pages'][0]['id'], 'PERSIST-7391');

    $result = Projects::applyStructure(
        Projects::require('zed', (int)$project['id']),
        "# Book\n\n1. Start\n   1. Written page, revised\n",
        true
    );

    same(1, $result['removed_pages'], 'the page was removed, as asked');
    $after = outlineShape((int)$project['id']);
    same('Written page, revised', $after[0]['pages'][0]['title'], 'and the new title is there');
    same('', $after[0]['pages'][0]['text'], 'as a page with nothing on it');
});

test('renaming a page nobody has written needs no confirmation', function () {
    // The guard is about losing text, not about renaming. An outline that only
    // moves empty pages around has to keep applying in one call.
    $project = outlineCourse('Empty rename');
    Projects::applyStructure($project, "# Book\n\n1. Start\n   1. Empty page\n");

    $result = Projects::applyStructure(
        Projects::require('zed', (int)$project['id']),
        "# Book\n\n1. Start\n   1. Renamed page\n"
    );
    same(1, $result['removed_pages'], 'the old row went');
    same('Renamed page', outlineShape((int)$project['id'])[0]['pages'][0]['title'], 'and the new one is there');
});

test('dropping one of two written pages of the same name names the right one', function () {
    // Two rows share a title and the outline now names it once. One of them has
    // to go, and the caller has to be told which - counting titles would have
    // said nothing was at risk, because the title is still in the outline.
    $project = outlineCourse('Duplicate loss');
    Projects::applyStructure($project, OUTLINE_DUPLICATES);
    $shape = outlineShape((int)$project['id']);
    outlineWrite($shape[0]['pages'][0]['id'], 'FIRST');
    outlineWrite($shape[0]['pages'][1]['id'], 'SECOND');

    $shorter = "# Dup test\n\nA course to test duplicates.\n\n1. Same chapter\n   1. Same page\n2. Same chapter\n   1. Other page\n";
    same(
        ['Same page'],
        Projects::pagesLosingContent(Projects::require('zed', (int)$project['id']), $shorter),
        'one of the two is named'
    );

    Projects::applyStructure(Projects::require('zed', (int)$project['id']), $shorter, true);
    $after = outlineShape((int)$project['id']);
    same(1, count($after[0]['pages']), 'one page of that name is left');
    same('FIRST', $after[0]['pages'][0]['text'], 'and it is the one the outline still points at');
});

/* --------------------------------------------------------- the book's title */

test('an outline with no title line leaves the book title alone', function () {
    // The header used to show this field, so a titleless outline renamed the
    // course on screen to "Untitled course". It is the published book's title:
    // an outline that says nothing about it must not overwrite it.
    $project = outlineCourse('Course as filed');
    Projects::applyStructure($project, "# The published book\n\n1. Start\n   1. Page\n");
    same('The published book', (string)Projects::require('zed', (int)$project['id'])['book_title'], 'the title was taken');

    Projects::applyStructure(Projects::require('zed', (int)$project['id']), "1. Start\n   1. Page\n");
    $after = Projects::require('zed', (int)$project['id']);
    same('The published book', (string)$after['book_title'], 'and an outline with no "# " line left it alone');
    same('Course as filed', (string)$after['name'], 'while the course keeps the name it is filed under');
});

test('a course still called "Untitled course" takes the name the outline gives it', function () {
    $project = outlineCourse('Untitled course');
    Projects::applyStructure($project, "# Vue from scratch\n\n1. Start\n   1. Page\n");

    $after = Projects::require('zed', (int)$project['id']);
    same('Vue from scratch', (string)$after['name'], 'the placeholder name gave way to the outline title');
    same('Vue from scratch', (string)$after['book_title'], 'and the book is called the same thing');
});
