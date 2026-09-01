<?php
/**
 * Descriptions long enough to look like structure.
 *
 * The outline format was written when a book description was two or three
 * hundred characters: one short line that could not plausibly be mistaken for
 * anything else. At six hundred words it can be several paragraphs, and one of
 * them can start "3. Install the plugin" or "- Check the version" or "# Note"
 * entirely by accident. Indented three spaces under a chapter, that is
 * character for character the shape of a page entry - and pages are matched by
 * title, so a description that gets read as one adds a page nobody asked for
 * and loses its own opening line.
 *
 * Two mechanisms stop that, and these tests hold both:
 *
 *   - toMarkdown() escapes such a line, and clean() reads the escape back off,
 *     so anything CourseForge wrote itself round-trips exactly;
 *   - a list item longer than a title could sensibly be is read as prose, which
 *     is the net under outlines that arrive from a client or a model rather
 *     than from toMarkdown().
 *
 * The paragraph tests are here for a plainer reason: six hundred words with
 * every blank line flattened into a space is one unreadable block, and the
 * BookStack cover page is where it lands.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use CourseForge\Domain\Structure;

/** Six hundred-ish words in three paragraphs, the middle one starting with a number. */
function longDescription(): string
{
    $a = trim(str_repeat('The course begins with the ideas everything else rests on. ', 12));
    $b = trim('2026 is the year the plugin API changed, so ' . str_repeat('every example here is written against the current release. ', 10));
    $c = trim(str_repeat('By the end you will have shipped something that works. ', 12));

    return $a . "\n\n" . $b . "\n\n" . $c;
}

test('a multi-paragraph book description keeps its paragraphs', function () {
    $parsed = Structure::parse("# A Book\n\nFirst paragraph.\n\nSecond paragraph.\n\n1. Chapter One\n   Short description.\n   1. A Page\n");

    same("First paragraph.\n\nSecond paragraph.", $parsed['description'], 'the blank line survives as a blank line');
    same(1, count($parsed['chapters']), 'the chapter is still a chapter');
});

test('two lines of one paragraph are still one paragraph', function () {
    $parsed = Structure::parse("# A Book\n\nA description that the model\nhappened to wrap over two lines.\n\n1. Chapter One\n   1. A Page\n");

    same(
        'A description that the model happened to wrap over two lines.',
        $parsed['description'],
        'a wrapped line joins with a space, not a paragraph break'
    );
});

test('a multi-paragraph chapter description keeps its paragraphs and its pages', function () {
    $md = "# A Book\n\nBook description.\n\n1. Chapter One\n   First paragraph of the chapter.\n\n   Second paragraph of the chapter.\n   1. Page One\n   2. Page Two\n";
    $chapter = Structure::parse($md)['chapters'][0];

    same(
        "First paragraph of the chapter.\n\nSecond paragraph of the chapter.",
        $chapter['description'],
        'the chapter description keeps its break'
    );
    same(2, count($chapter['pages']), 'both pages are still pages');
});

test('a long description round-trips through toMarkdown unchanged', function () {
    $description = longDescription();
    $chapters = [[
        'title' => 'Chapter One',
        'description' => $description,
        'tags' => [],
        'pages' => [['title' => 'Page One', 'tags' => []]],
    ]];

    $parsed = Structure::parse(Structure::toMarkdown('A Book', $description, $chapters));

    same('A Book', $parsed['title'], 'the title survives');
    same($description, $parsed['description'], 'the book description survives verbatim');
    same($description, $parsed['chapters'][0]['description'], 'the chapter description survives verbatim');
    same(1, count($parsed['chapters'][0]['pages']), 'the chapter did not grow a page out of its own description');
    same('Page One', $parsed['chapters'][0]['pages'][0]['title'], 'and the page it does have is the right one');
});

