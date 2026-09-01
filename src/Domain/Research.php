<?php
declare(strict_types=1);

namespace CourseForge\Domain;

use CourseForge\Support\Config;
use CourseForge\Support\Text;

/**
 * What somebody found out about the topic, and when.
 *
 * A course about WordPress, Vue, an API or a piece of law is out of date the
 * moment the model's training data is. CourseForge has always had one answer to
 * that - `web_research`, which asks whoever is writing the page to look it up
 * first - and it has always been per page. That is the right unit for "cite
 * what you read on this page" and the wrong one for "which version of WordPress
 * are we teaching": the second is a property of the course, every page needs
 * the same answer, and finding it two hundred times is two hundred times the
 * work for one fact.
 *
 * So findings are stored once, on the course, and travel into every brief after
 * that as `{{research_findings}}`. Who does the finding is deliberately open:
 *
 *   - a client connected over MCP - Claude Code, most usefully, which has a
 *     search tool of its own and costs nothing to use it - takes the assignment
 *     from `get_research_brief`, goes and reads, and posts the result back to
 *     `store_research`;
 *   - the Claude Code CLI provider does the same thing without a client, on the
 *     subscription of whoever is signed in on the machine;
 *   - a provider with a server-side search tool does it as part of writing;
 *   - or a person pastes in what they already know.
 *
 * All four write to the same column and are read the same way. Nothing here
 * knows or cares which one it was, beyond recording it so the age of the
 * findings can be reported honestly.
 *
 * Findings go stale, which is the entire point of having them, so they carry a
 * timestamp and everything that reads them says how old they are. Nothing is
 * ever silently expired: a six-month-old note about WordPress is still better
 * than the model's guess, and deciding it is too old is a person's call.
 */
final class Research
{
    /** How the findings got here. Recorded, never acted on. */
    public const SOURCE_CLIENT = 'client';
    public const SOURCE_MODEL = 'model';
    public const SOURCE_MANUAL = 'manual';

    /**
     * A ceiling on what one course may carry.
     *
     * Findings ride along in the context of every page of the course, so this
     * is not a storage limit - it is a per-page cost, paid as many times as
     * there are pages. Twelve thousand characters is a long briefing and about
     * three thousand tokens; a five-hundred-page course pays that five hundred
     * times, which is the number worth thinking about before raising it.
     */
    public const MAX_CHARS = 12000;

    /**
     * The findings on a course, or an empty string.
     *
     * @param array<string,mixed> $project
     */
    public static function of(array $project): string
    {
        return trim((string)($project['research_md'] ?? ''));
    }

    /** @param array<string,mixed> $project */
    public static function has(array $project): bool
    {
        return self::of($project) !== '';
    }

    /**
     * When the findings were written, as a unix timestamp, or null for never.
     *
     * @param array<string,mixed> $project
     */
    public static function at(array $project): ?int
    {
        $at = (int)($project['research_at'] ?? 0);
        return $at > 0 ? $at : null;
    }

    /** @param array<string,mixed> $project */
    public static function ageInDays(array $project): ?int
    {
        $at = self::at($project);
        return $at === null ? null : (int)floor((time() - $at) / 86400);
    }

    /**
     * A one-line description of how current the findings are.
     *
     * Written for a model as much as for a person: a client deciding whether to
     * research again needs the age, not the timestamp.
     *
     * @param array<string,mixed> $project
     */
    public static function freshness(array $project): string
    {
        if (!self::has($project)) {
            return 'none stored';
        }
        $days = self::ageInDays($project);
        if ($days === null) {
            return 'stored, date unknown';
        }
        return match (true) {
            $days <= 0 => 'stored today',
            $days === 1 => 'stored yesterday',
            $days < 31 => 'stored ' . $days . ' days ago',
            default => 'stored ' . (int)floor($days / 30) . ' month(s) ago - worth refreshing',
        };
    }

