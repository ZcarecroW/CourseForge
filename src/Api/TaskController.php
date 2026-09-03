<?php
declare(strict_types=1);

namespace CourseForge\Api;

use CourseForge\Ai\Run\RunManager;
use CourseForge\Domain\Projects;
use CourseForge\Domain\Tasks;
use CourseForge\Publish\Publisher;
use CourseForge\Security\Access;
use CourseForge\Security\Actor;
use CourseForge\Support\Config;
use CourseForge\Support\HttpException;
use CourseForge\Support\Request;
use CourseForge\Support\Runtime;
use CourseForge\Tasks\Runner;

/**
 * The tasks of one course: what is queued, what happened, and the log.
 *
 * Publishing used to be one long request. It is now a task the scheduler
 * works, and this is how the Publish tab talks to it: it queues one, watches
 * it, reads what it said, stops it, or - when no scheduler is calling in -
 * works a slice of it itself, one request at a time, so a push still happens
 * on an installation that has not set the scheduler up.
 */
final class TaskController
{
    /** How long a browser-driven slice may run before it hands the task back. */
    private const BROWSER_SLICE_SECONDS = 25;

    /** @return array<string,mixed> */
    public static function index(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $projectId = $request->id('id');
        Access::project($me, $projectId);

        $after = max(0, $request->queryInt('after', 0));

        return [
            'tasks' => Tasks::forProject($projectId),
            'log' => Tasks::logLines($projectId, $after, $after > 0 ? 2000 : 400),
            'cron' => RunManager::cronStatus(),
        ];
    }

    /**
     * Queues a publish or a link pass.
     *
     * Everything that can be refused at the door - no profile, no destination,
     * a destination its profile no longer defines - is refused here, with the
     * same words the synchronous push uses, rather than being written down as
     * a task that fails a minute later with nobody watching.
     *
     * @return array<string,mixed>
     */
    public static function create(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $projectId = $request->id('id');
        $project = Access::project($me, $projectId);
        $owner = (string)$project['username'];

        $kind = $request->enum('kind', [Tasks::KIND_PUBLISH, Tasks::KIND_LINKS], Tasks::KIND_PUBLISH);
        $scope = $request->enum('scope', ['all', 'book', 'chapter', 'page'], 'all');
        $itemId = $request->intOrNull('target_id');
        if ($kind === Tasks::KIND_PUBLISH && in_array($scope, ['chapter', 'page'], true) && ($itemId === null || $itemId <= 0)) {
            throw HttpException::unprocessable('A target id is required when pushing a single ' . $scope . '.');
        }
        $targetIds = self::targetIds($request);

        // Opened and thrown away: this is the check, not the push.
        Publisher::open($owner, $projectId, $targetIds);

        $params = [
            'scope' => $kind === Tasks::KIND_LINKS ? 'all' : $scope,
            'item_id' => $kind === Tasks::KIND_PUBLISH && in_array($scope, ['chapter', 'page'], true) ? $itemId : null,
            'target_ids' => $targetIds ?? [],
            'force' => $request->bool('force'),
        ];

        $task = Tasks::create($owner, $projectId, $kind, $params, $me->username, 'web');

        return [
            'task' => $task,
            'tasks' => Tasks::forProject($projectId),
            'cron' => RunManager::cronStatus(),
        ];
    }

    /** One task and everything it said. @return array<string,mixed> */
    public static function show(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $projectId = $request->id('id');
        Access::project($me, $projectId);
        $task = self::taskOfProject($projectId, $request->id('taskId'));

        return [
            'task' => Tasks::summary($task),
            'log' => Tasks::logOf((int)$task['id'], max(0, $request->queryInt('after', 0))),
        ];
    }

