<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * The punctuation pass, aimed at text that is already written.
 *
 * Correcting a page on the way in is the right place for it and is no help at
 * all to the pages written before the setting existed, written with it switched
 * off, or written by a release whose rules were worse. This is the door for
 * those: the same pass, over a course, a chapter or one page, run because
 * somebody asked for it rather than because a profile said so.
 *
 * Four properties are what make it safe to point at a finished course, and each
 * is tested here.
 *
 *   - it runs whatever the profile says, because the profile answers a
 *     different question from the one the button asks;
 *   - it only writes what it changed, so a course that was already right comes
 *     back with nothing touched and nothing newly out of sync with its wiki;
 *   - it can be asked without being obeyed, which is what lets a dialog say
 *     what it is about to do before it does it;
 *   - and the outline is rewritten from the titles it corrected, because
 *     applyStructure matches pages by title and the two must agree.
 */

use CourseForge\Domain\Chapters;
use CourseForge\Domain\Pages;
use CourseForge\Domain\Profiles;
use CourseForge\Domain\Projects;
use CourseForge\Domain\Typesetter;
use CourseForge\Mcp\Tools;
use CourseForge\Security\Actor;
use CourseForge\Support\Config;
use CourseForge\Support\Db;

const CROOKED_CONTENT = "Er sagte **\"Hallo\"** und ging - wirklich.\n\n\n\nDas Ende ... kam.";
const STRAIGHTENED_CONTENT = "Er sagte **„Hallo“** und ging – wirklich.\n\nDas Ende … kam.";

/** The account every fixture here belongs to; the suite shares one database. */
function typesetterOwner(): string
{
    return 'typesetter';
}

/**
 * A course whose text is exactly as wrong as a model writes it.
 *
 * `$typography` is what the profile says about correcting *new* pages, and it
 * defaults to off on purpose: the whole point of this pass is that it is not
 * that setting's business.
 *
 * @return array{0:array<string,mixed>,1:int,2:array<int,int>}
 */
function crookedCourse(string $language = 'Deutsch', bool $typography = false, int $pages = 2): array
{
    $owner = typesetterOwner();
    $profile = Profiles::create($owner, 'p-' . bin2hex(random_bytes(4)), [
        'language' => $language,
        'typography' => $typography,
    ]);

    $project = Projects::create($owner, 'Kurs', 'Ein Thema', (int)$profile['id']);
    $projectId = (int)$project['id'];
    Projects::update($owner, $projectId, [
        'book_title' => 'Das "grosse" Buch',
        'book_desc' => 'Ein Buch ... mit Strich - hier.',
    ]);

    Db::run(
        'INSERT INTO chapters (project_id, idx, title, description) VALUES (?,?,?,?)',
        [$projectId, 0, 'Das "erste" Kapitel', 'Ein "Ziel" ... hier.']
    );
    $chapterId = Db::lastId();

    $pageIds = [];
    for ($i = 0; $i < $pages; $i++) {
        Db::run(
            'INSERT INTO pages (project_id, chapter_id, idx, title, content, status, updated_at)
             VALUES (?,?,?,?,?,?,?)',
            [$projectId, $chapterId, $i, 'Seite "' . ($i + 1) . '"', CROOKED_CONTENT, 'generated', 1000]
        );
        $pageIds[] = Db::lastId();
    }

    return [Projects::require($owner, $projectId), $chapterId, $pageIds];
}

function pageContent(int $projectId, int $pageId): string
{
    return (string)(Pages::find($projectId, $pageId)['content'] ?? '');
}

