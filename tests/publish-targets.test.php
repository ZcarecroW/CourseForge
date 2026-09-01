<?php
/**
 * Publishing a course into more than one BookStack instance.
 *
 * A course used to have exactly one destination, kept in columns on the course,
 * on every chapter and on every page. It now has a list, and each entry owns
 * what a push made of it - its book, and the id, slug, URL and fingerprint of
 * every chapter and page in that wiki. Those are different in every wiki, and
 * not only because the ids are: a page's cross references point inside the wiki
 * it is in, so the same page is not even the same text in two of them.
 *
 * Three things are worth holding still here, and they are what this file is:
 *
 *  - the old columns keep answering. Half the application reads them - the
 *    course list, the sync badges, the link index the editor previews with -
 *    and they are now a mirror of whichever destination is first. If the mirror
 *    ever stopped being written, nothing would fail; a course would simply stop
 *    reporting what it had published, which is the worst way to be wrong.
 *  - an upgrade loses nothing. An installation on 4.6 has courses with books in
 *    them, and version 8 has to turn each of those into a destination carrying
 *    the same book and the same fingerprints - or the first push after the
 *    upgrade republishes an entire course over the top of itself.
 *  - one wiki being unreachable is not the push failing. It is one destination
 *    failing; the others still go out. Only when every one of them fails is the
 *    error raised, which is exactly what a single-destination course always did.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use CourseForge\Domain\LinkIndex;
use CourseForge\Domain\Profiles;
use CourseForge\Domain\Projects;
use CourseForge\Mcp\Args;
use CourseForge\Publish\Publisher;
use CourseForge\Publish\Targets;
use CourseForge\Support\Db;
use CourseForge\Support\HttpException;

/** A profile holding three BookStack instances, all of them usable. */
function targetProfile(string $name = 'three wikis'): array
{
    return Profiles::create('tgt', $name, [
        'bookstack' => [
            ['id' => 'live', 'name' => 'Live wiki', 'base_url' => 'https://live.example', 'token_id' => 'a', 'token_secret' => 'b'],
            ['id' => 'staging', 'name' => 'Staging wiki', 'base_url' => 'https://stage.example', 'token_id' => 'c', 'token_secret' => 'd'],
            ['id' => 'archive', 'name' => 'Archive wiki', 'base_url' => 'https://archive.example', 'token_id' => 'e', 'token_secret' => 'f'],
        ],
    ]);
}

/** A course of this file's own, with one chapter and one page in it. */
function targetCourse(string $name, ?int $profileId = null): array
{
    Db::run(
        'INSERT INTO projects (username, profile_id, name, topic, book_title, book_desc, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?)',
        ['tgt', $profileId, $name, 'testing', $name, 'A description.', time(), time()]
    );
    $projectId = Db::lastId();

    Db::run('INSERT INTO chapters (project_id, idx, title, description) VALUES (?,0,?,?)',
        [$projectId, 'Getting started', 'The first chapter.']);
    $chapterId = Db::lastId();

    Db::run(
        'INSERT INTO pages (project_id, chapter_id, idx, title, content, status, updated_at) VALUES (?,?,0,?,?,?,?)',
        [$projectId, $chapterId, 'First steps', 'Some text.', 'done', time()]
    );

    return ['project' => Projects::require('tgt', $projectId), 'chapter' => $chapterId, 'page' => Db::lastId()];
}

/* ------------------------------------------------ the list and the mirror - */

test('a course with one destination reports it exactly where it always did', function () {
    $profile = targetProfile();
    ['project' => $project] = targetCourse('One wiki', (int)$profile['id']);
    $projectId = (int)$project['id'];

    Targets::setPrimary('tgt', $projectId, 'live', ['shelf_id' => 7, 'shelf_name' => 'Handbooks']);

    $stored = Targets::all($projectId);
    same(1, count($stored), 'one destination was created');
    same('live', (string)$stored[0]['instance_id'], 'pointing at the instance that was named');

    // The columns half the application still reads.
    $row = Projects::require('tgt', $projectId);
    same('live', (string)$row['bs_instance_id'], 'and the course mirrors it');
    same(7, (int)$row['shelf_id'], 'shelf included');
    same('Handbooks', (string)$row['shelf_name'], 'and its name');
});

