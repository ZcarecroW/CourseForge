<?php
declare(strict_types=1);

namespace CourseForge\Ai\Provider;

use CourseForge\Ai\ModelId;
use CourseForge\Support\HttpException;
use CourseForge\Support\Runtime;
use Throwable;

/**
 * Turns a stored AI account into the provider that can serve it, and tells the
 * Profiles UI what an account is allowed to be in the first place.
 *
 * Both jobs live here because they have to agree with each other: the map from
 * a stored `kind` to a class, and the catalogue the picker is built from. A
 * profile written by CourseForge 3.2 has to keep working untouched, so the four
 * kind strings that existed then are unchanged, and an account written by 3.0 or
 * 3.1 - which carried no kind at all, because every account back then was
 * OpenAI-compatible - still has one inferred from its base URL. Nothing in this
 * file may make an existing profile unopenable; that upgrade path is the reason
 * inferKind() survives into a release that has six kinds instead of one.
 *
 * 4.0 adds two of them. `gemini` is a first-class adapter because nothing about
 * Google's API is OpenAI-shaped. `oai-compat` is the preset lane: one class, one
 * table, and an account that carries a `preset_key` naming the row it is on.
 * That is why a kind is no longer a unique key in the catalogue - some twenty
 * entries share the preset kind and are told apart by `preset_key` - so anything
 * that used to look an entry up by kind alone should use `id` instead, or ask
 * entryFor() for the entry belonging to one account.
 *
 * The preset kind string is read off OpenAiCompatibleProvider rather than
 * written out again, because two spellings are in circulation - `oai-compat` in
 * the design brief and in the class, `oai_compat` in the house convention that
 * gave `claude_cli` its underscore - and a factory that disagreed with a
 * provider about its own name would produce accounts that cannot be found again.
 * The class wins, the other spelling is accepted from stored data, and kindOf()
 * returns exactly what that provider's kind() will return.
 *
 * The one question the catalogue deliberately refuses to answer is whether a
 * given account can queue work right now. A queue is a property of an endpoint
 * *and a key* together: Gemini's is paid-tier only, a preset's may be closed to
 * the account holding it, and a model may be excluded from a queue that
 * otherwise works. That answer needs the account, so it lives in
 * batchReadiness(), and a queue badge has to be drawn from there rather than
 * from the `batch` cell of a catalogue row.
 */
final class Providers
{
    /* The four that existed in 3.2. These strings sit in stored profiles and
       are never to be changed. */
    public const OPENAI = 'openai';
    public const ANTHROPIC = 'anthropic';
    public const OPENROUTER = 'openrouter';
    public const CLAUDE_CLI = 'claude_cli';

    /* The two 4.0 adds, taken from the class that answers to them so that
       kindOf() and $provider->kind() cannot drift apart. */
    public const GEMINI = GeminiProvider::KIND;
    public const OAI_COMPAT = OpenAiCompatibleProvider::KIND;

    /** Sections of the picker, in the order they are meant to be shown. */
    public const GROUP_NATIVE = 'native';
    public const GROUP_HOSTED_QUEUE = 'hosted_queue';
    public const GROUP_HOSTED_SYNC = 'hosted_sync';
    public const GROUP_LOCAL = 'local';
    public const GROUP_CUSTOM = 'custom';

    /** @var array<string,class-string<Provider>> */
    private const CLASSES = [
        self::OPENAI => OpenAiProvider::class,
        self::ANTHROPIC => AnthropicProvider::class,
        self::GEMINI => GeminiProvider::class,
        self::OPENROUTER => OpenRouterProvider::class,
        self::CLAUDE_CLI => ClaudeCliProvider::class,
        self::OAI_COMPAT => OpenAiCompatibleProvider::class,
    ];