test('a course written before the switch existed can be corrected afterwards', static function (): void {
    [$project, $chapterId, $pageIds] = crookedCourse();
    $projectId = (int)$project['id'];

    $result = Typesetter::apply(typesetterOwner(), $project, 'course', null);

    same('Deutsch', $result['language'], 'the course language is read from its profile');
    same('de', $result['style'], 'which resolves to the arrangement German uses');
    same(['„', '“'], $result['marks'], 'and the screen can say which marks that is');
    same(2, $result['scanned']['pages'], 'every page was looked at');
    same(2, $result['corrected']['pages'], 'and both of them needed something');
    same(1, $result['corrected']['chapters'], 'so did the chapter');
    same(1, $result['corrected']['course'], 'and the book title and description');

    same(STRAIGHTENED_CONTENT, pageContent($projectId, $pageIds[0]), 'the stored page is the corrected one');
    same('Seite „1“', (string)Pages::find($projectId, $pageIds[0])['title'], 'and so is its title');

    $chapter = Chapters::find($projectId, $chapterId) ?? [];
    same('Das „erste“ Kapitel', (string)$chapter['title'], 'the chapter title');
    same('Ein „Ziel“ … hier.', (string)$chapter['description'], 'and its description');

    $after = Projects::require(typesetterOwner(), $projectId);
    same('Das „grosse“ Buch', (string)$after['book_title'], 'the book title');
    same('Ein Buch … mit Strich – hier.', (string)$after['book_desc'], 'and the book description');

    // Titles are lines of the outline, and applyStructure matches pages by
    // them. A correction that left the outline saying the old title would
    // orphan the page's own text on the next apply.
    ok(str_contains((string)$after['structure_md'], 'Seite „1“'), 'the outline was rewritten from the corrected titles');
    ok(!str_contains((string)$after['structure_md'], 'Seite "1"'), 'and holds none of the old ones');
});

test('the profile switch does not get a say in it', static function (): void {
    // `typography => false` is an answer about pages being generated. Somebody
    // standing in front of a finished course asking for it corrected is asking
    // something else, and a setting that refused would be refusing an
    // instruction.
    [$project, , $pageIds] = crookedCourse('Deutsch', false);
    Typesetter::apply(typesetterOwner(), $project, 'course', null);
    same(
        STRAIGHTENED_CONTENT,
        pageContent((int)$project['id'], $pageIds[0]),
        'a profile that says no to new pages does not say no to this'
    );

    // And neither does the installation-wide default the profile inherits.
    Config::set('app.typography', false);
    try {
        [$off, , $pages] = crookedCourse('Deutsch', false);
        Typesetter::apply(typesetterOwner(), $off, 'course', null);
        same(STRAIGHTENED_CONTENT, pageContent((int)$off['id'], $pages[0]), 'nor does the installation');
    } finally {
        Config::reset('app.typography');
    }
});

test('a preview does everything except the writing', static function (): void {
    [$project, , $pageIds] = crookedCourse();
    $projectId = (int)$project['id'];

    $preview = Typesetter::apply(typesetterOwner(), $project, 'course', null, null, true);

    ok($preview['preview'], 'the answer says it was a preview');
    same(2, $preview['corrected']['pages'], 'and counts what would change');
    same(CROOKED_CONTENT, pageContent($projectId, $pageIds[0]), 'while the page is exactly as it was');
    same(
        'Ein Buch ... mit Strich - hier.',
        (string)Projects::require(typesetterOwner(), $projectId)['book_desc'],
        'and so is the course'
    );

    // The number in the dialog has to be the number that changes, or the
    // dialog is an estimate rather than an answer.
    $real = Typesetter::apply(typesetterOwner(), $project, 'course', null);
    same($preview['corrected'], $real['corrected'], 'the preview counted what the run then changed');
});

test('correcting twice changes nothing the second time', static function (): void {
    [$project, , $pageIds] = crookedCourse();
    $projectId = (int)$project['id'];

    Typesetter::apply(typesetterOwner(), $project, 'course', null);
    $again = Typesetter::apply(typesetterOwner(), Projects::require(typesetterOwner(), $projectId), 'course', null);

    same(0, $again['total'], 'a corrected course needs nothing');
    same([], $again['changed'], 'and names nothing');
    same(STRAIGHTENED_CONTENT, pageContent($projectId, $pageIds[0]), 'the text is byte for byte what it was');
});

