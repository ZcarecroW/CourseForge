<?php
declare(strict_types=1);

namespace CourseForge\Ai\Batch\Driver;

use CourseForge\Ai\Batch\BatchHandle;
use CourseForge\Ai\Batch\BatchItemResult;
use CourseForge\Ai\Batch\BatchLimits;
use CourseForge\Ai\Batch\BatchStatus;
use CourseForge\Ai\Provider\GeminiProvider;
use CourseForge\Support\Http;
use CourseForge\Support\HttpException;
use CourseForge\Support\HttpResult;
use Generator;
use Throwable;

/**
 * Gemini's batch queue, which is not a batch object but a long-running
 * Operation.
 *
 * That single difference is what keeps this out of every other driver.
 * Submitting does not return a batch with a status; it returns an Operation
 * named `batches/{id}` whose progress lives under `.metadata` and whose answers
 * appear under `.response` only once `.done` is true. There is no separate
 * results endpoint to call and no results object to hold a reference to - the
 * answers are a field on the same Operation that reports the progress.
 *
 * Three details in that Operation cost real work if they are taken at face
 * value. The state string is spelled `JOB_STATE_SUCCEEDED` in Google's own
 * REST example and `BATCH_STATE_SUCCEEDED` in the API reference, and both are
 * in the wild, so every comparison here is made on the suffix. The counters in
 * `batchStats` are int64 values serialised as JSON strings, so `"1200" > 999`
 * is a string comparison in PHP and quietly wrong - BatchStatus::fromCounts
 * casts them. And a job can finish as SUCCEEDED with individual requests
 * failed, so every line is read defensively rather than trusted because the job
 * as a whole was fine.
 *
 * The deadline matters more here than anywhere else. Gemini targets 24 hours
 * but the hard rule is 48: a job still pending or running at that point flips
 * to EXPIRED and yields ZERO results - not partial ones, none - and the whole
 * submission has to be made again. Every handle this returns therefore carries
 * an `expiresAt` 48 hours out so a scheduler can see the cliff coming, and an
 * expired poll spells the rule out in its error text. Results that did arrive
 * stay downloadable for six weeks.
 *
 * Only the inline lane is built. Google offers a second one that uploads a
 * JSONL file of up to 2 GB, but reaching it means driving the resumable File
 * API: a start request whose *response header* `x-goog-upload-url` names the
 * address the bytes then go to. Support\Http returns bodies and never exposes
 * response headers, so that lane cannot be driven without changing shared
 * transport code, and 20 MB of inline requests is around 2,500 course pages -
 * more than any single CourseForge run submits. Reading a results file is a
 * plain GET and is supported, because a large job may be answered with one
 * whatever it was submitted as.
 */
final class GeminiLroBatch
{
    /**
     * The hard expiry, in seconds. Not the 24 hour target: this is the point at
     * which a job that has not finished is thrown away with nothing to collect.
     */
    public const EXPIRY_SECONDS = 172800;

