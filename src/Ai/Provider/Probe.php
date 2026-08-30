<?php
declare(strict_types=1);

namespace CourseForge\Ai\Provider;

use CourseForge\Support\Http;
use CourseForge\Support\HttpResult;
use CourseForge\Support\Text;
use Throwable;

/**
 * Asks an arbitrary OpenAI-compatible endpoint whether it has a batch queue,
 * without spending a cent and without submitting any work.
 *
 * Three GETs and, when the answer is still ambiguous, one POST of an empty
 * object that every real queue rejects with a field-level validation error.
 * Nothing here creates anything, and nothing here is a chat request - probing
 * with a POST to /chat/completions would be worse than useless against a local
 * server, where naming a model that is on disk but not resident makes LM Studio
 * or llama-server block for minutes while gigabytes load.
 *
 * The single most valuable thing this catches is a queue with no upload lane.
 * Gemini's OpenAI compatibility layer answers /batches but genuinely 404s
 * /files, so the file-JSONL driver can never work there however healthy the
 * queue looks; without probe 3 that endpoint would be badged as batch-capable
 * and fail at the first upload, hours into a course. The second most valuable
 * is the distinction between "no queue" and "your key may not use the queue" -
 * Gemini's queue is paid-tier only, and caching a 403 as "unsupported" would
 * disable batching permanently for an account that only needed an upgrade.
 *
 * The answer belongs to the account, not to the preset: the same base URL
 * behaves differently per key, so the result is stored on the account row and
 * re-taken when the key or the URL changes, when it is a month old, or when a
 * real submission comes back 404 and proves it wrong.
 *
 * Sizes are deliberately not probed. No free call reveals how large a file a
 * queue will swallow, and guessing from a successful small upload would be
 * guessing; unknown endpoints get BatchLimits::conservative() instead.
 */
final class Probe
{
    /**
     * Bumped whenever the logic below changes, so a stored result taken by an
     * older version is re-taken rather than trusted.
     *
     * 3 retires every row version 2 wrote, because version 2 could record a
     * definitive `no` on a batch list whose download died mid-body. Nothing
     * distinguishes such a row from an honest one after the fact, so they all
     * go rather than leaving one installation quietly unable to batch.
     *
     * 4 retires every row version 3 wrote, because version 3 asked the upload
     * lane about `purpose=batch` on every gateway. A gateway whose purpose is
     * spelled differently - Together's is `batch-api` - could answer that with
     * a refusal that says nothing about whether the lane works, so the stored
     * verdict was about a question the run never asks.
     */
    public const VERSION = 4;

    /** The queue is there and this key may use it. */
    public const YES = 'yes';
    /** There is no OpenAI-shaped queue here. */
    public const NO = 'no';
    /** A queue, but no /files route - the file-JSONL driver cannot work. */
    public const NO_UPLOAD_LANE = 'no_upload_lane';
    /** The route exists and this key is not allowed to use it. */
    public const FORBIDDEN = 'forbidden';
    /** The endpoint or the key answered in a way that decides nothing. */
    public const UNKNOWN = 'unknown';

    /** Every verdict this class produces, for reading a stored row back. */
    public const RESULTS = [self::YES, self::NO, self::NO_UPLOAD_LANE, self::FORBIDDEN, self::UNKNOWN];

    /** A stored result older than this is due to be taken again. */
    public const MAX_AGE_DAYS = 30;

    /** The steps a stored row records a status code for, in the order they run. */
    private const STEPS = ['models', 'batches', 'files', 'create'];

    /** @param array<string,string> $headers auth headers, never logged or returned */
    public function __construct(
        private readonly string $baseUrl,
        private readonly array $headers,
        private readonly PresetSpec $spec,
        private readonly int $timeout = 20,
    ) {
    }

