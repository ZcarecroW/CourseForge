<?php
declare(strict_types=1);

/**
 * A refusal, a block and a truncation must raise - one case per adapter.
 *
 * This is the test the design brief asks for by name, and it exists because
 * every one of these failures arrives as an ordinary HTTP 200. A refusal from
 * Anthropic, a prompt Gemini blocked before the model saw it, an answer cut off
 * at the output cap, a rate limit two hops behind OpenRouter: all of them look
 * like success to anything that only reads the status code, and all of them
 * write a blank or half-finished course page if they are allowed through. An
 * exception is the cheap outcome here and silence is the expensive one.
 *
 * The truncation cases matter most, because they are the ones that arrive WITH
 * text. An adapter that returns whatever it was given stores half a lesson and
 * marks the page finished, which nobody notices until a reader reaches the
 * sentence that stops mid-word.
 *
 * Three of the four adapters are reached through their own public reader rather
 * than through chat(), because AnthropicProvider, GeminiProvider and
 * OpenRouterProvider are declared final and a test double cannot override the
 * already-protected HttpProvider::send() on a final class. The readers are
 * where the judgement is made and chat() is the three lines that raise on it;
 * dropping `final` from those three classes would let the same canned bodies be
 * driven end to end, which is what the brief describes.
 */

use CourseForge\Ai\AiRequest;
use CourseForge\Ai\Provider\AnthropicProvider;
use CourseForge\Ai\Provider\GeminiProvider;
use CourseForge\Ai\Provider\OpenAiCompatibleProvider;
use CourseForge\Ai\Provider\OpenRouterProvider;
use CourseForge\Support\HttpResult;

/**
 * The preset lane with one canned reply behind it.
 *
 * OpenAiCompatibleProvider is not final, so the seam is the one HttpProvider
 * already offers: send() is protected, and overriding it feeds the adapter a
 * whole response without a socket. This is the lane that serves OpenAI, Groq
 * and every preset, so it is worth driving through the real chat().
 */
final class CannedGateway extends OpenAiCompatibleProvider
{
    /** @param array<string,mixed> $reply */
    public function __construct(private readonly array $reply, private readonly int $status = 200)
    {
        parent::__construct(['base_url' => 'https://gateway.test/v1', 'api_key' => 'test-key']);
    }

    /** @param array<string,mixed>|null $payload */
    protected function send(string $method, string $path, ?array $payload, int $timeout): HttpResult
    {
        return new HttpResult($this->status, (string)json_encode($this->reply), $this->reply, '', 0);
    }
}

/** The same page request every gateway case sends; only the reply differs. */
function pageRequest(): AiRequest
{
    return new AiRequest('some-model', 'You write course pages.', 'Write about tides.');
}

test('preset lane: finish_reason=length WITH text raises rather than storing half a page', static function (): void {
    $gateway = new CannedGateway([
        'choices' => [['message' => ['content' => 'Tides are caused by'], 'finish_reason' => 'length']],
    ]);
    $e = raises(static fn(): string => $gateway->chat(pageRequest()), 'a truncated answer');
    ok(
        str_contains(strtolower($e->getMessage()), 'length') || str_contains(strtolower($e->getMessage()), 'cut off'),
        'the reason should name the output cap, got: ' . $e->getMessage()
    );
});

test('preset lane: finish_reason=content_filter WITH text raises', static function (): void {
    $gateway = new CannedGateway([
        'choices' => [['message' => ['content' => 'Partial answer.'], 'finish_reason' => 'content_filter']],
    ]);
    raises(static fn(): string => $gateway->chat(pageRequest()), 'a filtered answer');
});

test('preset lane: a top-level error at HTTP 200 raises', static function (): void {
    $gateway = new CannedGateway(['error' => ['message' => 'Rate limited upstream.', 'code' => 429]]);
    $e = raises(static fn(): string => $gateway->chat(pageRequest()), 'an error inside a 200');
    ok(str_contains($e->getMessage(), 'Rate limited upstream.'), 'the provider wording should survive');
});

