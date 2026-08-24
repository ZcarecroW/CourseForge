<?php
declare(strict_types=1);

namespace CourseForge\Domain;

use CourseForge\Support\Db;
use CourseForge\Support\HttpException;
use PDOException;

/**
 * Generation runs and the pages inside them.
 *
 * A run is a set of pages to write, and there are two ways it gets written:
 *
 *   - `batch`  the whole set is handed to the provider's queue and collected
 *              later, at half the price,
 *   - `live`   CourseForge writes the pages itself, one at a time, from cron.
 *
 * Both share this table because they are the same thing from the outside: work
 * that was asked for, work still outstanding, work that came back. The
 * difference is only who does it.
 *
 * Either way a run outlives every process that touches it - a provider is
 * allowed a full day, and a 500-page live run takes hours - so nothing is held
 * in memory. The rows are written before the work starts, and closing the
 * browser is not an event the run notices.
 *
 * The tables are still called `batch_jobs` and `batch_items`: they were named
 * before live runs existed, and on an installation that already has runs in
 * them a rename would buy nothing worth the risk.
 */
final class Runs
{
    /* ------------------------------------------------------------ vocabulary */

    public const MODE_BATCH = 'batch';
    public const MODE_LIVE = 'live';

    /** Reserved, not yet started - a live run never lingers here. */
    public const PREPARING = 'preparing';
    /** Handed over (batch) or waiting for a worker (live). */
    public const SUBMITTED = 'submitted';
    public const RUNNING = 'running';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
    public const CANCELED = 'canceled';

    /** Statuses that will never change again without a new run. */
    private const TERMINAL = [self::COMPLETED, self::FAILED, self::CANCELED];

    /** Item states. `working` belongs to a live worker that holds the page. */
    public const ITEM_PENDING = 'pending';
    public const ITEM_WORKING = 'working';
    public const ITEM_DONE = 'succeeded';

    /* ---------------------------------------------------------------- create */

    /**
     * Claims the pages before any work starts.
     *
     * Reserving first is what makes starting a run safe. The obvious order -
     * do the work, then write down what was done - has two holes: two browser
     * tabs can both pass an "is this page already busy?" check and both start,
     * and a batch submission that succeeds while the insert afterwards fails
     * leaves a batch running at the provider that CourseForge has no record of.
     * Writing the rows first closes both: the unique index on one active item
     * per page rejects the second claim, and a failed submission deletes a run
     * that never had a remote id.
     *
     * @param array{mode:string,provider:string,ai_id:string,model:string} $meta
     * @param array<int,array{page_id:int,custom_id:string,title:string}> $items
     */
    public static function reserve(
        string $username,
        int $projectId,
        ?int $profileId,
        string $slot,
        array $meta,
        array $items,
    ): int {
        $now = time();

        try {
            return (int)Db::transaction(static function () use ($username, $projectId, $profileId, $slot, $meta, $items, $now): int {
                Db::run(
                    'INSERT INTO batch_jobs
                        (username, project_id, profile_id, slot, mode, provider, ai_id, model,
                         remote_id, remote_state, remote_ref, status, error, counts,
                         created_at, updated_at, polled_at, finished_at, expires_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $username, $projectId, $profileId, $slot, $meta['mode'],
                        $meta['provider'], $meta['ai_id'], $meta['model'],
                        '', '', '',
                        self::PREPARING, '', '{}',
                        $now, $now, 0, 0, 0,
                    ]
                );
                $runId = Db::lastId();

                foreach ($items as $item) {
                    Db::run(
                        'INSERT INTO batch_items (job_id, page_id, custom_id, title, status, error, attempts, started_at)
                         VALUES (?,?,?,?,?,?,0,0)',
                        [$runId, $item['page_id'], $item['custom_id'], $item['title'], self::ITEM_PENDING, '']
                    );
                }
                return $runId;
            });
        } catch (PDOException) {
            // The only unique constraint that can fire here is one active item
            // per page, and it means somebody got there first.
            throw HttpException::unprocessable(
                'Some of those pages are already part of a run. Open the run panel to see it, '
                . 'or stop it before starting them again.'
            );
        }
    }

