<?php
declare(strict_types=1);

namespace CourseForge\Ai\Provider;

/**
 * Everything that separates one OpenAI-compatible gateway from another.
 *
 * "OpenAI-compatible" is a claim about the chat body, and only about the chat
 * body. Around it, every gateway differs in ways that are pure configuration:
 * Groq serves the whole API under /openai/v1 but wants the bare
 * `/v1/chat/completions` in the batch `endpoint` field, DeepInfra hangs its
 * shim off /v1/openai, Z.ai does not use a /v1 segment at all, Ollama accepts
 * any key or none, and Groq answers 400 to `logprobs` where OpenAI would have
 * ignored it. None of that is worth a class. All of it is worth a field.
 *
 * The important distinction this object makes is between `batchEndpoint` and
 * the URL a batch is created at. They look like the same string and are not:
 * the URL is `{base_url}{batches_path}`, while `batchEndpoint` is the value of
 * the `endpoint` key inside the create-batch body, and Groq is the reason -
 * its real URL carries an `/openai` prefix that the field must not.
 *
 * `verified` is the one field that is about CourseForge rather than about the
 * gateway. It says whether the base URL and the quirks below were read off
 * live documentation or written from general knowledge, and an unverified
 * entry is not to be trusted until a probe has confirmed it against a real
 * key. Shipping a wrong base URL as though it were checked is how a user
 * spends an afternoon debugging their own network.
 */
final class PresetSpec
{
    /** `batch` when the answer is not known and the capability probe decides. */
    public const PROBE = 'probe';

    /**
     * @param bool|string $batch true, false, or PresetSpec::PROBE
     * @param string[] $strip parameters this gateway 400s on rather than ignoring
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $baseUrl,
        public readonly bool|string $batch = self::PROBE,
        public readonly string $authHeader = 'Authorization',
        public readonly string $authPrefix = 'Bearer ',
        public readonly string $chatPath = '/chat/completions',
        public readonly string $modelsPath = '/models',
        public readonly string $filesPath = '/files',
        public readonly string $batchesPath = '/batches',
        public readonly string $batchEndpoint = '/v1/chat/completions',
        public readonly string $window = '24h',
        public readonly string $maxTokensField = 'max_tokens',
        public readonly array $strip = [],
        public readonly bool $local = false,
        public readonly bool $requiresKey = true,
        public readonly bool $verified = false,
        public readonly string $modelsQuirk = '',
        public readonly string $docs = '',
        public readonly string $hint = '',
    ) {
    }

    /**
     * One row of the preset table, with the shared defaults merged under it.
     *
     * @param array<string,mixed> $row
     */
    public static function fromArray(string $key, array $row): self
    {
        $row += Presets::DEFAULTS;

        $strip = [];
        foreach ((array)($row['strip'] ?? []) as $param) {
            if (is_string($param) && trim($param) !== '') {
                $strip[] = trim($param);
            }
        }

        return new self(
            key: $key,
            label: (string)($row['label'] ?? $key),
            baseUrl: rtrim(trim((string)($row['base_url'] ?? '')), '/'),
            batch: self::readBatch($row['batch'] ?? self::PROBE),
            authHeader: (string)$row['auth_header'],
            authPrefix: (string)$row['auth_prefix'],
            chatPath: (string)$row['chat_path'],
            modelsPath: (string)$row['models_path'],
            filesPath: (string)$row['files_path'],
            batchesPath: (string)$row['batches_path'],
            batchEndpoint: (string)$row['batch_endpoint'],
            window: (string)$row['window'],
            maxTokensField: (string)$row['max_tokens_field'],
            strip: $strip,
            local: (bool)$row['local'],
            requiresKey: (bool)$row['requires_key'],
            verified: (bool)$row['verified'],
            modelsQuirk: (string)$row['models_quirk'],
            docs: (string)($row['docs'] ?? ''),
            hint: (string)($row['hint'] ?? ''),
        );
    }

