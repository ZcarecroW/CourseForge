<?php
declare(strict_types=1);

namespace CourseForge\Ai\Provider;

use CourseForge\Ai\AiRequest;
use CourseForge\Ai\Batch\BatchHandle;
use CourseForge\Ai\Batch\BatchItemRequest;
use CourseForge\Ai\Batch\BatchItemResult;
use CourseForge\Ai\Batch\BatchLimits;
use CourseForge\Ai\Batch\BatchStatus;
use CourseForge\Ai\Batch\Driver\OpenRouterInlineBatch;
use CourseForge\Support\Config;
use CourseForge\Support\Http;
use CourseForge\Support\HttpException;
use CourseForge\Support\HttpResult;
use CourseForge\Support\Runtime;
use CourseForge\Support\Text;
use Throwable;

/**
 * OpenRouter: one key in front of every vendor it fronts.
 *
 * The chat body is OpenAI's, which is why this sits on the preset driver and
 * restates none of it. Everything else about the endpoint is its own, and
 * three of those differences are the kind that produce a blank course page
 * rather than an error.
 *
 * The first and worst: a status code says almost nothing here. OpenRouter
 * commits the HTTP 200 as soon as the model it routed to starts producing, so
 * anything that goes wrong after that point - a rate limit two hops away, a
 * provider falling over mid-answer, a refusal - arrives as a successful
 * response with a top-level `error` object, or as a choice whose
 * `finish_reason` is `error`. Their own documented read order is error first,
 * finish_reason second, content last, and completionFailure() is that order
 * written down. It is used by chat() and by the batch driver alike, because a
 * queued page fails in exactly the same shapes as a live one.
 *
 * The second: the catalogue contains real model slugs ending in `:batch` -
 * sixty-one of them - which collides head-on with CourseForge's own convention
 * that a `:batch` suffix means "send this through the queue". The two readings
 * happen to agree, because the discount comes from using the Batch API and not
 * from the suffix, so this adapter removes the ambiguity rather than resolving
 * it case by case: routing variants never enter the model picker, a slug is
 * split on its last colon, and what goes upstream is always the plain one. The
 * `:batch` twins are kept for one purpose, which is reading what the queue
 * actually costs rather than assuming it is half.
 *
 * The third: the batch queue is not OpenAI's. It lives under /api/beta, takes
 * its requests inline, hands the results back inside the status response and
 * has no documented way to be cancelled. All of that is in
 * OpenRouterInlineBatch, and the inherited file-upload lane is replaced whole.
 */
final class OpenRouterProvider extends OpenAiCompatibleProvider implements SearchCapable
{
    /**
     * OpenRouter's spec. Baked in rather than listed in the preset table
     * because an account of this kind is never anything else, and because the
     * queue below is not the one a preset would give it.
     */
    private const PRESET = [
        'label' => 'OpenRouter',
        'batch' => true,
        'window' => '24h',
        'models_quirk' => 'Public (no auth), ~416 models, pricing as strings in USD per single token, '
            . 'and eight routing-variant suffixes that are not separate models.',
        'docs' => 'https://openrouter.ai/docs',
        'hint' => 'One key for every vendor OpenRouter fronts. Model ids carry the vendor prefix, '
            . 'as in anthropic/claude-opus-5.',
        'verified' => true,
    ];

    /**
     * Suffixes that mean "route this differently" rather than naming a model.
     *
     * Seven are documented - :free, :extended, :exacto, :thinking, :online,
     * :nitro, :floor - and `:batch` is not, which is the reason it is read as a
     * pricing representation rather than as a routing modifier. None of them
     * belongs in a picker: each is a variant of an entry that is already in the
     * list, and a dropdown offering eight spellings of one model is worse than
     * one offering the model. They stay reachable by typing one into the model
     * box, which is the only way to ask for :free deliberately.
     */
    private const ROUTING_VARIANTS = [
        'batch', 'free', 'nitro', 'floor', 'online', 'thinking', 'extended', 'exacto',
    ];

