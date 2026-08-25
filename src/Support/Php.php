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
 * THE HARD PART, AND HOW THIS SOLVES IT
 *
 * There is one measurement this code cannot take: what the host would give if
 * our own file were not there. `ini_get()` answers with what is in effect, and
 * once we have raised something, that includes our own answer.
 *
 * An earlier version tried to solve this by measuring the host once and
 * remembering it. That was worse in two ways. A remembered reading goes stale,
 * so a host that later raises its limits gets our older, smaller numbers
 * written back over the better ones - breaking the one promise this file
 * makes. And any re-measurement reads through our own block, records our values
 * as the host's, and then deletes every raise on the next run while reporting
 * success.
 *
 * So nothing is remembered. The decision is made MONOTONIC instead, which needs
 * no memory at all:
 *
 *   - a directive our block already sets is never removed and never lowered -
 *     removing it is precisely what would drop the host back, and that is the
 *     whole trap;
 *   - a directive our block does not set is raised only if what is in effect is
 *     below the floor;
 *   - nothing is ever written below what is in effect.
 *
 * Those three rules together mean a run can only leave things the same or
 * better, whatever state the file is in and whatever the host changed
 * underneath. Running it twice writes nothing the second time - not because a
 * signature said so, but because there is nothing left to raise.
 *
 * The way back is explicit rather than inferred: `release()` takes the block
 * out and lets the host's own values return, which is what somebody moving
 * hosts actually wants and the only honest way to get a clean reading.
 */
final class Php
{
    /** The file PHP reads, when it reads one at all. */
    public const FILE = '.user.ini';

    /** Marks the block this application owns, so anything else in the file survives. */
    private const BEGIN = '; >>> CourseForge - managed block, edited by Settings > Set up PHP';
    private const END = '; <<< CourseForge';

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
     * What is true here now, and what a run would do about it.
     *
     * Reads only - no file is written and no row is stored - which is what
     * makes it safe to call from a dry run and from every admin page load.
     *
     * @return array<string,mixed>
     */
    public static function inspect(): array
    {
        $mechanism = self::mechanism();
        $entries = ini_get_all(null, true);
        $body = self::read();
        $ours = self::blockValues($body);

        $rows = [];
        foreach (self::wanted() as $name => $spec) {
            $entry = is_array($entries) ? ($entries[$name] ?? null) : null;
            $effective = (string)($entry['local_value'] ?? (string)ini_get($name));
            $access = (int)($entry['access'] ?? 0);
            $mine = $ours[$name] ?? null;

            $target = self::target($effective, $mine, $spec);

            $rows[] = [
                'name' => $name,
                'effective' => $effective,
                'effective_label' => self::label($effective, $spec),
                // What our own block sets, so a reader can tell "the host gives
                // this" from "we asked for this" without being told a number
                // nobody can actually measure.
                'ours' => $mine,
                'ours_label' => $mine === null ? '' : self::label($mine, $spec),
                'from_host' => $mine === null,
                'target' => $target,
                'target_label' => $target === null ? '' : self::label($target, $spec),
                'satisfied' => $target === null,
                'keeping' => $target !== null && $mine !== null && $target === $mine,
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
            'has_block' => self::spans($body) !== [],
            'cache_ttl' => (int)ini_get('user_ini.cache_ttl'),
            'settings' => $rows,
        ];
    }

    /**
     * What a run would change, without changing it.
     *
     * @return array<string,mixed>
     */
    public static function plan(): array
    {
        $state = self::inspect();
        $possible = $state['mechanism'] === 'user-ini';

        $change = [];
        $blocked = [];
        $held = 0;
        foreach ($state['settings'] as $row) {
            if ($row['keeping']) {
                $held++;
                continue;
            }
            if ($row['satisfied']) {
                continue;
            }
            // On a host that reads no .user.ini, nothing is about to be raised
            // by anybody here - so it belongs on the list of things the host
            // decides, not on the list of what this run will do.
            if (!$row['settable'] || !$possible) {
                $blocked[] = $row;
                continue;
            }
            $change[] = $row;
        }

        return $state + [
            'possible' => $possible,
            'change' => $change,
            'blocked' => $blocked,
            'already_right' => $change === [] && $blocked === [],
            'note' => self::note($state['mechanism'], $change, $blocked, $held),
        ];
    }

    /**
     * Writes the file, and answers with what it did.
     *
     * @return array<string,mixed>
     */
    public static function apply(string $by = ''): array
    {
        $plan = self::plan();

        if (!$plan['possible']) {
            return $plan + ['written' => false, 'released' => false, 'error' => $plan['note']];
        }

        $existing = self::read();
        $body = self::merge($existing, self::compose($plan['settings']));

        if ($body === $existing) {
            return $plan + ['written' => false, 'released' => false, 'error' => '', 'unchanged' => true];
        }

        $error = self::put($body);
        if ($error !== '') {
            return $plan + ['written' => false, 'released' => false, 'error' => $error];
        }

        Audit::record($by, 'php.setup', Text::path(self::path()), count($plan['change']) . ' setting(s) written');

        return $plan + ['written' => true, 'released' => false, 'error' => '', 'unchanged' => false];
    }

    /**
     * Takes CourseForge's block out and lets the host's own values return.
     *
     * The honest way back, and what somebody moving hosts actually wants. It is
     * a separate act from applying, and it does not pretend to have measured
     * anything: PHP caches this file, so what the host gives is not observable
     * until that cache expires, and any reading taken before then is still ours.
     * Saying so and waiting is the only correct answer.
     *
     * @return array<string,mixed>
     */
    public static function release(string $by = ''): array
    {
        $state = self::plan();

        if (!$state['possible']) {
            return $state + ['written' => false, 'released' => false, 'error' => $state['note']];
        }

        $existing = self::read();
        $body = self::strip($existing);

        if ($body === $existing) {
            return array_merge($state, [
                'written' => false,
                'released' => false,
                'error' => '',
                'note' => 'There is nothing of CourseForge\'s in that file to remove.',
            ]);
        }

        $error = self::put($body);
        if ($error !== '') {
            return $state + ['written' => false, 'released' => false, 'error' => $error];
        }

        Audit::record($by, 'php.release', Text::path(self::path()), 'managed block removed');

        // array_merge, not +: PHP's + keeps the LEFT operand for a duplicate
        // key, and $state already carries a note from plan() - so the sentence
        // below was silently discarded and the plan's shown in its place,
        // which said everything was in place just after it had been removed.
        return array_merge($state, [
            'written' => true,
            'released' => true,
            'error' => '',
            'note' => 'CourseForge\'s settings are out of that file. This host\'s own values come back within '
                . max(1, (int)ini_get('user_ini.cache_ttl')) . ' seconds, and Set up PHP will then decide from '
                . 'those rather than from ours. Give it that long before pressing it.',
        ]);
    }

    /**
     * The cheap check that runs when an administrator opens the application.
     *
     * Calls apply(), which compares what the file should say against what it
     * does and writes only on a difference. That comparison IS the check - an
     * earlier version short-circuited on a stored signature plus is_file(), so
     * an update that replaced the file with the shipped one passed both tests
     * and the raised limits stayed gone.
     */
    public static function ensure(string $by = ''): bool
    {
        if (PHP_SAPI === 'cli') {
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

    private static function read(): string
    {
        $path = self::path();
        return is_file($path) ? str_replace("\r\n", "\n", (string)@file_get_contents($path)) : '';
    }

    /** Writes atomically, or says why it could not. */
    private static function put(string $body): string
    {
        $path = self::path();

        // Written beside the target and renamed over it, so a request arriving
        // mid-write reads one file or the other and never half of one. A broken
        // .user.ini is not a syntax error somebody sees - it is a directive
        // silently ignored, which is worse.
        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temporary, $body, LOCK_EX) === false) {
            return 'Could not write ' . Text::path($path) . '. The directory is not writable by PHP, so these '
                . 'values have to be set in your hosting control panel instead.';
        }
        @chmod($temporary, 0644);

        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            return 'Could not replace ' . Text::path($path) . '.';
        }
        return '';
    }

