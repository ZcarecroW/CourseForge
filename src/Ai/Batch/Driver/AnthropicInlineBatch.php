<?php
declare(strict_types=1);

namespace CourseForge\Ai\Batch\Driver;

use Closure;
use CourseForge\Ai\Batch\BatchHandle;
use CourseForge\Ai\Batch\BatchItemResult;
use CourseForge\Ai\Batch\BatchLimits;
use CourseForge\Ai\Batch\BatchStatus;
use CourseForge\Ai\Provider\AnthropicProvider;
use CourseForge\Support\Config;
use CourseForge\Support\Http;
use CourseForge\Support\HttpException;
use CourseForge\Support\HttpResult;
use CourseForge\Support\Text;
use Generator;
use Throwable;

/**
 * The Anthropic Message Batches queue.
 *
 * It is the simplest of the four batch styles to submit to and the hardest to
 * read back. Submitting is one JSON body: the requests go inline, there is no
 * file to upload and nothing to delete afterwards, so a submission is a single
 * POST that either is accepted whole or is refused whole. Reading back is the
 * opposite. The answers arrive as one JSONL body with a line per request, and a
 * batch may hold 100,000 of them - a course of long pages is comfortably a
 * gigabyte of prose. Nothing that large may ever exist as a PHP string, so the
 * download here is driven by a cURL write callback that cuts the incoming bytes
 * into whole lines and spools them, and the caller is handed a generator that
 * decodes one line at a time. Peak memory is one network chunk plus one page.
 *
 * The driver is a separate object from the provider because the two answer
 * different questions. The provider knows what a Messages request body looks
 * like - which parameters this model accepts, where the cache breakpoint goes,
 * how much output to ask for. This class knows the queue protocol: what a batch
 * object is called, which of the three processing states means finished, that
 * results come back in an arbitrary order and have to be keyed by custom id,
 * and that a cancelled batch still ends as "ended" with whatever it managed to
 * answer. It is handed finished request bodies and gives back finished results.
 *
 * Two deadlines come out of a submission and they are not the same date. The
 * batch itself dies 24 hours after creation and anything still queued comes
 * back as `expired`, unbilled. The results stay downloadable for 29 days from
 * creation, after which the batch object is still listed but `results_url` no
 * longer serves anything. Both are recorded on the handle, and the second one
 * is also written into the reference bag, because that is the only part of a
 * handle CourseForge persists in full.
 */
final class AnthropicInlineBatch
{
    /** Only used to prefix the two failures that happen before a response exists. */
    private const LABEL = 'Anthropic';

    private const PATH = '/v1/messages/batches';

    /** Requests per create call. The byte ceiling below usually binds first. */
    private const MAX_REQUESTS = 100000;

    /** 256 MB per create call, answered with 413 request_too_large above it. */
    private const MAX_BYTES = 256 * BatchLimits::MEGABYTE;

    /** Hard deadline for processing: created_at + 24 h, then `expired`. */
    private const WINDOW_SECONDS = 86400;

    /** How long results stay downloadable, counted from creation and not from the end. */
    private const RETENTION_DAYS = 29;

    /**
     * How much of a results download is held in memory before it spills to a
     * temp file. Small enough that an ordinary run never touches the disk, low
     * enough that a 100,000-page batch cannot exhaust the process.
     */
    private const SPOOL_BYTES = 8388608;

    /** How much of a failed download is read back to explain it. Error bodies are small. */
    private const ERROR_BYTES = 8192;

