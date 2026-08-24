<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * The OpenRouter create body serialises as endpoint, model, requests.
 *
 * The design brief asks for this test by name, and the reason is not tidiness.
 * The beta endpoint stream-parses the body as it arrives, so `endpoint` and
 * `model` have to have been read by the time it reaches `requests`; send them
 * the other way round and a body that validates perfectly by eye comes back as
 * a 400 with nothing in it that points at the order. PHP preserves insertion
 * order through json_encode, which is what makes the driver's single array
 * literal correct - and also what makes a later array_merge(), array_filter()
 * or `+` able to move `requests` to the front without anything failing until a
 * real submission is refused.
 *
 * So the assertion is on the encoded JSON rather than on the array. An
 * array_keys() check would pass on a rebuilt array that encodes in the wrong
 * order, which is precisely the change this is here to catch.
 *
 * Reflection is used because body() is private and there is no public path to
 * it: OpenRouterInlineBatch is final, its constructor takes the equally final
 * OpenRouterProvider, and reaching the same literal through submitBatch() would
 * mean a socket. The literal is the whole of what is under test.
 */

use CourseForge\Ai\Batch\Driver\OpenRouterInlineBatch;

/** @return array<string,mixed> */
function openRouterBody(string $model): array
{
    $body = (new ReflectionMethod(OpenRouterInlineBatch::class, 'body'))->invoke(
        null,
        $model,
        [['custom_id' => 'cf-page-1', 'body' => ['model' => $model, 'messages' => []]]]
    );

    return is_array($body) ? $body : [];
}

test('OpenRouter batch body encodes endpoint, then model, then requests', static function (): void {
    $json = (string)json_encode(openRouterBody('anthropic/claude-opus-5'), JSON_UNESCAPED_SLASHES);

    ok(
        str_starts_with($json, '{"endpoint":"/v1/chat/completions","model":"anthropic/claude-opus-5","requests":['),
        'the encoded body must open with the three keys in that order, got: ' . substr($json, 0, 120)
    );
});

test('OpenRouter batch body never puts requests in front of endpoint or model', static function (): void {
    $json = (string)json_encode(openRouterBody('openai/gpt-5.6-sol'), JSON_UNESCAPED_SLASHES);

    $endpoint = strpos($json, '"endpoint"');
    $model = strpos($json, '"model"');
    $requests = strpos($json, '"requests"');

    // Position 1, not 0: the opening brace is byte zero.
    same(1, $endpoint, 'endpoint must be the first key');
    ok(is_int($model) && is_int($endpoint) && $model > $endpoint, 'model must follow endpoint');
    ok(is_int($requests) && is_int($model) && $requests > $model, 'requests must come last of the three');
});

test('OpenRouter batch body carries the endpoint field the queue expects', static function (): void {
    $body = openRouterBody('anthropic/claude-opus-5');

    same(OpenRouterInlineBatch::ENDPOINT, $body['endpoint'] ?? null, 'the endpoint field');
    same('anthropic/claude-opus-5', $body['model'] ?? null, 'the batch-wide model');
    $requests = $body['requests'] ?? null;
    ok(is_array($requests) && array_is_list($requests), 'requests must encode as a JSON array, not an object');
});
