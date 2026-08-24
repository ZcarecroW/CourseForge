<?php
declare(strict_types=1);

namespace CourseForge\Ai\Provider;

use CourseForge\Ai\AiRequest;
use CourseForge\Ai\Batch\BatchHandle;
use CourseForge\Ai\Batch\BatchItemRequest;
use CourseForge\Ai\Batch\BatchItemResult;
use CourseForge\Ai\Batch\BatchLimits;
use CourseForge\Ai\Batch\BatchStatus;
use CourseForge\Ai\Batch\Driver\GeminiLroBatch;
use CourseForge\Support\HttpException;
use CourseForge\Support\HttpResult;
use CourseForge\Support\Runtime;
use CourseForge\Support\Text;
use RuntimeException;

/**
 * Google's Gemini Developer API, the native `generateContent` surface.
 *
 * Nothing about this endpoint is OpenAI-shaped, which is why it gets a class
 * rather than a preset. The model id lives in the URL path instead of the body,
 * authentication is an `x-goog-api-key` header, the conversation is
 * `contents[].parts[]` with the roles `user` and `model`, there is no `system`
 * role at all - the system prompt is a top-level `systemInstruction` object -
 * and everything to do with sampling or length is nested inside
 * `generationConfig` under different names. The answer comes back in
 * `candidates[]`, not `choices[]`.
 *
 * The thing worth reading this file for, though, is how it fails. Gemini
 * reports most of its refusals at HTTP 200: a prompt the safety layer rejected
 * comes back as a 200 with no `candidates` key whatsoever and a
 * `promptFeedback.blockReason`, and an answer that ran into the output cap or
 * that the model stopped reciting comes back as a 200 with a `finishReason` of
 * MAX_TOKENS or RECITATION and text that is missing or cut off mid-sentence.
 * RECITATION in particular is a real and frequent outcome for long-form
 * educational prose, which is precisely what CourseForge asks for. Every one of
 * those paths throws here. A course page that is blank, or that stops halfway
 * through a sentence, is a far worse outcome than a run that stopped and said
 * why, and a caller cannot tell the two apart from an empty string.
 *
 * WARNING, dated 2026-08-24 - Google is in the middle of retiring API keys and
 * this adapter ships during the changeover. Unrestricted standard keys are
 * already rejected, and in September 2026 - weeks from the date above - the
 * Gemini API stops accepting standard keys at all. Their replacement is the
 * service-account-bound "auth key" with an `AQ.` prefix, and there is an open,
 * unresolved cluster of reports of those keys answering 401
 * ACCESS_TOKEN_TYPE_UNSUPPORTED on `generateContent` through both
 * `x-goog-api-key` and the legacy `?key=` form. Because of that, this class
 * never collapses a Gemini 4xx into "authentication failed": describeFailure()
 * hands the operator Google's own error envelope verbatim - status, numeric
 * code, every `reason` in `details[]` and the raw body - because during this
 * window that envelope is the only thing that distinguishes a wrong key from a
 * key type the endpoint will not take. If this code looks over-careful about
 * error text in three months' time, that is why, and the caution can be
 * removed once the migration has settled.
 *
 * It is offered in the account picker as beta for the same reason.
 */
final class GeminiProvider extends HttpProvider implements BatchCapable
{
    /** The account kind stored in a profile, and the key Providers maps to this class. */
    public const KIND = 'gemini';

    private const LABEL = 'Google Gemini';

    /**
     * The models the Batch API accepts.
     *
     * A hardcoded list, and unavoidably so: `GET /v1beta/models` says nothing
     * about batch support. That fact lives only on the per-model documentation
     * pages, so there is no runtime source to read and this is seeded from the
     * design brief instead. It is intersected with the live listing whenever
     * models() has been called, so a model the account cannot see never reaches
     * the picker, and matching is by prefix so a dated or "-latest" variant of
     * a listed family still counts.
     */
    private const BATCH_MODELS = [
        'gemini-3.7-flash',
        'gemini-3.6-flash',
        'gemini-3.5-flash',
        'gemini-3.5-flash-lite',
        'gemini-3.1-pro-preview',
        'gemini-2.5-pro',
    ];

