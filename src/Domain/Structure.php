<?php
declare(strict_types=1);

namespace CourseForge\Domain;

use CourseForge\Support\Text;

/**
 * The course outline: strict Markdown ⇄ data. No AI involved.
 *
 * The AI is asked for exactly one shape (one `# ` title, chapters as a
 * top-level ordered list, pages as a nested one) but real models drift, so the
 * parser also accepts bullets, `1)` markers, bold titles and stray blank lines.
 *
 * Tag markers – `{{Tag1, Tag2}}` behind a title or on the line below it – are
 * extracted here and turned into real tag links by Projects::applyStructure().
 *
 * Descriptions are prose of some length – six hundred words rather than the
 * few hundred characters this format was first written for – and that changes
 * what the parser has to survive. A paragraph is no longer a single short line
 * that could not plausibly look like anything else: it can run to several
 * paragraphs, and one of them can begin "1. " or "- " or "# " by accident. At
 * three spaces of indentation that is exactly the shape of a page entry. Two
 * things keep the two apart, and they work from both ends:
 *
 *   - **writing escapes.** toMarkdown() prefixes a backslash to any
 *     description line that starts like a list item or a heading, which is
 *     ordinary Markdown and is read back off again by clean().
 *   - **reading measures.** A list item longer than PAGE_TITLE_MAX is prose
 *     that happens to start with a marker, not a title, and is treated as
 *     description. That is the safety net for outlines that arrive from
 *     somewhere other than toMarkdown() – a client that assembled the Markdown
 *     itself, or a model writing freehand.
 *
 * Paragraph breaks survive the round trip too. A blank line between two
 * paragraphs of a description is remembered as a blank line rather than
 * flattened to a space, because six hundred words in one unbroken block is not
 * something anybody wants to read on a BookStack cover page.
 */
