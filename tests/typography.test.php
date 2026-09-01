<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Punctuation set the way the language sets it.
 *
 * The bug this exists for is one glyph wide and every page long: a model
 * writing German opens a quotation with `„` and closes it with `"`, because the
 * two are one keystroke apart in the training data. It is not wrong enough to
 * notice on one page and it is wrong on all of them.
 *
 * Three properties carry the whole feature and each is tested here.
 *
 *   - the marks come out right, for the languages that differ from English;
 *   - nothing that is not prose is touched, which is the property that makes it
 *     safe to run over a course about a programming language at all;
 *   - and it is idempotent, so a page written, regenerated and re-imported is
 *     byte-for-byte the page written once. Anything else is a diff that appears
 *     from nowhere on a page nobody edited.
 *
 * The last of those is checked on every case rather than in a test of its own,
 * because the rule that breaks it will be some rule added later, not one of
 * these.
 */

use CourseForge\Ai\PageGenerator;
use CourseForge\Domain\Pages;
use CourseForge\Domain\Profiles;
use CourseForge\Domain\Projects;
use CourseForge\Support\Config;
use CourseForge\Support\Db;
use CourseForge\Support\Typography;

/** Sets the text, and asserts that setting the result again changes nothing. */
function typeset(string $text, string $language, string $what): string
{
    $once = Typography::apply($text, $language);
    same($once, Typography::apply($once, $language), $what . ' - and again, which must change nothing');
    return $once;
}

test('a language is recognised by name, by code, and not by accident', static function (): void {
    foreach (['Deutsch' => 'de', 'German' => 'de', 'de' => 'de', 'DEUTSCH' => 'de',
              'Français' => 'fr', 'french' => 'fr', 'French (Canadian)' => 'fr',
              'Español' => 'es', 'Italiano' => 'it',
              'English' => 'en', 'Nederlands' => 'en', '' => 'en'] as $name => $locale) {
        same($locale, Typography::localeOf((string)$name), '"' . $name . '" is set the ' . $locale . ' way');
    }

    // A two-letter code is only ever an exact match. Estonian is not Spanish,
    // and it is the reason the recogniser does not prefix-match short aliases.
    same('en', Typography::localeOf('Estonian'), 'Estonian is not es');
    same('en', Typography::localeOf('Frisian'), 'Frisian is not fr');
    same('en', Typography::localeOf('Denmark'), 'Denmark is not de');
});

test('the mixed pair a model actually writes comes out as a pair', static function (): void {
    // The reported case, exactly: a correct opening mark and a straight closing
    // one. Position is what tells them apart - counting would need the whole
    // document and would drift after the first stray apostrophe.
    same(
        'Er sagte „Hallo“ und ging.',
        typeset('Er sagte „Hallo" und ging.', 'Deutsch', 'a German quotation half-closed'),
        'the straight mark becomes the closing one'
    );

    same(
        'Ein „Test“ und noch ein „Test“.',
        typeset('Ein "Test" und noch ein "Test".', 'de', 'two straight pairs'),
        'both pairs, and the second is not confused by the first'
    );

    same(
        'Ein ‚kleines‘ Zitat im „großen“.',
        typeset("Ein 'kleines' Zitat im \"großen\".", 'de', 'single inside double'),
        'single marks are German too'
    );
});

test('English keeps its own marks, and its apostrophes', static function (): void {
    same(
        'She said “don’t” — it’s fine.',
        typeset('She said "don\'t" — it\'s fine.', 'English', 'an English quotation with apostrophes'),
        'curly doubles, and an apostrophe inside a word is never a quotation mark'
    );
});

test('French sets the spaces its typography asks for', static function (): void {
    $set = typeset('Il a dit "bonjour" ; puis il est parti !', 'Français', 'a French sentence');

    same(
        "Il a dit \u{00AB}\u{202F}bonjour\u{202F}\u{00BB}\u{202F}; puis il est parti\u{202F}!",
        $set,
        'guillemets with a narrow no-break space inside, and one before ; and !'
    );

    same(
        "Un mot\u{00A0}: voilà.",
        typeset('Un mot : voilà.', 'fr', 'a colon'),
        'a colon takes the wider no-break space'
    );

    // The rule asks for whitespace after the punctuation, which is what keeps it
    // out of a clock, a scale and a table's alignment row.
    same('Le train de 10:30 arrive.', typeset('Le train de 10:30 arrive.', 'fr', 'a time'), 'a time is not punctuation');
});

test('an ellipsis is one character and a dash is a dash', static function (): void {
    same(
        'Erst … dann – und zwar so.',
        typeset('Erst ... dann - und zwar so.', 'de', 'dots and a hyphen'),
        'three dots become one mark, a spaced hyphen becomes a dash'
    );

    // A bullet is a hyphen with nothing but indentation before it, which is why
    // the dash rule asks for a non-space on its left.
    $list = "- Erster Punkt - mit Strich\n  - Zweiter\n";
    same(
        "- Erster Punkt – mit Strich\n  - Zweiter\n",
        typeset($list, 'de', 'a list'),
        'the markers survive and the dash inside the item is still set'
    );
});