test('changing only the instance leaves the shelf where it was', function () {
    $profile = targetProfile();
    ['project' => $project] = targetCourse('Keeps its shelf', (int)$profile['id']);
    $projectId = (int)$project['id'];

    Targets::setPrimary('tgt', $projectId, 'live', ['shelf_id' => 3, 'shelf_name' => 'Shelf three']);
    Targets::setPrimary('tgt', $projectId, 'live'); // no shelf named at all

    $row = Projects::require('tgt', $projectId);
    same(3, (int)$row['shelf_id'], 'the shelf survived a save that did not mention it');
    same('Shelf three', (string)$row['shelf_name'], 'name included');

    // Saying "no shelf" is a different instruction and is obeyed.
    Targets::setPrimary('tgt', $projectId, 'live', ['shelf_id' => null, 'shelf_name' => '']);
    same(null, Projects::require('tgt', $projectId)['shelf_id'], 'and an explicit clear really clears it');
});

test('a second destination is added without disturbing the first', function () {
    $profile = targetProfile();
    ['project' => $project] = targetCourse('Two wikis', (int)$profile['id']);
    $projectId = (int)$project['id'];

    Targets::setPrimary('tgt', $projectId, 'live', ['shelf_id' => 1, 'shelf_name' => 'One']);
    // Pretend a push happened: the first destination now holds a book.
    Targets::update((int)Targets::primary($projectId)['id'], [
        'book_id' => 42, 'book_slug' => 'two-wikis', 'book_url' => 'https://live.example/books/two-wikis',
        'pushed_hash' => 'abc',
    ]);

    Targets::replaceAll('tgt', $projectId, [
        ['instance_id' => 'live', 'shelf_id' => 1, 'shelf_name' => 'One'],
        ['instance_id' => 'staging', 'shelf_id' => null, 'shelf_name' => ''],
    ]);

    $stored = Targets::all($projectId);
    same(2, count($stored), 'both destinations are stored');
    same(42, (int)$stored[0]['book_id'], 'and the first one kept the book it had made');
    same(null, $stored[1]['book_id'], 'while the new one has none yet');
    same(42, (int)Projects::require('tgt', $projectId)['book_id'], 'the mirror still shows the first');
});

test('the order of the list decides which destination the course mirrors', function () {
    $profile = targetProfile();
    ['project' => $project] = targetCourse('Reordered', (int)$profile['id']);
    $projectId = (int)$project['id'];

    Targets::replaceAll('tgt', $projectId, [
        ['instance_id' => 'live'],
        ['instance_id' => 'staging'],
    ]);
    foreach (Targets::all($projectId) as $target) {
        Targets::update((int)$target['id'], [
            'book_id' => (string)$target['instance_id'] === 'live' ? 10 : 20,
            'book_url' => 'https://' . (string)$target['instance_id'] . '.example/books/x',
        ]);
    }
    Targets::mirror('tgt', $projectId);
    same(10, (int)Projects::require('tgt', $projectId)['book_id'], 'the first one is mirrored');

    Targets::replaceAll('tgt', $projectId, [
        ['instance_id' => 'staging'],
        ['instance_id' => 'live'],
    ]);
    same(20, (int)Projects::require('tgt', $projectId)['book_id'], 'and swapping the order swaps the mirror');
});

test('a destination that is switched off is skipped, not forgotten', function () {
    $profile = targetProfile();
    ['project' => $project] = targetCourse('Half off', (int)$profile['id']);
    $projectId = (int)$project['id'];

    Targets::replaceAll('tgt', $projectId, [
        ['instance_id' => 'live', 'enabled' => false],
        ['instance_id' => 'staging', 'enabled' => true],
    ]);

    same(2, count(Targets::all($projectId)), 'both are still on record');
    same(1, count(Targets::enabled($projectId)), 'but only one is switched on');
    same('staging', (string)Targets::primary($projectId)['instance_id'],
        'and the mirror follows the first one that is on');

    // Everything off falls back to the first, because a course that has turned
    // its destinations off has still published to them.
    Targets::replaceAll('tgt', $projectId, [
        ['instance_id' => 'live', 'enabled' => false],
        ['instance_id' => 'staging', 'enabled' => false],
    ]);
    same('live', (string)Targets::primary($projectId)['instance_id'], 'with everything off the first one is shown');
});

test('a destination taken off the list is forgotten, book and all', function () {
    $profile = targetProfile();
    ['project' => $project, 'page' => $pageId] = targetCourse('Dropped', (int)$profile['id']);
    $projectId = (int)$project['id'];

    Targets::replaceAll('tgt', $projectId, [['instance_id' => 'live'], ['instance_id' => 'staging']]);
    $staging = Targets::byInstance($projectId, 'staging');
    Targets::saveItem((int)$staging['id'], 'page', $pageId, ['bs_id' => 5, 'bs_slug' => 'p', 'bs_url' => 'u', 'pushed_hash' => 'h']);

    Targets::replaceAll('tgt', $projectId, [['instance_id' => 'live']]);

    same(null, Targets::byInstance($projectId, 'staging'), 'the destination is gone');
    same(0, count(Db::rows('SELECT id FROM publish_items WHERE target_id = ?', [(int)$staging['id']])),
        'and what it had published went with it');
});