    /** How long finished results stay downloadable - six weeks. */
    public const RETENTION_DAYS = 42;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $maxInlineBytes,
        private readonly int $metaTimeout,
        private readonly int $fetchTimeout,
    ) {
    }

    /* --------------------------------------------------------------- submit */

    /**
     * Starts the Operation and returns what it will be found by.
     *
     * The bodies arrive already built, because what a GenerateContentRequest
     * looks like is the provider's knowledge and the lifecycle of an Operation
     * is this class's. The nesting below is not a mistake in the reading:
     * `input_config.requests.requests` really is doubled, and the per-request
     * id really does live at `metadata.key` for an inline submission while the
     * file lane puts the same value at the top level of each line.
     *
     * @param array<int,array{key:string,request:array<string,mixed>}> $rows
     */
    public function submit(string $model, array $rows): BatchHandle
    {
        if ($rows === []) {
            throw HttpException::unprocessable('There is nothing to submit.');
        }

        $requests = [];
        foreach ($rows as $row) {
            $requests[] = [
                'request' => $row['request'],
                'metadata' => ['key' => $row['key']],
            ];
        }

        $body = self::encode([
            'batch' => [
                // Required, and the only thing that makes one job legible in
                // the AI Studio list. Batch creation is not idempotent, so two
                // submissions of the same work become two jobs and two bills -
                // the timestamp is what tells them apart afterwards.
                'display_name' => 'courseforge-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)),
                'input_config' => ['requests' => ['requests' => $requests]],
            ],
        ]);

        // Measured on the bytes that are actually sent rather than estimated,
        // because at this point they exist. A 20 MB overshoot answered by the
        // server is a rejection after the whole upload; answered here it names
        // the ceiling and costs nothing.
        if (strlen($body) > $this->maxInlineBytes) {
            throw HttpException::unprocessable(
                'This submission is ' . self::megabytes(strlen($body)) . ' MB and Google Gemini accepts at most '
                . self::megabytes($this->maxInlineBytes) . ' MB of requests in one batch. Split the selection into '
                . 'smaller runs.'
            );
        }

        $url = $this->baseUrl . '/models/' . $model . ':batchGenerateContent';
        $res = $this->call('POST', $url, $body, $this->metaTimeout);
        $this->assertOk($res, 'the batch submission', $url);
        $this->assertJson($res, 'the batch submission', $url);

        $data = is_array($res->data) ? $res->data : [];
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw HttpException::badRequest(
                'Google Gemini accepted the batch but returned no operation name, so there is nothing to poll. '
                . 'The work may still be running: check the AI Studio batch list before submitting it again.'
            );
        }

        // The provider's own creation time is preferred over this server's
        // clock, since both deadlines are counted from it and a host running a
        // few hours out would otherwise report the cliff in the wrong place.
        $created = self::timestamp($data['metadata']['createTime'] ?? null) ?: time();

        return new BatchHandle(
            $name,
            self::stateOf($data),
            ['inline' => true],
            $created + self::EXPIRY_SECONDS,
            $created + (self::RETENTION_DAYS * 86400),
        );
    }

    /* ----------------------------------------------------------------- poll */

    public function poll(BatchHandle $handle): BatchStatus
    {
        $url = $this->operationUrl($handle);
        $res = $this->call('GET', $url, null, $this->metaTimeout);
        $this->assertOk($res, 'the batch status', $url);
        $this->assertJson($res, 'the batch status', $url);

        $data = is_array($res->data) ? $res->data : [];
        $raw = self::stateOf($data);
        $done = ($data['done'] ?? false) === true;
        $state = self::mapState($raw, $done);

        $failure = is_array($data['error'] ?? null) ? $data['error'] : [];
        if ($failure !== [] && $state !== BatchStatus::CANCELLED && $state !== BatchStatus::EXPIRED) {
            // A google.longrunning.Operation reports its own failure in
            // `error`. Individual requests that failed inside a healthy job
            // never set it, so this only fires when the job itself died.
            $state = BatchStatus::FAILED;
        }

        $stats = is_array($data['metadata']['batchStats'] ?? null) ? $data['metadata']['batchStats'] : [];
        $counts = [
            'total' => $stats['requestCount'] ?? 0,
            'succeeded' => $stats['successfulRequestCount'] ?? 0,
            'errored' => $stats['failedRequestCount'] ?? 0,
            'pending' => $stats['pendingRequestCount'] ?? 0,
        ];

        $ref = [];
        $file = trim((string)($data['response']['responsesFile'] ?? ''));
        if ($file !== '') {
            // Only knowable once the job has finished, and the reason poll is
            // allowed to write back into the handle at all.
            $ref['responses_file'] = $file;
        }

        return BatchStatus::fromCounts($state, $raw, $counts, $ref, self::why($state, $failure));
    }

    /* ---------------------------------------------------------------- fetch */

    /**
     * The answers, whichever of the two places they came back in.
     *
     * The Operation has to be read again rather than reusing what the poll
     * saw, because for an inline batch the answers are a field on it and can
     * run to the full 20 MB - far too large to have carried along in the
     * handle's reference bag, which is persisted into a database column.
     *
     * @return iterable<string,BatchItemResult>
     */
    public function fetch(BatchHandle $handle): iterable
    {
        $url = $this->operationUrl($handle);
        $res = $this->call('GET', $url, null, $this->fetchTimeout);
        $this->assertOk($res, 'the batch results', $url);
        $this->assertJson($res, 'the batch results', $url);

        $data = is_array($res->data) ? $res->data : [];
        $response = is_array($data['response'] ?? null) ? $data['response'] : [];

        $file = trim((string)($response['responsesFile'] ?? $handle->refValue('responses_file')));
        if ($file !== '') {
            return $this->download($file);
        }

        return self::readInline($response);
    }

    /* --------------------------------------------------------------- cancel */

    public function cancel(BatchHandle $handle): bool
    {
        $url = $this->operationUrl($handle) . ':cancel';

        // An explicit empty JSON object rather than no body at all: this is a
        // POST with an empty message, and a request with neither a body nor a
        // Content-Length is the kind of thing an intermediary rewrites.
        $res = $this->call('POST', $url, '{}', $this->metaTimeout);

        // A job that already ended, or one that was pruned from the list, is
        // reported the same way as a job that never existed. Neither is worth
        // raising: the caller only wanted it stopped if it still could be.
        if (!$res->ok() && !in_array($res->status, [400, 404, 409], true)) {
            $this->assertOk($res, 'the batch cancellation', $url);
        }

        // Accepted means processing of anything not yet started will stop, not
        // that the job has come to a halt.
        return $res->ok();
    }

    /* -------------------------------------------------------------- release */

    /**
     * Deletes the results file, and only that.
     *
     * The batch job is deliberately left alone. An inline job holds the only
     * copy of its answers, costs no storage and disappears by itself after six
     * weeks, so deleting it buys nothing and forecloses a re-read. A results
     * file is different: it is a File API object counting against the project's
     * 20 GB storage limit, that limit is shared with every other batch the
     * project runs, and by the time this is called CourseForge has the pages.
     */
    public function release(BatchHandle $handle): void
    {
        $file = trim($handle->refValue('responses_file'));
        if ($file === '') {
            return;
        }

        $url = $this->baseUrl . '/' . self::fileSegment($file);
        $res = $this->call('DELETE', $url, null, $this->metaTimeout);

        // Already gone is the outcome that was wanted.
        if (!$res->ok() && $res->status !== 404) {
            $this->assertOk($res, 'the deletion of the results file', $url);
        }
    }

    /* --------------------------------------------------------- reading lines */

    /**
     * The inline answers, in the doubled nesting Google returns them in.
     *
     * `.response.inlinedResponses.inlinedResponses[]` is what the reference
     * documents. The outer key is accepted as a plain list too, because that
     * shape appears in more than one worked example and guessing wrong here
     * means silently reading nothing at all out of a batch that succeeded.
     *
     * @param array<string,mixed> $response
     * @return Generator<string,BatchItemResult>
     */
    private static function readInline(array $response): Generator
    {
        $inlined = $response['inlinedResponses'] ?? [];
        if (is_array($inlined) && isset($inlined['inlinedResponses']) && is_array($inlined['inlinedResponses'])) {
            $inlined = $inlined['inlinedResponses'];
        }
        if (!is_array($inlined)) {
            return;
        }

        foreach ($inlined as $line) {
            if (!is_array($line)) {
                continue;
            }
            $key = self::keyOf($line);
            if ($key === '') {
                continue;
            }
            yield $key => self::resultFor($key, $line);
        }
    }

    /**
     * A results file, downloaded and read a line at a time.
     *
     * The download is a GET against a different path prefix from everything
     * else - `/download/v1beta/...` rather than `/v1beta/...` - and needs
     * `?alt=media` or it answers with the File's metadata instead of its
     * contents.
     *
     * @return Generator<string,BatchItemResult>
     */
    private function download(string $file): Generator
    {
        $url = $this->downloadRoot() . '/' . self::fileSegment($file) . ':download?alt=media';
        $res = $this->call('GET', $url, null, $this->fetchTimeout);
        $this->assertOk($res, 'the batch results file', $url);

        foreach (self::jsonLines($res->raw) as $line) {
            $key = self::keyOf($line);
            if ($key === '') {
                continue;
            }
            yield $key => self::resultFor($key, $line);
        }
    }

    /**
     * One answer, from either lane.
     *
     * A line is a GenerateContentResponse or a google.rpc.Status, and which of
     * the two is decided per line rather than per job: a batch can finish as
     * SUCCEEDED with a handful of its requests failed, and reading the job's
     * own state as the answer for every line inside it is how those get stored
     * as blank pages.
     *
     * @param array<string,mixed> $line
     */
    private static function resultFor(string $key, array $line): BatchItemResult
    {
        $error = is_array($line['error'] ?? null) ? $line['error'] : null;
        if ($error !== null) {
            // Handed on exactly as it arrived. A google.rpc.Status carries the
            // canonical code, the status name and often a `details` array, and
            // flattening that to a sentence here would throw away the only
            // thing that says whether the request is worth sending again.
            return BatchItemResult::failed($key, self::statusFor($error), $error);
        }

        $response = is_array($line['response'] ?? null)
            ? $line['response']
            : (isset($line['candidates']) ? $line : []);

        if ($response === []) {
            return BatchItemResult::failed($key, BatchItemResult::ERRORED, [
                'message' => 'The batch line carried neither a response nor an error.',
            ]);
        }

        // Each line names the model that answered it, and each may carry that
        // model's lifecycle stage. A queued run is the job most exposed to a
        // retirement, so the warning is read here as well as on the live path.
        GeminiProvider::noteModelStatus($response, trim((string)($response['modelVersion'] ?? 'the batch model')));

        // The same reader the live path uses, on purpose: a batch line is the
        // same GenerateContentResponse a live call returns, and a refusal or a
        // truncation has to mean the same thing in both places.
        $why = GeminiProvider::rejection($response);
        if ($why !== '') {
            return BatchItemResult::failed($key, BatchItemResult::ERRORED, [
                'message' => $why,
                'type' => strtoupper(trim((string)($response['candidates'][0]['finishReason'] ?? 'NO_CANDIDATES'))),
            ]);
        }

        $usage = is_array($response['usageMetadata'] ?? null) ? $response['usageMetadata'] : null;
        return BatchItemResult::ok($key, GeminiProvider::readText($response), $usage);
    }

    /**
     * The custom id of one line.
     *
     * Two placements for one concept: an inline answer carries it at
     * `metadata.key`, a line of a results file at the top level. Both are
     * accepted because a job submitted inline can still be answered with a
     * file. Correlating by position instead is documented as safe and is not -
     * the guide's own file example omits the key entirely, which is exactly how
     * a course ends up with every page's text under the wrong title.
     *
     * @param array<string,mixed> $line
     */
    private static function keyOf(array $line): string
    {
        $key = $line['metadata']['key'] ?? $line['key'] ?? '';
        return is_scalar($key) ? trim((string)$key) : '';
    }

    /** @param array<string,mixed> $error */
    private static function statusFor(array $error): string
    {
        $status = strtoupper(trim((string)($error['status'] ?? '')));
        return $status === 'CANCELLED' || $status === 'CANCELED'
            ? BatchItemResult::CANCELLED
            : BatchItemResult::ERRORED;
    }

    /**
     * JSONL, one object per line.
     *
     * Walked by offset rather than split into an array, because the body is
     * already in memory once and there is no reason to hold a second copy of it
     * broken into lines - and rather than by strtok, whose cursor is global to
     * the process and would be moved by anything the caller does between two
     * lines. Support\Http buffers its responses, so this is as far as streaming
     * reaches; consuming a generator is what keeps the decoded objects from all
     * existing at the same moment.
     *
     * @return Generator<int,array<string,mixed>>
     */
    private static function jsonLines(string $body): Generator
    {
        $length = strlen($body);
        $at = 0;

        while ($at < $length) {
            $break = strpos($body, "\n", $at);
            $end = $break === false ? $length : $break;

            $line = trim(substr($body, $at, $end - $at));
            $at = $end + 1;

            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                yield $decoded;
            }
        }
    }

    /* ------------------------------------------------------------ internals */

    /**
     * The state string, matched on its suffix.
     *
     * Google's batch guide compares against `JOB_STATE_SUCCEEDED` and the API
     * reference documents the enum as `BATCH_STATE_SUCCEEDED`. Both prefixes
     * are in the wild, and a full-string comparison against either one is a
     * batch that never appears to finish.
     */
    private static function mapState(string $raw, bool $done): string
    {
        $suffix = (string)preg_replace('/^(?:JOB|BATCH|OPERATION)_STATE_/', '', strtoupper(trim($raw)));

        return match ($suffix) {
            'PENDING', 'QUEUED' => BatchStatus::PENDING,
            'RUNNING' => BatchStatus::RUNNING,
            'SUCCEEDED' => BatchStatus::DONE,
            'FAILED' => BatchStatus::FAILED,
            'CANCELLING', 'CANCELING' => BatchStatus::CANCELLING,
            'CANCELLED', 'CANCELED' => BatchStatus::CANCELLED,
            'EXPIRED' => BatchStatus::EXPIRED,
            // An unrecognised state is read from `.done` instead, which is the
            // Operation contract itself and cannot drift the way the enum has.
            default => $done ? BatchStatus::DONE : BatchStatus::RUNNING,
        };
    }

    /** @param array<string,mixed> $data */
    private static function stateOf(array $data): string
    {
        return trim((string)($data['metadata']['state'] ?? ''));
    }

    /**
     * What to write in the run's error field, for the states that need saying.
     *
     * EXPIRED earns a sentence of its own. Everywhere else an expired batch
     * still hands back whatever it managed to answer first, and a person who
     * has seen that elsewhere will assume the same here and go looking for the
     * partial results. There are none.
     *
     * @param array<string,mixed> $failure a google.rpc.Status, or empty
     */
    private static function why(string $state, array $failure): string
    {
        if ($state === BatchStatus::EXPIRED) {
            return 'The batch was still queued 48 hours after it was submitted, so Google expired it. '
                . 'An expired Gemini batch returns no results at all, not even for the requests it had already '
                . 'finished, and nothing is billed for it. The pages have to be submitted again.';
        }
        if ($failure === []) {
            return '';
        }

        $status = trim((string)($failure['status'] ?? ''));
        $message = trim((string)($failure['message'] ?? ''));
        $code = $failure['code'] ?? null;

        if ($message === '' && $status === '') {
            return 'The batch failed and Google gave no reason with it.';
        }
        return trim(($status !== '' ? $status : 'code ' . (is_numeric($code) ? (int)$code : '?')) . ': ' . $message);
    }

    /**
     * The Operation's own URL.
     *
     * `remoteId` is a resource name such as `batches/123456` and goes into the
     * path with its slash intact, so it is checked against a character set
     * rather than encoded - the same reasoning as the model id in the provider,
     * and the same guarantee that nothing in it can climb out of the path.
     */
    private function operationUrl(BatchHandle $handle): string
    {
        $name = trim($handle->remoteId, " \t\n\r/");
        if ($name === '' || str_contains($name, '..') || preg_match('#^[A-Za-z0-9._/-]+$#', $name) !== 1) {
            throw HttpException::unprocessable(
                'This run has no usable Google Gemini batch name stored for it, so it cannot be looked up. '
                . 'The name should look like batches/123456.'
            );
        }
        return $this->baseUrl . '/' . $name;
    }

    /** A File API resource name, checked the same way and for the same reason. */
    private static function fileSegment(string $file): string
    {
        $file = trim($file, " \t\n\r/");
        if ($file === '' || str_contains($file, '..') || preg_match('#^[A-Za-z0-9._/-]+$#', $file) !== 1) {
            throw HttpException::unprocessable(
                'Google Gemini named the results file "' . $file . '", which is not a File API name and cannot '
                . 'be downloaded. The answers may still be readable from the batch job itself.'
            );
        }
        return $file;
    }

    /**
     * Where a results file is downloaded from.
     *
     * The download surface is a sibling of the API version segment rather than
     * a path underneath it: `https://host/download/v1beta/files/x:download`.
     * The provider normalises every base URL to end in exactly one version
     * segment, so lifting it out is a substitution and not a guess.
     */
    private function downloadRoot(): string
    {
        if (preg_match('#^(.*)/(v1beta\d*|v1)$#i', $this->baseUrl, $match) === 1) {
            return $match[1] . '/download/' . $match[2];
        }
        throw HttpException::unprocessable(
            'The base URL for this account (' . $this->baseUrl . ') does not end in an API version, so '
            . 'CourseForge cannot work out where to download the batch results from. It should end in /v1beta.'
        );
    }

    /* ---------------------------------------------------------- the transport */

    /**
     * One request, with the header set built from scratch every time.
     *
     * Never a reused handle and never inherited headers. An `Authorization`
     * header left over from another provider is enough to make this host answer
     * 401 ACCESS_TOKEN_TYPE_UNSUPPORTED even with a perfectly good
     * `x-goog-api-key` sitting beside it, and the error it produces describes
     * an OAuth token nobody sent.
     *
     * The body is passed pre-encoded rather than as an array so that the byte
     * count checked against the 20 MB inline ceiling is the count that is
     * actually sent, not an estimate of it.
     */
    private function call(string $method, string $url, ?string $body, int $timeout): HttpResult
    {
        $headers = GeminiProvider::authHeaders($this->apiKey);
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        try {
            // follow: false - the key travels in a custom header, and cURL only
            // strips `Authorization` across a redirect, never x-goog-api-key.
            return Http::request($method, $url, $headers, $body, $timeout, false);
        } catch (Throwable $e) {
            throw HttpException::badRequest(
                'Google Gemini: the request to ' . $url . ' crashed - ' . $e->getMessage()
            );
        }
    }

    /**
     * Every failure reported in Google's own words.
     *
     * Shared with the provider rather than written again, so that the same 401
     * reads identically whether it happened on a live completion or on a batch
     * poll - which during the API key migration is the difference between an
     * operator diagnosing one problem and chasing two.
     */
    private function assertOk(HttpResult $res, string $what, string $url): void
    {
        if (!$res->ok()) {
            throw HttpException::badRequest(
                GeminiProvider::describeFailure($res, $what, $url, $this->apiKey)
            );
        }
    }

    private function assertJson(HttpResult $res, string $what, string $url): void
    {
        if (!is_array($res->data)) {
            throw HttpException::badRequest(
                GeminiProvider::describeFailure($res, $what . ' (the reply was not JSON)', $url, $this->apiKey)
            );
        }
    }

    /** @param array<string,mixed> $payload */
    private static function encode(array $payload): string
    {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($json === false) {
            throw HttpException::unprocessable(
                'The batch could not be encoded as JSON: ' . json_last_error_msg()
                . '. One of the page briefs most likely contains text that is not valid UTF-8.'
            );
        }
        return $json;
    }

    private static function megabytes(int $bytes): string
    {
        return number_format($bytes / BatchLimits::MEGABYTE, 1);
    }

    /** RFC 3339 to a Unix timestamp, and 0 for anything unparseable. */
    private static function timestamp(mixed $value): int
    {
        if (!is_string($value) || trim($value) === '') {
            return 0;
        }
        $time = strtotime($value);
        return $time === false ? 0 : $time;
    }
}
