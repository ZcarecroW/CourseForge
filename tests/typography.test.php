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
    $sneaky = "Ein \x01Wort\x02 mit \x03 und \x04 Zeichen.";
    $set = Typography::apply($sneaky, 'de');
    foreach (["\x01", "\x02", "\x03", "\x04"] as $control) {
        ok(!str_contains($set, $control), 'the control character does not survive');
    }
});

test('a quotation mark reads through the emphasis around it', static function (): void {
    // The bug this release exists for. A Markdown asterisk stood where a space
    // should have been, the opening rule declined the mark on its account, and
    // the closing rule took it - so a bold quotation came out with two closing
    // marks and the report read "the first one is wrong".
    same(
        'Das **„Wort“** ist fett.',
        typeset('Das **"Wort"** ist fett.', 'de', 'a bold quotation'),
        'a pair inside ** is still a pair'
    );

    foreach (['*%s*', '_%s_', '__%s__', '***%s***', '~~%s~~'] as $wrapper) {
        same(
            sprintf($wrapper, '„Wort“'),
            typeset(sprintf($wrapper, '"Wort"'), 'de', 'a quotation inside ' . $wrapper),
            'emphasis is transparent to the decision, whichever kind it is'
        );
    }

    same(
        "- **„Fett“** hier\n- *„Kursiv“* dort\n",
        typeset("- **\"Fett\"** hier\n- *\"Kursiv\"* dort\n", 'de', 'emphasis inside a list'),
        'and inside a list item, which is its own block'
    );
});

test('a mark with no space on either side still picks a side', static function (): void {
    same(
        'Das Wort„Test“ ohne Leerzeichen.',
        typeset('Das Wort"Test" ohne Leerzeichen.', 'de', 'a quotation glued to the word before it'),
        'glued on both sides, so the open quotation decides: there is none, so it opens'
    );

    same(
        'Ein „Wort“-Bindestrich.',
        typeset('Ein "Wort"-Bindestrich.', 'de', 'a quotation a hyphen is attached to'),
        'a hyphen after the closing mark is not part of the quotation'
    );
});

test('a French quotation that was already right stays right', static function (): void {
    // Both of a French closing guillemet's neighbours are spaces, which is
    // exactly what an opening one looks like. Reading position alone, the pass
    // used to turn every `»` into a `«` - and it did it to correct French,
    // which is the worst version: text that was right before it ran.
    same(
        "Il a dit \u{00AB}\u{202F}bonjour\u{202F}\u{00BB} et voil\u{00E0}.",
        typeset('Il a dit « bonjour » et voilà.', 'fr', 'a correct French quotation'),
        'the closing guillemet closes, because a quotation is open and only it can'
    );

    same(
        "\u{00AB}\u{202F}Un\u{202F}\u{00BB} et \u{00AB}\u{202F}deux\u{202F}\u{00BB}.",
        typeset('« Un » et « deux ».', 'fr', 'two French quotations in a row'),
        'and the second pair is not confused by the first'
    );
});

test('a pair is closed by the mark that opened it', static function (): void {
    // Not by the character the model typed, which is the whole point: the model
    // types the wrong one. And the pairs alternate with the depth, so a double
    // quotation inside a double quotation is set as the inner pair.
    same(
        'Er sagte „Er sagte ‚Hallo‘“ laut.',
        typeset('Er sagte "Er sagte "Hallo"" laut.', 'de', 'a double inside a double'),
        'the inner pair is the secondary one, whichever character was typed'
    );

    same(
        'Nested: “He said ‘yes’ loudly.”',
        typeset('Nested: "He said \'yes\' loudly."', 'English', 'English nesting'),
        'and English alternates the same way'
    );

    same(
        'Ein „falsches“ Paar und ein „umgekehrtes“.',
        typeset('Ein ”falsches„ Paar und ein »umgekehrtes«.', 'de', 'marks pointing the wrong way'),
        'a mark facing the wrong way is still read by where it stands'
    );
});

test('an inch is not a closing quotation mark', static function (): void {
    same(
        'Das Brett ist 5″ breit und 3′ hoch.',
        typeset('Das Brett ist 5" breit und 3\' hoch.', 'de', 'a measurement'),
        'a straight mark after a digit, with nothing open, is a prime'
    );

    same(
        'Die Antwort ist „42“.',
        typeset('Die Antwort ist "42".', 'de', 'a quotation that ends on a digit'),
        'and the same mark closes a quotation when there is one to close'
    );
});

test('the apostrophe is told from the quotation mark', static function (): void {
    same(
        'It’s the ’90s, and rock ’n’ roll never left.',
        typeset("It's the '90s, and rock 'n' roll never left.", 'en', 'apostrophes of three kinds'),
        'inside a word, in front of a decade, and around an elided word'
    );

    same(
        'The dogs’ bowls and James’ book.',
        typeset("The dogs' bowls and James' book.", 'en', 'possessives'),
        'a trailing apostrophe is a closing mark by position, and the right one'
    );

    same(
        'Ein ‚kleines‘ Zitat im „großen“.',
        typeset("Ein 'kleines' Zitat im \"großen\".", 'de', 'single marks are not apostrophes'),
        'a single mark around a word is still a quotation'
    );
});