    /**
     * Neither bound is published for the beta queue, and the only evidence that
     * a ceiling exists at all is a typed `payload_too_large` error. So these are
     * a deliberate guess rather than a limit: the research recommends chunking
     * at five to ten thousand requests, and the byte figure is about this
     * process rather than about OpenRouter - the whole submission is built as
     * one PHP array and encoded in a single pass, so a body measured in
     * hundreds of megabytes would exhaust the memory limit long before it
     * reached the wire. A chunk refused anyway is halved once and resent.
     */
    private const BATCH_REQUESTS = 10000;
    private const BATCH_BYTES = 64 * BatchLimits::MEGABYTE;

    /** Results are held as JSONL in cloud storage and deleted 30 days after creation. */
    private const RETENTION_DAYS = 30;

    /** @var array<string,array<string,mixed>>|null the annotated catalogue, built as a side effect of models() */
    private ?array $catalogue = null;

    public static function defaultBaseUrl(): string
    {
        return 'https://openrouter.ai/api/v1';
    }

    public function kind(): string
    {
        return Providers::OPENROUTER;
    }

    public function label(): string
    {
        return 'OpenRouter';
    }

    /**
     * There is one address and one shape, so nothing here depends on the
     * account beyond letting a user point at a mirror.
     *
     * @param array<string,mixed> $account
     */
    protected static function resolveSpec(array $account): PresetSpec
    {
        $baseUrl = rtrim(trim((string)($account['base_url'] ?? '')), '/');

        $row = self::PRESET;
        $row['base_url'] = $baseUrl !== '' ? $baseUrl : self::defaultBaseUrl();

        return PresetSpec::fromArray(Providers::OPENROUTER, $row);
    }

    /** OpenRouter always has the queue, so there is nothing to probe for. */
    public function supportsBatch(): bool
    {
        // The queue is always there; the only question is whether this account
        // can reach it. Answering true for an account with no key would have
        // the readiness check promise a batch run that 401s at submit.
        return $this->baseUrl !== '' && $this->apiKey !== '';
    }

    /**
     * The capability probe is answered without asking anybody.
     *
     * Probe looks for an OpenAI-shaped queue at {base}/batches and an upload
     * lane at {base}/files, and OpenRouter has neither: its queue is at
     * /api/beta and it accepts no files at all, by design. Running the generic
     * probe would store "no queue" against an account whose queue works, and a
     * badge that is wrong is worse than a badge that was never earned.
     *
     * @return array{at:int,result:string,codes:array<string,int>,window:string,probe_ver:int,reason:string}
     */
    public function probe(): array
    {
        return [
            'at' => time(),
            'result' => Probe::YES,
            'codes' => ['models' => 0, 'batches' => 0, 'files' => 0, 'create' => 0],
            'window' => '24h',
            'probe_ver' => Probe::VERSION,
            'reason' => 'OpenRouter has its own inline batch queue at /api/beta/batches, which needs no probing '
                . 'and has no file upload lane to look for.',
        ];
    }

    public function batchLimits(): BatchLimits
    {
        return new BatchLimits(
            self::BATCH_REQUESTS,
            self::BATCH_BYTES,
            null,
            '24h', // the only supported completion window; there is no flex option
            self::RETENTION_DAYS,
        );
    }

    /**
     * Bearer, plus the two headers that make the account's usage visible.
     *
     * HTTP-Referer is what OpenRouter identifies an application by: without it
     * there is no app page and no entry in the rankings, and the title header
     * creates nothing on its own. The key itself comes from the spec, which
     * omits the header entirely when there is no key - and that omission is
     * what lets the public model list be read by an account that has not been
     * given one yet.
     *
     * @return array<string,string>
     */
    protected function headers(): array
    {
        $headers = parent::headers();

        $site = trim((string)($this->account['site_url'] ?? ''));
        if ($site !== '') {
            $headers['HTTP-Referer'] = $site;
        }

        $title = trim((string)($this->account['site_name'] ?? ''));
        $title = $title !== '' ? $title : Config::str('app.name', 'CourseForge');
        $headers['X-OpenRouter-Title'] = $title;
        $headers['X-Title'] = $title; // the older spelling of the same header, still accepted

        return $headers;
    }