    /**
     * The value to write for one directive, or null when nothing is needed.
     *
     * Monotonic, which is the whole design:
     *
     *   - a directive our block already sets is kept, whatever is in effect -
     *     removing it is exactly what would drop the host back to its own lower
     *     value, and doing that silently is the failure this guards against;
     *   - anything else is raised only when what is in effect falls short.
     *
     * So a run leaves things the same or better and never worse, with no memory
     * of what the host used to give.
     *
     * @param array<string,mixed> $spec
     */
    private static function target(string $effective, ?string $ours, array $spec): ?string
    {
        if ($spec['kind'] === 'fixed') {
            $want = (string)$spec['value'];
            if ($ours !== null) {
                return $want;
            }
            return self::sameFlag($effective, $want) ? null : $want;
        }

        $floor = (string)$spec['floor'];

        // Already ours: keep the largest of what we set, the floor, and what is
        // actually in effect. The third is not usually different - our own line
        // is what is in effect - but it is when the host has fixed the directive
        // at system level and ignored us. Writing our smaller number then would
        // be a reduction waiting to happen the day the host stops fixing it.
        if ($ours !== null) {
            return self::largest($ours, $floor, $effective);
        }

        if (in_array(trim($effective), (array)($spec['unlimited'] ?? []), true)) {
            return null;
        }

        return self::bytes($effective) >= self::bytes($floor) ? null : $floor;
    }