test('a description paragraph that starts like a page entry does not become one', function () {
    // Written by toMarkdown, so the escape is what is being relied on here.
    $md = Structure::toMarkdown('A Book', 'Book description.', [[
        'title' => 'Chapter One',
        'description' => "Opening paragraph.\n\n3. Install the plugin before you start, because the examples assume it.",
        'tags' => [],
        'pages' => [['title' => 'Real Page', 'tags' => []]],
    ]]);

    ok(str_contains($md, '\\3. Install the plugin'), 'the marker is escaped on the way out');

    $chapter = Structure::parse($md)['chapters'][0];
    same(1, count($chapter['pages']), 'the escaped line stayed out of the page list');
    same('Real Page', $chapter['pages'][0]['title'], 'the only page is the real one');
    ok(
        str_contains($chapter['description'], '3. Install the plugin before you start'),
        'and the line came back as description, backslash removed'
    );
});

test('a book description paragraph that starts with a hash does not become a heading', function () {
    $md = Structure::toMarkdown('A Book', "Opening paragraph.\n\n# 1 of the three rules is this one.", [[
        'title' => 'Chapter One', 'description' => '', 'tags' => [], 'pages' => [['title' => 'Page One', 'tags' => []]],
    ]]);

    $parsed = Structure::parse($md);
    same('A Book', $parsed['title'], 'the real title is still the title');
    ok(str_contains($parsed['description'], '# 1 of the three rules'), 'the hash paragraph stayed in the description');
});

test('an unescaped list item too long to be a title is read as prose', function () {
    // The net for Markdown that did not come from toMarkdown: a client or a
    // model wrote this by hand, so there is no backslash to rely on.
    $sentence = 'Install the plugin before you start, because every example in this chapter assumes it is present '
        . 'and configured, and the first three pages will not work at all without it being there already today, '
        . 'which is the sort of thing worth saying once at the top rather than four times further down.';
    ok(mb_strlen($sentence) > 200, 'the fixture is longer than any title would be');

    $chapter = Structure::parse("# A Book\n\nBook description.\n\n1. Chapter One\n   Opening line.\n   3. {$sentence}\n   1. Real Page\n")['chapters'][0];

    same(1, count($chapter['pages']), 'the long numbered line is not a page');
    same('Real Page', $chapter['pages'][0]['title'], 'the short one still is');
    ok(str_contains($chapter['description'], $sentence), 'the long line landed in the description');
});

test('a short numbered line is still a page, which is the whole point of the length test', function () {
    $chapter = Structure::parse("# A Book\n\nBook description.\n\n1. Chapter One\n   Opening line.\n   1. Reactive state with ref\n   2. Computed values\n")['chapters'][0];

    same(2, count($chapter['pages']), 'both ordinary page titles are pages');
    same('Reactive state with ref', $chapter['pages'][0]['title'], 'and they are unchanged');
});

test('a long chapter title line at indent zero does not swallow the chapter list', function () {
    $prose = 'This paragraph is the second one of the book description and it begins with a number because the model '
        . 'wrote the year first, which is a thing that models do when the topic has a date attached to it at all.';
    $parsed = Structure::parse("# A Book\n\nFirst paragraph.\n\n2. {$prose}\n\n1. Chapter One\n   1. A Page\n");

    same(1, count($parsed['chapters']), 'the prose paragraph did not become a chapter');
    same('Chapter One', $parsed['chapters'][0]['title'], 'the real chapter is the one that is there');
    ok(str_contains($parsed['description'], $prose), 'the paragraph stayed in the book description');
});