    /**
     * Records the provider's answer, turning a reservation into a live batch.
     *
     * Both deadlines are written here because this is the only moment either is
     * learned, and they are weeks apart. `expiresAt` is when the queue stops
     * running whatever it has not reached - 24 hours nearly everywhere, 48 on
     * Gemini, which then returns nothing at all for the pages left over.
     * `resultsExpireAt` is when the finished answers stop being downloadable:
     * 29 days at Anthropic, 30 at OpenAI and OpenRouter, six weeks at Gemini. A
     * run collected after the first date has lost the pages that were still
     * queued; a run collected after the second has lost all of them, including
     * the ones that were answered and paid for. Neither loss is recoverable,
     * which is the whole reason a provider is not treated as storage.
     */
    public static function activate(
        int $runId,
        string $remoteId,
        string $remoteState,
        string $remoteRef,
        int $expiresAt,
        int $resultsExpireAt = 0,
    ): void {
        Db::run(
            'UPDATE batch_jobs SET remote_id = ?, remote_state = ?, remote_ref = ?, status = ?,
                    expires_at = ?, results_expire_at = ?, updated_at = ?
              WHERE id = ?',
            [$remoteId, $remoteState, $remoteRef, self::SUBMITTED, $expiresAt, $resultsExpireAt, time(), $runId]
        );
    }

    /** Opens a live run for the cron worker to pick up. */
    public static function start(int $runId): void
    {
        Db::run(
            'UPDATE batch_jobs SET status = ?, updated_at = ? WHERE id = ?',
            [self::SUBMITTED, time(), $runId]
        );
    }

    /** Drops a reservation whose work never began. */
    public static function discard(int $runId): void
    {
        Db::run('DELETE FROM batch_jobs WHERE id = ? AND remote_id = ?', [$runId, '']);
    }

    /* ----------------------------------------------------------------- reads */

    /** @return array<string,mixed>|null */
    public static function find(string $username, int $id): ?array
    {
        return Db::row('SELECT * FROM batch_jobs WHERE username = ? AND id = ?', [$username, $id]);
    }

    /** @return array<string,mixed> */
    public static function require(string $username, int $id): array
    {
        return self::find($username, $id) ?? throw HttpException::notFound('Run not found.');
    }

    /** Every run of one course, newest first. @return array<int,array<string,mixed>> */
    public static function forProject(string $username, int $projectId): array
    {
        $rows = Db::rows(
            'SELECT * FROM batch_jobs WHERE username = ? AND project_id = ? ORDER BY created_at DESC',
            [$username, $projectId]
        );
        return array_map(static fn(array $row): array => self::summary($row), $rows);
    }

    /**
     * Runs still outstanding, across every course and optionally every user,
     * nearest download deadline first.
     *
     * The order is the interesting part, because the scheduler works to a time
     * budget and stops part way down this list every time an installation has
     * more open runs than one tick can collect. Whatever is left over waits a
     * minute, which costs nothing - unless one of those runs is a finished
     * batch whose answers the provider is about to delete, in which case it
     * costs the course. So a run with a known retention deadline is polled
     * before one without, soonest first, and creation order decides the rest.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function open(string $username = '', string $mode = ''): array
    {
        $sql = 'SELECT * FROM batch_jobs WHERE status NOT IN (?,?,?)';
        $args = self::TERMINAL;

        if ($username !== '') {
            $sql .= ' AND username = ?';
            $args[] = $username;
        }
        if ($mode !== '') {
            $sql .= ' AND mode = ?';
            $args[] = $mode;
        }
        // `results_expire_at = 0` sorts as 0 for a run that has a deadline and 1
        // for one that has none, so the runs on a clock come first and a live
        // run - which has no provider holding anything - comes after them.
        $sql .= ' ORDER BY results_expire_at = 0, results_expire_at, created_at';

        return array_map(static fn(array $row): array => self::summary($row), Db::rows($sql, $args));
    }

    /** @return array<int,array<string,mixed>> */
    public static function items(int $runId): array
    {
        return Db::rows('SELECT * FROM batch_items WHERE job_id = ? ORDER BY id', [$runId]);
    }

