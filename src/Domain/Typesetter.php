<?php
declare(strict_types=1);

namespace CourseForge\Domain;

use CourseForge\Ai\Completion;
use CourseForge\Support\HttpException;
use CourseForge\Support\Typography;

/**
 * The punctuation pass, run over text that is already written.
 *
 * {@see Typography} corrects a page on the way in, which is the right place for
 * it and is no help at all to the four hundred pages written before the setting
 * existed, or written with it switched off, or written by a release whose rules
 * were worse than this one's. Those pages are the reason this class exists: it
 * is the same pass, aimed at what is already in the database, over a course, a
 * chapter or a single page.
 *
 * It runs whatever the profile says. The per-profile switch answers "correct
 * the pages I am about to generate"; asking for this one is somebody standing
 * in front of a course they have already got and saying "correct that". A
 * setting that turned the second question into "no" would be a setting that
 * refuses an instruction, which is not what settings are for.
 *
 * Two things make it safe to point at a finished course.
 *
 * **It only writes what it changed.** Every field is set, compared with what
 * was there, and left alone if the two are equal - so a course that was already
 * correct comes back with nothing touched, no timestamps moved and nothing
 * newly out of sync with its wiki. That rests entirely on the pass being
 * idempotent, which is the property {@see Typography} is built around and
 * tested for.
 *
 * **It can be asked without being obeyed.** `preview` runs everything and
 * writes nothing, which is what turns "correct my course" from a leap into a
 * question with an answer: 41 pages, 12 of them would change, here are the
 * first of them.
 */
final class Typesetter
{
    /** The three things a request may be aimed at. */
    public const LEVELS = ['course', 'chapter', 'page'];

    /**
     * How many changed items are named in the result.
     *
     * A five-hundred-page course would otherwise answer with five hundred
     * titles, which is a wall of text in the browser and, over MCP, a result
     * big enough to be truncated in the middle of a sentence. The counts are
     * always complete; the list is a sample of what they mean.
     */
    private const LISTED = 40;

    /**
     * Sets the punctuation of what is already written.
     *
     * @param array<string,mixed> $project the course row, as Access returned it
     * @param string $level one of self::LEVELS
     * @param int|null $targetId the chapter or the page, when the level names one
     * @param string|null $language overrides the course's own language
     * @return array<string,mixed>
     */
    public static function apply(
        string $owner,
        array $project,
        string $level,
        ?int $targetId,
        ?string $language = null,
        bool $preview = false
    ): array {
        if (!in_array($level, self::LEVELS, true)) {
            throw HttpException::unprocessable('Scope must be one of: ' . implode(', ', self::LEVELS) . '.');
        }

        $projectId = (int)$project['id'];
        $language = $language !== null && trim($language) !== '' ? trim($language) : self::languageOf($owner, $project);

        $chapters = self::chaptersFor($projectId, $level, $targetId);
        $pages = self::pagesFor($projectId, $level, $targetId);

        $scanned = ['pages' => 0, 'chapters' => 0, 'course' => 0];
        $corrected = ['pages' => 0, 'chapters' => 0, 'course' => 0];
        $changed = [];
        $outline = false;

        foreach ($pages as $page) {
            $scanned['pages']++;
            $fields = self::fieldsFor(
                ['title' => (string)$page['title'], 'content' => (string)$page['content']],
                $language,
                ['title']
            );
            if ($fields === []) {
                continue;
            }
            $corrected['pages']++;
            $outline = $outline || isset($fields['title']);
            self::note($changed, 'page', (int)$page['id'], (string)$page['title'], $fields);
            if (!$preview) {
                Pages::update((int)$page['id'], $fields);
            }
        }

        foreach ($chapters as $chapter) {
            $scanned['chapters']++;
            $fields = self::fieldsFor(
                ['title' => (string)$chapter['title'], 'description' => (string)$chapter['description']],
                $language,
                ['title']
            );
            if ($fields === []) {
                continue;
            }
            $corrected['chapters']++;
            $outline = true; // both of a chapter's fields are written into the outline
            self::note($changed, 'chapter', (int)$chapter['id'], (string)$chapter['title'], $fields);
            if (!$preview) {
                Chapters::update((int)$chapter['id'], $fields);
            }
        }

        if ($level === 'course') {
            $scanned['course'] = 1;
            $fields = self::fieldsFor(
                ['book_title' => (string)$project['book_title'], 'book_desc' => (string)$project['book_desc']],
                $language,
                ['book_title']
            );
            if ($fields !== []) {
                $corrected['course'] = 1;
                $outline = true;
                self::note($changed, 'course', $projectId, Projects::bookTitle($project), $fields);
                if (!$preview) {
                    Projects::update($owner, $projectId, $fields);
                }
            }
        }

        $total = $corrected['pages'] + $corrected['chapters'] + $corrected['course'];

        if (!$preview && $total > 0) {
            // A title or a description is also a line of structure_md, and the
            // outline is what applyStructure matches pages by. Rewriting it from
            // the rows that were just corrected is what keeps the two saying the
            // same thing - the same repair a rename has always made.
            if ($outline) {
                Projects::resyncStructure($owner, $projectId);
            }
            Projects::touch($projectId);
        }

        return [
            'scope' => $level,
            'preview' => $preview,
            'language' => $language,
            'style' => Typography::styleOf($language),
            'marks' => Typography::marksOf($language),
            'scanned' => $scanned,
            'corrected' => $corrected,
            'total' => $total,
            'changed' => $changed,
            'listed' => count($changed),
        ];
    }