    /**
     * @param string  $baseUrl  the account's endpoint, with no trailing slash and no /v1
     * @param array<string,string> $headers complete request headers, x-api-key and
     *                                      anthropic-version included - the version header is
     *                                      mandatory on the batch endpoints too, and a missing
     *                                      one is the most common 400 against this API
     * @param Closure $assertOk (HttpResult, string $what, string $url): void - the provider's
     *                          own error ladder, borrowed rather than reimplemented so a batch
     *                          failure reads exactly like every other failure from this account
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly array $headers,
        private readonly Closure $assertOk,
        private readonly int $metaTimeout,
        private readonly int $downloadTimeout,
    ) {
    }

    /**
     * What one submission may contain, and how long its answers survive.
     *
     * The two size bounds are checked here rather than trusted: 100,000 rows of
     * course prose is nowhere near 256 MB, but 256 MB is reached at around
     * 30,000 pages, so the byte ceiling is the one that binds on this workload
     * and the row count is the second bound, not the first.
     */
    public static function limits(): BatchLimits
    {
        return new BatchLimits(self::MAX_REQUESTS, self::MAX_BYTES, null, '24h', self::RETENTION_DAYS);
    }

    /**
     * Hands the whole submission over in one body.
     *
     * The rows are pre-built Messages bodies - this class never decides what
     * goes in one - and are sent as `{"requests":[{custom_id, params}]}`. The
     * body is encoded once, here, so its real size can be measured before it is
     * put on the wire: a 413 is only returned after the whole thing has been
     * uploaded, which for a large course is the expensive half of the call.
     *
     * Nothing else about the request is validated, and that is not an oversight
     * to fix later: Anthropic validates `params` asynchronously, so a malformed
     * body is accepted at submit time and surfaces as an `errored` result when
     * the batch ends, up to 24 hours later. The custom ids are checked because
     * they are the one thing this side can get wrong in a way that loses the
     * answer rather than reporting it.
     *
     * @param array<int,array{custom_id:string,params:array<string,mixed>}> $rows
     */
    public function submit(array $rows): BatchHandle
    {
        $rows = array_values($rows);
        if ($rows === []) {
            throw HttpException::unprocessable('There is nothing to submit.');
        }
        if (count($rows) > self::MAX_REQUESTS) {
            throw HttpException::unprocessable(
                'Anthropic accepts at most ' . number_format(self::MAX_REQUESTS)
                . ' requests per batch, and this submission has ' . number_format(count($rows)) . '.'
            );
        }

        $seen = [];
        foreach ($rows as $row) {
            $customId = (string)($row['custom_id'] ?? '');
            self::assertCustomId($customId);
            if (isset($seen[$customId])) {
                // Results are matched back by custom id and nothing else, so a
                // repeated id means two pages sharing one answer and one page
                // silently getting none.
                throw HttpException::unprocessable(
                    'The batch contains "' . $customId . '" twice, and every custom id has to be unique within it.'
                );
            }
            $seen[$customId] = true;
        }

        $body = json_encode(
            ['requests' => $rows],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($body === false) {
            throw HttpException::badRequest(
                self::LABEL . ': the batch could not be encoded as JSON - ' . json_last_error_msg() . '.'
            );
        }
        if (strlen($body) > self::MAX_BYTES) {
            throw HttpException::unprocessable(
                'This submission is ' . number_format(strlen($body) / BatchLimits::MEGABYTE, 1)
                . ' MB and Anthropic accepts at most '
                . number_format(self::MAX_BYTES / BatchLimits::MEGABYTE, 0)
                . ' MB per batch. Queue the run in smaller pieces.'
            );
        }

        $res = $this->call('POST', self::PATH, $body, $this->metaTimeout);
        ($this->assertOk)($res, 'the batch submission', $this->url(self::PATH));
        $batch = is_array($res->data) ? $res->data : [];

        $id = (string)($batch['id'] ?? '');
        if ($id === '') {
            throw HttpException::badRequest(
                self::LABEL . ': the batch was accepted but no id came back - ' . Text::snippet($res->raw)
            );
        }

        // created_at is what both deadlines are counted from, so a response
        // that somehow omits it falls back to now rather than to no deadline
        // at all - an unknown deadline reads as "never expires", which is the
        // one answer that loses a course.
        $created = self::timestamp($batch['created_at'] ?? null) ?: time();
        $expires = self::timestamp($batch['expires_at'] ?? null) ?: $created + self::WINDOW_SECONDS;

        return new BatchHandle(
            $id,
            (string)($batch['processing_status'] ?? ''),
            self::ref($batch, $created),
            $expires,
            self::retention($created),
        );
    }

    /**
     * Where the batch stands.
     *
     * `processing_status` has exactly three values and only one of them is an
     * ending. A batch that was cancelled and a batch that ran out of time both
     * arrive at "ended" like any other: the difference shows up per request, in
     * the result lines, which is why an ended batch is always worth downloading
     * even when it was stopped on purpose.
     */
    public function poll(BatchHandle $handle): BatchStatus
    {
        $path = self::PATH . '/' . rawurlencode($handle->remoteId);

        $res = $this->call('GET', $path, null, $this->metaTimeout);
        ($this->assertOk)($res, 'the batch status', $this->url($path));
        $batch = is_array($res->data) ? $res->data : [];

        $remote = (string)($batch['processing_status'] ?? '');
        $counts = [];
        foreach ((array)($batch['request_counts'] ?? []) as $key => $value) {
            $counts[(string)$key] = (int)$value;
        }

        $state = match ($remote) {
            'ended' => BatchStatus::DONE,
            'canceling' => BatchStatus::CANCELLING,
            default => BatchStatus::RUNNING,
        };

        return BatchStatus::fromCounts(
            $state,
            $remote,
            $counts,
            self::ref($batch, self::timestamp($batch['created_at'] ?? null)),
        );
    }

    /**
     * The answers, one line at a time.
     *
     * The download is spooled first and decoded second, and both halves are
     * bounded. cURL is given a write callback that appends only whole lines to
     * a spool - a `php://temp` stream that stays in memory up to a few megabytes
     * and spills to a temp file beyond that - so the raw JSONL never exists as
     * a string. The generator then reads that spool with fgets and decodes one
     * line at a time, so no more than a single page of prose is live at once.
     *
     * That order is also why a broken download is safe. The generator body does
     * not run until the caller asks for the first result, so a connection that
     * dies mid-transfer raises while the run is still open and still owns its
     * pages; the run is retried on the next poll rather than written off as
     * unanswered.
     *
     * @return Generator<string,BatchItemResult>
     */
    public function results(BatchHandle $handle): Generator
    {
        // The canonical results path, not the `results_url` off the batch
        // object, even though the two address the same endpoint today. The
        // credential travels in a header on this request, and a URL taken out
        // of a response body is a needless place to send one; the value is kept
        // in the reference bag for diagnostics instead.
        $url = $this->url(self::PATH . '/' . rawurlencode($handle->remoteId) . '/results');
        $spool = $this->download($url);

        try {
            while (($line = fgets($spool)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (!is_array($decoded)) {
                    continue;
                }
                $customId = (string)($decoded['custom_id'] ?? '');
                if ($customId === '') {
                    continue;
                }
                yield $customId => self::result($customId, $decoded);
            }
        } finally {
            // Runs on an abandoned generator too, which is what a caller that
            // stops half way through a large download does.
            fclose($spool);
        }
    }

    /**
     * Asks for the batch to stop, and says whether the request was accepted.
     *
     * True means the batch has moved to "canceling", never that it has stopped.
     * Requests already in flight are allowed to finish, so a cancelled batch
     * still ends as "ended" and still has results worth collecting.
     */
    public function cancel(BatchHandle $handle): bool
    {
        $path = self::PATH . '/' . rawurlencode($handle->remoteId) . '/cancel';
        $res = $this->call('POST', $path, null, $this->metaTimeout);

        // A batch that has already ended cannot be cancelled, and saying so is
        // no use to the caller: it only wanted the batch stopped if it still
        // could be. Every one of these three answers means "too late".
        if (!$res->ok() && !in_array($res->status, [400, 404, 409], true)) {
            ($this->assertOk)($res, 'the batch cancellation', $this->url($path));
        }

        return $res->ok();
    }

    /* ------------------------------------------------------------ internals */

    /**
     * One result line turned into an answer.
     *
     * `succeeded` is not the same as usable, and this is the place that
     * difference is caught. The line carries a complete Messages response, so
     * every failure the synchronous path has to handle at HTTP 200 - a refusal,
     * an answer cut off at the output ceiling, a content array with no text
     * block in it - can arrive here too, inside a line the batch calls a
     * success. Reading it through the provider is what keeps the two paths
     * agreeing about what counts as a page.
     *
     * @param array<string,mixed> $line
     */
    private static function result(string $customId, array $line): BatchItemResult
    {
        $result = is_array($line['result'] ?? null) ? $line['result'] : [];
        $type = (string)($result['type'] ?? '');

        if ($type === 'succeeded') {
            $message = is_array($result['message'] ?? null) ? $result['message'] : [];
            $read = AnthropicProvider::readMessage($message);

            if ($read['problem'] !== '') {
                return BatchItemResult::failed(
                    $customId,
                    BatchItemResult::ERRORED,
                    ['type' => 'incomplete_result', 'message' => $read['problem']]
                );
            }

            return BatchItemResult::ok(
                $customId,
                $read['text'],
                is_array($message['usage'] ?? null) ? $message['usage'] : null
            );
        }

        if ($type === 'errored') {
            // The whole envelope, untouched: {type, error:{type,message}, request_id}.
            // Which kind of error it was decides whether resubmitting the page
            // is worth anything, and that is lost the moment it is flattened.
            return BatchItemResult::failed(
                $customId,
                BatchItemResult::ERRORED,
                is_array($result['error'] ?? null)
                    ? $result['error']
                    : ['message' => 'The provider reported an error without a message.']
            );
        }

        if ($type === 'expired') {
            return BatchItemResult::failed($customId, BatchItemResult::EXPIRED, [
                'type' => 'expired',
                'message' => 'This request was still queued when the batch hit its 24 hour limit, '
                    . 'so it never ran and was not billed.',
            ]);
        }

        // Anthropic spells it with one l; CourseForge with two.
        if ($type === 'canceled' || $type === 'cancelled') {
            return BatchItemResult::failed($customId, BatchItemResult::CANCELLED, [
                'type' => 'canceled',
                'message' => 'The batch was cancelled before this request ran.',
            ]);
        }

        return BatchItemResult::failed($customId, BatchItemResult::ERRORED, [
            'type' => $type !== '' ? $type : 'unknown',
            'message' => 'The batch returned a result of a kind CourseForge does not know.',
        ]);
    }

    /**
     * Streams one results body into a spool and hands back the rewound stream.
     *
     * cURL is driven by a write callback rather than by RETURNTRANSFER, which
     * is the only way to see a large body without holding it. The callback
     * keeps the tail of each chunk back until a newline arrives, so the spool
     * only ever contains whole lines and the reader never has to reassemble
     * one. Returning anything other than the byte count aborts the transfer, so
     * it always returns what it was given.
     *
     * @return resource
     */
    private function download(string $url)
    {
        if (!function_exists('curl_init')) {
            throw HttpException::badRequest('The PHP cURL extension is not enabled on this server.');
        }

        $spool = @fopen('php://temp/maxmemory:' . self::SPOOL_BYTES, 'w+b');
        if ($spool === false) {
            throw HttpException::badRequest(
                self::LABEL . ': the batch results could not be buffered - no writable temporary stream.'
            );
        }

        $pending = '';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => $this->headerLines(),
            CURLOPT_RETURNTRANSFER => true,          // moot while a write callback is set, and honest about intent
            CURLOPT_TIMEOUT => max(0, $this->downloadTimeout),
            CURLOPT_CONNECTTIMEOUT => max(5, Config::int('app.connect_timeout_seconds', 30)),
            CURLOPT_FOLLOWLOCATION => false,         // x-api-key is a custom header and cURL would replay it
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING => '',                  // decompressed before the callback sees it
            CURLOPT_NOSIGNAL => true,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_TCP_KEEPIDLE => 60,
            CURLOPT_TCP_KEEPINTVL => 30,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($spool, &$pending): int {
                $length = strlen($chunk);
                $pending .= $chunk;

                $cut = strrpos($pending, "\n");
                if ($cut !== false) {
                    fwrite($spool, substr($pending, 0, $cut + 1));
                    $pending = substr($pending, $cut + 1);
                }

                return $length;
            },
        ]);

        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        unset($ch); // PHP 8 frees the handle; curl_close() is deprecated in 8.5

        if ($pending !== '') {
            fwrite($spool, $pending); // a final line with no newline after it
        }

        $healthy = $errno === 0 && $status >= 200 && $status < 300;
        // An error body is JSON and small, so reading it back costs nothing.
        // A healthy body is the whole download and must never be read as one.
        $raw = $healthy ? '' : self::head($spool);

        $res = new HttpResult(
            $status,
            $raw,
            json_decode($raw, true),
            $errno !== 0 ? $error . ' (errno ' . $errno . ')' : '',
            $errno,
        );

        if (!$healthy) {
            fclose($spool);
            ($this->assertOk)($res, 'the batch results', $url);
            // assertOk throws on everything that gets here; this is unreachable
            // and exists so the function cannot fall through to a closed stream.
            throw HttpException::badRequest(self::LABEL . ': the batch results could not be downloaded.');
        }

        rewind($spool);
        return $spool;
    }

