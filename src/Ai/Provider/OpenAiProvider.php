<?php
declare(strict_types=1);

namespace CourseForge\Ai\Provider;

/**
 * OpenAI itself, and every endpoint that was configured before the preset
 * picker existed.
 *
 * The chat body needs no adapter at all - OpenAI is the reference
 * implementation of the shape the whole preset lane imitates, so this class
 * inherits it rather than restating it. That inheritance is the point: the
 * generic driver is exercised by the busiest provider on every single request
 * and cannot quietly stop working. What is added here are the four behaviours
 * that belong to OpenAI alone and would poison a driver that also has to serve
 * Ollama:
 *
 *   - `max_tokens` is a hard 400 on every reasoning model and has to become
 *     `max_completion_tokens`, decided from the model id because the model list
 *     carries no capability metadata to ask instead,
 *   - the same models reject `temperature`, `top_p`, the two penalties,
 *     `logit_bias` and `logprobs` outright rather than ignoring them, so those
 *     are stripped rather than sent,
 *   - a finished batch produces two result files, which the shared file driver
 *     already handles because Groq copied that part too,
 *   - `GET /v1/models` is unfiltered, unordered and mixes embeddings, audio,
 *     image, moderation and every fine-tune the organisation ever trained in
 *     with the chat models, so the picker needs a curated intersection rather
 *     than the raw list - plus the `shutdown_date` field, which is free
 *     deprecation telemetry and is surfaced as a badge rather than copied into
 *     a hardcoded table that would rot.
 *
 * The curation applies only when the endpoint really is api.openai.com. Every
 * account written before 4.0 has this class whatever it points at, because
 * "OpenAI-compatible" was the only kind there was, and filtering a Groq or an
 * LM Studio catalogue against OpenAI's own model names would empty the picker
 * of the models the user actually has.
 */
class OpenAiProvider extends OpenAiCompatibleProvider
{
    /**
     * The preset OpenAI would have if it were in the table.
     *
     * Baked in rather than listed there because OpenAI is not one gateway
     * choice among many - it is the shape every other row is a variation of -
     * and because the reasoning rules below have no representation in a table
     * of strings.
     */
    private const PRESET = [
        'label' => 'OpenAI',
        'batch' => true,
        'window' => '24h',
        'models_quirk' => 'Unfiltered, unordered, no capability metadata; carries shutdown_date.',
        'docs' => 'https://developers.openai.com/api/docs',
        'hint' => 'OpenAI itself, and any gateway that copies its API.',
        'verified' => true,
    ];

    /**
     * Reasoning models: gpt-5 and up, and the o-series.
     *
     * Matched on the id because there is nowhere else to look - unlike
     * Anthropic, OpenAI's model list reports no capabilities at all. What it
     * prevents is a hard 400, and chat() still retries once when a 400 blames a
     * parameter, so a model released after this line was written costs one
     * extra round trip rather than a lost page.
     */
    private const REASONING = '/^(gpt-5|o[134])/i';

    /** Rejected outright by the reasoning models, not ignored. */
    private const REASONING_REJECTS = [
        'temperature',
        'top_p',
        'presence_penalty',
        'frequency_penalty',
        'logit_bias',
        'logprobs',
        'top_logprobs',
    ];

    /** The families worth writing a course with, out of an unfiltered list. */
    private const CHAT_MODELS = '/^(gpt-5|gpt-4\.1|gpt-4o|o[134])/i';

    /** Chat models whose ids the pattern above cannot describe, as id endings. */
    private const ALSO_CHAT = ['chat-latest', 'chatgpt-4o-latest'];

    /** Substrings that mark a non-text model the pattern would otherwise keep. */
    private const NOT_CHAT = [
        'audio',
        'realtime',
        'transcribe',
        'tts',
        'image',
        'embedding',
        'moderation',
        'search-preview',
    ];

    /**
     * Models the queue will not take, as id endings.
     *
     * Short and hardcoded because the API does not report it anywhere:
     * `chat-latest` is documented as unsupported by Batch, while the whole
     * gpt-5.6 family is supported.
     *
     * Endings rather than whole ids, because there is no model called
     * `chat-latest`: the ids are `gpt-5-chat-latest` and `gpt-5.1-chat-latest`,
     * and an entry matched against a whole id would exclude nothing at all
     * while looking exactly like a list that works.
     */
    private const NO_BATCH = ['chat-latest', 'chatgpt-4o-latest'];

    /** @var string[] filled as a side effect of models() */
    private array $batchAccepted = [];

    /** @var array<string,string> model id to warning, filled as a side effect of models() */
    private array $notices = [];

    public static function defaultBaseUrl(): string
    {
        return 'https://api.openai.com/v1';
    }

    public function kind(): string
    {
        return Providers::OPENAI;
    }

    /**
     * OpenAI's own spec, aimed at whatever address the account carries.
     *
     * Two fields move with the host, and the label is one of them. On
     * api.openai.com it is "OpenAI" - the same word the account picker prints
     * over this row, because an error signed with any other name reads as
     * though it came from some other account. Anywhere else this class is
     * serving a gateway configured before the preset picker existed, where
     * "OpenAI" would be the misleading half of the same problem, so those keep
     * the generic name. The queue flag moves for the same reason: on
     * api.openai.com the queue is never absent, so there is nothing to ask;
     * anywhere else the only honest answer is the one that endpoint gives when
     * it is asked.
     *
     * @param array<string,mixed> $account
     */
    protected static function resolveSpec(array $account): PresetSpec
    {
        $baseUrl = rtrim(trim((string)($account['base_url'] ?? '')), '/');
        if ($baseUrl === '') {
            // Late static binding, so a subclass anchored somewhere else -
            // OpenRouter is one - never inherits api.openai.com by accident.
            $baseUrl = static::defaultBaseUrl();
        }

        $row = self::PRESET;
        $row['base_url'] = $baseUrl;
        if (!self::isOpenAiHost($baseUrl)) {
            $row['label'] = 'OpenAI-compatible endpoint';
            $row['batch'] = PresetSpec::PROBE;
            $row['verified'] = false;
        }
        return PresetSpec::fromArray(Providers::OPENAI, $row);
    }

