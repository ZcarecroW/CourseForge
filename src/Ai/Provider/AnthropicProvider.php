<?php
declare(strict_types=1);

namespace CourseForge\Ai\Provider;

use CourseForge\Ai\AiRequest;
use CourseForge\Ai\Batch\BatchHandle;
use CourseForge\Ai\Batch\BatchItemRequest;
use CourseForge\Ai\Batch\BatchItemResult;
use CourseForge\Ai\Batch\BatchLimits;
use CourseForge\Ai\Batch\BatchStatus;
use CourseForge\Ai\Batch\Driver\AnthropicInlineBatch;
use CourseForge\Support\Config;
use CourseForge\Support\HttpException;
use CourseForge\Support\Meta;
use CourseForge\Support\Runtime;
use CourseForge\Support\Text;
use Throwable;

/**
 * The native Anthropic Messages API.
 *
 * Four things make it genuinely different from an OpenAI-compatible endpoint,
 * and all four are handled here so the generators never see them:
 *
 *   - the system prompt is a top-level `system` field, not a message,
 *   - `max_tokens` is required and has no server-side default,
 *   - the answer is an array of typed blocks, and on the current models a
 *     `thinking` block arrives before the text one, so `content[0]` is the
 *     wrong thing to read,
 *   - the newest models reject any non-default `temperature` with a 400, and
 *     the ceiling on the models that do accept one is 1.0, not OpenAI's 2.0.
 *
 * Which model is in which of those camps is not decided here by name. The
 * models endpoint reports a `capabilities` object per model, and every gate in
 * this class is read out of it: whether the queue takes this model, how large
 * an output it will produce, and - by way of which kind of thinking it still
 * offers - whether a sampling parameter is legal. A list of model ids written
 * today is wrong within a release or two, and the way it fails is silent: a
 * model that quietly stops accepting `temperature` turns every page of a course
 * into a 400. The capability shape has outlived several generations of model
 * ids, so that is what is read, and the listing is cached for a day so the cost
 * of reading it is a database row rather than a request.
 *
 * The other thing this class refuses to do is return a bad page. Anthropic
 * reports two failures at HTTP 200 - a refusal, and an answer cut off at the
 * output ceiling - and both used to arrive in CourseForge as an empty or
 * half-written page with nothing to say why. Every path that produces text goes
 * through readMessage(), which throws instead.
 *
 * The Message Batches queue answers the same prompts at half price within 24
 * hours, and the discount stacks with the prompt cache. The queue protocol
 * lives in AnthropicInlineBatch; what a request body looks like lives here.
 */
final class AnthropicProvider extends HttpProvider implements BatchCapable, SearchCapable
{
    /** Required on every call, and unchanged since the API launched. */
    private const VERSION = '2023-06-01';

    /** How long a fetched model listing stands before it is read again. */
    private const CATALOGUE_SECONDS = 86400;

    /**
     * The emergency seed: what an install with no route to /v1/models may pick.
     *
     * Three model ids, and deliberately not a catalogue. It is never merged
     * into a listing, never remembered as one, and never consulted by anything
     * in this class that gates a parameter - a model that came from here is a
     * model nothing is known about, which is the literal truth. The listing is
     * the source of truth everywhere else; this exists because the alternative
     * is a dead end, where an install that cannot reach the endpoint cannot
     * pick a model, so cannot save an account, so can never be told what is
     * wrong with it.
     */
    private const SEED = ['claude-haiku-4-5', 'claude-opus-5', 'claude-sonnet-5'];

    /** Whether the last models() call actually spoke to the endpoint. */
    private bool $reachedEndpoint = true;

    /** Why it did not, when it did not. */
    private string $whyNot = '';

    /**
     * Whether the model list just returned came from the endpoint or from SEED.
     *
     * @return array{reached:bool,why:string}
     */
    public function lastReach(): array
    {
        return ['reached' => $this->reachedEndpoint, 'why' => $this->whyNot];
    }

    /**
     * The shortest system prompt worth putting a cache breakpoint on.
     *
     * Roughly 500 tokens of English, which is the smallest cacheable prefix any
     * current model has; the older ones want up to 4,096. Below the model's own
     * minimum a breakpoint is not an error, it silently does nothing - so this
     * is not a safety check, it is a way of keeping the wire format plain for
     * the short prompts where caching could never have applied.
     */
    private const CACHE_MIN_BYTES = 2000;

