<?php
declare(strict_types=1);

namespace CourseForge\Api;

use CourseForge\Ai\Provider\ClaudeCliProvider;
use CourseForge\Ai\Provider\Providers;
use CourseForge\Domain\Details;
use CourseForge\Domain\Profiles;
use CourseForge\Support\Config;
use CourseForge\Support\Request;

/**
 * Everything the UI needs to render itself: the prompt library, the detail
 * catalogue and the shape of an empty profile. Fetched once after sign-in.
 */
final class ConfigController
{
    /** @return array<string,mixed> */
    public static function show(Request $request, string $username): array
    {
        $catalogue = Details::catalogue();

        return [
            'app' => ['name' => Config::str('app.name', 'CourseForge'), 'version' => CF_VERSION],
            'prompt_groups' => Config::promptGroups(),
            'prompt_slots' => Config::promptSlots(),
            'details' => [
                'features' => array_values($catalogue['features']),
                'params' => array_values($catalogue['params']),
                'baseline' => Details::baseline(),
            ],
            'profile_defaults' => Profiles::defaults(),
            'providers' => Providers::catalogue(),
            // A host that forbids proc_open can never reach the Claude CLI, and
            // the Profiles screen says so rather than offering a dead option.
            'can_spawn' => ClaudeCliProvider::canSpawn(),
        ];
    }
}