test('deleting a page takes its published identities with it', function () {
    $profile = targetProfile();
    ['project' => $project, 'page' => $pageId] = targetCourse('Cascade', (int)$profile['id']);
    $projectId = (int)$project['id'];

    Targets::replaceAll('tgt', $projectId, [['instance_id' => 'live'], ['instance_id' => 'staging']]);
    foreach (Targets::all($projectId) as $target) {
        Targets::saveItem((int)$target['id'], 'page', $pageId, ['bs_id' => 9, 'bs_slug' => 'p', 'bs_url' => 'u', 'pushed_hash' => 'h']);
    }
    same(2, count(Db::rows('SELECT id FROM publish_items WHERE page_id = ?', [$pageId])), 'two rows to start with');

    Db::run('DELETE FROM pages WHERE id = ?', [$pageId]);
    same(0, count(Db::rows('SELECT id FROM publish_items WHERE page_id = ?', [$pageId])),
        'and nothing is left behind pointing at a page that has gone');
});

test('the same instance cannot be a destination of one course twice', function () {
    $profile = targetProfile();
    ['project' => $project] = targetCourse('No duplicates', (int)$profile['id']);
    $projectId = (int)$project['id'];

    Targets::replaceAll('tgt', $projectId, [
        ['instance_id' => 'live'],
        ['instance_id' => 'live'],
        ['instance_id' => 'staging'],
    ]);

    same(2, count(Targets::all($projectId)), 'the repeat was folded into one');
});

test('naming a different instance replaces the destination rather than adding one', function () {
    // The whole of 4.6's `bs_instance_id` was "this is where the course
    // publishes". A client saying it now means B has said it does not mean A -
    // and if A survived, the next unscoped push would go to both wikis and
    // nothing would ever say so.
    $profile = targetProfile();
    ['project' => $project] = targetCourse('Switched', (int)$profile['id']);
    $projectId = (int)$project['id'];

    Targets::setPrimary('tgt', $projectId, 'live');
    Targets::setPrimary('tgt', $projectId, 'staging');

    $stored = Targets::all($projectId);
    same(1, count($stored), 'there is still exactly one destination');
    same('staging', (string)$stored[0]['instance_id'], 'and it is the one that was named');
    same('staging', (string)Projects::require('tgt', $projectId)['bs_instance_id'], 'which the course mirrors');
});

test('the destinations behind the first one survive a switch', function () {
    $profile = targetProfile();
    ['project' => $project] = targetCourse('Switch the front', (int)$profile['id']);
    $projectId = (int)$project['id'];

    Targets::replaceAll('tgt', $projectId, [['instance_id' => 'live'], ['instance_id' => 'staging']]);
    Targets::setPrimary('tgt', $projectId, 'archive');

    $stored = array_map(static fn(array $t): string => (string)$t['instance_id'], Targets::all($projectId));
    same(['archive', 'staging'], $stored, 'the first was replaced and the second left alone');
});

test('naming a destination switches it on', function () {
    $profile = targetProfile();
    ['project' => $project] = targetCourse('Off then named', (int)$profile['id']);
    $projectId = (int)$project['id'];

    Targets::replaceAll('tgt', $projectId, [
        ['instance_id' => 'live', 'enabled' => true],
        ['instance_id' => 'staging', 'enabled' => false],
    ]);
    Targets::setPrimary('tgt', $projectId, 'staging');

    $staging = Targets::byInstance($projectId, 'staging');
    same(1, (int)$staging['enabled'], '"this is where it publishes" cannot leave it paused');
});

test('clearing the instance is refused while a book has been published to it', function () {
    $profile = targetProfile();
    ['project' => $project] = targetCourse('Has a book', (int)$profile['id']);
    $projectId = (int)$project['id'];

    Targets::setPrimary('tgt', $projectId, 'live');
    Targets::update((int)Targets::primary($projectId)['id'], ['book_id' => 5]);

    $e = raises(
        static fn() => Targets::setPrimary('tgt', $projectId, ''),
        'clearing a destination that holds a book is refused'
    );
    ok(str_contains($e->getMessage(), 'second book'), 'and says what it would cost: ' . $e->getMessage());
    same(1, count(Targets::all($projectId)), 'and nothing was removed');

    // One that has published nothing has nothing to lose, so it goes.
    Targets::setPrimary('tgt', $projectId, 'staging');
    Targets::setPrimary('tgt', $projectId, '');
    same([], Targets::all($projectId), 'an unpublished destination clears as it always did');
});

