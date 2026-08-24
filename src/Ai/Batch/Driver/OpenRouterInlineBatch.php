<?php
declare(strict_types=1);

namespace CourseForge\Ai\Batch\Driver;

use CourseForge\Ai\Batch\BatchHandle;
use CourseForge\Ai\Batch\BatchItemRequest;
use CourseForge\Ai\Batch\BatchItemResult;
use CourseForge\Ai\Batch\BatchStatus;
use CourseForge\Ai\Batch\JsonlChunker;
use CourseForge\Ai\Provider\OpenRouterProvider;
use CourseForge\Support\HttpException;
use CourseForge\Support\HttpResult;
use CourseForge\Support\Runtime;
use CourseForge\Support\Text;
use Generator;

/**
 * OpenRouter's batch queue, which is unlike the other three in almost every
 * mechanical detail and therefore has its own class.
 *
 * Nothing is uploaded and nothing is downloaded. The whole submission travels
 * as one JSON body to /api/beta/batches - OpenRouter writes the JSONL on its
 * own side - and the answers come back inside the ordinary status response, in
 * a `results` array. Their documentation is explicit that there is no separate
 * results endpoint, so poll and fetch here are the same GET made twice.
 *
 * Two properties of that endpoint shape everything below.
 *
 * The first is that the create body is stream-parsed, so its key order is part
 * of the contract: `endpoint` and `model` have to be on the wire before
 * `requests`, or a body that is otherwise perfectly valid comes back as a 400.
 * That is what body() is for, and why it is one literal with nothing merged
 * into it.
 *
 * The second is that neither a request cap nor a byte cap is published. The
 * only evidence that a ceiling exists at all is a typed `payload_too_large`
 * error, so the limits this driver works to are a guess: a submission is
 * chunked against them, halved once if a chunk is refused, and sent as however
 * many batches that takes. One CourseForge run can therefore stand behind more
 * than one OpenRouter batch - their ids live in the handle's `ref`, the poll
 * reports them as a single aggregate state, and the fetch reads them in turn.
 * The file lane refuses a run that needs more than one batch, and is right to,
 * because its limits are published numbers; refusing on the strength of a
 * number nobody published would stop a course being written for no reason,
 * while submitting only part of it would lose pages - and lost pages here are
 * unrecoverable, since OpenRouter offers no way to cancel what is already
 * running.
 *
 * It is beta, absent from the published OpenAPI spec, and an unknown path
 * under /api/beta answers with an HTML page rather than JSON. The contract is
 * treated as unstable throughout: every response is checked for being JSON at
 * all before it is read, and the status vocabulary is mapped by name with
 * anything unrecognised counted as still running.
 */
final class OpenRouterInlineBatch
{
    /** The only endpoint field a chat batch may declare. One shape per batch, no mixing. */
    public const ENDPOINT = '/v1/chat/completions';

    /** Where the ids of every batch behind one run are kept inside the handle. */
    public const REF_IDS = 'batch_ids';

    /** "The only supported completion window is 24h" - there is no flex option. */
    private const WINDOW_SECONDS = 86400;

    /** Inputs and results are held as JSONL in cloud storage for 30 days. */
    private const RETENTION_DAYS = 30;

    public function __construct(private readonly OpenRouterProvider $provider)
    {
    }

    /**
     * Hands the whole submission over, in as few batches as it takes.
     *
     * @param array<int,BatchItemRequest> $items
     * @param string $model the plain OpenRouter slug, applied batch-wide
     */
    public function submit(array $items, string $model): BatchHandle
    {
        $items = array_values($items);
        if ($items === []) {
            throw HttpException::unprocessable('There is nothing to submit.');
        }
        if (trim($model) === '') {
            throw HttpException::unprocessable('An OpenRouter batch needs a model, and none was given.');
        }

        [$rows, $sizes] = $this->encode($items);

        // Measured rather than estimated: the rows exist already, so the
        // chunker is given their real encoded length instead of JsonlChunker's
        // default guess. Bytes are the bound that matters here - the request
        // count is a defensive number rather than a published one.
        $chunks = (new JsonlChunker($this->provider->batchLimits()))->chunk(
            $items,
            static fn (BatchItemRequest $item): int => $sizes[$item->customId] ?? 1,
        );

        $accepted = [];
        try {
            foreach ($chunks as $chunk) {
                $this->create($chunk, $rows, $model, $accepted);
            }
        } catch (HttpException $e) {
            // Anything already accepted is running and billing, and cannot be
            // stopped. The operator has to be told which ones they are.
            throw $this->orphaned($e, self::idsOf($accepted));
        }

        $ids = self::idsOf($accepted);
        $createdAt = null;
        foreach ($accepted as $batch) {
            $createdAt ??= self::timestamp($batch['created_at'] ?? null);
        }
        $createdAt ??= time();

        // The batch object carries no expiry field of its own, so both
        // deadlines are derived: the window is fixed at 24 hours, and the
        // results are deleted 30 days after creation.
        return new BatchHandle(
            $ids[0],
            (string)($accepted[0]['status'] ?? ''),
            [self::REF_IDS => $ids],
            $createdAt + self::WINDOW_SECONDS,
            $createdAt + self::RETENTION_DAYS * 86400,
        );
    }

