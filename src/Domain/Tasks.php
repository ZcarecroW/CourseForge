<?php
declare(strict_types=1);

namespace CourseForge\Domain;

use CourseForge\Support\Config;
use CourseForge\Support\Db;
use CourseForge\Support\HttpException;

/**
 * Work that belongs to the server rather than to the request that asked for it.
 *
 * Pressing Publish used to hold one HTTP request open for as long as the push
 * took - dozens to hundreds of calls against somebody else's wiki - and the
 * push lived and died with that request: a host time limit, a closed tab or a
 * wiki that stopped answering half way ended it, and the only record of what
 * had happened was in the browser that pressed the button. A task is the
 * opposite arrangement. It is written down first, worked by the scheduler in
 * slices of one tick each, and it keeps its own place in the work, so that a
 * slice cut short is taken up again from exactly where it stopped rather than
 * from the beginning. What it says while it works is written here too, one
 * line at a time, and outlives every process that touched it.
 *
 * Three states a task can be in are worth telling apart. `queued` is waiting
 * for a tick - either its first, or the one after a slice that ran out of
 * time, or the one a failure has put off until later. `running` is held by a
 * process under a lease; a lease that runs out with the row still `running` is
 * a process that died, and the sweep gives the task back to the queue. The
 * terminal three are what the browser draws a badge for.
 */
final class Tasks
{
    public const KIND_PUBLISH = 'publish';
    public const KIND_LINKS = 'resolve_links';

    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const FAILED = 'failed';
    public const CANCELED = 'canceled';

    private const TERMINAL = [self::DONE, self::FAILED, self::CANCELED];

    /** Finished tasks kept per course, so the log has a past without growing for ever. */
    public const KEEP_PER_PROJECT = 30;

    /** The most attempts a task is ever allowed, whatever the setting says. */
    public const MAX_ATTEMPTS_CEILING = 200;

    /* ---------------------------------------------------------------- create */

    /**
     * Writes a task down. Nothing is done here; the scheduler does it.
     *
     * @param array<string,mixed> $params what the kind needs - scope, item, targets, force
     * @return array<string,mixed> the summary, as the browser reads it
     */
    public static function create(
        string $username,
        int $projectId,
        string $kind,
        array $params,
        string $createdBy = '',
        string $source = 'web',
    ): array {
        if (!in_array($kind, [self::KIND_PUBLISH, self::KIND_LINKS], true)) {
            throw HttpException::unprocessable('There is no task kind called "' . $kind . '".');
        }

        $now = time();
        Db::run(
            'INSERT INTO tasks (username, created_by, project_id, kind, params, status, progress, attempts,
                                max_attempts, next_at, owner, lease_until, error, source,
                                created_at, updated_at, started_at, finished_at)
             VALUES (?,?,?,?,?,?,?,0,?,?,?,0,?,?,?,?,0,0)',
            [
                $username, $createdBy, $projectId, $kind, self::encode($params),
                self::QUEUED, '{}', self::maxAttempts(), $now, '', '', $source, $now, $now,
            ]
        );
        $id = Db::lastId();
        self::prune($projectId);

