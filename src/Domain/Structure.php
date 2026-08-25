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
 */
final class Structure
{
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
        $descriptionParts = [];
        $chapters = [];
        $chapter = null;
        $seenChapter = false;
        $bulletChapters = false; // the model used "- Chapter" instead of "1. Chapter"
        $target = 'book';        // which entity a standalone {{...}} line belongs to

        foreach (explode("\n", $markdown) as $line) {
            $raw = rtrim($line);
            $trimmed = trim($raw);
            if ($trimmed === '') {
                continue;
            }

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

            // Chapter: an ordered item at indent 0 – or a top-level bullet when
            // the model refused to number chapters at all.
            if ($indent <= 1 && ($ordered || ($bullet && ($bulletChapters || !$seenChapter)))) {
                $bulletChapters = $bulletChapters || $bullet;
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
                $descriptionParts[] = $text;
            } elseif ($chapter !== null) {
                $chapter['description'] = trim($chapter['description'] . ' ' . $text);
                $target = 'chapter';
            }
        }

        if ($chapter !== null) {
            $chapters[] = $chapter;
        }

        return [
            'title' => $title,
            'description' => trim(implode(' ', $descriptionParts)),
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
        $out .= "\n" . $description . "\n\n";

        foreach ($chapters as $ci => $chapter) {
            $out .= ($ci + 1) . '. ' . $chapter['title'] . "\n";
            if (($chapter['tags'] ?? []) !== []) {
                $out .= '   ' . $marker((array)$chapter['tags']) . "\n";
            }
            if (trim((string)($chapter['description'] ?? '')) !== '') {
                $out .= '   ' . $chapter['description'] . "\n";
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
        return trim($text);
    }
}