    /**
     * Spellings that mean a kind above without being it.
     *
     * Each one is a real disagreement rather than a courtesy: the design brief
     * writes the preset lane as `oai_compat` and the CLI as `claude-cli`, the
     * classes spell both the other way round, and an account saved from either
     * vocabulary has to open. Accepting both costs one array lookup and removes
     * a class of bug that only ever shows up in somebody else's stored profile.
     *
     * @var array<string,string>
     */
    private const ALIASES = [
        'oai_compat' => self::OAI_COMPAT,
        'oai-compat' => self::OAI_COMPAT,
        'openai_compatible' => self::OAI_COMPAT,
        'claude-cli' => self::CLAUDE_CLI,
        'google' => self::GEMINI,
    ];

    /** PresetSpec's own section names, in this file's vocabulary. */
    private const GROUPS = [
        'queue' => self::GROUP_HOSTED_QUEUE,
        'sync' => self::GROUP_HOSTED_SYNC,
        'local' => self::GROUP_LOCAL,
        'custom' => self::GROUP_CUSTOM,
    ];

    /**
     * What the Profiles UI renders and what the API validates against.
     *
     * Every entry has the same shape, so a consumer reads a value rather than
     * testing for a key. The fields worth explaining:
     *
     *   `id`         unique across the catalogue - the kind for a native
     *                adapter, `kind:preset_key` for a preset. This is the value
     *                a select should carry now that `kind` repeats.
     *   `batch`      what the *endpoint* is claimed to offer: true, false, or
     *                'probe' for "nobody knows until it is asked with a key". A
     *                default for the account form, not a capability, and a queue
     *                badge drawn from it would be wrong for every preset whose
     *                queue turns out to be per-account.
     *   `batch_note` the caveat such a badge would otherwise hide, in one
     *                sentence - Gemini's paid tier, OpenRouter's beta contract,
     *                a preset still waiting on its probe.
     *   `verified`   whether the base URL and the quirks behind it were read off
     *                live documentation on 2026-08-24 or written from general
     *                knowledge. An unverified row is a suggestion.
     *   `local`      runs on this machine: no key to ask for, no queue to wait
     *                in, and a model box that must accept free text because the
     *                list such a server returns is only ever advisory.
     *   `group`      which section of the picker it belongs under.
     *
     * The order is natives first - so the first entry stays the OpenAI one a
     * fresh account defaults to - then the presets with the custom escape hatch
     * at the head of them. That last detail is deliberate: two callers still
     * look an entry up by kind alone, and the honest answer for a bare
     * `oai-compat` account that names no preset is the row which assumes
     * nothing, not whichever gateway happens to sort first.
     *
     * @return array<int,array{kind:string,id:string,preset_key:string,label:string,base_url:string,
     *     needs_key:bool,batch:bool|string,batch_note:string,hint:string,docs:string,local:bool,
     *     verified:bool,beta:bool,group:string}>
     */
    public static function catalogue(): array
    {
        $entries = self::natives();
        foreach (self::presetSpecs() as $spec) {
            $entries[] = self::presetEntry($spec);
        }
        return $entries;
    }

    /**
     * The catalogue entry an existing account belongs to.
     *
     * A form that has an account in hand needs the row behind it - the hint, the
     * docs link, whether to hide the key field - and cannot find it by kind any
     * more, because every preset account shares one. An account whose preset key
     * is empty or names a row that no longer exists resolves to the custom
     * entry, which is the same fallback OpenAiCompatibleProvider makes when it
     * builds the spec, so the form and the driver never describe one account two
     * different ways.
     *
     * @param array<string,mixed> $account
     * @return array<string,mixed>
     */
    public static function entryFor(array $account): array
    {
        $kind = self::kindOf($account);
        $preset = self::presetKeyOf($account);

        $fallback = null;
        foreach (self::catalogue() as $entry) {
            if ($entry['kind'] !== $kind) {
                continue;
            }
            if ($entry['preset_key'] === $preset) {
                return $entry;
            }
            $fallback ??= $entry;
        }

        return $fallback ?? self::entry(['kind' => $kind, 'label' => $kind]);
    }