    /* --------------------------------------------------------------- models */

    /**
     * The catalogue, fetched without demanding a key.
     *
     * GET /api/v1/models needs no authentication whatsoever, and refusing to
     * list models until a key has been saved would make the picker useless on a
     * fresh account for no reason - so this fetches the rows itself rather than
     * going through the inherited path, which insists on a configured account.
     * Everything that does need the key still calls assertConfigured().
     *
     * @return string[]
     */
    public function models(): array
    {
        return $this->pickModels($this->catalogueRows());
    }

    /**
     * The picker: plain slugs only.
     *
     * Every routing variant is left out, `:batch` included. That one matters
     * beyond tidiness - `anthropic/claude-opus-5:batch` is simultaneously a
     * real OpenRouter slug and CourseForge's own way of writing "queue this
     * model", and a picker that offers it invites the user to store a string
     * whose meaning depends on who reads it. The queue is turned on by the
     * toggle beside the field, and the plain slug is what goes up either way.
     *
     * @param array<int,mixed> $rows
     * @return string[]
     */
    protected function pickModels(array $rows): array
    {
        $this->catalogue = self::annotate($rows);

        $picker = [];
        foreach ($this->catalogue as $id => $info) {
            if ($info['variant'] === '') {
                $picker[] = $id;
            }
        }
        sort($picker, SORT_NATURAL | SORT_FLAG_CASE);

        if ($picker === []) {
            throw HttpException::badRequest('OpenRouter answered, but its catalogue held no plain model ids.');
        }

        return $picker;
    }

    /**
     * The models the catalogue itself shows batch-tier pricing for.
     *
     * There is no capability field to ask, and the batch endpoint is not
     * documented to refuse anything, so this is evidence rather than a rule: a
     * model with a `:batch` twin is certainly priced for the queue, and one
     * without may still be accepted by it. The interface treats a missing entry
     * as a warning rather than a block, which is the right weight for it.
     *
     * @return string[]
     */
    public function batchModels(): array
    {
        $batchable = [];
        foreach ($this->catalogue() as $id => $info) {
            if ($info['variant'] === '' && $info['batch'] === true) {
                $batchable[] = $id;
            }
        }
        sort($batchable, SORT_NATURAL | SORT_FLAG_CASE);

        return $batchable;
    }

    /**
     * The whole live catalogue, annotated, keyed by slug.
     *
     * Every entry carries what a cost estimate and a deprecation warning need:
     * prices as floats in USD per single token, the context window, the output
     * cap, and - on a plain slug whose `:batch` twin exists - what the same
     * work costs through the queue. That last pair is read rather than
     * calculated, on purpose. The discount is usually exactly half, and
     * `minimax/minimax-m3:batch` is priced at the full rate with the context
     * window halved, so a hardcoded 0.5 would quietly misprice a course.
     *
     * `expires` is the other reason this is worth keeping: a non-null
     * expiration_date means the model is scheduled for removal, which is free
     * deprecation telemetry for a picker that would otherwise break silently.
     *
     * @return array<string,array<string,mixed>>
     */
    public function catalogue(): array
    {
        if ($this->catalogue === null) {
            $this->pickModels($this->catalogueRows());
        }

        return $this->catalogue ?? [];
    }

    /**
     * What the catalogue says about one model, by its plain slug.
     *
     * @return array<string,mixed>
     */
    public function modelInfo(string $model): array
    {
        $plain = self::plainSlug($model);
        foreach ($this->catalogue() as $id => $info) {
            if (strcasecmp((string)$id, $plain) === 0) {
                return $info;
            }
        }

        return [];
    }

    /* ---------------------------------------------------- reading an answer */

