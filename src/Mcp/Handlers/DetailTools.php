<?php
declare(strict_types=1);

namespace CourseForge\Mcp\Handlers;

use CourseForge\Domain\Chapters;
use CourseForge\Domain\Details;
use CourseForge\Domain\Pages;
use CourseForge\Domain\Projects;
use CourseForge\Mcp\Args;
use CourseForge\Mcp\Resolve;
use CourseForge\Mcp\Schema;
use CourseForge\Mcp\Scopes;
use CourseForge\Mcp\Tool;
use CourseForge\Security\Actor;
use CourseForge\Support\Audit;
use CourseForge\Support\HttpException;

/**
 * Content details: why two courses written by the same model come out
 * differently.
 *
 * Thirteen switchable elements - whether a page opens with learning objectives,
 * closes with a summary, carries exercises, may draw Mermaid diagrams, set
 * LaTeX formulas, use tables, callouts, emojis, append flashcards or drop cross
 * references - and seven values, from the word count a page aims at to the
 * audience it is written for, decide what the generator actually produces. They
 * are the sharpest control anybody has over the finished text, and they are
 * cheap: changing them costs nothing until the next page is written.
 *
 * They exist at three levels because a course is rarely uniform. A reference
 * course wants no exercises anywhere except in the chapter that teaches the
 * syntax; the single page that introduces the data model wants a diagram budget
 * the rest of the course has no use for. So the course sets the tone, a chapter
 * deviates where it must, and one page may deviate further. Only deviations are
 * stored - nothing is copied downwards - which is what makes a change at the
 * course level reach every page that never disagreed with it.
 *
 * That is also the one thing to get right when reading anything this group
 * returns: a feature is tri-state, and 0 is not "off". It means this level has
 * no opinion and takes whatever the level above it has. Switching something off
 * for a page is -1, and it is a different act from removing the page's override
 * altogether.
 *
 * None of this rewrites text that already exists. It changes what the next
 * generation produces, so the usual order is set_details first, generate after.
 */
final class DetailTools
{
    /** How a stored tri-state reads in prose, for clients that show the answer to a person. */
    private const STATE_WORDS = [
        Details::OFF => 'off',
        Details::INHERIT => 'inherit',
        Details::ON => 'on',
    ];