    /* ------------------------------------------------------------ internals */

    /**
     * The fields of one row that the pass would change, and only those.
     *
     * A title is not free prose: it is a line of the outline, and `applyStructure`
     * finds a page by matching it. So a corrected title is put back through the
     * same canonical form every other way of writing a title goes through, and a
     * correction that emptied one is refused rather than stored.
     *
     * @param array<string,string> $row
     * @param array<int,string> $titles which of the keys are titles
     * @return array<string,string>
     */
    private static function fieldsFor(array $row, string $language, array $titles): array
    {
        $fields = [];
        foreach ($row as $key => $was) {
            $now = Typography::apply($was, $language);
            if (in_array($key, $titles, true)) {
                $now = Structure::canonicalTitle($now);
                if ($now === '') {
                    continue;
                }
            }
            if ($now !== $was) {
                $fields[$key] = $now;
            }
        }
        return $fields;
    }

    /**
     * @param array<int,array<string,mixed>> $changed
     * @param array<string,string> $fields
     */
    private static function note(array &$changed, string $type, int $id, string $title, array $fields): void
    {
        if (count($changed) >= self::LISTED) {
            return;
        }
        $changed[] = ['type' => $type, 'id' => $id, 'title' => $title, 'fields' => array_keys($fields)];
    }

    /** @return array<int,array<string,mixed>> */
    private static function chaptersFor(int $projectId, string $level, ?int $targetId): array
    {
        if ($level === 'page') {
            return [];
        }
        if ($level === 'chapter') {
            return [Chapters::require($projectId, self::required($targetId, 'chapter'))];
        }
        return Chapters::ordered($projectId);
    }

    /** @return array<int,array<string,mixed>> */
    private static function pagesFor(int $projectId, string $level, ?int $targetId): array
    {
        if ($level === 'page') {
            return [Pages::require($projectId, self::required($targetId, 'page'))];
        }

        $pages = Pages::ordered($projectId);
        if ($level === 'course') {
            return $pages;
        }

        $chapterId = self::required($targetId, 'chapter');
        return array_values(array_filter(
            $pages,
            static fn(array $page): bool => (int)$page['chapter_id'] === $chapterId
        ));
    }

    private static function required(?int $targetId, string $what): int
    {
        if ($targetId === null || $targetId <= 0) {
            throw HttpException::unprocessable('A ' . $what . ' id is required when correcting one ' . $what . '.');
        }
        return $targetId;
    }

    /**
     * The language the course is written in.
     *
     * Read from the profile rather than passed in, because all three callers
     * have the course and only one of them was holding a profile - and because
     * "which language is this" already has one answer in this application, in
     * Completion, which is the one a page was generated with.
     *
     * @param array<string,mixed> $project
     */
    private static function languageOf(string $owner, array $project): string
    {
        $id = $project['profile_id'] ?? null;
        if ($id !== null && $owner !== '') {
            try {
                return Completion::language(Profiles::data($owner, (int)$id));
            } catch (\Throwable) {
                // A profile deleted since the course was written is not a
                // reason to refuse to correct it; the installation's own
                // default language is the next best answer.
            }
        }
        return Completion::language([]);
    }
}
