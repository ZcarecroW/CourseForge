<?php
declare(strict_types=1);

namespace CourseForge\Mcp\Handlers;

use CourseForge\Ai\Completion;
use CourseForge\Ai\ModelId;
use CourseForge\Ai\PageGenerator;
use CourseForge\Ai\Provider\BatchCapable;
use CourseForge\Ai\Provider\Provider;
use CourseForge\Ai\Provider\Providers;
use CourseForge\Ai\Run\RunManager;
use CourseForge\Domain\Details;
use CourseForge\Domain\Pages;
use CourseForge\Domain\Projects;
use CourseForge\Domain\Runs;
use CourseForge\Mcp\Args;
use CourseForge\Mcp\Resolve;
use CourseForge\Mcp\Schema;
use CourseForge\Mcp\Scopes;
use CourseForge\Mcp\Tool;
use CourseForge\Security\Access;
use CourseForge\Security\Actor;
use CourseForge\Support\Audit;
use CourseForge\Support\Config;
use CourseForge\Support\Db;
use CourseForge\Support\HttpException;
use CourseForge\Support\Runtime;
use Throwable;

/**
 * Runs: work that is written down before it starts, and outlives the client.
 *
 * Every other tool on this surface finishes while somebody is still listening.
 * A run does not, and that is the point of it. The selection, the model, the
 * account and every page in it are recorded in the database before a single
 * request leaves the server, so ordering five hundred pages and then closing
 * the laptop is a supported way of using CourseForge rather than something it
 * happens to survive. The conversation that started a run does not have to be
 * the conversation that collects it: come back tomorrow, call list_runs, and
 * the course is written.
 *
 * There are two ways that happens. A live run is worked by CourseForge's own
 * scheduler, one page at a time, tick after tick, and is the answer for an
 * ordinary model on a host where cron is set up. A batch run hands every prompt
 * to the provider's own queue in one submission and collects the answers within
 * a day at roughly half the price - which for a course generator is usually the
 * better trade. Nobody needs a textbook in ninety seconds; they need it to be
 * good, and they would rather not pay twice for the privilege of watching a
 * progress bar.
 *
 * A model working here should go in that order: estimate_run to see the size of
 * what it is about to buy, scheduler_status when the run will be a background
 * one, start_run, and then poll_run or list_runs whenever it next gets the
 * chance to look.
 */
final class RunTools
{
    /**
     * Four characters to a token.
     *
     * The same divisor the HTTP estimate uses, so the two surfaces cannot
     * disagree about the same course. It is a rule of thumb for English prose
     * and is reported as one: the number exists to tell fifty pages apart from
     * five hundred, not to predict an invoice.
     */
    private const CHARS_PER_TOKEN = 4;

    /** Six characters to a word, its trailing space included. */
    private const CHARS_PER_WORD = 6;

    /**
     * How many prompts an estimate actually builds.
     *
     * Building one is several queries, and every page prompt carries the same
     * course structure - so a spread sample scales up honestly, and a
     * five-hundred-page estimate does not cost two thousand queries.
     */
    private const SAMPLE_PAGES = 24;

    /** Newest-first cap when listing runs across every course. */
    private const LIST_LIMIT = 100;