    /** @return array<int,Tool> */
    public static function tools(): array
    {
        return [
            new Tool(
                name: 'get_detail_catalogue',
                scope: Scopes::DETAILS,
                title: 'List every content detail',
                description: 'Every content detail this installation has, with what it does and its '
                    . 'installation-wide default: the switchable features - summary, exercises, diagrams, formulas, '
                    . 'flashcards, code examples, tables, callouts, emojis, auto links and the rest - and the values '
                    . 'such as minimum and maximum length, diagram count, card count and audience. This is the only '
                    . 'place the valid keys, the value types and the permitted ranges are listed, so read it before '
                    . 'calling set_details. Takes no arguments and costs nothing.',
                properties: [],
                required: [],
                handler: static fn(Actor $actor, array $args): array => self::detailCatalogue(),
                readOnly: true,
                idempotent: true,
            ),

            new Tool(
                name: 'get_details',
                scope: Scopes::DETAILS,
                title: 'Read the content details of one level',
                description: 'What the content details are at one level and where each of them comes from. Give '
                    . 'course_id alone for the course, add chapter_id for one chapter, or page_id for one page - '
                    . 'page_id wins over chapter_id, which wins over the course. Every detail is reported three ways: '
                    . 'what this level stores of its own, what it would inherit if it stored nothing, and what '
                    . 'actually applies to a page written here. Features are tri-state, so a stored 0 means '
                    . 'inherited, never off. Costs nothing.',
                properties: [
                    'course_id' => Schema::courseId(),
                    'chapter_id' => Schema::int('Read one chapter\'s level instead of the course\'s.'),
                    'page_id' => Schema::int('Read one page\'s level. Takes precedence over chapter_id.'),
                ],
                required: ['course_id'],
                handler: static fn(Actor $actor, array $args): array => self::getDetails($actor, Args::of($args)),
                readOnly: true,
                idempotent: true,
            ),

            new Tool(
                name: 'set_details',
                scope: Scopes::DETAILS,
                title: 'Set content details at one level',
                description: 'Sets content detail overrides on the course, on one chapter or on one page. The ids '
                    . 'choose the level: page_id wins over chapter_id, which wins over the course, so course_id alone '
                    . 'changes the whole course. features is an object keyed by feature - 1 or true switches it on, '
                    . '-1 or false switches it off, 0 or null removes the override so that level inherits again. '
                    . 'values is an object keyed by value - a number or a string sets it, null clears it back to '
                    . 'inherited. Only the keys you send are touched; everything else stays as it was. Keys are '
                    . 'checked against get_detail_catalogue and a wrong one is rejected with the list of valid ones. '
                    . 'Returns the resolved details afterwards so you can see the effect. This changes what the next '
                    . 'generation writes and never edits a page that already has text.',
                properties: [
                    'course_id' => Schema::courseId(),
                    'chapter_id' => Schema::int('Set the override on one chapter instead of the course.'),
                    'page_id' => Schema::int('Set the override on one page. Takes precedence over chapter_id.'),
                    'features' => Schema::object(
                        'Feature keys mapped to 1 / true (on), -1 / false (off) or 0 / null (inherit). '
                        . 'For example {"exercises": 1, "emojis": -1, "mermaid": 0}.'
                    ),
                    'values' => Schema::object(
                        'Value keys mapped to a number, a string, or null to inherit. '
                        . 'For example {"min_length": 800, "diagram_max": 1, "audience": "experienced developers"}.'
                    ),
                ],
                required: ['course_id'],
                handler: static fn(Actor $actor, array $args): array => self::setDetails($actor, Args::of($args)),
                idempotent: true,
            ),

            new Tool(
                name: 'reset_details',
                scope: Scopes::DETAILS,
                title: 'Clear the content details of one level',
                description: 'Removes every content detail override at one level so it inherits everything again: a '
                    . 'page falls back to its chapter, a chapter to its course, and the course to the installation '
                    . 'defaults. The ids choose the level the same way as set_details - page_id wins over '
                    . 'chapter_id, which wins over the course. Nothing else is touched: resetting a chapter leaves '
                    . 'its pages\' own overrides in place. The cleared overrides are returned, so the same set_details '
                    . 'call can put them back if this was a mistake.',
                properties: [
                    'course_id' => Schema::courseId(),
                    'chapter_id' => Schema::int('Clear one chapter\'s overrides instead of the course\'s.'),
                    'page_id' => Schema::int('Clear one page\'s overrides. Takes precedence over chapter_id.'),
                ],
                required: ['course_id'],
                handler: static fn(Actor $actor, array $args): array => self::resetDetails($actor, Args::of($args)),
                destructive: true,
                idempotent: true,
            ),
        ];
    }

    /* ------------------------------------------------------------- handlers */

    /** @return array<string,mixed> */
    private static function detailCatalogue(): array
    {
        $catalogue = Details::catalogue();
        $baseline = Details::baseline();

        $features = [];
        foreach ($catalogue['features'] as $key => $spec) {
            $features[] = [
                'key' => (string)$key,
                'label' => (string)$spec['label'],
                'description' => (string)$spec['description'],
                'default' => (bool)($baseline['features'][$key] ?? false),
            ];
        }

        $values = [];
        foreach ($catalogue['params'] as $key => $spec) {
            $row = [
                'key' => (string)$key,
                'label' => (string)$spec['label'],
                'description' => (string)$spec['description'],
                'type' => (string)$spec['type'],
                'unit' => (string)$spec['unit'],
                'default' => $baseline['params'][$key] ?? $spec['default'],
            ];
            if ($spec['type'] !== 'text') {
                $row['min'] = (int)$spec['min'];
                $row['max'] = (int)$spec['max'];
                $row['step'] = (int)$spec['step'];
            }
            $values[] = $row;
        }

        return [
            'features' => $features,
            'values' => $values,
            'feature_count' => count($features),
            'value_count' => count($values),
            'levels' => 'Each of these can be set on the course, on one chapter or on one page. What applies to a '
                . 'page is whatever is set closest to it: the page, then its chapter, then the course, then the '
                . 'installation default listed here.',
            'features_are_tri_state' => '1 switches a feature on, -1 switches it off, 0 removes the override so the '
                . 'level inherits. 0 is never "off".',
            'values_are_nullable' => 'A number or a string sets a value; null clears it so the level inherits again. '
                . 'A length of 0 means "leave it to the model", which is not the same as inheriting.',
            'defaults_are' => 'The defaults above are the installation-wide baseline. They apply wherever no course, '
                . 'chapter or page overrides them.',
            'next_step' => 'Call get_details for a course, chapter or page to see what is set now, then set_details '
                . 'to change it.',
        ];
    }

