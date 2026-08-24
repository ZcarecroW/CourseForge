<?php
declare(strict_types=1);

namespace CourseForge\Ai\Run;

use CourseForge\Ai\Batch\BatchHandle;
use CourseForge\Ai\Batch\BatchItemRequest;
use CourseForge\Ai\Batch\BatchItemResult;
use CourseForge\Ai\Batch\BatchStatus;
use CourseForge\Ai\Completion;
use CourseForge\Ai\PageGenerator;
use CourseForge\Ai\Provider\BatchCapable;
use CourseForge\Ai\Provider\Providers;
use CourseForge\Domain\Pages;
use CourseForge\Domain\Profiles;
use CourseForge\Domain\Projects;
use CourseForge\Domain\Runs;
use CourseForge\Support\Db;
use CourseForge\Support\HttpException;
use CourseForge\Support\Runtime;
use Throwable;

/**
 * A run served by the provider's own batch queue.
 *
 * Every prompt is built up front and handed over in one submission; the answers
 * come back within a day, at half the price. Nothing here holds state between
 * calls: submitting writes the remote id, polling reads it back, and either can
 * happen in a browser request or in a cron tick without knowing which did it
 * last.
 *
 * That is why the provider is handed a BatchHandle rather than an id. The
 * reference a batch's results are downloaded by is not always known when the
 * batch is created - on OpenAI it is a pair of file ids that only exist once
 * processing finishes - so a poll can come back with something new to remember,
 * and the run row is where it is remembered.
 *
 * The handle also carries the two dates a queued course can be lost on, and
 * they are the reason this class watches a clock at all. The provider stops
 * running a batch after its window - a day, two on Gemini - and stops serving
 * the finished answers after its retention period, which is a month or more and
 * a different number on every provider. Both are recorded at submission and
 * both are read on every poll, because a run that quietly passes either one is
 * a course that has to be written and paid for a second time.
 */
final class BatchDriver
{
    /**
     * How long past its stated deadline a batch is still given the benefit of
     * the doubt.
     *
     * A queue that is assembling results as the window closes goes on
     * reporting the batch as running for a few minutes afterwards, and the two
     * clocks involved are not the same clock. Calling that dead on the second
     * would write off answers that were about to arrive.
     */
    private const EXPIRY_GRACE_SECONDS = 900;

    /**
     * Builds every prompt and hands the lot to the provider.
     *
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $project
     * @param array<int,int> $pageIds
     */
    public static function submit(
        int $runId,
        BatchCapable $provider,
        array $profile,
        array $project,
        array $pageIds,
        string $model,
    ): void {
        $items = [];
        foreach ($pageIds as $pageId) {
            $plan = PageGenerator::plan($profile, $project, $pageId);
            $request = Completion::request($profile, 'page', $plan['system'], $plan['user']);
            $items[] = new BatchItemRequest(RunManager::customId($pageId), $request->withModel($model));
        }

        $handle = $provider->submitBatch($items);
        Runs::activate(
            $runId,
            $handle->remoteId,
            $handle->remoteState,
            $handle->refJson(),
            $handle->expiresAt ?? 0,
            $handle->resultsExpireAt ?? 0,
        );
    }