    /** The items still waiting for an answer. @return array<int,array<string,mixed>> */
    public static function pendingItems(int $runId): array
    {
        return Db::rows(
            'SELECT * FROM batch_items WHERE job_id = ? AND status IN (?,?) ORDER BY id',
            [$runId, self::ITEM_PENDING, self::ITEM_WORKING]
        );
    }

    /* --------------------------------------------------------------- updates */

    /** @param array<string,mixed> $fields */
    public static function update(int $runId, array $fields): void
    {
        $writable = ['remote_state', 'remote_ref', 'status', 'error', 'counts', 'polled_at', 'finished_at', 'expires_at'];
        $set = [];
        $args = [];
        foreach ($fields as $key => $value) {
            if (in_array($key, $writable, true)) {
                $set[] = $key . ' = ?';
                $args[] = $value;
            }
        }
        if ($set === []) {
            return;
        }
        $set[] = 'updated_at = ?';
        $args[] = time();
        $args[] = $runId;
        Db::run('UPDATE batch_jobs SET ' . implode(', ', $set) . ' WHERE id = ?', $args);
    }

    /**
     * Records one page's outcome, but only from the state it is expected in.
     *
     * The guard is what makes a second poller, or a run cancelled mid-flight,
     * harmless: whoever gets there first writes the page and everybody else
     * finds nothing left to settle rather than overwriting it.
     */
    public static function settleItem(int $runId, string $customId, string $status, string $error = '', string $from = ''): bool
    {
        $expected = $from !== '' ? [$from] : [self::ITEM_PENDING, self::ITEM_WORKING];
        $placeholders = implode(',', array_fill(0, count($expected), '?'));

        return Db::run(
            'UPDATE batch_items SET status = ?, error = ? WHERE job_id = ? AND custom_id = ? AND status IN (' . $placeholders . ')',
            array_merge([$status, mb_substr($error, 0, 500), $runId, $customId], $expected)
        )->rowCount() > 0;
    }

    /* ------------------------------------------------------------ the worker */

    /**
     * Hands one page to a cron worker, atomically.
     *
     * Two ticks may run at once - that is the whole point of having more than
     * one worker slot - so the claim has to be the thing that decides, not a
     * read followed by a write. The UPDATE reporting a changed row is the claim;
     * a worker that loses the race simply asks again.
     *
     * @return array<string,mixed>|null
     */
    public static function claimNextItem(): ?array
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $row = Db::row(
                'SELECT i.*, j.username, j.project_id, j.profile_id, j.slot
                   FROM batch_items i
                   JOIN batch_jobs j ON j.id = i.job_id
                  WHERE j.mode = ? AND j.status IN (?,?) AND i.status = ?
                  ORDER BY j.created_at, i.id
                  LIMIT 1',
                [self::MODE_LIVE, self::SUBMITTED, self::RUNNING, self::ITEM_PENDING]
            );
            if ($row === null) {
                return null;
            }

            $claimed = Db::run(
                'UPDATE batch_items SET status = ?, started_at = ?, attempts = attempts + 1
                  WHERE id = ? AND status = ?',
                [self::ITEM_WORKING, time(), (int)$row['id'], self::ITEM_PENDING]
            )->rowCount() > 0;