    /** @return array<string,mixed> */
    private static function getDetails(Actor $actor, Args $args): array
    {
        $target = self::target($actor, $args);
        $view = self::view($target);

        return self::identify($target) + $view + [
            'stored_overrides' => [
                'features' => $target['own']['features'],
                'values' => $target['own']['params'],
            ],
            'how_to_read' => [
                'own is what this level stores: 1 forces the feature on, -1 forces it off, 0 means no override at '
                    . 'all. 0 is not "off" - a feature at 0 takes whatever the level above it says.',
                'inherited is what would apply if this level stored nothing; effective is what actually applies here.',
                'from names the level the effective answer comes from: default, course, chapter or page.',
            ],
            'next_step' => 'set_details changes this level; get_detail_catalogue explains what each key does.',
        ];
    }

    /** @return array<string,mixed> */
    private static function setDetails(Actor $actor, Args $args): array
    {
        $target = self::target($actor, $args);
        $features = self::readFeatures($args->object('features'));
        $values = self::readValues($args->object('values'));

        if ($features === [] && $values === []) {
            throw HttpException::unprocessable(
                'Nothing to set. Give features, values, or both - for example features {"exercises": -1} or values '
                . '{"max_length": 2000}. reset_details clears a level instead.'
            );
        }

        $projectId = (int)$target['project']['id'];
        match ($target['level']) {
            // Every one of these takes the level's own identifiers, and the
            // course patch takes the course's OWNER - an administrator editing
            // somebody else's course must not write it as themselves.
            'page' => Pages::patchDetails($projectId, (int)$target['page']['id'], $features, $values),
            'chapter' => Chapters::patchDetails($projectId, (int)$target['chapter']['id'], $features, $values),
            default => Projects::patchDetails($target['owner'], $projectId, $features, $values),
        };
        Projects::touch($projectId);

        $target = self::reread($target);

        return self::identify($target) + [
            'updated' => true,
            'features_set' => array_map(static fn(int $s): string => self::STATE_WORDS[$s], $features),
            'values_set' => $values,
        ] + self::view($target) + [
            'stored_overrides' => [
                'features' => $target['own']['features'],
                'values' => $target['own']['params'],
            ],
            'next_step' => 'The effective column above is what the next generation of this ' . $target['level']
                . ' will follow. get_page_brief shows the brief these details produce, and nothing already written '
                . 'has changed.',
        ];
    }

    /** @return array<string,mixed> */
    private static function resetDetails(Actor $actor, Args $args): array
    {
        $target = self::target($actor, $args);
        $cleared = $target['own'];

        if ($cleared['features'] === [] && $cleared['params'] === []) {
            return self::identify($target) + [
                'cleared' => false,
                'message' => 'This level stores no overrides of its own, so it already inherits everything.',
            ] + self::view($target);
        }

        // A patch only touches the keys it is handed, so clearing a level means
        // sending every key it holds back as "inherit".
        $features = array_fill_keys(array_keys($cleared['features']), Details::INHERIT);
        $values = array_fill_keys(array_keys($cleared['params']), null);

        $projectId = (int)$target['project']['id'];
        match ($target['level']) {
            'page' => Pages::patchDetails($projectId, (int)$target['page']['id'], $features, $values),
            'chapter' => Chapters::patchDetails($projectId, (int)$target['chapter']['id'], $features, $values),
            default => Projects::patchDetails($target['owner'], $projectId, $features, $values),
        };
        Projects::touch($projectId);

        Audit::record(
            $actor->username,
            'details.reset',
            (string)$target['project']['name'],
            $target['applies_to'] . ': ' . (count($cleared['features']) + count($cleared['params'])) . ' overrides cleared',
            'mcp'
        );

        $target = self::reread($target);

        return self::identify($target) + [
            'cleared' => true,
            'cleared_features' => $cleared['features'],
            'cleared_values' => $cleared['params'],
        ] + self::view($target) + [
            'next_step' => 'This level now inherits everything. The cleared values above can be put back with '
                . 'set_details if that was not intended.',
        ];
    }

    /* -------------------------------------------------------------- helpers */