test('with every destination paused the course still reports the one holding the book', function () {
    $profile = targetProfile();
    ['project' => $project] = targetCourse('All paused', (int)$profile['id']);
    $projectId = (int)$project['id'];

    Targets::replaceAll('tgt', $projectId, [['instance_id' => 'live'], ['instance_id' => 'staging']]);
    Targets::update((int)Targets::byInstance($projectId, 'staging')['id'], ['book_id' => 88]);
    Targets::replaceAll('tgt', $projectId, [
        ['instance_id' => 'live', 'enabled' => false],
        ['instance_id' => 'staging', 'enabled' => false],
    ]);

    same('staging', (string)Targets::primary($projectId)['instance_id'],
        'the fall-back prefers a destination that has actually published something');
    same(88, (int)Projects::require('tgt', $projectId)['book_id'], 'so the book does not vanish from the course');
});

/* ------------------------------------------------------ links per wiki --- */

test('a cross reference resolves to the URL of the wiki it is written into', function () {
    $profile = targetProfile();
    ['project' => $project, 'page' => $pageId] = targetCourse('Per-wiki links', (int)$profile['id']);
    $projectId = (int)$project['id'];

    Targets::replaceAll('tgt', $projectId, [['instance_id' => 'live'], ['instance_id' => 'staging']]);
    $live = Targets::byInstance($projectId, 'live');
    $staging = Targets::byInstance($projectId, 'staging');

    Targets::saveItem((int)$live['id'], 'page', $pageId, [
        'bs_id' => 1, 'bs_slug' => 'first-steps', 'bs_url' => 'https://live.example/books/x/page/first-steps', 'pushed_hash' => '',
    ]);
    Targets::saveItem((int)$staging['id'], 'page', $pageId, [
        'bs_id' => 2, 'bs_slug' => 'first-steps', 'bs_url' => 'https://stage.example/books/x/page/first-steps', 'pushed_hash' => '',
    ]);

    $found = LinkIndex::forTarget($projectId, (int)$live['id'])->lookup('First steps');
    same('https://live.example/books/x/page/first-steps', (string)$found['url'], 'the live index points at live');

    $found = LinkIndex::forTarget($projectId, (int)$staging['id'])->lookup('First steps');
    same('https://stage.example/books/x/page/first-steps', (string)$found['url'], 'and the staging index at staging');

    // The project-wide index is the mirror, which is the first destination.
    Targets::mirror('tgt', $projectId);
    same(
        'https://live.example/books/x/page/first-steps',
        (string)LinkIndex::forProject($projectId)->lookup('First steps')['url'],
        'and the course-wide index follows the first destination'
    );
});

/* ------------------------------------------------------- what the tree says */

test('published means published everywhere, and out of sync means somewhere', function () {
    $profile = targetProfile();
    ['project' => $project, 'page' => $pageId, 'chapter' => $chapterId] = targetCourse('Folded', (int)$profile['id']);
    $projectId = (int)$project['id'];

    Targets::replaceAll('tgt', $projectId, [['instance_id' => 'live'], ['instance_id' => 'staging']]);
    $live = (int)Targets::byInstance($projectId, 'live')['id'];
    $staging = (int)Targets::byInstance($projectId, 'staging')['id'];

    $page = Db::row('SELECT * FROM pages WHERE id = ?', [$pageId]);
    $hash = CourseForge\Domain\Pages::pushHash((string)$page['title'], (string)$page['content'], []);

    // Published to one wiki only, and up to date there.
    Targets::saveItem($live, 'page', $pageId, ['bs_id' => 1, 'bs_slug' => 's', 'bs_url' => 'u', 'pushed_hash' => $hash]);
    Targets::saveItem($live, 'chapter', $chapterId, ['bs_id' => 2, 'bs_slug' => 's', 'bs_url' => 'u', 'pushed_hash' => 'x']);

    $tree = Projects::tree('tgt', $projectId);
    $summary = $tree['chapters'][0]['pages'][0];
    same(false, $summary['pushed'], 'a page missing from one of two wikis is not published');
    same(true, $summary['dirty'], 'and it is work outstanding, because one wiki has it and one does not');
    same(2, count($summary['targets']), 'the per-wiki detail travels with it');

    // Now in both, and current in both.
    Targets::saveItem($staging, 'page', $pageId, ['bs_id' => 3, 'bs_slug' => 's', 'bs_url' => 'u', 'pushed_hash' => $hash]);
    $summary = Projects::tree('tgt', $projectId)['chapters'][0]['pages'][0];
    same(true, $summary['pushed'], 'in both wikis it counts as published');
    same(false, $summary['dirty'], 'and nothing is outstanding');

    // One of them goes stale.
    Targets::saveItem($staging, 'page', $pageId, ['pushed_hash' => 'stale']);
    $summary = Projects::tree('tgt', $projectId)['chapters'][0]['pages'][0];
    same(true, $summary['pushed'], 'it is still in both');
    same(true, $summary['dirty'], 'but one of them would be written to again');
});

