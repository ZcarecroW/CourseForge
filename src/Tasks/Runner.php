<?php
declare(strict_types=1);

namespace CourseForge\Tasks;

use CourseForge\Domain\Tasks;
use CourseForge\Publish\PublishBudget;
use CourseForge\Support\Runtime;
use Throwable;

/**
 * Works the task queue: from the scheduler, one tick at a time, or from the
 * browser, one slice at a time when there is no scheduler to do it.
 *
 * A task is claimed under a lease, worked until the budget runs out, and put
 * back with its place kept. The lease is renewed as the work goes - that is
 * what the budget's liveness callback does - and a renewal that fails means
 * the task was stopped or taken over, at which point the publisher finishes
 * the item it is on and hands back. Nothing here decides what a task does; the
 * job for its kind does that.
 */
final class Runner
{
    /** How much of a request or tick is left for the task before the slice is called off. */
    private const MARGIN_SECONDS = 2.0;

    /**
     * Works whatever is due until the deadline.
     *
     * @return array{claimed:int,finished:int,failed:int,paused:int,retried:int,errors:array<int,string>}
     */
    public static function work(float $deadline, string $by = 'cron'): array
    {
        $report = ['claimed' => 0, 'finished' => 0, 'failed' => 0, 'paused' => 0, 'retried' => 0, 'errors' => []];
        $owner = bin2hex(random_bytes(8));
        $lease = self::leaseFor($deadline);

        while (microtime(true) < $deadline - self::MARGIN_SECONDS) {
            $task = Tasks::claimNext($owner, $lease);
            if ($task === null) {
                break;
            }
            $report['claimed']++;
            try {
                $outcome = self::slice($task, $deadline, $owner, $lease, $by);
                $report[$outcome]++;
            } catch (Throwable $e) {
                $report['errors'][] = 'task ' . $task['id'] . ': ' . $e->getMessage();
            }
        }

        return $report;
    }

    /**
     * Works one particular task for one slice, at the browser's request.
     *
     * The Publish tab uses this while the scheduler is not around - not
     * configured, or not calling in - so a publish still happens, one slice
     * per request, under the same lease as a tick would take. A task the
     * scheduler is already holding is left to it.
     *
     * @return array{ran:bool,outcome:string,task:array<string,mixed>|null}
     */
    public static function runOne(int $taskId, float $deadline, string $by = 'browser'): array
    {
        $owner = bin2hex(random_bytes(8));
        $lease = self::leaseFor($deadline);

        $task = Tasks::claim($taskId, $owner, $lease);
        if ($task === null) {
            $current = Tasks::find($taskId);
            return [
                'ran' => false,
                'outcome' => $current === null ? 'missing' : ((string)$current['status'] === Tasks::RUNNING ? 'busy' : 'not-due'),
                'task' => $current === null ? null : Tasks::summary($current),
            ];
        }

        $outcome = self::slice($task, $deadline, $owner, $lease, $by);
        $after = Tasks::find($taskId);
        return ['ran' => true, 'outcome' => $outcome, 'task' => $after === null ? null : Tasks::summary($after)];
    }

    /* ------------------------------------------------------------ internals */

    /**
     * One slice of one claimed task.
     *
     * @param array<string,mixed> $row the claimed row
     * @return string finished | failed | paused | retried
     */
    private static function slice(array $row, float $deadline, string $owner, int $lease, string $by): string
    {
        $id = (int)$row['id'];
        $task = Tasks::summary($row);

        $log = static function (string $line, string $level = 'info', ?int $targetId = null) use ($id): void {
            Tasks::log($id, $line, $level, $targetId);
        };

        if ((int)$row['attempts'] === 0 && $task['progress'] === []) {
            $log(self::openingLine($task, $by), 'info');
        }

        $budget = new PublishBudget(
            $deadline - self::MARGIN_SECONDS,
            static fn(): bool => Tasks::renew($id, $owner, $lease),
        );

        try {
            $result = match ($task['kind']) {
                Tasks::KIND_PUBLISH, Tasks::KIND_LINKS => PublishJob::slice($task, $budget, $log),
                default => ['status' => 'failed', 'progress' => [], 'error' => 'Unknown task kind "' . $task['kind'] . '".', 'delay' => 0],
            };
        } catch (Throwable $e) {
            // The job itself broke rather than one wiki: a bug, or the
            // database going away. Counted as an attempt, with the place kept
            // as far as it got before this slice.
            Runtime::log('task.slice', $e);
            $result = [
                'status' => 'retry',
                'progress' => $task['progress'],
                'error' => $e->getMessage(),
                'delay' => Tasks::backoff((int)$row['attempts'] + 1),
            ];
            $log('Stopped by an error in CourseForge: ' . $e->getMessage(), 'error');
        }

        // Nobody wants this any more: it was stopped, or the lease was taken
        // over. Whatever was written stays; the row is somebody else's now.
        if ($budget->lost()) {
            $log('Stopped.', 'warn');
            return 'paused';
        }

        switch ($result['status']) {
            case 'done':
                Tasks::finish($id, $owner, $result['progress'], Tasks::DONE);
                $log('Done.', 'done');
                return 'finished';
            case 'failed':
                Tasks::finish($id, $owner, $result['progress'], Tasks::FAILED, $result['error']);
                return 'failed';
            case 'retry':
                Tasks::retryLater($id, $owner, $result['progress'], $result['error'], (int)$result['delay']);
                return 'retried';
            default:
                Tasks::pause($id, $owner, $result['progress']);
                return 'paused';
        }
    }

    /** How long a claim is good for: the slice, plus a margin for the wiki to answer one last call. */
    private static function leaseFor(float $deadline): int
    {
        return (int)max(120, ceil($deadline - microtime(true)) + 90);
    }

    /** @param array<string,mixed> $task */
    private static function openingLine(array $task, string $by): string
    {
        $what = $task['kind'] === Tasks::KIND_LINKS ? 'Resolving auto links' : 'Publishing';
        $params = $task['params'];
        $scope = (string)($params['scope'] ?? 'all');
        $detail = match ($scope) {
            'book' => ', book metadata only',
            'chapter' => ', one chapter',
            'page' => ', one page',
            default => '',
        };
        $force = !empty($params['force']) ? ', forcing every item' : '';
        $who = $task['created_by'] !== '' ? ' - asked for by ' . $task['created_by'] : '';
        $where = $by === 'browser' ? ' (started from the browser)' : ' (started by the scheduler)';
        return $what . $detail . $force . $who . $where . '.';
    }
}