            if ($claimed) {
                Db::run(
                    'UPDATE batch_jobs SET status = ?, updated_at = ? WHERE id = ? AND status = ?',
                    [self::RUNNING, time(), (int)$row['job_id'], self::SUBMITTED]
                );
                $row['attempts'] = (int)$row['attempts'] + 1;
                $row['status'] = self::ITEM_WORKING;
                return $row;
            }
        }
        return null;
    }

    /**
     * Pages whose worker never came back.
     *
     * A worker is a PHP process on somebody else's server; it can be killed at
     * any moment. Anything left `working` for longer than a page could
     * plausibly take is assumed lost. Deciding what to *do* about each one is
     * the driver's job, because it also has to put the page itself right - a
     * row here is only half the story.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function staleItems(int $olderThanSeconds): array
    {
        return Db::rows(
            'SELECT i.*, j.project_id, j.username
               FROM batch_items i
               JOIN batch_jobs j ON j.id = i.job_id
              WHERE i.status = ? AND i.started_at > 0 AND i.started_at < ?',
            [self::ITEM_WORKING, time() - max(60, $olderThanSeconds)]
        );
    }

    /** Closes a live run once none of its pages are outstanding. */
    public static function closeIfDone(int $runId): bool
    {
        $row = Db::row(
            'SELECT COUNT(*) AS outstanding FROM batch_items WHERE job_id = ? AND status IN (?,?)',
            [$runId, self::ITEM_PENDING, self::ITEM_WORKING]
        );
        if ((int)($row['outstanding'] ?? 0) > 0) {
            return false;
        }

        Db::run(
            'UPDATE batch_jobs SET status = ?, finished_at = ?, updated_at = ? WHERE id = ? AND status NOT IN (?,?,?)',
            array_merge([self::COMPLETED, time(), time(), $runId], self::TERMINAL)
        );
        return true;
    }

    /* -------------------------------------------------------------- lifecycle */

    public static function delete(string $username, int $id): void
    {
        self::require($username, $id);
        Db::run('DELETE FROM batch_jobs WHERE username = ? AND id = ?', [$username, $id]);
    }

    /** Finished runs are only bookkeeping; the pages they wrote stay. */
    public static function pruneFinished(string $username, int $keepDays = 30): void
    {
        $cutoff = time() - max(1, $keepDays) * 86400;
        Db::run(
            'DELETE FROM batch_jobs WHERE username = ? AND status IN (?,?,?) AND finished_at > 0 AND finished_at < ?',
            array_merge([$username], self::TERMINAL, [$cutoff])
        );
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    /**
     * The shape the browser polls.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function summary(array $row): array
    {
        $runId = (int)$row['id'];
        $tally = Db::row(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'pending'    THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN status = 'working'    THEN 1 ELSE 0 END) AS working,
                    SUM(CASE WHEN status = 'succeeded'  THEN 1 ELSE 0 END) AS written,
                    SUM(CASE WHEN status = 'superseded' THEN 1 ELSE 0 END) AS skipped,
                    SUM(CASE WHEN status NOT IN ('pending','working','succeeded','superseded') THEN 1 ELSE 0 END) AS failed
               FROM batch_items WHERE job_id = ?",
            [$runId]
        ) ?? [];

        $counts = json_decode((string)$row['counts'], true);

        return [
            'id' => $runId,
            'project_id' => (int)$row['project_id'],
            'mode' => (string)($row['mode'] ?? self::MODE_BATCH),
            'slot' => (string)$row['slot'],
            'provider' => (string)$row['provider'],
            'model' => (string)$row['model'],
            'remote_id' => (string)$row['remote_id'],
            'remote_state' => (string)$row['remote_state'],
            'status' => (string)$row['status'],
            'error' => (string)$row['error'],
            'terminal' => self::isTerminal((string)$row['status']),
            'counts' => is_array($counts) ? $counts : [],
            'pages' => [
                'total' => (int)($tally['total'] ?? 0),
                'pending' => (int)($tally['pending'] ?? 0),
                'working' => (int)($tally['working'] ?? 0),
                'written' => (int)($tally['written'] ?? 0),
                // Answers that arrived for a page somebody had already written
                // another way, and which were therefore not applied.
                'skipped' => (int)($tally['skipped'] ?? 0),
                'failed' => (int)($tally['failed'] ?? 0),
            ],
            'created_at' => (int)$row['created_at'],
            'updated_at' => (int)$row['updated_at'],
            'polled_at' => (int)$row['polled_at'],
            'finished_at' => (int)$row['finished_at'],
            // Two deadlines, never one. The first is when the provider stops
            // running what is still queued, the second when it stops letting
            // the finished answers be downloaded - a day against a month.
            'expires_at' => (int)$row['expires_at'],
            'results_expire_at' => (int)($row['results_expire_at'] ?? 0),
        ];
    }
}
