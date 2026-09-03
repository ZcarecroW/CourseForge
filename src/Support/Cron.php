<?php
declare(strict_types=1);

namespace CourseForge\Support;

use CourseForge\Ai\Run\LiveDriver;
use CourseForge\Ai\Run\RunManager;
use CourseForge\Domain\Runs;
use CourseForge\Domain\Tasks;
use CourseForge\Security\Hardening;
use CourseForge\Tasks\Runner;
use CourseForge\Update\Updater;
use Throwable;

/**
 * One scheduler tick.
 *
 * Called once a minute, from cron.php over HTTP or from tools/cron.php on the
 * command line. Three things happen, in this order and for a reason:
 *
 *   1. pages whose worker never came back are given up for lost and requeued,
 *      so a killed process cannot strand a run - and the same for a task,
 *   2. batch runs are polled and anything finished is written home,
 *   3. the tasks people queued - a publish, a link pass - are worked, each
 *      from where it last stopped,
 *   4. the live queue is worked until the tick runs out of time.
 *
 * Everything is bounded. A tick that is cut short - by a host time limit, a
 * restart, a network stall - loses nothing: every claim is a lease with an
 * expiry and every page is written the moment it is finished, not at the end.
 */
final class Cron
{
    /**
     * @return array<string,mixed> a report, for the caller to print or return
     */
    public static function tick(): array
    {
        $started = microtime(true);
        $budget = max(5, Config::int('app.cron_seconds', 50));
        $deadline = $started + $budget;

        // The whole point of this endpoint is to run longer than a page load.
        Runtime::beginLongRequest();

        $report = [
            'ok' => true,
            'released' => 0,
            'batches_polled' => 0,
            'pages_written' => 0,
            'pages_failed' => 0,
            'pages_claimed' => 0,
            'slot' => '',
            'tasks' => ['claimed' => 0, 'finished' => 0, 'failed' => 0, 'paused' => 0, 'retried' => 0],
            'errors' => [],
        ];

        // 1. Recover anything a dead worker was holding.
        try {
            $report['released'] = LiveDriver::recover();
        } catch (Throwable $e) {
            $report['errors'][] = 'recover: ' . $e->getMessage();
        }
        try {
            $report['tasks_released'] = Tasks::recover();
        } catch (Throwable $e) {
            $report['errors'][] = 'recover tasks: ' . $e->getMessage();
        }

        // 2. Collect finished provider batches, for every user on the install.
        foreach (self::usernames() as $username) {
            foreach (Runs::open($username, Runs::MODE_BATCH) as $run) {
                if (microtime(true) >= $deadline) {
                    break 2;
                }
                try {
                    RunManager::poll($username, (int)$run['id']);
                    $report['batches_polled']++;
                } catch (Throwable $e) {
                    $report['errors'][] = 'batch ' . $run['id'] . ': ' . $e->getMessage();
                }
            }
            try {
                Runs::pruneFinished($username, max(1, Config::int('app.batch_keep_days', 30)));
            } catch (Throwable $e) {
                $report['errors'][] = 'prune: ' . $e->getMessage();
            }
        }

        // 3. The tasks people queued. A publish is short and somebody is
        //    usually waiting for it, so it goes before the generation queue;
        //    when live pages are queued as well the two share the tick, or a
        //    long publish would keep every page waiting for as long as it ran.
        try {
            $liveQueued = Runs::open('', Runs::MODE_LIVE) !== [];
            $taskDeadline = $liveQueued ? min($deadline, $started + max(10.0, $budget / 2)) : $deadline;
            $worked = Runner::work($taskDeadline);
            foreach ($worked['errors'] as $error) {
                $report['errors'][] = 'task: ' . $error;
            }
            unset($worked['errors']);
            $report['tasks'] = $worked;
        } catch (Throwable $e) {
            $report['errors'][] = 'tasks: ' . $e->getMessage();
        }

        // 4. Write pages until the time is up.
        try {
            $worked = LiveDriver::work($deadline);
            $report['pages_claimed'] = $worked['claimed'];
            $report['pages_written'] = $worked['written'];
            $report['pages_failed'] = $worked['failed'];
            $report['slot'] = $worked['slot'];
        } catch (Throwable $e) {
            $report['errors'][] = 'work: ' . $e->getMessage();
        }

        // 5. Whether the server still refuses its private files, taken again
        //    every few hours. Only from a request or with a public address
        //    set: from a shell with no address to ask, the last verdict stands.
        try {
            if (Hardening::due() && (isset($_SERVER['HTTP_HOST']) || trim(Config::str('app.public_url', '')) !== '')) {
                $report['security'] = (string)Hardening::check()['verdict'];
            }
        } catch (Throwable $e) {
            $report['errors'][] = 'security: ' . $e->getMessage();
        }

        // 6. The unattended half of the update feature. It runs after the work,
        //    never before it: an update replaces the very files this tick is
        //    executing, so anything still owed to a course is finished first.
        try {
            $report['update'] = Updater::scheduled();
        } catch (Throwable $e) {
            $report['errors'][] = 'update: ' . $e->getMessage();
        }

        // Recorded last, and unconditionally: the UI uses it to tell the user
        // whether the scheduler is alive at all, which matters most precisely
        // when the tick is having trouble.
        Meta::set('cron.last_at', (string)time());

        $report['ok'] = $report['errors'] === [];
        $report['seconds'] = round(microtime(true) - $started, 2);
        return $report;
    }

    /**
     * Everyone with a run that still needs attention.
     *
     * Read from the runs themselves rather than from the account list: a run
     * that outlived the account which started it still has to be collected, and
     * an installation with two hundred accounts should not be walked once a
     * minute for the three that have anything queued.
     *
     * @return array<int,string>
     */
    private static function usernames(): array
    {
        try {
            $rows = Db::rows(
                "SELECT DISTINCT username FROM batch_jobs
                  WHERE status NOT IN ('completed', 'failed', 'canceled')
                     OR finished_at > ?",
                [time() - 86400]
            );
        } catch (Throwable) {
            return [];
        }
        return array_values(array_filter(
            array_map(static fn(array $row): string => (string)$row['username'], $rows),
            static fn(string $n): bool => $n !== ''
        ));
    }

    /**
     * The URL a control-panel scheduler should be pointed at.
     *
     * Worked out from the current request when there is one, so the Settings
     * screen can show something that pastes straight into a hosting panel.
     */
    public static function publicUrl(string $token): string
    {
        return PublicUrl::file('cron.php') . '?token=' . rawurlencode($token);
    }

    /**
     * Whether the caller may run a tick.
     *
     * cron.php is a public URL - it has to be, because that is the only thing a
     * shared host's scheduler can call - so it is gated on a shared secret
     * rather than on a session. Compared in constant time, and refused outright
     * when no secret has been configured, so an install that never set one is
     * not quietly left with an open endpoint.
     */
    public static function tokenValid(string $presented): bool
    {
        $expected = trim(Config::str('app.cron_token', ''));
        return $expected !== '' && $presented !== '' && hash_equals($expected, $presented);
    }
}
