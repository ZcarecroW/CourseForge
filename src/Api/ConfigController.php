<?php
declare(strict_types=1);

namespace CourseForge\Api;

use CourseForge\Ai\Provider\ClaudeCliProvider;
use CourseForge\Ai\Provider\Providers;
use CourseForge\Ai\Run\RunManager;
use CourseForge\Domain\Details;
use CourseForge\Domain\Profiles;
use CourseForge\Security\Actor;
use CourseForge\Support\Config;
use CourseForge\Support\HttpException;
use CourseForge\Support\Request;

/**
 * Everything the UI needs to render itself: the prompt library, the detail
 * catalogue and the shape of an empty profile. Fetched once after sign-in.
 */
final class ConfigController
{
    /** @return array<string,mixed> */
    public static function show(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();

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
            // Who is asking, in the same shape everything else in 4.0 reports
            // an actor - so the SPA has one description of the signed-in
            // account rather than several that can drift apart.
            'actor' => $me->toArray(),
            'can' => self::can(),
        ];
    }

    /**
     * What the navigation has to know that the role does not already say.
     *
     * Deliberately not a copy of the role. `actor` above reports it once, and a
     * second copy here would be the drift that block exists to prevent - two
     * descriptions of one account, one of them eventually stale. Anything the
     * SPA can decide from `actor.is_admin` it decides from there.
     *
     * What belongs here is the other half of the question a navigation entry
     * asks. `background_runs` is not a permission - nobody is forbidden the
     * scheduler - it is whether the installation has one configured at all,
     * which is why nothing in here takes the actor. To the navigation the shape
     * is the same either way: is there something behind this entry, or would
     * pressing it only lead to an explanation of why there is not.
     *
     * @return array<string,bool>
     */
    private static function can(): array
    {
        return [
            'background_runs' => RunManager::cronConfigured(),
        ];
    }
}