test('a course with one destination answers exactly as it did before there were several', function () {
    $profile = targetProfile();
    ['project' => $project, 'page' => $pageId] = targetCourse('Just one', (int)$profile['id']);
    $projectId = (int)$project['id'];

    Targets::setPrimary('tgt', $projectId, 'live');
    $live = (int)Targets::primary($projectId)['id'];

    $page = Db::row('SELECT * FROM pages WHERE id = ?', [$pageId]);
    $hash = CourseForge\Domain\Pages::pushHash((string)$page['title'], (string)$page['content'], []);
    Targets::saveItem($live, 'page', $pageId, ['bs_id' => 1, 'bs_slug' => 's', 'bs_url' => 'u', 'pushed_hash' => $hash]);
    Targets::mirror('tgt', $projectId);

    $tree = Projects::tree('tgt', $projectId);
    $summary = $tree['chapters'][0]['pages'][0];
    same(true, $summary['pushed'], 'published');
    same(false, $summary['dirty'], 'and in sync');
    ok(!isset($summary['targets']), 'and no per-wiki list, because there is only one wiki');
    same(1, count($tree['targets']), 'the course names its one destination');
    same('Live wiki', (string)$tree['targets'][0]['instance_name'], 'by the name the profile gives it');
});

test('a destination whose instance the profile no longer defines says so', function () {
    $profile = targetProfile();
    ['project' => $project] = targetCourse('Orphaned', (int)$profile['id']);
    $projectId = (int)$project['id'];

    Targets::setPrimary('tgt', $projectId, 'live');
    Profiles::update('tgt', (int)$profile['id'], 'two wikis', [
        'bookstack' => [
            ['id' => 'staging', 'name' => 'Staging wiki', 'base_url' => 'https://stage.example', 'token_id' => 'c', 'token_secret' => 'd'],
        ],
    ]);

    $target = Projects::tree('tgt', $projectId)['targets'][0];
    same(false, $target['known'], 'the destination is marked as pointing at nothing');
    same('', (string)$target['instance_name'], 'and has no name to show');
});

/* ----------------------------------------------------- refusals and folding */

test('a course with nowhere to publish is refused before anything is contacted', function () {
    $profile = targetProfile();
    ['project' => $project] = targetCourse('Nowhere', (int)$profile['id']);
    $projectId = (int)$project['id'];

    $e = raises(static fn() => Publisher::open('tgt', $projectId), 'a course with no destination is refused');
    ok(str_contains($e->getMessage(), 'BookStack instance'), 'and told to choose one: ' . $e->getMessage());

    Targets::replaceAll('tgt', $projectId, [['instance_id' => 'live', 'enabled' => false]]);
    $e = raises(static fn() => Publisher::open('tgt', $projectId), 'and one with every destination off too');
    ok(str_contains($e->getMessage(), 'switched off'), 'saying which it is: ' . $e->getMessage());
});

test('a push aimed at a destination of another course is refused', function () {
    $profile = targetProfile();
    ['project' => $mine] = targetCourse('Mine', (int)$profile['id']);
    ['project' => $theirs] = targetCourse('Theirs', (int)$profile['id']);

    Targets::setPrimary('tgt', (int)$mine['id'], 'live');
    Targets::setPrimary('tgt', (int)$theirs['id'], 'live');
    $foreign = (int)Targets::primary((int)$theirs['id'])['id'];

    raises(
        static fn() => Publisher::open('tgt', (int)$mine['id'], [$foreign]),
        "a course cannot be pushed to another course's destination"
    );
});