    /**
     * Bearer, plus the organisation header when the key belongs to more than
     * one organisation.
     *
     * It is sent only when the account carries one, which for a gateway that is
     * not OpenAI is never - and an unexpected header is exactly the kind of
     * thing a strict gateway answers 400 to.
     *
     * @return array<string,string>
     */
    protected function headers(): array
    {
        $headers = parent::headers();
        $organization = trim((string)($this->account['organization'] ?? ''));
        if ($organization !== '') {
            $headers['OpenAI-Organization'] = $organization;
        }
        return $headers;
    }

    /* --------------------------------------------------------------- models */

    /**
     * A picker, out of a list that is not one.
     *
     * `/v1/models` returns everything the key can see, in no order, with no
     * capability, context-window or modality metadata attached. The live call
     * proves the key can see a model; it cannot build a picker on its own. So
     * the list is intersected with a pattern for the current chat families and
     * a short table of the ids that pattern cannot describe.
     *
     * The intersection is never allowed to empty the picker. If it matches
     * nothing, the raw list is handed back: a dropdown filtered down to zero
     * looks like a broken account, while being wrong about one model id is a
     * 404 the user can read.
     *
     * @param array<int,mixed> $rows
     * @return string[]
     */
    protected function pickModels(array $rows): array
    {
        $ids = parent::pickModels($rows);
        $this->notices = self::readNotices($rows);
        $this->batchAccepted = [];

        if (!self::isOpenAiHost($this->baseUrl)) {
            // Some other gateway, reached through this class because it was
            // configured before presets existed. Its catalogue is its own.
            return $ids;
        }

        $picked = [];
        foreach ($ids as $id) {
            if (self::isChatModel($id)) {
                $picked[] = $id;
            }
        }
        if ($picked === []) {
            return $ids;
        }

        foreach ($picked as $id) {
            if (!self::endsWithAny($id, self::NO_BATCH)) {
                $this->batchAccepted[] = $id;
            }
        }
        return $picked;
    }

    /** @return string[] */
    public function batchModels(): array
    {
        return $this->batchAccepted;
    }

    /**
     * What is wrong with a model the user can still pick, keyed by model id.
     *
     * Today that is one thing: `shutdown_date`, which OpenAI added to the model
     * list so a client can warn about a scheduled removal without carrying a
     * deprecation table of its own. Reading it beats hardcoding the dates,
     * which would be a second list to keep in step with the first and would be
     * wrong the day one of them moved.
     *
     * @return array<string,string>
     */
    public function modelNotices(): array
    {
        return $this->notices;
    }

    /* ------------------------------------------------------------ internals */

    /**
     * The reasoning-model parameter rules, which are hard 400s rather than
     * ignored fields.
     *
     * Both token caps include the invisible reasoning tokens, so a budget that
     * was generous for prose can leave nothing for the prose: too small a
     * `max_completion_tokens` comes back billed and empty. That one is caught
     * downstream, where an empty completion raises instead of being written to
     * a page.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    protected function tuneForModel(array $payload, string $model): array
    {
        if (preg_match(self::REASONING, trim($model)) !== 1) {
            return $payload;
        }

        if (isset($payload['max_tokens'])) {
            $payload['max_completion_tokens'] = $payload['max_tokens'];
            unset($payload['max_tokens']);
        }
        foreach (self::REASONING_REJECTS as $param) {
            unset($payload[$param]);
        }
        return $payload;
    }

    /**
     * The deprecation warnings the model list volunteered.
     *
     * @param array<int,mixed> $rows
     * @return array<string,string>
     */
    private static function readNotices(array $rows): array
    {
        $notices = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string)($row['id'] ?? ''));
            $shutdown = trim((string)($row['shutdown_date'] ?? ''));
            if ($id !== '' && $shutdown !== '') {
                $notices[$id] = 'Scheduled for shutdown on ' . $shutdown . '.';
            }
        }
        return $notices;
    }

    private static function isChatModel(string $id): bool
    {
        $id = strtolower(trim($id));
        foreach (self::NOT_CHAT as $marker) {
            if (str_contains($id, $marker)) {
                return false;
            }
        }
        return preg_match(self::CHAT_MODELS, $id) === 1 || self::endsWithAny($id, self::ALSO_CHAT);
    }

    /**
     * Whether a model id ends with any of a list of id endings.
     *
     * The two curated lists here name families by the tail of the id, which is
     * the part OpenAI keeps stable while the prefix moves with the generation -
     * `gpt-5-chat-latest` became `gpt-5.1-chat-latest` without the meaning
     * changing.
     *
     * @param string[] $endings already lower case
     */
    private static function endsWithAny(string $id, array $endings): bool
    {
        $id = strtolower(trim($id));
        foreach ($endings as $ending) {
            if (str_ends_with($id, $ending)) {
                return true;
            }
        }
        return false;
    }

    /** Whether this really is OpenAI, rather than something wearing its API. */
    private static function isOpenAiHost(string $baseUrl): bool
    {
        return strtolower((string)parse_url(trim($baseUrl), PHP_URL_HOST)) === 'api.openai.com';
    }
}
