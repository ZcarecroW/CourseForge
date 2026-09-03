<?php
declare(strict_types=1);

namespace CourseForge\Security;

use CourseForge\Support\Config;
use CourseForge\Support\DataDirectory;
use CourseForge\Support\HttpException;
use CourseForge\Support\Meta;
use CourseForge\Support\PublicUrl;
use CourseForge\Support\Text;
use Throwable;

/**
 * Whether this server actually refuses what CourseForge asks it to refuse.
 *
 * The release ships `.htaccess` files that deny `data/`, `config/` and every
 * file type that holds a secret. That is a promise Apache keeps and nginx,
 * Caddy, IIS and PHP's own server do not - they read no `.htaccess` at all -
 * and the difference is not a warning in a manual: it is `data/app.sqlite`,
 * with every AI key and every BookStack token in plain text, one URL away.
 *
 * So rather than describe the danger, this class tries it. It writes a small
 * file into the data directory and asks the server, over HTTP, for that file,
 * for the database, for the shipped defaults and for the deny file itself.
 * A server that hands any of them back is exposed; one that refuses all of
 * them is not. The answer is kept and re-taken now and then, and until it is
 * "secure" - or an administrator has looked at it and said, in so many words,
 * that they accept the risk - nothing that would store a secret is allowed to.
 */
final class Hardening
{
    public const VERDICT_SECURE = 'secure';
    public const VERDICT_EXPOSED = 'exposed';
    public const VERDICT_UNVERIFIED = 'unverified';
    public const VERDICT_UNKNOWN = 'unknown';

    public const META_CHECK = 'security.check';
    public const META_ACK = 'security.ack';

    /** How old a verdict may be before the scheduler takes it again. */
    public const RECHECK_SECONDS = 6 * 3600;

    /** The file the probe asks the server for. Written into data/, read over HTTP. */
    public const PROBE_FILE = 'security-probe.txt';

    /** How long a confirmation code stays valid once it is on screen. */
    private const CODE_SECONDS = 900;

    /** Whether the gate holds on the command line too - off by default, because tests and cron write no secrets. */
    public static bool $enforceInCli = false;

    /* --------------------------------------------------------------- status */

    /**
     * Everything the Security screen and the shell need.
     *
     * @return array<string,mixed>
     */
    public static function status(): array
    {
        $check = self::lastCheck();
        $ack = self::acknowledgement();
        $verdict = (string)($check['verdict'] ?? self::VERDICT_UNKNOWN);

        return [
            'verdict' => $verdict,
            'checked_at' => (int)($check['at'] ?? 0),
            'stale' => self::due(),
            'reason' => (string)($check['reason'] ?? ''),
            'probes' => is_array($check['probes'] ?? null) ? $check['probes'] : [],
            'server' => is_array($check['server'] ?? null) ? array_merge(self::server(), $check['server']) : self::server(),
            'base_url' => (string)($check['base_url'] ?? ''),
            'acknowledged' => $ack,
            'locked' => self::lockedBy($verdict, $ack),
            'data_under_root' => DataDirectory::isUnderDocumentRoot(),
            'data_dir' => Text::path(CF_DATA),
            'env_var' => 'COURSEFORGE_DATA_DIR',
        ];
    }

    /** True when secrets may not be stored: not proven safe, and nobody has accepted the risk. */
    public static function locked(): bool
    {
        try {
            $check = self::lastCheck();
            return self::lockedBy((string)($check['verdict'] ?? self::VERDICT_UNKNOWN), self::acknowledgement());
        } catch (Throwable) {
            return true;
        }
    }

    /** @param array<string,mixed>|null $ack */
    private static function lockedBy(string $verdict, ?array $ack): bool
    {
        return $verdict !== self::VERDICT_SECURE && $ack === null;
    }

    /** The gate every write of a secret passes through. */
    public static function assertSecretsWritable(): void
    {
        if (PHP_SAPI === 'cli' && !self::$enforceInCli) {
            return;
        }
        if (!self::locked()) {
            return;
        }
        $verdict = (string)(self::lastCheck()['verdict'] ?? self::VERDICT_UNKNOWN);
        throw HttpException::unprocessable(match ($verdict) {
            self::VERDICT_EXPOSED => 'This server hands out the files that would hold this secret, so CourseForge refuses '
                . 'to store it. Administration › Security says what was found and how to fix it.',
            self::VERDICT_UNVERIFIED => 'CourseForge could not verify that this server keeps its data directory private, so '
                . 'it will not store a secret yet. Administration › Security says why, and lets an administrator accept '
                . 'the risk deliberately.',
            default => 'Nobody has checked yet whether this server keeps its data directory private, and a secret is '
                . 'the one thing that must not be stored before that is known. Open Administration › Security: the '
                . 'check runs there.',
        });
    }

