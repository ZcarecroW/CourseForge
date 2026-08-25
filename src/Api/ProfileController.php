<?php
declare(strict_types=1);

namespace CourseForge\Api;

use CourseForge\Ai\Provider\ClaudeCliProvider;
use CourseForge\Ai\Provider\OpenAiCompatibleProvider;
use CourseForge\Ai\Provider\Probe;
use CourseForge\Ai\Provider\Providers;
use CourseForge\Domain\Profiles;
use CourseForge\Publish\BookStackClient;
use CourseForge\Security\Access;
use CourseForge\Security\Actor;
use CourseForge\Support\HttpException;
use CourseForge\Support\Json;
use CourseForge\Support\Request;
use CourseForge\Support\Runtime;

/**
 * Profiles: the AI accounts and BookStack instances a course is written with.
 *
 * Every route answers with the caller's own library, whoever's profile was
 * just written - see `Access::workingSetOwner()`, which is where that rule and
 * its reasoning live.
 */
final class ProfileController
{
    /** @return array<string,mixed> */
    public static function index(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        return ['profiles' => Profiles::all(Access::workingSetOwner($me, $request))];
    }

    /** @return array<string,mixed> */
    public static function create(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();

        $name = $request->str('name', 'New profile');
        $profile = Profiles::create($me->username, $name !== '' ? $name : 'New profile', $request->arr('data'));
        return ['profile' => Profiles::redact($profile), 'profiles' => Profiles::all(Access::workingSetOwner($me, $request))];
    }

    /** @return array<string,mixed> */
    public static function update(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $id = $request->id('id');
        $owner = self::owner($me, $id);

        // An absent field means "leave it alone", which is what every other
        // update endpoint here does and what the MCP tool promises its caller.
        // It used to mean "replace it with nothing": a PUT carrying only a name
        // answered 200 and took every API key and BookStack credential with it.
        // A profile is the one row in this application that holds secrets, so
        // it is the last one that should be destroyed by omission.
        $stored = Profiles::require($owner, $id);

        $name = $request->has('name') ? $request->str('name') : (string)$stored['name'];
        if ($name === '') {
            $name = (string)$stored['name'];
        }

        // Profiles::update shapes the data explicitly, so a partial document
        // would still drop whatever it did not mention. Merging here keeps that
        // shaping intact while making an omitted section mean "unchanged".
        $data = $request->has('data')
            ? Json::merge((array)$stored['data'], $request->arr('data'))
            : (array)$stored['data'];

        $profile = Profiles::update($owner, $id, $name, $data);
        return ['profile' => Profiles::redact($profile), 'profiles' => Profiles::all(Access::workingSetOwner($me, $request))];
    }

    /** @return array<string,mixed> */
    public static function delete(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $id = $request->id('id');
        $owner = self::owner($me, $id);

        Profiles::delete($owner, $id);
        return ['profiles' => Profiles::all(Access::workingSetOwner($me, $request))];
    }

