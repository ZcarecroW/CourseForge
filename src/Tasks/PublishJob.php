<?php
declare(strict_types=1);

namespace CourseForge\Tasks;

use CourseForge\Domain\Tasks;
use CourseForge\Publish\PublishBudget;
use CourseForge\Publish\PublishFailure;
use CourseForge\Publish\Publisher;
use CourseForge\Support\HttpException;
use CourseForge\Support\Runtime;
use Throwable;

/**
 * One slice of a publish or a link pass, worked by the scheduler.
 *
 * A task holds, per wiki, where the work stands: not started, part way through
 * with the exact item it stopped at, finished, or failed with the reason. A
 * slice takes the wikis in order, skips the finished ones, carries on with the
 * one that was interrupted, and stops when the budget runs out. A wiki that
 * fails does not stop the others - its failure is written down, the next wiki
 * is tried, and at the end of the slice the task is put back for later with
 * everything it achieved kept. The next attempt does not start over: it picks
 * up each failed wiki at the item it was writing when the failure happened.
 *
 * Only a refusal at the door - the course has no profile, no destination, no
 * credentials for one of them - ends a task at once. Those are things a person
 * has to fix, and trying again every minute would only make the log longer.
 */
final class PublishJob
{
    /**
     * @param array<string,mixed> $task a row summary from Tasks
     * @param callable(string,string,?int):void $log line, level, target id
     * @return array{status:string,progress:array<string,mixed>,error:string,delay:int}
     *         status is done | paused | retry | failed
     */
    public static function slice(array $task, PublishBudget $budget, callable $log): array
    {
        $params = is_array($task['params'] ?? null) ? $task['params'] : [];
        $progress = is_array($task['progress'] ?? null) ? $task['progress'] : [];
        $progress['targets'] = is_array($progress['targets'] ?? null) ? $progress['targets'] : [];

        $kind = (string)$task['kind'];
        $scope = (string)($params['scope'] ?? 'all');
        $itemId = isset($params['item_id']) && is_numeric($params['item_id']) ? (int)$params['item_id'] : null;
        $force = (bool)($params['force'] ?? false);
        $wanted = self::targetIds($params);

        try {
            $publisher = Publisher::open((string)$task['username'], (int)$task['project_id'], $wanted);
        } catch (HttpException $e) {
            // Refused before anything was contacted: a course with no profile,
            // no destination, a destination its profile no longer defines.
            // Nothing a retry can change.
            $log($e->getMessage(), 'error', null);
            return ['status' => 'failed', 'progress' => $progress, 'error' => $e->getMessage(), 'delay' => 0];
        }

        $paused = false;
        $failed = [];

        foreach ($publisher->targets() as $target) {
            $targetId = (int)$target['id'];
            $state = is_array($progress['targets'][$targetId] ?? null) ? $progress['targets'][$targetId] : [];
            $state += ['status' => 'pending', 'work' => [], 'error' => '', 'failures' => 0];

            if ($state['status'] === 'done') {
                continue;
            }
            if ($budget->exhausted()) {
                $paused = true;
                break;
            }

            $name = $publisher->nameOf($target);
            $log(self::opening($kind, $state, $name), 'info', $targetId);

            $emit = static function (string $line, string $level) use ($log, $targetId): void {
                $log($line, $level, $targetId);
            };

            try {
                $work = is_array($state['work']) ? $state['work'] : [];
                $result = $kind === Tasks::KIND_LINKS
                    ? $publisher->resolveTarget($target, $force, $work, $budget, $emit)
                    : $publisher->pushTarget($target, $scope, $itemId, $force, $work, $budget, $emit);

                $state['work'] = $result['state'];
                $state['links'] = $result['links'];
                $state['error'] = '';

                if ($result['done']) {
                    $state['status'] = 'done';
                    $state['finished_at'] = time();
                    $log('Finished ' . $name . '.', 'done', $targetId);
                } else {
                    $state['status'] = 'partial';
                    $paused = true;
                }
            } catch (Throwable $e) {
                Runtime::log('task.publish', $e);
                // The place the walk had reached travels with the failure, so
                // the next attempt starts at the item that broke rather than
                // at the book.
                if ($e instanceof PublishFailure) {
                    $state['work'] = $e->state;
                }
                $state['status'] = 'failed';
                $state['error'] = $e->getMessage();
                $state['failures'] = (int)$state['failures'] + 1;
                $state['failed_at'] = time();
                $failed[] = $name . ': ' . $e->getMessage();
                $log('Failed: ' . $e->getMessage(), 'error', $targetId);
            }

            $progress['targets'][$targetId] = $state;

            if ($paused) {
                break;
            }
        }

        // What the wikis now hold, copied back onto the course after every
        // slice, so the badges follow the work rather than the task.
        try {
            $publisher->settle();
        } catch (Throwable $e) {
            Runtime::log('task.settle', $e);
        }

        if ($paused) {
            return ['status' => 'paused', 'progress' => $progress, 'error' => '', 'delay' => 0];
        }

        if ($failed !== []) {
            $attempt = (int)($task['attempts'] ?? 0) + 1;
            $max = max(1, (int)($task['max_attempts'] ?? Tasks::maxAttempts()));
            $summary = count($failed) === 1
                ? $failed[0]
                : count($failed) . ' destinations could not be published to. ' . $failed[0];

            if ($attempt >= $max) {
                $log(
                    'Given up after ' . $attempt . ' attempt' . ($attempt === 1 ? '' : 's') . '. '
                    . 'Everything that succeeded is kept; press Retry to try the rest again.',
                    'error',
                    null
                );
                return ['status' => 'failed', 'progress' => $progress, 'error' => $summary, 'delay' => 0];
            }

            $delay = Tasks::backoff($attempt);
            $log(
                'Will try again in ' . self::duration($delay) . ' from where it stopped (attempt ' . $attempt
                . ' of ' . $max . ').',
                'warn',
                null
            );
            return ['status' => 'retry', 'progress' => $progress, 'error' => $summary, 'delay' => $delay];
        }

        return ['status' => 'done', 'progress' => $progress, 'error' => '', 'delay' => 0];
    }

    /**
     * The wikis a task is aimed at, or null for every one that is on.
     *
     * @param array<string,mixed> $params
     * @return array<int,int>|null
     */
    private static function targetIds(array $params): ?array
    {
        $ids = $params['target_ids'] ?? null;
        if (!is_array($ids) || $ids === []) {
            return null;
        }
        $clean = [];
        foreach ($ids as $id) {
            if (is_numeric($id) && (int)$id > 0) {
                $clean[] = (int)$id;
            }
        }
        return $clean === [] ? null : $clean;
    }

    /** The first line a wiki gets in a slice: what is about to happen to it. */
    private static function opening(string $kind, array $state, string $name): string
    {
        $verb = $kind === Tasks::KIND_LINKS ? 'Resolving links in' : 'Publishing to';
        return match ($state['status']) {
            'failed' => 'Trying ' . $name . ' again from where it stopped.',
            'partial' => 'Continuing with ' . $name . '.',
            default => $verb . ' ' . $name . '…',
        };
    }

    private static function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' seconds';
        }
        $minutes = intdiv($seconds, 60);
        return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
    }
}
