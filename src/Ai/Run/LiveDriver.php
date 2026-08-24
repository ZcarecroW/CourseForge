<?php
declare(strict_types=1);

namespace CourseForge\Ai\Run;

use CourseForge\Ai\PageGenerator;
use CourseForge\Domain\Pages;
use CourseForge\Domain\Profiles;
use CourseForge\Domain\Projects;
use CourseForge\Domain\Runs;
use CourseForge\Support\Config;
use CourseForge\Support\Lock;
use Throwable;

/**
 * A run CourseForge writes itself, one page at a time, from cron.
 *
 * This is the answer to "start a five hundred page course and shut the laptop".
 * The Content tab's own generator is a loop in the browser: it is immediate and
 * it is right for a handful of pages you are watching, but it dies with the tab.
 * Here the scheduler is the loop, and the browser is only a window onto it.
 *
 * Shape of a tick
 * ---------------
 * Cron calls once a minute. Each call takes one of a small number of worker
 * slots, writes pages until it runs out of time, and lets go. Slots are leases
 * with an expiry rather than locks, because the process holding one can be
 * killed by a host time limit at any moment and a lock that had to be released
 * by hand would stop the queue for good.
 *
 * Parallelism therefore comes from cron itself: with two slots, two ticks work
 * side by side on different pages. Nothing here manages threads, and nothing
 * needs curl_multi.
 */
final class LiveDriver
{
    /**
     * Works the queue until the deadline, or until there is nothing left.
     *
     * @return array{claimed:int,written:int,failed:int,slot:string}
     */
    public static function work(float $deadline): array
    {
        $written = 0;
        $failed = 0;
        $claimed = 0;

        $slots = max(1, min(8, Config::int('app.cron_workers', 2)));
        $lease = max(60, Config::int('app.cron_seconds', 50) + 60);

        // Take the first free slot. All busy means another tick is already doing
        // this work, and the right thing is to leave immediately rather than
        // queue up behind it - cron will be back in a minute.
        $slot = '';
        $owner = false;
        for ($i = 1; $i <= $slots; $i++) {
            $name = 'cron.worker.' . $i;
            $owner = Lock::acquire($name, $lease);
            if ($owner !== false) {
                $slot = $name;
                break;
            }
        }
        if ($owner === false) {
            return ['claimed' => 0, 'written' => 0, 'failed' => 0, 'slot' => ''];
        }

        try {
            while (microtime(true) < $deadline) {
                $item = Runs::claimNextItem();
                if ($item === null) {
                    break;
                }
                $claimed++;

                self::writeOne($item) ? $written++ : $failed++;

                // Hold the slot for as long as we are actually using it.
                if (!Lock::renew($slot, $lease, $owner)) {
                    break; // somebody decided we were dead; do not fight over it
                }
            }
        } finally {
            Lock::release($slot, $owner);
        }

        return ['claimed' => $claimed, 'written' => $written, 'failed' => $failed, 'slot' => $slot];
    }

    /**
     * Writes one claimed page.
     *
     * @param array<string,mixed> $item a row from Runs::claimNextItem()
     */
    private static function writeOne(array $item): bool
    {
        $runId = (int)$item['job_id'];
        $pageId = (int)$item['page_id'];
        $customId = (string)$item['custom_id'];
        $username = (string)$item['username'];
        $projectId = (int)$item['project_id'];
        $attempts = (int)$item['attempts'];
        $maxAttempts = max(1, Config::int('app.cron_max_attempts', 3));

        try {
            $project = Projects::require($username, $projectId);
            if ($project['profile_id'] === null) {
                throw new \RuntimeException('This course no longer has a profile to generate with.');
            }
            $profile = Profiles::data($username, (int)$project['profile_id']);

            // PageGenerator does the whole live path: it marks the page as
            // generating, builds the prompt from the page as it stands right
            // now, sends it, and stores the answer.
            PageGenerator::run($profile, $project, $pageId);
        } catch (Throwable $e) {
            self::recordFailure($runId, $customId, $projectId, $pageId, $attempts, $maxAttempts, $e->getMessage());
            Runs::closeIfDone($runId);
            return false;
        }

        // Settling last is deliberate: the page is written and paid for, so it
        // is kept even if the run was stopped while this page was in flight.
        // A false here only means nobody is waiting for it any more.
        Runs::settleItem($runId, $customId, Runs::ITEM_DONE, '', Runs::ITEM_WORKING);
        Projects::touch($projectId);
        Runs::closeIfDone($runId);

        return true;
    }

    /**
     * Decides whether a failure is worth another go.
     *
     * Most failures on this path are transient - a rate limit, a gateway hiccup,
     * a provider having a bad minute - and the queue is long enough that coming
     * back to the page later costs nothing. A page that has failed repeatedly is
     * a real problem and is left alone with its error showing.
     */
    private static function recordFailure(
        int $runId,
        string $customId,
        int $projectId,
        int $pageId,
        int $attempts,
        int $maxAttempts,
        string $message,
    ): void {
        if ($attempts < $maxAttempts) {
            if (Runs::settleItem($runId, $customId, Runs::ITEM_PENDING, $message, Runs::ITEM_WORKING)) {
                // PageGenerator::run has already stamped the page as failed;
                // put it back in the queue so the tab shows it as waiting.
                self::requeuePage($projectId, $pageId);
            }
            return;
        }

        Runs::settleItem($runId, $customId, 'errored', $message, Runs::ITEM_WORKING);
        PageGenerator::fail($pageId, $message);
    }

    /**
     * Gives back pages whose worker never came back, and closes runs that are
     * finished but were never noticed.
     *
     * Both halves matter. Releasing the item without putting the page right
     * leaves it sitting in the outline as "queued" with nothing queued for it,
     * which is exactly the sort of thing that is never noticed until somebody
     * wonders why one page of five hundred never arrived.
     *
     * @return int items released
     */
    public static function recover(): int
    {
        $maxAttempts = max(1, Config::int('app.cron_max_attempts', 3));
        $released = 0;

        foreach (Runs::staleItems(max(120, Config::int('app.cron_item_timeout_seconds', 1800))) as $item) {
            $runId = (int)$item['job_id'];
            $pageId = (int)$item['page_id'];
            $customId = (string)$item['custom_id'];
            $projectId = (int)$item['project_id'];

            if ((int)$item['attempts'] < $maxAttempts) {
                if (Runs::settleItem($runId, $customId, Runs::ITEM_PENDING, '', Runs::ITEM_WORKING)) {
                    self::requeuePage($projectId, $pageId);
                    $released++;
                }
                continue;
            }

            $why = 'Generation was interrupted repeatedly and was given up on.';
            if (Runs::settleItem($runId, $customId, 'errored', $why, Runs::ITEM_WORKING)) {
                PageGenerator::fail($pageId, $why);
            }
        }

        foreach (Runs::open('', Runs::MODE_LIVE) as $run) {
            Runs::closeIfDone((int)$run['id']);
        }
        return $released;
    }

    /** Puts a page back into the queue, whatever a half-finished attempt left it as. */
    private static function requeuePage(int $projectId, int $pageId): void
    {
        if (Pages::find($projectId, $pageId) !== null) {
            Pages::update($pageId, ['status' => 'queued', 'error' => '']);
        }
    }
}