    /** @return array<int,Tool> */
    public static function tools(): array
    {
        return [
            new Tool(
                name: 'start_run',
                scope: Scopes::RUNS,
                title: 'Write pages on the server',
                description: 'Starts writing a set of pages on the server, and returns at once. The run is recorded '
                    . 'before any work begins, so it carries on after this client disconnects, after the session '
                    . 'ends and across a restart of the server - start it, go away, and call poll_run or list_runs '
                    . 'later to see where it got to. A live run is written by CourseForge\'s own scheduler in the '
                    . 'background and therefore needs cron to be configured: when it is not, this tool starts '
                    . 'nothing and says so, and the ways round it are to have an administrator set app.cron_token, '
                    . 'to switch to a batch run, or to write pages one at a time with generate_page. A batch run '
                    . 'hands the whole selection to the provider\'s own queue at roughly half the price and is '
                    . 'answered within 24 hours, which is the right choice for a large course where time is not '
                    . 'critical. mode auto reads the profile\'s page model: a model ending in ":batch" queues, '
                    . 'anything else goes to the background worker. Call estimate_run first to see the size of it.',
                properties: [
                    'course_id' => Schema::courseId(),
                    'pages' => self::pagesArgument(),
                    'chapter_id' => Schema::int(
                        'Restrict the run to one chapter. It combines with pages: the selection is then made inside '
                        . 'that chapter only.'
                    ),
                    'mode' => Schema::enum(
                        'How the work is done. auto reads the profile\'s page model and is almost always right. '
                        . 'live means CourseForge writes the pages itself from the scheduler; batch means the '
                        . 'provider\'s queue, at about half the price, answered within a day.',
                        ['auto', 'live', 'batch']
                    ),
                ],
                required: ['course_id'],
                handler: static fn(Actor $actor, array $args): array => self::startRun($actor, Args::of($args)),
                spends: true,
            ),

            new Tool(
                name: 'estimate_run',
                scope: Scopes::RUNS,
                title: 'What a run would cost',
                description: 'What start_run would do with the same arguments, without doing it and without '
                    . 'spending anything: how many pages, which model and provider, whether it would go to the '
                    . 'background worker or to the batch queue, and roughly how many input and output tokens are '
                    . 'involved. The input figure is measured from the real prompts - the same ones the run would '
                    . 'send - sampled across the selection and scaled up, and the answer says how many pages were '
                    . 'measured and at how many characters to the token. No price is given: CourseForge does not '
                    . 'know what an account pays, and a confident number in the wrong currency is worse than a '
                    . 'token count. Read the warnings: the usual one is that the provider has a batch queue nobody '
                    . 'is using. The prompts it measures are built and thrown away and no model is ever called, so '
                    . 'finding out what a run would cost is itself free. Costs nothing.',
                properties: [
                    'course_id' => Schema::courseId(),
                    'pages' => self::pagesArgument(),
                    'chapter_id' => Schema::int('Restrict the estimate to one chapter.'),
                    'mode' => Schema::enum(
                        'Which way to cost it. auto reads the profile\'s page model, as start_run would.',
                        ['auto', 'live', 'batch']
                    ),
                ],
                required: ['course_id'],
                handler: static fn(Actor $actor, array $args): array => self::estimateRun($actor, Args::of($args)),
                readOnly: true,
                idempotent: true,
                // It asks the provider whether it runs a batch queue, which is
                // a round trip off this machine even though nothing is spent.
                openWorld: true,
            ),

            new Tool(
                name: 'list_runs',
                scope: Scopes::RUNS,
                title: 'List generation runs',
                description: 'Every run this account can see, newest first, with the course it belongs to, whether '
                    . 'it is live or batch, its status, and how many of its pages are written, still pending, '
                    . 'skipped or failed. This is the tool to call after reconnecting, to find out whether work '
                    . 'ordered earlier is still going. only=open lists just the runs that have not ended; all and '
                    . 'finished look at the 100 newest. Follow with get_run for the detail of one, or poll_run to '
                    . 'make a batch check in now. Costs nothing.',
                properties: [
                    'course_id' => Schema::int('One course only. Omit for every course this account can see.'),
                    'only' => Schema::enum(
                        'Which runs to return. open means anything that has not ended.',
                        ['all', 'open', 'finished']
                    ),
                ],
                required: [],
                handler: static fn(Actor $actor, array $args): array => self::listRuns($actor, Args::of($args)),
                readOnly: true,
                idempotent: true,
            ),

            new Tool(
                name: 'get_run',
                scope: Scopes::RUNS,
                title: 'Read one run',
                description: 'One run in detail: its status, its mode, the model and provider behind it, the page '
                    . 'counts, when it started, when it was last checked, and - for a batch - the provider\'s own '
                    . 'id and the moment its results expire and stop being collectable. include_items adds every '
                    . 'page in the run with its own status and error message, which is how you find out which '
                    . 'three of five hundred pages failed and why. This reads what is stored and asks the provider '
                    . 'nothing; poll_run is the one that goes out to the network. Costs nothing.',
                properties: [
                    'run_id' => Schema::int('The run, as returned by list_runs or start_run.'),
                    'include_items' => Schema::bool(
                        'Include every page in the run with its own status and error. On a large run this is a '
                        . 'long list.'
                    ),
                ],
                required: ['run_id'],
                handler: static fn(Actor $actor, array $args): array => self::getRun($actor, Args::of($args)),
                readOnly: true,
                idempotent: true,
                // A five-hundred-page run with include_items is a row per page,
                // errors included. Without the hint a client cuts it off part
                // way down the list, which is exactly the part being read for.
                maxResultChars: 200000,
            ),

            new Tool(
                name: 'poll_run',
                scope: Scopes::RUNS,
                title: 'Check a run now',
                description: 'Asks the provider now whether a batch has finished, and writes home every page that '
                    . 'has, instead of waiting for the next scheduler tick to do it. Use it when you want the '
                    . 'answer in this conversation. On a live run there is no provider to ask: it reports where '
                    . 'the background worker has got to and closes the run if the last page has landed. Safe to '
                    . 'call as often as you like - the same page is never written twice - and it spends nothing '
                    . 'beyond what the run has already committed. It reports how many pages arrived since the '
                    . 'previous check. Costs nothing.',
                properties: [
                    'run_id' => Schema::int('The run to check.'),
                ],
                required: ['run_id'],
                handler: static fn(Actor $actor, array $args): array => self::pollRun($actor, Args::of($args)),
                idempotent: true,
                // Straight to the provider, and it downloads whatever has
                // finished while it is there.
                openWorld: true,
            ),

            new Tool(
                name: 'cancel_run',
                scope: Scopes::RUNS,
                title: 'Stop a run',
                description: 'Stops a run and releases the pages it was holding back to unwritten. At the provider '
                    . 'it is best effort: a batch that has already finished cannot be cancelled, and is collected '
                    . 'and written home instead of being thrown away. A page a live worker is writing at this very '
                    . 'moment is left to finish, because it has already been paid for. Pages that were written '
                    . 'before the cancel stay written - this stops the work, it does not undo it. Use delete_run '
                    . 'afterwards to remove the record. Costs nothing.',
                properties: [
                    'run_id' => Schema::int('The run to stop.'),
                ],
                required: ['run_id'],
                handler: static fn(Actor $actor, array $args): array => self::cancelRun($actor, Args::of($args)),
                destructive: true,
                idempotent: true,
                // Cancelling a batch is a request to the provider, and one that
                // collects the run instead if it has already finished.
                openWorld: true,
                // No confirm_ argument here or on delete_run, and the asymmetry
                // with the rest of the surface is deliberate. Cancelling stops
                // work that has not happened yet and start_run orders it again;
                // deleting a finished run removes a record and not one word of
                // what it wrote. Neither destroys anything a person made, which
                // is the thing a confirmation is for. apply_structure, which
                // does, has one.
            ),

            new Tool(
                name: 'delete_run',
                scope: Scopes::RUNS,
                title: 'Forget a finished run',
                description: 'Removes the record of a run that has ended. This is bookkeeping only: every page the '
                    . 'run wrote stays exactly as it is, and nothing is refunded or recalled. A run that is still '
                    . 'open is refused - stop it with cancel_run first, then delete it. Costs nothing.',
                properties: [
                    'run_id' => Schema::int('The finished run to forget.'),
                ],
                required: ['run_id'],
                handler: static fn(Actor $actor, array $args): array => self::deleteRun($actor, Args::of($args)),
                destructive: true,
                idempotent: true,
            ),

            new Tool(
                name: 'scheduler_status',
                scope: Scopes::RUNS,
                title: 'Is anything collecting background work',
                description: 'Whether background work will actually be picked up on this installation: whether a '
                    . 'cron token is configured, when the scheduler last called in, how many worker slots it has, '
                    . 'and how many runs and pages are waiting for it. Ask before starting a live run if you want '
                    . 'to be sure something will collect it, because a background run on an installation with no '
                    . 'scheduler sits untouched for ever. A batch run can be submitted without the scheduler - the '
                    . 'provider holds that work - but then its results have to be collected with poll_run. Costs '
                    . 'nothing.',
                properties: [],
                required: [],
                handler: static fn(Actor $actor, array $args): array => self::schedulerStatus($actor),
                readOnly: true,
                idempotent: true,
            ),
        ];
    }