    /**
     * The level a call is addressed to, with its stored overrides and the
     * levels above it in resolution order - closest ancestor last, which is the
     * order Details::describe expects.
     *
     * @return array{
     *   project:array<string,mixed>, owner:string, level:string, applies_to:string,
     *   chapter:array<string,mixed>|null, page:array<string,mixed>|null,
     *   own:array{features:array<string,int>,params:array<string,int|string>},
     *   ancestors:array<int,array{level:string,settings:array{features:array<string,int>,params:array<string,int|string>}}>
     * }
     */
    private static function target(Actor $actor, Args $args): array
    {
        ['project' => $project, 'owner' => $owner] = Resolve::course($actor, $args->id());
        $chapterId = $args->optionalId('chapter_id');
        $pageId = $args->optionalId('page_id');

        if ($pageId !== null) {
            $page = Resolve::page($project, $pageId);

            // page_id wins over chapter_id, so a chapter_id naming a different
            // chapter is a mistake worth stopping rather than quietly ignoring:
            // the caller has one of the two ids wrong and cannot see which.
            if ($chapterId !== null && $chapterId !== (int)$page['chapter_id']) {
                throw HttpException::unprocessable(
                    'Page ' . $pageId . ' belongs to chapter ' . (int)$page['chapter_id'] . ', not chapter '
                    . $chapterId . '. Send page_id alone to work on the page, or chapter_id alone for the chapter.'
                );
            }

            $chapter = Resolve::chapter($project, (int)$page['chapter_id']);
            return [
                'project' => $project,
                'owner' => $owner,
                'level' => 'page',
                'applies_to' => 'page "' . (string)$page['title'] . '"',
                'chapter' => $chapter,
                'page' => $page,
                'own' => Pages::settings($page),
                'ancestors' => [
                    ['level' => 'course', 'settings' => Projects::settings($project)],
                    ['level' => 'chapter', 'settings' => Chapters::settings($chapter)],
                ],
            ];
        }

        if ($chapterId !== null) {
            $chapter = Resolve::chapter($project, $chapterId);
            return [
                'project' => $project,
                'owner' => $owner,
                'level' => 'chapter',
                'applies_to' => 'chapter "' . (string)$chapter['title'] . '"',
                'chapter' => $chapter,
                'page' => null,
                'own' => Chapters::settings($chapter),
                'ancestors' => [
                    ['level' => 'course', 'settings' => Projects::settings($project)],
                ],
            ];
        }

        return [
            'project' => $project,
            'owner' => $owner,
            'level' => 'course',
            'applies_to' => 'course "' . (string)$project['name'] . '"',
            'chapter' => null,
            'page' => null,
            'own' => Projects::settings($project),
            'ancestors' => [],
        ];
    }

    /**
     * Reloads the level's own overrides after a patch. The ancestors cannot
     * have moved - a patch writes one level - so only this one is re-read.
     *
     * @param array<string,mixed> $target
     * @return array<string,mixed>
     */
    private static function reread(array $target): array
    {
        $projectId = (int)$target['project']['id'];

        $target['own'] = match ($target['level']) {
            'page' => Pages::settings(Pages::require($projectId, (int)$target['page']['id'])),
            'chapter' => Chapters::settings(Chapters::require($projectId, (int)$target['chapter']['id'])),
            default => Projects::settings(Projects::require((string)$target['owner'], $projectId)),
        };

        return $target;
    }

    /**
     * The identifying header every answer in this group opens with.
     *
     * @param array<string,mixed> $target
     * @return array<string,mixed>
     */
    private static function identify(array $target): array
    {
        return [
            'course_id' => (int)$target['project']['id'],
            'owner' => (string)$target['owner'],
            'level' => (string)$target['level'],
            'applies_to' => (string)$target['applies_to'],
            'chapter_id' => $target['chapter'] === null ? null : (int)$target['chapter']['id'],
            'page_id' => $target['page'] === null ? null : (int)$target['page']['id'],
        ];
    }

    /**
     * The three views of every detail at one level, one row per key, in the
     * catalogue's own order.
     *
     * @param array<string,mixed> $target
     * @return array{features:array<int,array<string,mixed>>,values:array<int,array<string,mixed>>}
     */
    private static function view(array $target): array
    {
        $catalogue = Details::catalogue();
        $ancestors = array_map(
            static fn(array $level): array => $level['settings'],
            $target['ancestors']
        );
        $described = Details::describe($target['own'], ...$ancestors);

        $features = [];
        foreach ($catalogue['features'] as $key => $spec) {
            $stored = (int)($target['own']['features'][$key] ?? Details::INHERIT);
            $features[] = [
                'key' => (string)$key,
                'label' => (string)$spec['label'],
                'own' => $stored,
                'own_meaning' => self::STATE_WORDS[$stored],
                'inherited' => (bool)($described['inherited']['features'][$key] ?? false),
                'effective' => (bool)($described['effective']['features'][$key] ?? false),
                'from' => self::origin('features', (string)$key, $target),
            ];
        }

        $values = [];
        foreach ($catalogue['params'] as $key => $spec) {
            $values[] = [
                'key' => (string)$key,
                'label' => (string)$spec['label'],
                'unit' => (string)$spec['unit'],
                'own' => $target['own']['params'][$key] ?? null,
                'inherited' => $described['inherited']['params'][$key] ?? $spec['default'],
                'effective' => $described['effective']['params'][$key] ?? $spec['default'],
                'from' => self::origin('params', (string)$key, $target),
            ];
        }

        return ['features' => $features, 'values' => $values];
    }