test('nothing that is not prose is touched', static function (): void {
    $md = <<<'MD'
        Setze `"strict"` - so.

        ```json
        {"mode": "strict", "hint": "don't ... - ever"}
        ```

        [die "Doku"](https://example.com/a?q="x") und https://example.com/b?z="y"

        Formeln \(a - b\) und $$k = "v"$$ bleiben.

        Ein Verweis (🔗 Zweite Seite) und ein Cloze {{c1::das "Wort"}}.

        | Spalte | Wert |
        |:-------|-----:|
        | "a"    | 1    |

        <img src="x.png" alt="ein Bild">
        MD;

    $set = typeset($md, 'Deutsch', 'a page full of things that are not prose');

    foreach ([
        '`"strict"`' => 'an inline code span',
        '{"mode": "strict", "hint": "don\'t ... - ever"}' => 'a fenced block',
        '[die "Doku"](https://example.com/a?q="x")' => 'a link, target and all',
        'https://example.com/b?z="y"' => 'a bare address',
        '\(a - b\)' => 'inline maths',
        '$$k = "v"$$' => 'display maths',
        '(🔗 Zweite Seite)' => 'a cross-reference marker',
        '{{c1::das "Wort"}}' => 'a cloze deletion',
        '|:-------|-----:|' => "a table's alignment row",
        '<img src="x.png" alt="ein Bild">' => 'an HTML tag',
    ] as $literal => $what) {
        ok(str_contains($set, $literal), $what . ' comes back exactly as it went in');
    }

    // And the prose around them is still set, including across a code span:
    // the rules read what is on either side of what they change, so a span in
    // the middle of a line must not read as the end of one.
    ok(str_contains($set, '`"strict"` – so.'), 'the dash after a code span is still a dash');
    ok(str_contains($set, '| „a“'), 'a quotation inside a table cell is still prose');
});

test('a page that needs nothing is returned unchanged', static function (): void {
    $clean = "# Titel\n\nEin „Wort“ und ein Gedankenstrich – fertig.\n";
    same($clean, Typography::apply($clean, 'de'), 'nothing to do, nothing done');
    same('', Typography::apply('', 'de'), 'and an empty page is not a crash');
    same('   ', Typography::apply('   ', 'de'), 'nor is a blank one');
});

test('the markers the pass works with cannot be smuggled in', static function (): void {
    // The three control characters the implementation parks its decisions on.
    // Arriving in the input, they would have come out as quotation marks or
    // eaten a code span, so they are dropped on the way in.
    $sneaky = "Ein \x01Wort\x02 mit \x03 Zeichen.";
    $set = Typography::apply($sneaky, 'de');
    foreach (["\x01", "\x02", "\x03"] as $control) {
        ok(!str_contains($set, $control), 'the control character does not survive');
    }
});

/* ------------------------------------------------- through the whole funnel */

/**
 * One course with one page, written straight in.
 *
 * @return array{0:array<string,mixed>,1:array<string,mixed>}
 */
function courseWithAPage(string $language, ?bool $typography): array
{
    $owner = 'typographer';
    $data = ['language' => $language];
    if ($typography !== null) {
        $data['typography'] = $typography;
    }
    $profile = Profiles::create($owner, 'p-' . $language . '-' . var_export($typography, true), $data);

    $project = Projects::create($owner, 'Kurs', 'Ein Thema', (int)$profile['id']);
    Db::run('INSERT INTO chapters (project_id, idx, title, description) VALUES (?,?,?,?)',
        [(int)$project['id'], 0, 'Kapitel', '']);
    $chapterId = Db::lastId();
    Db::run('INSERT INTO pages (project_id, chapter_id, idx, title) VALUES (?,?,?,?)',
        [(int)$project['id'], $chapterId, 0, 'Seite']);

    return [$project, Pages::find((int)$project['id'], Db::lastId()) ?? []];
}

test('a page stored through the generator is set in the course language', static function (): void {
    [$project, $page] = courseWithAPage('Deutsch', true);

    PageGenerator::store($project, $page, "Er sagte \"Hallo\" ... und ging - wirklich.");

    same(
        'Er sagte „Hallo“ … und ging – wirklich.',
        (string)(Pages::find((int)$project['id'], (int)$page['id'])['content'] ?? ''),
        'the stored page is the corrected one, because what is stored is what an author reads'
    );
});

test('a profile that says no is left alone', static function (): void {
    [$project, $page] = courseWithAPage('Deutsch', false);

    $written = "Er sagte \"Hallo\" ... und ging - wirklich.";
    PageGenerator::store($project, $page, $written);

    same(
        $written,
        (string)(Pages::find((int)$project['id'], (int)$page['id'])['content'] ?? ''),
        'nothing was touched'
    );
});

test('a profile that has never said follows the installation', static function (): void {
    Config::set('app.typography', false);
    try {
        [$project, $page] = courseWithAPage('Deutsch', null);
        $written = 'Ein "Wort".';
        PageGenerator::store($project, $page, $written);
        same(
            $written,
            (string)(Pages::find((int)$project['id'], (int)$page['id'])['content'] ?? ''),
            'switched off for the whole installation, and the profile inherits that'
        );
    } finally {
        Config::reset('app.typography');
    }

    [$project, $page] = courseWithAPage('Deutsch', null);
    PageGenerator::store($project, $page, 'Ein "Wort".');
    same(
        'Ein „Wort“.',
        (string)(Pages::find((int)$project['id'], (int)$page['id'])['content'] ?? ''),
        'and on again by default'
    );
});
