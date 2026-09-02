<?php
declare(strict_types=1);

/**
 * The outlines both parsers have to agree on.
 *
 *     php tools/outline-fixtures.php        rewrite tools/outline-fixtures.json from the PHP parser
 *     node tools/outline-test.mjs           check the browser parser against that file
 *     php tests/run.php outline-fixtures    check the PHP parser still writes the same file
 *
 * `Structure::parse()` decides what an outline means when it is applied, and
 * `assets/js/core/outline.js` shows that meaning in the browser while the
 * outline is being typed. Two parsers of one grammar drift unless something
 * holds them together, and this is it: the inputs below cover every shape the
 * PHP parser was written to survive, the JSON file holds what it makes of them,
 * and each side is tested against that file rather than against the other -
 * so a change to the grammar fails one test, is written into the file on
 * purpose, and then fails the other until it is ported.
 */

/** @return array<string,string> name => outline */
function outlineFixtures(): array
{
    $long = str_repeat('a very long title that goes on ', 8);

    return [
        'the strict shape' => "# Vue 3 from the ground up\n\nA course for developers who know JavaScript.\n\nIt starts with the reactivity system and ends with a deployed application.\n\n1. Getting started\n   What the learner can do after it: install the toolchain and run a project.\n\n   The second paragraph of the description.\n   1. Installing Node and the CLI\n   2. The project layout\n2. Reactivity\n   1. ref and reactive\n   2. computed and watch\n",
        'bullets and parentheses' => "# Title\n\n- First chapter\n   - Page one\n   - Page two\n- Second chapter\n   1) Page three\n",
        'bold titles and a heading strip' => "# **The Book**\n\n1. **Chapter one**\n   1. __Page one__\n   2. ## Page two\n",
        'tags inline and on their own lines' => "# Book {{Vue, Reactivity}}\n{{Tooling}}\n\n1. Chapter {{Setup}}\n   {{Beginner, setup}}\n   1. Page {{Node, node}}\n   {{CLI}}\n   2. Another page\n",
        'a description that starts like a list' => "# Book\n\n1. Chapter\n   \\1. Install the toolchain before you start, because every example assumes it is present and working.\n   \\- Not a page either.\n   1. Real page\n",
        'a numbered sentence is prose, a numbered title is a page' => "# Book\n\n1. Chapter\n   1. Install the toolchain before you start, because every example assumes it is present.\n   2. Reactive state with ref\n   3. {$long}\n",
        'the whole answer in a fence' => "```markdown\n# Fenced\n\nDescription.\n\n1. Chapter\n   1. Page\n```\n",
        'windows line endings and tabs' => "# Book\r\n\r\n1. Chapter\r\n\t1. Tabbed page\r\n\t2. Second\r\n",
        'an empty chapter title keeps the previous chapter open' => "# Book\n\n1. Real chapter\n   1. Page A\n2. ****\n   2. Page B\n",
        'a page before any chapter' => "# Book\n\n   1. Orphan page\n1. Chapter\n   1. Page\n",
        'no title line at all' => "1. Chapter\n   1. Page\n",
        'chapter description with a blank line and a marker line' => "# Book\n\n1. Chapter\n   First paragraph.\n\n   Second paragraph {{Tag}}\n   1. Page\n",
        'a chapter with no pages, then one with' => "# Book\n\n1. Empty chapter\n2. Full chapter\n   1. Page\n",
        'cjk prose is counted by character' => "# 本\n\n1. 章\n   1. これは十分に長い文章であり、タイトルではなく説明文として読まれるべきです。本当にそうです。\n   2. ページ\n",
        'dashes and colons after a title with tags' => "# Book\n\n1. Chapter - {{A}}\n   1. Page: {{B, C}}\n",
        'only a title and prose' => "# Book\n\nJust a description, nothing else.\n",
        'nothing at all' => "\n\n",
    ];
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    require __DIR__ . '/../src/bootstrap.php';

    $out = [];
    foreach (outlineFixtures() as $name => $markdown) {
        $out[] = ['name' => $name, 'markdown' => $markdown, 'expected' => \CourseForge\Domain\Structure::parse($markdown)];
    }
    $json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    file_put_contents(__DIR__ . '/outline-fixtures.json', $json . "\n");
    echo count($out) . " fixtures written to tools/outline-fixtures.json\n";
}
