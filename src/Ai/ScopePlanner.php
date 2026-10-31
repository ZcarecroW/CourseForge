<?php
declare(strict_types=1);

namespace CourseForge\Ai;

use CourseForge\Domain\Details;
use CourseForge\Domain\Pages;
use CourseForge\Domain\Projects;
use CourseForge\Support\Config;
use CourseForge\Support\HttpException;
use CourseForge\Support\Text;

/**
 * Plans what each page of an existing outline - and no other page - teaches.
 *
 * An outline designed since scopes existed carries one under every page. One
 * designed before, or typed by hand, or extended page by page, has pages with
 * none, and a page with no scope is a page the others can only guess about.
 * This fills them in: one request that reads the whole outline and answers
 * with one short line per page, which is a few thousand output tokens for a
 * course whose pages will cost a few hundred thousand.
 *
 * It asks for scopes only, not for the outline back. Revising the outline
 * with "add scopes" as feedback would work too, and would have the model
 * rewrite every six-hundred-word chapter description to deliver forty-word
 * answers - the most expensive way there is of getting the cheapest result.
 *
 * Scopes a page already has are shown to the model as fixed, so planning the
 * missing ones divides what is left rather than what is taken; `all` plans
 * every page afresh.
 */
final class ScopePlanner
{
    /**
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $project
     * @return array{planned:int,missing:int,unanswered:string[]}
     */
    public static function run(array $profile, array $project, bool $all = false): array
    {
        $projectId = (int)$project['id'];
        $rows = Pages::outline($projectId);
        if ($rows === []) {
            throw HttpException::unprocessable('This course has no pages yet, so there is nothing to plan.');
        }

        $targets = array_values(array_filter(
            $rows,
            static fn(array $row): bool => $all || trim((string)$row['scope']) === ''
        ));
        if ($targets === []) {
            return ['planned' => 0, 'missing' => 0, 'unanswered' => []];
        }

        $plan = self::prompt($profile, $project, $rows, $targets, $all);
        $answer = Completion::run($profile, 'overview', $plan['system'], $plan['user']);
        $scopes = self::parse($answer);

        $planned = 0;
        $unanswered = [];
        foreach ($targets as $row) {
            $scope = $scopes[CourseContext::ref($row)] ?? '';
            if ($scope === '') {
                $unanswered[] = CourseContext::ref($row) . ' ' . (string)$row['title'];
                continue;
            }
            Pages::update((int)$row['id'], ['scope' => $scope]);
            $planned++;
        }

        if ($planned > 0) {
            // The outline is where a scope is written down, so it is rewritten
            // to carry them - otherwise the next apply of the stored outline
            // would read every page as having none.
            Projects::resyncStructure((string)$project['username'], $projectId);
            Projects::touch($projectId);
        }

        return ['planned' => $planned, 'missing' => count($targets) - $planned, 'unanswered' => $unanswered];
    }

    /**
     * The two prompt halves, without sending anything - also what a connected
     * client is handed to plan the scopes itself.
     *
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $project
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,array<string,mixed>> $targets
     * @return array{system:string,user:string}
     */
    public static function prompt(array $profile, array $project, array $rows, array $targets, bool $all): array
    {
        $library = Prompt::library($profile);

        // With every scope being planned afresh the outline is shown without
        // the old ones, so they cannot anchor the new plan.
        $outline = $all
            ? Projects::outlineWithoutScopes($project)
            : (string)$project['structure_md'];

        // The course's own content details, for the slots that read one - the
        // audience above all, which the global prompt may name.
        $vars = Details::resolve(...Projects::chain($project))['params'] + [
            'language' => Completion::language($profile),
            'app_name' => Config::str('app.name', 'CourseForge'),
            'topic' => (string)$project['topic'],
            'book_title' => Projects::bookTitle($project),
            'course_structure_full' => $outline,
            'course_structure' => CourseContext::map($rows),
            'page_list' => implode("\n", array_map(
                static fn(array $row): string => CourseContext::ref($row) . ' ' . Text::tidy((string)$row['title']),
                $targets
            )),
        ];

        return [
            'system' => Prompt::join(
                Prompt::slot($library, 'global_system', $vars),
                Prompt::slotOrDefault($library, 'scope_plan_system', $vars,
                    "Plan what each listed page of the course, and no other page, teaches. Answer with one line "
                    . "per page: its number, a space, then its scope - concepts separated by semicolons, at most "
                    . "25 words. No two scopes overlap. Nothing else."),
                Prompt::slotOrDefault($library, 'language_instruction', $vars, 'Write every scope in {{language}}.'),
            ),
            'user' => Prompt::slotOrDefault($library, 'scope_plan_user', $vars,
                "Course outline:\n\n{{course_structure_full}}\n\nPlan the scope of each of these pages:\n\n{{page_list}}"),
        ];
    }

    /**
     * `3.2 scope` lines out of an answer, keyed by "3.2".
     *
     * Forgiving about what models put around the number - a bullet, a colon,
     * a dash, bold - and strict about the number itself, because that is the
     * only thing tying a line to a page.
     *
     * @return array<string,string>
     */
    public static function parse(string $answer): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $answer) ?: [] as $line) {
            $line = trim(str_replace(['**', '__'], '', $line));
            if (preg_match('/^(?:[-*+]\s*)?(\d+)\.(\d+)\.?\s*[:)\x{2013}\x{2014}-]?\s+(.+)$/u', $line, $m) !== 1) {
                continue;
            }
            $scope = Text::tidy(trim($m[3], " \t\"'"));
            if ($scope !== '') {
                $out[(int)$m[1] . '.' . (int)$m[2]] ??= $scope;
            }
        }
        return $out;
    }
}