    /* ------------------------------------------------------------- handlers */

    /** @return array<string,mixed> */
    private static function startRun(Actor $actor, Args $args): array
    {
        ['project' => $project, 'owner' => $owner] = Resolve::course($actor, $args->id());
        $profile = Resolve::profile($project);
        $config = Completion::modelConfig($profile, 'page');

        $selection = self::selection($args, $project);
        $pageIds = array_map(static fn(array $page): int => (int)$page['id'], $selection['pages']);

        $mode = self::mode($args, $config['model']);
        $cron = RunManager::cronStatus();

        // Starting a background run on an installation with no scheduler would
        // write a row that nothing will ever pick up. Refusing with the three
        // ways out is more use than a run that never moves.
        if ($mode === Runs::MODE_LIVE && !$cron['configured']) {
            return [
                'started' => false,
                'reason' => 'The scheduler is not configured on this installation, so a background run would sit '
                    . 'untouched. Nothing has been started and nothing has been spent.',
                'course_id' => (int)$project['id'],
                'would_have_written' => count($pageIds),
                'options' => [
                    'An administrator sets app.cron_token in data/config.json and has the host call /cron.php once '
                        . 'a minute. scheduler_status confirms it once that is done.',
                    'Run it as a batch instead: mode "batch" hands the work to the provider\'s own queue, which '
                        . 'does not need the scheduler, and costs about half as much. Collect it with poll_run.',
                    'Write the pages one at a time with generate_page, or with get_page_brief and write_page, '
                        . 'which both happen while you are connected.',
                ],
                'next_step' => 'Call scheduler_status for the detail, or start_run again with mode "batch".',
            ];
        }

        // Submitting a batch builds every prompt and posts them in one request,
        // and the queue probe below is a network round trip of its own.
        Runtime::beginLongRequest();

        if ($mode === Runs::MODE_BATCH) {
            self::requireBatchQueue($profile, $config['ai_id'], count($pageIds));
        }

        $run = RunManager::start($owner, $profile, $project, $pageIds, $mode);
        Audit::record(
            $actor->username,
            'run.start',
            (string)$project['name'],
            $mode . ' run over ' . count($pageIds) . ' page(s) with ' . $run['model'],
            'mcp'
        );

        return [
            'started' => true,
            'run_id' => (int)$run['id'],
            'course_id' => (int)$project['id'],
            'course' => (string)$project['name'],
            'mode' => $mode,
            'pages' => count($pageIds),
            'selection' => $selection['how'],
            'model' => (string)$run['model'],
            'provider' => (string)$run['provider'],
            'status' => (string)$run['status'],
        ] + self::deadlines($run) + [
            'what_happens_now' => $mode === Runs::MODE_BATCH
                ? 'The whole selection is queued at the provider. Answers normally arrive well inside 24 hours and '
                    . 'cost about half the live rate. The scheduler collects them when it next runs; poll_run asks now.'
                : 'CourseForge writes these pages itself, one at a time, from the scheduler. Nothing further is '
                    . 'needed from you, and the work continues after you disconnect.',
            'warnings' => self::startWarnings($mode, $cron),
            'next_step' => 'The work is on the server now and you may disconnect. Call poll_run with run_id '
                . $run['id'] . ' to check on it, get_run for the page-by-page detail, or list_runs later.',
        ];
    }