    /** @var array<string,array<string,mixed>> lower-case model id => what the listing said about it */
    private array $catalogue = [];

    private bool $catalogueLoaded = false;

    /** Whether this instance has already been to the network for a fresh listing. */
    private bool $refreshed = false;

    /** Whether the last listing attempt failed because nothing answered at all. */
    private bool $listingUnreachable = false;

    private ?AnthropicInlineBatch $driver = null;

    public static function defaultBaseUrl(): string
    {
        return 'https://api.anthropic.com';
    }

    public function kind(): string
    {
        return Providers::ANTHROPIC;
    }

    public function label(): string
    {
        return 'Anthropic';
    }

    public function supportsBatch(): bool
    {
        return true;
    }

    /** The queue's own numbers: 100,000 requests or 256 MB, and 29 days to collect. */
    public function batchLimits(): BatchLimits
    {
        return AnthropicInlineBatch::limits();
    }

    /**
     * Anthropic paths all start at `/v1`, but people paste the base URL with
     * and without it. Both are accepted and mean the same endpoint.
     */
    protected function normaliseBaseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        return (string)preg_replace('#/v1$#i', '', $url);
    }

    /**
     * @return array<string,string>
     *
     * `anthropic-version` is mandatory on every endpoint this class touches,
     * the model listing and the batch routes included, and a missing one is the
     * single most common 400 against this API. It is set here, once, so no call
     * site can forget it - the batch driver is handed this same array.
     *
     * Nothing else belongs here. `service_tier` is vestigial: batch pricing
     * comes from using the batch endpoint and there is no value that asks for
     * it. The `context-1m` and `extended-cache-ttl` beta flags are retired -
     * both behaviours are on by default now - and carrying a stale beta header
     * is how a working integration starts failing on a day nobody deployed.
     */
    protected function headers(): array
    {
        return [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::VERSION,
        ];
    }

    /* --------------------------------------------------------------- models */

    /**
     * Every model this key can use - or, when nothing can be reached at all,
     * the three ids that let an offline install finish configuring itself.
     *
     * The seed is offered for exactly one failure, and it is the one with no
     * other way out: the endpoint never answered. An endpoint that did answer
     * is a different situation with a different answer, and both of those still
     * raise. A refused key comes back through the error ladder with its own
     * status on it, and a listing that came back empty means the key reached a
     * workspace with no models in it - putting three ids in the picker there
     * would hide a real account problem behind a dropdown that looks healthy
     * and 404s on the first page of the course.
     *
     * @return string[]
     */
    public function models(): array
    {
        $this->assertConfigured();

        try {
            $catalogue = $this->fetchCatalogue();
        } catch (HttpException $e) {
            if (!$this->listingUnreachable) {
                throw $e;
            }
            // Logged rather than swallowed: the picker works from here on, and
            // the reason the real catalogue is missing has to be readable
            // somewhere.
            Runtime::log('anthropic.models', $e);

            // Remembered, because the same list means two different things
            // depending on how it was arrived at. As a model picker it is a
            // reasonable fallback; as an answer to "is this endpoint and key
            // right?" it is a lie, and check() asks.
            $this->reachedEndpoint = false;
            $this->whyNot = $e->getMessage();
            return self::SEED;
        }

        if ($catalogue === []) {
            throw HttpException::badRequest(
                'Anthropic answered, but returned no models. Check that the key belongs to an active workspace.'
            );
        }

        $this->remember($catalogue);

        return self::collectModelIds(array_column($catalogue, 'id'));
    }

    /**
     * The models the queue will take, straight from the listing.
     *
     * No allowlist is involved and none is needed: `capabilities.batch.supported`
     * is reported per model, which is the one provider that says so directly.
     *
     * @return string[]
     */
    public function batchModels(): array
    {
        $ids = [];
        foreach ($this->catalogue() as $record) {
            if (($record['batch'] ?? null) === true) {
                $ids[] = (string)$record['id'];
            }
        }
        sort($ids, SORT_NATURAL | SORT_FLAG_CASE);
        return $ids;
    }

    /* ----------------------------------------------------------------- chat */

    public function chat(AiRequest $request): string
    {
        $this->assertConfigured();
        $this->assertModel($request);

        $payload = $this->payload($request);
        $res = $this->send('POST', '/v1/messages', $payload, $this->chatTimeout());

        // The capability lookup decides whether `temperature` goes out at all,
        // but it can be unavailable - an offline install, a listing that did
        // not answer, a model id the listing has never heard of. This is the
        // net under that: one retry without the parameter the endpoint just
        // objected to turns a hard failure into a written page. It is also the
        // only such net - a queued request is answered hours later and cannot
        // be corrected - which is why payload() gates the parameter more
        // cautiously for a batch than it does here.
        if ($res->status === 400 && isset($payload['temperature']) && self::blamesSampling($res->message(500))) {
            unset($payload['temperature']);
            $res = $this->send('POST', '/v1/messages', $payload, $this->chatTimeout());
        }

        $this->assertOk($res, 'the completion', $this->url('/v1/messages'));
        $this->assertJson($res, 'the completion');

        $read = self::readMessage(is_array($res->data) ? $res->data : []);
        if ($read['problem'] !== '') {
            throw HttpException::badRequest($this->label() . ': ' . $read['problem']);
        }

        return $read['text'];
    }

    /* ---------------------------------------------------------------- batch */

    /** @param array<int,BatchItemRequest> $items */
    public function submitBatch(array $items): BatchHandle
    {
        $this->assertConfigured();

        $rows = [];
        foreach ($items as $item) {
            $this->assertModel($item->request);
            $this->assertQueueable($item->request->model);
            // max_tokens: 0 and stream are both rejected inside a batch, and
            // payload() never emits either.
            $rows[] = ['custom_id' => $item->customId, 'params' => $this->payload($item->request, true)];
        }

        return $this->driver()->submit($rows);
    }

    public function pollBatch(BatchHandle $handle): BatchStatus
    {
        $this->assertConfigured();
        return $this->driver()->poll($handle);
    }

    /** @return iterable<string,BatchItemResult> */
    public function fetchBatchResults(BatchHandle $handle): iterable
    {
        $this->assertConfigured();
        return $this->driver()->results($handle);
    }

    public function canCancel(): bool
    {
        return true;
    }

    public function cancelBatch(BatchHandle $handle): bool
    {
        $this->assertConfigured();
        return $this->driver()->cancel($handle);
    }

    /**
     * Nothing to release, on purpose.
     *
     * The requests were submitted inline, so there is no uploaded file sitting
     * at Anthropic costing anyone storage - the litter this call exists to
     * clear up on the file-based providers does not accumulate here. The batch
     * object itself could be deleted once processing has ended, and is not:
     * until CourseForge keeps its own copy of a results file, that object is
     * the only remaining copy of output somebody paid for, and it is kept for
     * 29 days for exactly this reason. Deleting it the instant the pages appear
     * to have been written would mean a storage failure discovered an hour
     * later has nothing left to be retried against.
     */
    public function releaseBatch(BatchHandle $handle): void
    {
    }

    /* ------------------------------------------------- reading a Message object */

    /**
     * The one place a Messages response is turned into a page, or refused.
     *
     * Public because the batch results file is made of these same objects, one
     * per line, and a queued page has to be judged by exactly the rules a live
     * one is. The alternative - the driver reading `content` itself - is how
     * the two paths drift until only one of them notices a refusal.
     *
     * `problem` is empty when the text can be stored, and otherwise says what
     * went wrong in the words the person who has to fix it needs. It is never
     * both: a truncated answer is a problem even though it has text in it,
     * because half a course page written into the database with no error beside
     * it is the failure that costs the most to find.
     *
     * @param array<string,mixed> $message
     * @return array{text:string,problem:string}
     */
    public static function readMessage(array $message): array
    {
        $stop = is_string($message['stop_reason'] ?? null) ? (string)$message['stop_reason'] : '';

        // Before the content array is touched. A refusal is a perfectly ordinary
        // HTTP 200 whose content carries no usable text, and branching on the
        // stop reason first is the only way it is ever seen.
        if ($stop === 'refusal') {
            return ['text' => '', 'problem' => self::refusal($message)];
        }

        $text = self::extractText($message);

        if ($text === '') {
            return [
                'text' => '',
                'problem' => 'the answer contained no text' . self::because($stop) . '.',
            ];
        }

        if ($stop === 'max_tokens') {
            return [
                'text' => '',
                'problem' => 'the answer was cut off at the output ceiling after '
                    . number_format(self::outputTokens($message)) . ' tokens (stop_reason=max_tokens). '
                    . 'Raise "Max tokens" for this slot - thinking tokens are drawn from the same ceiling, '
                    . 'and it is on by default on the current models.',
            ];
        }

        if ($stop === 'model_context_window_exceeded') {
            return [
                'text' => '',
                'problem' => 'the prompt and the requested output together do not fit this model\'s context '
                    . 'window (stop_reason=model_context_window_exceeded). Shorten the course context or '
                    . 'lower "Max tokens".',
            ];
        }

        if ($stop === 'pause_turn') {
            return [
                'text' => '',
                'problem' => 'the model paused before finishing the answer (stop_reason=pause_turn), '
                    . 'so the page would have been stored half written.',
            ];
        }

        return ['text' => $text, 'problem' => ''];
    }

    /* ------------------------------------------------------------ internals */

    /**
     * One Messages request body.
     *
     * @return array<string,mixed>
     */
    private function payload(AiRequest $request, bool $queued = false): array
    {
        $payload = [
            'model' => $request->model,
            'max_tokens' => $this->maxTokens($request),
            'messages' => [['role' => 'user', 'content' => $request->user]],
        ];

        if (trim($request->system) !== '') {
            $payload['system'] = self::system($request->system, $queued);
        }

        // The basic search tool, deliberately, and not the newer dated
        // variants. The newer ones only exist on the current generation, and
        // this field is a model id somebody typed - it may be any Claude model
        // the account can reach, including one older than that. /v1/models
        // reports no web-search capability at all, so there is nothing to
        // branch on; the variant that works everywhere is the one to send.
        if ($request->research) {
            $tool = ['type' => 'web_search_20250305', 'name' => 'web_search'];
            if ($request->maxSearches > 0) {
                $tool['max_uses'] = $request->maxSearches;
            }
            $payload['tools'] = [$tool];
        }

        // A queued request is the one that cannot be corrected: there is no
        // retry behind it and no error to read for a day, so it omits what the
        // listing could not confirm.
        if ($this->acceptsSampling($request->model, !$queued)) {
            // Anthropic's ceiling is 1.0; CourseForge's slider goes to 2.0
            // because OpenAI's does.
            $payload['temperature'] = max(0.0, min(1.0, $request->temperature));
        }

        return $payload;
    }

    /**
     * The system prompt, with a cache breakpoint on it when there is anything
     * to gain.
     *
     * This is the one place CourseForge's shape and Anthropic's pricing line up
     * exactly. Every page of a course is generated from the same profile prompt
     * library and the same course-level variables, so the system prompt is
     * byte-identical across all of them; everything that differs per page - the
     * page brief, the neighbouring titles, the author's extra context - is in
     * the user turn. Anthropic renders a request as tools, then system, then
     * messages, so a single breakpoint at the end of the system field caches
     * precisely the part that repeats and nothing that does not. A cached read
     * is a tenth of the input price, and inside a batch that tenth is halved
     * again, which for a fifty-page course is most of the input bill.
     *
     * The queued path asks for the one-hour lifetime rather than the default
     * five minutes because a batch is answered concurrently over an hour or
     * more, and a five-minute entry would be cold for most of it. Live
     * generation keeps the five-minute default, where each page refreshes the
     * entry for free as the run walks through the course.
     *
     * Two things would quietly break this and neither is done: putting anything
     * that changes per request into the system prompt, and sending
     * `output_config.effort`, whose resolved value is rendered into the prompt
     * and so invalidates the cache whenever it is changed.
     *
     * @return string|array<int,array<string,mixed>>
     */
    private static function system(string $system, bool $queued): string|array
    {
        if (strlen($system) < self::CACHE_MIN_BYTES) {
            return $system;
        }

        $control = ['type' => 'ephemeral'];
        if ($queued) {
            $control['ttl'] = '1h';
        }

        return [['type' => 'text', 'text' => $system, 'cache_control' => $control]];
    }

    /**
     * Unlike OpenAI, there is no "let the provider decide" here: the field is
     * required. A slot left at 0 gets the configured default, and either is
     * held to whatever the model will actually produce - the listing reports
     * that as `max_tokens`, which is the output cap and not the context window.
     * The context window is `max_input_tokens`; there is no `context_window`
     * field on this API, and asking for more output than the model can give is
     * a 400 rather than a truncation.
     */
    private function maxTokens(AiRequest $request): int
    {
        $wanted = $request->maxTokens > 0
            ? $request->maxTokens
            : max(1, Config::int('app.anthropic_max_tokens', 16000));

        $cap = (int)($this->record($request->model)['max_tokens'] ?? 0);

        return $cap > 0 ? min($wanted, $cap) : $wanted;
    }

    private function assertModel(AiRequest $request): void
    {
        if (trim($request->model) === '') {
            throw HttpException::unprocessable('No model is selected for this request.');
        }
    }

    /**
     * Refuses a model the queue is known not to take, before a day is spent
     * finding out.
     *
     * Batch parameters are validated asynchronously: a submission Anthropic
     * cannot serve is accepted at the door and comes back as an errored result
     * when the batch ends, which can be 24 hours later. The listing knows the
     * answer now. Only an explicit `false` refuses - a model the listing has
     * nothing to say about is submitted, because being unable to look
     * something up is not evidence against it.
     */
    private function assertQueueable(string $model): void
    {
        if (($this->record($model)['batch'] ?? null) === false) {
            throw HttpException::unprocessable(
                'Anthropic does not accept "' . $model . '" through its batch queue. '
                . 'Pick a model the queue takes, or run this generation live.'
            );
        }
    }

    /**
     * Whether `temperature` may be sent to this model.
     *
     * There is no capability flag for sampling, so this is read off the two
     * capabilities that move with it: which kind of thinking the model offers,
     * and whether it has effort levels. The models that still accept a sampling
     * parameter are the ones that still offer the old explicit thinking mode
     * (`thinking.types.enabled`, the budget_tokens generation); the ones that
     * reject it are the ones that have moved to adaptive thinking and
     * `output_config.effort`. That is an inference and it is written down as
     * one - but it is an inference over live capability data rather than over a
     * list of model ids, and it fails in the safe direction. If the capability
     * shape ever changes, this stops sending `temperature`, which costs a
     * slightly different sampling default; a list of ids fails the other way,
     * with a hard 400 on every page of every course until somebody edits it.
     *
     * A model nothing is known about is the interesting case, and the answer
     * depends on which path is asking - which is why the caller supplies it
     * rather than this method assuming one. Live, the parameter goes out and
     * chat() retries without it the moment the endpoint objects, so being wrong
     * costs one round trip. Queued, there is no retry to fall back on and no
     * error to see for a day: batch parameters are validated asynchronously, so
     * five hundred pages submitted against a model that refuses a sampling
     * parameter are accepted at the door and come back as five hundred errored
     * lines tomorrow. One unreachable model listing - an expired cache, a
     * network blip, an offline install - is all it takes, so the queued path
     * omits what it could not confirm and accepts the default sampling instead.
     *
     * @param bool $whenUnknown what to answer when the listing says nothing about this model
     */
    private function acceptsSampling(string $model, bool $whenUnknown): bool
    {
        $record = $this->record($model);
        if ($record === null) {
            return $whenUnknown;
        }
        // The effort ladder is asked about FIRST, and the order is the whole
        // point. A current model reports both: it still lists
        // thinking.types.enabled among the shapes it knows, and it also lists
        // low..max under effort. Reading the legacy flag first therefore says
        // "this one still samples" about exactly the generation that answers a
        // sampling parameter with a 400. An effort ladder is the marker that
        // cannot be misread - no model has ever had one and accepted
        // temperature - so it is the one that decides.
        if (($record['effort'] ?? []) !== []) {
            return false; // moved to output_config.effort, where sampling is a 400
        }
        if (($record['legacy_thinking'] ?? null) === true) {
            return true; // still the budget_tokens generation, which still samples
        }
        if (($record['thinking'] ?? null) !== true) {
            return true; // a model with no thinking at all predates the change
        }
        return false; // adaptive thinking only
    }

    private static function blamesSampling(string $message): bool
    {
        $message = strtolower($message);
        return str_contains($message, 'temperature') || str_contains($message, 'top_p') || str_contains($message, 'top_k');
    }

    /* ------------------------------------------------------- SearchCapable */

    public function supportsSearch(): bool
    {
        return true;
    }

    /**
     * Empty, and that is the honest answer rather than a shrug.
     *
     * /v1/models reports batch, citations, code execution, context management,
     * effort, image and PDF input, structured outputs and thinking. It says
     * nothing at all about web search, so there is no subset to name and the
     * toggle is offered for every model this account can reach.
     *
     * @return array<int,string>
     */
    public function searchModels(): array
    {
        return [];
    }

    public function searchNote(): string
    {
        return 'Anthropic charges $10 per 1,000 searches, and what comes back is billed as input '
            . 'tokens on top. A 200-page course researching five times a page is a thousand searches.';
    }

    private function driver(): AnthropicInlineBatch
    {
        // The driver is given the provider's own error ladder rather than
        // growing a second one: a batch that is refused should read like every
        // other refusal from this account, down to the label.
        return $this->driver ??= new AnthropicInlineBatch(
            $this->baseUrl,
            $this->headers(),
            $this->assertOk(...),
            $this->metaTimeout(),
            $this->chatTimeout(),
        );
    }

    /* ------------------------------------------------- the capability listing */

    /**
     * What the models endpoint says, without insisting on an answer.
     *
     * Used by everything that gates a parameter, which means it runs in front
     * of every generation - so it must be cheap and it must not be able to stop
     * a page being written. A listing is read from the local cache when there
     * is a fresh one, fetched at most once per instance when there is not, and
     * a fetch that fails is logged and treated as "nothing is known": the
     * request then goes out with the conservative defaults and the retry in
     * chat() catches what it has to.
     *
     * @return array<string,array<string,mixed>>
     */
    private function catalogue(): array
    {
        if ($this->catalogueLoaded) {
            return $this->catalogue;
        }

        $stored = $this->readCatalogue();
        if ($stored !== null) {
            $this->catalogue = $stored;
            $this->catalogueLoaded = true;
            return $this->catalogue;
        }

        return $this->refresh();
    }

    /**
     * What the listing says about one model.
     *
     * A model id the listing does not carry is looked for once more against a
     * freshly fetched listing, because the usual reason for a miss is a model
     * released since the cached copy was written - which is exactly the moment
     * a stale gating decision would be most wrong. The second look happens once
     * per instance and never again.
     *
     * Ids that are not listed verbatim are matched to the longest listed id
     * they extend, so a dated snapshot inherits the capabilities of the family
     * it belongs to.
     *
     * @return array<string,mixed>|null
     */
    private function record(string $model): ?array
    {
        $model = strtolower(trim($model));
        if ($model === '') {
            return null;
        }

        $hit = self::match($this->catalogue(), $model);
        if ($hit !== null || $this->refreshed) {
            return $hit;
        }

        return self::match($this->refresh(), $model);
    }

    /**
     * @param array<string,array<string,mixed>> $catalogue
     * @return array<string,mixed>|null
     */
    private static function match(array $catalogue, string $model): ?array
    {
        if (isset($catalogue[$model])) {
            return $catalogue[$model];
        }

        $best = null;
        $length = 0;
        foreach ($catalogue as $id => $record) {
            // The trailing hyphen matters: it is what makes this "a variant of
            // that model" rather than "some id starting with those letters".
            if (str_starts_with($model, $id . '-') && strlen($id) > $length) {
                $best = $record;
                $length = strlen($id);
            }
        }
        return $best;
    }

    /**
     * Goes to the network for a listing, and never lets that be fatal.
     *
     * @return array<string,array<string,mixed>>
     */
    private function refresh(): array
    {
        $this->refreshed = true;
        $this->catalogueLoaded = true;

        try {
            $this->remember($this->fetchCatalogue());
        } catch (Throwable $e) {
            Runtime::log('anthropic.models', $e);
        }

        return $this->catalogue;
    }

    /**
     * The whole listing, distilled.
     *
     * @return array<string,array<string,mixed>>
     */
    private function fetchCatalogue(): array
    {
        $models = [];
        $after = '';

        // The list is paginated and newest-first; 1000 is the documented cap.
        for ($page = 0; $page < 20; $page++) {
            $query = '/v1/models?limit=1000' . ($after !== '' ? '&after_id=' . rawurlencode($after) : '');
            $res = $this->send('GET', $query, null, $this->metaTimeout());
            // Recorded before the error ladder turns it into an exception:
            // "nothing answered" is the one listing failure with a fallback
            // behind it, and once it is an exception it cannot be told apart
            // from "the key was refused".
            $this->listingUnreachable = $res->unreachable();
            $this->assertOk($res, 'the model list', $this->url($query));
            $this->assertJson($res, 'the model list');

            $items = is_array($res->data['data'] ?? null) ? $res->data['data'] : [];
            foreach ($items as $item) {
                if (!is_array($item) || !is_string($item['id'] ?? null) || trim($item['id']) === '') {
                    continue;
                }
                $id = trim($item['id']);
                $models[strtolower($id)] = self::distil($id, $item);
            }

            if (($res->data['has_more'] ?? false) !== true || !is_string($res->data['last_id'] ?? null)) {
                break;
            }
            $after = (string)$res->data['last_id'];
        }

        return $models;
    }

    /**
     * One model's row, reduced to the facts CourseForge gates on.
     *
     * Kept small deliberately, because it is written to a cache row and read
     * back on every generation. Three-valued on purpose: `null` means the
     * listing did not say, which is a different thing from `false`, and the
     * difference decides whether a submission is refused or merely allowed to
     * go and find out.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private static function distil(string $id, array $item): array
    {
        $capabilities = is_array($item['capabilities'] ?? null) ? $item['capabilities'] : [];
        $thinking = is_array($capabilities['thinking'] ?? null) ? $capabilities['thinking'] : [];
        $effort = is_array($capabilities['effort'] ?? null) ? $capabilities['effort'] : [];

        // The effort levels this model offers, which is both what an effort
        // dropdown would have to be built from and - because only the models
        // that moved to output_config.effort have any - the marker for the
        // generation that no longer accepts a sampling parameter.
        $levels = [];
        if (($effort['supported'] ?? null) === true) {
            foreach (['low', 'medium', 'high', 'xhigh', 'max'] as $level) {
                if (isset($effort[$level]) && ($effort[$level]['supported'] ?? null) !== false) {
                    $levels[] = $level;
                }
            }
        }

        return [
            'id' => $id,
            'batch' => self::flag($capabilities['batch']['supported'] ?? null),
            'thinking' => self::flag($thinking['supported'] ?? null),
            'legacy_thinking' => self::flag($thinking['types']['enabled']['supported'] ?? null),
            'effort' => $levels,
            // max_tokens is the OUTPUT cap and max_input_tokens is the context
            // window. There is no context_window field on this API, and reading
            // the two the other way round produces requests that are refused
            // for a reason the message does not explain.
            'max_tokens' => is_int($item['max_tokens'] ?? null) ? $item['max_tokens'] : 0,
            'max_input_tokens' => is_int($item['max_input_tokens'] ?? null) ? $item['max_input_tokens'] : 0,
        ];
    }

    private static function flag(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /** @param array<string,array<string,mixed>> $catalogue */
    private function remember(array $catalogue): void
    {
        if ($catalogue === []) {
            return;
        }

        $this->catalogue = $catalogue;
        $this->catalogueLoaded = true;
        $this->refreshed = true;

        try {
            Meta::set($this->catalogueKey(), (string)json_encode(
                ['at' => time(), 'models' => $catalogue],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));
        } catch (Throwable $e) {
            // A cache that cannot be written costs one extra request per
            // process, which is not a reason to refuse to generate anything.
            Runtime::log('anthropic.models.cache', $e);
        }
    }

    /** @return array<string,array<string,mixed>>|null null when there is nothing usable and fresh */
    private function readCatalogue(): ?array
    {
        try {
            $raw = Meta::get($this->catalogueKey());
        } catch (Throwable $e) {
            Runtime::log('anthropic.models.cache', $e);
            return null;
        }
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $at = (int)($decoded['at'] ?? 0);
        $models = $decoded['models'] ?? null;
        if (!is_array($models) || $models === [] || $at <= 0 || time() - $at > self::CATALOGUE_SECONDS) {
            return null;
        }

        $catalogue = [];
        foreach ($models as $id => $record) {
            if (is_array($record) && is_string($record['id'] ?? null)) {
                $catalogue[(string)$id] = $record;
            }
        }
        return $catalogue === [] ? null : $catalogue;
    }

    /**
     * Where one account's listing is cached.
     *
     * A hash of the endpoint and the key, never either of them: the meta table
     * is readable by anything that can read the database, and a credential has
     * no business being in a cache key. Two accounts on the same endpoint with
     * different keys can see different models, so the key has to cover both -
     * and a rotated key leaves a few stale kilobytes behind, which is
     * cheaper than anything that would clean them up.
     */
    private function catalogueKey(): string
    {
        return 'anthropic.models.' . Text::hash($this->baseUrl, $this->apiKey);
    }

    /* ------------------------------------------------- reading the blocks */

    /**
     * The assistant text out of one Message object.
     *
     * `content` is an array of typed blocks and the text is not reliably the
     * first of them: with adaptive thinking - on by default on the current
     * models - block zero is a `thinking` block whose `text` key does not
     * exist, so `content[0]['text']` reads as an empty page. Every block whose
     * type is not `text` is skipped rather than rejected, because the union
     * grows with every release.
     *
     * All text blocks are joined rather than only the first. A plain prose
     * answer is one block, but a cited one is split at every citation boundary,
     * and taking the first would store the opening paragraph as the whole page.
     *
     * @param array<string,mixed> $message
     */
    private static function extractText(array $message): string
    {
        $blocks = (array)($message['content'] ?? []);

        // With the search tool attached the answer is not the whole of the text.
        // The model narrates first - "I'll look up the current version" - then
        // the server tool runs, and only what follows the last result is the
        // page. Concatenating every text block would publish the narration as
        // the opening paragraph, which is the kind of thing nobody notices until
        // it is in BookStack.
        //
        // Everything before the last tool block is therefore dropped. With no
        // tool blocks at all - every request that does not research - the loop
        // below finds nothing and this is exactly what it was before.
        $lastTool = -1;
        foreach ($blocks as $index => $block) {
            $type = is_array($block) ? ($block['type'] ?? '') : '';
            if ($type === 'web_search_tool_result' || $type === 'server_tool_use') {
                $lastTool = (int)$index;
            }
        }

        $parts = [];
        foreach ($blocks as $index => $block) {
            if ((int)$index <= $lastTool) {
                continue;
            }
            if (is_array($block) && ($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }
        return trim(implode('', $parts));
    }

    /**
     * A refusal, explained as far as the response explains it.
     *
     * `stop_details` is only populated for this stop reason, and carries the
     * category the model refused under and sometimes a sentence about it.
     *
     * @param array<string,mixed> $message
     */
    private static function refusal(array $message): string
    {
        $details = is_array($message['stop_details'] ?? null) ? $message['stop_details'] : [];
        $category = trim((string)($details['category'] ?? ''));
        $why = trim((string)($details['explanation'] ?? ''));

        return 'the model declined to answer this request (stop_reason=refusal'
            . ($category !== '' ? ', category ' . $category : '') . ')'
            . ($why !== '' ? ' - ' . mb_substr($why, 0, 200) : '')
            . '. Rewording the page brief or the system prompt is the only way past it.';
    }

    /** The stop reason as a parenthetical, for a message that already reads well without one. */
    private static function because(string $stop): string
    {
        return $stop !== '' ? ' (stop_reason=' . $stop . ')' : '';
    }

    /** @param array<string,mixed> $message */
    private static function outputTokens(array $message): int
    {
        return (int)($message['usage']['output_tokens'] ?? 0);
    }
}
