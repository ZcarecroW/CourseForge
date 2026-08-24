<?php
declare(strict_types=1);

namespace CourseForge\Api;

use CourseForge\Ai\Completion;
use CourseForge\Ai\ModelId;
use CourseForge\Ai\PageGenerator;
use CourseForge\Ai\Provider\BatchCapable;
use CourseForge\Ai\Provider\Provider;
use CourseForge\Ai\Provider\Providers;
use CourseForge\Ai\Run\RunManager;
use CourseForge\Domain\Details;
use CourseForge\Domain\Pages;
use CourseForge\Domain\Profiles;
use CourseForge\Domain\Projects;
use CourseForge\Domain\Runs;
use CourseForge\Security\Access;
use CourseForge\Security\Actor;
use CourseForge\Support\Config;
use CourseForge\Support\Db;
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
    /**
     * Four characters to a token.
     *
     * Every provider in play publishes some version of this rule of thumb for
     * English prose, and none of them will tokenise a prompt for free before it
     * is sent. It is an estimate and it is reported as one - the point of the
     * number is to tell five hundred pages apart from fifty, not to predict an
     * invoice to the token.
     */
    private const CHARS_PER_TOKEN = 4;

    /**
     * Six characters to a word, the space after it included.
     *
     * The length parameters are expressed in words because that is what a
     * person writing a course thinks in. Converting through characters rather
     * than guessing a words-to-tokens ratio keeps the whole estimate resting on
     * the one divisor above.
     */
    private const CHARS_PER_WORD = 6;

    /**
     * How many prompts an estimate actually builds.
     *
     * Building one is several queries, and a five-hundred-page run would mean
     * several thousand of them for an answer that would barely move: every page
     * prompt carries the same course structure, and only the page's own place
     * in it differs. So a spread sample is measured and scaled, and the
     * response says how many pages it was measured from.
     */
    private const SAMPLE_PAGES = 24;

    /** Newest-first cap for the cross-course run list. */
    private const LIST_LIMIT = 100;
    private const LIST_LIMIT_MAX = 500;

    /**
     * Every run this account may see, newest first.
     *
     * The per-course panel answers "what is happening to this course". This
     * answers the other question - "is anything of mine still running" - which
     * is the one somebody has after closing the laptop on an overnight batch,
     * and which an administrator has about the whole installation. Each row
     * carries the course name and the owner, so the list reads without a
     * request per run.
     *
     * @return array<string,mixed>
     */
    public static function all(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $owner = Access::listingOwner($me, $request->query('owner'));

        $limit = max(1, min(self::LIST_LIMIT_MAX, $request->queryInt('limit', self::LIST_LIMIT)));

        $where = $owner === null ? '' : 'WHERE b.username = ?';
        $args = $owner === null ? [] : [$owner];

        // The limit is inlined rather than bound. Db::run() binds everything as
        // TEXT and SQLite refuses a string in LIMIT; the value is clamped to a
        // pair of class constants above, so nothing from the request reaches
        // the statement text.
        $rows = Db::rows(
            "SELECT b.*, p.name AS project_name
               FROM batch_jobs b LEFT JOIN projects p ON p.id = b.project_id
               {$where}
              ORDER BY b.created_at DESC, b.id DESC
              LIMIT " . $limit,
            $args
        );

        $runs = [];
        foreach ($rows as $row) {
            // summary() counts the items of one run, so this is one extra query
            // per row - which is why the list is capped rather than complete.
            $runs[] = Runs::summary($row) + [
                'owner' => (string)$row['username'],
                'project_name' => (string)($row['project_name'] ?? ''),
            ];
        }

        return ['runs' => $runs, 'cron' => RunManager::cronStatus()];
    }

    /** Every run of one course, plus what this course can currently do. */
    public static function index(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $projectId = $request->id('id');
        $project = Access::project($me, $projectId);
        $owner = (string)$project['username'];

        // capability() may ask a gateway whether it has a batch queue, which is
        // a network round trip. Releasing the session lock first stops that
        // serialising every other request the same user has in flight.
        Runtime::beginLongRequest();

        return [
            'runs' => Runs::forProject($owner, $projectId),
            'capability' => self::capability($owner, $project),
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
    public static function create(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $projectId = $request->id('id');
        $project = Access::project($me, $projectId);
        $owner = (string)$project['username'];

        if ($project['profile_id'] === null) {
            throw HttpException::unprocessable('Assign a profile to this course first.');
        }
        // The run is billed to the course owner's AI account and recorded under
        // their name, whoever pressed the button.
        $profile = Profiles::data($owner, (int)$project['profile_id']);

        $pageIds = self::selection($request, $projectId);
        $mode = $request->enum('mode', [Runs::MODE_LIVE, Runs::MODE_BATCH, ''], '');

        Runtime::beginLongRequest();
        $run = RunManager::start($owner, $profile, $project, $pageIds, $mode);

        return self::state($owner, $projectId, $run);
    }

    /**
     * What a run would cost, without starting it.
     *
     * Batch generation is sold on saving money, and the moment that claim has
     * to survive contact with a person is the moment they are about to queue
     * five hundred pages. Nobody should have to find out the size of what they
     * bought from the invoice, so this builds the prompts the run would build,
     * measures them, and says how many tokens are about to go over the wire and
     * which way they would travel.
     *
     * It stops short of a price on purpose. CourseForge never learns what an
     * account pays - rates differ per model, per provider, per contract, and a
     * confidently wrong number in currency is worse than an honest token count.
     *
     * The body is the one create() takes, so the estimate and the run are
     * always about the same set of pages.
     *
     * @return array<string,mixed>
     */
    public static function estimate(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $projectId = $request->id('id');
        $project = Access::project($me, $projectId);
        $owner = (string)$project['username'];

        if ($project['profile_id'] === null) {
            throw HttpException::unprocessable('Assign a profile to this course first.');
        }
        $profile = Profiles::data($owner, (int)$project['profile_id']);

        $pageIds = self::selection($request, $projectId);
        $config = Completion::modelConfig($profile, 'page');
        $provider = Providers::fromProfile($profile, $config['ai_id']);

        $batched = ModelId::isBatch($config['model']);
        $asked = $request->enum('mode', [Runs::MODE_LIVE, Runs::MODE_BATCH, ''], '');
        $mode = $asked !== '' ? $asked : ($batched ? Runs::MODE_BATCH : Runs::MODE_LIVE);

        // Two dozen prompts is two dozen small queries, and supportsBatch() can
        // be a network round trip. Neither should be holding the session lock.
        Runtime::beginLongRequest();

        // capability() only probes for a queue once the user has opted in with
        // `:batch`, because it runs on every poll of the Content tab. This
        // handler runs when somebody presses a button to ask exactly that
        // question, so here the round trip is worth paying for.
        $available = $provider instanceof BatchCapable && $provider->supportsBatch();

        $sample = self::sample($profile, $project, $pageIds);
        $pages = count($pageIds);

        $inputPerPage = (int)ceil($sample['input_chars'] / $sample['sampled'] / self::CHARS_PER_TOKEN);
        $outputPerPage = self::wordsToTokens($sample['output_words'] / $sample['sampled']);
        $ceilingPerPage = self::wordsToTokens($sample['ceiling_words'] / $sample['sampled']);

        // A model's own output limit is a hard ceiling, whatever the course
        // asked for in words.
        if ($config['max_tokens'] > 0) {
            $outputPerPage = min($outputPerPage, $config['max_tokens']);
            $ceilingPerPage = min($ceilingPerPage, $config['max_tokens']);
        }

        return ['estimate' => [
            'pages' => $pages,
            'sampled' => $sample['sampled'],
            'mode' => $mode,
            'batched' => $batched,
            'batch_available' => $available,
            'batch_limit' => $provider instanceof BatchCapable ? $provider->batchLimits()->maxRequests : 0,
            'background_available' => RunManager::cronConfigured(),
            'provider' => $provider->kind(),
            'label' => $provider->label(),
            'model' => ModelId::base($config['model']),
            'model_slot' => $config['model'],
            'tokens' => [
                'input_per_page' => $inputPerPage,
                'output_per_page' => $outputPerPage,
                'input' => $inputPerPage * $pages,
                'output' => $outputPerPage * $pages,
                'total' => ($inputPerPage + $outputPerPage) * $pages,
                'output_ceiling' => $ceilingPerPage * $pages,
                'chars_per_token' => self::CHARS_PER_TOKEN,
            ],
            // No price, and the reason for it, in words the screen can print.
            'price' => null,
            'basis' => self::basis($sample, $pages, $config['max_tokens']),
            'warnings' => self::warnings($mode, $available, $provider, $pages),
        ]];
    }

    /** Asks where a run stands, and writes home anything finished. */
    public static function poll(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $projectId = $request->id('id');
        $owner = (string)Access::project($me, $projectId)['username'];
        Runtime::beginLongRequest();

        $runId = $request->intOrNull('run_id');
        $run = null;
        if ($runId !== null) {
            self::runOfProject($owner, $projectId, $runId);
            $run = RunManager::poll($owner, $runId);
        } else {
            RunManager::pollAll($owner);
        }

        return self::state($owner, $projectId, $run);
    }

    /** Stops a run and releases its pages. */
    public static function cancel(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $projectId = $request->id('id');
        $owner = (string)Access::project($me, $projectId)['username'];
        Runtime::beginLongRequest();

        $runId = $request->requiredId('run_id', 'Run id');
        self::runOfProject($owner, $projectId, $runId);
        $run = RunManager::cancel($owner, $runId);

        return self::state($owner, $projectId, $run);
    }

    /** Forgets a finished run. The pages it wrote are untouched. */
    public static function delete(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $projectId = $request->id('id');
        $owner = (string)Access::project($me, $projectId)['username'];

        $runId = $request->requiredId('run_id', 'Run id');
        $run = self::runOfProject($owner, $projectId, $runId);
        if (!Runs::isTerminal((string)$run['status'])) {
            throw HttpException::unprocessable('Stop this run before removing it.');
        }
        Runs::delete($owner, $runId);

        return ['runs' => Runs::forProject($owner, $projectId)];
    }

    /* ------------------------------------------------------------ internals */

    /** @return array<string,mixed> */
    private static function state(string $owner, int $projectId, ?array $run): array
    {
        return [
            'run' => $run,
            'runs' => Runs::forProject($owner, $projectId),
            'project' => Projects::tree($owner, $projectId),
            'cron' => RunManager::cronStatus(),
        ];
    }

    /**
     * The run, having checked that it is one of this course's.
     *
     * `Runs::require()` only asks whether the owner matches. A run id belonging
     * to another of the same account's courses would satisfy it and then be
     * polled, stopped or deleted through a route that names a course it has
     * nothing to do with - so the course is checked here as well, and a
     * mismatch is reported as missing rather than as a mistake.
     *
     * @return array<string,mixed>
     */
    private static function runOfProject(string $owner, int $projectId, int $runId): array
    {
        $run = Runs::require($owner, $runId);
        if ((int)$run['project_id'] !== $projectId) {
            throw HttpException::notFound('Run not found.');
        }
        return $run;
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
    private static function capability(string $owner, array $project): array
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
            $profile = Profiles::data($owner, (int)$project['profile_id']);
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

    /* -------------------------------------------------------------- estimate */

    /**
     * Measures the prompts of a spread sample of the selected pages.
     *
     * The prompts come from PageGenerator::plan(), which is the same call the
     * live path and the batch runner make - so what is measured is the text
     * that would actually be sent, not an approximation of it.
     *
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $project
     * @param array<int,int> $pageIds
     * @return array{sampled:int,input_chars:int,output_words:int,ceiling_words:int,bounded:bool}
     */
    private static function sample(array $profile, array $project, array $pageIds): array
    {
        $projectId = (int)$project['id'];
        $total = count($pageIds);
        $step = (int)ceil($total / self::SAMPLE_PAGES);

        // The length parameters resolve course → chapter → page, exactly as the
        // generator resolves them, so a chapter told to write long pages is not
        // averaged away by the course default.
        $rows = [];
        foreach (Pages::ordered($projectId) as $row) {
            $rows[(int)$row['id']] = $row;
        }
        $courseSettings = Projects::settings($project);

        $sampled = 0;
        $chars = 0;
        $words = 0;
        $ceiling = 0;
        $bounded = true;

        for ($i = 0; $i < $total; $i += $step) {
            $pageId = $pageIds[$i];
            $plan = PageGenerator::plan($profile, $project, $pageId);
            $chars += mb_strlen($plan['system']) + mb_strlen($plan['user']);

            $page = $rows[$pageId] ?? [];
            $params = Details::resolve(
                $courseSettings,
                Details::decode((string)($page['chapter_settings'] ?? '{}')),
                Pages::settings($page),
            )['params'];

            $min = max(0, (int)($params['min_length'] ?? 0));
            $max = max(0, (int)($params['max_length'] ?? 0));

            if ($min === 0 && $max === 0) {
                // Both bounds off means "leave the length to the model", so
                // there is nothing here to estimate an answer from.
                $bounded = false;
            }
            // The typical page sits between the two bounds; the ceiling is what
            // the model has been told not to exceed.
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
     * How the numbers were arrived at, in sentences the screen can print.
     *
     * An estimate nobody can check is a guess with a decimal point on it, so
     * every assumption that went into it is stated where the numbers are shown.
     *
     * @param array{sampled:int,input_chars:int,output_words:int,ceiling_words:int,bounded:bool} $sample
     * @return string[]
     */
    private static function basis(array $sample, int $pages, int $maxTokens): array
    {
        $lines = [
            'Tokens are estimated at ' . self::CHARS_PER_TOKEN
                . ' characters each - the rule of thumb for English prose. No provider will count a prompt for '
                . 'free before it is sent, so this is an estimate, not a quotation.',
        ];

        $lines[] = $sample['sampled'] >= $pages
            ? 'The input figure is the real length of all ' . $pages . ' prompt(s) this run would send.'
            : 'The input figure comes from ' . $sample['sampled'] . ' of the ' . $pages
                . ' prompts, spread across the selection, and scaled up. Every page prompt carries the same '
                . 'course structure, so they differ little in length.';

        $lines[] = $sample['bounded']
            ? 'The output figure comes from the length settings of the pages themselves, counted at '
                . self::CHARS_PER_WORD . ' characters a word.'
            : 'Some of these pages have no length bounds set, so their share of the output figure is a floor '
                . 'rather than an estimate - the model decides how long they get.';

        if ($maxTokens > 0) {
            $lines[] = 'The model is capped at ' . number_format($maxTokens)
                . ' output tokens per page, and no page is counted above that.';
        }

        $lines[] = 'CourseForge does not know what your account pays. Rates differ by model, by provider and by '
            . 'contract, so the price is yours to work out from these counts.';

        return $lines;
    }

    /**
     * What would go wrong, or cost more than it needs to.
     *
     * Every line is about `$mode`, which is the mode the run would actually
     * start in - the model's own suffix decides it only when the request has
     * not said. Reading the model id instead would have this advising a caller
     * to do what their `mode` has already done.
     *
     * @return string[]
     */
    private static function warnings(
        string $mode,
        bool $available,
        Provider $provider,
        int $pages,
    ): array {
        $warnings = [];

        if ($mode === Runs::MODE_BATCH && !$available) {
            $warnings[] = $provider->label() . ' has no batch queue to submit to, so this cannot run as a batch.';
        }
        if ($mode === Runs::MODE_BATCH && $provider instanceof BatchCapable) {
            $maxRequests = $provider->batchLimits()->maxRequests;
            if ($pages > $maxRequests) {
                $warnings[] = 'That is more pages than ' . $provider->label() . ' accepts in one batch ('
                    . number_format($maxRequests) . '). Split the selection.';
            }
        }
        if ($mode === Runs::MODE_LIVE && !RunManager::cronConfigured()) {
            $warnings[] = 'A background run needs the scheduler, which is not configured on this installation.';
        }
        if ($mode !== Runs::MODE_BATCH && $available) {
            // The whole reason this endpoint exists: the cheaper door is open
            // and the person is walking past it.
            $warnings[] = $provider->label() . ' does have a batch queue, and providers that run one commonly '
                . 'bill it at half the live rate. Adding ":batch" to the page model would send this work through it.';
        }

        return $warnings;
    }

    /**
     * Which pages to include.
     *
     * The list is deduplicated, because `RunManager::start()` deduplicates the
     * one it is handed and the estimate has to be about the set the run would
     * actually write - a page named three times is one page of work, not three.
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
            $ids = array_values(array_unique($ids));
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