    /**
     * Where the run stands, as one answer over however many batches it took.
     *
     * A run is only finished when every part of it is, so anything still moving
     * decides the reported state. When they have all finished and disagree, a
     * part that completed outranks one that expired: its answers are worth
     * collecting, and the pages behind the dead part are written off one at a
     * time by the caller when no result arrives for them.
     */
    public function poll(BatchHandle $handle): BatchStatus
    {
        $ids = $this->batchIds($handle);

        $states = [];
        $raw = [];
        $counts = [];
        $errors = [];

        foreach ($ids as $id) {
            $batch = $this->read($id, false);

            $remote = strtolower(trim((string)($batch['status'] ?? '')));
            $states[] = self::state($remote);
            $raw[] = $remote !== '' ? $remote : 'unknown';

            foreach ((array)($batch['request_counts'] ?? []) as $key => $value) {
                $counts[(string)$key] = ($counts[(string)$key] ?? 0) + (int)$value;
            }

            $error = OpenRouterProvider::failureText($batch['error'] ?? null);
            if ($error !== '') {
                $errors[] = count($ids) > 1 ? $id . ': ' . $error : $error;
            }
        }

        return BatchStatus::fromCounts(
            self::combine($states),
            implode(', ', $raw),
            $counts,
            [self::REF_IDS => $ids],
            implode(' ', $errors),
        );
    }

