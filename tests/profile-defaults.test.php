<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * A profile decides content details one level above the course.
 *
 * Until 5.0 the chain was installation default, course, chapter, page, and the
 * only way to give twenty courses the same house style - objectives on, 1,500
 * words, a standing audience - was to open twenty Details tabs. The profile is
 * what those courses already share, so it is where a default belongs: a course
 * inherits what its profile decided and can still override any of it, exactly
 * as a chapter inherits from the course.
 *
 * These tests walk that chain end to end: what a profile stores, what a course
 * on it inherits, what wins when the two disagree, what happens when the
 * profile goes, and that a connected client can do all of it through
 * set_profile_details rather than only from the browser.
 */

use CourseForge\Domain\Details;
use CourseForge\Domain\Profiles;
use CourseForge\Domain\Projects;
use CourseForge\Mcp\Tools;
use CourseForge\Security\Actor;
use CourseForge\Support\Db;

function houseActor(): Actor
{
    return Actor::make('housestyle', 'House Style', Actor::ROLE_USER);
}

/** @return array<string,mixed> */
function houseCall(string $tool, array $args): array
{
    return (array)(Tools::call(houseActor(), $tool, $args)['data'] ?? []);
}

/** A chapter with one page under a course, the way the parity tests make them. */
function houseChapterAndPage(int $projectId): array
{
    Db::run('INSERT INTO chapters (project_id, idx, title, description) VALUES (?,?,?,?)',
        [$projectId, 0, 'Kapitel', '']);
    $chapterId = Db::lastId();
    Db::run(
        'INSERT INTO pages (project_id, chapter_id, idx, title, content, status, updated_at) VALUES (?,?,?,?,?,?,?)',
        [$projectId, $chapterId, 0, 'Seite', '', 'pending', time()]
    );
    return [$chapterId, Db::lastId()];
}

test('a profile stores content details of its own, and only the ones the catalogue knows', static function (): void {
    $profile = Profiles::create('housestyle', 'Hausstil', ['details' => [
        'features' => ['objectives' => 1, 'summary' => 0, 'no_such_feature' => 1, 'exercises' => -1],
        'params' => ['min_length' => 1500, 'audience' => '  working developers ', 'bogus' => 3, 'anki_cards' => null],
    ]]);

    $stored = Profiles::detailsOf($profile['data']);
    same(['objectives' => Details::ON, 'exercises' => Details::OFF], $stored['features'],
        'a decided feature is kept, an inherited one and an unknown one are not');
    same(['min_length' => 1500, 'audience' => 'working developers'], $stored['params'],
        'a value is kept and trimmed, an unknown one and a null one are not');

    $blank = Profiles::create('housestyle', 'Leer', []);
    same(['features' => [], 'params' => []], Profiles::detailsOf($blank['data']),
        'a profile that never decided anything has an empty layer');

    $row = Db::row('SELECT data FROM profiles WHERE id = ?', [(int)$blank['id']]);
    ok(str_contains((string)$row['data'], '"features":{}'), 'and an empty layer is stored as an object, not a list');

    $sent = Profiles::redact($blank)['data'];
    ok(
        json_encode($sent['details']) === '{"features":{},"params":{}}',
        'so the browser receives {} it can write a key into, never [] that would drop it'
    );
});

test('a course inherits what its profile decided, and can still override it', static function (): void {
    $profile = Profiles::create('housestyle', 'Vorgaben', ['details' => [
        'features' => ['objectives' => 1, 'exercises' => -1],
        'params' => ['min_length' => 1500],
    ]]);
    $project = Projects::create('housestyle', 'Kurs', 'Thema', (int)$profile['id']);
    $projectId = (int)$project['id'];

    $tree = Projects::tree('housestyle', $projectId);
    same(true, $tree['details']['inherited']['features']['objectives'], 'the course inherits a feature the profile switched on');
    same(false, $tree['details']['inherited']['features']['exercises'], 'and one it switched off');
    same(1500, $tree['details']['inherited']['params']['min_length'], 'and a value it set');
    same(true, $tree['details']['effective']['features']['objectives'], 'which is what applies while the course stays silent');
    same(true, $tree['profile_decides'], 'and the tree says the profile has decided something');
    same([], $tree['details']['own']['features'], 'while the course itself still stores nothing');

    Projects::patchDetails('housestyle', $projectId, ['objectives' => -1], ['min_length' => 900]);
    $tree = Projects::tree('housestyle', $projectId);
    same(false, $tree['details']['effective']['features']['objectives'], 'a course override beats the profile');
    same(900, $tree['details']['effective']['params']['min_length'], 'for a value as well');
    same(true, $tree['details']['inherited']['features']['objectives'], 'and "inherited" still shows what the profile says');

    [$chapterId, $pageId] = houseChapterAndPage($projectId);
    $tree = Projects::tree('housestyle', $projectId);
    $chapter = $tree['chapters'][0];
    same(false, $chapter['details']['inherited']['features']['exercises'], 'a chapter inherits the profile through the course');
    same(900, $chapter['details']['inherited']['params']['min_length'], 'with the course winning where the two disagree');
    $page = $chapter['pages'][0];
    same(false, $page['details']['effective']['features']['exercises'], 'and a page gets the same answer');

    // The generator resolves the same chain, so the brief a page is written
    // from follows the profile too.
    $resolved = Details::resolve(...Projects::chain(Projects::require('housestyle', $projectId)));
    same(false, $resolved['features']['exercises'], 'Projects::chain walks profile then course');
    same(900, $resolved['params']['min_length'], 'in that order');

    $listed = array_values(array_filter(Projects::all('housestyle'), static fn(array $p): bool => (int)$p['id'] === $projectId))[0];
    same(false, $listed['auto_links'], 'the course list resolves through the profile as well');
    Profiles::update('housestyle', (int)$profile['id'], 'Vorgaben', ['details' => ['features' => ['auto_links' => 1]]]);
    $listed = array_values(array_filter(Projects::all('housestyle'), static fn(array $p): bool => (int)$p['id'] === $projectId))[0];
    same(true, $listed['auto_links'], 'and follows a change to the profile without the course being touched');
    same(true, Projects::tree('housestyle', $projectId)['details']['inherited']['features']['auto_links'],
        'a profile written in this request is read back in this request');
});