    /**
     * Whether two spellings of a switch mean the same thing.
     *
     * Not every one of these is a plain boolean. output_buffering is off, on,
     * or a buffer size in bytes - so "4096" means ON, and reading it as a word
     * that is not "on" made a buffered host look unbuffered and report itself
     * already correct.
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
            return is_numeric($value) && (float)$value > 0;
        };

        return $read($a) === $read($b);
    }

    /** Whichever of these means the most, kept in the spelling it arrived in. */
    private static function largest(string ...$values): string
    {
        $best = $values[0];
        foreach ($values as $value) {
            if ($value !== '' && self::bytes($value) > self::bytes($best)) {
                $best = $value;
            }
        }
        return $best;
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
        if ($spec['kind'] === 'seconds') {
            return $value === '0' || $value === '-1' ? 'no limit' : $value . 's';
        }
        if ($spec['kind'] === 'bytes') {
            return $value === '0' || $value === '-1' ? 'no limit' : $value;
        }
        return $value;
    }

    /* ------------------------------------------------------------- the block */

    /**
     * Every managed block in the file, as [start, end-after] offsets.
     *
     * All of them, not the first: PHP reads a repeated directive last-wins, so
     * a second block left behind silently overrides the one just written.
     *
     * @return array<int,array{0:int,1:int}>
     */
    private static function spans(string $body): array
    {
        $spans = [];
        $from = 0;

        while (($start = strpos($body, self::BEGIN, $from)) !== false) {
            $end = strpos($body, self::END, $start + strlen(self::BEGIN));

            if ($end === false) {
                // A BEGIN with no END is a damaged or half-edited file. It is
                // NOT a licence to delete down to the next END this tool writes
                // later - doing that swallowed the directives somebody had put
                // below it. An unpaired block ends where the file ends, so
                // there is nothing beyond it to lose.
                $spans[] = [$start, strlen($body)];
                break;
            }

            $after = $end + strlen(self::END);
            $spans[] = [$start, $after];
            $from = $after;
        }

        return $spans;
    }

    /**
     * The directives our own block currently sets.
     *
     * Read from the file rather than remembered, so it is always what is
     * actually there. A repeated directive takes its last value, which is what
     * PHP itself would do.
     *
     * @return array<string,string>
     */
    private static function blockValues(string $body): array
    {
        $known = self::wanted();
        $values = [];

        foreach (self::spans($body) as [$start, $after]) {
            foreach (explode("\n", substr($body, $start, $after - $start)) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === ';' || !str_contains($line, '=')) {
                    continue;
                }
                [$name, $value] = explode('=', $line, 2);
                $name = trim($name);
                if (isset($known[$name])) {
                    $values[$name] = trim($value);
                }
            }
        }

        return $values;
    }

    /** Removes every managed block, leaving everything else exactly as it was. */
    private static function strip(string $body): string
    {
        // Back to front, so removing one does not shift the offsets of the next.
        foreach (array_reverse(self::spans($body)) as [$start, $after]) {
            $body = substr($body, 0, $start) . substr($body, $after);
        }

        return $body;
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
            '; FLOOR this host did not already meet - a limit it grants more of',
            '; is left alone. Anything outside this block is yours and is never',
            '; touched.',
            ';',
            '; A line here is never removed by CourseForge, because removing it',
            '; is what would drop the host back to its own lower value. To hand',
            '; these settings back - after moving to different hosting, say -',
            '; use "Remove these settings" in Settings, which takes the whole',
            '; block out in one go and lets the host decide again.',
            ';',
            '; PHP caches this file for ' . max(0, (int)ini_get('user_ini.cache_ttl'))
                . ' seconds, so a change can take that long to take effect.',
            ';',
        ];

        $wrote = false;
        foreach ($rows as $row) {
            if ($row['target'] === null || !$row['settable']) {
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

    /** Replaces every managed block with exactly one, or appends it. */
    private static function merge(string $existing, string $block): string
    {
        $rest = self::strip($existing);

        return trim($rest) === '' ? $block : rtrim($rest) . "\n\n" . $block;
    }

    /**
     * @param array<int,array<string,mixed>> $change
     * @param array<int,array<string,mixed>> $blocked
     */
    private static function note(string $mechanism, array $change, array $blocked, int $held = 0): string
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
            return 'This server does not read a per-directory PHP configuration file, so nothing here can be '
                . 'changed from inside the application - these values have to be set in your hosting control '
                . 'panel.';
        }
        if ($change === [] && $blocked === []) {
            // Saying "the host already meets everything" while holding ten
            // directives up ourselves credits the host with our own work, and
            // would read as licence to delete the file.
            return $held > 0
                ? 'Everything CourseForge needs is in place. ' . $held . ' of these '
                    . ($held === 1 ? 'is' : 'are') . ' held by the .user.ini it wrote - removing that file would '
                    . 'put this host back to its own lower limits.'
                : 'This host already meets everything CourseForge asks for. Nothing needs changing.';
        }
        if ($blocked !== []) {
            return 'Some of these are fixed by the host and cannot be changed from here - they are listed so you '
                . 'know what to ask for, or where the limit will bite.';
        }
        return 'These raise limits that are lower than CourseForge needs. Nothing already generous is reduced.';
    }
}