    /**
     * The failure inside a body that arrived as a success, or null.
     *
     * Read before the text on every completion this provider handles, live or
     * queued, because the status code was committed before the answer existed.
     * The order is OpenRouter's own: a top-level `error` first, then a choice
     * whose `finish_reason` is `error`, and only then the content. The two
     * stopping reasons are treated as failures too - a page cut off by the
     * output cap or halted by a content filter is not a page, and returning one
     * would leave half a lesson in the database looking finished.
     *
     * The envelope comes back as it arrived rather than flattened, so a batch
     * result can keep it and a caller can still tell a rate limit apart from an
     * invalid request.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>|null
     */
    public static function completionFailure(array $body): ?array
    {
        $error = $body['error'] ?? null;
        if (is_array($error) && $error !== []) {
            return $error;
        }
        if (is_string($error) && trim($error) !== '') {
            return ['message' => trim($error)];
        }

        $choice = is_array($body['choices'][0] ?? null) ? $body['choices'][0] : [];
        if ($choice === []) {
            return null;
        }

        $finish = strtolower(trim((string)($choice['finish_reason'] ?? '')));
        $native = trim((string)($choice['native_finish_reason'] ?? ''));

        if ($finish === 'error') {
            // A mid-answer failure carries its own error object beside the
            // partial content, and it is more specific than anything that could
            // be said here.
            $inner = is_array($choice['error'] ?? null) ? $choice['error'] : [];
            if ($inner !== []) {
                return $inner;
            }
            return [
                'code' => 'error',
                'message' => 'The upstream provider failed while it was producing this answer'
                    . ($native !== '' ? ' (' . $native . ')' : '') . '.',
            ];
        }

        if ($finish === 'length') {
            return [
                'code' => 'length',
                'message' => 'The answer was cut off by the output limit, so the page is incomplete. '
                    . 'Raise "Max tokens" for this slot, or shorten the brief.',
            ];
        }

        if ($finish === 'content_filter') {
            return [
                'code' => 'content_filter',
                'message' => 'The upstream provider blocked this answer'
                    . ($native !== '' ? ' (' . $native . ')' : '') . '.',
            ];
        }

        return null;
    }

