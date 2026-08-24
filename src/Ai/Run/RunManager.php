<?php
declare(strict_types=1);

namespace CourseForge\Ai\Run;

use CourseForge\Ai\Completion;
use CourseForge\Ai\ModelId;
use CourseForge\Ai\Provider\BatchCapable;
use CourseForge\Ai\Provider\Providers;
use CourseForge\Domain\Pages;
use CourseForge\Domain\Profiles;
use CourseForge\Domain\Projects;
use CourseForge\Domain\Runs;
use CourseForge\Support\Config;
use CourseForge\Support\HttpException;
use CourseForge\Support\Meta;
use Throwable;

/**
 * Starting, watching and stopping a generation run.
 *
 * One entry point for both ways of writing a course, because from the outside
 * they are the same thing: you ask for a set of pages, you close the browser,
 * you come back and they are written. Which of the two happens depends on the
 * model - a `:batch` model goes to the provider's queue at half price, anything
 * else is written by CourseForge's own cron worker.
 *
 * The distinction that matters is not batch versus live, it is *browser versus
 * server*. The Content tab can still write pages itself, one request at a time,
 * and that is the right thing for three pages you are watching. A run is for
 * the other case: five hundred pages, overnight, with the laptop shut.
 */
final class RunManager
{
    /**
     * Starts a run over the given pages and returns its summary.
     *
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $project
     * @param array<int,int> $pageIds
     * @param string $mode '' to choose from the model, or an explicit mode
     * @return array<string,mixed>
     */
    public static function start(string $username, array $profile, array $project, array $pageIds, string $mode = ''): array
    {
        $projectId = (int)$project['id'];
        $config = Completion::modelConfig($profile, 'page');
        $provider = Providers::fromProfile($profile, $config['ai_id']);

        $pageIds = array_values(array_unique(array_map('intval', $pageIds)));
        if ($pageIds === []) {
            throw HttpException::unprocessable('Select at least one page.');
        }

        $mode = $mode !== '' ? $mode : (ModelId::isBatch($config['model']) ? Runs::MODE_BATCH : Runs::MODE_LIVE);

        if ($mode === Runs::MODE_BATCH) {
            if (!$provider instanceof BatchCapable || !$provider->supportsBatch()) {
                throw HttpException::unprocessable(
                    $provider->label() . ' has no batch queue. Remove the ":batch" suffix from the page model, '
                    . 'or run it in the background instead.'
                );
            }
            // The row count is only the first of two bounds; the byte ceiling
            // is the chunker's business, and it is usually the one that binds.
            $limits = $provider->batchLimits();
            if (count($pageIds) > $limits->maxRequests) {
                throw HttpException::unprocessable(
                    'That is more pages than ' . $provider->label() . ' accepts in one batch ('
                    . number_format($limits->maxRequests) . ').'
                );
            }
        } elseif (!self::cronConfigured()) {
            throw HttpException::unprocessable(
                'A background run needs the scheduler. Set app.cron_token in data/config.json and have your host '
                . 'call /cron.php once a minute - see the documentation. Until then, use "write them now" instead.'
            );
        }

        // The model on the wire never carries the suffix: it is CourseForge's
        // marker for "use the queue", not part of any provider's model id.
        $model = ModelId::base($config['model']);

        $rows = [];
        foreach ($pageIds as $pageId) {
            $page = Pages::require($projectId, $pageId);
            $rows[] = [
                'page_id' => $pageId,
                'custom_id' => self::customId($pageId),
                'title' => (string)$page['title'],
            ];
        }

        // Claim the pages first, so two tabs cannot start the same work twice
        // and a failed submission cannot leave a batch nobody is tracking.
        $runId = Runs::reserve($username, $projectId, (int)($project['profile_id'] ?? 0) ?: null, 'page', [
            'mode' => $mode,
            'provider' => $provider->kind(),
            'ai_id' => $config['ai_id'],
            'model' => $model,
        ], $rows);

        try {
            if ($mode === Runs::MODE_BATCH) {
                /** @var BatchCapable $provider */
                BatchDriver::submit($runId, $provider, $profile, $project, $pageIds, $model);
            } else {
                // Nothing to send: the worker builds each prompt when it picks
                // the page up, so an edit made after queueing is still honoured.
                Runs::start($runId);
            }
        } catch (Throwable $e) {
            Runs::discard($runId);
            throw $e;
        }

        foreach ($pageIds as $pageId) {
            Pages::update($pageId, ['status' => 'queued', 'error' => '']);
        }
        Projects::touch($projectId);

        return Runs::summary(Runs::require($username, $runId));
    }