        return self::summary(self::require($id));
    }

    /** How many tries a task gets before it is given up on. */
    public static function maxAttempts(): int
    {
        return max(1, min(self::MAX_ATTEMPTS_CEILING, Config::int('app.task_max_attempts', 20)));
    }

    /* ----------------------------------------------------------------- reads */

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Db::row('SELECT * FROM tasks WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed> */
    public static function require(int $id): array
    {
        return self::find($id) ?? throw HttpException::notFound('Task not found.');
    }

    /** The tasks of one course, newest first. @return array<int,array<string,mixed>> */
    public static function forProject(int $projectId, int $limit = self::KEEP_PER_PROJECT): array
    {
        $limit = max(1, min(200, $limit));
        $rows = Db::rows(
            'SELECT * FROM tasks WHERE project_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . $limit,
            [$projectId]
        );
        return array_map(static fn(array $row): array => self::summary($row), $rows);
    }

    /** Tasks of one course that are not finished. @return array<int,array<string,mixed>> */
    public static function openForProject(int $projectId): array
    {
        $rows = Db::rows(
            'SELECT * FROM tasks WHERE project_id = ? AND status IN (?,?) ORDER BY id',
            [$projectId, self::QUEUED, self::RUNNING]
        );
        return array_map(static fn(array $row): array => self::summary($row), $rows);
    }

    /** Every task still owed, across the installation. @return array<int,array<string,mixed>> */
    public static function open(): array
    {
        $rows = Db::rows(
            'SELECT * FROM tasks WHERE status IN (?,?) ORDER BY id',
            [self::QUEUED, self::RUNNING]
        );
        return array_map(static fn(array $row): array => self::summary($row), $rows);
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    /* --------------------------------------------------------------- claims */

    /**
     * Hands the next task that is due to a worker, atomically.
     *
     * The UPDATE is the claim: whoever's reports a changed row has the task,
     * and a worker that loses the race asks again. A course never has two of
     * its tasks worked at once - a publish and a link pass on the same wiki
     * would write the same pages over each other - so a task whose course
     * already has one running is left for later.
     *
     * @return array<string,mixed>|null the row, with the claim on it
     */
    public static function claimNext(string $owner, int $leaseSeconds): ?array
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $row = Db::row(
                'SELECT * FROM tasks t
                  WHERE t.status = ? AND t.next_at <= ?
                    AND NOT EXISTS (SELECT 1 FROM tasks r WHERE r.project_id = t.project_id AND r.status = ?)
                  ORDER BY t.next_at, t.id
                  LIMIT 1',
                [self::QUEUED, time(), self::RUNNING]
            );
            if ($row === null) {
                return null;
            }
            $claimed = self::claim((int)$row['id'], $owner, $leaseSeconds);
            if ($claimed !== null) {
                return $claimed;
            }
        }
        return null;
    }

    /**
     * Claims one particular task, if it is waiting and due.
     *
     * This is what the browser uses when the scheduler is not around: the
     * Publish tab asks for one slice at a time, under the same lease and the
     * same rules, so a task never has two workers whichever of them arrives.
     *
     * @return array<string,mixed>|null
     */
    public static function claim(int $id, string $owner, int $leaseSeconds): ?array
    {
        $now = time();
        $taken = Db::run(
            'UPDATE tasks SET status = ?, owner = ?, lease_until = ?, updated_at = ?,
                    started_at = CASE WHEN started_at = 0 THEN ? ELSE started_at END
              WHERE id = ? AND status = ? AND next_at <= ?
                AND NOT EXISTS (SELECT 1 FROM tasks r WHERE r.project_id = tasks.project_id AND r.status = ?)',
            [self::RUNNING, $owner, $now + max(30, $leaseSeconds), $now, $now, $id, self::QUEUED, $now, self::RUNNING]
        )->rowCount() > 0;

        return $taken ? self::find($id) : null;
    }

    /** Keeps the lease alive. False means the task was taken away - stopped, or given to somebody else. */
    public static function renew(int $id, string $owner, int $leaseSeconds): bool
    {
        return Db::run(
            'UPDATE tasks SET lease_until = ?, updated_at = ? WHERE id = ? AND owner = ? AND status = ?',
            [time() + max(30, $leaseSeconds), time(), $id, $owner, self::RUNNING]
        )->rowCount() > 0;
    }

    /**
     * Puts a task back in the queue with its place kept, because the slice ran
     * out of time rather than into trouble. Not an attempt: nothing failed.
     *
     * @param array<string,mixed> $progress
     */
    public static function pause(int $id, string $owner, array $progress): bool
    {
        return Db::run(
            'UPDATE tasks SET status = ?, progress = ?, owner = ?, lease_until = 0, next_at = ?, updated_at = ?
              WHERE id = ? AND owner = ? AND status = ?',
            [self::QUEUED, self::encode($progress), '', time(), time(), $id, $owner, self::RUNNING]
        )->rowCount() > 0;
    }

    /**
     * Puts a task back in the queue after a failure, to be tried again later
     * from where it stopped. This is what counts as an attempt.
     *
     * @param array<string,mixed> $progress
     */
    public static function retryLater(int $id, string $owner, array $progress, string $error, int $delaySeconds): bool
    {
        return Db::run(
            'UPDATE tasks SET status = ?, progress = ?, error = ?, attempts = attempts + 1, owner = ?,
                    lease_until = 0, next_at = ?, updated_at = ?
              WHERE id = ? AND owner = ? AND status = ?',
            [
                self::QUEUED, self::encode($progress), mb_substr($error, 0, 1000), '',
                time() + max(1, $delaySeconds), time(), $id, $owner, self::RUNNING,
            ]
        )->rowCount() > 0;
    }

    /**
     * Ends a task, one way or the other.
     *
     * @param array<string,mixed> $progress
     */
    public static function finish(int $id, string $owner, array $progress, string $status, string $error = ''): bool
    {
        if (!in_array($status, [self::DONE, self::FAILED], true)) {
            throw new \InvalidArgumentException('A task finishes as done or failed, not as ' . $status . '.');
        }
        $now = time();
        return Db::run(
            'UPDATE tasks SET status = ?, progress = ?, error = ?, owner = ?, lease_until = 0,
                    finished_at = ?, updated_at = ?
              WHERE id = ? AND owner = ? AND status = ?',
            [$status, self::encode($progress), mb_substr($error, 0, 1000), '', $now, $now, $id, $owner, self::RUNNING]
        )->rowCount() > 0;
    }

    /**
     * Stops a task. A worker holding it finds out at its next renew and stops
     * writing; what it had already written stays, because it was real work.
     */
    public static function cancel(int $id): bool
    {
        $now = time();
        return Db::run(
            'UPDATE tasks SET status = ?, owner = ?, lease_until = 0, finished_at = ?, updated_at = ?
              WHERE id = ? AND status IN (?,?)',
            [self::CANCELED, '', $now, $now, $id, self::QUEUED, self::RUNNING]
        )->rowCount() > 0;
    }

    /**
     * Gives a task that failed or was stopped another go, from where it got
     * to. The attempt counter starts again: a person asking is a new decision,
     * not the twenty-first automatic one.
     */
    public static function requeue(int $id): bool
    {
        return Db::run(
            'UPDATE tasks SET status = ?, attempts = 0, error = ?, owner = ?, lease_until = 0, next_at = ?,
                    finished_at = 0, updated_at = ?
              WHERE id = ? AND status IN (?,?)',
            [self::QUEUED, '', '', time(), time(), $id, self::FAILED, self::CANCELED]
        )->rowCount() > 0;
    }

    /** Forgets a finished task and its log. */
    public static function delete(int $id): void
    {
        Db::run('DELETE FROM tasks WHERE id = ? AND status IN (?,?,?)', array_merge([$id], self::TERMINAL));
    }

    /** Forgets every finished task of one course. */
    public static function clearFinished(int $projectId): int
    {
        return Db::run(
            'DELETE FROM tasks WHERE project_id = ? AND status IN (?,?,?)',
            array_merge([$projectId], self::TERMINAL)
        )->rowCount();
    }

    /**
     * Gives tasks whose worker died back to the queue.
     *
     * A worker is a PHP process on somebody else's server. When its lease has
     * run out with the row still `running`, nothing is working on it, and the
     * task is queued again - with its place kept, and the lost slice counted as
     * an attempt, because whatever killed the process may do it again and a
     * task must not be able to loop for ever on a host that cuts it off.
     *
     * @return int tasks released
     */
    public static function recover(): int
    {
        $now = time();
        $lost = Db::rows(
            'SELECT id, attempts, max_attempts FROM tasks WHERE status = ? AND lease_until > 0 AND lease_until < ?',
            [self::RUNNING, $now]
        );
        $released = 0;
        foreach ($lost as $row) {
            $id = (int)$row['id'];
            $exhausted = (int)$row['attempts'] + 1 >= max(1, (int)$row['max_attempts']);
            $changed = $exhausted
                ? Db::run(
                    'UPDATE tasks SET status = ?, attempts = attempts + 1, error = ?, owner = ?, lease_until = 0,
                            finished_at = ?, updated_at = ?
                      WHERE id = ? AND status = ? AND lease_until < ?',
                    [
                        self::FAILED,
                        'The process working on this task stopped answering, and it had already been tried the '
                        . 'maximum number of times.',
                        '', $now, $now, $id, self::RUNNING, $now,
                    ]
                )->rowCount()
                : Db::run(
                    'UPDATE tasks SET status = ?, attempts = attempts + 1, owner = ?, lease_until = 0,
                            next_at = ?, updated_at = ?
                      WHERE id = ? AND status = ? AND lease_until < ?',
                    [self::QUEUED, '', $now, $now, $id, self::RUNNING, $now]
                )->rowCount();
            if ($changed > 0) {
                self::log(
                    $id,
                    $exhausted
                        ? 'The worker stopped answering, and the task has been given up on.'
                        : 'The worker stopped answering. The task goes back into the queue and carries on from where it got to.',
                    $exhausted ? 'error' : 'warn'
                );
                $released++;
            }
        }
        return $released;
    }

    /** How long a failed task waits before it is tried again: 30 seconds, then a minute, up to a quarter of an hour. */
    public static function backoff(int $attempt): int
    {
        $steps = [30, 60, 120, 300, 600, 900];
        return $steps[max(0, min(count($steps) - 1, $attempt - 1))];
    }

    /* ------------------------------------------------------------------ log */

    public static function log(int $taskId, string $line, string $level = 'info', ?int $targetId = null): void
    {
        $line = trim($line);
        if ($line === '') {
            return;
        }
        Db::run(
            'INSERT INTO task_log (task_id, ts, level, target_id, line) VALUES (?,?,?,?,?)',
            [$taskId, time(), $level, $targetId, mb_substr($line, 0, 2000)]
        );
    }

    /**
     * Log lines of a course's tasks, in the order they were said.
     *
     * With `$afterId` this is the poll: only what was said since. Without it,
     * the most recent `$limit` lines - a course that has been published fifty
     * times has thousands, and the screen wants the end of the story first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function logLines(int $projectId, int $afterId = 0, int $limit = 400): array
    {
        $limit = max(1, min(2000, $limit));
        if ($afterId > 0) {
            $rows = Db::rows(
                'SELECT l.* FROM task_log l JOIN tasks t ON t.id = l.task_id
                  WHERE t.project_id = ? AND l.id > ? ORDER BY l.id LIMIT ' . $limit,
                [$projectId, $afterId]
            );
        } else {
            $rows = array_reverse(Db::rows(
                'SELECT l.* FROM task_log l JOIN tasks t ON t.id = l.task_id
                  WHERE t.project_id = ? ORDER BY l.id DESC LIMIT ' . $limit,
                [$projectId]
            ));
        }
        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'task_id' => (int)$row['task_id'],
            'ts' => (int)$row['ts'],
            'level' => (string)$row['level'],
            'target_id' => $row['target_id'] === null ? null : (int)$row['target_id'],
            'line' => (string)$row['line'],
        ], $rows);
    }

    /** The log of one task alone. @return array<int,array<string,mixed>> */
    public static function logOf(int $taskId, int $afterId = 0, int $limit = 2000): array
    {
        $limit = max(1, min(5000, $limit));
        $rows = Db::rows(
            'SELECT * FROM task_log WHERE task_id = ? AND id > ? ORDER BY id LIMIT ' . $limit,
            [$taskId, $afterId]
        );
        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'task_id' => (int)$row['task_id'],
            'ts' => (int)$row['ts'],
            'level' => (string)$row['level'],
            'target_id' => $row['target_id'] === null ? null : (int)$row['target_id'],
            'line' => (string)$row['line'],
        ], $rows);
    }

    /* ---------------------------------------------------------------- shape */

    /**
     * The shape the browser reads.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function summary(array $row): array
    {
        $params = json_decode((string)$row['params'], true);
        $progress = json_decode((string)$row['progress'], true);
        $status = (string)$row['status'];
        $lease = (int)$row['lease_until'];

        return [
            'id' => (int)$row['id'],
            'project_id' => (int)$row['project_id'],
            // The course owner, whose profile holds the credentials the task
            // publishes with - the account the work is done as.
            'username' => (string)$row['username'],
            'kind' => (string)$row['kind'],
            'kind_label' => (string)$row['kind'] === self::KIND_LINKS ? 'Resolve auto links' : 'Publish',
            'status' => $status,
            'terminal' => self::isTerminal($status),
            // Running, and somebody is actually holding it - a lease that has
            // run out is a worker that died, which the sweep will notice.
            'live' => $status === self::RUNNING && $lease >= time(),
            'attempts' => (int)$row['attempts'],
            'max_attempts' => (int)$row['max_attempts'],
            'next_at' => (int)$row['next_at'],
            'error' => (string)$row['error'],
            'params' => is_array($params) ? $params : [],
            'progress' => is_array($progress) ? $progress : [],
            'created_by' => (string)$row['created_by'],
            'source' => (string)$row['source'],
            'created_at' => (int)$row['created_at'],
            'updated_at' => (int)$row['updated_at'],
            'started_at' => (int)$row['started_at'],
            'finished_at' => (int)$row['finished_at'],
        ];
    }

    /* -------------------------------------------------------------- helpers */

    /** @param array<string,mixed> $value */
    private static function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    /** Keeps the newest finished tasks of a course and forgets the rest. */
    private static function prune(int $projectId): void
    {
        $rows = Db::rows(
            'SELECT id FROM tasks WHERE project_id = ? AND status IN (?,?,?) ORDER BY created_at DESC, id DESC',
            array_merge([$projectId], self::TERMINAL)
        );
        $extra = array_slice(array_map(static fn(array $r): int => (int)$r['id'], $rows), self::KEEP_PER_PROJECT);
        foreach ($extra as $id) {
            Db::run('DELETE FROM tasks WHERE id = ?', [$id]);
        }
    }
}