    /** Whether the scheduler should take the verdict again. */
    public static function due(): bool
    {
        $check = self::lastCheck();
        return (int)($check['at'] ?? 0) < time() - self::RECHECK_SECONDS;
    }

    /* ---------------------------------------------------------------- check */

    /**
     * Takes the verdict: probes the server and writes down what it found.
     *
     * @return array<string,mixed> the status afterwards
     */
    public static function check(): array
    {
        $server = self::server();
        $base = self::baseUrl();
        $result = [
            'at' => time(),
            'verdict' => self::VERDICT_UNVERIFIED,
            'reason' => '',
            'probes' => [],
            'server' => $server,
            'base_url' => $base,
        ];

        if ($base === '') {
            $result['reason'] = 'CourseForge does not know its own public address, so it could not ask the server '
                . 'for its files. Set "Public address" under Administration › Settings › General and check again.';
            self::store($result);
            return self::status();
        }

        $token = bin2hex(random_bytes(16));
        $probeWritten = self::writeProbe($token);

        $probes = [];
        $reachable = true;
        foreach (self::probeList($probeWritten) as $probe) {
            $probes[] = $answer = self::probe($base, $probe, $token);
            // The first transport failure says the server cannot be reached
            // from here at all; asking eight more times only makes the check
            // slow, and the answer is the same for each.
            if ($answer['outcome'] === 'unreachable') {
                $reachable = false;
                break;
            }
        }
        self::removeProbe();

        $result['probes'] = $probes;
        if (!$reachable) {
            $failed = $probes[count($probes) - 1];
            $result['reason'] = 'The server could not be reached from itself at ' . $base . ' ('
                . (string)($failed['detail'] ?? 'no answer') . '). The verdict cannot be taken until it can: check '
                . '"Public address" under Settings, or allow the server to make HTTP requests to its own address.';
            self::store($result);
            return self::status();
        }

        $exposed = array_values(array_filter(
            $probes,
            static fn(array $p): bool => $p['critical'] && $p['outcome'] === 'exposed'
        ));
        $undecided = array_values(array_filter(
            $probes,
            static fn(array $p): bool => $p['critical'] && $p['outcome'] === 'undecided'
        ));

        if ($exposed !== []) {
            $result['verdict'] = self::VERDICT_EXPOSED;
            $result['reason'] = 'The server handed back ' . count($exposed) . ' file' . (count($exposed) === 1 ? '' : 's')
                . ' it must refuse: ' . implode(', ', array_map(static fn(array $p): string => $p['path'], $exposed)) . '.';
        } elseif ($undecided !== []) {
            $result['verdict'] = self::VERDICT_UNVERIFIED;
            $result['reason'] = 'The server answered, but not clearly enough to be sure about '
                . implode(', ', array_map(static fn(array $p): string => $p['path'], $undecided)) . '.';
        } else {
            $result['verdict'] = self::VERDICT_SECURE;
            $result['reason'] = 'Every private file was refused when asked for over HTTP.';
        }

        self::store($result);
        return self::status();
    }

    /**
     * The files asked for, and what each answer would mean.
     *
     * `critical` marks the ones that decide the verdict: anything holding a
     * secret. The rest are worth knowing - a served `tests/` directory is a
     * mistake - but do not lock the installation.
     *
     * @return array<int,array{path:string,label:string,critical:bool,marker:string,why:string}>
     */
    public static function probeList(bool $probeWritten = true): array
    {
        $list = [];
        if ($probeWritten) {
            $list[] = [
                'path' => 'data/' . self::PROBE_FILE,
                'label' => 'A file in the data directory',
                'critical' => true,
                'marker' => 'probe',
                'why' => 'data/ holds the database, with every key and token in plain text.',
            ];
        }
        $list[] = [
            'path' => 'data/.htaccess',
            'label' => 'The deny file of the data directory',
            'critical' => true,
            'marker' => 'Require all denied',
            'why' => 'A server that serves the deny file is not reading it.',
        ];
        $list[] = [
            'path' => 'data/app.sqlite',
            'label' => 'The database',
            'critical' => true,
            'marker' => 'SQLite format 3',
            'why' => 'Every password hash, AI key and BookStack token.',
        ];
        $list[] = [
            'path' => 'config/defaults.json',
            'label' => 'The shipped configuration',
            'critical' => true,
            'marker' => '"prompts"',
            'why' => 'Not secret in itself, but the same rule that refuses it refuses data/config.json - which holds the cron and GitHub tokens.',
        ];
        if (is_file(CF_ROOT . '/' . Invite::FILE)) {
            $list[] = [
                'path' => Invite::FILE,
                'label' => 'The invite code',
                'critical' => true,
                'marker' => 'invite code',
                'why' => 'The key to an account on this installation.',
            ];
        }
        $list[] = [
            'path' => '.user.ini',
            'label' => 'The PHP configuration',
            'critical' => false,
            'marker' => 'display_errors',
            'why' => 'Reveals how PHP is set up here.',
        ];
        $list[] = [
            'path' => 'tests/run.php',
            'label' => 'The test runner',
            'critical' => false,
            'marker' => 'command line only',
            'why' => 'Executable PHP that is not part of the application.',
        ];
        $list[] = [
            'path' => 'tools/cron.php',
            'label' => 'The command-line tools',
            'critical' => false,
            'marker' => 'command line only',
            'why' => 'Executable PHP that is not part of the application.',
        ];
        return $list;
    }