    /**
     * Which level the effective answer for one key comes from. Stored settings
     * hold deviations only, so the presence of a key is the whole test.
     *
     * @param array<string,mixed> $target
     */
    private static function origin(string $group, string $key, array $target): string
    {
        if (isset($target['own'][$group][$key])) {
            return (string)$target['level'];
        }
        foreach (array_reverse($target['ancestors']) as $level) {
            if (isset($level['settings'][$group][$key])) {
                return (string)$level['level'];
            }
        }
        return 'default';
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,int>
     */
    private static function readFeatures(array $raw): array
    {
        $known = Details::catalogue()['features'];

        $out = [];
        foreach ($raw as $key => $value) {
            $key = (string)$key;
            if (!isset($known[$key])) {
                throw HttpException::unprocessable(
                    'features contains "' . $key . '", which is not a content detail. The features are: '
                    . implode(', ', array_keys($known)) . '. Call get_detail_catalogue for what each one does.'
                );
            }
            $out[$key] = self::state($key, $value);
        }

        return $out;
    }

    /** One incoming feature value, in any of the forms a client sensibly sends. */
    private static function state(string $key, mixed $value): int
    {
        if ($value === null) {
            return Details::INHERIT;
        }
        if (is_bool($value)) {
            return $value ? Details::ON : Details::OFF;
        }
        if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1)) {
            $state = (int)$value;
            if ($state === Details::ON || $state === Details::OFF || $state === Details::INHERIT) {
                return $state;
            }
        }
        if (is_string($value)) {
            // The words a client reaches for when it is not thinking in tri-state.
            $word = strtolower(trim($value));
            $map = [
                'on' => Details::ON,
                'true' => Details::ON,
                'yes' => Details::ON,
                'off' => Details::OFF,
                'false' => Details::OFF,
                'no' => Details::OFF,
                'inherit' => Details::INHERIT,
                'default' => Details::INHERIT,
            ];
            if (isset($map[$word])) {
                return $map[$word];
            }
        }

        throw HttpException::unprocessable(
            'features.' . $key . ' must be 1 or true to switch it on, -1 or false to switch it off, or 0 or null to '
            . 'inherit from the level above.'
        );
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,int|string|null>
     */
    private static function readValues(array $raw): array
    {
        $known = Details::catalogue()['params'];

        $out = [];
        foreach ($raw as $key => $value) {
            $key = (string)$key;
            if (!isset($known[$key])) {
                throw HttpException::unprocessable(
                    'values contains "' . $key . '", which is not a content detail value. The values are: '
                    . implode(', ', array_keys($known)) . '. Call get_detail_catalogue for what each one means.'
                );
            }
            $spec = $known[$key];

            if ($value === null || (is_string($value) && trim($value) === '')) {
                $out[$key] = null;
                continue;
            }

            if ($spec['type'] === 'text') {
                if (!is_scalar($value)) {
                    throw HttpException::unprocessable(
                        'values.' . $key . ' must be text, or null to inherit from the level above.'
                    );
                }
                $out[$key] = trim((string)$value);
                continue;
            }

            if (!is_numeric($value)) {
                throw HttpException::unprocessable(
                    'values.' . $key . ' must be a whole number between ' . (int)$spec['min'] . ' and '
                    . (int)$spec['max'] . ', or null to inherit from the level above.'
                );
            }

            // A patch would clamp this silently, and a client that asked for
            // forty diagrams is better told it cannot have them than shown
            // twenty and left to work out why.
            $number = (int)$value;
            if ($number < (int)$spec['min'] || $number > (int)$spec['max']) {
                throw HttpException::unprocessable(
                    'values.' . $key . ' is ' . $number . ', which is outside the permitted range '
                    . (int)$spec['min'] . ' to ' . (int)$spec['max'] . '.'
                );
            }
            $out[$key] = $number;
        }

        return $out;
    }
}