    /**
     * Stores findings on a course.
     *
     * Trimmed to MAX_CHARS on the way in rather than on the way out, because
     * the cost is paid per page and a caller that sent too much should be told
     * so once, here, rather than have it quietly inflate every prompt for the
     * rest of the course's life. The cut is at a line boundary: a briefing that
     * stops mid-URL is worse than one that stops a paragraph early.
     *
     * @return array{stored:bool,characters:int,truncated:bool}
     */
    public static function store(string $username, int $projectId, string $findings, string $source): array
    {
        $findings = trim(str_replace(["\r\n", "\r"], "\n", $findings));
        $truncated = false;

        if (mb_strlen($findings) > self::MAX_CHARS) {
            $cut = mb_substr($findings, 0, self::MAX_CHARS);
            $break = mb_strrpos($cut, "\n");
            $findings = rtrim($break !== false && $break > self::MAX_CHARS / 2 ? mb_substr($cut, 0, $break) : $cut);
            $truncated = true;
        }

        Projects::update($username, $projectId, [
            'research_md' => $findings,
            // Clearing the findings clears the date with them, so that "none
            // stored" and "stored at some unknown time" stay different states.
            'research_at' => $findings === '' ? 0 : time(),
            'research_source' => $findings === '' ? '' : self::source($source),
        ]);

        return ['stored' => $findings !== '', 'characters' => mb_strlen($findings), 'truncated' => $truncated];
    }

    /** Anything unrecognised is a person, because a person is the one that cannot be checked. */
    private static function source(string $source): string
    {
        return in_array($source, [self::SOURCE_CLIENT, self::SOURCE_MODEL, self::SOURCE_MANUAL], true)
            ? $source
            : self::SOURCE_MANUAL;
    }

    /**
     * The findings as a prompt block, or an empty string when there are none.
     *
     * The date is part of the block on purpose. A model handed undated facts
     * treats them as timeless and writes "the current version is 6.4" into a
     * course that will be read next year; handed the date, it writes what it
     * was told and says when that was true.
     *
     * @param array<string,mixed> $project
     */
    public static function block(array $project): string
    {
        $findings = self::of($project);
        if ($findings === '') {
            return '';
        }

        $at = self::at($project);
        $when = $at === null ? 'an unknown date' : gmdate('j F Y', $at);

        return "Researched facts about this topic, established on " . $when . ". Prefer these over what you "
            . "remember, treat them as true as of that date, and say which version or date a statement holds for "
            . "when it matters:\n\n" . $findings;
    }

    /**
     * The assignment: what a researcher should go and find out.
     *
     * Deliberately about the course rather than about one page. What comes back
     * is read by every page, so what is asked for is the set of facts every
     * page would otherwise get wrong on its own.
     *
     * @param array<string,mixed> $project
     */
    public static function assignment(array $project, int $maxSearches): string
    {
        $topic = trim((string)$project['topic']);
        $title = Projects::bookTitle($project);
        $subject = $topic !== '' ? $topic : $title;
        $budget = $maxSearches > 0
            ? 'About ' . $maxSearches . ' searches is the budget; spend them on the things that actually move.'
            : 'Search as much as the subject needs.';
        $max = self::MAX_CHARS;

        return <<<TXT
        Establish the current state of this subject on the web, today, before any of the course is designed or
        written. What you find is stored once on the course and is then read by the outline and by every single
        page, so this is the one place where being current is worth doing properly.

        The subject:

        {$subject}

        Find out, and write down:
        - The latest stable version, release or edition, its number, and when it was released.
        - What changed recently enough that a model trained a year ago would get it wrong: new APIs, renamed
          options, moved configuration, changed defaults, new recommended practice.
        - What has been deprecated or removed, and what replaced it. This is the half that produces course pages
          teaching something that no longer exists.
        - Where the official documentation now lives, as URLs.
        - What practitioners currently recommend, where that differs from what the documentation says.
        - Anything version-dependent that the course will have to state a version for.

        Rules:
        - Prefer official documentation, release notes, changelogs and specifications over blog posts and tutorials.
        - Never invent a version number, a date, a URL or a source, and never cite a page you did not read. If
          something cannot be established, write that it could not be established rather than guessing.
        - Give dates. A fact with no date is a fact that cannot be aged later.
        - {$budget}

        Write it as plain Markdown: short sections, dense bullets, a closing "## Sources" list of what you read as
        Markdown links. Keep it under {$max} characters - it is carried in the context of every page of the course,
        so it is paid for once per page. No preamble, no commentary, just the findings.
        TXT;
    }

    /** A short, safe excerpt for a tool answer or a log line. */
    public static function preview(string $findings): string
    {
        return Text::snippet($findings, 400);
    }

    /** The configured search budget for a course, for the tools that report it. */
    public static function searchBudget(array $params): int
    {
        $configured = (int)($params['research_max_searches'] ?? 0);
        return $configured > 0 ? $configured : Config::int('app.research_default_searches', 8);
    }
}
