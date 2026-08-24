<?php
declare(strict_types=1);

namespace CourseForge\Ai;

use CourseForge\Domain\Details;
use CourseForge\Domain\Projects;
use CourseForge\Support\Config;
use CourseForge\Support\Text;

/** Designs a course outline, or revises the one that already exists. */
final class StructureGenerator
{
    /**
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $project
     * @return string Strict outline Markdown, ready for Structure::parse().
     */
    public static function run(array $profile, array $project, string $feedback = ''): string
    {
        $library = Prompt::library($profile);
        $existing = trim((string)$project['structure_md']);
        $feedback = trim($feedback);
        $refine = $feedback !== '' && $existing !== '';

        $pool = Text::splitList((string)$project['tag_pool']);
        $autoTags = (int)$project['auto_tags'] === 1;
        $strictPool = (int)$project['tag_pool_strict'] === 1 && $pool !== [];

        $details = Details::resolve(Projects::settings($project));
        $vars = self::vars($profile, $project, $details['params']) + [
            'current_structure' => $existing,
            'feedback' => $feedback,
            'tag_pool' => $pool === []
                ? '(no predefined pool – choose consistent keywords yourself)'
                : implode(', ', $pool),
            'tag_policy' => $strictPool
                ? 'Use ONLY tags from that list. Never invent a tag of your own; if nothing fits, use fewer tags.'
                : ($pool === []
                    ? 'Choose short, reusable keywords yourself and reuse the very same tag across items that belong together.'
                    : 'Prefer the tags from that list and reuse them consistently, but you may add further fitting tags of your own where they add real value.'),
        ];

        $systemSlot = $refine && trim($library['overview_refine_system'] ?? '') !== ''
            ? 'overview_refine_system'
            : 'overview_system';

        $system = Prompt::join(
            Prompt::slot($library, 'global_system', $vars),
            ($vars['audience'] ?? '') !== '' ? Prompt::slot($library, 'audience_block', $vars) : '',
            Prompt::slot($library, $systemSlot, $vars),
            $autoTags ? Prompt::slot($library, 'structure_tags_rules', $vars) : '',
            Prompt::slotOrDefault($library, 'language_instruction', $vars, 'Write every title and description in {{language}}.')
        );

        $user = $refine
            ? Prompt::slotOrDefault($library, 'overview_refine_user', $vars,
                "Course topic: {{topic}}\n\nCurrent structure:\n\n{{current_structure}}\n\n"
                . "Requested changes:\n{{feedback}}\n\n"
                . 'Return the complete, corrected structure in the exact required Markdown format. Language: {{language}}.')
            : Prompt::slotOrDefault($library, 'overview_user', $vars,
                "Design a complete course for the following request:\n\n{{topic}}\n\n"
                . 'Build a didactically sound outline in the exact required Markdown format. Language: {{language}}.');

        return Completion::run($profile, 'overview', $system, $user);
    }

    /**
     * Placeholder values available to every structure prompt.
     *
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $project
     * @param array<string,int|string> $params
     * @return array<string,scalar>
     */
    private static function vars(array $profile, array $project, array $params): array
    {
        return $params + [
            'language' => Completion::language($profile),
            'app_name' => Config::str('app.name', 'CourseForge'),
            'topic' => (string)$project['topic'],
            'book_title' => Projects::bookTitle($project),
            'book_description' => (string)$project['book_desc'],
            'extra_context' => '',
        ];
    }
}