    /**
     * Runs the probe and returns what goes on the account row.
     *
     * The `for` field comes back empty. A probe knows the address it called and
     * the headers it sent, but stamping the credentials onto the row is the job
     * of whatever stores it - see fingerprint() - and leaving the field blank
     * here is what keeps a result safe to hand straight to a browser.
     *
     * @return array{at:int,result:string,codes:array<string,int>,window:string,
     *     probe_ver:int,reason:string,for:string}
     */
    public function run(): array
    {
        $codes = array_fill_keys(self::STEPS, 0);

        // Probe 1 - auth and shape sanity. A failure here says nothing about
        // the queue, so the remaining probes are not made: their answers would
        // all be the same 401 and would read as "no queue".
        $models = $this->get($this->spec->modelsPath);
        $codes['models'] = $models->status;
        if (!$models->ok()) {
            return $this->outcome(self::UNKNOWN, $codes, 'The endpoint did not answer '
                . $this->url($this->spec->modelsPath) . ' (HTTP ' . $models->status . '): '
                . ($models->status === 0 ? $models->error : $models->message(300)));
        }

        // Probe 2 - is there a queue route at all?
        $batches = $this->get($this->spec->batchesPath . '?limit=1');
        $codes['batches'] = $batches->status;

        // A 2xx that did not arrive whole is not an answer about the queue. The
        // branches below read the body to tell a batch list from anything else,
        // and a connection that dies after the response line leaves the status
        // at 200 with nothing behind it - which the "not a list of batches" arm
        // would then record as a definitive no, reached on a body nobody saw.
        // Statuses outside 2xx describe themselves and are left to speak even
        // when their body came up short.
        if ($batches->truncated() && $batches->status >= 200 && $batches->status < 300) {
            return $this->outcome(self::UNKNOWN, $codes, 'The batch route answered HTTP '
                . $batches->status . ' and then the connection dropped before the body arrived ('
                . $batches->error . '), so this decides nothing about the queue.');
        }

        $ambiguous = false;
        if ($batches->status === 200 && is_array($batches->data) && array_key_exists('data', $batches->data)) {
            $ambiguous = false;
        } elseif ($batches->status === 400 && is_array($batches->data)) {
            // A route that exists and rejected the query string. Gemini's
            // compatibility layer answers exactly this way, with an
            // INVALID_ARGUMENT envelope on a route that is genuinely there.
            $ambiguous = true;
        } elseif ($batches->status === 401 || $batches->status === 403) {
            return $this->outcome(self::FORBIDDEN, $codes, 'The endpoint has a batch queue, but this key '
                . 'may not use it (HTTP ' . $batches->status . '). Some providers sell the queue only on a '
                . 'paid tier. ' . $batches->message(200));
        } elseif ($batches->status === 404 || $batches->status === 405) {
            // The Content-Type matters more than the status: a JSON 404 is a
            // framework saying "no such route on this API", while an HTML 404
            // is a web server saying "no such page", which usually means the
            // base URL itself is pointing somewhere that is not an API.
            return $this->outcome(self::NO, $codes, self::looksLikeHtml($batches)
                ? 'The endpoint answered ' . $batches->status . ' with a web page rather than JSON, so there '
                    . 'is no queue here - and the base URL may be wrong.'
                : 'This endpoint has no batch queue (HTTP ' . $batches->status . ' on '
                    . $this->url($this->spec->batchesPath) . ').');
        } elseif ($batches->status === 200) {
            return $this->outcome(self::NO, $codes, 'The batch route answered 200 but not with a list of '
                . 'batches, so it is not an OpenAI-shaped queue.');
        } else {
            return $this->outcome(self::UNKNOWN, $codes, 'The batch route answered HTTP '
                . $batches->status . ', which decides nothing: ' . $batches->message(200));
        }

        // Probe 3 - is there an upload lane? A queue without one cannot be fed
        // by the file-JSONL driver, whatever the queue itself reports.
        // The purpose the submission will actually use, not the word OpenAI
        // happens to use for it: Together's is 'batch-api', and a probe that
        // asks a different question from the one the run asks is not a probe.
        $files = $this->get($this->spec->filesPath . '?purpose=' . rawurlencode($this->spec->filePurpose) . '&limit=1');
        $codes['files'] = $files->status;

        if ($files->status === 404 || $files->status === 405) {
            return $this->outcome(self::NO_UPLOAD_LANE, $codes, 'This provider has a batch queue but no '
                . 'compatible file upload (HTTP ' . $files->status . ' on ' . $this->url($this->spec->filesPath)
                . '), so CourseForge cannot submit to it.');
        }
        if ($files->status === 401 || $files->status === 403) {
            return $this->outcome(self::FORBIDDEN, $codes, 'The upload route exists but this key may not use '
                . 'it (HTTP ' . $files->status . '). ' . $files->message(200));
        }
        if ($files->status === 0) {
            return $this->outcome(self::UNKNOWN, $codes, 'The upload route could not be reached: ' . $files->error);
        }

        // Probe 4 - the confirmation. An empty object is not a well-formed
        // submission, so a real queue answers 400 naming the field it wanted
        // and creates nothing. Anything that is not a 4xx is not an answer to
        // this question and is abandoned rather than interpreted.
        $create = $this->post($this->spec->batchesPath);
        $codes['create'] = $create->status;

        // A 4xx whose body was cut off is not the validation error this probe
        // came for: the field it named and the windows it allows are both read
        // out of that body, and half of one would be guessed at rather than
        // read.
        $definitive = $create->status >= 400 && $create->status < 500 && !$create->truncated();
        $message = $definitive ? $create->message(300) : '';
        $window = $definitive ? self::windowIn($message, $this->spec->window) : $this->spec->window;

        if ($ambiguous && !$definitive) {
            return $this->outcome(self::UNKNOWN, $codes, 'The batch route exists but would not confirm '
                . 'itself: creating an empty batch answered HTTP ' . $create->status . ' instead of a '
                . 'validation error. Nothing here settles it either way, so only a real submission can.',
                $window);
        }

        $named = $definitive && self::namesABatchField($message);
        return $this->outcome(
            self::YES,
            $codes,
            $named
                ? 'The batch queue is present and answered a create-validation probe: ' . $message
                : 'The batch queue is present.',
            $window,
        );
    }

