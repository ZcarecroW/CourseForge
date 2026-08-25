<?php
declare(strict_types=1);

namespace CourseForge\Support;

/**
 * What PHP has been told to do here, what CourseForge needs, and the one file
 * that can change it.
 *
 * Shared hosting hands out a PHP configuration nobody chose for this
 * application: sixty seconds of execution, a socket timeout shorter than a
 * model takes to answer, a memory limit set for a blog. None of that is
 * unreasonable of the host; it is simply not what a course generator wants.
 *
 * There is exactly one thing an application in a document root can do about it,
 * and only on some hosts: `.user.ini`. It is read by the CGI, FastCGI and FPM
 * SAPIs, applies to its own directory and everything below, and can set any
 * directive marked PHP_INI_USER or PHP_INI_PERDIR. Under mod_php it is not read
 * at all - there `.htaccess` `php_value` is the mechanism, and CourseForge
 * deliberately does not write that unattended, because one bad line there is a
 * 500 for the whole site rather than a setting that did not take.
 *
 * Three rules this file keeps, and they are the whole of its judgement:
 *
 *   - **Never lower a limit the host already grants.** Every numeric target is
 *     a floor, not a value. A host giving 768M of memory keeps 768M; a host
 *     giving 128M is asked for 256M. A configuration tool that quietly halved
 *     somebody's memory limit because a constant said 256M would be worse than
 *     no tool at all.
 *
 *   - **Say what could not be done.** A directive the host has fixed at the
 *     system level cannot be changed from here, and a `.user.ini` line for it
 *     is ignored in silence. Those are reported, not written.
 *
 *   - **Be idempotent, and re-check.** The file is rewritten only when it
 *     differs from what it should be, so an update that replaces it is repaired
 *     on the next admin page load rather than staying wrong until somebody
 *     notices.
 */
final class Php
{
    /** The file PHP reads, when it reads one at all. */
    public const FILE = '.user.ini';

    /** Marks the block this application owns, so anything else in the file survives. */
    private const BEGIN = '; >>> CourseForge - managed block, edited by Settings > Set up PHP';
    private const END = '; <<< CourseForge';

    /** Whether this installation has ever been set up, and against what. */
    public const META_APPLIED = 'php.setup_signature';

    /**
     * What this host gave us before we changed anything.
     *
     * Measured once and kept. Every decision is made against this rather than
     * against what is in effect now, because once our own .user.ini is being
     * read those two numbers are different - and deciding from the second one
     * makes the tool undo its own work on the next run, then redo it, for ever.
     */
    public const META_BASELINE = 'php.host_baseline';

