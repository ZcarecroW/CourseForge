<?php
declare(strict_types=1);

namespace CourseForge\Ai\Batch\Driver;

use CourseForge\Ai\Batch\BatchHandle;
use CourseForge\Ai\Batch\BatchItemRequest;
use CourseForge\Ai\Batch\BatchItemResult;
use CourseForge\Ai\Batch\BatchStatus;
use CourseForge\Ai\Batch\JsonlChunker;
use CourseForge\Ai\Provider\OpenAiCompatibleProvider;
use CourseForge\Ai\Provider\Probe;
use CourseForge\Domain\Profiles;
use CourseForge\Support\HttpException;
use CourseForge\Support\Text;
use Generator;

/**
 * The file-JSONL batch queue: upload the prompts as one file, point a batch at
 * it, come back a few hours later for two result files.
 *
 * This is the one batch style with real reach. OpenAI defined it, Groq copied
 * it byte for byte, and every preset whose capability probe finds both
 * /batches and /files speaks it too, so a single implementation covers the
 * whole hosted lane. Everything that varies between those endpoints is a field
 * on the PresetSpec - which path the files live at, which path the batches live
 * at, how long a completion window may be, and the value of the `endpoint` key
 * inside the create body, which on Groq is deliberately not the URL that was
 * just posted to.
 *
 * Three details here are the difference between a working course and a course
 * with holes in it, and every one of them hides inside a successful response.
 *
 * The first is that there are two result files, not one. Successes land in
 * `output_file_id` and failures in `error_file_id`, the second is null only
 * when nothing failed, and a client that fetches one of them reads a partial
 * set as "the provider had nothing to say about those pages".
 *
 * The second is the per-line `status_code`. The download is an HTTP 200 and
 * line 4,000 of it can be a 429 or a 400. Checking only the status of the
 * download turns every one of those into a page that succeeded with no text.
 *
 * The third is `finish_reason` inside a line that is a 200 all the way down.
 * `length` and `content_filter` arrive with text beside them - most of a
 * lesson, ending mid-sentence, or the paragraph written before a block - and a
 * line judged by whether it has text in it stores those as finished pages. That
 * judgement belongs to the provider (batchFailure()) rather than to this class,
 * so the queued lane and the live lane refuse exactly the same answers.
 *
 * The uploaded input file is deleted once the answers are safely on this side.
 * It counts against the organisation's storage forever otherwise, and a course
 * of 500 pages uploads a new one every time it is regenerated.
 */
final class OpenAiFileBatch
{
    /** Only ever seen by a human reading the provider's file list. */
    private const FILENAME = 'courseforge-batch.jsonl';

    public function __construct(private readonly OpenAiCompatibleProvider $provider)
    {
    }