test('a page that needed nothing is not written at all', static function (): void {
    // Writing it anyway would move updated_at and mark the page as differing
    // from what is in the wiki, which is a publish somebody has to do for a
    // change that never happened.
    [$project, $chapterId, ] = crookedCourse();
    $projectId = (int)$project['id'];

    Db::run(
        'INSERT INTO pages (project_id, chapter_id, idx, title, content, status, updated_at)
         VALUES (?,?,?,?,?,?,?)',
        [$projectId, $chapterId, 9, 'Schon richtig', 'Ein „Wort“ und ein Strich – fertig.', 'generated', 1000]
    );
    $cleanId = Db::lastId();

    Typesetter::apply(typesetterOwner(), $project, 'course', null);

    same(1000, (int)Pages::find($projectId, $cleanId)['updated_at'], 'the untouched page kept its timestamp');
});

test('a chapter and a page are corrected without their neighbours', static function (): void {
    [$project, $chapterId, $pageIds] = crookedCourse('Deutsch', false, 2);
    $projectId = (int)$project['id'];

    Db::run('INSERT INTO chapters (project_id, idx, title, description) VALUES (?,?,?,?)',
        [$projectId, 1, 'Das "zweite" Kapitel', '']);
    $otherChapter = Db::lastId();
    Db::run(
        'INSERT INTO pages (project_id, chapter_id, idx, title, content, status, updated_at)
         VALUES (?,?,?,?,?,?,?)',
        [$projectId, $otherChapter, 0, 'Fremde Seite', CROOKED_CONTENT, 'generated', 1000]
    );
    $otherPage = Db::lastId();

    $result = Typesetter::apply(typesetterOwner(), $project, 'chapter', $chapterId);

    same('chapter', $result['scope'], 'the scope is reported back');
    same(2, $result['scanned']['pages'], 'only the pages of that chapter were looked at');
    same(CROOKED_CONTENT, pageContent($projectId, $otherPage), 'the other chapter was left alone');
    same('Das "zweite" Kapitel', (string)Chapters::find($projectId, $otherChapter)['title'], 'title and all');

    // And one page on its own, from the chapter that has not been touched.
    $single = Typesetter::apply(typesetterOwner(), $project, 'page', $otherPage);
    same(1, $single['scanned']['pages'], 'one page');
    same(0, $single['scanned']['chapters'], 'and no chapter, because a page is not one');
    same(STRAIGHTENED_CONTENT, pageContent($projectId, $otherPage), 'which is now corrected');
    same('Das "zweite" Kapitel', (string)Chapters::find($projectId, $otherChapter)['title'], 'its chapter still is not');
});

test('a scope that names one thing has to say which', static function (): void {
    [$project, , ] = crookedCourse();

    foreach (['chapter', 'page'] as $level) {
        $error = raises(
            static fn() => Typesetter::apply(typesetterOwner(), $project, $level, null),
            'a ' . $level . ' scope with no id'
        );
        ok(str_contains($error->getMessage(), $level . ' id is required'), 'and says so in those words');
    }

    $error = raises(
        static fn() => Typesetter::apply(typesetterOwner(), $project, 'everything', null),
        'a scope that is not one of the three'
    );
    ok(str_contains($error->getMessage(), 'course, chapter, page'), 'and lists the ones that are');
});

