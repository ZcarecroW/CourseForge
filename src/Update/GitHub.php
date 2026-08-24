<?php
declare(strict_types=1);

namespace CourseForge\Update;

use CourseForge\Support\Config;
use CourseForge\Support\Meta;
use CourseForge\Support\Runtime;
use CourseForge\Support\Settings;
use RuntimeException;
use Throwable;

/**
 * The one place that talks to GitHub.
 *
 * There is no SDK here and there is not going to be one: the whole of what
 * CourseForge needs from GitHub is two read-only endpoints, and pulling in a
 * dependency tree to call them would be the largest thing in the project.
 *
 * It does not use Support\Http, which is the wrapper everything else uses, and
 * the reason is narrow but real: rate limiting. When GitHub refuses a call it
 * answers 403 with a body that says very little and headers that say exactly
 * when the limit resets, and Support\Http returns a result object with no
 * headers on it. Being told "come back at 14:20" is the difference between an
 * administrator understanding what happened and an administrator retrying every
 * thirty seconds, so the cURL call is made here with a header collector
 * attached. The style otherwise follows Support\Http deliberately, so the two
 * read alike.
 *
 * The answer is cached in the meta table. An admin screen that asks "is there
 * an update" on every load would burn through the sixty anonymous calls an hour
 * GitHub allows per IP address in about an hour of ordinary use, and there is
 * no version of this feature where that is worth it - a release that appeared
 * four minutes ago can wait. `force` exists for the button that says "check
 * now", which is the one moment the user is entitled to a real answer.
 */
final class GitHub
{
    private const API = 'https://api.github.com';

    /** How long a cached answer stands before a check goes back to the network. */
    private const CACHE_SECONDS = 3600;

    /**
     * How long a failed check waits before trying again.
     *
     * Shorter than the good-answer cache, because a failure is usually a
     * network blink and waiting an hour to notice it has cleared is annoying.
     * Not much shorter: a repository that is misconfigured rather than
     * unreachable fails identically and for ever, and the scheduler asks once a
     * minute, so a backoff measured in minutes is the difference between four
     * calls an hour and sixty - which is the entire anonymous allowance.
     */
    private const RETRY_SECONDS = 900;

    /** Nothing here downloads a release, so this is a metadata call and short. */
    private const TIMEOUT = 30;

    /** How many releases the pre-release channel looks at. */
    private const PAGE_SIZE = 20;

    /** When GitHub last answered. Only a successful check moves it. */
    public const META_CHECKED = 'updates.last_check_at';

    /** When a check was last attempted, successfully or not. Drives the backoff. */
    public const META_ATTEMPT = 'updates.last_attempt_at';

    public const META_LATEST = 'updates.latest';
    public const META_ERROR = 'updates.last_error';

    /**
     * The newest release on the configured channel.
     *
     * Never throws. A check that fails is an answer in itself - the caller is
     * usually rendering a screen, and an unreachable GitHub is not a reason to
     * refuse to draw it - so the failure comes back as `error` and the last
     * known good release comes back alongside it.
     *
     * @return array{release:?Release,checked_at:int,attempted_at:int,cached:bool,error:string,repository:string,channel:string}
     */
    public static function check(bool $force = false): array
    {
        $repository = self::repository();
        $channel = self::channel();
        $checkedAt = Meta::int(self::META_CHECKED);
        $attemptedAt = Meta::int(self::META_ATTEMPT);
        $lastError = Meta::get(self::META_ERROR);

        $answer = [
            'release' => self::cached(),
            'checked_at' => $checkedAt,
            'attempted_at' => $attemptedAt,
            'cached' => true,
            'error' => $lastError,
            'repository' => $repository,
            'channel' => $channel,
        ];

        if (!self::enabled()) {
            return array_merge($answer, ['error' => 'Updates are switched off in Settings.']);
        }
        if ($repository === '') {
            return array_merge($answer, ['error' => 'No GitHub repository is configured.']);
        }
        if (!$force) {
            if ($checkedAt > 0 && time() - $checkedAt < self::CACHE_SECONDS) {
                return $answer;
            }
            // The scheduler calls this every minute. Without the second window a
            // check that always fails - a repository name with a typo in it -
            // would call GitHub once a minute for ever and spend the whole
            // anonymous rate limit on finding out the same thing again.
            if ($lastError !== '' && $attemptedAt > 0 && time() - $attemptedAt < self::RETRY_SECONDS) {
                return $answer;
            }
        }

        Meta::set(self::META_ATTEMPT, (string)time());

        try {
            $release = self::fetch($repository, $channel);
            // Stored and returned say the same thing. A repository with nothing
            // published is an answer GitHub gave rather than a failure, but it
            // still needs explaining on the screen - and if only the return
            // value carried it, the next cached poll would show no release and
            // no reason for it.
            $note = $release === null ? 'That repository has no published release yet.' : '';

            Meta::set(self::META_CHECKED, (string)time());
            Meta::set(self::META_ERROR, $note);
            Meta::set(self::META_LATEST, $release === null ? '' : (string)json_encode($release->toArray()));

            return array_merge($answer, [
                'release' => $release,
                'checked_at' => time(),
                'attempted_at' => time(),
                'cached' => false,
                'error' => $note,
            ]);
        } catch (Throwable $e) {
            // `checked_at` deliberately stays where it was. It means "when
            // GitHub last answered", and a screen showing a week-old release
            // beside today's date would be telling the administrator something
            // untrue. The attempt timestamp above is what the backoff runs on,
            // and the error text is what explains the stale date.
            Runtime::log('update.check', $e);
            Meta::set(self::META_ERROR, $e->getMessage());

            return array_merge($answer, [
                'error' => $e->getMessage(),
                'attempted_at' => time(),
                'cached' => true,
            ]);
        }
    }