test('a wiki that is switched off is not published to even when it is asked for', function () {
    $profile = targetProfile();
    ['project' => $project] = targetCourse('Asked for anyway', (int)$profile['id']);
    $projectId = (int)$project['id'];

    Targets::replaceAll('tgt', $projectId, [
        ['instance_id' => 'live', 'enabled' => true],
        ['instance_id' => 'staging', 'enabled' => false],
    ]);
    $staging = (int)Targets::byInstance($projectId, 'staging')['id'];

    $e = raises(
        static fn() => Publisher::open('tgt', $projectId, [$staging]),
        'naming a switched-off destination is refused rather than obeyed or ignored'
    );
    ok(str_contains($e->getMessage(), 'switched off'), 'and it says why: ' . $e->getMessage());
});

test('the columns the course still carries are written by the mirror and by nothing else', function () {
    $profile = targetProfile();
    ['project' => $project, 'page' => $pageId] = targetCourse('One writer', (int)$profile['id']);
    $projectId = (int)$project['id'];

    Targets::setPrimary('tgt', $projectId, 'live');
    Targets::update((int)Targets::primary($projectId)['id'], ['book_id' => 12]);
    Targets::mirror('tgt', $projectId);

    // The ordinary write path is asked to change the book, the instance and a
    // page's published id. All three have to be ignored: they are a copy of
    // what a destination holds, and a second writer is a course claiming a book
    // no wiki has.
    Projects::update('tgt', $projectId, ['book_id' => 999, 'bs_instance_id' => 'staging', 'name' => 'Renamed']);
    CourseForge\Domain\Pages::update($pageId, ['bs_id' => 999, 'title' => 'Renamed page']);

    $row = Projects::require('tgt', $projectId);
    same('Renamed', (string)$row['name'], 'the field that is genuinely writable went through');
    same(12, (int)$row['book_id'], 'while the mirrored book did not');
    same('live', (string)$row['bs_instance_id'], 'nor the mirrored instance');

    $page = CourseForge\Support\Db::row('SELECT title, bs_id FROM pages WHERE id = ?', [$pageId]);
    same('Renamed page', (string)$page['title'], 'the same on a page');
    same(null, $page['bs_id'], 'whose published id is the mirror\'s to write');
});

test('when every wiki fails the push fails, with the first reason', function () {
    // Both instances are missing their credentials, which BookStackClient
    // refuses before a single request leaves the building - so this exercises
    // the failure path without contacting anything.
    $profile = Profiles::create('tgt', 'broken', [
        'bookstack' => [
            ['id' => 'live', 'name' => 'Live wiki', 'base_url' => '', 'token_id' => '', 'token_secret' => ''],
            ['id' => 'staging', 'name' => 'Staging wiki', 'base_url' => '', 'token_id' => '', 'token_secret' => ''],
        ],
    ]);
    ['project' => $project] = targetCourse('All broken', (int)$profile['id']);
    $projectId = (int)$project['id'];
    Targets::replaceAll('tgt', $projectId, [['instance_id' => 'live'], ['instance_id' => 'staging']]);

    $e = raises(
        static fn() => Publisher::open('tgt', $projectId)->push('book'),
        'nothing got through, so the push is an error rather than a log'
    );
    ok(str_contains($e->getMessage(), 'Live wiki'), 'and it is the first wiki\'s reason: ' . $e->getMessage());
});

test('the counts of several wikis are folded the way each of them means', function () {
    $folded = Publisher::combine([
        ['log' => ['[A] Created page "One".'], 'links' => ['resolved' => 4, 'pending' => 1, 'updated' => 2]],
        ['log' => ['[B] Created page "One".'], 'links' => ['resolved' => 4, 'pending' => 0, 'updated' => 3]],
    ]);

    same(4, $folded['links']['resolved'], 'the same references resolved in both, so they are not counted twice');
    same(1, $folded['links']['pending'], 'and the worst wiki is what "pending" reports');
    same(5, $folded['links']['updated'], 'but writes really did happen in both, so they add up');
    same(2, count($folded['log']), 'and the log holds every line of both');
});

test('a log line labelled with its wiki is still classified by its verb', function () {
    $method = new ReflectionMethod(CourseForge\Mcp\Handlers\PublishTools::class, 'classify');
    $method->setAccessible(true);

    $items = $method->invoke(null, [
        '[Live wiki] Created page "One".',
        '[Staging wiki] Updated page "One".',
        'Skipped "Two" – nothing generated yet.',
        '[Live wiki] Page "Three" is already up to date.',
    ]);

    same(1, count($items['created']), 'a labelled creation is a creation');
    same(1, count($items['updated']), 'a labelled update is an update');
    same(1, count($items['skipped']), 'an unlabelled line still works');
    same(1, count($items['unchanged']), 'and so does a labelled one further in');
    same(0, count($items['other']), 'nothing fell through to "other"');
});

