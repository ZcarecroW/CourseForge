<?php
declare(strict_types=1);

namespace CourseForge\Api;

use CourseForge\Ai\Completion;
use CourseForge\Ai\ModelId;
use CourseForge\Ai\Provider\BatchCapable;
use CourseForge\Ai\Provider\Providers;
use CourseForge\Ai\Run\RunManager;
use CourseForge\Domain\Pages;
use CourseForge\Domain\Profiles;
use CourseForge\Domain\Projects;
use CourseForge\Domain\Runs;
use CourseForge\Support\Config;
use CourseForge\Support\HttpException;
use CourseForge\Support\Request;
use CourseForge\Support\Runtime;
use Throwable;

/**
 * Generation runs: start one, see where it is, stop it.
 *
 * Nothing here blocks. Starting hands the work over and returns; polling asks
 * once and returns. The browser drives both on a timer while it is open, and
 * cron.php drives them for the hours when it is not.
 */
final class RunController
{
    /** Every run of one course, plus what this course can currently do. */
    public static function index(Request $request, string $username): array
    {
        $projectId = $request->id('id');
        $project = Projects::require($username, $projectId);

        // capability() may ask a gateway whether it has a batch queue, which is
        // a network round trip. Releasing the session lock first stops that
        // serialising every other request the same user has in flight.
        Runtime::beginLongRequest();

        return [
            'runs' => Runs::forProject($username, $projectId),
            'capability' => self::capability($username, $project),
            'cron' => RunManager::cronStatus(),
        ];
    }

    /**
     * Starts a run.
     *
     * `mode` may be given explicitly; without it the model decides, so a
     * `:batch` slot queues at the provider and everything else goes to the
     * background worker.
     */
    public static function create(Request $request, string $username): array
    {
        $projectId = $request->id('id');
        $project = Projects::require($username, $projectId);

        if ($project['profile_id'] === null) {
            throw HttpException::unprocessable('Assign a profile to this course first.');
        }
        $profile = Profiles::data($username, (int)$project['profile_id']);

        $pageIds = self::selection($request, $projectId);
        $mode = $request->enum('mode', [Runs::MODE_LIVE, Runs::MODE_BATCH, ''], '');

        Runtime::beginLongRequest();
        $run = RunManager::start($username, $profile, $project, $pageIds, $mode);

        return self::state($username, $projectId, $run);
    }

    /** Asks where a run stands, and writes home anything finished. */
    public static function poll(Request $request, string $username): array
    {
        $projectId = $request->id('id');
        Projects::require($username, $projectId);
        Runtime::beginLongRequest();

        $runId = $request->intOrNull('run_id');
        $run = null;
        if ($runId !== null) {
            $run = RunManager::poll($username, $runId);
        } else {
            RunManager::pollAll($username);
        }

        return self::state($username, $projectId, $run);
    }

    /** Stops a run and releases its pages. */
    public static function cancel(Request $request, string $username): array
    {
        $projectId = $request->id('id');
        Projects::require($username, $projectId);
        Runtime::beginLongRequest();

        $run = RunManager::cancel($username, $request->requiredId('run_id', 'Run id'));

        return self::state($username, $projectId, $run);
    }

    /** Forgets a finished run. The pages it wrote are untouched. */
    public static function delete(Request $request, string $username): array
    {
        $projectId = $request->id('id');
        Projects::require($username, $projectId);

        $runId = $request->requiredId('run_id', 'Run id');
        $run = Runs::require($username, $runId);
        if (!Runs::isTerminal((string)$run['status'])) {
            throw HttpException::unprocessable('Stop this run before removing it.');
        }
        Runs::delete($username, $runId);

        return ['runs' => Runs::forProject($username, $projectId)];
    }

    /* ------------------------------------------------------------ internals */

    /** @return array<string,mixed> */
    private static function state(string $username, int $projectId, ?array $run): array
    {
        return [
            'run' => $run,
            'runs' => Runs::forProject($username, $projectId),
            'project' => Projects::tree($username, $projectId),
            'cron' => RunManager::cronStatus(),
        ];
    }

    /**
     * What this course's page slot can do right now.
     *
     * The UI needs to know three things before it can offer a button: which
     * mode the model implies, whether that mode is actually available, and how
     * often to come back and look.
     *
     * @param array<string,mixed> $project
     * @return array<string,mixed>
     */
    private static function capability(string $username, array $project): array
    {
        $blank = [
            'mode' => Runs::MODE_LIVE,
            'batched' => false,
            'batch_available' => false,
            'background_available' => RunManager::cronConfigured(),
            'model' => '',
            'provider' => '',
            'label' => '',
            'reason' => '',
            'poll_seconds' => max(15, Config::int('app.batch_poll_seconds', 60)),
        ];

        if ($project['profile_id'] === null) {
            return array_merge($blank, ['reason' => 'This course has no profile yet.']);
        }

        try {
            $profile = Profiles::data($username, (int)$project['profile_id']);
            $config = Completion::modelConfig($profile, 'page');
            $provider = Providers::fromProfile($profile, $config['ai_id']);
            $batched = Completion::isBatched($profile, 'page');
        } catch (Throwable $e) {
            return array_merge($blank, ['reason' => $e->getMessage()]);
        }

        // supportsBatch() probes the network for a generic gateway, so it is
        // only asked once the user has actually opted in with `:batch`.
        $batchAvailable = $batched
            && $provider instanceof BatchCapable
            && $provider->supportsBatch();

        return array_merge($blank, [
            'mode' => $batched ? Runs::MODE_BATCH : Runs::MODE_LIVE,
            'batched' => $batched,
            'batch_available' => $batchAvailable,
            'model' => $config['model'],
            'provider' => $provider->kind(),
            'label' => $provider->label(),
            'reason' => match (true) {
                $batched && !$batchAvailable => $provider->label() . ' did not answer with a batch queue.',
                default => '',
            },
        ]);
    }

    /**
     * Which pages to include.
     *
     * @return array<int,int>
     */
    private static function selection(Request $request, int $projectId): array
    {
        $explicit = $request->arr('pages');
        if ($explicit !== []) {
            $ids = [];
            foreach ($explicit as $value) {
                $id = (int)$value;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
            if ($ids === []) {
                throw HttpException::unprocessable('No usable page ids were given.');
            }
            foreach ($ids as $id) {
                Pages::require($projectId, $id); // reject anything from another course
            }
            return $ids;
        }

        $mode = $request->enum('select', ['missing', 'all', 'errors'], 'missing');
        $ids = [];
        foreach (Pages::ordered($projectId) as $page) {
            $written = trim((string)$page['content']) !== '';
            $matches = match ($mode) {
                'all' => true,
                'errors' => (string)$page['status'] === 'error',
                default => !$written,
            };
            // A page already claimed by a run is never included twice; the
            // unique index would refuse it anyway, but the message is nicer.
            if ($matches && (string)$page['status'] !== 'queued') {
                $ids[] = (int)$page['id'];
            }
        }
        if ($ids === []) {
            throw HttpException::unprocessable('There is nothing to generate with this selection.');
        }
        return $ids;
    }
}