    /**
     * The answers, read straight out of the status response.
     *
     * A generator rather than an array, because a finished batch of a few
     * hundred course pages is a JSON document of tens of megabytes and there is
     * no reason to hold two of them at once when a run spans several batches.
     * `results` is null while the batch is running and stays null when it
     * failed, expired or was cancelled - none of those keeps partial answers -
     * so yielding nothing is a normal outcome the caller already handles.
     *
     * @return Generator<string,BatchItemResult>
     */
    public function fetch(BatchHandle $handle): Generator
    {
        foreach ($this->batchIds($handle) as $id) {
            $batch = $this->read($id, true);
            $rows = is_array($batch['results'] ?? null) ? $batch['results'] : [];

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $result = $this->readRow($row);
                if ($result !== null) {
                    yield $result->customId => $result;
                }
            }
        }
    }

    /* ------------------------------------------------------------- building */

    /**
     * The create body, in the one order OpenRouter accepts.
     *
     * This is not a style choice. The endpoint stream-parses the body as it
     * arrives, so `endpoint` and `model` must already have been read by the
     * time it reaches `requests`; send them the other way round and a body that
     * validates perfectly by eye comes back as a 400. PHP preserves insertion
     * order through json_encode, which is the only reason this can be written
     * as ordinary code - and the reason it has to stay one literal. A later
     * array_merge(), array_filter() or `+` that rebuilds this array can move
     * `requests` in front of the other two keys and reintroduce the bug
     * silently, with nothing failing until a submission is refused.
     *
     * @param array<int,array<string,mixed>> $requests
     * @return array<string,mixed>
     */
    private static function body(string $model, array $requests): array
    {
        return [
            'endpoint' => self::ENDPOINT,
            'model' => $model,
            'requests' => array_values($requests),
        ];
    }

    /**
     * Every request encoded once, with the byte cost the chunker will split on.
     *
     * @param array<int,BatchItemRequest> $items
     * @return array{0:array<string,array<string,mixed>>,1:array<string,int>}
     */
    private function encode(array $items): array
    {
        $rows = [];
        $sizes = [];

        foreach ($items as $item) {
            $customId = $item->customId;
            if (trim($customId) === '') {
                throw HttpException::unprocessable(
                    'A page was queued without a custom id, so its answer could never be matched back.'
                );
            }
            if (isset($rows[$customId])) {
                throw HttpException::unprocessable(
                    'Two requests were queued under the custom id "' . $customId . '". OpenRouter requires them '
                    . 'to be unique inside one batch, and there is nothing else an answer is matched back by.'
                );
            }

            $body = $this->provider->batchBody($item->request);

            // A request body may leave `model` out and inherit the batch-level
            // one; a body that sets its own must match it exactly or the entire
            // submission is rejected. Having none is the only shape that cannot
            // disagree with the batch.
            unset($body['model']);

            $row = ['custom_id' => $customId, 'body' => $body];
            $encoded = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($encoded === false) {
                // Better here than as a validation failure hours later, where
                // the only clue would be a row number.
                throw HttpException::unprocessable(
                    'The page queued as "' . $customId . '" could not be encoded for the batch ('
                    . json_last_error_msg() . '). Check it for invalid characters and try again.'
                );
            }

            $rows[$customId] = $row;
            $sizes[$customId] = strlen($encoded) + 1; // plus the comma that joins it to the next
        }

        return [$rows, $sizes];
    }

    /**
     * Sends one chunk, and splits it once if the endpoint says it is too big.
     *
     * No size ceiling is published for this endpoint, so a 413 is the only way
     * to learn where it is. The answer is one halving and no more: a second
     * refusal at half the size means the assumed limits are wrong by more than
     * a factor of two, which is a thing to tell the operator about rather than
     * to keep bisecting silently while accepted batches pile up behind it.
     *
     * @param array<int,BatchItemRequest> $chunk
     * @param array<string,array<string,mixed>> $rows
     * @param array<int,array<string,mixed>> $accepted collects every batch created, by reference
     */
    private function create(array $chunk, array $rows, string $model, array &$accepted): void
    {
        $res = $this->post($chunk, $rows, $model);
        if (!self::tooLarge($res)) {
            $accepted[] = $this->accept($res);
            return;
        }

        if (count($chunk) < 2) {
            throw HttpException::unprocessable(
                'OpenRouter refused a single page as too large to queue. Shorten the course context or that '
                . 'page brief, or generate this one live instead.'
            );
        }

        $half = intdiv(count($chunk), 2);
        foreach ([array_slice($chunk, 0, $half), array_slice($chunk, $half)] as $piece) {
            $retry = $this->post($piece, $rows, $model);
            if (self::tooLarge($retry)) {
                throw HttpException::unprocessable(
                    'OpenRouter refused this submission as too large even at half the size (' . count($piece)
                    . ' pages). Queue fewer pages at a time.'
                );
            }
            $accepted[] = $this->accept($retry);
        }
    }

    /**
     * @param array<int,BatchItemRequest> $chunk
     * @param array<string,array<string,mixed>> $rows
     */
    private function post(array $chunk, array $rows, string $model): HttpResult
    {
        $requests = [];
        foreach ($chunk as $item) {
            $requests[] = $rows[$item->customId];
        }

        return $this->provider->betaRequest(
            'POST',
            $this->provider->betaUrl('/batches'),
            self::body($model, $requests),
            true,
        );
    }

    /**
     * A created batch, or the reason there is not one.
     *
     * @return array<string,mixed>
     */
    private function accept(HttpResult $res): array
    {
        $batch = $this->assertBeta($res, 'the batch submission', $this->provider->betaUrl('/batches'));

        $id = trim((string)($batch['id'] ?? ''));
        if ($id === '') {
            // 202 Accepted means the batch was persisted and queued for
            // validation, and its id is the whole of what CourseForge gets to
            // keep. Without one the submission is unrecoverable.
            throw HttpException::badRequest(
                $this->provider->label() . ' accepted the batch but returned no id, so it could never be polled: '
                . Text::snippet($res->raw)
            );
        }
        $batch['id'] = $id;

        return $batch;
    }

    /* -------------------------------------------------------------- reading */

    /**
     * One batch object. The same call answers "where is it" and "what did it say".
     *
     * @return array<string,mixed>
     */
    private function read(string $id, bool $withResults): array
    {
        // Ids look like batch_1a2b3c, but they came off the wire, so they are
        // encoded rather than trusted into a path.
        $url = $this->provider->betaUrl('/batches/' . rawurlencode($id));
        $res = $this->provider->betaRequest('GET', $url, null, $withResults);

        return $this->assertBeta($res, 'the batch status', $url);
    }

    /**
     * One row of the results array.
     *
     * It can fail in three unrelated ways and only the first is obvious.
     * `error` is set when the request never produced a response at all. A
     * populated `response` with a non-2xx `status_code` is a per-request
     * rejection inside an HTTP 200 download - the batch succeeded and this one
     * line did not. And a `status_code` of 200 still proves nothing on
     * OpenRouter, where a failure that happened after the model started
     * producing arrives as a successful response with an error in the body.
     *
     * @param array<string,mixed> $row
     */
    private function readRow(array $row): ?BatchItemResult
    {
        $customId = trim((string)($row['custom_id'] ?? ''));
        if ($customId === '') {
            return null;
        }

        // Exactly one of `response` and `error` is populated per result.
        $error = $row['error'] ?? null;
        if (is_array($error) && $error !== []) {
            return BatchItemResult::failed($customId, self::itemStatus($error), $error);
        }
        if (is_string($error) && trim($error) !== '') {
            return BatchItemResult::failed($customId, BatchItemResult::ERRORED, ['message' => trim($error)]);
        }

        $response = is_array($row['response'] ?? null) ? $row['response'] : [];
        $status = (int)($response['status_code'] ?? 0);
        $body = is_array($response['body'] ?? null) ? $response['body'] : [];

        if ($status !== 0 && ($status < 200 || $status >= 300)) {
            $envelope = is_array($body['error'] ?? null) ? $body['error'] : ['message' => 'HTTP ' . $status . '.'];
            return BatchItemResult::failed($customId, BatchItemResult::ERRORED, $envelope, $status);
        }

        // The status is deliberately not carried past this point. It is a 200 -
        // that is the whole problem with this branch - and prefixing the
        // operator's error line with "HTTP 200" would read as a contradiction
        // while telling them nothing they can act on.
        $failure = OpenRouterProvider::completionFailure($body);
        if ($failure !== null) {
            return BatchItemResult::failed($customId, BatchItemResult::ERRORED, $failure);
        }

        // The same extraction chat() uses, so a queued page and a live one are
        // read out of the response by one piece of code rather than two.
        $text = $this->provider->batchText($body);
        if ($text === '') {
            return BatchItemResult::failed(
                $customId,
                BatchItemResult::ERRORED,
                ['message' => 'The model answered this page with no text at all.'],
            );
        }

        // usage carries `cost` as well as the token counts, and a queued run is
        // the only place the accounting for a whole course is reported.
        $usage = is_array($body['usage'] ?? null) ? $body['usage'] : null;

        return BatchItemResult::ok($customId, $text, $usage);
    }

    /** @param array<string,mixed> $error */
    private static function itemStatus(array $error): string
    {
        $code = strtolower(trim((string)($error['code'] ?? $error['metadata']['error_type'] ?? '')));

        return str_contains($code, 'expired') ? BatchItemResult::EXPIRED : BatchItemResult::ERRORED;
    }

    /**
     * The documented progression is validating, in_progress, finalizing,
     * completed, with failed, expired, cancelling and cancelled off to the
     * side. An unrecognised word from a beta endpoint counts as work still in
     * progress: calling it finished would write every page off as unanswered,
     * and the word itself travels back untouched for the operator to read.
     */
    private static function state(string $remote): string
    {
        return match ($remote) {
            'validating' => BatchStatus::PENDING,
            'in_progress' => BatchStatus::RUNNING,
            'finalizing' => BatchStatus::FINALIZING,
            'completed' => BatchStatus::DONE,
            'failed' => BatchStatus::FAILED,
            'expired' => BatchStatus::EXPIRED,
            'cancelling', 'canceling' => BatchStatus::CANCELLING,
            'cancelled', 'canceled' => BatchStatus::CANCELLED,
            default => BatchStatus::RUNNING,
        };
    }

    /** @param array<int,string> $states */
    private static function combine(array $states): string
    {
        $states = array_values(array_unique($states));
        if ($states === []) {
            return BatchStatus::RUNNING;
        }
        if (count($states) === 1) {
            return $states[0];
        }

        foreach ([BatchStatus::PENDING, BatchStatus::RUNNING, BatchStatus::FINALIZING, BatchStatus::CANCELLING] as $open) {
            if (in_array($open, $states, true)) {
                return $open;
            }
        }
        foreach ([BatchStatus::DONE, BatchStatus::EXPIRED, BatchStatus::FAILED] as $ended) {
            if (in_array($ended, $states, true)) {
                return $ended;
            }
        }

        return BatchStatus::CANCELLED;
    }

    /* ------------------------------------------------------------ internals */

    /**
     * Every batch id this run stands behind.
     *
     * The handle's remote id comes first because that is the one written into
     * the run row and shown in the interface; the rest live in `ref`. A handle
     * stored before a submission ever spanned more than one batch carries only
     * the remote id, and keeps working unchanged.
     *
     * @return array<int,string>
     */
    private function batchIds(BatchHandle $handle): array
    {
        $ids = [];
        if (trim($handle->remoteId) !== '') {
            $ids[] = trim($handle->remoteId);
        }
        foreach ((array)($handle->ref[self::REF_IDS] ?? []) as $id) {
            if (is_string($id) && trim($id) !== '') {
                $ids[] = trim($id);
            }
        }

        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            throw HttpException::unprocessable('This run has no OpenRouter batch id to look up.');
        }

        return $ids;
    }

    /**
     * @param array<int,array<string,mixed>> $batches
     * @return array<int,string>
     */
    private static function idsOf(array $batches): array
    {
        $ids = [];
        foreach ($batches as $batch) {
            $id = trim((string)($batch['id'] ?? ''));
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * A refusal for size, in either of the two ways it is reported.
     *
     * The typed vocabulary is the reliable half: OpenRouter's own guidance is
     * to switch on error.metadata.error_type rather than on the status, because
     * the status is lossy across the API skins it serves.
     */
    private static function tooLarge(HttpResult $res): bool
    {
        if ($res->status === 413) {
            return true;
        }
        $type = is_array($res->data)
            ? strtolower(trim((string)($res->data['error']['metadata']['error_type'] ?? '')))
            : '';

        return $type === 'payload_too_large';
    }

    /**
     * The one place a beta response is turned into something readable.
     *
     * @return array<string,mixed>
     */
    private function assertBeta(HttpResult $res, string $what, string $url): array
    {
        $label = $this->provider->label();

        if ($res->unreachable()) {
            throw HttpException::badRequest($label . ': could not reach ' . $url . ' - ' . $res->error . '.');
        }
        if ($res->truncated()) {
            throw HttpException::badRequest(
                $label . ': the connection dropped part way through ' . $what . ' (' . $url . ') - '
                . $res->error . '. Nothing was stored; try again.'
            );
        }

        // An unknown path under /api/beta answers with an HTML 404 page rather
        // than a JSON error envelope, and Http keeps no response headers, so
        // the shape of the body has to stand in for Content-Type. A body that
        // will not decode means the route is gone, which is a different problem
        // from a request the route rejected.
        if (!is_array($res->data)) {
            throw HttpException::badRequest(
                $label . ': ' . $what . ' at ' . $url . ' did not answer with JSON (HTTP ' . $res->status . '). '
                . 'The batch queue is a beta endpoint and may have moved. Response started with: '
                . Text::snippet($res->raw)
            );
        }

        if ($res->status === 401 || $res->status === 403) {
            // The gate answers "No cookie auth credentials found", which reads
            // as though a browser session were wanted. It is not: a Bearer key
            // is the correct credential on /api/beta as much as on /api/v1.
            throw HttpException::badRequest(
                $label . ' refused ' . $what . ' (HTTP ' . $res->status . '). Check the API key and its limits. '
                . self::describe($res->data)
            );
        }
        if (!$res->ok()) {
            throw HttpException::badRequest(
                $label . ': ' . $what . ' failed (HTTP ' . $res->status . '): ' . self::describe($res->data)
            );
        }

        return $res->data;
    }

    /** @param array<string,mixed> $body */
    private static function describe(array $body): string
    {
        $text = OpenRouterProvider::failureText($body['error'] ?? null);

        return $text !== '' ? $text : Text::snippet((string)(json_encode($body, JSON_UNESCAPED_SLASHES) ?: ''));
    }

    /**
     * The same failure, with a note about what is still running behind it.
     *
     * A submission that spans several batches can be refused half way through,
     * and the batches already accepted keep going and keep billing. There is no
     * documented way to stop them, so the only honest thing to do is name them.
     *
     * @param array<int,string> $ids
     */
    private function orphaned(HttpException $error, array $ids): HttpException
    {
        if ($ids === []) {
            return $error;
        }

        $out = new HttpException(
            $error->getMessage() . ' ' . count($ids) . ' earlier part(s) of the same submission had already been '
            . 'accepted and are still running (' . implode(', ', $ids) . '). OpenRouter publishes no way to stop a '
            . 'batch, so they will finish or expire on their own and this run cannot collect them.',
            $error->status(),
        );
        Runtime::log('openrouter.batch.orphaned', $out);

        return $out;
    }

    /** A unix second out of whatever the field turned out to hold. */
    private static function timestamp(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && trim($value) !== '') {
            $text = trim($value);
            if (ctype_digit($text)) {
                return (int)$text > 0 ? (int)$text : null;
            }
            $parsed = strtotime($text);
            return $parsed !== false ? $parsed : null;
        }

        return null;
    }
}