    /**
     * Where a run stands. Only a batch run has anything to ask a provider.
     *
     * @return array<string,mixed>
     */
    public static function poll(string $username, int $runId): array
    {
        $run = Runs::require($username, $runId);

        if ((string)$run['mode'] === Runs::MODE_LIVE) {
            // The worker keeps the rows current; the only thing left is to
            // notice when the last page has landed.
            if (!Runs::isTerminal((string)$run['status'])) {
                Runs::closeIfDone($runId);
            }
            return Runs::summary(Runs::require($username, $runId));
        }

        return BatchDriver::poll($username, $runId);
    }

    /** @return array<int,array<string,mixed>> */
    public static function pollAll(string $username): array
    {
        $summaries = [];
        foreach (Runs::open($username) as $open) {
            try {
                $summaries[] = self::poll($username, (int)$open['id']);
            } catch (Throwable $e) {
                Runs::update((int)$open['id'], ['error' => mb_substr($e->getMessage(), 0, 500), 'polled_at' => time()]);
                $summaries[] = Runs::summary(Runs::require($username, (int)$open['id']));
            }
        }
        return $summaries;
    }

    /**
     * Stops a run and releases its pages.
     *
     * @return array<string,mixed>
     */
    public static function cancel(string $username, int $runId): array
    {
        $run = Runs::require($username, $runId);
        if (Runs::isTerminal((string)$run['status'])) {
            return Runs::summary($run);
        }

        if ((string)$run['mode'] === Runs::MODE_BATCH) {
            return BatchDriver::cancel($username, $runId);
        }

        // A page a worker already holds is left alone: it will finish, find its
        // item is no longer claimed, and keep the text it produced rather than
        // throwing away work that was already paid for.
        foreach (Runs::pendingItems($runId) as $item) {
            if (!Runs::settleItem($runId, (string)$item['custom_id'], 'canceled', 'Stopped before this page was written.', Runs::ITEM_PENDING)) {
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
     * Anthropic accepts 1-64 characters of letters, digits, hyphen and
     * underscore, the narrowest of the vocabularies in play - so every run uses
     * ids in that shape, batch or not.
     */
    public static function customId(int $pageId): string
    {
        return 'cf-page-' . $pageId;
    }

    /** Whether a background run has any chance of being picked up. */
    public static function cronConfigured(): bool
    {
        return trim(Config::str('app.cron_token', '')) !== '';
    }

    /**
     * How the scheduler is doing, for the UI to show.
     *
     * A background run that nobody collects is the one failure mode worth
     * warning about in advance, so the Content tab can say "the scheduler has
     * never run" before the user waits an hour for nothing.
     *
     * @return array{configured:bool,last_at:int,seconds_ago:int,healthy:bool}
     */
    public static function cronStatus(): array
    {
        $last = Meta::int('cron.last_at');
        $ago = $last > 0 ? max(0, time() - $last) : 0;

        return [
            'configured' => self::cronConfigured(),
            'last_at' => $last,
            'seconds_ago' => $ago,
            // Asked to run every minute; five is generous enough not to cry wolf.
            'healthy' => $last > 0 && $ago < 300,
        ];
    }

}