    /**
     * One error envelope as a single line, for a person to read.
     *
     * `metadata.error_type` goes in front of the message because it is the
     * stable half: OpenRouter's typed vocabulary - context_length_exceeded,
     * provider_overloaded, payload_too_large - survives across the three API
     * skins it serves, while the status code and the upstream's own wording do
     * not. `provider_code` names the vendor behind the failure where there is
     * one, and is omitted on a 500, where the message is masked anyway.
     */
    public static function failureText(mixed $envelope): string
    {
        if (is_string($envelope)) {
            return mb_substr(trim($envelope), 0, 400);
        }
        if (!is_array($envelope) || $envelope === []) {
            return '';
        }

        $message = trim((string)($envelope['message'] ?? ''));
        $type = trim((string)(
            $envelope['metadata']['error_type'] ?? $envelope['type'] ?? $envelope['code'] ?? ''
        ));
        $provider = trim((string)($envelope['metadata']['provider_code'] ?? ''));

        if ($message === '') {
            $message = (string)(json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        }
        if ($type !== '' && !str_starts_with(strtolower($message), strtolower($type))) {
            $message = $type . ': ' . $message;
        }
        if ($provider !== '') {
            $message .= ' (upstream: ' . $provider . ')';
        }

        return mb_substr(trim($message), 0, 400);
    }

    /* ---------------------------------------------------------------- batch */

    /**
     * Hands the run to the beta queue.
     *
     * @param array<int,BatchItemRequest> $items
     */
    public function submitBatch(array $items): BatchHandle
    {
        $this->assertConfigured();

        $items = array_values($items);
        if ($items === []) {
            throw HttpException::unprocessable('There is nothing to submit.');
        }

        // The model is a property of the batch rather than of its requests, and
        // it goes up plain: the queue is what halves the price, not a suffix on
        // the id.
        $model = self::plainSlug($items[0]->request->model);
        foreach ($items as $item) {
            if (self::plainSlug($item->request->model) !== $model) {
                throw HttpException::unprocessable(
                    'An OpenRouter batch carries one model for every request in it, and this run has more than one.'
                );
            }
        }
        $this->assertQueueModel($model);

        return $this->queue()->submit($items, $model);
    }

    public function pollBatch(BatchHandle $handle): BatchStatus
    {
        $this->assertConfigured();

        return $this->queue()->poll($handle);
    }

    /** @return iterable<string,BatchItemResult> */
    public function fetchBatchResults(BatchHandle $handle): iterable
    {
        $this->assertConfigured();

        return $this->queue()->fetch($handle);
    }

    /**
     * There is no cancel route to offer.
     *
     * A POST to /api/beta/batches/{id}/cancel answers 401 where a path that
     * genuinely does not exist answers with an HTML 404, so something is
     * probably there - but its verb, its body and its semantics are unpublished
     * and untested, and a button that might do nothing is worse than no button.
     * CourseForge is built on the assumption that an OpenRouter batch runs to
     * completion or to its 24 hour expiry, and the run panel hides the control
     * because of this answer.
     */
    public function canCancel(): bool
    {
        return false;
    }

    /** Never asked, because canCancel() says so. False means "nothing was requested". */
    public function cancelBatch(BatchHandle $handle): bool
    {
        return false;
    }

    /**
     * Nothing to release. The requests went up inside the create body and the
     * answers come back inside the status response, so OpenRouter is holding no
     * file of ours to delete - and the JSONL it keeps on its own side goes on
     * its own schedule, thirty days after creation, with no route offered to do
     * it sooner.
     */
    public function releaseBatch(BatchHandle $handle): void
    {
    }

    /* ------------------------------------------- what the batch driver needs */

    /*
     * The queue is at /api/beta rather than under the account's /api/v1 base
     * URL, so the driver cannot reach it through batchUrl() and batchRequest()
     * the way the file lane does. These three are its whole view of this
     * provider, alongside batchBody(), batchText(), batchLimits() and label(),
     * which it inherits unchanged.
     */

    /**
     * The batch queue sits beside /v1 rather than inside it: an account's base
     * URL ends at /api/v1 and the batches live at /api/beta. A base URL saved
     * without the version segment still lands in the right place.
     */
    public function betaUrl(string $path): string
    {
        $root = (string)preg_replace('#/v1/?$#i', '', $this->baseUrl);

        return $root . '/beta/' . ltrim($path, '/');
    }

    /**
     * One call against the beta queue, with the result handed back untouched.
     *
     * Which statuses matter is a question about that endpoint - a 413 is a
     * chunk to halve rather than an error to raise, and a body that is not JSON
     * means the route moved - and the driver is what knows the answers. The
     * timeout is chosen here rather than passed in: `$long` is the two calls
     * that carry a whole course, submit and fetch, against a poll that carries
     * a dozen fields.
     *
     * @param array<string,mixed>|null $payload
     */
    public function betaRequest(string $method, string $url, ?array $payload = null, bool $long = false): HttpResult
    {
        $this->assertConfigured();

        try {
            // follow: false, for the same reason every other provider call does
            // it - a redirect must never replay the key at wherever it points.
            return Http::json(
                $method,
                $url,
                $this->headers(),
                $payload,
                $long ? $this->chatTimeout() : $this->metaTimeout(),
                false,
            );
        } catch (Throwable $e) {
            throw HttpException::badRequest(
                $this->label() . ': the request to ' . $url . ' crashed - ' . $e->getMessage()
            );
        }
    }

    /* ------------------------------------------------------------ internals */

    /** Every request body, with the slug reduced to the one OpenRouter knows. */
    protected function payload(AiRequest $request): array
    {
        $payload = parent::payload($request);
        $payload['model'] = self::plainSlug((string)($payload['model'] ?? ''));

        // The web plugin rather than the `:online` suffix. They do the same
        // thing, but the suffix is a second way of writing a model id - and this
        // adapter already spends effort reducing model ids to the one form
        // OpenRouter knows, because `anthropic/claude-opus-5:batch` means
        // something to CourseForge and nothing to the endpoint. A plugin is a
        // field, which cannot collide with any of that.
        if ($request->research) {
            $plugin = ['id' => 'web'];
            if ($request->maxSearches > 0) {
                $plugin['max_results'] = $request->maxSearches;
            }
            $payload['plugins'] = [$plugin];
        }

        return $payload;
    }

    /* ------------------------------------------------------- SearchCapable */

    public function supportsSearch(): bool
    {
        return true;
    }

    /**
     * Empty: the plugin is OpenRouter's own, applied in front of whichever
     * upstream model is chosen, so it is not a property of the model.
     *
     * @return array<int,string>
     */
    public function searchModels(): array
    {
        return [];
    }

    public function searchNote(): string
    {
        return 'OpenRouter bills its web plugin per result returned, on top of the model\'s own '
            . 'tokens, and the charge appears in the usage a queued run already reports.';
    }

    /**
     * The 200-that-is-not check, on the way through chat().
     *
     * The inherited version reads the same two shapes and phrases them
     * generically; this one reads the typed error vocabulary that only
     * OpenRouter has, and adds the two stopping reasons that leave a page
     * half-written rather than empty - which the generic path cannot notice,
     * because there is text in the body.
     */
    protected function assertBodyOk(HttpResult $res): void
    {
        if (!is_array($res->data)) {
            return;
        }

        $failure = self::completionFailure($res->data);
        if ($failure !== null) {
            throw HttpException::badRequest($this->label() . ' reported an error: ' . self::failureText($failure));
        }
    }

    /**
     * The slug OpenRouter is actually asked for.
     *
     * Split on the last colon, because the vendor prefix uses a slash and the
     * variant uses a colon - and only `:batch` is removed. The other variants
     * are genuine routing instructions: `:free` picks free-tier hosting,
     * `:nitro` sorts by throughput and can bill at a priority rate, so
     * stripping one would silently change what the user asked for. `:batch` is
     * the ambiguous one, and both of its readings end in the same place - the
     * plain slug, submitted to the Batch API.
     */
    private static function plainSlug(string $model): string
    {
        $model = trim($model);
        $colon = strrpos($model, ':');
        if ($colon === false) {
            return $model;
        }

        return strtolower(substr($model, $colon + 1)) === 'batch'
            ? rtrim(substr($model, 0, $colon))
            : $model;
    }

    /** The routing variant a slug ends in, or an empty string. */
    private static function variantOf(string $id): string
    {
        $colon = strrpos($id, ':');
        if ($colon === false) {
            return '';
        }
        $suffix = strtolower(substr($id, $colon + 1));

        return in_array($suffix, self::ROUTING_VARIANTS, true) ? $suffix : '';
    }

    /**
     * The model list turned into the annotated catalogue.
     *
     * @param array<int,mixed> $rows
     * @return array<string,array<string,mixed>>
     */
    private static function annotate(array $rows): array
    {
        $entries = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string)($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $variant = self::variantOf($id);
            $entries[$id] = [
                'id' => $id,
                'base' => $variant !== '' ? substr($id, 0, -(strlen($variant) + 1)) : $id,
                'variant' => $variant,
                'name' => trim((string)($row['name'] ?? $id)),
                'context' => (int)($row['context_length'] ?? 0),
                'max_output' => (int)($row['top_provider']['max_completion_tokens'] ?? 0),
                'prompt_usd' => self::price($row['pricing']['prompt'] ?? null),
                'completion_usd' => self::price($row['pricing']['completion'] ?? null),
                'batch' => false,
                'batch_prompt_usd' => null,
                'batch_completion_usd' => null,
                'batch_context' => null,
                'batch_ratio' => null,
                'expires' => trim((string)($row['expiration_date'] ?? '')),
            ];
        }

        foreach ($entries as $entry) {
            $base = (string)$entry['base'];
            if ($entry['variant'] !== 'batch' || !isset($entries[$base])) {
                continue;
            }

            $standard = (float)$entries[$base]['completion_usd'];
            $entries[$base]['batch'] = true;
            $entries[$base]['batch_prompt_usd'] = $entry['prompt_usd'];
            $entries[$base]['batch_completion_usd'] = $entry['completion_usd'];
            // The twin can carry a different context window as well, which is
            // the detail that catches out anyone assuming it is the same model
            // at half the price.
            $entries[$base]['batch_context'] = $entry['context'];
            $entries[$base]['batch_ratio'] = $standard > 0.0
                ? round((float)$entry['completion_usd'] / $standard, 3)
                : null;
        }

        return $entries;
    }

