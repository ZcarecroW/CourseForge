<?php
declare(strict_types=1);

namespace CourseForge\Ai\Run;

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
use Throwable;

/**
 * A run served by the provider's own batch queue.
 *
 * Every prompt is built up front and handed over in one submission; the answers
 * come back within a day, at half the price. Nothing here holds state between
 * calls: submitting writes the remote id, polling reads it back, and either can
 * happen in a browser request or in a cron tick without knowing which did it
 * last.
 */
final class BatchDriver
{
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
        Runs::activate($runId, $handle->remoteId, $handle->remoteState, $handle->resultsRef, $handle->expiresAt);
    }

    /**
     * Asks the provider where the run stands and writes home anything finished.
     *
     * Safe to call as often as you like: a run that has already ended returns
     * its stored state without a network call, and results are applied under a
     * per-page guard so two pollers cannot write the same page twice.
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

        $status = $provider->pollBatch((string)$run['remote_id'], (string)$run['remote_ref']);

        Runs::update($runId, [
            'remote_state' => $status->remoteState,
            'counts' => json_encode($status->counts, JSON_UNESCAPED_SLASHES) ?: '{}',
            'polled_at' => time(),
            'error' => $status->error,
        ] + ($status->resultsRef !== '' ? ['remote_ref' => $status->resultsRef] : [])
          + (!$status->finished() ? ['status' => Runs::RUNNING] : []));

        if (!$status->finished()) {
            return Runs::summary(Runs::require($username, $runId));
        }

        self::settle($username, $run, $provider, $status);
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
            $status = $provider->pollBatch((string)$run['remote_id'], (string)$run['remote_ref']);

            if ($status->finished()) {
                // Too late to cancel, and that is good news: collect it instead.
                self::settle($username, $run, $provider, $status);
                Projects::touch((int)$run['project_id']);
                return Runs::summary(Runs::require($username, $runId));
            }

            $provider->cancelBatch((string)$run['remote_id']);
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
     * @param array<string,mixed> $run
     */
    private static function settle(string $username, array $run, BatchCapable $provider, BatchStatus $status): void
    {
        $runId = (int)$run['id'];

        if ($status->hasResults()) {
            try {
                $results = $provider->fetchBatchResults(
                    (string)$run['remote_id'],
                    $status->resultsRef !== '' ? $status->resultsRef : (string)$run['remote_ref']
                );
            } catch (Throwable $e) {
                Runs::update($runId, [
                    'error' => 'The results could not be downloaded: ' . mb_substr($e->getMessage(), 0, 400),
                    'polled_at' => time(),
                ]);
                return; // stays open; the next poll tries again
            }

            self::apply($username, $run, $results);

            foreach (Runs::pendingItems($runId) as $item) {
                if (Runs::settleItem($runId, (string)$item['custom_id'], 'errored', 'The provider returned no result for this page.')) {
                    PageGenerator::fail((int)$item['page_id'], 'The batch finished without an answer for this page.');
                }
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
                BatchStatus::CANCELED => Runs::CANCELED,
                default => Runs::COMPLETED,
            },
            'finished_at' => time(),
        ]);
    }

    /**
     * @param array<string,mixed> $run
     * @param array<string,BatchItemResult> $results
     */
    private static function apply(string $username, array $run, array $results): void
    {
        $runId = (int)$run['id'];
        $projectId = (int)$run['project_id'];
        $project = Projects::require($username, $projectId);

        foreach (Runs::pendingItems($runId) as $item) {
            $customId = (string)$item['custom_id'];
            $pageId = (int)$item['page_id'];
            $result = $results[$customId] ?? null;

            if ($result === null) {
                continue; // settled by the caller as "no answer"
            }

            if (!$result->succeeded()) {
                if (Runs::settleItem($runId, $customId, $result->state, $result->error)) {
                    PageGenerator::fail($pageId, $result->error !== '' ? $result->error : 'The batch returned no content.');
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
                        PageGenerator::store($project, $page, $result->content);
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