test('the language can be overridden, and comes from the installation when there is no profile',
    static function (): void {
        [$project, , $pageIds] = crookedCourse('Deutsch');
        $projectId = (int)$project['id'];

        $result = Typesetter::apply(typesetterOwner(), $project, 'page', $pageIds[0], 'Français');
        same('fr', $result['style'], 'the override decides');
        ok(
            str_contains(pageContent($projectId, $pageIds[0]), "\u{00AB}\u{202F}Hallo\u{202F}\u{00BB}"),
            'and the page is set in French, guillemets and narrow spaces and all'
        );

        // A course whose profile was deleted is still a course in a language.
        Config::set('app.default_language', 'Deutsch');
        try {
            $orphan = Projects::create(typesetterOwner(), 'Ohne Profil', 'Thema', null);
            Db::run('INSERT INTO chapters (project_id, idx, title, description) VALUES (?,?,?,?)',
                [(int)$orphan['id'], 0, 'Kapitel', '']);
            Db::run(
                'INSERT INTO pages (project_id, chapter_id, idx, title, content, status, updated_at)
                 VALUES (?,?,?,?,?,?,?)',
                [(int)$orphan['id'], Db::lastId(), 0, 'Seite', 'Ein "Wort".', 'generated', 1000]
            );
            $pageId = Db::lastId();

            $fallback = Typesetter::apply(
                typesetterOwner(),
                Projects::require(typesetterOwner(), (int)$orphan['id']),
                'course',
                null
            );
            same('Deutsch', $fallback['language'], 'the installation answers for a course with no profile');
            same('Ein „Wort“.', pageContent((int)$orphan['id'], $pageId), 'and the page is set that way');
        } finally {
            Config::reset('app.default_language');
        }
    });

test('the list of what changed is a sample and the counts are not', static function (): void {
    [$project, , ] = crookedCourse('Deutsch', false, 60);

    $result = Typesetter::apply(typesetterOwner(), $project, 'course', null, null, true);

    same(60, $result['corrected']['pages'], 'every page is counted');
    same(40, $result['listed'], 'and forty of them are named');
    same(40, count($result['changed']), 'which is what the list holds');
    ok($result['total'] > $result['listed'], 'so a screen knows to say "and n more"');
});

/* ------------------------------------------------------------------ over MCP */

test('fix_typography corrects a course over MCP', static function (): void {
    [$project, $chapterId, $pageIds] = crookedCourse();
    $projectId = (int)$project['id'];
    $actor = Actor::make(typesetterOwner(), 'Typesetter', Actor::ROLE_USER);

    $preview = (array)(Tools::call($actor, 'fix_typography', [
        'course_id' => $projectId,
        'preview' => true,
    ])['data'] ?? []);
    same(2, $preview['corrected']['pages'], 'the preview counts');
    same(CROOKED_CONTENT, pageContent($projectId, $pageIds[0]), 'and writes nothing');
    ok(str_contains((string)$preview['next_step'], 'without preview'), 'and says how to go through with it');

    $done = (array)(Tools::call($actor, 'fix_typography', ['course_id' => $projectId])['data'] ?? []);
    same(2, $done['corrected']['pages'], 'the run corrects the same two');
    same(STRAIGHTENED_CONTENT, pageContent($projectId, $pageIds[0]), 'and this time it is stored');
    ok(str_contains((string)$done['next_step'], 'publish_course'), 'and points at the wiki it just left behind');

    // One page, named the way the tool's own description says it is named.
    Db::run('UPDATE pages SET content = ? WHERE id = ?', [CROOKED_CONTENT, $pageIds[1]]);
    $one = (array)(Tools::call($actor, 'fix_typography', [
        'course_id' => $projectId,
        'page_id' => $pageIds[1],
    ])['data'] ?? []);
    same('page', $one['scope'], 'page_id wins over the course');
    same(1, $one['scanned']['pages'], 'and is the only page looked at');

    // A page and a chapter that disagree is a mistake, not a preference.
    $clash = raises(
        static fn() => Tools::call($actor, 'fix_typography', [
            'course_id' => $projectId,
            'chapter_id' => $chapterId + 999,
            'page_id' => $pageIds[0],
        ]),
        'a page_id and a chapter_id that disagree'
    );
    ok(
        str_contains($clash->getMessage(), 'not chapter ' . ($chapterId + 999)),
        'and is answered with which chapter the page is actually in'
    );
});
