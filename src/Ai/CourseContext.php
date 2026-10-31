<?php
declare(strict_types=1);

namespace CourseForge\Ai;

use CourseForge\Support\Text;

/**
 * What one page is told about the rest of the course.
 *
 * Two things, and they are deliberately different in kind.
 *
 * **The map** is the plan: every chapter and every page, each page with the
 * scope the outline gave it - what that page and no other page teaches. It is
 * the same text for every page of the course, which is the point twice over:
 * a page can see which page owns a concept before it explains it, and a
 * provider that caches prompts reads the map once at full price and every page
 * after that at a tenth of it. It replaces the outline Markdown the page prompt
 * used to carry, which spent six hundred words per chapter describing chapters
 * and said nothing at all about what was on any page.
 *
 * **The digest** is what actually happened: the headings of pages that have
 * already been written next to this one. A model told what a page owns still
 * drifts - the page on `ref` grows a section on `watch` - and the headings are
 * where that shows. They are read from the stored text, so they cost nothing to
 * produce, and only the neighbours in the same chapter are sent, which is where
 * overlap happens and what stays short.
 */
final class CourseContext
{
    /** More headings than this from one neighbour is a table of contents, not a reminder. */
    private const DIGEST_HEADINGS = 12;

    /** How many written neighbours a page is shown. */
    private const DIGEST_PAGES = 8;

    /**
     * The numbered map of the whole course.
     *
     *     1. Getting started
     *        1.1 Installing Node and the CLI - node, npm, the create-vue CLI
     *        1.2 The project layout
     *
     * @param array<int,array<string,mixed>> $rows Pages::outline() rows, in reading order
     * @param array<int,array<string,mixed>> $chapters Chapters::ordered() rows, so an empty chapter still appears
     */
    public static function map(array $rows, array $chapters = []): string
    {
        $byChapter = [];
        foreach ($rows as $row) {
            $byChapter[(int)$row['chapter_id']][] = $row;
        }

        if ($chapters === []) {
            foreach ($rows as $row) {
                $chapters[(int)$row['chapter_id']] ??= [
                    'id' => (int)$row['chapter_id'],
                    'idx' => (int)$row['chapter_idx'],
                    'title' => (string)$row['chapter_title'],
                ];
            }
            $chapters = array_values($chapters);
        }

        // Numbered by the stored positions, the same numbers ref() gives a
        // page, so "see 3.2" in a brief points at the line the map labels 3.2.
        $lines = [];
        foreach ($chapters as $chapter) {
            $lines[] = ((int)$chapter['idx'] + 1) . '. ' . Text::tidy((string)$chapter['title']);
            foreach ($byChapter[(int)$chapter['id']] ?? [] as $page) {
                $scope = Text::tidy((string)($page['scope'] ?? ''));
                $lines[] = '   ' . self::ref($page) . ' ' . Text::tidy((string)$page['title'])
                    . ($scope !== '' ? ' - ' . $scope : '');
            }
        }
        return implode("\n", $lines);
    }

    /** "3.2": where a page sits on the map. @param array<string,mixed> $row */
    public static function ref(array $row): string
    {
        return ((int)$row['chapter_idx'] + 1) . '.' . ((int)$row['idx'] + 1);
    }

    /** A page as one line: its place, its title and, when it has one, its scope. @param array<string,mixed> $row */
    public static function line(array $row): string
    {
        $scope = Text::tidy((string)($row['scope'] ?? ''));
        return self::ref($row) . ' "' . Text::tidy((string)$row['title']) . '"' . ($scope !== '' ? ' - ' . $scope : '');
    }

    /**
     * The section headings of one written page, as a single line.
     *
     * `##` and `###` only: a page's own structure, which is what another page
     * needs to see in order not to repeat it. Fenced code is skipped, because a
     * shell comment or a Markdown sample inside a fence starts with `#` too.
     */
    public static function digest(string $markdown): string
    {
        $headings = [];
        $fence = null;
        foreach (preg_split('/\R/u', $markdown) ?: [] as $line) {
            if (preg_match('/^\s{0,3}(`{3,}|~{3,})/u', $line, $m) === 1) {
                if ($fence === null) {
                    $fence = $m[1][0];
                } elseif ($m[1][0] === $fence) {
                    $fence = null;
                }
                continue;
            }
            if ($fence !== null || preg_match('/^\s{0,3}#{2,3}\s+(.+?)\s*#*\s*$/u', $line, $m) !== 1) {
                continue;
            }
            $heading = Text::tidy(str_replace(['**', '__', '`'], '', $m[1]));
            if ($heading !== '') {
                $headings[] = $heading;
            }
            if (count($headings) >= self::DIGEST_HEADINGS) {
                break;
            }
        }
        return implode('; ', $headings);
    }

    /**
     * The written pages of a page's chapter, nearest first, each with its
     * headings - or an empty string when none has been written yet.
     *
     * @param array<int,array<string,mixed>> $rows Pages::outline() rows
     * @param callable(int[]):array<int,string> $contents page ids => Markdown
     */
    public static function writtenNeighbours(array $rows, array $page, callable $contents): string
    {
        $pageId = (int)$page['id'];
        $chapterId = (int)$page['chapter_id'];
        $at = (int)$page['idx'];

        $neighbours = array_values(array_filter(
            $rows,
            static fn(array $row): bool => (int)$row['chapter_id'] === $chapterId
                && (int)$row['id'] !== $pageId
                && ($row['written'] ?? false) === true
        ));
        if ($neighbours === []) {
            return '';
        }

        // Nearest first, so the cap drops the pages furthest away - and then
        // back into reading order, which is the order they make sense in.
        usort($neighbours, static fn(array $a, array $b): int => abs((int)$a['idx'] - $at) <=> abs((int)$b['idx'] - $at));
        $neighbours = array_slice($neighbours, 0, self::DIGEST_PAGES);
        usort($neighbours, static fn(array $a, array $b): int => (int)$a['idx'] <=> (int)$b['idx']);

        $text = $contents(array_map(static fn(array $row): int => (int)$row['id'], $neighbours));

        $lines = [];
        foreach ($neighbours as $row) {
            $digest = self::digest($text[(int)$row['id']] ?? '');
            $lines[] = '- ' . self::ref($row) . ' "' . Text::tidy((string)$row['title']) . '"'
                . ($digest !== '' ? ': ' . $digest : '');
        }
        return implode("\n", $lines);
    }

    /**
     * A short, stable name for one shared context.
     *
     * Sent where a provider routes or accounts its prompt cache by key, so
     * every page of a course lands where the course context is already warm.
     * Derived from the text rather than from a database id: two installations
     * share nothing, and a course whose map changes gets a new key exactly when
     * its old cache entries stop being any use.
     *
     * @param string[] $context
     */
    public static function key(array $context): string
    {
        $first = (string)($context[0] ?? '');
        return $first === '' ? '' : 'cf-' . substr(hash('sha256', $first), 0, 24);
    }

    /**
     * The name of one exact segment, for a client that keeps what it was sent.
     *
     * Same text, same id, whichever page it was handed out with; one changed
     * character - a scope edited, a page added - and the id is new.
     */
    public static function id(string $segment): string
    {
        return $segment === '' ? '' : 'ctx-' . substr(hash('sha256', $segment), 0, 16);
    }
}