    /**
     * What CourseForge asks for, and why.
     *
     * `floor` is a minimum, never a value to impose. `fixed` is a setting whose
     * right answer does not depend on the host. `unlimited` lists the values
     * that already beat any floor - a socket timeout of -1 is better than 300,
     * not worse.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function wanted(): array
    {
        return [
            'memory_limit' => [
                'kind' => 'bytes',
                'floor' => '256M',
                'unlimited' => ['-1'],
                'why' => 'A course tree, its pages and an update archive are all held in memory at once. '
                    . '128M is enough until an update, and then it is not.',
            ],
            'max_execution_time' => [
                'kind' => 'seconds',
                'floor' => '300',
                'unlimited' => ['0'],
                'why' => 'Writing a page, publishing a book and installing an update all take longer than the '
                    . 'sixty seconds most hosts allow. CourseForge lifts this again at runtime where it can, but '
                    . 'the request has already started by then.',
            ],
            'default_socket_timeout' => [
                'kind' => 'seconds',
                'floor' => '300',
                'unlimited' => ['-1', '0'],
                'why' => 'A model can take minutes to answer one page. At sixty seconds the request is cut off '
                    . 'while the answer is still coming, and the page is recorded as failed.',
            ],
            'max_input_time' => [
                'kind' => 'seconds',
                'floor' => '300',
                'unlimited' => ['-1', '0'],
                'why' => 'How long PHP will spend reading the request body. A long page arriving over a slow '
                    . 'connection needs more than the default.',
            ],
            'post_max_size' => [
                'kind' => 'bytes',
                'floor' => '32M',
                'unlimited' => ['0'],
                'why' => 'A finished page, its outline and its tags arrive in one request. Eight megabytes is the '
                    . 'usual default and a long course outline can pass it.',
            ],
            'upload_max_filesize' => [
                'kind' => 'bytes',
                'floor' => '32M',
                'unlimited' => ['0'],
                'why' => 'Restoring a backup means uploading the archive an update made.',
            ],
            'max_input_vars' => [
                'kind' => 'count',
                'floor' => '5000',
                'unlimited' => [],
                'why' => 'A course with hundreds of pages sends more fields than the default thousand allows, and '
                    . 'PHP drops the ones past the limit without saying so.',
            ],
            'display_errors' => [
                'kind' => 'fixed',
                'value' => 'Off',
                'why' => 'An error page that prints a file path tells a stranger where the database is.',
            ],
            'log_errors' => [
                'kind' => 'fixed',
                'value' => 'On',
                'why' => 'What display_errors stops showing has to go somewhere it can still be read.',
            ],
            'output_buffering' => [
                'kind' => 'fixed',
                'value' => 'Off',
                'why' => 'A long answer held in a buffer is a long answer held in memory, twice.',
            ],
        ];
    }

    /**
     * What is true here now.
     *
     * @return array<string,mixed>
     */
    public static function inspect(bool $remeasure = false): array
    {
        $mechanism = self::mechanism();
        $entries = ini_get_all(null, true);
        $baseline = self::baseline($remeasure);

        $rows = [];
        foreach (self::wanted() as $name => $spec) {
            $entry = is_array($entries) ? ($entries[$name] ?? null) : null;
            $effective = (string)($entry['local_value'] ?? (string)ini_get($name));
            $access = (int)($entry['access'] ?? 0);

            // Decided against what the host gave before we touched anything.
            // Deciding against what is in effect would mean deciding against
            // our own last answer, which is how the loop this replaced began.
            $host = (string)($baseline['values'][$name] ?? $effective);
            $target = self::target($host, $spec);

            $rows[] = [
                'name' => $name,
                'current' => $host,
                'current_label' => self::label($host, $spec),
                'effective' => $effective,
                'effective_label' => self::label($effective, $spec),
                'raised' => $effective !== $host,
                'target' => $target,
                'target_label' => $target === null ? '' : self::label($target, $spec),
                'satisfied' => $target === null,
                // .user.ini honours PHP_INI_USER (1) and PHP_INI_PERDIR (2).
                // Anything that is only PHP_INI_SYSTEM (4) is the host's to
                // decide and a line here would be ignored in silence.
                'settable' => ($access & 3) !== 0,
                'why' => (string)$spec['why'],
            ];
        }

        return [
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'mechanism' => $mechanism,
            'file' => $mechanism === 'user-ini' ? Text::path(self::path()) : '',
            'file_exists' => is_file(self::path()),
            'cache_ttl' => (int)ini_get('user_ini.cache_ttl'),
            'measured_at' => (int)($baseline['at'] ?? 0),
            'settings' => $rows,
        ];
    }