    /**
     * One price field.
     *
     * Every `pricing.*` value is a string in USD per SINGLE token - "0.000005"
     * is five dollars per million - and the strings are there to dodge float
     * precision rather than to be decorative. Reading one as a per-1K rate, the
     * habit every other provider's pricing page teaches, is out by a factor of
     * a thousand. floatval is accurate enough for an estimate, and bcmath is
     * not present on every host CourseForge runs on.
     */
    private static function price(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }
        if (!is_string($value) || trim($value) === '') {
            return 0.0;
        }

        return (float)trim($value);
    }

    /**
     * The raw model list, without demanding a key.
     *
     * @return array<int,mixed>
     */
    private function catalogueRows(): array
    {
        if ($this->baseUrl === '') {
            throw HttpException::unprocessable(
                'This OpenRouter account has no base URL (for example ' . self::defaultBaseUrl() . ').'
            );
        }
        if (preg_match('#^https?://#i', $this->baseUrl) !== 1) {
            throw HttpException::unprocessable(
                'The base URL must start with http:// or https:// - got "' . $this->baseUrl . '".'
            );
        }

        // No limit and no offset: pagination on this endpoint is opt-in, and
        // asking for neither returns the whole catalogue with links.next null,
        // so there is no cursor to follow and nothing to page through.
        $path = $this->spec->modelsPath;
        $url = $this->url($path);
        $res = $this->send('GET', $path, null, $this->metaTimeout());
        $this->assertOk($res, 'the model list', $url);
        $this->assertJson($res, 'the model list');

        $rows = is_array($res->data['data'] ?? null) ? $res->data['data'] : null;
        if ($rows === null) {
            throw HttpException::badRequest(
                'The OpenRouter model list came back without a data array: ' . Text::snippet($res->raw)
            );
        }

        return $rows;
    }

    /**
     * Refuses a slug the catalogue has never heard of, before it costs a day.
     *
     * `model` is optional in the batch schema, which means a mistyped id is not
     * an error at all: the account default answers instead, and the first sign
     * of it is a course written by the wrong model twenty-four hours later.
     * Checking is free - the catalogue needs no key and no credit - so it is
     * done on every submission. Only the queue is guarded this way; a live
     * request comes back in seconds and shows the same mistake immediately.
     */
    private function assertQueueModel(string $model): void
    {
        try {
            $catalogue = $this->catalogue();
        } catch (Throwable $e) {
            // A catalogue that cannot be read right now is not a reason to
            // refuse work that would otherwise be accepted.
            Runtime::log('openrouter.catalogue', $e);
            return;
        }

        foreach (array_keys($catalogue) as $id) {
            if (strcasecmp((string)$id, $model) === 0) {
                return;
            }
        }

        throw HttpException::unprocessable(
            'OpenRouter has no model called "' . $model . '". The batch API treats the model as optional, so a '
            . 'mistyped id is not refused - it is answered by the account default a day later. Fetch the model '
            . 'list and pick the id from it.'
        );
    }

    private function queue(): OpenRouterInlineBatch
    {
        return new OpenRouterInlineBatch($this);
    }
}
