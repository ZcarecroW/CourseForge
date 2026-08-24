<?php
declare(strict_types=1);

namespace CourseForge\Domain;

use CourseForge\Support\Db;
use CourseForge\Support\Text;

/**
 * Everything in one course that an auto link may point at.
 *
 * Built straight from the database, so a target only carries a URL once it has
 * actually been published to BookStack. Titles are matched leniently – exact
 * first, then a normalised key, then a unique prefix, then the closest match
 * above a similarity floor – because a model retyping a title is the common
 * case, not the exception.
 */
final class LinkIndex
{
    /** Below this, a "closest match" is a guess and gets rejected. */
    private const SIMILARITY_FLOOR = 0.86;

    /**
     * How much of a title a marker has to be before a prefix match is trusted.
     *
     * Four characters is short enough for a real abbreviation and long enough
     * that a stray initial, or a marker somebody left half-typed, does not
     * silently become a link to the first page that happens to start with it.
     */
    private const PREFIX_MIN_LENGTH = 4;

    /**
     * How close in length the two titles have to be.
     *
     * "Reactive state" against "Reactive state with ref" is 0.61 and should
     * match; "A" against "Advanced Vue" is 0.08 and should not. Half is a
     * comfortable line between an abbreviation and a coincidence.
     */
    private const PREFIX_MIN_COVERAGE = 0.5;

    /** @param array<int,array{type:string,id:int,title:string,url:string,key:string}> $entries */
    private function __construct(
        private readonly array $entries,
        /** @var array<string,int> normalised title → index into $entries */
        private readonly array $byKey,
    ) {
    }

    public static function forProject(int $projectId): self
    {
        $entries = [];

        // Chapters first: when a chapter and a page normalise to the same key,
        // the page (added later) wins, which is what a reader expects.
        foreach (Db::rows('SELECT id, title, bs_url FROM chapters WHERE project_id = ? ORDER BY idx', [$projectId]) as $row) {
            $entries[] = [
                'type' => 'chapter',
                'id' => (int)$row['id'],
                'title' => (string)$row['title'],
                'url' => (string)$row['bs_url'],
                'key' => Text::key((string)$row['title']),
            ];
        }
        foreach (Db::rows('SELECT id, title, bs_url FROM pages WHERE project_id = ? ORDER BY chapter_id, idx', [$projectId]) as $row) {
            $entries[] = [
                'type' => 'page',
                'id' => (int)$row['id'],
                'title' => (string)$row['title'],
                'url' => (string)$row['bs_url'],
                'key' => Text::key((string)$row['title']),
            ];
        }

        $byKey = [];
        foreach ($entries as $i => $entry) {
            if ($entry['key'] !== '') {
                $byKey[$entry['key']] = $i;
            }
        }
        return new self($entries, $byKey);
    }

    /** @param array<int,array{type:string,id:int,title:string,url:string}> $entries Test seam. */
    public static function fromEntries(array $entries): self
    {
        $normalised = [];
        $byKey = [];
        foreach ($entries as $entry) {
            $entry['key'] = Text::key((string)$entry['title']);
            $normalised[] = $entry;
            if ($entry['key'] !== '') {
                $byKey[$entry['key']] = count($normalised) - 1;
            }
        }
        return new self($normalised, $byKey);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * Finds the item a marker means.
     *
     * @return array{type:string,id:int,title:string,url:string}|null
     */
    public function lookup(string $title): ?array
    {
        $key = Text::key($title);
        if ($key === '') {
            return null;
        }
        if (isset($this->byKey[$key])) {
            return $this->entries[$this->byKey[$key]];
        }

        // A unique prefix is unambiguous enough: "Reactive state" → "Reactive
        // state with ref". Only if it is actually a prefix of something,
        // though, and not merely its first letter.
        //
        // Without a floor this was the loosest rule in the file, and the
        // failure was silent in the worst way: in a course with one chapter
        // beginning "A", the marker "A" resolved to "Advanced Vue", was
        // published as a real link, and was counted as resolved rather than
        // dropped. Nobody reading the report would know a guess had been made.
        //
        // So the query must be long enough to be a title rather than an
        // initial, and the two keys must be close enough in length that one is
        // plausibly a shortening of the other. The similarity rule below is
        // floored at 0.86; this one had no floor at all.
        if (mb_strlen($key) >= self::PREFIX_MIN_LENGTH) {
            $prefixHits = [];
            foreach ($this->entries as $i => $entry) {
                if ($entry['key'] === '') {
                    continue;
                }
                if (!str_starts_with($entry['key'], $key) && !str_starts_with($key, $entry['key'])) {
                    continue;
                }
                $shorter = min(mb_strlen($key), mb_strlen($entry['key']));
                $longer = max(mb_strlen($key), mb_strlen($entry['key']));
                if ($longer > 0 && $shorter / $longer >= self::PREFIX_MIN_COVERAGE) {
                    $prefixHits[] = $i;
                }
            }
            if (count($prefixHits) === 1) {
                return $this->entries[$prefixHits[0]];
            }
        }

        $best = null;
        $bestScore = self::SIMILARITY_FLOOR;
        foreach ($this->entries as $entry) {
            $score = Text::similarity($key, $entry['key']);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $entry;
            }
        }
        return $best;
    }
}