    /**
     * The kinds an account may be stored as, for validating input.
     *
     * Canonical spellings only. The aliases are accepted when an account is
     * read and are deliberately absent here: a list offered to a person should
     * name each choice once.
     *
     * @return string[]
     */
    public static function kinds(): array
    {
        return array_keys(self::CLASSES);
    }

    /** @param array<string,mixed> $account */
    public static function fromAccount(array $account): Provider
    {
        $kind = self::kindOf($account);
        $class = self::CLASSES[$kind] ?? null;
        if ($class === null) {
            throw HttpException::unprocessable('Unknown AI account type "' . $kind . '".');
        }
        return new $class($account);
    }

    /** @param array<string,mixed> $profile */
    public static function fromProfile(array $profile, string $accountId): Provider
    {
        return self::fromAccount(self::account($profile, $accountId));
    }

    /**
     * @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    public static function account(array $profile, string $accountId): array
    {
        foreach ((array)($profile['ai'] ?? []) as $account) {
            if (is_array($account) && (string)($account['id'] ?? '') === $accountId) {
                return $account;
            }
        }
        throw HttpException::unprocessable('AI account "' . $accountId . '" is not part of this profile.');
    }

    /**
     * The account's kind, however it was written down.
     *
     * Three sources, in order. An explicit kind wins, once it has been folded
     * onto its canonical spelling. A row that names a preset but has lost its
     * kind is a 4.0 row and belongs to the preset lane - that only happens when
     * something rewrote the account without copying every field, but the answer
     * costs nothing to get right. Everything else is a pre-3.2 account and is
     * guessed from the base URL.
     *
     * An unrecognised kind falls through to the guess rather than raising. A
     * profile written by a newer CourseForge than this one still has to open,
     * even if one account in it lands on the wrong adapter.
     *
     * @param array<string,mixed> $account
     */
    public static function kindOf(array $account): string
    {
        $stated = trim((string)($account['kind'] ?? ''));
        $kind = self::canonicalKind($stated);
        if ($kind !== '') {
            return $kind;
        }

        // The guess below is for accounts written before 3.2, which carry no
        // kind at all. It must not also swallow a kind that WAS stated and is
        // simply not one this release knows - a typo, or a newer client - by
        // quietly landing on "openai" and then failing with a message about a
        // missing OpenAI key.
        if ($stated !== '') {
            throw HttpException::unprocessable(
                'This account names the provider kind "' . $stated . '", which this release does not know. '
                . 'It must be one of: ' . implode(', ', self::kinds()) . '.'
            );
        }

        if (self::namesPreset($account)) {
            return self::OAI_COMPAT;
        }
        return self::inferKind((string)($account['base_url'] ?? ''));
    }

    /**
     * One spelling of a kind folded onto the canonical one, or '' when it names
     * nothing this release knows about.
     */
    public static function canonicalKind(string $kind): string
    {
        $kind = strtolower(trim($kind));
        $kind = self::ALIASES[$kind] ?? $kind;

        return isset(self::CLASSES[$kind]) ? $kind : '';
    }

    /**
     * Host-based guess for accounts stored before the kind field existed.
     *
     * Only the hosts that have a first-class adapter are recognised, and
     * everything else - including every host that now has a preset - stays
     * `openai`. That is not laziness: a 3.2 account pointing at Groq is served
     * today by OpenAiProvider, it works, and quietly moving it onto the preset
     * lane would change its label, its parameter handling and its stored kind
     * without anybody having asked. A user who wants the Groq preset picks it.
     */
    public static function inferKind(string $baseUrl): string
    {
        $host = strtolower((string)parse_url(trim($baseUrl), PHP_URL_HOST));
        if ($host === '') {
            return self::OPENAI;
        }
        if (str_contains($host, 'openrouter.ai')) {
            return self::OPENROUTER;
        }
        if (str_contains($host, 'anthropic.com')) {
            return self::ANTHROPIC;
        }
        if (str_contains($host, 'generativelanguage.googleapis.com')) {
            return self::GEMINI;
        }
        return self::OPENAI;
    }