    /**
     * What this host gave before CourseForge changed anything.
     *
     * Measured the first time and kept. Re-measured only when asked, or when
     * the host is demonstrably a different one - a new PHP version or a new
     * SAPI. Notably NOT re-measured because the file went missing: an update
     * replaces it routinely, and PHP caches it for five minutes either way, so
     * a reading taken then would record our own value as the host's and freeze
     * the mistake in place.
     *
     * @return array{values:array<string,string>,php:string,sapi:string,at:int}
     */
    private static function baseline(bool $remeasure = false): array
    {
        $stored = json_decode(Meta::get(self::META_BASELINE, ''), true);

        $usable = is_array($stored)
            && is_array($stored['values'] ?? null)
            && ($stored['php'] ?? '') === PHP_VERSION
            && ($stored['sapi'] ?? '') === PHP_SAPI;

        if ($usable && !$remeasure) {
            /** @var array{values:array<string,string>,php:string,sapi:string,at:int} $stored */
            return $stored;
        }

        $values = [];
        foreach (array_keys(self::wanted()) as $name) {
            $values[$name] = (string)ini_get($name);
        }

        $fresh = ['values' => $values, 'php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'at' => time()];
        Meta::set(self::META_BASELINE, (string)json_encode($fresh));

        return $fresh;
    }

    /**
     * What a run would change, without changing it.
     *
     * @return array<string,mixed>
     */
    public static function plan(bool $remeasure = false): array
    {
        $state = self::inspect($remeasure);

        $change = [];
        $blocked = [];
        foreach ($state['settings'] as $row) {
            if ($row['satisfied']) {
                continue;
            }
            if (!$row['settable']) {
                $blocked[] = $row;
                continue;
            }
            $change[] = $row;
        }

        $possible = $state['mechanism'] === 'user-ini';

        return $state + [
            'possible' => $possible,
            'change' => $possible ? $change : [],
            'blocked' => $possible ? $blocked : array_merge($change, $blocked),
            'already_right' => $change === [] && $blocked === [],
            'note' => self::note($state['mechanism'], $change, $blocked),
        ];
    }

    /**
     * Writes the file, and answers with what it did.
     *
     * @return array<string,mixed>
     */
    public static function apply(string $by = '', bool $remeasure = false): array
    {
        $plan = self::plan($remeasure);

        if (!$plan['possible']) {
            return $plan + ['written' => false, 'error' => $plan['note']];
        }

        $desired = self::compose($plan['settings']);
        $path = self::path();
        $existing = is_file($path) ? (string)@file_get_contents($path) : '';

        if (self::merge($existing, $desired) === $existing) {
            Meta::set(self::META_APPLIED, self::signature());
            return $plan + ['written' => false, 'error' => '', 'unchanged' => true];
        }

        $body = self::merge($existing, $desired);

        // Written beside the target and renamed over it, so a request arriving
        // mid-write reads one file or the other and never half of one. A broken
        // .user.ini is not a syntax error somebody sees - it is a directive
        // silently ignored, which is worse.
        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temporary, $body, LOCK_EX) === false) {
            return $plan + [
                'written' => false,
                'error' => 'Could not write ' . Text::path($path) . '. The directory is not writable by PHP, so '
                    . 'these values have to be set in your hosting control panel instead.',
            ];
        }
        @chmod($temporary, 0644);

        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            return $plan + ['written' => false, 'error' => 'Could not replace ' . Text::path($path) . '.'];
        }

        Meta::set(self::META_APPLIED, self::signature());
        Audit::record($by, 'php.setup', Text::path($path), count($plan['change']) . ' setting(s) written');

        return $plan + ['written' => true, 'error' => '', 'unchanged' => false];
    }

    /**
     * The cheap check that runs when an administrator opens the application.
     *
     * Does nothing at all in the ordinary case: a signature of what the file
     * should contain is compared against what was last written, and only a
     * difference costs anything. That is what makes it safe to call on every
     * admin page load, and what repairs a file an update replaced.
     */
    public static function ensure(string $by = ''): bool
    {
        if (PHP_SAPI === 'cli') {
            return false;
        }
        if (Meta::get(self::META_APPLIED) === self::signature() && is_file(self::path())) {
            return false;
        }

        $result = self::apply($by);
        return ($result['written'] ?? false) === true;
    }

    /* ------------------------------------------------------------- internals */

    /** Which mechanism, if any, can change PHP's mind on this host. */
    private static function mechanism(): string
    {
        if (PHP_SAPI === 'cli') {
            return 'cli';
        }
        if (trim((string)ini_get('user_ini.filename')) === '') {
            return 'none';
        }
        // .user.ini is a CGI/FastCGI feature. Under mod_php the file is never
        // read, and the honest answer is to say so rather than write one and
        // let somebody believe it took effect.
        if (in_array(PHP_SAPI, ['cgi', 'cgi-fcgi', 'fpm-fcgi', 'litespeed'], true)) {
            return 'user-ini';
        }
        return PHP_SAPI === 'apache2handler' ? 'htaccess' : 'none';
    }

    private static function path(): string
    {
        return CF_ROOT . '/' . (trim((string)ini_get('user_ini.filename')) ?: self::FILE);
    }

    /**
     * The value to write for one directive, or null when the host is already
     * doing at least as well.
     *
     * @param array<string,mixed> $spec
     */
    private static function target(string $current, array $spec): ?string
    {
        if ($spec['kind'] === 'fixed') {
            $want = (string)$spec['value'];
            return self::sameFlag($current, $want) ? null : $want;
        }

        if (in_array(trim($current), (array)($spec['unlimited'] ?? []), true)) {
            return null;
        }

        $floor = (string)$spec['floor'];
        $have = self::bytes($current);
        $need = self::bytes($floor);

        return $have >= $need ? null : $floor;
    }

    /**
     * Whether two spellings of a switch mean the same thing.
     *
     * Not every one of these is a plain boolean. output_buffering is off, on,
     * or a buffer size in bytes - so "4096" means ON, and reading it as a word
     * that is not "on" made a buffered host look like an unbuffered one and
     * report itself already correct.
     */
    private static function sameFlag(string $a, string $b): bool
    {
        $read = static function (string $value): bool {
            $value = strtolower(trim($value));
            if (in_array($value, ['1', 'on', 'true', 'yes'], true)) {
                return true;
            }
            if (in_array($value, ['', '0', 'off', 'false', 'no', '-1'], true)) {
                return false;
            }
            // A size rather than a word: any positive number is switched on.
            return is_numeric($value) && (float)$value > 0;
        };

        return $read($a) === $read($b);
    }

    /** A shorthand size, in bytes. -1 and 0 mean "no limit", which beats any floor. */
    private static function bytes(string $value): float
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        if ($value === '-1' || $value === '0') {
            return INF;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float)$value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /** @param array<string,mixed> $spec */
    private static function label(string $value, array $spec): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'not set';
        }
        if (in_array($spec['kind'], ['seconds'], true)) {
            return $value === '0' || $value === '-1' ? 'no limit' : $value . 's';
        }
        if ($spec['kind'] === 'bytes') {
            return $value === '0' || $value === '-1' ? 'no limit' : $value;
        }
        return $value;
    }

    /**
     * The managed block, as it should read.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private static function compose(array $rows): string
    {
        $lines = [
            self::BEGIN,
            ';',
            '; Written by CourseForge to suit this host. Every number here is a',
            '; FLOOR that was not already met - a limit the host grants more of',
            '; is left exactly as it was. Anything outside this block is yours',
            '; and is never touched.',
            ';',
            '; The host was measured once, before any of this was written, and',
            '; that measurement is what these values were decided from - not',
            '; what is in effect now, which includes them. After changing hosts,',
            '; use "Measure this host again" in Settings; deleting this block by',
            '; hand is not enough, because PHP caches the file either way and a',
            '; reading taken in that window would record these values as the',
            '; host\'s own.',
            ';',
            '; PHP caches this file for ' . max(0, (int)ini_get('user_ini.cache_ttl'))
                . ' seconds, so a change can take that long to take effect.',
            ';',
        ];

        $wrote = false;
        foreach ($rows as $row) {
            if ($row['satisfied'] || !$row['settable']) {
                continue;
            }
            foreach (explode("\n", wordwrap((string)$row['why'], 68)) as $line) {
                $lines[] = '; ' . $line;
            }
            $lines[] = $row['name'] . ' = ' . $row['target'];
            $lines[] = '';
            $wrote = true;
        }

        if (!$wrote) {
            $lines[] = '; Nothing to change: this host already meets everything CourseForge';
            $lines[] = '; asks for.';
            $lines[] = '';
        }

        $lines[] = self::END;

        return implode("\n", $lines) . "\n";
    }

    /** Replaces the managed block in an existing file, or appends it. */
    private static function merge(string $existing, string $block): string
    {
        $existing = str_replace("\r\n", "\n", $existing);

        $start = strpos($existing, self::BEGIN);
        $end = strpos($existing, self::END);

        if ($start !== false && $end !== false && $end > $start) {
            $before = substr($existing, 0, $start);
            $after = substr($existing, $end + strlen(self::END));
            return rtrim($before) === ''
                ? $block . ltrim($after)
                : rtrim($before) . "\n\n" . $block . ltrim($after);
        }

        return trim($existing) === '' ? $block : rtrim($existing) . "\n\n" . $block;
    }

    /** What the file should contain, reduced to something comparable. */
    private static function signature(): string
    {
        // Built from the host baseline and the targets, both of which are
        // stable once measured - so a second run produces the same signature
        // and writes nothing, which is what makes ensure() cheap enough to
        // call on every admin request.
        $parts = [PHP_VERSION, PHP_SAPI, self::mechanism()];
        foreach (self::inspect()['settings'] as $row) {
            $parts[] = $row['name'] . '=' . $row['current'] . '/' . ($row['target'] ?? '-');
        }
        return hash('sha256', implode('|', $parts));
    }

    /**
     * @param array<int,array<string,mixed>> $change
     * @param array<int,array<string,mixed>> $blocked
     */
    private static function note(string $mechanism, array $change, array $blocked): string
    {
        if ($mechanism === 'cli') {
            return 'This is the command line, which reads no .user.ini. Nothing to do here.';
        }
        if ($mechanism === 'htaccess') {
            return 'This server runs PHP as an Apache module, which never reads .user.ini. These values have to '
                . 'go in .htaccess as php_value lines, or in your hosting control panel. CourseForge will not '
                . 'write them for you: one bad php_value line takes the whole site down with a 500, and that is '
                . 'not a risk worth taking unattended.';
        }
        if ($mechanism === 'none') {
            return 'This server does not read a per-directory PHP configuration file, so these values have to be '
                . 'set in your hosting control panel.';
        }
        if ($change === [] && $blocked === []) {
            return 'This host already meets everything CourseForge asks for. Nothing needs changing.';
        }
        if ($blocked !== []) {
            return 'Some of these are fixed by the host and cannot be changed from here - they are listed so you '
                . 'know what to ask for, or where the limit will bite.';
        }
        return 'These raise limits that are lower than CourseForge needs. Nothing already generous is reduced.';
    }
}