    /**
     * Asks the provider where the run stands and writes home anything finished.
     *
     * Safe to call as often as you like: a run that has already ended returns
     * its stored state without a network call, and results are applied under a
     * per-page guard so two pollers cannot write the same page twice.
     *
     * Two deadlines end a poll before the provider is asked anything, and they
     * are the reason the handle carries both. Past the download deadline the
     * answers have been deleted, so there is nothing to ask about and the run
     * is closed rather than retried once a minute for ever. Past the batch's
     * own window the queue will not run what is left, whatever it says - and
     * that one is not a write-off, because the pages it did answer are still
     * there to collect.
     *
     * @return array<string,mixed>
     */
    public static function poll(string $username, int $runId): array
    {
        $run = Runs::require($username, $runId);
        if (Runs::isTerminal((string)$run['status'])) {
            return Runs::summary($run);
        }
        if ((string)$run['remote_id'] === '') {
            return Runs::summary($run); // a reservation that never reached a provider
        }

        $handle = self::handle($run);
        if ($handle->unreachable()) {
            self::abandon($runId, self::deletedMessage($handle));
            return Runs::summary(Runs::require($username, $runId));
        }

        try {
            $provider = self::providerFor($username, $run);
        } catch (HttpException $e) {
            // The profile or the account inside it is gone. That does not fix
            // itself, so the run ends here rather than being retried by cron
            // every minute until somebody notices.
            self::abandon($runId, $e->getMessage());
            return Runs::summary(Runs::require($username, $runId));
        } catch (Throwable $e) {
            Runs::update($runId, ['error' => mb_substr($e->getMessage(), 0, 500), 'polled_at' => time()]);
            return Runs::summary(Runs::require($username, $runId));
        }

        $status = $provider->pollBatch($handle);

        // Whatever the poll learned goes back into the handle before anything
        // else touches it: on OpenAI this is the only call that ever reports
        // the result file ids, and a poll that discovers them and does not
        // write them down has to be made all over again to read the results.
        $handle->mergeRef($status->ref);

        // A window that has closed does not reopen, and not every provider says
        // so promptly - a batch can go on being reported as running well past
        // the deadline it was given. Reading it as expired here is what stops a
        // dead run being polled once a minute until somebody notices, and it
        // costs nothing: everywhere except Gemini an expired batch still hands
        // over whatever it answered inside the window, and settling is what
        // collects that.
        if (!$status->finished() && $handle->dead(time() - self::EXPIRY_GRACE_SECONDS)) {
            $status = self::expired($status, $handle);
        }

        Runs::update($runId, [
            'remote_state' => (string)$status->rawState,
            'counts' => json_encode($status->counts, JSON_UNESCAPED_SLASHES) ?: '{}',
            'polled_at' => time(),
            'error' => $status->error,
        ] + ($status->ref !== [] ? ['remote_ref' => $handle->refJson()] : [])
          + (!$status->finished() ? ['status' => Runs::RUNNING] : []));

        if (!$status->finished()) {
            return Runs::summary(Runs::require($username, $runId));
        }

        self::settle($username, $run, $provider, $handle, $status);
        Projects::touch((int)$run['project_id']);

        return Runs::summary(Runs::require($username, $runId));
    }

    /**
     * Asks the provider to stop, then settles whatever it managed to finish.
     *
     * A cancel is asynchronous everywhere, and every provider answers "already
     * ended" with the same status code as "no such batch" - so the first thing
     * this does is look. A run that finished while the user was reaching for
     * the button is collected, not thrown away.
     *
     * @return array<string,mixed>
     */
    public static function cancel(string $username, int $runId): array
    {
        $run = Runs::require($username, $runId);
        if ((string)$run['remote_id'] === '') {
            Runs::discard($runId);
            return Runs::summary($run) + ['terminal' => true];
        }

        try {
            $provider = self::providerFor($username, $run);
            $handle = self::handle($run);
            $status = $provider->pollBatch($handle);
            $handle->mergeRef($status->ref);

            if ($status->finished()) {
                // Too late to cancel, and that is good news: collect it instead.
                self::settle($username, $run, $provider, $handle, $status);
                Projects::touch((int)$run['project_id']);
                return Runs::summary(Runs::require($username, $runId));
            }

            // A provider with no cancel route is not asked. OpenRouter's is
            // undocumented, and a button that answers 404 is worse than one
            // that is not offered.
            if ($provider->canCancel()) {
                $provider->cancelBatch($handle);
            }
        } catch (Throwable $e) {
            Runs::update($runId, ['error' => mb_substr($e->getMessage(), 0, 500)]);
        }

        foreach (Runs::pendingItems($runId) as $item) {
            if (!Runs::settleItem($runId, (string)$item['custom_id'], 'canceled', 'Stopped before the provider answered.')) {
                continue;
            }
            $page = Pages::find((int)$run['project_id'], (int)$item['page_id']);
            if ($page !== null && (string)$page['status'] === 'queued') {
                Pages::update((int)$item['page_id'], ['status' => 'pending', 'error' => '']);
            }
        }

        Runs::update($runId, ['status' => Runs::CANCELED, 'finished_at' => time()]);
        Projects::touch((int)$run['project_id']);

        return Runs::summary(Runs::require($username, $runId));
    }

    /* ------------------------------------------------------------ internals */