    /**
     * Uploads one JSONL file and creates the batch that reads it.
     *
     * @param array<int,BatchItemRequest> $items
     */
    public function submit(array $items): BatchHandle
    {
        if ($items === []) {
            throw HttpException::unprocessable('There is nothing to submit.');
        }
        self::assertUniqueIds($items);

        $spec = $this->provider->spec();
        $limits = $this->provider->batchLimits();

        // The chunker measures by bytes, which is the bound that actually
        // binds: a course prompt is around 8 KB once it is JSON-encoded, so the
        // file ceiling is reached thousands of rows before the 50,000-row cap -
        // and that ceiling is the smaller of what the provider accepts and what
        // this process can build without exhausting its memory limit, which is
        // reasoned about in OpenAiCompatibleProvider::batchLimits(). The
        // chunker also refuses a single page too large to send at all, by name,
        // rather than letting the upload discover it. A run is one remote
        // batch, so more than one chunk is a run that has to be split rather
        // than a file to truncate.
        $chunks = (new JsonlChunker($limits))->chunk($items);
        if (count($chunks) > 1) {
            throw HttpException::unprocessable(
                'This run is too large for one submission on ' . $this->provider->label() . ': it needs '
                . count($chunks) . ' batches and the limit is ' . $limits->describe()
                . '. Generate it in smaller selections.'
            );
        }

        $jsonl = $this->buildJsonl($items, $spec->batchEndpoint);
        if (strlen($jsonl) > $limits->maxBytes) {
            throw HttpException::unprocessable(
                'The prompts came to ' . number_format(strlen($jsonl) / 1048576, 1) . ' MB, and '
                . $this->provider->label() . ' accepts ' . $limits->describe()
                . '. Generate this run in smaller selections.'
            );
        }

        $fileId = $this->upload($jsonl);

        $path = $spec->batchesPath;
        $body = [
            'input_file_id' => $fileId,
            // Not the URL that was just posted to. Groq serves its batches at
            // /openai/v1/batches and wants "/v1/chat/completions" here, without
            // the prefix, which is why the preset carries the field separately.
            'endpoint' => $spec->batchEndpoint,
        ];
        // Sent only where it means something. Together documents a window that
        // "defaults to 24h and cannot be changed" and a create body with no
        // such field, and a submission is validated hours after it is accepted
        // - so an argument about a field nobody can change is not worth having
        // at the point where the whole course has already been encoded.
        if ($spec->sendsWindow) {
            $body['completion_window'] = $spec->window;
        }
        $res = $this->provider->batchRequest('POST', $path, $body);
        $this->assertQueueExists($res->status, $path);
        $this->provider->batchAssert($res, 'the batch submission', $this->provider->batchUrl($path), true);

        $data = is_array($res->data) ? $res->data : [];
        $id = (string)($data['id'] ?? '');
        if ($id === '') {
            throw HttpException::badRequest(
                $this->provider->label() . ' accepted the batch but returned no id: ' . Text::snippet($res->raw)
            );
        }

        // The input file id goes into the handle at submit because this is the
        // only moment it is known, and release() has nothing to delete without
        // it. The two result file ids do not exist yet; poll writes them.
        return new BatchHandle(
            $id,
            (string)($data['status'] ?? ''),
            ['input_file_id' => $fileId],
            (int)($data['expires_at'] ?? 0) ?: null,
            self::retentionDeadline($data, $this->provider->batchLimits()->retentionDays),
        );
    }

    public function poll(BatchHandle $handle): BatchStatus
    {
        $path = $this->provider->spec()->batchesPath . '/' . rawurlencode($handle->remoteId);

        $res = $this->provider->batchRequest('GET', $path);
        $this->provider->batchAssert($res, 'the batch status', $this->provider->batchUrl($path), true);
        $data = is_array($res->data) ? $res->data : [];

        $remote = strtolower((string)($data['status'] ?? ''));
        $counts = [];
        foreach ((array)($data['request_counts'] ?? []) as $key => $value) {
            $counts[(string)$key] = (int)$value;
        }

        // "failed" is the whole-file rejection: one malformed line and the
        // batch never runs, no result file is ever written, and the reason
        // sits in errors.data[] rather than in any per-request answer.
        $state = match ($remote) {
            'validating' => BatchStatus::PENDING,
            'finalizing' => BatchStatus::FINALIZING,
            'completed' => BatchStatus::DONE,
            'expired' => BatchStatus::EXPIRED,
            'cancelled', 'canceled' => BatchStatus::CANCELLED,
            'cancelling', 'canceling' => BatchStatus::CANCELLING,
            'failed' => BatchStatus::FAILED,
            default => BatchStatus::RUNNING,
        };

        // Neither result id exists before the batch finishes, which is why they
        // travel back to the caller from here rather than from the submission.
        $ref = array_filter([
            'output_file_id' => (string)($data['output_file_id'] ?? ''),
            'error_file_id' => (string)($data['error_file_id'] ?? ''),
        ]);

        return BatchStatus::fromCounts($state, $remote, $counts, $ref, self::validationErrors($data));
    }