    /** The last release GitHub told us about, from the cache, without any network call. */
    public static function cached(): ?Release
    {
        $raw = Meta::get(self::META_LATEST);
        if (trim($raw) === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? Release::fromArray($data) : null;
    }

    /* -------------------------------------------------------- configuration */

    public static function enabled(): bool
    {
        return (bool)filter_var(self::setting('updates.enabled'), FILTER_VALIDATE_BOOLEAN);
    }

    public static function repository(): string
    {
        return trim((string)self::setting('updates.repository'));
    }

    public static function channel(): string
    {
        $channel = (string)self::setting('updates.channel');

        return $channel === 'prerelease' ? 'prerelease' : 'stable';
    }

    /**
     * The configured token.
     *
     * The single place it is read from. Everything that needs to authenticate -
     * this class and Archive, when it downloads - takes it from here, so there
     * is exactly one line to look at when asking where the secret goes.
     */
    public static function token(): string
    {
        return trim((string)self::setting('updates.github_token'));
    }

    /**
     * A setting, with the catalogue's declaration as the fallback.
     *
     * `config/defaults.json` ships application defaults but carries no updates
     * block - the update settings are declared only in Support\Settings, which
     * is the catalogue every screen and every API call already reads. Going
     * through it here means an unconfigured installation gets the same default
     * the Settings screen would show it, rather than a literal repeated at each
     * call site that can drift away from the catalogue.
     */
    private static function setting(string $key): mixed
    {
        $field = Settings::field($key);

        return Config::get($key, $field['default'] ?? null);
    }

    /* --------------------------------------------------------------- network */

    /**
     * Asks GitHub for the newest release on a channel.
     *
     * The stable channel uses `/releases/latest`, which is GitHub's own idea of
     * latest and already excludes drafts and pre-releases. The pre-release
     * channel cannot use it, because that endpoint would hide exactly the
     * releases the channel exists for, so it reads a page of the release list
     * and picks the highest version itself. Highest version, not first in the
     * list: GitHub orders by creation date, and a 4.0.1 patch published after
     * 4.1.0-rc1 would otherwise look like the newer of the two.
     *
     * @throws RuntimeException when GitHub cannot be reached or refuses
     */
    private static function fetch(string $repository, string $channel): ?Release
    {
        if ($channel !== 'prerelease') {
            $body = self::call('/repos/' . $repository . '/releases/latest');

            return is_array($body) ? Release::fromApi($body) : null;
        }

        $body = self::call('/repos/' . $repository . '/releases?per_page=' . self::PAGE_SIZE);
        if (!is_array($body)) {
            return null;
        }

        $best = null;
        foreach ($body as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $release = Release::fromApi($entry);
            if ($release === null) {
                continue;
            }
            if ($best === null || Release::isNewer($release->version, $best->version)) {
                $best = $release;
            }
        }

        return $best;
    }

    /**
     * One GET against the GitHub API.
     *
     * @return array<mixed>|null the decoded body, or null for 404
     * @throws RuntimeException for anything else that is not a 2xx
     */
    private static function call(string $path): ?array
    {
        $token = self::token();
        $headers = [
            // GitHub answers 403 to a request without a User-Agent, with a body
            // that does not mention the User-Agent. It is not optional.
            'User-Agent: CourseForge/' . CF_VERSION . ' (+PHP ' . PHP_VERSION . ')',
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        [$status, $responseHeaders, $body, $error, $errno] = self::curl(self::API . $path, $headers);

        if ($status === 0) {
            throw new RuntimeException('GitHub could not be reached: ' . ($error !== '' ? $error : 'no response') . '.');
        }
        if ($errno !== 0) {
            throw new RuntimeException('The answer from GitHub did not arrive in full: ' . $error . '.');
        }
        if ($status === 404) {
            throw new RuntimeException(
                $token === ''
                    ? 'GitHub does not know that repository, or it is private and no token is configured.'
                    : 'GitHub does not know that repository, or the configured token cannot see it.'
            );
        }
        if ($status === 401) {
            throw new RuntimeException('GitHub rejected the configured token. Replace it in Settings.');
        }
        if ($status === 403 || $status === 429) {
            throw new RuntimeException(self::rateLimitMessage($status, $responseHeaders, $body, $token !== ''));
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('GitHub answered ' . $status . ': ' . self::snippet($body));
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('GitHub answered ' . $status . ' with something that is not JSON.');
        }

        return $decoded;
    }

    /**
     * Turns a refusal into a sentence with a time in it.
     *
     * A 403 from GitHub is often the rate limit, and the only useful thing to
     * say about a rate limit is when it lifts. Three shapes are handled: the
     * hourly limit (`x-ratelimit-remaining: 0` plus a reset timestamp), the
     * secondary limit that guards bursts (`retry-after`, in seconds), and a
     * plain 403 that is really a permissions problem.
     *
     * Only the remaining count decides between the first and the third. GitHub
     * sends `x-ratelimit-reset` on every response it makes, so a future reset
     * timestamp says nothing at all about whether the limit was reached - and
     * treating it as evidence turned "Resource not accessible by personal
     * access token" into an invitation to wait half an hour for a limit that
     * had 4,987 calls left on it.
     *
     * @param array<string,string> $headers
     */
    private static function rateLimitMessage(int $status, array $headers, string $body, bool $authenticated): string
    {
        $retryAfter = (int)($headers['retry-after'] ?? 0);
        if ($retryAfter > 0) {
            return 'GitHub is asking for a pause before the next call (it suggests '
                . self::duration($retryAfter) . '). This is its burst limit, not the hourly one.';
        }

        $remaining = $headers['x-ratelimit-remaining'] ?? null;
        $reset = (int)($headers['x-ratelimit-reset'] ?? 0);

        if ($remaining === '0') {
            $wait = max(0, $reset - time());
            $when = $reset > 0
                ? gmdate('H:i', $reset) . ' UTC' . ($wait > 0 ? ', in ' . self::duration($wait) : '')
                : 'shortly';

            return 'GitHub\'s rate limit is used up until ' . $when . '. '
                . ($authenticated
                    ? 'The configured token allows 5000 calls an hour.'
                    : 'Anonymous callers get 60 calls an hour per IP address; a GitHub token in Settings raises that to 5000.');
        }

        return 'GitHub refused the request (' . $status . '): ' . self::snippet($body);
    }

    private static function duration(int $seconds): string
    {
        if ($seconds < 90) {
            return $seconds . ' second' . ($seconds === 1 ? '' : 's');
        }
        $minutes = (int)ceil($seconds / 60);

        return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
    }

    private static function snippet(string $body): string
    {
        $decoded = json_decode($body, true);
        $message = is_array($decoded) ? (string)($decoded['message'] ?? '') : '';
        $text = trim($message !== '' ? $message : $body);

        return $text === '' ? 'it said nothing at all.' : mb_substr($text, 0, 300);
    }

    /**
     * The cURL call, with a header collector attached.
     *
     * @param array<int,string> $headers
     * @return array{0:int,1:array<string,string>,2:string,3:string,4:int}
     */
    private static function curl(string $url, array $headers): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is not enabled on this server.');
        }

        $collected = [];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$collected): int {
                $length = strlen($line);
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    // Lower-cased keys: HTTP header names are case insensitive
                    // and GitHub has changed their casing before now.
                    $collected[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $length;
            },
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch); // PHP 8 frees the handle; curl_close() is deprecated in 8.5

        return [$status, $collected, is_string($response) ? $response : '', $error, $errno];
    }
}
