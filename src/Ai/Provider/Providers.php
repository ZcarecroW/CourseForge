<?php
declare(strict_types=1);

namespace CourseForge\Ai\Provider;

use CourseForge\Support\HttpException;

/**
 * Turns a stored AI account into the provider that can serve it.
 *
 * The account's `kind` decides. Profiles written by CourseForge 3.0 and 3.1
 * have no kind at all - every account back then was OpenAI-compatible - so an
 * absent one is inferred from the base URL rather than rejected. That is the
 * whole of the upgrade path: an existing profile keeps working untouched, and
 * only an account the user actually edits gains an explicit kind.
 */
final class Providers
{
    public const OPENAI = 'openai';
    public const ANTHROPIC = 'anthropic';
    public const OPENROUTER = 'openrouter';
    public const CLAUDE_CLI = 'claude_cli';

    /** @var array<string,class-string<Provider>> */
    private const CLASSES = [
        self::OPENAI => OpenAiProvider::class,
        self::ANTHROPIC => AnthropicProvider::class,
        self::OPENROUTER => OpenRouterProvider::class,
        self::CLAUDE_CLI => ClaudeCliProvider::class,
    ];

    /**
     * What the Profiles UI offers, and what the API validates against.
     *
     * @return array<int,array{kind:string,label:string,base_url:string,needs_key:bool,batch:bool,hint:string}>
     */
    public static function catalogue(): array
    {
        return [
            [
                'kind' => self::OPENAI,
                'label' => 'OpenAI-compatible',
                'base_url' => OpenAiProvider::defaultBaseUrl(),
                'needs_key' => true,
                'batch' => true,
                'hint' => 'OpenAI itself and anything that speaks /chat/completions: Azure OpenAI, Groq, '
                    . 'Together, DeepInfra, Mistral, vLLM, LM Studio, Ollama. The base URL must end at /v1.',
            ],
            [
                'kind' => self::ANTHROPIC,
                'label' => 'Anthropic API',
                'base_url' => AnthropicProvider::defaultBaseUrl(),
                'needs_key' => true,
                'batch' => true,
                'hint' => 'The native Messages API with an sk-ant- key. Supports the Message Batches queue, '
                    . 'which halves the price of a long generation run.',
            ],
            [
                'kind' => self::OPENROUTER,
                'label' => 'OpenRouter',
                'base_url' => OpenRouterProvider::defaultBaseUrl(),
                'needs_key' => true,
                'batch' => true,
                'hint' => 'One key for every vendor OpenRouter fronts. Model ids carry the vendor prefix, '
                    . 'as in anthropic/claude-opus-5.',
            ],
            [
                'kind' => self::CLAUDE_CLI,
                'label' => 'Claude subscription (Pro / Max)',
                'base_url' => '',
                'needs_key' => false,
                'batch' => false,
                'hint' => 'Uses the Claude Code CLI already signed in on this server, so generation is billed '
                    . 'against a Claude Pro or Max plan instead of an API key. No key is stored by CourseForge.',
            ],
        ];
    }

    /** @return string[] */
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
     * The account's kind, inferred from the base URL when it predates 3.2.
     *
     * @param array<string,mixed> $account
     */
    public static function kindOf(array $account): string
    {
        $kind = strtolower(trim((string)($account['kind'] ?? '')));
        if ($kind !== '' && isset(self::CLASSES[$kind])) {
            return $kind;
        }
        return self::inferKind((string)($account['base_url'] ?? ''));
    }

    /** Host-based guess for accounts stored before the kind field existed. */
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
        return self::OPENAI;
    }
}