    /**
     * Which preset row an account is on, or '' when its kind is a native one.
     *
     * An empty or unknown key answers `custom`, which is the spec the provider
     * itself falls back to, so the picker and the driver agree about what the
     * account is.
     *
     * @param array<string,mixed> $account
     */
    public static function presetKeyOf(array $account): string
    {
        if (self::kindOf($account) !== self::OAI_COMPAT) {
            return '';
        }
        $key = strtolower(trim((string)($account['preset_key'] ?? '')));

        return $key !== '' && Presets::has($key) ? $key : Presets::CUSTOM;
    }

    /* --------------------------------------------------------- batch queues */

    /**
     * Whether this account can queue work right now.
     *
     * Pass the model when there is one - the answer differs per model on the two
     * providers that report it - and leave it out when the question is only
     * about the account.
     *
     * @param array<string,mixed> $account
     */
    public static function batchReady(array $account, string $model = ''): bool
    {
        return self::batchReadiness($account, $model)['ready'];
    }

    /**
     * The same answer with its reasoning, for a UI that has to explain itself.
     *
     * Three separate facts are combined here and the order they are asked in is
     * the whole design. First a stored probe result, because it is the only one
     * of the three that was measured against this account's own key - and a
     * negative one outranks an adapter's optimism, since the way a probe turns
     * negative against a provider that swears it has a queue is a real
     * submission coming back 404. Then the provider's own supportsBatch(), which
     * is what the endpoint offers. Then the model, where a provider is able to
     * say which of its models the queue accepts: an empty list there means "it
     * did not say", never "none of them", so a missing model is only refused
     * when the list was actually populated.
     *
     * Two of those three can reach the network - supportsBatch() on a preset
     * whose queue has never been probed, and batchModels() nearly everywhere -
     * so this belongs behind a button or a save rather than in a render loop.
     * Both are wrapped: a lookup that fails is treated as "did not say" and
     * logged, because refusing to queue on the strength of a timed-out
     * catalogue call is a worse answer than trying and being told no.
     *
     * @param array<string,mixed> $account
     * @return array{ready:bool,reason:string,kind:string,label:string,queue:bool,model:string,
     *     probe:string,probe_stale:bool}
     */
    public static function batchReadiness(array $account, string $model = ''): array
    {
        $stored = is_array($account['batch_probe'] ?? null) ? $account['batch_probe'] : null;
        $model = ModelId::base($model);

        $answer = [
            'ready' => false,
            'reason' => '',
            'kind' => self::kindOf($account),
            'label' => '',
            'queue' => false,
            'model' => $model,
            'probe' => $stored !== null ? (string)($stored['result'] ?? '') : '',
            'probe_stale' => Probe::stale($stored),
        ];

        try {
            $provider = self::fromAccount($account);
        } catch (Throwable $e) {
            $answer['reason'] = $e->getMessage();
            return $answer;
        }

        $answer['label'] = $provider->label();

        if (!$provider instanceof BatchCapable) {
            $answer['reason'] = $provider->label() . ' has no batch queue to send work to.';
            return $answer;
        }

        if (Probe::supported($stored) === false) {
            $reason = Probe::reason($stored);
            $answer['reason'] = $reason !== ''
                ? $reason
                : $provider->label() . ' was checked with this key and answered with no usable queue.';
            return $answer;
        }

        try {
            $answer['queue'] = $provider->supportsBatch();
        } catch (Throwable $e) {
            Runtime::log('providers.supports_batch', $e);
            $answer['reason'] = $provider->label() . ' could not be asked whether it has a queue - '
                . $e->getMessage();
            return $answer;
        }

        if (!$answer['queue']) {
            // Deliberately not "did not answer": half the providers that land
            // here were never asked anything. A local server, a preset the
            // table rules out and an account with no key all refuse without a
            // round trip, and a reason that implies one sends the reader to
            // look at their network.
            $answer['reason'] = $provider->label() . ' has no batch queue available to this account.';
            return $answer;
        }

        if ($model !== '' && self::queueTakes($provider, $model) === false) {
            $answer['reason'] = $provider->label() . ' does not accept ' . $model . ' through its queue.';
            return $answer;
        }

        $answer['ready'] = true;
        return $answer;
    }