    /** @return array<string,mixed> */
    private static function estimateRun(Actor $actor, Args $args): array
    {
        ['project' => $project] = Resolve::course($actor, $args->id());
        $profile = Resolve::profile($project);
        $config = Completion::modelConfig($profile, 'page');
        $provider = Providers::fromProfile($profile, $config['ai_id']);

        $selection = self::selection($args, $project);
        $pages = count($selection['pages']);

        $batched = ModelId::isBatch($config['model']);
        $mode = self::mode($args, $config['model']);

        // Two dozen prompts is two dozen small queries, and asking a gateway
        // whether it runs a queue is a network round trip.
        Runtime::beginLongRequest();

        $available = self::batchAvailable($provider);
        $sample = self::sample($profile, $project, $selection['pages']);

        $inputPerPage = (int)ceil($sample['input_chars'] / $sample['sampled'] / self::CHARS_PER_TOKEN);
        $outputPerPage = self::wordsToTokens($sample['output_words'] / $sample['sampled']);
        $ceilingPerPage = self::wordsToTokens($sample['ceiling_words'] / $sample['sampled']);

        // Whatever the course asked for in words, the model's own output limit
        // is a hard ceiling.
        if ($config['max_tokens'] > 0) {
            $outputPerPage = min($outputPerPage, $config['max_tokens']);
            $ceilingPerPage = min($ceilingPerPage, $config['max_tokens']);
        }

        return [
            'course_id' => (int)$project['id'],
            'course' => (string)$project['name'],
            'pages' => $pages,
            'sampled' => $sample['sampled'],
            'selection' => $selection['how'],
            'mode' => $mode,
            'model' => ModelId::base($config['model']),
            'model_slot' => $config['model'],
            'provider' => $provider->kind(),
            'provider_label' => $provider->label(),
            'batched_model' => $batched,
            'batch_available' => $available,
            'batch_limit' => $provider instanceof BatchCapable ? $provider->batchLimits()->maxRequests : 0,
            'background_available' => RunManager::cronConfigured(),
            'tokens' => [
                'input_per_page' => $inputPerPage,
                'output_per_page' => $outputPerPage,
                'input' => $inputPerPage * $pages,
                'output' => $outputPerPage * $pages,
                'total' => ($inputPerPage + $outputPerPage) * $pages,
                'output_ceiling' => $ceilingPerPage * $pages,
                'chars_per_token' => self::CHARS_PER_TOKEN,
            ],
            'price' => null,
            'basis' => self::basis($sample, $pages, $config['max_tokens']),
            'warnings' => self::estimateWarnings($mode, $batched, $available, $provider, $pages),
            'next_step' => 'Nothing has been spent. Call start_run with the same course_id, pages, chapter_id and '
                . 'mode to begin.',
        ];
    }