test('a course without a profile, or whose profile went, starts from the installation', static function (): void {
    $baseline = Details::baseline();

    $alone = Projects::create('housestyle', 'Ohne', 'Thema', null);
    $tree = Projects::tree('housestyle', (int)$alone['id']);
    same($baseline, $tree['details']['inherited'], 'no profile means the installation defaults, as always');
    same(false, $tree['profile_decides'], 'and nothing above the course has decided anything');

    $profile = Profiles::create('housestyle', 'Kurzlebig', ['details' => ['features' => ['anki' => 1]]]);
    $project = Projects::create('housestyle', 'Verwaist', 'Thema', (int)$profile['id']);
    same(true, Projects::tree('housestyle', (int)$project['id'])['details']['inherited']['features']['anki'],
        'a course on the profile inherits from it');

    Profiles::delete('housestyle', (int)$profile['id']);
    $tree = Projects::tree('housestyle', (int)$project['id']);
    same($baseline, $tree['details']['inherited'], 'once the profile is gone the course is back on the installation');
});

test('set_profile_details decides, reads back, refuses nonsense and resets', static function (): void {
    $profile = Profiles::create('housestyle', 'Per MCP', []);
    $profileId = (int)$profile['id'];
    $project = Projects::create('housestyle', 'Auf dem Profil', 'Thema', $profileId);

    $set = houseCall('set_profile_details', [
        'profile_id' => $profileId,
        'features' => ['anki' => 'on', 'summary' => -1],
        'values' => ['exercise_count' => 5, 'audience' => 'apprentices'],
    ]);
    same(true, $set['updated'], 'the profile was written');
    same('on', $set['decided']['features']['anki'], 'a feature given as a word is understood');
    same('off', $set['decided']['features']['summary'], 'and one given as a number');
    same(5, $set['decided']['values']['exercise_count'], 'a value is stored');
    same(true, $set['courses_start_from']['features']['anki'], 'and the answer says what a course now starts from');
    same(1, count($set['courses']), 'naming the courses it reaches');

    $read = houseCall('get_profile', ['profile_id' => $profileId]);
    same('on', $read['content_defaults']['features']['anki'], 'get_profile reads the decision back');
    same('apprentices', $read['content_defaults']['values']['audience'], 'values too');

    $details = houseCall('get_details', ['course_id' => (int)$project['id']]);
    $anki = array_values(array_filter((array)$details['features'], static fn(array $f): bool => $f['key'] === 'anki'))[0];
    same(true, $anki['inherited'], 'a course on the profile inherits it');
    same('profile', $anki['from'], 'and get_details names the profile as where it comes from');

    $error = raises(
        static fn() => houseCall('set_profile_details', ['profile_id' => $profileId, 'features' => ['no_such' => 1]]),
        'an unknown feature'
    );
    ok(str_contains($error->getMessage(), 'no content detail'), 'an unknown feature is refused, not dropped');

    $error = raises(
        static fn() => houseCall('set_profile_details', ['profile_id' => $profileId, 'values' => ['min_length' => 5000]]),
        'a minimum above the maximum'
    );
    ok(str_contains($error->getMessage(), 'above Maximum length'), 'a crossed length pair is refused with the numbers');
    same([], houseCall('get_profile', ['profile_id' => $profileId])['content_defaults']['values']['min_length'] ?? [],
        'and nothing was written by the refused call');

    $error = raises(
        static fn() => houseCall('set_profile_details', ['profile_id' => $profileId]),
        'an empty call'
    );
    ok(str_contains($error->getMessage(), 'Nothing to change'), 'a call that decides nothing is refused');

    $reset = houseCall('set_profile_details', ['profile_id' => $profileId, 'reset_all' => true]);
    same(['features' => [], 'values' => []], $reset['decided'], 'reset_all leaves the profile deciding nothing');
    same(Details::baseline()['features'], $reset['courses_start_from']['features'],
        'so its courses start from the installation again');
});
