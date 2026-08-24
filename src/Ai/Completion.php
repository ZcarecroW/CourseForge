<?php
declare(strict_types=1);

namespace CourseForge\Ai;

use CourseForge\Ai\Provider\Provider;
use CourseForge\Ai\Provider\Providers;
use CourseForge\Support\Config;
use CourseForge\Support\HttpException;

/**
 * One chat completion for one profile slot ("overview" or "page").
 *
 * Keeping the slot lookup here means the generators only deal with prompts,
 * never with accounts, models, providers or temperatures.
 *
 * A slot whose model carries the `:batch` suffix still answers live through
 * this class. That is deliberate: batching one request would mean waiting up to
 * a day for a single page. The suffix is honoured where it pays – the bulk run
 * in BatchRunner – and ignored when a person is sitting in the editor waiting
 * for one page to come back.
 */
final class Completion
{
    /** @param array<string,mixed> $profile */
    public static function run(array $profile, string $slot, string $system, string $user): string
    {
        return self::provider($profile, $slot)->chat(self::request($profile, $slot, $system, $user));
    }

    /** The request a slot would send, without sending it. @param array<string,mixed> $profile */
    public static function request(array $profile, string $slot, string $system, string $user): AiRequest
    {
        $config = self::modelConfig($profile, $slot);
        return new AiRequest(
            ModelId::base($config['model']),
            $system,
            $user,
            $config['temperature'],
            $config['max_tokens'],
        );
    }

    /** @param array<string,mixed> $profile */
    public static function provider(array $profile, string $slot): Provider
    {
        return Providers::fromProfile($profile, self::modelConfig($profile, $slot)['ai_id']);
    }

    /** True when this slot is configured to go through the provider's batch queue. */
    public static function isBatched(array $profile, string $slot): bool
    {
        return ModelId::isBatch(self::modelConfig($profile, $slot)['model']);
    }

    /**
     * @param array<string,mixed> $profile
     * @return array{ai_id:string,model:string,temperature:float,max_tokens:int}
     */
    public static function modelConfig(array $profile, string $slot): array
    {
        $config = (array)($profile['models'][$slot] ?? []);
        $aiId = (string)($config['ai_id'] ?? '');
        $model = trim((string)($config['model'] ?? ''));

        if ($aiId === '' || $model === '') {
            throw HttpException::unprocessable(
                'This profile has no AI account and model configured for "' . $slot . '". Open Profiles → Models to set one.'
            );
        }
        return [
            'ai_id' => $aiId,
            'model' => $model,
            'temperature' => (float)($config['temperature'] ?? 0.7),
            'max_tokens' => max(0, (int)($config['max_tokens'] ?? 0)),
        ];
    }

    /** @param array<string,mixed> $profile */
    public static function language(array $profile): string
    {
        $language = trim((string)($profile['language'] ?? ''));
        return $language !== '' ? $language : Config::str('app.default_language', 'English');
    }
}