    /**
     * The inline submission ceiling. The alternative 2 GB file lane is not
     * built - see GeminiLroBatch for why the resumable upload it requires
     * cannot be driven through Support\Http.
     */
    private const INLINE_MAX_BYTES = 20 * BatchLimits::MEGABYTE;

    /**
     * Google publishes no per-batch request count, so this number is derived
     * rather than quoted. The bound that binds is the 20 MB inline body, and a
     * CourseForge page prompt carries the whole course context with it and runs
     * to roughly 8 KB once it is JSON-encoded - which is where 2,500 comes
     * from, by the same arithmetic the design brief uses for OpenAI's 200 MB.
     * The exact test is still made on bytes at submit time; this number exists
     * so the run form can warn a person before they select a thousand pages too
     * many rather than after the prompts have all been built.
     */
    private const BATCH_MAX_REQUESTS = 2500;

    /**
     * The per-model "batch enqueued tokens" quota, summed across every active
     * batch job on the project. Tier 1 gives the flash models 3,000,000 and is
     * more generous for the pro and lite models; the lowest of them is used
     * here because batchLimits() is not told which model is about to be sent
     * and a quota quoted too high is one nobody plans around.
     */
    private const BATCH_ENQUEUED_TOKENS = 3000000;

    /** @var string[] the batch-capable subset, filled as a side effect of models() */
    private array $batchModels = [];

    private bool $listed = false;

    /** @var array<string,true> deprecation notices already logged this process */
    private static array $noticed = [];

    public static function defaultBaseUrl(): string
    {
        return 'https://generativelanguage.googleapis.com/v1beta';
    }

    public function kind(): string
    {
        return self::KIND;
    }

    public function label(): string
    {
        return self::LABEL;
    }

    /**
     * The row the Profiles picker should offer.
     *
     * It lives here rather than being written out inside Providers::catalogue()
     * because the beta warning and the account form belong to the same fact,
     * and a warning kept in a different file from the code it warns about goes
     * stale first.
     *
     * @return array{kind:string,label:string,base_url:string,needs_key:bool,batch:bool,beta:bool,hint:string}
     */
    public static function catalogueEntry(): array
    {
        return [
            'kind' => self::KIND,
            'label' => 'Google Gemini (beta)',
            'base_url' => self::defaultBaseUrl(),
            'needs_key' => true,
            'batch' => true,
            'beta' => true,
            'hint' => 'The native Gemini Developer API with an AI Studio key. Marked beta because Google is '
                . 'retiring standard API keys in September 2026 and the auth keys that replace them are still '
                . 'being reported as refused on this endpoint - if a call fails, read the reply CourseForge '
                . 'shows you word for word before changing anything. The batch queue halves the price but is '
                . 'paid-tier only, and a batch that has not finished within 48 hours expires with no results '
                . 'at all.',
        ];
    }

    public function supportsBatch(): bool
    {
        // True for the endpoint, which is all this can answer. The queue itself
        // is paid-tier only: a free-tier key is refused at submit, and that
        // refusal reaches the operator with Google's own wording rather than a
        // guess made here.
        return true;
    }

    /**
     * No request-count cap is published, a 20 MB inline body, a per-model
     * enqueued-token quota, a 24 hour target and results kept for six weeks.
     *
     * The window is the one number here that is a promise rather than a limit,
     * and Gemini's is softer than everyone else's: 24 hours is a stated target,
     * while the hard fact is the 48 hour expiry that returns nothing.
     */
    public function batchLimits(): BatchLimits
    {
        return new BatchLimits(
            self::BATCH_MAX_REQUESTS,
            self::INLINE_MAX_BYTES,
            self::BATCH_ENQUEUED_TOKENS,
            '24h',
            GeminiLroBatch::RETENTION_DAYS,
        );
    }