    /**
     * The answers, one line at a time, out of both result files.
     *
     * A generator over a stream, and both halves of that matter. A completed
     * batch of a large course is a JSONL body of tens of megabytes: the
     * provider spools the download into a php://temp stream that only ever
     * contains whole lines, and this reads it back with fgets one line at a
     * time, so no more than a single page of prose is live at once and the raw
     * body never exists as a PHP value. Nothing here holds the download and
     * nothing splits it, which is the difference between a run that finishes
     * and a run that dies on the memory limit.
     *
     * The caller consumes this inside the same try that started it, because a
     * download that dies half way through raises while the lines are being
     * read, not when the call is made.
     *
     * @return Generator<string,BatchItemResult>
     */
    public function fetch(BatchHandle $handle): Generator
    {
        $fileIds = $this->resultFileIds($handle);
        $spec = $this->provider->spec();

        foreach ($fileIds as $field => $fileId) {
            $url = $this->provider->batchUrl($spec->filesPath . '/' . rawurlencode($fileId) . '/content');

            // Which of the two files this is decides what a 404 means, and
            // getting that backwards is the expensive mistake. An error file
            // that is not there is a batch in which nothing failed. An OUTPUT
            // file that is not there is the answers this run paid for, gone -
            // and treating that as "the provider had nothing to say about those
            // pages" fails every page and closes the run as finished. So only
            // the error file is allowed to come back null; everything else -
            // that 404, a rate limit, an expired key, a download that died part
            // way through - is raised inside batchStream() and leaves the run
            // open to be collected again.
            $spool = $this->provider->batchStream($url, $field === 'error_file_id');
            if ($spool === null) {
                continue;
            }

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
                    $result = $this->readResultLine($decoded);
                    if ($result !== null) {
                        yield $result->customId => $result;
                    }
                }
            } finally {
                // Runs on an abandoned generator too, which is what a caller
                // that stops half way through a large download does.
                fclose($spool);
            }
        }
    }

    /**
     * Asks for the batch to stop.
     *
     * A batch that has already ended cannot be cancelled, and every gateway
     * says so with the same status code it uses for "no such batch". Neither is
     * worth raising to a person who only wanted it stopped if it still could
     * be, so those are swallowed and everything else is not.
     */
    public function cancel(BatchHandle $handle): bool
    {
        $path = $this->provider->spec()->batchesPath . '/' . rawurlencode($handle->remoteId) . '/cancel';
        $res = $this->provider->batchRequest('POST', $path, null);

        if (!$res->ok() && !in_array($res->status, [400, 404, 409], true)) {
            $this->provider->batchAssert($res, 'the batch cancellation', $this->provider->batchUrl($path));
        }

        // The batch enters "cancelling" and can stay there for ten minutes, so
        // this says the request was accepted and nothing more.
        return $res->ok();
    }

    /**
     * Deletes the uploaded input file, once the answers are stored on this side.
     *
     * Housekeeping, and treated as such: a failure here is not a failed course.
     * The two result files are left alone deliberately - the provider expires
     * them on its own schedule, and a user who wants to re-import a run inside
     * that window should still be able to.
     */
    public function release(BatchHandle $handle): void
    {
        $fileId = $handle->refValue('input_file_id');
        if ($fileId === '') {
            return;
        }
        $path = $this->provider->spec()->filesPath . '/' . rawurlencode($fileId);
        $this->provider->batchRequest('DELETE', $path, null);
    }

    /* ------------------------------------------------------------ internals */

    /**
     * The input file, one JSON object per line.
     *
     * Concatenated as it goes rather than collected and imploded. The file is
     * the largest string this process ever builds, and an array of its lines
     * standing beside it is a second copy of the whole course bought for
     * nothing - which matters because the copies that come after this one are
     * not optional: the multipart body wraps it, and libcurl copies that.
     *
     * @param array<int,BatchItemRequest> $items
     */
    private function buildJsonl(array $items, string $endpoint): string
    {
        $jsonl = '';
        foreach ($items as $item) {
            // A line that cannot be encoded fails the whole file at validation
            // time, hours later and with no output file at all, so an
            // unencodable page has to be caught here instead.
            $line = json_encode([
                'custom_id' => $item->customId,
                'method' => 'POST',
                // Has to match the batch's own `endpoint` value exactly.
                'url' => $endpoint,
                'body' => $this->provider->batchBody($item->request),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

            if ($line === false) {
                throw HttpException::unprocessable(
                    'The page "' . $item->customId . '" could not be encoded for the batch ('
                    . json_last_error_msg() . '). Check it for invalid characters and try again.'
                );
            }
            $jsonl .= $line . "\n";
        }
        return $jsonl;
    }

    /** Uploads the JSONL and returns the file id the batch will read. */
    private function upload(string $jsonl): string
    {
        $spec = $this->provider->spec();
        // The address the upload actually went to, so a 404 names the path that
        // answered rather than the one files are deleted from.
        $url = $this->provider->batchUrl($spec->uploadPath());

        $res = $this->provider->batchUpload($jsonl, self::FILENAME);

        if ($res->status === 413) {
            throw HttpException::unprocessable(
                $this->provider->label() . ' refused the input file as too large ('
                . number_format(strlen($jsonl) / 1048576, 1) . ' MB). Generate this run in smaller selections.'
            );
        }
        $this->assertUploadLane($res->status, $spec->uploadPath());
        $this->provider->batchAssert($res, 'the batch file upload', $url, true);

        $id = (string)(is_array($res->data) ? ($res->data['id'] ?? '') : '');
        if ($id === '') {
            throw HttpException::badRequest(
                'The batch file uploaded but no id came back: ' . Text::snippet($res->raw)
            );
        }
        return $id;
    }

    /**
     * The two file ids the results are in, asked for again if the stored handle
     * predates them.
     *
     * Keyed by the field they arrived under rather than flattened to a list,
     * because the reader has to know which is which: a missing error file is a
     * batch in which nothing failed, and a missing output file is lost work.
     *
     * @return array<string,string>
     */
    private function resultFileIds(BatchHandle $handle): array
    {
        $ids = array_filter([
            'output_file_id' => $handle->refValue('output_file_id'),
            'error_file_id' => $handle->refValue('error_file_id'),
        ]);
        if ($ids !== []) {
            return $ids;
        }

        // A handle stored before the batch finished carries neither id, and the
        // caller may not have polled first. One extra GET beats concluding that
        // a finished batch answered nothing.
        $ref = $this->poll($handle)->ref;
        return array_filter([
            'output_file_id' => (string)($ref['output_file_id'] ?? ''),
            'error_file_id' => (string)($ref['error_file_id'] ?? ''),
        ]);
    }

    /**
     * One JSONL result line, which can fail in three entirely different ways.
     *
     * `error` is set when the request never produced a response at all - most
     * often because the batch hit the end of its window. A null `error` with a
     * non-2xx `response.status_code` is the opposite: the request ran, the
     * provider rejected it, the download was still a perfectly good 200, and
     * the real message is nested inside the body. The third is a line that is a
     * 200 all the way down and still carries no page - an upstream error in the
     * body, a truncation at the output cap, a content filter - which is what
     * the provider's batchFailure() reads, before the text and by the same
     * rules the live path uses.
     *
     * The whole envelope is kept rather than flattened, because a rate limit
     * and an invalid request read almost identically as prose and mean opposite
     * things to a retry.
     *
     * @param array<string,mixed> $line
     */
    private function readResultLine(array $line): ?BatchItemResult
    {
        $customId = (string)($line['custom_id'] ?? '');
        if ($customId === '') {
            return null;
        }

        if (is_array($line['error'] ?? null)) {
            $code = strtolower((string)($line['error']['code'] ?? ''));
            return BatchItemResult::failed(
                $customId,
                $code === 'batch_expired' ? BatchItemResult::EXPIRED : BatchItemResult::ERRORED,
                $line['error'],
            );
        }

        // OpenAI itself always sends an object here, but this driver serves
        // every preset whose probe finds /batches and /files - LiteLLM, vLLM,
        // self-hosted shims - and those write a bare sentence. Falling through
        // with it would land on the empty-text branch below and report "the
        // provider answered with no text", which is both untrue and the last
        // place the gateway's own reason could have been kept.
        if (is_string($line['error'] ?? null) && trim($line['error']) !== '') {
            return BatchItemResult::failed($customId, BatchItemResult::ERRORED, trim($line['error']));
        }

        $response = is_array($line['response'] ?? null) ? $line['response'] : [];
        $status = (int)($response['status_code'] ?? 0);
        $body = is_array($response['body'] ?? null) ? $response['body'] : [];

        if ($status !== 0 && ($status < 200 || $status >= 300)) {
            $error = is_array($body['error'] ?? null) ? $body['error'] : ['message' => 'HTTP ' . $status . '.'];
            return BatchItemResult::failed($customId, BatchItemResult::ERRORED, $error, $status);
        }

        // Before the text, and deliberately without the status: it is a 200 -
        // that is the whole problem with this branch - and prefixing the
        // operator's error line with "HTTP 200" would read as a contradiction
        // while telling them nothing they can act on.
        $failure = $this->provider->batchFailure($body);
        if ($failure !== null) {
            return BatchItemResult::failed($customId, BatchItemResult::ERRORED, $failure);
        }

        $content = $this->provider->batchText($body);
        if ($content === '') {
            $finish = strtolower((string)($body['choices'][0]['finish_reason'] ?? ''));
            return BatchItemResult::failed(
                $customId,
                BatchItemResult::ERRORED,
                ['message' => 'The provider answered this page with no text'
                    . ($finish !== '' ? ' (finish_reason=' . $finish . ')' : '') . '.'],
                $status ?: null,
            );
        }

        $usage = is_array($body['usage'] ?? null) ? $body['usage'] : null;
        return BatchItemResult::ok($customId, $content, $usage);
    }

    /**
     * A create call that 404s or 405s disproves whatever said there was a queue
     * here, and says so in the one place a person will read it.
     */
    private function assertQueueExists(int $status, string $path): void
    {
        if ($status !== 404 && $status !== 405) {
            return;
        }

        // The account's stored capability result is overwritten on the way out.
        // A submission that met a 404 outranks anything a probe concluded
        // earlier, and without this the same run would be attempted again on
        // the next press of the button.
        Profiles::storeProbeFor($this->provider->probeFingerprint(), Probe::disprovedBySubmit(
            $this->provider->label() . ' answered HTTP ' . $status . ' when asked to create a batch at '
            . $this->provider->batchUrl($path) . ', so the queue a capability check found is not there.',
            $status,
        ));

        throw HttpException::unprocessable(
            $this->provider->label() . ' has no batch queue at ' . $this->provider->batchUrl($path)
            . ' (HTTP ' . $status . '). Re-check the endpoint on the account and generate this run live instead.'
        );
    }

    /**
     * The failure the capability probe exists to catch, met the expensive way.
     *
     * A queue with no upload lane cannot be fed by this driver at all - it is
     * how Gemini's OpenAI compatibility layer behaves - and the message has to
     * say so, because "404 on /files" reads like a transient fault.
     */
    private function assertUploadLane(int $status, string $path): void
    {
        if ($status !== 404 && $status !== 405) {
            return;
        }

        // Corrected to no_upload_lane rather than to a flat no, because that is
        // the finding, and it is the one the account's own page can explain.
        Profiles::storeProbeFor($this->provider->probeFingerprint(), Probe::disprovedBySubmit(
            $this->provider->label() . ' has a batch queue but answered HTTP ' . $status . ' on '
            . $this->provider->batchUrl($path) . ', so it has no file upload CourseForge can use.',
            $status,
            'files',
        ));

        throw HttpException::unprocessable(
            $this->provider->label() . ' has a batch queue but no file upload at '
            . $this->provider->batchUrl($path) . ' (HTTP ' . $status . '), so CourseForge cannot queue on it. '
            . 'Re-check the endpoint on the account and generate this run live instead.'
        );
    }

    /**
     * When the finished results stop being downloadable.
     *
     * Counted from the batch's own creation timestamp rather than from now,
     * because a handle rebuilt from a run row days later would otherwise keep
     * moving its own deadline forward and never reach it.
     *
     * @param array<string,mixed> $batch
     */
    private static function retentionDeadline(array $batch, int $retentionDays): ?int
    {
        $created = (int)($batch['created_at'] ?? 0);
        if ($created <= 0 || $retentionDays <= 0) {
            return null;
        }
        return $created + $retentionDays * 86400;
    }

    /**
     * The input file failed validation: say which line, because a 50,000-line
     * file hides one very well.
     *
     * @param array<string,mixed> $batch
     */
    private static function validationErrors(array $batch): string
    {
        if (!is_array($batch['errors']['data'] ?? null)) {
            return '';
        }
        $parts = [];
        foreach (array_slice($batch['errors']['data'], 0, 3) as $error) {
            if (!is_array($error)) {
                continue;
            }
            $line = isset($error['line']) ? ' (line ' . (int)$error['line'] . ')' : '';
            $parts[] = trim((string)($error['message'] ?? 'Validation failed.')) . $line;
        }
        return implode(' ', $parts);
    }

    /**
     * Custom ids are the only thing that matches an answer to a page - results
     * come back in an arbitrary order everywhere - so a duplicate silently
     * loses one of them. Catching it here costs nothing.
     *
     * @param array<int,BatchItemRequest> $items
     */
    private static function assertUniqueIds(array $items): void
    {
        $seen = [];
        foreach ($items as $item) {
            if (isset($seen[$item->customId])) {
                throw HttpException::unprocessable(
                    'The same page was queued twice in one batch ("' . $item->customId . '").'
                );
            }
            $seen[$item->customId] = true;
        }
    }
}