    /* ----------------------------------------------------------- stored form */

    /**
     * Which endpoint and which key a result was taken against.
     *
     * A probe answers a question about a pair, never about a URL alone - the
     * same gateway says yes to a paid key and no to a free one - so the pair is
     * stamped onto the row and checked every time it is read back. That is what
     * turns "the user edited the base URL or the key" into a re-probe without
     * anything having to notice the edit: the row stops belonging to the
     * account and is dropped where accounts are shaped.
     *
     * The fingerprint sits in the same JSON blob as the key it fingerprints, so
     * it discloses nothing to a reader who can already read the row. It must
     * still never be sent to a browser, which is the one place the key itself is
     * redacted, and an offline-checkable hash of a live credential would undo
     * that redaction.
     */
    public static function fingerprint(string $baseUrl, string $apiKey): string
    {
        // Case is left alone. Folding it would let two accounts whose paths
        // differ only in case share one result, and the cost of not folding it
        // is at worst one re-probe after somebody retypes their own URL.
        return Text::hash(rtrim(trim($baseUrl), '/'), trim($apiKey));
    }

    /**
     * A stored value read back as a probe result, or null when it is not one.
     *
     * Pass the fingerprint of the account holding it and a row taken against
     * other credentials is refused, as is a row carrying no fingerprint at all.
     * That second rule is the load-bearing one: the only writer that can stamp
     * a fingerprint is server-side code holding the plaintext key, so a
     * `batch_probe` a browser invented and posted back cannot survive this.
     *
     * @return array{at:int,result:string,codes:array<string,int>,window:string,
     *     probe_ver:int,reason:string,for:string}|null
     */
    public static function stored(mixed $value, string $fingerprint = ''): ?array
    {
        if (!is_array($value) || !is_string($value['result'] ?? null)) {
            return null;
        }
        $result = strtolower(trim($value['result']));
        if (!in_array($result, self::RESULTS, true)) {
            return null;
        }

        $for = is_string($value['for'] ?? null) ? $value['for'] : '';
        if ($fingerprint !== '' && !hash_equals($fingerprint, $for)) {
            return null;
        }

        $codes = [];
        foreach (self::STEPS as $step) {
            $codes[$step] = max(0, (int)(is_array($value['codes'] ?? null) ? ($value['codes'][$step] ?? 0) : 0));
        }
        $reason = trim((string)($value['reason'] ?? ''));

        return [
            'at' => max(0, (int)($value['at'] ?? 0)),
            'result' => $result,
            'codes' => $codes,
            'window' => trim((string)($value['window'] ?? '')) ?: '24h',
            'probe_ver' => max(0, (int)($value['probe_ver'] ?? 0)),
            'reason' => $reason === '' ? '' : Text::snippet($reason, 400),
            'for' => $for,
        ];
    }