    /**
     * Turns a finished batch into pages, and only then closes the run.
     *
     * The order matters. Downloading the results can fail on its own - a
     * timeout, a rate limit, a connection that dies half way through a large
     * JSONL file - and the one thing that must never happen is treating that as
     * "the provider had no answer for these pages". So a failed fetch leaves the
     * run open to be tried again, and pages are only written off as unanswered
     * once a download has actually succeeded.
     *
     * The download and the applying happen inside the same try because the
     * results are a stream: a provider that reads a large results file line by
     * line raises its connection errors while the lines are being consumed, not
     * when the call is made. Pages already written before that point stay
     * written - settling one is guarded - and the rest are collected on the
     * next poll.
     *
     * @param array<string,mixed> $run
     */
    private static function settle(
        string $username,
        array $run,
        BatchCapable $provider,
        BatchHandle $handle,
        BatchStatus $status,
    ): void {
        $runId = (int)$run['id'];

        if ($status->hasResults()) {
            try {
                self::apply($username, $run, $provider->fetchBatchResults($handle));
            } catch (Throwable $e) {
                Runs::update($runId, [
                    'error' => 'The results could not be downloaded: ' . mb_substr($e->getMessage(), 0, 400),
                    'polled_at' => time(),
                ]);
                return; // stays open; the next poll tries again
            }

            foreach (Runs::pendingItems($runId) as $item) {
                if (Runs::settleItem($runId, (string)$item['custom_id'], 'errored', 'The provider returned no result for this page.')) {
                    PageGenerator::fail((int)$item['page_id'], 'The batch finished without an answer for this page.');
                }
            }

            try {
                // The answers are on this side now, so whatever the provider is
                // still holding for us can go. An OpenAI batch input file counts
                // against the organisation's storage until it is deleted.
                $provider->releaseBatch($handle);
            } catch (Throwable $e) {
                // Housekeeping only. A course that was written and stored is not
                // a failed run because a cleanup call came back badly.
                Runtime::log('batch.release', $e);
            }
        } else {
            $why = $status->error !== '' ? $status->error : 'The batch failed before it ran.';
            foreach (Runs::pendingItems($runId) as $item) {
                if (Runs::settleItem($runId, (string)$item['custom_id'], 'errored', $why)) {
                    PageGenerator::fail((int)$item['page_id'], $why);
                }
            }
        }

        Runs::update($runId, [
            'status' => match ($status->state) {
                BatchStatus::FAILED => Runs::FAILED,
                BatchStatus::CANCELLED => Runs::CANCELED,
                default => Runs::COMPLETED,
            },
            'finished_at' => time(),
        ]);
    }

    /**
     * Writes the answers home.
     *
     * The outstanding pages are indexed up front and the answers are walked
     * past them, rather than the other way round, because the results are an
     * iterable that may only be traversed once - a 200 MB JSONL download is
     * read a line at a time and never exists as an array. Anything the stream
     * has no answer for is left pending for the caller to write off.
     *
     * @param array<string,mixed> $run
     * @param iterable<string,BatchItemResult> $results
     */
    private static function apply(string $username, array $run, iterable $results): void
    {
        $runId = (int)$run['id'];
        $projectId = (int)$run['project_id'];
        $project = Projects::require($username, $projectId);

        $outstanding = [];
        foreach (Runs::pendingItems($runId) as $item) {
            $outstanding[(string)$item['custom_id']] = (int)$item['page_id'];
        }

        foreach ($results as $result) {
            $customId = $result->customId;
            if (!isset($outstanding[$customId])) {
                continue; // not ours, or settled by somebody who polled first
            }
            $pageId = $outstanding[$customId];
            unset($outstanding[$customId]);

            if (!$result->succeeded()) {
                $why = $result->errorMessage();
                if (Runs::settleItem($runId, $customId, $result->status, $why)) {
                    PageGenerator::fail($pageId, $why !== '' ? $why : 'The batch returned no content.');
                }
                continue;
            }

            $page = Pages::find($projectId, $pageId);
            if ($page === null) {
                Runs::settleItem($runId, $customId, 'errored', 'The page no longer exists.');
                continue;
            }

            // The page is only still ours if nobody touched it while the batch
            // ran. A page rewritten by hand, or generated live, has left
            // 'queued' - and a day-old answer must not overwrite that.
            if ((string)$page['status'] !== 'queued') {
                Runs::settleItem(
                    $runId,
                    $customId,
                    'superseded',
                    'The page was written another way while the batch was queued, so this answer was discarded.'
                );
                continue;
            }

            try {
                Db::transaction(static function () use ($runId, $customId, $project, $page, $result): void {
                    if (Runs::settleItem($runId, $customId, Runs::ITEM_DONE)) {
                        PageGenerator::store($project, $page, $result->content());
                    }
                });
            } catch (Throwable $e) {
                Runs::settleItem($runId, $customId, 'errored', $e->getMessage());
                PageGenerator::fail($pageId, $e->getMessage());
            }
        }
    }