    /**
     * What a batch object says about where its answers will be found.
     *
     * `results_url` is null until processing ends, and `mergeRef` drops empty
     * values, so a poll made while the batch is still running never blanks out
     * what a later one wrote. The retention deadline is kept here as well as on
     * the handle because the reference bag is the part of a handle CourseForge
     * stores whole - the download deadline would otherwise be forgotten the
     * moment the submitting request ended.
     *
     * @param array<string,mixed> $batch
     * @return array<string,mixed>
     */
    private static function ref(array $batch, int $created): array
    {
        $ref = [];
        if (is_string($batch['results_url'] ?? null) && trim($batch['results_url']) !== '') {
            $ref['results_url'] = trim($batch['results_url']);
        }
        if ($created > 0) {
            $ref['results_expire_at'] = self::retention($created);
        }
        return $ref;
    }

    /** 29 days from creation, which is when results_url stops serving anything. */
    private static function retention(int $created): int
    {
        return $created + self::RETENTION_DAYS * 86400;
    }

    /**
     * Anthropic's own rule, checked before submission rather than after.
     *
     * The narrowest custom-id vocabulary of any provider: no slashes, dots,
     * colons or spaces. Sending one that breaks it fails the whole batch, and a
     * batch is not cheap to rebuild.
     */
    private static function assertCustomId(string $id): void
    {
        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id) !== 1) {
            throw HttpException::unprocessable(
                'Anthropic only accepts batch ids of 1-64 letters, digits, hyphens or underscores - got "'
                . Text::snippet($id, 80) . '".'
            );
        }
    }

    /** @return string[] */
    private function headerLines(): array
    {
        $lines = [];
        foreach ($this->headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        if (defined('CF_VERSION')) {
            $lines[] = 'User-Agent: CourseForge/' . CF_VERSION . ' (+PHP ' . PHP_VERSION . ')';
        }
        return $lines;
    }

    private function url(string $path): string
    {
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    /** One JSON call on the batch endpoints, with the same crash wrapping as the provider's own. */
    private function call(string $method, string $path, ?string $body, int $timeout): HttpResult
    {
        $url = $this->url($path);
        $headers = $this->headers;
        $headers['Accept'] = 'application/json';
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        try {
            // follow: false for the same reason the provider uses it - cURL only
            // strips `Authorization` across a redirect, never `x-api-key`.
            return Http::request($method, $url, $headers, $body, $timeout, false);
        } catch (Throwable $e) {
            throw HttpException::badRequest(
                self::LABEL . ': the request to ' . $url . ' crashed - ' . $e->getMessage()
            );
        }
    }

    /**
     * The start of a spooled body, for an error message.
     *
     * @param resource $spool
     */
    private static function head($spool): string
    {
        rewind($spool);
        return (string)stream_get_contents($spool, self::ERROR_BYTES);
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
