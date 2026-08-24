<?php
declare(strict_types=1);

namespace CourseForge\Api;

use CourseForge\Ai\Provider\ClaudeCliProvider;
use CourseForge\Ai\Provider\Providers;
use CourseForge\Domain\Profiles;
use CourseForge\Publish\BookStackClient;
use CourseForge\Support\Request;
use CourseForge\Support\Runtime;

final class ProfileController
{
    /** @return array<string,mixed> */
    public static function index(Request $request, string $username): array
    {
        return ['profiles' => Profiles::all($username)];
    }

    /** @return array<string,mixed> */
    public static function create(Request $request, string $username): array
    {
        $name = $request->str('name', 'New profile');
        $profile = Profiles::create($username, $name !== '' ? $name : 'New profile', $request->arr('data'));
        return ['profile' => Profiles::redact($profile), 'profiles' => Profiles::all($username)];
    }

    /** @return array<string,mixed> */
    public static function update(Request $request, string $username): array
    {
        $id = $request->id('id');
        $name = $request->str('name', 'Profile');
        $profile = Profiles::update($username, $id, $name !== '' ? $name : 'Profile', $request->arr('data'));
        return ['profile' => Profiles::redact($profile), 'profiles' => Profiles::all($username)];
    }

    /** @return array<string,mixed> */
    public static function delete(Request $request, string $username): array
    {
        Profiles::delete($username, $request->id('id'));
        return ['profiles' => Profiles::all($username)];
    }

    /**
     * Live model list from the provider - the profile must be saved first.
     *
     * `batch` is the subset the provider will actually queue. Anthropic reports
     * it per model and OpenRouter publishes a separate `:batch` slug for each,
     * so both can answer precisely; a generic OpenAI-compatible gateway cannot,
     * and returns an empty list meaning "whatever supports_batch says".
     */
    public static function models(Request $request, string $username): array
    {
        $profile = Profiles::data($username, $request->id('id'));
        $provider = Providers::fromProfile($profile, $request->requiredStr('ai_id', 'AI account'));
        Runtime::beginLongRequest();

        $models = $provider->models();

        return [
            'models' => $models,
            'batch' => $provider->batchModels(),
            'supports_batch' => $provider->supportsBatch(),
            'kind' => $provider->kind(),
        ];
    }

    /**
     * Whether one account is actually usable, before a whole course depends on it.
     *
     * For the subscription account this is the only way to see the three things
     * that can be wrong: the CLI is missing, it is not signed in, or an API key
     * in the server's environment has quietly taken over the billing.
     */
    public static function check(Request $request, string $username): array
    {
        $profile = Profiles::data($username, $request->id('id'));
        $account = Providers::account($profile, $request->requiredStr('ai_id', 'AI account'));
        $provider = Providers::fromAccount($account);
        Runtime::beginLongRequest();

        if ($provider instanceof ClaudeCliProvider) {
            return ['check' => ['kind' => $provider->kind(), 'label' => $provider->label()] + $provider->status()];
        }

        // Everywhere else, fetching the model list is the cheapest proof that
        // the base URL and the key are both right.
        $models = $provider->models();
        return ['check' => [
            'kind' => $provider->kind(),
            'label' => $provider->label(),
            'ok' => true,
            'detail' => count($models) . ' model(s) reachable.',
            'supports_batch' => $provider->supportsBatch(),
        ]];
    }

    /** Shelves of a BookStack instance, so a course can be filed into one. */
    public static function shelves(Request $request, string $username): array
    {
        $profile = Profiles::data($username, $request->id('id'));
        $client = BookStackClient::fromProfile($profile, $request->requiredStr('instance_id', 'BookStack instance'));
        Runtime::beginLongRequest();
        return ['shelves' => $client->shelves()];
    }
}