test('a list of destinations that is not a list is refused rather than guessed at', function () {
    raises(
        static fn() => Args::of(['targets' => ['instance' => 'live']])->objects('targets'),
        'an object where a list belongs is refused'
    );
    raises(
        static fn() => Args::of(['targets' => ['live', 'staging']])->objects('targets'),
        'and so is a list of strings'
    );
    same([], Args::of([])->objects('targets'), 'while an absent argument is an empty list');
});

/* ------------------------------------------------------- over the protocol */

/** The tools as a client reaches them, so the schema and the handler are both in it. */
function targetTool(string $name, array $args): array
{
    return (array)CourseForge\Mcp\Tools::call(
        CourseForge\Security\Actor::make('tgt', 'tgt', 'admin'),
        $name,
        $args
    )['data'];
}

test('set_publish_targets writes the whole list and says what it forgot', function () {
    $profile = targetProfile();
    ['project' => $project] = targetCourse('Over MCP', (int)$profile['id']);
    $projectId = (int)$project['id'];
    Targets::replaceAll('tgt', $projectId, [['instance_id' => 'live'], ['instance_id' => 'staging']]);

    $answer = targetTool('set_publish_targets', [
        'course_id' => $projectId,
        'targets' => [
            ['instance' => 'staging', 'shelf_id' => 4, 'shelf_name' => 'Four'],
            ['instance' => 'archive', 'enabled' => false],
        ],
    ]);

    same(2, count($answer['targets']), 'the list it was given is the list it has');
    same('staging', (string)$answer['targets'][0]['instance_id'], 'in the order it was given');
    same(4, (int)$answer['targets'][0]['shelf_id'], 'with the shelf');
    same(false, $answer['targets'][1]['enabled'], 'and the switch');
    same(['live'], $answer['forgotten'], 'and it names what it stopped tracking');
    ok(str_contains($answer['next_step'], 'live'), 'and says so again where it matters: ' . $answer['next_step']);
});

test('set_publish_targets refuses an instance the profile does not define', function () {
    $profile = targetProfile();
    ['project' => $project] = targetCourse('Strict over MCP', (int)$profile['id']);
    $projectId = (int)$project['id'];
    Targets::setPrimary('tgt', $projectId, 'live');

    $e = raises(
        static fn() => targetTool('set_publish_targets', [
            'course_id' => $projectId,
            'targets' => [['instance' => 'invented']],
        ]),
        'an instance nobody has credentials for is refused'
    );
    ok(str_contains($e->getMessage(), 'invented'), 'and named: ' . $e->getMessage());
    same('live', (string)Targets::primary($projectId)['instance_id'], 'and nothing was written');
});

test('update_course still points a course at one instance, and replaces the last one', function () {
    $profile = targetProfile();
    ['project' => $project] = targetCourse('Legacy door', (int)$profile['id']);
    $projectId = (int)$project['id'];

    $answer = targetTool('update_course', [
        'course_id' => $projectId,
        'bookstack_instance' => 'live',
        'shelf_id' => 2,
        'shelf_name' => 'Two',
    ]);
    ok(in_array('bookstack_instance', $answer['changed'], true), 'the change is reported');
    same('live', (string)Targets::primary($projectId)['instance_id'], 'and made');
    same(2, (int)Targets::primary($projectId)['shelf_id'], 'shelf included');

    targetTool('update_course', ['course_id' => $projectId, 'bookstack_instance' => 'staging']);
    same(1, count(Targets::all($projectId)), 'switching does not leave the old one behind');
    same('staging', (string)Targets::primary($projectId)['instance_id'], 'it is the new one');
});

test('update_course refuses a shelf for a course that has nowhere to put it', function () {
    $profile = targetProfile();
    ['project' => $project] = targetCourse('Shelf with no wiki', (int)$profile['id']);

    $e = raises(
        static fn() => targetTool('update_course', ['course_id' => (int)$project['id'], 'shelf_id' => 3]),
        'a shelf on a course with no destination is refused rather than dropped'
    );
    ok(str_contains($e->getMessage(), 'bookstack_instance'), 'and says what to send instead: ' . $e->getMessage());
});

/* ------------------------------------------------------------ the upgrade - */

