<?php
declare(strict_types=1);

namespace CourseForge\Domain;

use CourseForge\Support\Markdown;

/**
 * Turns the plain-text cross references the AI leaves behind into real links.
 *
 * While a page is written the model only writes a marker:
 *
 *     ... handled by the reactive system (🔗 Reactive state with ref) ...
 *
 * After the course has been published, every chapter and page has a BookStack
 * URL, and this class rewrites the markers – programmatically, with no second
 * AI call:
 *
 *     ... handled by the reactive system ([🔗 Reactive state with ref](https://…)) ...
 *
 * The stored page content always keeps the raw marker. Resolution happens on
 * the way out, which means it is idempotent, survives a re-generation and never
 * corrupts the source text if a target is renamed or removed.
 *
 * Three outcomes per marker:
 *   resolved  the target exists and is published  → real link
 *   pending   the target exists but is not pushed → marker kept, resolves later
 *   dropped   no such chapter or page, or it is this very page → plain text
 */
final class AutoLinker
{
    /** The marker as the prompt contract defines it: ( 🔗 Title ). */
    private const MARKER = '/\(\s*\x{1F517}\x{FE0F}?\s*([^()\r\n]{1,200}?)\s*\)/u';

    /** Markers that will actually be rewritten – anything inside code is ignored. */
    public static function countMarkers(string $markdown): int
    {
        if (!self::hasMarkers($markdown)) {
            return 0;
        }
        $count = 0;
        foreach (Markdown::segments($markdown) as $segment) {
            if (!$segment['code']) {
                $count += preg_match_all(self::MARKER, $segment['text']) ?: 0;
            }
        }
        return $count;
    }

    /** Cheap pre-check; may still be true when every marker sits inside code. */
    public static function hasMarkers(string $markdown): bool
    {
        return preg_match(self::MARKER, $markdown) === 1;
    }

    /**
     * Rewrites every marker outside code blocks.
     *
     * @return array{content:string,resolved:int,pending:int,dropped:int,unknown:string[]}
     */
    public static function apply(string $markdown, LinkIndex $index, int $selfPageId = 0): array
    {
        $result = ['content' => $markdown, 'resolved' => 0, 'pending' => 0, 'dropped' => 0, 'unknown' => []];
        if ($markdown === '' || !self::hasMarkers($markdown)) {
            return $result;
        }

        $resolved = 0;
        $pending = 0;
        $dropped = 0;
        $unknown = [];

        $result['content'] = Markdown::mapProse($markdown, static function (string $prose) use (
            $index, $selfPageId, &$resolved, &$pending, &$dropped, &$unknown
        ): string {
            return (string)preg_replace_callback(self::MARKER, static function (array $m) use (
                $index, $selfPageId, &$resolved, &$pending, &$dropped, &$unknown
            ): string {
                $wanted = trim($m[1]);
                if ($wanted === '') {
                    $dropped++;
                    return '';
                }

                $target = $index->lookup($wanted);

                if ($target === null) {
                    $dropped++;
                    $unknown[] = $wanted;
                    return '(' . $wanted . ')';
                }
                if ($target['type'] === 'page' && $target['id'] === $selfPageId) {
                    $dropped++; // a page linking to itself helps nobody
                    return '(' . $target['title'] . ')';
                }
                if ($target['url'] === '') {
                    $pending++; // not published yet – keep the marker for the next push
                    return $m[0];
                }

                $resolved++;
                return '([🔗 ' . self::escapeLabel($target['title']) . '](' . $target['url'] . '))';
            }, $prose);
        });

        // Every marker sat inside code: hand back the byte-identical original so
        // the push hash cannot drift over pure line-ending normalisation.
        if ($resolved === 0 && $pending === 0 && $dropped === 0) {
            $result['content'] = $markdown;
            return $result;
        }

        $result['resolved'] = $resolved;
        $result['pending'] = $pending;
        $result['dropped'] = $dropped;
        $result['unknown'] = array_values(array_unique($unknown));
        return $result;
    }

    /** Convenience wrapper for the places that only need the text. */
    public static function render(string $markdown, LinkIndex $index, int $selfPageId = 0): string
    {
        return self::apply($markdown, $index, $selfPageId)['content'];
    }

    /** Square brackets inside a Markdown link label would end it early. */
    private static function escapeLabel(string $title): string
    {
        return str_replace(['[', ']'], ['\\[', '\\]'], $title);
    }
}