final class Structure
{
    /**
     * Longer than this and a list item is not a title.
     *
     * Chapter and page titles are "short, concrete, teachable" by contract and
     * a handful of words in practice; two hundred characters is far past any
     * real one and far short of a paragraph. The number only has to separate
     * those two populations, and there is a wide empty gap between them.
     */
    private const PAGE_TITLE_MAX = 200;
    /**
     * `title` is empty when the outline carries no `# ` line at all.
     *
     * That is a different thing from a book called "Untitled course", and the
     * difference matters to whoever is storing the result: an outline that says
     * nothing about the title must leave the stored one alone rather than
     * stamp a placeholder over it.
     *
     * @return array{
     *   title:string, description:string, tags:string[],
     *   chapters:array<int,array{title:string,description:string,tags:string[],
     *                            pages:array<int,array{title:string,tags:string[]}>}>
     * }
     */
    public static function parse(string $markdown): array
    {
        $markdown = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '   '], $markdown);
        // A model that wraps its whole answer in a fence must not break parsing.
        $markdown = preg_replace('/^\s*```[a-zA-Z]*\s*\n(.*)\n```\s*$/s', '$1', $markdown) ?? $markdown;

        $title = '';
        $bookTags = [];
        $description = '';
        $chapters = [];
        $chapter = null;
        $seenChapter = false;
        $bulletChapters = false; // the model used "- Chapter" instead of "1. Chapter"
        $target = 'book';        // which entity a standalone {{...}} line belongs to
        $blank = false;          // a blank line is pending, so the next prose starts a paragraph

        foreach (explode("\n", $markdown) as $line) {
            $raw = rtrim($line);
            $trimmed = trim($raw);
            if ($trimmed === '') {
                $blank = true;
                continue;
            }

            // Read once and cleared here rather than at every `continue`: only
            // the prose branch cares, and it is the branch that runs last.
            $break = $blank;
            $blank = false;

            // A tag marker on its own line belongs to the entity written above it.
            if (preg_match('/^(?:[-*+]\s+|\d+[.)]\s+)?\{\{([^{}]*)\}\}$/u', $trimmed, $m) === 1) {
                self::attachTags($bookTags, $chapter, $target, Text::splitList($m[1]));
                continue;
            }

            // Book title.
            if ($title === '' && preg_match('/^#\s+(.*)$/', $trimmed, $m) === 1) {
                [$title, $tags] = self::extractTags(self::clean($m[1]));
                $bookTags = Text::mergeUnique($bookTags, $tags);
                $target = 'book';
                continue;
            }

            $indent = strlen($raw) - strlen(ltrim($raw, ' '));
            $ordered = preg_match('/^(\d+)[.)]\s+(.*)$/u', $trimmed, $om) === 1;
            $bullet = !$ordered && preg_match('/^[-*+]\s+(.*)$/u', $trimmed, $bm) === 1;
            [$itemText, $itemTags] = self::extractTags(
                self::clean($ordered ? $om[2] : ($bullet ? $bm[1] : ''))
            );

            // A marker in front of a paragraph is not a title. Dropping the
            // item shapes here sends the line to the prose branch below with
            // its marker still on it, which is what it is: description text
            // that happens to start that way.
            if (($ordered || $bullet) && mb_strlen($itemText) > self::PAGE_TITLE_MAX) {
                $ordered = false;
                $bullet = false;
            }

            // Chapter: an ordered item at indent 0 – or a top-level bullet when
            // the model refused to number chapters at all.
            if ($indent <= 1 && ($ordered || ($bullet && ($bulletChapters || !$seenChapter)))) {
                $bulletChapters = $bulletChapters || $bullet;

                // A chapter line whose title cleans away to nothing - "1. ****"
                // is the one that turns up, because clean() strips the bold
                // wrapper and leaves the empty string - used to open a chapter
                // anyway. Its pages then accumulated under it, and the filter
                // at the bottom of this method dropped the untitled chapter
                // *with every page nested under it*, silently. Applying such an
                // outline deletes those pages and the text on them.
                //
                // Keeping the previous chapter open instead means the pages
                // land somewhere real. That is the same choice the page branch
                // below already makes when a page arrives before any chapter
                // has been opened: attach it to something rather than lose it.
                if ($itemText === '') {
                    $target = 'chapter';
                    continue;
                }

                if ($chapter !== null) {
                    $chapters[] = $chapter;
                }
                $chapter = ['title' => $itemText, 'description' => '', 'tags' => $itemTags, 'pages' => []];
                $seenChapter = true;
                $target = 'chapter';
                continue;
            }

            // Page: any list item nested below a chapter.
            if (($ordered || $bullet) && $indent >= 2) {
                $chapter ??= ['title' => 'Chapter 1', 'description' => '', 'tags' => [], 'pages' => []];
                $seenChapter = true;
                if ($itemText !== '') {
                    $chapter['pages'][] = ['title' => $itemText, 'tags' => $itemTags];
                    $target = 'page';
                }
                continue;
            }

            // Plain prose: the book description before the first chapter,
            // the chapter description afterwards.
            [$text, $proseTags] = self::extractTags(self::clean($trimmed));
            if ($proseTags !== []) {
                self::attachTags($bookTags, $chapter, $target, $proseTags);
            }
            if ($text === '') {
                continue;
            }
            if (!$seenChapter) {
                $description = self::join($description, $text, $break);
            } elseif ($chapter !== null) {
                $chapter['description'] = self::join($chapter['description'], $text, $break);
                $target = 'chapter';
            }
        }

        if ($chapter !== null) {
            $chapters[] = $chapter;
        }

        return [
            'title' => $title,
            'description' => trim($description),
            'tags' => $bookTags,
            'chapters' => array_values(array_filter($chapters, static fn(array $c): bool => $c['title'] !== '')),
        ];
    }

    /** Re-renders the data back into the strict Markdown format, tag markers included. */
    public static function toMarkdown(string $title, string $description, array $chapters, array $bookTags = []): string
    {
        $marker = static fn(array $tags): string => $tags === [] ? '' : '{{' . implode(', ', $tags) . '}}';

        $out = '# ' . $title . "\n";
        if ($bookTags !== []) {
            $out .= $marker($bookTags) . "\n";
        }
        $out .= "\n" . self::describe($description, '') . "\n\n";

        foreach ($chapters as $ci => $chapter) {
            $out .= ($ci + 1) . '. ' . $chapter['title'] . "\n";
            if (($chapter['tags'] ?? []) !== []) {
                $out .= '   ' . $marker((array)$chapter['tags']) . "\n";
            }
            if (trim((string)($chapter['description'] ?? '')) !== '') {
                $out .= self::describe((string)$chapter['description'], '   ') . "\n";
            }
            foreach ((array)($chapter['pages'] ?? []) as $pi => $page) {
                $pageTitle = is_array($page) ? (string)$page['title'] : (string)$page;
                $pageTags = is_array($page) ? (array)($page['tags'] ?? []) : [];
                $out .= '   ' . ($pi + 1) . '. ' . $pageTitle . ($pageTags === [] ? '' : ' ' . $marker($pageTags)) . "\n";
            }
            $out .= "\n";
        }
        return trim($out) . "\n";
    }

    /* ------------------------------------------------------------- helpers */

    /**
     * Adds one prose line to a description, keeping paragraphs apart.
     *
     * `$break` says a blank line stood between this text and what came before,
     * which is the only signal the format carries for "new paragraph" – and
     * the one thing worth preserving when a description is long enough to have
     * paragraphs at all.
     */
    private static function join(string $carried, string $text, bool $break): string
    {
        if ($carried === '') {
            return $text;
        }
        return $carried . ($break ? "\n\n" : ' ') . $text;
    }

    /**
     * A description, laid out so that parse() reads back exactly what went in.
     *
     * Every line carries the indentation its position requires – none for the
     * book, three spaces under a chapter – and any line that would otherwise be
     * read as a list item or a heading is escaped. Blank lines are left blank
     * rather than indented, because trailing whitespace on an empty line is
     * invisible, pointless, and something editors strip.
     */
    private static function describe(string $text, string $indent): string
    {
        $lines = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", trim($text))) as $line) {
            $line = trim($line);
            $lines[] = $line === '' ? '' : $indent . self::escapeMarker($line);
        }
        return implode("\n", $lines);
    }

    /**
     * Puts a backslash in front of a leading list marker or heading hash.
     *
     * Ordinary Markdown escaping, and the exact inverse of what clean() undoes.
     * Without it a description paragraph beginning "3. Install the plugin" is
     * indistinguishable from a page called "Install the plugin" – and since
     * pages are matched by title, the outline would grow a page nobody asked
     * for and the description would lose its opening.
     */
    private static function escapeMarker(string $line): string
    {
        return preg_replace('/^(\d+[.)]|[-*+]|#+)(\s)/u', '\\\\$1$2', $line) ?? $line;
    }

    /**
     * @param string[] $bookTags
     * @param array{title:string,description:string,tags:string[],pages:array<int,array{title:string,tags:string[]}>}|null $chapter
     * @param string[] $names
     */
    private static function attachTags(array &$bookTags, ?array &$chapter, string $target, array $names): void
    {
        if ($names === []) {
            return;
        }
        if ($target === 'page' && $chapter !== null && $chapter['pages'] !== []) {
            $last = count($chapter['pages']) - 1;
            $chapter['pages'][$last]['tags'] = Text::mergeUnique($chapter['pages'][$last]['tags'], $names);
            return;
        }
        if ($target === 'chapter' && $chapter !== null) {
            $chapter['tags'] = Text::mergeUnique($chapter['tags'], $names);
            return;
        }
        $bookTags = Text::mergeUnique($bookTags, $names);
    }

    /** @return array{0:string,1:string[]} [text without the marker, tag names] */
    private static function extractTags(string $text): array
    {
        if (preg_match('/\{\{([^{}]*)\}\}\s*$/u', $text, $m) !== 1) {
            return [$text, []];
        }
        $tags = Text::splitList($m[1]);
        $pos = strrpos($text, $m[0]); // may legitimately be 0
        if ($pos !== false) {
            $text = substr($text, 0, $pos);
        }
        return [trim(rtrim(trim($text), " -–—:")), $tags];
    }

    /**
     * A title in the only shape the outline format can carry unchanged.
     *
     * The outline is written by toMarkdown() and read back by parse(), and the
     * two are not inverses for every string: parse() strips `**bold**`,
     * `__underscored__`, a leading `#`, and a trailing `{{tag}}` marker, and a
     * title containing a newline stops being one line at all - it is read back
     * as chapters and pages that do not exist.
     *
     * Since applyStructure matches existing pages BY TITLE, any such title
     * orphans its own content on the next apply: the page is seen as removed
     * and re-added, and the text goes with it. Storing the canonical form
     * instead makes writing and reading agree by construction.
     *
     * Titles that arrive through an outline are already canonical - they came
     * out of parse(). This is for the doors that bypass it, and it makes them
     * behave the way those already did.
     */
    public static function canonicalTitle(string $raw): string
    {
        // Every kind of line break, and every run of whitespace, becomes one
        // space. A line-based format cannot hold a title with a newline in it,
        // and silently inventing structure is worse than flattening.
        $text = preg_replace('/\s+/u', ' ', $raw) ?? $raw;

        // The same order parse() uses, so the answer is what parse() would give.
        [$text] = self::extractTags(self::clean($text));

        return $text;
    }

    private static function clean(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^\*\*(.*)\*\*$/u', '$1', $text) ?? $text;
        $text = preg_replace('/^__(.*)__$/u', '$1', $text) ?? $text;
        $text = preg_replace('/^#+\s*/u', '', $text) ?? $text;
        // The other half of escapeMarker(). Done after the heading strip so
        // that "\## Something" loses the backslash and keeps its hashes, which
        // is what escaping them was for.
        $text = preg_replace('/^\\\\(?=(?:\d+[.)]|[-*+]|#+)\s)/u', '', $text) ?? $text;
        return trim($text);
    }
}