    /**
     * Whether a stored result is due to be taken again.
     *
     * Four things ask for a fresh one: nothing readable stored; a row belonging
     * to other credentials; a bumped VERSION, which is how a fix to the logic
     * above reaches every account without touching the database; and age,
     * because a provider can ship a queue at any time.
     *
     * Being due is not the same as being wrong, which is why supported() below
     * still answers from a month-old row. A re-probe is four requests against
     * somebody else's server and belongs on a save or a button; sending every
     * page render to the network to replace an expired verdict is the failure
     * this whole class exists to prevent.
     *
     * @param array<string,mixed>|null $stored
     */
    public static function stale(?array $stored, ?int $now = null, string $fingerprint = ''): bool
    {
        $row = self::stored($stored, $fingerprint);
        if ($row === null || $row['probe_ver'] < self::VERSION) {
            return true;
        }
        return $row['at'] <= 0 || $row['at'] < ($now ?? time()) - self::MAX_AGE_DAYS * 86400;
    }

    /**
     * What a stored result says about batching, or null when it says nothing.
     *
     * Age is deliberately not consulted - stale() explains why. What does void
     * a row is a bumped VERSION, because then the verdict was reached by logic
     * this release has already decided was wrong.
     *
     * `forbidden` deliberately answers false rather than null: the queue is
     * real, so re-probing will not change anything, and the account needs a
     * tier upgrade rather than another round trip.
     *
     * `unknown` is the mirror image and answers null, because that verdict is
     * recorded precisely when nothing was learned - a gateway that timed out, a
     * 502 in front of the queue route, a route that would not confirm itself.
     * Answering false there would be worse than saying nothing twice over: this
     * answer outranks the preset table in supportsBatch(), so one bad minute on
     * somebody else's server switches off a documented queue, and the recovery
     * every such row promises - a real submission settling it - is exactly what
     * the false would refuse to allow.
     *
     * @param array<string,mixed>|null $stored
     */
    public static function supported(?array $stored, string $fingerprint = ''): ?bool
    {
        $row = self::stored($stored, $fingerprint);
        if ($row === null || $row['probe_ver'] < self::VERSION || $row['result'] === self::UNKNOWN) {
            return null;
        }
        return $row['result'] === self::YES;
    }

    /**
     * The reason a stored result gives, for a UI that has to explain a missing
     * badge rather than leave one out silently.
     *
     * @param array<string,mixed>|null $stored
     */
    public static function reason(?array $stored): string
    {
        return is_array($stored) ? trim((string)($stored['reason'] ?? '')) : '';
    }