    /**
     * Asks the server for one file.
     *
     * @param array{path:string,label:string,critical:bool,marker:string,why:string} $probe
     * @return array<string,mixed>
     */
    private static function probe(string $base, array $probe, string $token): array
    {
        $url = $base . '/' . ltrim($probe['path'], '/');
        $answer = self::fetch($url);

        $row = [
            'path' => $probe['path'],
            'label' => $probe['label'],
            'critical' => $probe['critical'],
            'why' => $probe['why'],
            'status' => $answer['status'],
            'outcome' => 'refused',
            'detail' => '',
        ];

        if ($answer['error'] !== '' && $answer['status'] === 0) {
            $row['outcome'] = 'unreachable';
            $row['detail'] = $answer['error'];
            return $row;
        }

        $body = $answer['body'];
        $marker = $probe['marker'] === 'probe' ? $token : $probe['marker'];
        $served = $answer['status'] >= 200 && $answer['status'] < 300;

        if ($served && $marker !== '' && stripos($body, $marker) !== false) {
            $row['outcome'] = 'exposed';
            $row['detail'] = 'HTTP ' . $answer['status'] . ', and the file came back.';
        } elseif ($served) {
            // 200 with something else in the body: a front controller that
            // answers every path with index.html, a custom error page, or a
            // PHP file that ran and printed nothing. Not the file - but for a
            // file that holds a secret, "not sure" is not "safe".
            $row['outcome'] = $probe['critical'] && trim($body) === '' ? 'undecided' : 'refused';
            $row['detail'] = 'HTTP ' . $answer['status'] . ', but not the file itself'
                . ($row['outcome'] === 'undecided' ? ' - an empty answer, which could be the file being run rather than served' : '') . '.';
        } else {
            $row['detail'] = 'HTTP ' . $answer['status'] . '.';
        }
        return $row;
    }