test('a freehand six-hundred-word outline keeps its shape and all of its words', function () {
    // The case the browser found and the length ceiling alone did not catch:
    // outline Markdown written the way a model writes it - no escaping, because
    // nothing escaped it - where one paragraph of each description happens to
    // open with a numbered step, a heading hash or a dash. Each of those is
    // around a hundred and ten characters, well under TITLE_MAX_CHARS, and each
    // was read as structure: the outline grew a chapter and three pages nobody
    // asked for, and the book description lost two thirds of itself to them.
    $sentences = static fn(string $seed, int $n): string => implode(' ', array_map(
        static fn(int $i): string => $seed . ' sentence ' . $i
            . ' carries real weight and says something concrete about the material a learner has to absorb here.',
        range(1, $n)
    ));

    $bookDesc = implode("\n\n", [
        $sentences('Opening', 14),
        '3. Install the toolchain before you start, because every example assumes it is present and configured.',
        $sentences('Middle', 14),
        $sentences('Closing', 14),
    ]);

    $chapterDesc = implode("\n\n", [
        $sentences('Chapter opening', 14),
        '- 1 is the chapter where the tooling stops being incidental and starts being the subject itself for good.',
        $sentences('Chapter closing', 14),
    ]);

    $indent = static fn(string $text): string => implode("\n", array_map(
        static fn(string $line): string => trim($line) === '' ? '' : '   ' . $line,
        explode("\n", $text)
    ));

    $md = "# WordPress 7.2 for PHP Developers\n\n" . $bookDesc . "\n\n";
    for ($c = 1; $c <= 3; $c++) {
        $md .= $c . ". Chapter " . $c . ": Blocks and the editor\n" . $indent($chapterDesc) . "\n";
        for ($p = 1; $p <= 4; $p++) {
            $md .= '   ' . $p . '. Page ' . $c . '.' . $p . " - a concrete teachable title\n";
        }
        $md .= "\n";
    }

    ok(str_word_count($bookDesc) > 600, 'the book description really is past six hundred words');
    ok(str_word_count($chapterDesc) > 400, 'and the chapter description is a long one too');

    $parsed = Structure::parse($md);

    same(3, count($parsed['chapters']), 'three chapters, not four');
    same(
        12,
        array_sum(array_map(static fn(array $c): int => count($c['pages']), $parsed['chapters'])),
        'twelve pages, not fifteen'
    );

    ok(
        str_contains($parsed['description'], 'Install the toolchain before you start'),
        'the numbered paragraph stayed in the book description'
    );
    ok(str_contains($parsed['description'], 'Closing sentence 14'), 'and so did everything after it');
    same(4, substr_count($parsed['description'], "\n\n") + 1, 'with all four of its paragraphs intact');

    ok(
        str_contains($parsed['chapters'][0]['description'], 'is the chapter where the tooling'),
        'and the dashed paragraph stayed in the chapter description'
    );
    same(
        ['Page 1.1 - a concrete teachable title', 'Page 1.2 - a concrete teachable title',
         'Page 1.3 - a concrete teachable title', 'Page 1.4 - a concrete teachable title'],
        array_map(static fn(array $p): string => $p['title'], $parsed['chapters'][0]['pages']),
        'leaving exactly the four real pages'
    );
});

test('an ordinary short title is never mistaken for prose', function () {
    // The other direction, which is the one that would break every existing
    // course: the prose test must not demote a real title.
    $parsed = Structure::parse(
        "# A Book\n\nDescription.\n\n1. Reactive state\n   1. Reactive state with ref and reactive\n"
        . "   2. Past tense of regular verbs\n   3. Working with files\n   4. What is new in 7.2?\n"
    );

    same(4, count($parsed['chapters'][0]['pages']), 'all four titles are still pages');
    same('What is new in 7.2?', $parsed['chapters'][0]['pages'][3]['title'], 'a short question mark is a title');
});

test('the description a six-hundred-word contract produces is stored whole', function () {
    // Nothing in CourseForge truncates it: book_desc and chapters.description
    // are TEXT. What the model wrote is what comes back.
    $description = longDescription();
    ok(str_word_count($description) > 300, 'the fixture is long enough to be worth the test');

    $parsed = Structure::parse(Structure::toMarkdown('A Book', $description, [
        ['title' => 'Chapter One', 'description' => $description, 'tags' => [], 'pages' => []],
    ]));

    same($description, $parsed['description'], 'no clamp on the way through the parser');
});