    /** Ends a run that can never be polled again, releasing its pages. */
    private static function abandon(int $runId, string $why): void
    {
        foreach (Runs::pendingItems($runId) as $item) {
            if (Runs::settleItem($runId, (string)$item['custom_id'], 'errored', $why)) {
                PageGenerator::fail((int)$item['page_id'], $why);
            }
        }
        Runs::update($runId, [
            'status' => Runs::FAILED,
            'error' => mb_substr($why, 0, 500),
            'finished_at' => time(),
            'polled_at' => time(),
        ]);
    }

    /**
     * The same poll answer, read as expired.
     *
     * Everything the provider actually said is kept - its own word for the
     * state, its counts, any error it gave - because that is what somebody
     * diagnosing the run will want to read, and inventing a tidier story than
     * the one the provider told would make the row useless. Only CourseForge's
     * reading of it changes, from "still going" to "the window has closed",
     * which is what routes the run into settling and gets the pages that were
     * answered inside the window written before the results are deleted.
     */
    private static function expired(BatchStatus $status, BatchHandle $handle): BatchStatus
    {
        $closed = $handle->expiresAt !== null ? gmdate('Y-m-d H:i', $handle->expiresAt) . ' UTC' : 'its deadline';

        return new BatchStatus(
            BatchStatus::EXPIRED,
            $status->rawState,
            $status->total,
            $status->completed,
            $status->failed,
            $status->ref,
            $status->error !== ''
                ? $status->error
                : 'The provider\'s window closed at ' . $closed . ' with pages still queued. '
                    . 'Whatever was answered before then has been collected; the rest were never run '
                    . 'and have to be queued again.',
            $status->counts,
        );
    }

    /** Why a run that was still open has nothing left to collect. */
    private static function deletedMessage(BatchHandle $handle): string
    {
        $gone = $handle->resultsExpireAt !== null
            ? ' on ' . gmdate('Y-m-d H:i', $handle->resultsExpireAt) . ' UTC'
            : '';

        return 'The provider deleted this batch\'s results' . $gone . ', before they were downloaded. '
            . 'They cannot be fetched again at any price, so these pages have to be written a second time. '
            . 'Results are only kept for weeks, so a run has to be collected while the scheduler is running.';
    }

    /**
     * The stored batch, back in the shape the provider works with.
     *
     * `remote_ref` holds the provider's own reference bag as JSON. A row
     * written before that column carried JSON decodes to nothing, which is
     * harmless: the handle comes back without a reference and the next poll
     * supplies one. The same is true of both deadlines: a row written before
     * `results_expire_at` existed reports zero, the handle treats that as
     * "unknown" rather than as "expired in 1970", and such a run is polled
     * exactly as it was before.
     *
     * @param array<string,mixed> $run
     */
    private static function handle(array $run): BatchHandle
    {
        return BatchHandle::fromStorage(
            (string)$run['remote_id'],
            (string)$run['remote_state'],
            (string)$run['remote_ref'],
            (int)$run['expires_at'],
            (int)($run['results_expire_at'] ?? 0),
        );
    }

    /** @param array<string,mixed> $run */
    private static function providerFor(string $username, array $run): BatchCapable
    {
        $profileId = $run['profile_id'] !== null ? (int)$run['profile_id'] : 0;
        if ($profileId <= 0) {
            throw HttpException::unprocessable('This run has no profile left to read its credentials from.');
        }

        $provider = Providers::fromProfile(Profiles::data($username, $profileId), (string)$run['ai_id']);
        if (!$provider instanceof BatchCapable) {
            throw HttpException::unprocessable($provider->label() . ' cannot be asked about batches.');
        }
        return $provider;
    }
}
