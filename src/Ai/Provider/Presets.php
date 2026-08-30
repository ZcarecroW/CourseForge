<?php
declare(strict_types=1);

namespace CourseForge\Ai\Provider;

use CourseForge\Support\HttpException;

/**
 * The gateways CourseForge knows about without having a class for any of them.
 *
 * Every entry here speaks OpenAI's /chat/completions, so the only thing that
 * separates them is configuration: where the base URL points, which parameters
 * the gateway rejects rather than ignores, whether there is a batch queue
 * behind it. Turning each of those into an adapter would be twenty classes
 * that differ by four strings, and every one of them would rot at a different
 * rate. They are a table instead, consumed by OpenAiCompatibleProvider.
 *
 * Two flags carry the honesty of the table. `batch` says whether a queue is
 * known to exist, and its third value - 'probe' - means the answer has to be
 * discovered against the user's own key rather than guessed, because a queue
 * can be present on the endpoint and unavailable to the account holding it.
 * `verified` says whether the row was read off live documentation on
 * 2026-08-24 or written from general knowledge; an unverified base URL is a
 * suggestion, not a fact, and must be confirmed by a probe before a badge in
 * the UI claims anything about it. Only one row in this table is verified.
 *
 * The legend the table was written against, kept verbatim:
 *
 * 'batch'    true    = queue documented and OpenAI-shaped -> enable the queue UI immediately
 *            false   = confirmed absent -> hide the queue UI, never probe
 *            'probe' = unknown -> run the free capability probe (see section 5) on first save
 * 'verified' true    = base_url / quirks confirmed against live docs on 2026-08-24
 *            false   = from general knowledge; confirm with GET {base_url}/models before shipping
 * 'strip'            = params this gateway 400s on instead of ignoring
 * 'batch_endpoint'   = the value of the "endpoint" FIELD in the create-batch body.
 *                      NOT the URL. Groq is the reason this is a separate field.
 */
final class Presets
{
    /** The escape hatch: a base URL the user typed, with no assumptions attached. */
    public const CUSTOM = 'custom';