    /**
     * A spec for an endpoint nobody has described, which is the whole of the
     * `custom` preset.
     *
     * Everything is the OpenAI default because that is the only assumption a
     * bare base URL licenses, `verified` is false because a URL a user typed
     * has been confirmed by nobody, and `batch` is left to the probe. A gateway
     * that turns out to want something else is a gateway that earns a row in
     * the preset table.
     */
    public static function forBaseUrl(string $baseUrl, string $label = ''): self
    {
        $row = Presets::all()['custom'];
        $row['base_url'] = $baseUrl;
        if (trim($label) !== '') {
            $row['label'] = trim($label);
        }
        return self::fromArray('custom', $row);
    }

    /**
     * The same gateway reached at a different address.
     *
     * Local presets are the reason this exists: LM Studio's port is a setting,
     * vLLM is wherever it was started, and a LiteLLM proxy is per installation.
     * The quirks stay, only the host moves.
     */
    public function withBaseUrl(string $baseUrl): self
    {
        $row = $this->toArray();
        $row['base_url'] = $baseUrl;
        return self::fromArray($this->key, $row);
    }

    /** The same gateway with one or more table fields replaced. @param array<string,mixed> $row */
    public function with(array $row): self
    {
        return self::fromArray($this->key, $row + $this->toArray());
    }

    /**
     * The row shape again, for storing an inline spec on a custom account.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'base_url' => $this->baseUrl,
            'batch' => $this->batch,
            'auth_header' => $this->authHeader,
            'auth_prefix' => $this->authPrefix,
            'chat_path' => $this->chatPath,
            'models_path' => $this->modelsPath,
            'files_path' => $this->filesPath,
            'batches_path' => $this->batchesPath,
            'batch_endpoint' => $this->batchEndpoint,
            'window' => $this->window,
            'max_tokens_field' => $this->maxTokensField,
            'strip' => $this->strip,
            'local' => $this->local,
            'requires_key' => $this->requiresKey,
            'verified' => $this->verified,
            'models_quirk' => $this->modelsQuirk,
            'docs' => $this->docs,
            'hint' => $this->hint,
        ];
    }

    /** True when the table says the queue is there and no probe is needed. */
    public function batchDeclared(): bool
    {
        return $this->batch === true;
    }

    /** True when the table has ruled the queue out and probing would be noise. */
    public function batchRefused(): bool
    {
        return $this->batch === false;
    }

    /** True when only the capability probe can answer. */
    public function batchUnknown(): bool
    {
        return $this->batch === self::PROBE;
    }

    /**
     * The auth header pair, or nothing at all.
     *
     * An empty key produces no header rather than an empty one: the local
     * servers accept an unauthenticated request and reject a malformed
     * `Authorization: Bearer ` outright, so sending the prefix on its own turns
     * a working setup into a 401.
     *
     * @return array<string,string>
     */
    public function authHeaders(string $apiKey): array
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '' || $this->authHeader === '') {
            return [];
        }
        return [$this->authHeader => $this->authPrefix . $apiKey];
    }

    /**
     * Removes the parameters this gateway rejects instead of ignoring.
     *
     * Silently dropping a parameter is the friendlier failure and most
     * gateways choose it; Groq does not, and answers 400 to `logprobs`,
     * `top_logprobs` and `n` above 1. Stripping here rather than at the call
     * site means the generic body stays generic.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function stripUnsupported(array $payload): array
    {
        foreach ($this->strip as $param) {
            unset($payload[$param]);
        }
        return $payload;
    }

    /**
     * Which section of the picker this belongs under.
     *
     * Derived rather than stored, because the three facts it reads - is it
     * local, does it have a queue, is it the escape hatch - are already in the
     * table and a fourth field would be a fourth thing to keep in step.
     */
    public function group(): string
    {
        if ($this->key === 'custom') {
            return 'custom';
        }
        if ($this->local) {
            return 'local';
        }
        return $this->batch === false ? 'sync' : 'queue';
    }

    /** Normalises the `batch` cell; anything unrecognised is treated as unknown. */
    private static function readBatch(mixed $value): bool|string
    {
        if (is_bool($value)) {
            return $value;
        }
        return self::PROBE;
    }
}