    /** Stops a task. What it has already written stays. @return array<string,mixed> */
    public static function cancel(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $projectId = $request->id('id');
        Access::project($me, $projectId);
        $task = self::taskOfProject($projectId, $request->id('taskId'));

        if (!Tasks::cancel((int)$task['id'])) {
            throw HttpException::unprocessable('This task has already finished.');
        }
        Tasks::log((int)$task['id'], 'Stopped by ' . $me->username . '.', 'warn');

        return ['task' => Tasks::summary(Tasks::require((int)$task['id'])), 'tasks' => Tasks::forProject($projectId)];
    }

    /** Gives a failed or stopped task another go, from where it got to. @return array<string,mixed> */
    public static function retry(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $projectId = $request->id('id');
        Access::project($me, $projectId);
        $task = self::taskOfProject($projectId, $request->id('taskId'));

        if (!Tasks::requeue((int)$task['id'])) {
            throw HttpException::unprocessable('Only a task that failed or was stopped can be retried.');
        }
        Tasks::log((int)$task['id'], 'Retried by ' . $me->username . ' - carrying on from where it stopped.', 'info');

        return ['task' => Tasks::summary(Tasks::require((int)$task['id'])), 'tasks' => Tasks::forProject($projectId)];
    }

    /**
     * Works one slice of a task inside this request.
     *
     * The browser asks for this while the scheduler is not calling in. The
     * slice is bounded so the request ends well inside any host's time limit,
     * and the task is put back with its place kept for the next one - which
     * may be a tick of the scheduler, if it has come back in the meantime.
     *
     * @return array<string,mixed>
     */
    public static function run(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $projectId = $request->id('id');
        $project = Access::project($me, $projectId);
        $task = self::taskOfProject($projectId, $request->id('taskId'));

        Runtime::beginLongRequest();
        $seconds = max(5, min(self::BROWSER_SLICE_SECONDS, Config::int('app.cron_seconds', 50)));
        $result = Runner::runOne((int)$task['id'], microtime(true) + $seconds, 'browser');

        return [
            'ran' => $result['ran'],
            'outcome' => $result['outcome'],
            'task' => $result['task'],
            'tasks' => Tasks::forProject($projectId),
            'project' => Projects::tree((string)$project['username'], $projectId),
        ];
    }

    /** Forgets one finished task and its log. @return array<string,mixed> */
    public static function delete(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $projectId = $request->id('id');
        Access::project($me, $projectId);
        $task = self::taskOfProject($projectId, $request->id('taskId'));

        if (!Tasks::isTerminal((string)$task['status'])) {
            throw HttpException::unprocessable('Stop this task before removing it.');
        }
        Tasks::delete((int)$task['id']);

        return ['tasks' => Tasks::forProject($projectId)];
    }

    /** Forgets every finished task of the course - the Clear button. @return array<string,mixed> */
    public static function clear(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $projectId = $request->id('id');
        Access::project($me, $projectId);

        return ['removed' => Tasks::clearFinished($projectId), 'tasks' => Tasks::forProject($projectId)];
    }

    /* -------------------------------------------------------------- helpers */

    /** @return array<string,mixed> */
    private static function taskOfProject(int $projectId, int $taskId): array
    {
        $task = Tasks::require($taskId);
        if ((int)$task['project_id'] !== $projectId) {
            throw HttpException::notFound('Task not found.');
        }
        return $task;
    }

    /**
     * The wikis one request is aimed at, or null for "every one that is on".
     *
     * The same rule PublishController applies, for the same reason: a
     * malformed id must not quietly widen "this one wiki" into "all of them".
     *
     * @return array<int,int>|null
     */
    private static function targetIds(Request $request): ?array
    {
        if (!$request->has('target_ids')) {
            return null;
        }
        $given = $request->arr('target_ids');
        if ($given === []) {
            return null;
        }
        $ids = [];
        foreach ($given as $id) {
            if (!is_numeric($id) || (int)$id <= 0 || (float)$id !== (float)(int)$id) {
                throw HttpException::unprocessable('target_ids must hold destination ids, as whole numbers.');
            }
            $ids[] = (int)$id;
        }
        return $ids;
    }
}