    /** Merged under every entry. */
    public const DEFAULTS = [
        'auth_header'      => 'Authorization',
        'auth_prefix'      => 'Bearer ',
        'chat_path'        => '/chat/completions',
        'models_path'      => '/models',
        'files_path'       => '/files',
        // Where the upload POST goes when it is not files_path, and the value
        // of the multipart `purpose` field. Together is the row that forced
        // both: it uploads to /files/upload and calls the purpose 'batch-api',
        // while still deleting and downloading from /files like everyone else.
        'files_upload_path' => '',
        'file_purpose'     => 'batch',
        'batches_path'     => '/batches',
        'batch_endpoint'   => '/v1/chat/completions',
        'window'           => '24h',
        // Whether the create body may carry completion_window at all. Together
        // publishes a window that "defaults to 24h and cannot be changed" and
        // its documented create body has no such field, so it is not sent.
        'sends_window'     => true,
        'max_tokens_field' => 'max_tokens',
        'strip'            => [],
        'local'            => false,
        'requires_key'     => true,
        'verified'         => false,
        'models_quirk'     => 'Standard OpenAI {object:list,data:[{id}]}.',
        // OpenAI's published ceilings, which are the right guess for a gateway
        // nobody has checked rather than a fact about all of them - Together
        // publishes 100 MB and disproves it. A row that has been read overrides
        // them.
        'max_batch_requests'  => 50000,
        'max_batch_megabytes' => 200,
    ];

    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        return [

        // ---------- hosted, batch queue confirmed ----------
        'groq' => [
            'label'          => 'Groq (GroqCloud)',
            'base_url'       => 'https://api.groq.com/openai/v1',
            'batch'          => true,
            'batch_endpoint' => '/v1/chat/completions',  // NOTE: no /openai prefix in the field
            'window'         => '7d',                    // Groq's docs recommend 7d over 24h
            'strip'          => ['logprobs', 'top_logprobs', 'n'], // 400s, does not ignore
            'max_tokens_field' => 'max_completion_tokens',
            'models_quirk'   => 'Adds context_window + max_completion_tokens per model - use them, '
                              . 'do not hardcode. Llama ids were retired 2026-08-16: resolve at runtime.',
            'docs'           => 'https://console.groq.com/docs/api-reference',
            'hint'           => 'Fastest inference; 50% batch discount, 7-day window. Model ids churn - never hardcode.',
            'verified'       => true,
        ],

        // ---------- hosted, OpenAI-shaped chat, queue unknown ----------
        'deepseek' => [
            'label'    => 'DeepSeek',
            'base_url' => 'https://api.deepseek.com/v1',
            'batch'    => 'probe',
            'docs'     => 'https://api-docs.deepseek.com/',
            'hint'     => 'Very cheap long-context drafting. Off-peak pricing; check the queue probe result.',
        ],
        'together' => [
            'label'    => 'Together AI',
            // api.together.xyz still answers, but every current document uses
            // api.together.ai and that is where the batch tutorial points.
            'base_url' => 'https://api.together.ai/v1',
            'batch'    => 'probe',
            'files_upload_path' => '/files/upload',
            'file_purpose'      => 'batch-api',
            'sends_window'      => false,
            'max_batch_requests' => 50000,
            'max_batch_megabytes' => 100,
            'docs'     => 'https://docs.together.ai/docs/inference/batch/tutorial',
            'hint'     => 'Large open-weight catalogue. Its queue takes 50,000 requests and a 100 MB file, '
                . 'and uploads to its own address; the probe still decides whether this key can reach it.',
        ],
        'fireworks' => [
            'label'    => 'Fireworks AI',
            'base_url' => 'https://api.fireworks.ai/inference/v1',
            'batch'    => 'probe',
            'models_quirk' => 'Model ids are namespaced paths (accounts/fireworks/models/...). '
                            . 'Never urlencode into a path segment.',
            'docs'     => 'https://docs.fireworks.ai/api-reference/introduction',
            'hint'     => 'Open-weight hosting; model ids contain slashes.',
        ],
        'xai' => [
            'label'    => 'xAI (Grok)',
            'base_url' => 'https://api.x.ai/v1',
            'batch'    => 'probe',
            'docs'     => 'https://docs.x.ai/api',
            'hint'     => 'Grok models via an OpenAI-shaped endpoint.',
        ],
        'mistral' => [
            'label'    => 'Mistral AI',
            'base_url' => 'https://api.mistral.ai/v1',
            'batch'    => false, // native queue exists at /v1/batch/jobs but is NOT OpenAI-shaped
            'docs'     => 'https://docs.mistral.ai/api/',
            'hint'     => 'Sync only in 4.0 - Mistral has a queue, but not an OpenAI-shaped one.',
        ],
        'cerebras' => [
            'label'    => 'Cerebras',
            'base_url' => 'https://api.cerebras.ai/v1',
            'batch'    => 'probe',
            'docs'     => 'https://inference-docs.cerebras.ai/',
            'hint'     => 'Very high token throughput, small catalogue.',
        ],
        'deepinfra' => [
            'label'    => 'DeepInfra',
            'base_url' => 'https://api.deepinfra.com/v1/openai',
            'batch'    => 'probe',
            'docs'     => 'https://deepinfra.com/docs',
            'hint'     => 'Cheap open-weight hosting.',
        ],
        'nebius' => [
            'label'    => 'Nebius AI Studio',
            'base_url' => 'https://api.studio.nebius.com/v1',
            'batch'    => 'probe',
            'docs'     => 'https://docs.nebius.com/studio/inference',
            'hint'     => 'EU-hosted open-weight models.',
        ],
        'moonshot' => [
            'label'    => 'Moonshot (Kimi)',
            'base_url' => 'https://api.moonshot.ai/v1',
            'batch'    => 'probe',
            'docs'     => 'https://platform.moonshot.ai/docs',
            'hint'     => 'Kimi K-series; very large single-response output budgets.',
        ],
        'zai' => [
            'label'    => 'Z.ai (GLM)',
            'base_url' => 'https://api.z.ai/api/paas/v4',
            'batch'    => 'probe',
            'models_quirk' => 'Non-/v1 base path; confirm /models exists before enabling the picker.',
            'docs'     => 'https://docs.z.ai/',
            'hint'     => 'GLM family at 1M context, aggressive pricing.',
        ],

        // ---------- self-hosted / local ----------
        'ollama' => [
            'label'        => 'Ollama (local)',
            'base_url'     => 'http://localhost:11434/v1',
            'batch'        => false,
            'requires_key' => false,
            'auth_prefix'  => 'Bearer ',   // any non-empty string works; UI pre-fills "ollama"
            'local'        => true,
            'models_quirk' => '/v1/models works but is thin. Prefer the native GET '
                            . 'http://localhost:11434/api/tags for sizes and families, and always '
                            . 'allow free-text model entry.',
            'docs'         => 'https://docs.ollama.com/api',
            'hint'         => 'Local models. WATCH THE CONTEXT WINDOW: the OpenAI shim cannot set '
                            . 'num_ctx, so a long course prompt can be silently truncated by the '
                            . 'server default - raise OLLAMA_CONTEXT_LENGTH on the host.',
        ],
        'lmstudio' => [
            'label'        => 'LM Studio (local)',
            'base_url'     => 'http://localhost:1234/v1',
            'batch'        => false,
            'requires_key' => false,
            'local'        => true,
            'models_quirk' => '/v1/models lists loaded and loadable models; ids are the local file '
                            . 'identifiers, so free-text entry is mandatory.',
            'docs'         => 'https://lmstudio.ai/docs/app/api/endpoints/openai',
            'hint'         => 'Local desktop server. Start the server in LM Studio first; first '
                            . 'request may block while the model loads - use a long cURL timeout.',
        ],
        'vllm' => [
            'label'        => 'vLLM (self-hosted)',
            'base_url'     => 'http://localhost:8000/v1',
            'batch'        => false, // vLLM's "batch" is an offline CLI, not an HTTP queue
            'requires_key' => false, // unless the server was started with --api-key
            'local'        => true,
            'models_quirk' => '/v1/models returns exactly the served model(s). The id must match '
                            . 'the --served-model-name / HF path EXACTLY or you get a 404.',
            'docs'         => 'https://docs.vllm.ai/en/latest/serving/openai_compatible_server.html',
            'hint'         => 'Self-hosted GPU serving. Model id must match the server exactly.',
        ],
        'llamacpp' => [
            'label'        => 'llama.cpp server (local)',
            'base_url'     => 'http://localhost:8080/v1',
            'batch'        => false,
            'requires_key' => false,
            'local'        => true,
            'models_quirk' => 'Often reports a single placeholder id. Free-text entry required.',
            'docs'         => 'https://github.com/ggml-org/llama.cpp/tree/master/tools/server',
            'hint'         => 'Bare-metal local inference. One model per server process.',
        ],
        'litellm' => [
            'label'        => 'LiteLLM proxy (self-hosted)',
            'base_url'     => 'http://localhost:4000/v1',
            'batch'        => 'probe', // LiteLLM proxies /batches for backends that have one
            'local'        => true,
            'requires_key' => false,
            'models_quirk' => 'Ids are the proxy aliases from config.yaml, not upstream ids.',
            'docs'         => 'https://docs.litellm.ai/docs/simple_proxy',
            'hint'         => 'Your own gateway in front of many providers. Probe finds the queue.',
        ],

        // ---------- escape hatch ----------
        'custom' => [
            'label'        => 'Custom OpenAI-compatible endpoint',
            'base_url'     => '',          // user-entered, must end without a trailing slash
            'batch'        => 'probe',
            'docs'         => '',
            'hint'         => 'Paste any base URL ending in /v1. CourseForge will probe it for a '
                            . 'model list and a batch queue without spending a cent.',
        ],
        ];
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /**
     * One preset, ready to drive a provider.
     *
     * An unknown key is an error rather than a silent fallback to `custom`: a
     * preset that vanished between releases would otherwise turn into an
     * account pointed at an empty base URL, and the account would look
     * configured while being unusable.
     */
    public static function spec(string $key): PresetSpec
    {
        $rows = self::all();
        $row = $rows[$key] ?? null;
        if ($row === null) {
            throw HttpException::unprocessable('Unknown endpoint preset "' . $key . '".');
        }
        return PresetSpec::fromArray($key, $row);
    }

    /**
     * Every preset in table order, which is the order the picker shows them in.
     *
     * @return array<string,PresetSpec>
     */
    public static function specs(): array
    {
        $specs = [];
        foreach (self::all() as $key => $row) {
            $specs[$key] = PresetSpec::fromArray($key, $row);
        }
        return $specs;
    }
}