    /**
     * The self-healing result: a real submission met a 404 or a 405, which
     * outranks anything a probe concluded earlier.
     *
     * The row comes back unstamped, like run()'s, because only the layer that
     * stores an account may say which credentials a result belongs to.
     *
     * The status goes under `batches` for a create call and under `files` for
     * an upload, because those are the two routes a real submission can
     * disprove and telling them apart is the whole difference between "no
     * queue" and "a queue with no upload lane".
     *
     * @return array{at:int,result:string,codes:array<string,int>,window:string,
     *     probe_ver:int,reason:string,for:string}
     */
    public static function disprovedBySubmit(
        string $reason,
        int $status = 404,
        string $step = 'batches',
    ): array {
        $codes = array_fill_keys(self::STEPS, 0);
        $codes[in_array($step, self::STEPS, true) ? $step : 'batches'] = $status;

        return [
            'at' => time(),
            'result' => $step === 'files' ? self::NO_UPLOAD_LANE : self::NO,
            'codes' => $codes,
            'window' => '24h',
            'probe_ver' => self::VERSION,
            'reason' => Text::snippet($reason, 400),
            'for' => '',
        ];
    }

    /* ------------------------------------------------------------ internals */

    /**
     * @param array<string,int> $codes
     * @return array{at:int,result:string,codes:array<string,int>,window:string,
     *     probe_ver:int,reason:string,for:string}
     */
    private function outcome(string $result, array $codes, string $reason, string $window = ''): array
    {
        return [
            'at' => time(),
            'result' => $result,
            'codes' => $codes,
            'window' => $window !== '' ? $window : $this->spec->window,
            'probe_ver' => self::VERSION,
            'reason' => Text::snippet($reason, 400),
            'for' => '',
        ];
    }

    private function get(string $path): HttpResult
    {
        return $this->call('GET', $path, null);
    }

    /** The empty body is the point: it is rejected, and it creates nothing. */
    private function post(string $path): HttpResult
    {
        return $this->call('POST', $path, new \stdClass());
    }

    /**
     * One probe call.
     *
     * A transport failure is turned into a status-0 result rather than an
     * exception: a probe reports what it found, and "the host did not answer"
     * is a finding like any other. Redirects are not followed, because the
     * key travels in a header the probe controls and a redirect is somewhere
     * it was never meant to go.
     */
    private function call(string $method, string $path, mixed $payload): HttpResult
    {
        try {
            return Http::json($method, $this->url($path), $this->headers, $payload, $this->timeout, false);
        } catch (Throwable $e) {
            return new HttpResult(0, '', null, $e->getMessage(), -1);
        }
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Whether the body is a web page rather than an API answer.
     *
     * This stands in for reading Content-Type, which the shared HTTP wrapper
     * does not hand back, and it is the more reliable of the two: a proxy that
     * serves an HTML error page under `Content-Type: application/json` is a
     * real configuration and would fool the header check. A body that failed to
     * decode and starts with a tag is a page.
     */
    private static function looksLikeHtml(HttpResult $res): bool
    {
        if (is_array($res->data)) {
            return false;
        }
        return str_starts_with(ltrim($res->raw), '<');
    }

    /** Whether a create-validation error names a field only a real queue has. */
    private static function namesABatchField(string $message): bool
    {
        $message = strtolower($message);
        return str_contains($message, 'input_file_id') || str_contains($message, 'endpoint');
    }

    /**
     * The completion window a validation error named, if it named one.
     *
     * Groq accepts up to 7d and says so when it rejects a malformed create;
     * OpenAI names 24h. The largest duration in the message is the one worth
     * taking, because that is what an "allowed values" list ends with.
     */
    private static function windowIn(string $message, string $fallback): string
    {
        if (preg_match_all('/\b(\d{1,3})\s*([hd])\b/i', $message, $matches, PREG_SET_ORDER) < 1) {
            return $fallback;
        }
        $best = '';
        $bestHours = 0;
        foreach ($matches as $match) {
            $hours = (int)$match[1] * (strtolower($match[2]) === 'd' ? 24 : 1);
            if ($hours > $bestHours) {
                $bestHours = $hours;
                $best = strtolower($match[1] . $match[2]);
            }
        }
        return $best !== '' ? $best : $fallback;
    }
}