    /**
     * Live model list from the provider - the profile must be saved first.
     *
     * `batch` is the subset the provider will actually queue. Anthropic reports
     * it per model and OpenRouter publishes a separate `:batch` slug for each,
     * so both can answer precisely; a generic OpenAI-compatible gateway cannot,
     * and returns an empty list meaning "whatever supports_batch says".
     */
    public static function models(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $id = $request->id('id');

        $profile = Profiles::data(self::owner($me, $id), $id);
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
     *
     * For an OpenAI-compatible endpoint this route is also the capability
     * probe, and the only place it ever runs. Three free GETs and, when they
     * get that far, one POST that every real queue rejects decide whether there
     * is a batch queue and whether this key may use it, and the answer is
     * written onto the account row so that nothing else has to ask the network
     * the same question again - not the Content tab's poll, not a page render.
     * That is the whole reason the probe exists rather than a live check at the
     * moment of need, and it is why an endpoint with a queue but no upload lane
     * is discovered here, in a second, instead of at the first upload of a
     * course that has been queueing for an hour.
     *
     * `probe` says how hard to work at that question. The default `force` is
     * the "Check this endpoint" button and always asks the endpoint; `auto`
     * reuses an answer that is not yet due to be retaken; `skip` checks the key
     * and leaves the queue alone.
     */
    public static function check(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $id = $request->id('id');
        $owner = self::owner($me, $id);
        $aiId = $request->requiredStr('ai_id', 'AI account');
        $depth = $request->enum('probe', ['force', 'auto', 'skip'], 'force');

        $profile = Profiles::data($owner, $id);
        $account = Providers::account($profile, $aiId);
        $provider = Providers::fromAccount($account);
        Runtime::beginLongRequest();

        if ($provider instanceof ClaudeCliProvider) {
            return ['check' => ['kind' => $provider->kind(), 'label' => $provider->label()] + $provider->status()];
        }

        // Everywhere else, fetching the model list is the cheapest proof that
        // the base URL and the key are both right - but only when the list came
        // from the endpoint. The Anthropic adapter falls back to a hard-coded
        // list when it cannot reach one at all, which is right for a model
        // picker and would be a false verdict here: a closed port and a junk
        // key both answered "both good".
        $models = $provider->models();

        if (method_exists($provider, 'lastReach')) {
            $reach = $provider->lastReach();
            if (($reach['reached'] ?? true) === false) {
                throw HttpException::badRequest(
                    'Could not reach that endpoint, so the key could not be checked'
                    . (($reach['why'] ?? '') !== '' ? ': ' . $reach['why'] : '.')
                );
            }
        }

        $probe = $provider instanceof OpenAiCompatibleProvider
            ? self::probeAccount($provider, $account, $owner, $id, $aiId, $depth)
            : null;

        return ['check' => [
            'kind' => $provider->kind(),
            'label' => $provider->label(),
            'ok' => true,
            'detail' => count($models) . ' model(s) reachable.',
            // The provider was built before the probe ran, so its own copy of
            // the account still has whatever was stored an instant ago. A fresh
            // verdict outranks it.
            'supports_batch' => Probe::supported($probe) ?? $provider->supportsBatch(),
            'probe' => $probe,
        ]];
    }

    /** Shelves of a BookStack instance, so a course can be filed into one. */
    public static function shelves(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $id = $request->id('id');

        $profile = Profiles::data(self::owner($me, $id), $id);
        $client = BookStackClient::fromProfile($profile, $request->requiredStr('instance_id', 'BookStack instance'));
        Runtime::beginLongRequest();
        return ['shelves' => $client->shelves()];
    }

    /* -------------------------------------------------------------- helpers */

    /**
     * The capability probe for one account: taken and stored, or read back.
     *
     * A stored answer is reused only under `auto`, and only while it is not due
     * to be retaken - the base URL and key it belongs to are unchanged, the
     * probe logic has not moved on, and it is less than a month old. Profiles
     * has already dropped anything that fails the first of those on its way out
     * of the database, so what arrives here either belongs to this account or is
     * not there at all.
     *
     * What goes to the browser is what the probe found. What goes into the
     * database is the same thing stamped with the credentials it was found
     * against, which Profiles does on the way in, because that stamp is a hash
     * of a live key and the browser is the one reader that must never hold one.
     *
     * @param array<string,mixed> $account
     * @return array<string,mixed>|null
     */
    private static function probeAccount(
        OpenAiCompatibleProvider $provider,
        array $account,
        string $owner,
        int $profileId,
        string $aiId,
        string $depth,
    ): ?array {
        if ($depth === 'skip') {
            return null;
        }

        $stored = Probe::stored($account['batch_probe'] ?? null);
        if ($depth === 'auto' && $stored !== null && !Probe::stale($stored)) {
            $stored['for'] = '';
            return $stored;
        }

        $probe = $provider->probe();
        Profiles::storeProbe($owner, $profileId, $aiId, $probe);
        return $probe;
    }

    /**
     * Authorises the actor for this profile and hands back whose it is.
     *
     * The distinction matters even here, where the profile is addressed by its
     * own id: an administrator opening somebody else's profile is opening that
     * person's credentials, and every call downstream has to be made in their
     * name or it will not find the row at all.
     */
    private static function owner(Actor $actor, int $id): string
    {
        return (string)Access::profile($actor, $id)['username'];
    }
}