    /**
     * Every path is anchored on a version segment, so the base URL is
     * normalised to end in exactly one `/v1beta` however it was typed.
     *
     * `v1beta` and not `v1`: the Batch API and explicit caching exist only
     * there, and a base URL pointing at `/v1` would answer 404 for half of what
     * this adapter does.
     */
    protected function normaliseBaseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '') {
            return '';
        }
        $url = (string)preg_replace('#/v1(beta\d*)?$#i', '', $url);
        return rtrim($url, '/') . '/v1beta';
    }

    /**
     * The complete header set, rebuilt for every single request.
     *
     * Completely, and never merged into whatever a previous call left behind.
     * Sending `Authorization: Bearer` to this host returns 401
     * ACCESS_TOKEN_TYPE_UNSUPPORTED even when a perfectly valid
     * `x-goog-api-key` is present alongside it - the Authorization header wins
     * and is parsed as an OAuth token - and the error text it produces points
     * nowhere near the cause. A leftover Authorization header from the OpenAI
     * or Anthropic path is therefore all it takes to make Gemini look broken,
     * which is why Support\Http is handed a fresh, complete header list on
     * every call and no cURL handle is ever reused across providers.
     *
     * @return array<string,string>
     */
    protected function headers(): array
    {
        return self::authHeaders($this->apiKey);
    }

    /** @return array<string,string> */
    public static function authHeaders(string $apiKey): array
    {
        return [
            'x-goog-api-key' => $apiKey,
            'Accept' => 'application/json',
        ];
    }

    /**
     * Every failed call, reported as Google phrased it.
     *
     * HttpProvider turns a 401 or 403 into "check the API key and its
     * permissions", which is the single most useless sentence anyone could be
     * given during the key migration described at the top of this file - it is
     * the same sentence for a revoked key, for a key of a type this endpoint no
     * longer accepts, and for a project without the API enabled. The raw
     * envelope separates them and nothing else does.
     */
    protected function assertOk(HttpResult $res, string $what, string $url = ''): void
    {
        if ($res->ok()) {
            return;
        }
        throw HttpException::badRequest(self::describeFailure($res, $what, $url, $this->apiKey));
    }

    /* --------------------------------------------------------------- models */

    /** @return string[] */
    public function models(): array
    {
        $this->assertConfigured();

        $ids = [];
        $batch = [];
        $token = '';

        // 1000 is the documented maximum page size; the loop bound is a guard
        // against a nextPageToken that never empties, not an expected count.
        for ($page = 0; $page < 20; $page++) {
            $path = '/models?pageSize=1000' . ($token !== '' ? '&pageToken=' . rawurlencode($token) : '');
            $res = $this->send('GET', $path, null, $this->metaTimeout());
            $this->assertOk($res, 'the model list', $this->url($path));
            $this->assertJson($res, 'the model list');

            $data = is_array($res->data) ? $res->data : [];
            foreach ((array)($data['models'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                // Every name is returned fully qualified as models/gemini-x.
                // The picker and the URL path both want the bare id.
                $name = trim((string)($item['name'] ?? ''));
                $id = str_starts_with($name, 'models/') ? substr($name, 7) : $name;
                if ($id === '') {
                    continue;
                }

                // The REST field is supportedGenerationMethods. The Python and
                // Go SDKs surface the same data as supported_actions, which
                // does not exist in the raw JSON - looking for it finds
                // nothing and quietly empties the picker.
                $methods = array_map('strval', (array)($item['supportedGenerationMethods'] ?? []));
                if (!in_array('generateContent', $methods, true)) {
                    continue;
                }

                $ids[] = $id;
                if (self::batchAccepts($id)) {
                    $batch[] = $id;
                }
            }

            $token = trim((string)($data['nextPageToken'] ?? ''));
            if ($token === '') {
                break;
            }
        }

        if ($ids === []) {
            throw HttpException::badRequest(
                self::LABEL . ' answered, but listed no model that can generate content. '
                . 'Check that the key belongs to a project with the Generative Language API enabled.'
            );
        }

        sort($batch, SORT_NATURAL | SORT_FLAG_CASE);
        $this->batchModels = array_values(array_unique($batch));
        $this->listed = true;

        return self::collectModelIds($ids);
    }

    /**
     * @return string[] the allowlist, narrowed to what this account can see
     *                  once models() has been called
     */
    public function batchModels(): array
    {
        // Before the listing has been fetched there is nothing to narrow, and
        // returning an empty array would be read as "the provider did not say".
        // Here the provider is the only thing that ever says, so the seed is
        // returned instead.
        return $this->listed ? $this->batchModels : self::BATCH_MODELS;
    }

    /* ----------------------------------------------------------------- chat */

    public function chat(AiRequest $request): string
    {
        $this->assertConfigured();

        $model = self::modelSegment($request->model);
        $path = '/models/' . $model . ':generateContent';

        $res = $this->send('POST', $path, $this->payload($request), $this->chatTimeout());
        $this->assertOk($res, 'the completion', $this->url($path));
        $this->assertJson($res, 'the completion');

        $data = is_array($res->data) ? $res->data : [];

        // Free deprecation telemetry: every response may carry the model's own
        // lifecycle stage and retirement date. Reading it here is cheaper and
        // far more timely than watching a documentation page.
        self::noteModelStatus($data, $model);

        // The whole point of this adapter. Gemini answers HTTP 200 to a blocked
        // prompt, to an answer cut off at the output cap and to one stopped for
        // reciting training data, and each of those would otherwise be stored
        // as a blank or half-written page with nothing to explain it.
        $why = self::rejection($data);
        if ($why !== '') {
            throw HttpException::badRequest(self::LABEL . ': ' . $why);
        }

        return self::readText($data);
    }

    /* ---------------------------------------------------------------- batch */

    /** @param array<int,BatchItemRequest> $items */
    public function submitBatch(array $items): BatchHandle
    {
        $this->assertConfigured();
        if ($items === []) {
            throw HttpException::unprocessable('There is nothing to submit.');
        }

        $model = '';
        $seen = [];
        $rows = [];

        foreach ($items as $item) {
            $id = trim($item->customId);
            self::assertCustomId($id);
            if (isset($seen[$id])) {
                throw HttpException::unprocessable(
                    'Two requests in this batch share the id "' . $id . '", and the answers are matched back by '
                    . 'nothing else.'
                );
            }
            $seen[$id] = true;

            // One batch, one model, because the model is in the URL rather than
            // in each request. Every other queue would accept a mixed
            // submission; this one has nowhere to put the second model.
            $segment = self::modelSegment($item->request->model);
            if ($model === '') {
                $model = $segment;
            } elseif ($model !== $segment) {
                throw HttpException::unprocessable(
                    self::LABEL . ' addresses a batch by model, so every request in one submission has to use the '
                    . 'same model - this one mixes "' . $model . '" with "' . $segment . '".'
                );
            }

            $rows[] = ['key' => $id, 'request' => $this->payload($item->request)];
        }

        // No count check here on purpose: Gemini publishes no request cap, and
        // the bound that decides is the 20 MB inline body, which the driver
        // measures exactly once the bodies have been built.
        return $this->batch()->submit($model, $rows);
    }

    public function pollBatch(BatchHandle $handle): BatchStatus
    {
        $this->assertConfigured();
        return $this->batch()->poll($handle);
    }

    /** @return iterable<string,BatchItemResult> */
    public function fetchBatchResults(BatchHandle $handle): iterable
    {
        $this->assertConfigured();
        return $this->batch()->fetch($handle);
    }

    public function canCancel(): bool
    {
        return true;
    }

    public function cancelBatch(BatchHandle $handle): bool
    {
        $this->assertConfigured();
        return $this->batch()->cancel($handle);
    }

    public function releaseBatch(BatchHandle $handle): void
    {
        $this->assertConfigured();
        $this->batch()->release($handle);
    }

    /**
     * The long-running-operation driver, built fresh per call.
     *
     * It is not a stored property because it holds no state worth keeping
     * between calls and because a provider is routinely constructed for a
     * single question about models. The timeouts differ by what is being done:
     * submitting, polling and cancelling are quick control-plane calls, while
     * downloading a results file is on the same footing as a generation.
     */
    private function batch(): GeminiLroBatch
    {
        return new GeminiLroBatch(
            $this->baseUrl,
            $this->apiKey,
            self::INLINE_MAX_BYTES,
            $this->metaTimeout(),
            $this->chatTimeout(),
        );
    }

    /* ------------------------------------------------- the wire body, shared */

    /**
     * One GenerateContentRequest, as sent live and as embedded in a batch.
     *
     * Live and queued generation share this deliberately. A batch line is
     * byte-identical to the body of a live call, and the moment the two are
     * built in different places they start to differ in ways that only show up
     * a day later in half a course.
     *
     * @return array<string,mixed>
     */
    private function payload(AiRequest $request): array
    {
        $payload = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $request->user]]],
            ],
        ];

        if (trim($request->system) !== '') {
            // A Content object with parts, not a string, and it takes no role.
            // There is no system role in contents[] to put this in.
            $payload['systemInstruction'] = ['parts' => [['text' => $request->system]]];
        }

        $config = [];
        if ($request->maxTokens > 0) {
            // Optional here, unlike Anthropic: omitting it means the model's own
            // output limit, which is 65,536 tokens across the Gemini 3 family
            // and more room than a course page needs.
            $config['maxOutputTokens'] = $request->maxTokens;
        }
        if (self::acceptsSampling($request->model)) {
            $config['temperature'] = max(0.0, min(2.0, $request->temperature));
        }
        if ($config !== []) {
            $payload['generationConfig'] = $config;
        }

        // Three things are deliberately absent. `candidateCount` is unsupported
        // on every Gemini 3 model. `safetySettings` are off by default on 2.5
        // and 3, so sending them can only make the filters stricter than they
        // already are. `thinkingConfig` is spelled thinkingLevel on 3 and
        // thinkingBudget on 2.5, each an error on the other generation, and the
        // per-model default is the right setting for prose.

        return $payload;
    }

    /**
     * Whether this model still honours `temperature`.
     *
     * Sampling was deprecated across the board on 2026-07-21. Gemini 3.6 and
     * 3.7 ignore or reject `temperature`, `topP` and `topK` outright, and on
     * the 3.x models that still accept them a temperature below 1.0 is
     * documented as causing looping and degraded output - so the slider is
     * suppressed for the whole generation rather than passed through and hoped
     * for. An id this cannot parse is treated as new, because omitting the
     * parameter has never been an error anywhere and sending it now is.
     */
    private static function acceptsSampling(string $model): bool
    {
        if (preg_match('/gemini-(\d+)/i', $model, $match) === 1) {
            return (int)$match[1] < 3;
        }
        return false;
    }

    /**
     * The model id as it goes into the URL path.
     *
     * Not rawurlencode: model ids are path segments here, and encoding one is
     * how a slash-bearing id becomes a 404 elsewhere in this codebase. A
     * character set is checked instead, which is the safe half of the same
     * concern - nothing reaches the URL that could climb out of the segment.
     */
    private static function modelSegment(string $model): string
    {
        $model = trim($model);
        if ($model === '') {
            throw HttpException::unprocessable('No model is selected for this request.');
        }
        // The model list writes every name as models/gemini-3.7-flash, and that
        // is what people paste back in.
        if (str_starts_with($model, 'models/')) {
            $model = substr($model, 7);
        }
        if (preg_match('#^[A-Za-z0-9._-]+$#', $model) !== 1) {
            throw HttpException::unprocessable(
                '"' . $model . '" is not a ' . self::LABEL . ' model id. It should look like gemini-3.7-flash.'
            );
        }
        return $model;
    }

    /** Whether the hardcoded batch allowlist covers this id. */
    private static function batchAccepts(string $model): bool
    {
        $model = strtolower(trim($model));
        foreach (self::BATCH_MODELS as $allowed) {
            if ($model === $allowed || str_starts_with($model, $allowed . '-')) {
                return true;
            }
        }
        return false;
    }

    private static function assertCustomId(string $id): void
    {
        // Google documents no character set for metadata.key, so the house rule
        // applies: an id has to satisfy every provider at once, and Anthropic's
        // is the narrowest. The value is echoed back verbatim and is the only
        // thing that matches an answer to a page.
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) !== 1) {
            throw HttpException::unprocessable(
                'A batch id must be 1-64 letters, digits, hyphens or underscores - got "' . $id . '".'
            );
        }
    }

    /* ------------------------------------------------- reading a 200 that failed */

    /**
     * The answer text out of one GenerateContentResponse.
     *
     * `parts` is an array of typed blocks and not a text field with extra
     * decoration. Thought summaries arrive in that same array flagged
     * `"thought": true`, so on a thinking model `parts[0]` is the reasoning and
     * not the answer; blocks carrying inline data or a function call have no
     * `text` key at all. Every part that is not plain answer text is skipped
     * rather than rejected, because the union grows with each release.
     *
     * Only the first candidate is read, which is not a guess: `candidateCount`
     * is unsupported on every Gemini 3 model, so there is exactly one.
     *
     * @param array<string,mixed> $response
     */
    public static function readText(array $response): string
    {
        $parts = $response['candidates'][0]['content']['parts'] ?? null;
        if (!is_array($parts)) {
            return '';
        }

        $out = [];
        foreach ($parts as $part) {
            if (!is_array($part) || !empty($part['thought']) || !is_string($part['text'] ?? null)) {
                continue;
            }
            $out[] = $part['text'];
        }
        return trim(implode('', $out));
    }

    /**
     * Why this response must not be written to a page, or '' when it may.
     *
     * The one function that both the live path and the batch path ask, because
     * a batch line is the same GenerateContentResponse the live call returns
     * and the two must not disagree about what counts as an answer.
     *
     * Three failures hide behind HTTP 200 and each is checked here. A prompt
     * the safety layer rejected produces no `candidates` key at all, which is
     * documented behaviour and the reason `candidates[0]` can never be assumed
     * to exist. A `finishReason` other than STOP means the text is partial: at
     * the output cap the page stops mid-sentence, and under RECITATION the
     * model stopped because the answer was tracking its training data too
     * closely - a genuine and common outcome for long educational prose, which
     * is exactly the workload here. Whatever text arrived alongside those is
     * discarded on purpose; half a page stored as though it were whole is the
     * failure this adapter exists to prevent.
     *
     * @param array<string,mixed> $response
     */
    public static function rejection(array $response): string
    {
        $candidates = $response['candidates'] ?? null;

        if (!is_array($candidates) || $candidates === []) {
            $reason = strtoupper(trim((string)($response['promptFeedback']['blockReason'] ?? '')));
            $detail = trim((string)($response['promptFeedback']['blockReasonMessage'] ?? ''));
            if ($reason !== '') {
                return 'the prompt was refused before the model saw it (blockReason=' . $reason . ')'
                    . ($detail !== '' ? ' - ' . mb_substr($detail, 0, 200) : '')
                    . '. Nothing was generated and nothing was billed. Rephrase the page brief or the course '
                    . 'context and run it again.';
            }
            return 'the reply contained no candidates at all, which the API documents as a rejected prompt, '
                . 'and no blockReason came with it. Raw reply: ' . self::snippetOf($response);
        }

        $candidate = is_array($candidates[0] ?? null) ? $candidates[0] : [];
        $finish = strtoupper(trim((string)($candidate['finishReason'] ?? '')));
        $text = self::readText($response);

        if ($finish !== '' && $finish !== 'STOP' && $finish !== 'STOP_SEQUENCE') {
            return match ($finish) {
                'MAX_TOKENS' => 'the answer ran into the output limit (finishReason=MAX_TOKENS), so the page '
                    . 'would have been stored cut off mid-sentence. Raise "Max tokens" for this slot, or set it '
                    . 'to 0 to use the model\'s own ceiling of 65,536 tokens. Thinking tokens are spent from the '
                    . 'same budget, which is how a low limit produces no text whatsoever.',
                'RECITATION' => 'the model stopped because the answer was reproducing its training data too '
                    . 'closely (finishReason=RECITATION). This is a common outcome for long-form educational '
                    . 'writing rather than a sign of anything wrong: reword the page brief so it asks for the '
                    . 'material in your own framing, or split the page into smaller sections and run it again.',
                'SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'IMAGE_SAFETY', 'SPII' =>
                    'the answer was withheld by the safety filters (finishReason=' . $finish . '). Blocked '
                    . 'content is never returned, so there is nothing to salvage from this response; the '
                    . 'per-category ratings are in candidates[0].safetyRatings.',
                default => 'the model stopped for a reason CourseForge does not recognise (finishReason='
                    . $finish . '). Anything other than STOP means the page is incomplete, so it was not '
                    . 'stored. Raw reply: ' . self::snippetOf($response),
            };
        }

        if ($text === '') {
            return 'the model reported a normal stop and returned no text at all. Raw reply: '
                . self::snippetOf($response);
        }

        return '';
    }

    /**
     * Google's own words about a failed call, kept whole.
     *
     * Static, and shared with the batch driver, so that the two never describe
     * the same 401 differently. The summary line ahead of the raw body is a
     * convenience; the raw body is the evidence, and it is what the migration
     * warning at the top of this file asks the operator to read.
     *
     * The key is passed in only to be removed: the legacy `?key=` form of this
     * API still routes, so a URL echoed back inside an error body is a real
     * place for a credential to end up in a log or on a screen.
     */
    public static function describeFailure(HttpResult $res, string $what, string $url, string $apiKey = ''): string
    {
        $where = $url !== '' ? ' (' . self::redact($url, $apiKey) . ')' : '';

        if ($res->unreachable()) {
            return self::LABEL . ': could not reach the endpoint' . $where . ' - ' . $res->error . '.';
        }
        if ($res->truncated()) {
            return self::LABEL . ': the connection dropped part way through ' . $what . $where
                . ' - ' . $res->error . '. Nothing was stored; try again.';
        }
        if ($res->status >= 300 && $res->status < 400) {
            return self::LABEL . ': the endpoint redirected' . $where . ' (HTTP ' . $res->status . '). '
                . 'The key travels in a header that cURL would replay to wherever the redirect points, so '
                . 'redirects are not followed here. Point the base URL at the address it redirects to.';
        }

        $data = is_array($res->data) ? $res->data : [];
        $error = is_array($data['error'] ?? null) ? $data['error'] : [];

        $status = trim((string)($error['status'] ?? ''));
        $code = $error['code'] ?? null;
        $message = trim((string)($error['message'] ?? ''));
        $reasons = self::reasons($error);

        $head = self::LABEL . ': ' . $what . ' failed (HTTP ' . $res->status
            . ($status !== '' ? ' ' . $status : '')
            . (is_numeric($code) ? ', code ' . (int)$code : '')
            . ($reasons !== '' ? ', reason ' . $reasons : '')
            . ')' . $where . '.';

        return $head
            . ($message !== '' ? ' ' . self::redact(mb_substr($message, 0, 400), $apiKey) : '')
            . self::migrationNote($res->status, $apiKey)
            . ' Google\'s reply, word for word: '
            . self::redact(Text::snippet($res->raw !== '' ? $res->raw : $res->error, 700), $apiKey);
    }

    /* ------------------------------------------------------------ internals */

    /**
     * Every `reason` inside `error.details[]`, comma separated.
     *
     * This is where the answer usually is. A 401 whose message is "Expected
     * OAuth 2 access token" carries the reason ACCESS_TOKEN_TYPE_UNSUPPORTED,
     * and the reason names the actual problem while the message points at
     * something the caller never sent.
     *
     * @param array<string,mixed> $error
     */
    private static function reasons(array $error): string
    {
        $found = [];
        foreach ((array)($error['details'] ?? []) as $detail) {
            if (!is_array($detail)) {
                continue;
            }
            $reason = trim((string)($detail['reason'] ?? ''));
            if ($reason !== '' && !in_array($reason, $found, true)) {
                $found[] = $reason;
            }
        }
        return implode(', ', array_slice($found, 0, 4));
    }

    /**
     * The one piece of interpretation this adapter allows itself on a 4xx, and
     * it interprets nothing away: it adds the dated context an operator needs
     * and then defers to the raw reply above it.
     */
    private static function migrationNote(int $status, string $apiKey): string
    {
        if ($status !== 401 && $status !== 403) {
            return '';
        }

        // Only the documented shape of the key is named here, never any part of
        // its value.
        $form = match (true) {
            str_starts_with($apiKey, 'AQ.') => 'The account holds one of the new service-account-bound auth keys.',
            str_starts_with($apiKey, 'AIza') => 'The account holds a standard API key, which is the form being '
                . 'retired - a new project needs an auth key from AI Studio instead.',
            default => 'The key stored for this account is in neither of the two documented forms.',
        };

        return ' Google is retiring API keys as this is read: unrestricted standard keys are already refused, '
            . 'standard keys stop working in September 2026, and the auth keys replacing them have an open '
            . 'cluster of reports of answering exactly this way on generateContent. ' . $form
            . ' Read the reply below before changing anything - it is the only thing that tells a wrong key '
            . 'apart from a key type this endpoint will not take.';
    }

    /** Removes the key, and the legacy query form of it, from anything shown to a person. */
    private static function redact(string $text, string $apiKey): string
    {
        if (strlen($apiKey) >= 8) {
            $text = str_replace($apiKey, '[key removed]', $text);
        }
        return (string)preg_replace('/([?&]key=)[^&\s"]+/i', '$1[removed]', $text);
    }

    /**
     * Writes the model's own deprecation signal to the log, once per process.
     *
     * Every response may carry `modelStatus` with a lifecycle stage and a
     * retirement date. It costs nothing to read and it is the earliest warning
     * available that a model a profile depends on is going away - considerably
     * earlier, and more reliable, than noticing a documentation page changed.
     * The batch driver reads it from finished batch lines for the same reason,
     * which is why this is public: a queued run of several hundred pages is
     * exactly the job that would otherwise discover a retirement by failing.
     *
     * Runtime::log is the only log channel here and it takes a Throwable, so
     * the notice is wrapped in one. It is wrapped rather than thrown because a
     * deprecation warning must never fail a page that generated perfectly well.
     * The record is deduplicated per model and stage so that a five-hundred-page
     * run contributes one line rather than five hundred.
     *
     * @param array<string,mixed> $response
     */
    public static function noteModelStatus(array $response, string $model): void
    {
        $status = is_array($response['modelStatus'] ?? null) ? $response['modelStatus'] : [];
        if ($status === []) {
            return;
        }

        $stage = strtoupper(trim((string)($status['modelStage'] ?? '')));
        $stage = (string)preg_replace('/^MODEL_STAGE_/', '', $stage);
        $retires = trim((string)($status['retirementTime'] ?? ''));
        $message = trim((string)($status['message'] ?? ''));

        // STABLE is the answer for a healthy model and is not worth a line.
        if (($stage === '' || $stage === 'STABLE' || $stage === 'UNSPECIFIED') && $retires === '') {
            return;
        }

        $key = $model . '|' . $stage . '|' . $retires;
        if (isset(self::$noticed[$key])) {
            return;
        }
        self::$noticed[$key] = true;

        Runtime::log('gemini.model-status', new RuntimeException(
            $model . ' is reported by Google as ' . ($stage !== '' ? $stage : 'not stable')
            . ($retires !== '' ? ', retiring ' . $retires : '')
            . ($message !== '' ? ' - ' . mb_substr($message, 0, 300) : '') . '.'
        ));
    }

    /** @param array<string,mixed> $response */
    private static function snippetOf(array $response): string
    {
        return Text::snippet(
            (string)(json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            400
        );
    }
}