    /** @return array<string,mixed> */
    private static function listRuns(Actor $actor, Args $args): array
    {
        $only = $args->enum('only', ['all', 'open', 'finished'], 'all');
        $capped = false;

        if ($args->has('course_id')) {
            ['project' => $project, 'owner' => $owner] = Resolve::course($actor, $args->id());
            $summaries = Runs::forProject($owner, (int)$project['id']);
            $names = [(int)$project['id'] => (string)$project['name']];
            $owners = [(int)$project['id'] => $owner];
        } else {
            $names = [];
            $owners = [];
            foreach (Projects::all($actor->isAdmin() ? null : $actor->username) as $course) {
                $names[(int)$course['id']] = (string)$course['name'];
                $owners[(int)$course['id']] = (string)$course['owner'];
            }

            if ($only === 'open') {
                // Open runs are the ones somebody is actually waiting for, so
                // they are read in full rather than off the top of a capped list.
                $summaries = Runs::open($actor->isAdmin() ? '' : $actor->username);
            } else {
                // One scoped query rather than one per course: an account with
                // fifty courses would otherwise cost fifty round trips before
                // the first run could be shown. The limit is inlined because
                // Db binds everything as text and SQLite will not take a string
                // in LIMIT - the value is a class constant, not input.
                [$where, $params] = $actor->scope('username');
                $rows = Db::rows(
                    'SELECT * FROM batch_jobs WHERE ' . $where
                    . ' ORDER BY created_at DESC, id DESC LIMIT ' . self::LIST_LIMIT,
                    $params
                );
                $capped = count($rows) >= self::LIST_LIMIT;
                $summaries = array_map(static fn(array $row): array => Runs::summary($row), $rows);
            }
        }

        usort(
            $summaries,
            static fn(array $a, array $b): int => [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']]
        );

        $runs = [];
        foreach ($summaries as $summary) {
            $terminal = (bool)$summary['terminal'];
            if (($only === 'open' && $terminal) || ($only === 'finished' && !$terminal)) {
                continue;
            }
            $courseId = (int)$summary['project_id'];
            $runs[] = self::shape(
                $summary,
                $names[$courseId] ?? '',
                $actor->isAdmin() ? ($owners[$courseId] ?? '') : null
            );
        }

        return [
            'runs' => $runs,
            'count' => count($runs),
            'filter' => $only,
            'truncated' => $capped,
            'hint' => $runs === []
                ? ($only === 'open'
                    ? 'Nothing is running. start_run begins a new one.'
                    : 'There are no runs to show.')
                : 'Call get_run for the page-by-page detail of one, or poll_run to make a batch check in now.',
        ];
    }

    /** @return array<string,mixed> */
    private static function getRun(Actor $actor, Args $args): array
    {
        ['run' => $row, 'owner' => $owner] = self::resolveRun($actor, $args);
        $summary = Runs::summary($row);
        $project = Projects::find($owner, (int)$row['project_id']) ?? [];

        $out = self::shape($summary, (string)($project['name'] ?? ''), $actor->isAdmin() ? $owner : null);
        $out['profile_id'] = $row['profile_id'] === null ? null : (int)$row['profile_id'];
        $out['provider_run_id'] = (string)$summary['remote_id'];
        $out['provider_state'] = (string)$summary['remote_state'];
        $out['provider_counts'] = $summary['counts'];

        if ($args->bool('include_items')) {
            $items = [];
            foreach (Runs::items((int)$row['id']) as $item) {
                $items[] = [
                    'page_id' => (int)$item['page_id'],
                    'title' => (string)$item['title'],
                    'status' => (string)$item['status'],
                    'attempts' => (int)$item['attempts'],
                    'started_at' => self::when((int)$item['started_at']),
                    'error' => (string)$item['error'],
                ];
            }
            $out['items'] = $items;
        }

        $out['next_step'] = $summary['terminal']
            ? ($summary['pages']['failed'] > 0
                ? 'Some pages failed. Start a new run with pages "failed" to try them again, or read the errors '
                    . 'with include_items.'
                : 'This run has ended. delete_run removes the record; the pages it wrote stay.')
            : 'This run is still going. poll_run asks the provider now; otherwise the scheduler collects it.';

        return $out;
    }

    /** @return array<string,mixed> */
    private static function pollRun(Actor $actor, Args $args): array
    {
        ['run' => $row, 'owner' => $owner] = self::resolveRun($actor, $args);
        $before = Runs::summary($row);

        // Polling a batch means a request to the provider and, when it has
        // finished, downloading and storing every page it answered.
        Runtime::beginLongRequest();
        $after = RunManager::poll($owner, (int)$row['id']);

        $project = Projects::find($owner, (int)$row['project_id']) ?? [];
        $arrived = (int)$after['pages']['written'] - (int)$before['pages']['written'];

        $out = self::shape($after, (string)($project['name'] ?? ''), $actor->isAdmin() ? $owner : null);
        $out['pages_arrived_since_last_check'] = max(0, $arrived);
        $out['provider_state'] = (string)$after['remote_state'];
        $out['next_step'] = $after['terminal']
            ? 'This run has ended. get_course or list_pages shows what it wrote.'
            : 'Still outstanding. Poll again later, or leave it to the scheduler - the run does not need you.';

        return $out;
    }

    /** @return array<string,mixed> */
    private static function cancelRun(Actor $actor, Args $args): array
    {
        ['run' => $row, 'owner' => $owner] = self::resolveRun($actor, $args);

        if (Runs::isTerminal((string)$row['status'])) {
            return [
                'cancelled' => false,
                'run_id' => (int)$row['id'],
                'status' => (string)$row['status'],
                'reason' => 'This run had already ended, so there was nothing to stop.',
            ];
        }

        // Cancelling a batch asks the provider first, which is a network call,
        // and collects the run instead if it turns out to have finished.
        Runtime::beginLongRequest();
        $run = RunManager::cancel($owner, (int)$row['id']);
        Audit::record($actor->username, 'run.cancel', 'run ' . $row['id'], (string)$row['mode'] . ' run', 'mcp');

        $project = Projects::find($owner, (int)$row['project_id']) ?? [];
        $out = self::shape($run, (string)($project['name'] ?? ''), $actor->isAdmin() ? $owner : null);
        $out['cancelled'] = (string)$run['status'] === Runs::CANCELED;
        $out['note'] = (string)$run['status'] === Runs::CANCELED
            ? 'Pending pages were released and are unwritten again - the tally counts them under "failed", which '
                . 'is where it puts anything that produced no text. Pages already written are kept, and a page a '
                . 'worker was holding at the time will still finish.'
            : 'The run had finished before the cancel reached the provider, so it was collected rather than '
                . 'thrown away.';
        $out['next_step'] = 'delete_run removes the record now that it has ended.';

        return $out;
    }

    /** @return array<string,mixed> */
    private static function deleteRun(Actor $actor, Args $args): array
    {
        ['run' => $row, 'owner' => $owner] = self::resolveRun($actor, $args);

        if (!Runs::isTerminal((string)$row['status'])) {
            throw HttpException::unprocessable(
                'Run ' . $row['id'] . ' is still open, with status "' . $row['status'] . '". Stop it with '
                . 'cancel_run first, then delete it.'
            );
        }

        Runs::delete($owner, (int)$row['id']);
        Audit::record($actor->username, 'run.delete', 'run ' . $row['id'], (string)$row['mode'] . ' run', 'mcp');

        return [
            'deleted' => true,
            'run_id' => (int)$row['id'],
            'course_id' => (int)$row['project_id'],
            'note' => 'The record is gone. Every page the run wrote is untouched.',
        ];
    }

    /** @return array<string,mixed> */
    private static function schedulerStatus(Actor $actor): array
    {
        $cron = RunManager::cronStatus();
        $open = Runs::open($actor->isAdmin() ? '' : $actor->username);

        $live = 0;
        $batch = 0;
        $outstanding = 0;
        foreach ($open as $run) {
            (string)$run['mode'] === Runs::MODE_BATCH ? $batch++ : $live++;
            $outstanding += (int)$run['pages']['pending'] + (int)$run['pages']['working'];
        }

        $minutes = (int)round($cron['seconds_ago'] / 60);

        return [
            'configured' => $cron['configured'],
            'healthy' => $cron['healthy'],
            'last_run_at' => self::when($cron['last_at']),
            'seconds_since_last_run' => $cron['last_at'] > 0 ? $cron['seconds_ago'] : null,
            'workers' => max(1, min(8, Config::int('app.cron_workers', 2))),
            'seconds_per_tick' => max(5, Config::int('app.cron_seconds', 50)),
            'open_runs' => ['live' => $live, 'batch' => $batch, 'total' => $live + $batch],
            'pages_outstanding' => $outstanding,
            'verdict' => match (true) {
                !$cron['configured'] => 'No cron token is configured, so nothing picks up background work. A live '
                    . 'run started now would never move. Batch runs can still be submitted, because the provider '
                    . 'holds that work, but their results have to be collected with poll_run.',
                $cron['last_at'] === 0 => 'The scheduler is configured but has never called in. Check that the host '
                    . 'really calls /cron.php with the token once a minute before relying on a background run.',
                !$cron['healthy'] => 'The scheduler last called in about ' . $minutes . ' minute(s) ago, which is '
                    . 'later than the once a minute it is meant to run. Work will be collected slowly, or not at all '
                    . 'if it has stopped.',
                default => 'The scheduler is running. A background run started now is picked up within a minute or '
                    . 'so, and finished batches are collected on the same tick.',
            },
            'next_step' => $cron['configured']
                ? 'start_run may be used in either mode.'
                : 'Use mode "batch" for large work, or generate_page for one page at a time.',
        ];
    }

    /* -------------------------------------------------------------- helpers */

    /**
     * The `pages` argument, which takes either a keyword or a list of ids.
     *
     * @return array<string,mixed>
     */
    private static function pagesArgument(): array
    {
        return [
            'description' => 'Which pages to write. Omit it, or "unwritten", for every page that has no text yet - '
                . 'the usual case. "all" rewrites the whole course, pages that are already written included. '
                . '"failed" retries the ones whose last attempt errored. Or give a list of page ids from '
                . 'list_pages. Pages already claimed by another run are left out of the keyword selections.',
            'anyOf' => [
                ['type' => 'string', 'enum' => ['unwritten', 'all', 'failed']],
                ['type' => 'array', 'items' => ['type' => 'integer']],
            ],
        ];
    }

    /**
     * The pages a run would cover, in reading order.
     *
     * Shared by start_run and estimate_run so that an estimate is always about
     * the set the run would actually take.
     *
     * @param array<string,mixed> $project
     * @return array{pages:array<int,array<string,mixed>>,how:string}
     */
    private static function selection(Args $args, array $project): array
    {
        $projectId = (int)$project['id'];
        $pages = Pages::ordered($projectId);

        if ($pages === []) {
            throw HttpException::unprocessable(
                'Course "' . $project['name'] . '" has no pages to write. Give it an outline first: '
                . 'generate_structure designs one, apply_structure stores one you wrote yourself.'
            );
        }

        $chapterId = null;
        if ($args->has('chapter_id')) {
            $chapter = Resolve::chapter($project, $args->id('chapter_id'));
            $chapterId = (int)$chapter['id'];
            $pages = array_values(array_filter(
                $pages,
                static fn(array $page): bool => (int)$page['chapter_id'] === $chapterId
            ));
            if ($pages === []) {
                throw HttpException::unprocessable(
                    'Chapter "' . $chapter['title'] . '" has no pages in it.'
                );
            }
        }

        $raw = $args->all()['pages'] ?? null;
        if (is_array($raw)) {
            return self::explicitSelection($args, $project, $pages, $chapterId);
        }

        $which = $args->enum('pages', ['unwritten', 'all', 'failed'], 'unwritten');

        $chosen = [];
        $claimed = 0;
        foreach ($pages as $page) {
            $written = trim((string)$page['content']) !== '';
            $matches = match ($which) {
                'all' => true,
                'failed' => (string)$page['status'] === 'error',
                default => !$written,
            };
            if (!$matches) {
                continue;
            }
            // A page already in an open run is never claimed twice; the unique
            // index would refuse it anyway, and leaving it out says so better.
            if ((string)$page['status'] === 'queued') {
                $claimed++;
                continue;
            }
            $chosen[] = $page;
        }

        if ($chosen === []) {
            throw HttpException::unprocessable(self::emptySelectionMessage($which, $project, $chapterId, $claimed));
        }

        $where = $chapterId === null ? '' : ' in that chapter';
        return [
            'pages' => $chosen,
            'how' => match ($which) {
                'all' => 'every page' . $where . ' (' . count($chosen) . '), written ones included',
                'failed' => count($chosen) . ' page(s)' . $where . ' whose last attempt failed',
                default => count($chosen) . ' unwritten page(s)' . $where,
            },
        ];
    }

    /**
     * A selection given as page ids.
     *
     * @param array<string,mixed> $project
     * @param array<int,array<string,mixed>> $pages the course, or one chapter of it
     * @return array{pages:array<int,array<string,mixed>>,how:string}
     */
    private static function explicitSelection(Args $args, array $project, array $pages, ?int $chapterId): array
    {
        $ids = $args->ids('pages');
        if ($ids === []) {
            throw HttpException::unprocessable(
                'pages was an empty list. Omit it for every unwritten page, or give at least one page id from '
                . 'list_pages.'
            );
        }

        $known = [];
        foreach ($pages as $page) {
            $known[(int)$page['id']] = $page;
        }

        $chosen = [];
        foreach ($ids as $id) {
            if (isset($known[$id])) {
                $chosen[] = $known[$id];
                continue;
            }
            // Not in the candidate set: either not this course's page at all,
            // which Resolve reports, or outside the chapter that was asked for.
            Resolve::page($project, $id);
            throw HttpException::unprocessable(
                'Page ' . $id . ' is not in chapter ' . $chapterId . '. Drop chapter_id, or leave that page out.'
            );
        }

        return ['pages' => $chosen, 'how' => count($chosen) . ' page(s) you named'];
    }

    /** @param array<string,mixed> $project */
    private static function emptySelectionMessage(
        string $which,
        array $project,
        ?int $chapterId,
        int $claimed,
    ): string {
        $where = $chapterId === null ? 'course "' . $project['name'] . '"' : 'that chapter';

        if ($claimed > 0) {
            return 'Every page in ' . $where . ' that matched is already part of an open run ('
                . $claimed . ' of them). Call list_runs with only "open" to see it, or cancel_run to stop it first.';
        }

        return match ($which) {
            'failed' => 'No page in ' . $where . ' has failed, so there is nothing to retry.',
            'all' => 'There are no pages in ' . $where . ' to write.',
            default => 'Every page in ' . $where . ' has already been written. Pass pages "all" to rewrite them, '
                . '"failed" to retry only the ones that errored, or a list of page ids from list_pages.',
        };
    }

    /** auto reads the model, exactly as RunManager would if the mode were left empty. */
    private static function mode(Args $args, string $model): string
    {
        $asked = $args->enum('mode', ['auto', 'live', 'batch'], 'auto');
        if ($asked !== 'auto') {
            return $asked === 'batch' ? Runs::MODE_BATCH : Runs::MODE_LIVE;
        }
        return ModelId::isBatch($model) ? Runs::MODE_BATCH : Runs::MODE_LIVE;
    }

    /**
     * Refuses a batch the provider cannot take, before anything is reserved.
     *
     * @param array<string,mixed> $profile
     */
    private static function requireBatchQueue(array $profile, string $accountId, int $pages): void
    {
        $provider = Providers::fromProfile($profile, $accountId);

        if (!$provider instanceof BatchCapable || !$provider->supportsBatch()) {
            throw HttpException::unprocessable(
                $provider->label() . ' has no batch queue to submit to. Start this with mode "live" so '
                . 'CourseForge writes the pages itself, or point the profile\'s page model at an account that has '
                . 'one - list_profiles shows which.'
            );
        }
        $maxRequests = $provider->batchLimits()->maxRequests;
        if ($pages > $maxRequests) {
            throw HttpException::unprocessable(
                'That is ' . number_format($pages) . ' pages, and ' . $provider->label() . ' accepts at most '
                . number_format($maxRequests) . ' in one batch. Split it with chapter_id or an explicit '
                . 'list of page ids and start several runs.'
            );
        }
    }

    /** Whether the provider answers with a queue at all. A probe is not worth failing an estimate over. */
    private static function batchAvailable(Provider $provider): bool
    {
        try {
            return $provider instanceof BatchCapable && $provider->supportsBatch();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Measures the prompts of a spread sample of the selected pages.
     *
     * The text measured is the text that would be sent: PageGenerator::plan()
     * is the same call the live worker and the batch submission both make.
     *
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $project
     * @param array<int,array<string,mixed>> $selected
     * @return array{sampled:int,input_chars:int,output_words:int,ceiling_words:int,bounded:bool}
     */
    private static function sample(array $profile, array $project, array $selected): array
    {
        $total = count($selected);
        $step = (int)ceil($total / self::SAMPLE_PAGES);
        $courseSettings = Projects::settings($project);

        $sampled = 0;
        $chars = 0;
        $words = 0;
        $ceiling = 0;
        $bounded = true;

        for ($i = 0; $i < $total; $i += $step) {
            $page = $selected[$i];
            $plan = PageGenerator::plan($profile, $project, (int)$page['id']);
            $chars += mb_strlen($plan['system']) + mb_strlen($plan['user']);

            // The length settings resolve course, then chapter, then page, the
            // way the generator resolves them - so a chapter told to write long
            // pages is not averaged away by the course default.
            $params = Details::resolve(
                $courseSettings,
                Details::decode((string)($page['chapter_settings'] ?? '{}')),
                Pages::settings($page),
            )['params'];

            $min = max(0, (int)($params['min_length'] ?? 0));
            $max = max(0, (int)($params['max_length'] ?? 0));

            if ($min === 0 && $max === 0) {
                // Both bounds off means "leave the length to the model", and
                // there is nothing in that to estimate an answer from.
                $bounded = false;
            }
            $words += $min > 0 && $max > 0 ? (int)round(($min + $max) / 2) : max($min, $max);
            $ceiling += max($min, $max);

            $sampled++;
        }

        return [
            'sampled' => $sampled,
            'input_chars' => $chars,
            'output_words' => $words,
            'ceiling_words' => $ceiling,
            'bounded' => $bounded,
        ];
    }

    private static function wordsToTokens(float $words): int
    {
        return (int)ceil($words * self::CHARS_PER_WORD / self::CHARS_PER_TOKEN);
    }

    /**
     * How the numbers were arrived at.
     *
     * An estimate nobody can check is a guess with a decimal point on it, so
     * every assumption behind it travels with it.
     *
     * @param array{sampled:int,input_chars:int,output_words:int,ceiling_words:int,bounded:bool} $sample
     * @return string[]
     */
    private static function basis(array $sample, int $pages, int $maxTokens): array
    {
        $lines = [
            'Tokens are counted at ' . self::CHARS_PER_TOKEN . ' characters each, the rule of thumb for English '
                . 'prose. No provider will tokenise a prompt for free before it is sent, so this is an estimate '
                . 'rather than a quotation.',
        ];

        $lines[] = $sample['sampled'] >= $pages
            ? 'The input figure is the real length of all ' . $pages . ' prompt(s) this run would send.'
            : 'The input figure was measured from ' . $sample['sampled'] . ' of the ' . $pages . ' prompts, spread '
                . 'across the selection, and scaled up. Every page prompt carries the same course structure, so '
                . 'they differ little in length.';

        $lines[] = $sample['bounded']
            ? 'The output figure comes from the length settings of the pages themselves, converted at '
                . self::CHARS_PER_WORD . ' characters to the word.'
            : 'Some of these pages have no length bounds set, so their share of the output figure is a floor rather '
                . 'than an estimate - the model decides how long they get.';

        if ($maxTokens > 0) {
            $lines[] = 'The model is capped at ' . number_format($maxTokens) . ' output tokens a page, and no page '
                . 'is counted above that.';
        }

        $lines[] = 'CourseForge does not know what this account pays. Rates differ by model, by provider and by '
            . 'contract, so no price is given: multiply these counts by your own rates for the model named above.';

        return $lines;
    }

    /**
     * What would go wrong, or cost more than it needs to.
     *
     * @return string[]
     */
    private static function estimateWarnings(
        string $mode,
        bool $batched,
        bool $available,
        Provider $provider,
        int $pages,
    ): array {
        $warnings = [];

        if ($mode === Runs::MODE_BATCH && !$available) {
            $warnings[] = $provider->label() . ' has no batch queue to submit to, so this cannot run as a batch. '
                . 'start_run would refuse it.';
        }
        if ($mode === Runs::MODE_BATCH && $provider instanceof BatchCapable) {
            $maxRequests = $provider->batchLimits()->maxRequests;
            if ($pages > $maxRequests) {
                $warnings[] = 'That is more pages than ' . $provider->label() . ' accepts in one batch ('
                    . number_format($maxRequests) . '). Split the selection with chapter_id or page ids.';
            }
        }
        if ($mode === Runs::MODE_LIVE && !RunManager::cronConfigured()) {
            $warnings[] = 'A background run needs the scheduler, which is not configured here, so start_run would '
                . 'refuse this. See scheduler_status.';
        }
        if (!$batched && $available) {
            // The reason this tool exists: the cheaper door is open and the
            // caller is about to walk past it.
            $warnings[] = $provider->label() . ' does run a batch queue, and providers that have one commonly bill '
                . 'it at about half the live rate with answers inside 24 hours. Adding ":batch" to the profile\'s '
                . 'page model, or passing mode "batch", would send this work through it.';
        }

        return $warnings;
    }

    /**
     * @param array{configured:bool,last_at:int,seconds_ago:int,healthy:bool} $cron
     * @return string[]
     */
    private static function startWarnings(string $mode, array $cron): array
    {
        if ($mode !== Runs::MODE_LIVE || $cron['healthy']) {
            return [];
        }

        return [$cron['last_at'] === 0
            ? 'The scheduler is configured but has never called in, so nothing may collect this run. Check the '
                . 'host really calls /cron.php once a minute.'
            : 'The scheduler last called in about ' . (int)round($cron['seconds_ago'] / 60) . ' minute(s) ago, '
                . 'later than it should. This run may sit untouched until it comes back.'];
    }

    /**
     * A run the actor may reach, and its owner.
     *
     * @return array{run:array<string,mixed>,owner:string}
     */
    private static function resolveRun(Actor $actor, Args $args): array
    {
        $row = Access::run($actor, $args->id('run_id'));
        return ['run' => $row, 'owner' => (string)$row['username']];
    }

    /**
     * One run, in the shape these tools answer with.
     *
     * @param array<string,mixed> $summary a Runs::summary() row
     * @return array<string,mixed>
     */
    private static function shape(array $summary, string $courseName, ?string $owner): array
    {
        $row = [
            'run_id' => (int)$summary['id'],
            'course_id' => (int)$summary['project_id'],
            'course' => $courseName,
            'mode' => (string)$summary['mode'],
            'status' => (string)$summary['status'],
            'finished' => (bool)$summary['terminal'],
            'provider' => (string)$summary['provider'],
            'model' => (string)$summary['model'],
            'pages' => $summary['pages'],
            'started_at' => self::when((int)$summary['created_at']),
            'last_checked_at' => self::when((int)$summary['polled_at']),
            'finished_at' => self::when((int)$summary['finished_at']),
            'error' => (string)$summary['error'],
        ] + self::deadlines($summary);
        if ($owner !== null) {
            $row['owner'] = $owner;
        }
        return $row;
    }

    /**
     * A run's two deadlines, named so that neither can be read as the other.
     *
     * They are weeks apart and they describe different losses.
     * `batch_expires_at` is when the provider stops running whatever is still
     * queued - a day nearly everywhere, two on Gemini, which then returns
     * nothing at all for the pages it never reached. `results_expire_at` is
     * when the finished answers stop being downloadable: 29 days at Anthropic,
     * 30 at OpenAI and OpenRouter, six weeks at Gemini.
     *
     * Both are reported because an agent acts on them differently. The first
     * says whether it is still worth waiting; the second says how long the
     * scheduler has to collect a batch nobody is watching. Reporting the first
     * under the second's name - which is what this did - tells an agent that a
     * month of retention is over tomorrow, and that is the precise inversion
     * the two fields exist to prevent. Either is null when the run is a live
     * one, or was submitted before its deadline was recorded.
     *
     * @param array<string,mixed> $summary a Runs::summary() row
     * @return array{batch_expires_at:?string,results_expire_at:?string}
     */
    private static function deadlines(array $summary): array
    {
        return [
            'batch_expires_at' => self::when((int)($summary['expires_at'] ?? 0)),
            'results_expire_at' => self::when((int)($summary['results_expire_at'] ?? 0)),
        ];
    }

    /** A stored moment as ISO 8601, or null for one that has not happened. */
    private static function when(int $timestamp): ?string
    {
        return $timestamp > 0 ? gmdate('c', $timestamp) : null;
    }
}