test('an installation upgrading keeps the book it already published', function () {
    // A 4.6 course: the destination and everything the push created live in
    // columns, and there is not a publish_targets row in sight.
    Db::run(
        "INSERT INTO projects (username, name, topic, bs_instance_id, shelf_id, shelf_name,
                               book_id, book_slug, book_url, pushed_hash, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
        ['tgt', 'Legacy', 'testing', 'live', 4, 'Old shelf', 77, 'legacy', 'https://live.example/books/legacy', 'bookhash', time(), time()]
    );
    $projectId = Db::lastId();

    Db::run("INSERT INTO chapters (project_id, idx, title, bs_id, bs_slug, bs_url, pushed_hash) VALUES (?,0,?,?,?,?,?)",
        [$projectId, 'Old chapter', 88, 'old-chapter', 'https://live.example/books/legacy/chapter/old-chapter', 'chash']);
    $chapterId = Db::lastId();

    Db::run(
        "INSERT INTO pages (project_id, chapter_id, idx, title, content, bs_id, bs_slug, bs_url, pushed_hash, updated_at)
         VALUES (?,?,0,?,?,?,?,?,?,?)",
        [$projectId, $chapterId, 'Old page', 'text', 99, 'old-page', 'https://live.example/books/legacy/page/old-page', 'phash', time()]
    );
    $pageId = Db::lastId();

    // A page that was never published carries nothing, and must not gain a row.
    Db::run('INSERT INTO pages (project_id, chapter_id, idx, title, content, updated_at) VALUES (?,?,1,?,?,?)',
        [$projectId, $chapterId, 'Never published', 'text', time()]);
    $unpublished = Db::lastId();

    $upgrade = new ReflectionMethod(CourseForge\Support\Db::class, 'upgradeToV8');
    $upgrade->setAccessible(true);
    $upgrade->invoke(null, Db::pdo());

    $targets = Targets::all($projectId);
    same(1, count($targets), 'the one destination it had became its first target');
    $target = $targets[0];
    same('live', (string)$target['instance_id'], 'pointing at the same instance');
    same(4, (int)$target['shelf_id'], 'with the same shelf');
    same(77, (int)$target['book_id'], 'holding the same book');
    same('bookhash', (string)$target['pushed_hash'], 'and the same fingerprint, so the next push does not rewrite it');

    $items = Targets::items((int)$target['id']);
    same(88, (int)$items['chapter'][$chapterId]['bs_id'], 'the chapter carried its id across');
    same('chash', (string)$items['chapter'][$chapterId]['pushed_hash'], 'and its fingerprint');
    same(99, (int)$items['page'][$pageId]['bs_id'], 'and so did the page');
    same('phash', (string)$items['page'][$pageId]['pushed_hash'], 'with its own');
    ok(!isset($items['page'][$unpublished]), 'while a page that was never published gained nothing');

    // And running it a second time - which a re-migration would - changes nothing.
    $upgrade->invoke(null, Db::pdo());
    same(1, count(Targets::all($projectId)), 'a second run adds no duplicate destination');
    same(2, count(Db::rows('SELECT id FROM publish_items WHERE target_id = ?', [(int)$target['id']])),
        'and no duplicate items');
});

test('a course that never chose an instance gains no destination on upgrade', function () {
    Db::run('INSERT INTO projects (username, name, topic, created_at, updated_at) VALUES (?,?,?,?,?)',
        ['tgt', 'Never published anywhere', 'testing', time(), time()]);
    $projectId = Db::lastId();

    $upgrade = new ReflectionMethod(CourseForge\Support\Db::class, 'upgradeToV8');
    $upgrade->setAccessible(true);
    $upgrade->invoke(null, Db::pdo());

    same([], Targets::all($projectId), 'nothing was invented for it');
});

/* -------------------------------------------------------- the API refusals */

test('a destination with no credentials behind it is named before the push starts', function () {
    $profile = targetProfile();
    ['project' => $project] = targetCourse('Strict', (int)$profile['id']);
    $projectId = (int)$project['id'];

    Targets::replaceAll('tgt', $projectId, [['instance_id' => 'live'], ['instance_id' => 'staging']]);
    same([], CourseForge\Mcp\Handlers\PublishTools::blockingReasons(
        Projects::require('tgt', $projectId),
        'tgt'
    ), 'two good destinations block nothing');

    // The profile stops defining one of them.
    Profiles::update('tgt', (int)$profile['id'], 'two wikis', [
        'bookstack' => [
            ['id' => 'staging', 'name' => 'Staging wiki', 'base_url' => 'https://stage.example', 'token_id' => 'c', 'token_secret' => 'd'],
        ],
    ]);

    $reasons = CourseForge\Mcp\Handlers\PublishTools::blockingReasons(Projects::require('tgt', $projectId), 'tgt');
    same(1, count($reasons), 'which is one thing standing in the way');
    ok(str_contains($reasons[0], '"live"'), 'and it says which instance: ' . $reasons[0]);
});