    /**
     * One GET, a few seconds long, a few kilobytes at most.
     *
     * Its own cURL rather than Http::request(), because this is the one
     * request in CourseForge that must not read a whole body - the database
     * can be hundreds of megabytes and the first few bytes say what it is -
     * and the one that may accept a certificate it cannot verify: it is
     * talking to itself, sends nothing, and learns nothing a stranger could
     * not learn by asking the same URL.
     *
     * @return array{status:int,body:string,error:string}
     */
    private static function fetch(string $url, bool $verify = true): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'the PHP cURL extension is not enabled'];
        }

        $body = '';
        $limit = 8192;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_HTTPHEADER => ['Accept: */*', 'Range: bytes=0-' . ($limit - 1), 'X-CourseForge-Probe: 1'],
            CURLOPT_USERAGENT => 'CourseForge/' . CF_VERSION . ' security check',
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, $limit): int {
                $body .= $chunk;
                // Returning less than the chunk aborts the transfer, which is
                // what "a few kilobytes at most" means to cURL.
                return strlen($body) > $limit ? 0 : strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        // Cut off by the cap above: the bytes that arrived are the answer.
        if ($errno === CURLE_WRITE_ERROR && $status > 0) {
            return ['status' => $status, 'body' => $body, 'error' => ''];
        }
        // A certificate this server cannot verify about itself - self-signed,
        // or a chain the host's bundle does not hold - is not what this check
        // is about. Ask once more without it.
        if ($verify && in_array($errno, [CURLE_SSL_CACERT, CURLE_SSL_PEER_CERTIFICATE, CURLE_SSL_CONNECT_ERROR, 60, 51, 35], true)) {
            return self::fetch($url, false);
        }
        if ($errno !== 0 && $status === 0) {
            return ['status' => 0, 'body' => $body, 'error' => $error . ' (errno ' . $errno . ')'];
        }
        return ['status' => $status, 'body' => $body, 'error' => ''];
    }

    /** The address to ask, or '' when there is none worth trying. */
    private static function baseUrl(): string
    {
        $configured = trim(Config::str('app.public_url', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
        if (!isset($_SERVER['HTTP_HOST'])) {
            return '';
        }
        $base = PublicUrl::base();
        return str_starts_with($base, 'https://your-install') ? '' : $base;
    }

    /* --------------------------------------------------------------- server */

    /**
     * What can be told about the server from inside a request.
     *
     * @return array<string,mixed>
     */
    public static function server(): array
    {
        $software = (string)($_SERVER['SERVER_SOFTWARE'] ?? '');
        $sapi = PHP_SAPI;
        $family = self::family($software, $sapi);

        return [
            'software' => $software,
            'sapi' => $sapi,
            'family' => $family,
            'php' => PHP_VERSION,
            'https' => isset($_SERVER['HTTP_HOST']) ? Session::isHttps() : null,
            'htaccess_root' => is_file(CF_ROOT . '/.htaccess'),
            'htaccess_data' => is_file(CF_DATA . '/.htaccess'),
            'reads_htaccess' => in_array($family, ['apache', 'litespeed'], true),
            'mod_rewrite' => self::apacheModule('mod_rewrite'),
            'mod_headers' => self::apacheModule('mod_headers'),
            'data_dir' => Text::path(CF_DATA),
            'root' => Text::path(CF_ROOT),
        ];
    }

    private static function family(string $software, string $sapi): string
    {
        $s = strtolower($software);
        return match (true) {
            str_contains($s, 'litespeed') => 'litespeed',
            str_contains($s, 'nginx') => 'nginx',
            str_contains($s, 'caddy') => 'caddy',
            str_contains($s, 'microsoft-iis') => 'iis',
            str_contains($s, 'apache') => 'apache',
            str_contains($s, 'development server') => 'builtin',
            $sapi === 'apache2handler' => 'apache',
            $sapi === 'cli-server' => 'builtin',
            default => 'unknown',
        };
    }

    /** True, false, or null when the question cannot be asked here. */
    private static function apacheModule(string $name): ?bool
    {
        if (!function_exists('apache_get_modules')) {
            return null;
        }
        try {
            return in_array($name, (array)apache_get_modules(), true);
        } catch (Throwable) {
            return null;
        }
    }

    /* -------------------------------------------------------- acknowledgement */

    /**
     * The confirmation code the Security screen asks to be typed back.
     *
     * Six characters from an alphabet with nothing that can be mistaken for
     * anything else, kept in the session for a quarter of an hour. Typing it
     * proves that whoever presses the button read the box it is printed in.
     */
    public static function issueCode(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['security_code'] = ['code' => $code, 'expires' => time() + self::CODE_SECONDS];
        }
        return $code;
    }

    /** Whether what was typed is the code that was shown, and still fresh. */
    public static function codeMatches(string $typed): bool
    {
        $held = $_SESSION['security_code'] ?? null;
        if (!is_array($held) || (int)($held['expires'] ?? 0) < time()) {
            return false;
        }
        $typed = preg_replace('/\s+/', '', $typed) ?? '';
        return $typed !== '' && hash_equals((string)$held['code'], $typed);
    }

    /** Records that an administrator has read the verdict and accepts it. */
    public static function acknowledge(string $by): void
    {
        $verdict = (string)(self::lastCheck()['verdict'] ?? self::VERDICT_UNKNOWN);
        Meta::set(self::META_ACK, (string)json_encode([
            'by' => $by,
            'at' => time(),
            'verdict' => $verdict,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        unset($_SESSION['security_code']);
    }

    /** Takes the acknowledgement back: the gate closes again unless the verdict is secure. */
    public static function revokeAcknowledgement(): void
    {
        Meta::set(self::META_ACK, '');
    }

    /** @return array<string,mixed>|null */
    public static function acknowledgement(): ?array
    {
        $raw = Meta::get(self::META_ACK, '');
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) && (int)($decoded['at'] ?? 0) > 0 ? $decoded : null;
    }

    /* -------------------------------------------------------------- storage */

    /** @return array<string,mixed> */
    private static function lastCheck(): array
    {
        $raw = Meta::get(self::META_CHECK, '');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $result */
    private static function store(array $result): void
    {
        Meta::set(self::META_CHECK, (string)json_encode(
            $result,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        ));
    }

    /** Forgets the verdict, so the next look takes it again. */
    public static function forget(): void
    {
        Meta::set(self::META_CHECK, '');
    }

    private static function writeProbe(string $token): bool
    {
        try {
            DataDirectory::ensure();
        } catch (Throwable) {
            return false;
        }
        $path = CF_DATA . '/' . self::PROBE_FILE;
        $body = "CourseForge security probe " . $token . "\n"
            . "If you can read this over HTTP, the data directory is not private.\n";
        return @file_put_contents($path, $body, LOCK_EX) !== false;
    }

    private static function removeProbe(): void
    {
        @unlink(CF_DATA . '/' . self::PROBE_FILE);
    }
}