    /* ------------------------------------------------------------ internals */

    /**
     * Whether the queue is reported to take this model: true, false, or null for
     * "the provider does not publish that".
     */
    private static function queueTakes(Provider $provider, string $model): ?bool
    {
        try {
            $models = $provider->batchModels();
        } catch (Throwable $e) {
            Runtime::log('providers.batch_models', $e);
            return null;
        }

        return $models === [] ? null : in_array($model, $models, true);
    }

    /** @param array<string,mixed> $account */
    private static function namesPreset(array $account): bool
    {
        if (trim((string)($account['preset_key'] ?? '')) !== '') {
            return true;
        }
        $inline = $account['preset'] ?? null;

        return is_array($inline) && $inline !== [];
    }

    /**
     * The five adapters that have a class of their own.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function natives(): array
    {
        // Gemini writes its own row. The beta warning and the account form
        // belong to the same fact, and a warning kept in a different file from
        // the code it warns about is the one that goes stale first.
        $gemini = GeminiProvider::catalogueEntry();

        return [
            self::entry([
                'kind' => self::OPENAI,
                'label' => 'OpenAI',
                'base_url' => OpenAiProvider::defaultBaseUrl(),
                'batch' => true,
                'docs' => 'https://developers.openai.com/api/docs',
                'verified' => true,
                'hint' => 'OpenAI itself. Reasoning models take different parameters from the chat models, and '
                    . 'the picker is curated because /v1/models returns embeddings, audio and every fine-tune '
                    . 'mixed in with them. Accounts written before 4.0 stay on this type whatever they point '
                    . 'at - to add a new gateway, pick it from the OpenAI-compatible list instead.',
            ]),
            self::entry([
                'kind' => self::ANTHROPIC,
                'label' => 'Anthropic API',
                'base_url' => AnthropicProvider::defaultBaseUrl(),
                'batch' => true,
                'docs' => 'https://platform.claude.com/docs/en/api/messages',
                'verified' => true,
                'hint' => 'The native Messages API with an sk-ant- key. The Message Batches queue halves the '
                    . 'price of a long run and stacks with prompt caching, and which models it takes is read '
                    . 'from the model list rather than guessed.',
            ]),
            self::entry([
                'kind' => self::GEMINI,
                'label' => (string)($gemini['label'] ?? 'Google Gemini (beta)'),
                'base_url' => (string)($gemini['base_url'] ?? GeminiProvider::defaultBaseUrl()),
                'needs_key' => (bool)($gemini['needs_key'] ?? true),
                'batch' => (bool)($gemini['batch'] ?? true),
                'beta' => (bool)($gemini['beta'] ?? true),
                'docs' => 'https://ai.google.dev/api',
                'verified' => true,
                'hint' => (string)($gemini['hint'] ?? ''),
                'batch_note' => 'The queue is paid-tier only, so a free-tier key is refused at submit - and a '
                    . 'batch that has not finished within 48 hours expires with no results at all.',
            ]),
            self::entry([
                'kind' => self::OPENROUTER,
                'label' => 'OpenRouter',
                'base_url' => OpenRouterProvider::defaultBaseUrl(),
                'batch' => true,
                'docs' => 'https://openrouter.ai/docs',
                'verified' => true,
                'hint' => 'One key for every vendor OpenRouter fronts. Model ids carry the vendor prefix, as in '
                    . 'anthropic/claude-opus-5, and the routing suffixes such as :free or :nitro are typed in '
                    . 'rather than picked from the list.',
                'batch_note' => 'The queue is a beta API with no published size limits and no documented way to '
                    . 'cancel, so a batch is designed to run to completion or expiry.',
            ]),
            self::entry([
                'kind' => self::CLAUDE_CLI,
                'label' => 'Claude subscription (Pro / Max)',
                'base_url' => '',
                'needs_key' => false,
                'batch' => false,
                // Local in the sense the account form cares about: it runs on
                // this server and there is no key to store. It is not free - the
                // work bills against the subscription the CLI is signed in with
                // - which is why the hint says so rather than the flag.
                'local' => true,
                'verified' => true,
                'hint' => 'Uses the Claude Code CLI already signed in on this server, so generation is billed '
                    . 'against a Claude Pro or Max plan instead of an API key. No key is stored by CourseForge, '
                    . 'and there is no queue to batch into.',
            ]),
        ];
    }

    /**
     * The preset rows, with the escape hatch first.
     *
     * @return array<string,PresetSpec>
     */
    private static function presetSpecs(): array
    {
        $specs = Presets::specs();

        $custom = $specs[Presets::CUSTOM] ?? null;
        if ($custom === null) {
            return $specs;
        }
        unset($specs[Presets::CUSTOM]);

        return [Presets::CUSTOM => $custom] + $specs;
    }