test('preset lane: no text at all raises', static function (): void {
    $gateway = new CannedGateway(['choices' => [['message' => ['content' => ''], 'finish_reason' => 'length']]]);
    raises(static fn(): string => $gateway->chat(pageRequest()), 'an empty completion');
});

test('Anthropic: stop_reason refusal is a failure, not an empty page', static function (): void {
    $read = AnthropicProvider::readMessage([
        'stop_reason' => 'refusal',
        'content' => [['type' => 'text', 'text' => '']],
    ]);
    same('', $read['text'], 'a refusal carries no text');
    ok($read['problem'] !== '', 'a refusal must be reported as a problem');
});

test('Anthropic: stop_reason max_tokens discards the partial text', static function (): void {
    $read = AnthropicProvider::readMessage([
        'stop_reason' => 'max_tokens',
        'content' => [
            ['type' => 'thinking', 'thinking' => 'Planning the page.'],
            ['type' => 'text', 'text' => 'The Moon pulls the ocean towards'],
        ],
        'usage' => ['output_tokens' => 4096],
    ]);
    same('', $read['text'], 'a truncated answer must not be handed on as text');
    ok(str_contains($read['problem'], 'max_tokens'), 'the reason should name the stop reason');
});

test('Gemini: promptFeedback.blockReason with no candidates key is a failure', static function (): void {
    $why = GeminiProvider::rejection(['promptFeedback' => ['blockReason' => 'SAFETY']]);
    ok(str_contains($why, 'SAFETY'), 'the block reason should reach the operator, got: ' . $why);
});

test('Gemini: finishReason RECITATION is a failure', static function (): void {
    $why = GeminiProvider::rejection([
        'candidates' => [[
            'finishReason' => 'RECITATION',
            'content' => ['parts' => [['text' => 'The tides of the Bay of Fundy']]],
        ]],
    ]);
    ok(str_contains($why, 'RECITATION'), 'a recited answer must not be stored, got: ' . $why);
});

test('Gemini: finishReason MAX_TOKENS is a failure even with text', static function (): void {
    $why = GeminiProvider::rejection([
        'candidates' => [[
            'finishReason' => 'MAX_TOKENS',
            'content' => ['parts' => [['text' => 'Tides rise and fall because']]],
        ]],
    ]);
    ok(str_contains($why, 'MAX_TOKENS'), 'a truncated answer must not be stored, got: ' . $why);
});

test('Gemini: a normal stop with text is not a failure', static function (): void {
    $why = GeminiProvider::rejection([
        'candidates' => [[
            'finishReason' => 'STOP',
            'content' => ['parts' => [['thought' => true, 'text' => 'Thinking.'], ['text' => 'Tides rise and fall.']]],
        ]],
    ]);
    same('', $why, 'a good answer must not be rejected');
});

test('OpenRouter: a top-level error inside an HTTP 200 is a failure', static function (): void {
    $failure = OpenRouterProvider::completionFailure([
        'error' => ['code' => 429, 'message' => 'Provider returned error', 'metadata' => ['provider_code' => 'anthropic']],
    ]);
    ok($failure !== null, 'an error object at 200 must be caught');
    ok(
        str_contains(OpenRouterProvider::failureText($failure), 'Provider returned error'),
        'the upstream wording should survive'
    );
});

test('OpenRouter: finish_reason length is a failure even with text beside it', static function (): void {
    $failure = OpenRouterProvider::completionFailure([
        'choices' => [['message' => ['content' => 'Half a page'], 'finish_reason' => 'length']],
    ]);
    ok($failure !== null, 'a truncated answer must not be stored');
});

test('OpenRouter: an ordinary finished answer is not a failure', static function (): void {
    $failure = OpenRouterProvider::completionFailure([
        'choices' => [['message' => ['content' => 'A whole page.'], 'finish_reason' => 'stop']],
    ]);
    same(null, $failure, 'a good answer must not be rejected');
});