test('the dashes a model reaches for become the ones the language uses', static function (): void {
    same(
        'Ein Wort – ohne Leerzeichen – Gedankenstrich.',
        typeset('Ein Wort—ohne Leerzeichen—Gedankenstrich.', 'de', 'em dashes in German'),
        'German sets a spaced en dash where English sets an em dash'
    );

    same(
        'He said “hello” — then left.',
        typeset('He said "hello" — then left.', 'English', 'an em dash in English'),
        'and English keeps its own'
    );

    same(
        'Von 1990–2000, S. 12–15, aber 2026-09-01 und T-34.',
        typeset('Von 1990-2000, S. 12-15, aber 2026-09-01 und T-34.', 'de', 'ranges'),
        'a span of numbers is a span; a date and a name with a hyphen in it are not'
    );

    same(
        'Erst – so – und dann – so.',
        typeset('Erst - so -- und dann --- so.', 'de', 'hyphens standing in for a dash'),
        'one, two or three hyphens between two words are all the same dash'
    );
});

test('the spacing a keyboard gets wrong', static function (): void {
    same(
        'Mehrere Leerzeichen und doppelte, hier.',
        typeset('Mehrere    Leerzeichen und  doppelte , hier.', 'de', 'runs of spaces'),
        'one space between two words, and none before a comma'
    );

    same(
        'Die Funktion (innen) bleibt.',
        typeset('Die Funktion ( innen ) bleibt.', 'de', 'spaces inside brackets'),
        'a round bracket closes on its content'
    );

    same(
        "Erster Absatz.\n\nZweiter Absatz.\n",
        typeset("Erster Absatz.   \n\n\n\n\nZweiter Absatz.\n", 'de', 'blank lines and trailing space'),
        'at most one blank line, and no whitespace hanging off the end'
    );

    // Two trailing spaces are a line break in Markdown, and deleting them
    // would join two lines the author deliberately separated.
    same(
        "Zeile eins  \nZeile zwei\n",
        typeset("Zeile eins  \nZeile zwei\n", 'de', 'a hard line break'),
        'exactly two trailing spaces survive, because they mean something'
    );

    // A table's padding is what makes its source readable and means nothing to
    // the renderer, so rewriting it would be a diff on every table for nothing.
    $table = "| Name    | Wert |\n| ------- | ---- |\n| „a“     | 1    |\n";
    same($table, typeset($table, 'de', 'a padded table'), 'a table row keeps its padding');
});

test('a paragraph cannot spoil the next one', static function (): void {
    // One unbalanced mark used to shift every mark after it for the rest of the
    // page. The depth is forgotten at every block boundary, so it costs its own
    // paragraph and nothing else.
    same(
        "Ein „offenes Zitat\n\nEin „neues“ hier.\n",
        typeset("Ein \"offenes Zitat\n\nEin \"neues\" hier.\n", 'de', 'an unbalanced quotation'),
        'the second paragraph starts from nothing open'
    );
});

test('more languages than the four that started it', static function (): void {
    foreach ([
        'Polski' => ['pl', '„Test”'],
        'Čeština' => ['cs', '„Test“'],
        'Magyar' => ['hu', '„Test”'],
        'Русский' => ['ru', '«Test»'],
        'Svenska' => ['sv', '”Test”'],
        'Ελληνικά' => ['el', '«Test»'],
    ] as $language => [$locale, $expected]) {
        same($locale, Typography::localeOf((string)$language), $language . ' is recognised');
        same(
            $expected,
            typeset('"Test"', (string)$language, 'a quotation in ' . $language),
            $language . ' gets the marks it uses'
        );
    }

    same(['„', '“'], Typography::marksOf('Deutsch'), 'the marks a screen can show before it offers to set them');
    same('de', Typography::styleOf('Čeština'), 'and languages that share an arrangement say so');
});

test('what an author escaped, and what a comment holds, are not prose', static function (): void {
    $md = "Ein \\\"Wort\\\" bleibt gerade.\n\n<!-- ein \"Kommentar\" mit ... -->\n\nAber \"dieses\" nicht.\n";
    $set = typeset($md, 'de', 'escapes and comments');

    ok(str_contains($set, '\\"Wort\\"'), 'a quotation mark escaped on purpose stays a straight one');
    ok(str_contains($set, '<!-- ein "Kommentar" mit ... -->'), 'an HTML comment comes back exactly as it went in');
    ok(str_contains($set, 'Aber „dieses“ nicht.'), 'and the prose around them is still set');
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
