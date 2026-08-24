<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * How much of a course one answer carries.
 *
 * `get_course` with include_content used to return every written page with no
 * ceiling of any kind. A finished course is five hundred pages, and the answer
 * was however large that was - twenty times what a client keeps, which meant
 * the client cut it off in the middle of the JSON with nothing to say it had,
 * and on a small host PHP ran out of memory building a string nobody could
 * read. The tool's `anthropic/maxResultSizeChars` did not prevent that and was
 * never going to: it asks a client to raise the size at which it truncates, it
 * is not a promise the server makes about its own output.
 *
 * So the answer bounds itself, and the two properties that make a bounded
 * answer usable rather than merely smaller are what is tested here: the outline
 * is never truncated, so a caller can always see every page that exists; and
 * the text stops on a page boundary with a marker saying where to carry on
 * from, so reading a whole course is a walk rather than a guess.
 */

use CourseForge\Mcp\Tools;
use CourseForge\Security\Actor;
use CourseForge\Support\Db;

/** A course of N pages of roughly $bytes each, all of them written. */
function sizedCourse(string $name, int $pages, int $bytes): int
{
    $now = time();
    Db::run(
        'INSERT INTO projects (username, name, topic, created_at, updated_at) VALUES (?,?,?,?,?)',
        ['zed', $name, 'size probe', $now, $now]
    );
    $projectId = Db::lastId();

    Db::run('INSERT INTO chapters (project_id, idx, title) VALUES (?,1,?)', [$projectId, 'The only chapter']);
    $chapterId = Db::lastId();

    for ($p = 1; $p <= $pages; $p++) {
        Db::run(
            'INSERT INTO pages (project_id, chapter_id, idx, title, content, status, updated_at)
             VALUES (?,?,?,?,?,"done",?)',
            [$projectId, $chapterId, $p, 'Page ' . $p, str_repeat('x', $bytes), $now]
        );
    }
    return $projectId;
}

/** @return array<string,mixed> */
function sizedRead(int $courseId, array $extra = []): array
{
    $result = Tools::call(
        Actor::make('zed', 'zed', 'admin'),
        'get_course',
        ['course_id' => $courseId, 'include_content' => true] + $extra
    );
    return (array)$result['data'];
}

/** @return array<int,array<string,mixed>> every page of the answer, in order */
function sizedPages(array $answer): array
{
    $pages = [];
    foreach ($answer['chapters'] as $chapter) {
        foreach ($chapter['pages'] as $page) {
            $pages[] = $page;
        }
    }
    return $pages;
}

test('a course that fits comes back whole, and says so', static function (): void {
    $id = sizedCourse('sized-small', 4, 1000);
    $answer = sizedRead($id);

    same(true, $answer['content']['complete'], 'nothing was left out');
    same(4, $answer['content']['pages_included'], 'all four pages carry their text');
    same(0, $answer['content']['pages_after_window'], 'and there is nothing to come back for');
    same(null, $answer['content']['next_page_id'], 'so there is nowhere to carry on from');

    foreach (sizedPages($answer) as $page) {
        same(1000, strlen((string)$page['content']), 'each page is there in full');
    }
});

test('a course too large for one answer stops on a page boundary and says where', static function (): void {
    // Forty pages of twenty thousand characters: 800,000, well past the limit.
    $id = sizedCourse('sized-large', 40, 20000);
    $answer = sizedRead($id);

    same(false, $answer['content']['complete'], 'the answer knows it is not the whole course');
    ok($answer['content']['characters'] <= $answer['content']['limit'], 'and stayed inside the limit');
    ok($answer['content']['pages_after_window'] > 0, 'with pages left over');
    ok(is_int($answer['content']['next_page_id']), 'and an id to resume from');

    $pages = sizedPages($answer);
    same(40, count($pages), 'the outline still lists every page, which is how the rest can be asked for');

    $carrying = 0;
    foreach ($pages as $page) {
        if (array_key_exists('content', $page)) {
            same(20000, strlen((string)$page['content']), 'a page that is here is here in full, never half of one');
            $carrying++;
            continue;
        }
        ok(isset($page['content_omitted']), 'and a page that is not says so in its own row');
    }
    same($answer['content']['pages_included'], $carrying, 'the count in the answer is the count in the answer');
});

test('following next_page_id reads the whole course and then stops', static function (): void {
    $id = sizedCourse('sized-walk', 40, 20000);

    $seen = [];
    $next = null;
    $rounds = 0;

    do {
        $answer = $next === null ? sizedRead($id) : sizedRead($id, ['content_from_page_id' => $next]);
        foreach (sizedPages($answer) as $page) {
            if (array_key_exists('content', $page)) {
                $seen[(int)$page['page_id']] = true;
            }
        }
        $next = $answer['content']['next_page_id'];
        $rounds++;
    } while ($next !== null && $rounds < 20);

    same(40, count($seen), 'every page was read exactly once across the walk');
    same(true, $answer['content']['complete'], 'and the last answer is the one that says it is finished');
    ok($rounds > 1 && $rounds < 20, 'in more than one round and without looping');
});

test('one page larger than the whole limit is still returned, and explains itself', static function (): void {
    // Otherwise the answer would carry nothing and point at itself as the place
    // to resume, which is a loop rather than a result.
    $id = sizedCourse('sized-huge', 1, 400000);
    $answer = sizedRead($id);

    same(1, $answer['content']['pages_included'], 'the page is there');
    same(true, $answer['content']['complete'], 'and the course is finished with it');
    ok($answer['content']['characters'] > $answer['content']['limit'], 'over the limit, which is the surprising part');
    ok(str_contains((string)$answer['content']['note'], 'over the usual limit'), 'so the answer says why');
});

test('a page id the course does not have is refused rather than ignored', static function (): void {
    $id = sizedCourse('sized-missing', 3, 100);
    $answer = sizedRead($id, ['content_from_page_id' => 999999]);

    same(0, $answer['content']['pages_included'], 'nothing was included');
    same(false, $answer['content']['complete'], 'and the answer does not claim to be the course');
    ok(str_contains((string)$answer['content']['note'], '999999'), 'the note names the id that matched nothing');
});

test('leaving include_content off says nothing about content at all', static function (): void {
    $id = sizedCourse('sized-outline', 3, 1000);
    $result = Tools::call(Actor::make('zed', 'zed', 'admin'), 'get_course', ['course_id' => $id]);
    $answer = (array)$result['data'];

    ok(!array_key_exists('content', $answer), 'no window block on an answer that was never asked for text');
    foreach (sizedPages($answer) as $page) {
        ok(!array_key_exists('content', $page) && !isset($page['content_omitted']), 'and no page pretends to have been cut');
    }
});

test('the tidying up', static function (): void {
    Db::run("DELETE FROM projects WHERE name LIKE 'sized-%'");
    ok(true, 'the courses this file made are gone');
});