    /** @return array<string,mixed> */
    private static function presetEntry(PresetSpec $spec): array
    {
        return self::entry([
            'kind' => self::OAI_COMPAT,
            'preset_key' => $spec->key,
            'label' => $spec->label,
            'base_url' => $spec->baseUrl,
            'needs_key' => $spec->requiresKey,
            'batch' => $spec->batch,
            'batch_note' => self::presetBatchNote($spec),
            'hint' => $spec->hint,
            'docs' => $spec->docs,
            'local' => $spec->local,
            'verified' => $spec->verified,
            'group' => self::GROUPS[$spec->group()] ?? self::GROUP_CUSTOM,
        ]);
    }

    /**
     * What the `batch` cell of a preset row does not say on its own.
     *
     * Even a confirmed queue gets a sentence, because the confirmation is about
     * the endpoint while the badge has to be about the key. This is the text a
     * picker shows beside a queue it is not yet entitled to promise.
     */
    private static function presetBatchNote(PresetSpec $spec): string
    {
        if ($spec->batchDeclared()) {
            return 'Documented and OpenAI-shaped. The queue badge still waits for the probe, because a queue '
                . 'can be open on the endpoint and closed to the key using it.';
        }
        if ($spec->batchRefused()) {
            return $spec->local
                ? 'A local server answers each request as it arrives; there is no queue to wait in.'
                : 'No OpenAI-shaped queue here, so generation runs live.';
        }

        return 'Unknown until CourseForge probes this endpoint with your key - a few free GETs, run when the '
            . 'account is saved and repeatable from the re-check button.';
    }

    /**
     * One catalogue row, filled out completely.
     *
     * Every entry carries every field even where it is empty, so a consumer can
     * read a value rather than test for a key: the MCP handler passes these
     * through whole, and a front end that has to guard each field grows one more
     * guard per release.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function entry(array $row): array
    {
        $kind = (string)($row['kind'] ?? '');
        $preset = (string)($row['preset_key'] ?? '');
        $batch = $row['batch'] ?? false;

        return [
            'kind' => $kind,
            'id' => $preset === '' ? $kind : $kind . ':' . $preset,
            'preset_key' => $preset,
            'label' => (string)($row['label'] ?? $kind),
            'base_url' => (string)($row['base_url'] ?? ''),
            'needs_key' => (bool)($row['needs_key'] ?? true),
            'batch' => is_bool($batch) ? $batch : PresetSpec::PROBE,
            'batch_note' => (string)($row['batch_note'] ?? ''),
            'hint' => (string)($row['hint'] ?? ''),
            'docs' => (string)($row['docs'] ?? ''),
            'local' => (bool)($row['local'] ?? false),
            'verified' => (bool)($row['verified'] ?? false),
            'beta' => (bool)($row['beta'] ?? false),
            'group' => (string)($row['group'] ?? self::GROUP_NATIVE),
        ];
    }
}
