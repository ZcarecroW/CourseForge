<?php
declare(strict_types=1);

namespace CourseForge\Update;

use CourseForge\Support\Audit;
use CourseForge\Support\Config;
use CourseForge\Support\Db;
use CourseForge\Support\HttpException;
use CourseForge\Support\Json;
use CourseForge\Support\Lock;
use CourseForge\Support\Meta;
use CourseForge\Support\Runtime;
use CourseForge\Support\Settings;
use CourseForge\Support\Text;
use DateTimeImmutable;
use DateTimeZone;
use ParseError;
use RuntimeException;
use Throwable;

/**
 * Replacing CourseForge with a newer CourseForge, from inside CourseForge.
 *
 * This is the most dangerous thing in the application, because the files being
 * replaced are the files doing the replacing, and because the person who will
 * find out that it went wrong is an administrator looking at a blank page with
 * no application left to tell them anything. Everything below is arranged
 * around that one fact.
 *
 * The install root is never swapped wholesale. The obvious design - unpack the
 * release beside the installation and rename the two directories - is the wrong
 * one here for two separate reasons. The document root is usually not something
 * PHP is allowed to rename, because it is the thing the web server is
 * configured to point at and its parent belongs to the hosting account rather
 * than to the site; and the script performing the rename is inside the
 * directory being renamed, which on some platforms pulls the ground out from
 * under the running request. So the update walks the release file by file
 * instead: slower, entirely undramatic, and it can be undone.
 *
 * Everything the release will touch is copied into a backup first, and that
 * backup is finished and closed before a single file is replaced. It is what
 * makes rollback possible, and it is what makes the failure mode survivable: a
 * swap that stops half way through has already put every file it was going to
 * touch, replaced or not, somewhere it can be read back from.
 *
 * The backup is one zip archive rather than a directory of files, and that is a
 * security decision rather than a tidy one. A backup tree is a complete and
 * executable second copy of the application sitting under `data/`, and `data/`
 * is kept out of the web by an .htaccess whose no-mod_rewrite fallback names
 * sqlite, json, md, log, ini and txt but not php - and there are hosts that
 * ignore an .htaccess in a subdirectory altogether. Where both of those hold,
 * every backed-up .php file is reachable and runnable over HTTP: an old version
 * of this application, with whatever was wrong with it, served beside the new
 * one. Nothing inside a zip executes, whatever the web server believes about
 * the directory it is in.
 *
 * `data/` is never touched, whatever the archive contains. That is the whole
 * bargain of the two-layer configuration: `config/defaults.json` is shipped and
 * replaced on every update, `data/config.json` holds what this installation
 * decided and is not the update's business. The same goes for the database, the
 * invite code, and anything else that is this installation rather than this
 * release.
 *
 * A finished update proves itself before it is accepted. Every PHP file it
 * installed is parsed, the new configuration is read, the new CF_VERSION is
 * checked, and - where the host allows PHP to start a program - a child process
 * boots the new code from scratch, loads every class in it, and runs the
 * release's own database migration. The migration has to be proved there and
 * nowhere else: this process opened its PDO handle long before the swap and
 * hands back the same one for ever after, and the class that would do the
 * migrating is the old one it loaded at the time. If any of that fails the
 * backup goes straight back and the history row says so. An update that cannot
 * prove it works is not an update.
 */
final class Updater
{
    /** The lease name. One update at a time, across every worker and tab. */
    public const LOCK = 'updates.install';

    /**
     * The lease, renewed at every phase boundary rather than taken once.
     *
     * Long enough for a slow download on a slow host, short enough to recover
     * from a killed process - but it is the same 1800 seconds Archive allows
     * one download, so a download that used its whole budget would leave
     * nothing of the lease for verifying, unpacking, swapping and a deep check
     * that has two minutes of its own. Renewing between phases gives each of
     * them the full lease in front of it, which is the only arrangement in
     * which a cron tick cannot walk in and start a second swap over the same
     * tree.
     */
    private const LOCK_SECONDS = 1800;

    private const META_AUTO_CHECK = 'updates.auto_check_on';
    private const META_AUTO_INSTALL = 'updates.auto_install_on';

    /** How long past the configured time an unattended install waits for a busy installation. */
    private const DEFER_SECONDS = 7200;

    /**
     * Paths an update never writes to or deletes, relative to the install root.
     *
     * `data` is the installation itself - configuration overrides, the
     * database, sessions, backups, the staged update - and replacing any of it
     * with what a release happens to carry would undo the update's own work.
     * `INVITE-CODE.txt` is generated on this server and exists nowhere else, so
     * losing it locks the first administrator out; a release archive should
     * never contain one, and this line is here for the archive that does.
     * `.git` and `.github` are repository plumbing that a GitHub zipball drags
     * along and that has no business in a document root.
     */
    private const PROTECTED = ['data', 'INVITE-CODE.txt', '.git', '.github'];

    /** What a staged tree must contain before it is believed to be CourseForge. */
    private const SIGNATURE = ['src/bootstrap.php', 'config/defaults.json', 'api/index.php'];

    /** The backup's own record of what it holds. Prefixed so no release can ship one. */
    private const BACKUP_MANIFEST = '.cf-backup.json';

    /* ------------------------------------------------------------- reading */

    /**
     * Everything the Updates screen needs in one call.
     *
     * @return array<string,mixed>
     */
    public static function status(bool $force = false): array
    {
        $check = GitHub::check($force);
        $release = $check['release'];

        return [
            'version' => CF_VERSION,
            'repository' => $check['repository'],
            'channel' => $check['channel'],
            'checked_at' => $check['checked_at'],
            'attempted_at' => $check['attempted_at'],
            'cached' => $check['cached'],
            'error' => $check['error'],
            'available' => $release !== null && $release->isNewerThan(CF_VERSION),
            'latest' => $release?->toArray(),
            'preconditions' => self::preconditions($release),
            'settings' => self::settings(),
            'schedule' => self::schedule(),
            'running' => self::running(),
            'backups' => self::backups(),
        ];
    }

    /**
     * The update settings, without the secret.
     *
     * The token is deliberately absent rather than masked. A screen only ever
     * needs to know whether one is stored, and a value that is never put into a
     * response cannot be leaked by a response.
     *
     * @return array<string,mixed>
     */
    public static function settings(): array
    {
        return [
            'enabled' => (bool)filter_var(self::setting('updates.enabled'), FILTER_VALIDATE_BOOLEAN),
            'repository' => trim((string)self::setting('updates.repository')),
            'channel' => GitHub::channel(),
            'auto_check' => (bool)filter_var(self::setting('updates.auto_check'), FILTER_VALIDATE_BOOLEAN),
            'auto_install' => (bool)filter_var(self::setting('updates.auto_install'), FILTER_VALIDATE_BOOLEAN),
            'auto_time' => (string)self::setting('updates.auto_time'),
            'timezone' => self::timezone()->getName(),
            'keep_backups' => max(0, (int)self::setting('updates.keep_backups')),
            'token_set' => GitHub::token() !== '',
        ];
    }

    /** True while an install or a rollback holds the lease. */
    public static function running(): bool
    {
        try {
            return Lock::heldFor(self::LOCK) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<int,array<string,mixed>> newest first */
    public static function history(int $limit = 25): array
    {
        // Inlined rather than bound: SQLite will not take a parameter in LIMIT,
        // and the value is clamped here to a small integer.
        $limit = max(1, min(200, $limit));

        return array_map(
            static fn(array $row): array => self::readable($row),
            Db::rows('SELECT * FROM update_history ORDER BY id DESC LIMIT ' . $limit)
        );
    }

    /**
     * One history row on its way to a screen.
     *
     * The stored path is left exactly as it was written, because it is the
     * archive an operator may later have to find by hand and because nothing
     * should be able to rewrite a record of what happened. Only the copy the
     * screen prints is respelled.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function readable(array $row): array
    {
        if (($row['backup_path'] ?? '') !== '') {
            $row['backup_path'] = Text::path((string)$row['backup_path']);
        }

        return $row;
    }

    /**
     * The backups on disk, newest first.
     *
     * Each one is a single archive, and its manifest is an entry inside it. An
     * archive whose manifest will not read is still listed: it is still a set
     * of files that can be put back, and hiding it would only mean an
     * administrator could not see the one thing that might save them.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function backups(): array
    {
        $root = self::backupRoot();
        if (!is_dir($root)) {
            return [];
        }

        $backups = [];
        foreach ((array)@glob($root . '/*.zip') as $file) {
            $file = (string)$file;
            if (!is_file($file)) {
                continue;
            }
            $manifest = [];
            $raw = Archive::readEntry($file, self::BACKUP_MANIFEST);
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                $manifest = is_array($decoded) ? $decoded : [];
            }
            $backups[] = [
                'name' => basename($file, '.zip'),
                'path' => $file,
                'created_at' => (int)($manifest['created_at'] ?? @filemtime($file) ?: 0),
                'from_version' => (string)($manifest['from_version'] ?? ''),
                'to_version' => (string)($manifest['to_version'] ?? ''),
                'files' => count((array)($manifest['replaced'] ?? [])) + count((array)($manifest['deleted'] ?? [])),
                'complete' => $manifest !== [],
            ];
        }

        usort($backups, static fn(array $a, array $b): int => $b['created_at'] <=> $a['created_at']);

        return $backups;
    }

    /**
     * Every condition an update has to meet, each answered separately.
     *
     * They are reported one by one, and before anything is downloaded, because
     * "the update failed" is not a useful sentence. An administrator who is told
     * that the install directory is not writable can go and fix that; an
     * administrator who is told that something went wrong can only try again.
     *
     * A `blocking` check that fails stops the update. A non-blocking one is a
     * warning: worth reading, not worth refusing over.
     *
     * @return array<int,array{key:string,label:string,ok:bool,blocking:bool,detail:string}>
     */
    public static function preconditions(?Release $release = null): array
    {
        $settings = self::settings();
        $checks = [];

        $checks[] = self::check(
            'enabled',
            'Updates are switched on',
            $settings['enabled'],
            true,
            $settings['enabled'] ? '' : 'updates.enabled is off, so CourseForge will not replace its own files.'
        );

        $checks[] = self::check(
            'repository',
            'A repository is configured',
            $settings['repository'] !== '',
            true,
            $settings['repository'] !== '' ? $settings['repository'] : 'updates.repository is empty.'
        );

        $zip = class_exists(\ZipArchive::class);
        $checks[] = self::check(
            'zip',
            'PHP can open a zip archive',
            $zip,
            // Only blocking for a zip release. A fork shipping a .tar.gz needs
            // ext-phar instead, which Archive reports for itself.
            $release === null || !$release->isTarball(),
            $zip ? 'ext-zip is loaded.' : 'ext-zip is not enabled, and a release is a zip file.'
        );

        [$writable, $writeDetail] = self::probeWritable(CF_ROOT);
        $checks[] = self::check('install_writable', 'PHP may write to the installation', $writable, true, $writeDetail);

        [$dataWritable, $dataDetail] = self::probeWritable(CF_DATA);
        $checks[] = self::check('data_writable', 'PHP may write to the data directory', $dataWritable, true, $dataDetail);

        $running = self::running();
        $checks[] = self::check(
            'not_running',
            'No update is already running',
            !$running,
            true,
            $running ? 'Another update has held the lease for ' . Lock::heldFor(self::LOCK) . ' more second(s).' : ''
        );

        $newer = $release !== null && $release->isNewerThan(CF_VERSION);
        $checks[] = self::check(
            'newer',
            'A newer version is available',
            $newer,
            true,
            match (true) {
                $release === null => 'Nothing has been fetched from GitHub yet - check for updates first.',
                $newer => $release->version . ' is newer than the installed ' . CF_VERSION . '.',
                default => 'The newest release is ' . $release->version . ' and this installation is on ' . CF_VERSION . '.',
            }
        );

        $active = self::activeRuns();
        $checks[] = self::check(
            'idle',
            'No generation run is in flight',
            $active === 0,
            false,
            $active === 0
                ? ''
                : $active . ' run(s) are still being written. Their pages are saved as they finish, so nothing is lost, '
                    . 'but a worker mid-request will be cut off by the swap and its page retried on a later tick.'
        );

        $free = @disk_free_space(CF_DATA);
        $needed = ($release?->assetSize ?? 0) * 3; // download, extraction, backup
        $checks[] = self::check(
            'disk',
            'There is room for the download and a backup',
            !is_float($free) || $needed === 0 || $free > $needed,
            false,
            is_float($free)
                ? Archive::size((int)$free) . ' free in the data directory.'
                : 'The free space could not be read.'
        );

        return $checks;
    }

    /* ------------------------------------------------------------ installing */

    /**
     * Downloads and installs the newest release.
     *
     * Throws only when the update never started - a failed precondition, a lock
     * held by somebody else, no release to install. Once it has started, a
     * failure is an outcome rather than an exception: it is written to the
     * history row with the log that explains it, and that row is what comes
     * back, because a person needs to read what happened far more than the
     * caller needs a stack trace.
     *
     * @return array{history:array<string,mixed>,log:string,ok:bool}
     */
    public static function install(string $actor, string $trigger = 'manual'): array
    {
        // An install always asks GitHub afresh. Acting on an hour-old cache is
        // how an installation ends up downloading a release that was pulled.
        $check = GitHub::check(true);
        $release = $check['release'];

        if ($release === null) {
            throw HttpException::unprocessable(
                $check['error'] !== '' ? $check['error'] : 'No release could be read from ' . $check['repository'] . '.'
            );
        }

        $blocking = array_filter(
            self::preconditions($release),
            static fn(array $c): bool => !$c['ok'] && $c['blocking']
        );
        if ($blocking !== []) {
            throw HttpException::unprocessable(
                'This update cannot start. ' . implode(' ', array_map(
                    static fn(array $c): string => $c['label'] . ': ' . ($c['detail'] !== '' ? $c['detail'] : 'failed.'),
                    $blocking
                ))
            );
        }

        $owner = Lock::acquire(self::LOCK, self::LOCK_SECONDS);
        if ($owner === false) {
            throw HttpException::unprocessable('An update is already running on this installation.');
        }

        try {
            return self::perform($release, $actor, $trigger, $check['channel'], $owner);
        } finally {
            Lock::release(self::LOCK, $owner);
        }
    }

    /**
     * The update itself, with the lease already held.
     *
     * @param string $owner the lease token, so each phase can push the expiry out in front of the next one
     * @return array{history:array<string,mixed>,log:string,ok:bool}
     */
    private static function perform(Release $release, string $actor, string $trigger, string $channel, string $owner): array
    {
        $lines = [];
        $log = static function (string $line) use (&$lines): void {
            $lines[] = gmdate('H:i:s') . '  ' . $line;
        };

        $from = CF_VERSION;
        $historyId = self::begin($from, $release->version, $channel, $trigger, $actor);
        $backupFile = '';

        try {
            $log('CourseForge ' . $from . ' to ' . $release->version . ' (' . $release->tag . ', '
                . $channel . ' channel), started by ' . ($actor !== '' ? $actor : 'the scheduler') . '.');

            $staging = self::stagingRoot();
            Archive::ensureDirectory($staging);

            $token = GitHub::token();
            $archive = Archive::download($release, $token, $staging, $log);
            self::renewLease($owner, $log, true);

            Archive::verify($archive, $release, $token, $log);

            $stagedRoot = Archive::extract($archive, $staging . '/' . $release->slug(), $log);
            self::assertLooksLikeCourseForge($stagedRoot);

            $shipped = self::shippedFiles($stagedRoot, $log);
            if ($shipped === []) {
                throw new RuntimeException('The release archive contains no files this update may install.');
            }

            // Everything this method still needs is loaded now, while the old
            // files are all still on disk. A class that is first autoloaded
            // after the swap would be read from the new release and dropped into
            // a process full of the old one, which is a mixture nobody has ever
            // tested.
            self::warmUp();
            self::renewLease($owner, $log, true);

            $backupFile = self::backupFile($from);
            $manifest = self::swap($stagedRoot, $shipped, $backupFile, $release, $from, $log);

            self::refresh($log);
            self::renewLease($owner, $log, false);

            $problems = self::smoke($shipped, $release, $from, $log);
            if ($problems !== []) {
                $log('SMOKE CHECK FAILED. Putting ' . $from . ' back.');
                self::restore($backupFile, $log);
                self::refresh($log);
                $log('Rolled back to ' . $from . '. Nothing further was changed.');

                $row = self::finish($historyId, 'rolled_back', $lines, implode(' ', $problems), $backupFile);
                Audit::record($actor, 'update.rolled_back', $release->tag, implode(' ', $problems), self::source($trigger));

                return ['history' => $row, 'log' => (string)$row['log'], 'ok' => false];
            }

            self::writeManifest($shipped, $release);
            self::prune($log);

            // The staged copy has served its purpose and is several megabytes.
            Archive::remove($stagedRoot);
            @unlink($archive);

            $log('Update complete. CourseForge is now ' . $release->version . '.');
            $row = self::finish($historyId, 'installed', $lines, '', $backupFile);
            Audit::record(
                $actor,
                'update.install',
                $release->tag,
                $from . ' to ' . $release->version . ', ' . count($manifest['replaced']) . ' file(s) replaced, '
                    . count($manifest['added']) . ' added, ' . count($manifest['deleted']) . ' removed',
                self::source($trigger)
            );

            return ['history' => $row, 'log' => (string)$row['log'], 'ok' => true];
        } catch (Throwable $e) {
            Runtime::log('update.install', $e);
            $log('FAILED: ' . $e->getMessage());

            // A failure after the first file was replaced leaves a mixture of
            // two releases on disk, which is the one state that must not be
            // left behind. The backup exists from the moment before the swap
            // begins, so its presence is the test: anything that failed earlier
            // than that has changed nothing, and anything later is put back
            // whether or not the swap got as far as returning.
            if ($backupFile !== '' && is_file($backupFile)) {
                try {
                    self::restore($backupFile, $log);
                    self::refresh($log);
                    $log('Put ' . $from . ' back from the backup.');
                } catch (Throwable $restore) {
                    Runtime::log('update.restore', $restore);
                    $log('THE ROLLBACK ALSO FAILED: ' . $restore->getMessage()
                        . ' The backup is still at ' . $backupFile . ' and can be unpacked over the installation by hand.');
                }
            }

            $row = self::finish($historyId, 'failed', $lines, $e->getMessage(), $backupFile);
            Audit::record($actor, 'update.failed', $release->tag, $e->getMessage(), self::source($trigger));

            return ['history' => $row, 'log' => (string)$row['log'], 'ok' => false];
        }
    }

    /**
     * Puts the most recent backup back.
     *
     * @return array{history:array<string,mixed>,log:string,ok:bool}
     */
    public static function rollback(string $actor, string $trigger = 'manual'): array
    {
        $backups = self::backups();
        if ($backups === []) {
            throw HttpException::unprocessable(
                'There is no backup on this installation, so there is nothing to go back to.'
            );
        }
        $backup = $backups[0];

        [$writable] = self::probeWritable(CF_ROOT);
        if (!$writable) {
            throw HttpException::unprocessable(
                'PHP cannot write to ' . Text::path(CF_ROOT) . ', so nothing can be restored.'
            );
        }

        $owner = Lock::acquire(self::LOCK, self::LOCK_SECONDS);
        if ($owner === false) {
            throw HttpException::unprocessable('An update is already running on this installation.');
        }

        $lines = [];
        $log = static function (string $line) use (&$lines): void {
            $lines[] = gmdate('H:i:s') . '  ' . $line;
        };

        $from = CF_VERSION;
        $to = $backup['from_version'] !== '' ? (string)$backup['from_version'] : 'the previous version';
        $historyId = self::begin($from, $to, GitHub::channel(), $trigger, $actor);

        try {
            $log('Restoring ' . $backup['name'] . ', taken ' . gmdate('Y-m-d H:i', (int)$backup['created_at']) . ' UTC.');
            self::warmUp();
            self::restore((string)$backup['path'], $log);
            self::refresh($log);
            self::renewLease($owner, $log, false);

            $problems = self::smokeFiles($log);
            $log($problems === []
                ? 'The restored installation parses and its configuration and database read back.'
                : 'The restored installation still reports: ' . implode(' ', $problems));

            $row = self::finish($historyId, 'restored', $lines, implode(' ', $problems), (string)$backup['path']);
            Audit::record($actor, 'update.rollback', (string)$backup['name'], $from . ' back to ' . $to, self::source($trigger));

            return ['history' => $row, 'log' => (string)$row['log'], 'ok' => $problems === []];
        } catch (Throwable $e) {
            Runtime::log('update.rollback', $e);
            $log('FAILED: ' . $e->getMessage());
            $row = self::finish($historyId, 'failed', $lines, $e->getMessage(), (string)$backup['path']);

            return ['history' => $row, 'log' => (string)$row['log'], 'ok' => false];
        } finally {
            Lock::release(self::LOCK, $owner);
        }
    }

    /* -------------------------------------------------------------- schedule */

    /**
     * The unattended half, called once a minute from Support\Cron.
     *
     * Three decisions are worth spelling out, because none of them is the
     * obvious one.
     *
     * The daily check and the daily install are both gated on a calendar day in
     * the configured time zone, stored in the meta table, rather than on the
     * tick landing in a particular minute. A minute is far too small a target:
     * a host that runs cron every five minutes, a tick that overruns, a server
     * that was asleep at five in the morning - all of them would miss it, and
     * the feature would work for some installations and silently not for
     * others. Comparing days instead means the window is the rest of the day.
     *
     * That is also the answer to a clock that goes backwards. Moving the clock
     * back an hour lands on the same calendar day, the marker for that day is
     * already stored, and nothing runs twice.
     *
     * A missed window runs late rather than not at all. If the configured time
     * is 05:00 and the first tick of the day arrives at 09:20, the update
     * happens at 09:20. An administrator who asked for unattended updates wants
     * them installed; the hour is a preference about when it is least
     * disruptive, not a condition.
     *
     * Switching automatic installation on is also, on its own, a request to
     * find out what there is to install. The daily check is a separate setting
     * about the Updates screen being current; an installation that is allowed
     * to update itself unattended has to ask regardless, or it would be acting
     * on a cache that nothing refreshes.
     *
     * @return array<string,mixed> a line for the cron report
     */
    public static function scheduled(): array
    {
        $report = [
            'enabled' => false,
            'checked' => false,
            'available' => false,
            'latest' => '',
            'installed' => false,
            'status' => '',
            'note' => '',
        ];

        if (!GitHub::enabled()) {
            return array_merge($report, ['note' => 'updates are switched off']);
        }
        $report['enabled'] = true;

        $settings = self::settings();
        $zone = self::timezone();
        $now = new DateTimeImmutable('now', $zone);
        $today = $now->format('Y-m-d');

        // Unattended installation asks GitHub whether the daily check is
        // switched on or not. Without that it would act on GitHub::cached(),
        // which on an installation that has never checked holds nothing at all
        // - so the feature would quietly never fire - and on one that checked
        // last month holds last month's answer.
        $dailyCheckDue = $settings['auto_check'] && Meta::get(self::META_AUTO_CHECK) !== $today;
        if ($dailyCheckDue || $settings['auto_install']) {
            // Not forced. GitHub::check() holds a good answer for an hour and
            // backs off for a quarter of one after a failure, so asking on every
            // tick costs at most one call an hour, a check that cannot succeed
            // is retried at a sensible pace, and one an administrator has
            // already made by hand today costs nothing to repeat.
            $check = GitHub::check(false);
            if ($check['error'] === '') {
                Meta::set(self::META_AUTO_CHECK, $today);
                $report['checked'] = true;
            } else {
                $report['note'] = 'check failed: ' . $check['error'];
            }
        }

        $release = GitHub::cached();
        $report['latest'] = $release?->version ?? '';
        $report['available'] = $release !== null && $release->isNewerThan(CF_VERSION);

        if (!$settings['auto_install']) {
            return array_merge($report, ['note' => $report['note'] !== '' ? $report['note'] : 'automatic installation is off']);
        }
        if (!$report['available']) {
            return array_merge($report, ['note' => $report['note'] !== '' ? $report['note'] : 'nothing newer than ' . CF_VERSION]);
        }
        if (Meta::get(self::META_AUTO_INSTALL) === $today) {
            return array_merge($report, ['note' => 'the unattended slot for ' . $today . ' has already been used']);
        }

        $due = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $today . ' ' . $settings['auto_time'], $zone);
        if ($due === false) {
            return array_merge($report, ['note' => 'updates.auto_time is not a readable time of day']);
        }
        if ($now < $due) {
            return array_merge($report, ['note' => 'due at ' . $settings['auto_time'] . ' ' . $zone->getName()]);
        }

        // A busy installation is given a couple of hours' grace before the swap
        // interrupts it. Past that the update goes ahead anyway: a run that has
        // been open all morning is more likely to be stuck than to be busy, and
        // an update that never happens is worse than a page that gets retried.
        $active = self::activeRuns();
        if ($active > 0 && $now->getTimestamp() - $due->getTimestamp() < self::DEFER_SECONDS) {
            return array_merge($report, ['note' => 'deferred: ' . $active . ' generation run(s) still in flight']);
        }

        // Asked here as well as inside install(), because the answer decides
        // whether the day's slot is spent. A precondition that fails on this
        // tick will fail on the next one too - an install directory does not
        // become writable by itself - so there is nothing to be gained by
        // starting an update that is going to be refused, and something to lose:
        // install() asks GitHub afresh every time it is called.
        $blocking = array_filter(
            self::preconditions($release),
            static fn(array $c): bool => !$c['ok'] && $c['blocking']
        );
        if ($blocking !== []) {
            return array_merge($report, ['note' => 'not started: ' . implode(' ', array_map(
                static fn(array $c): string => $c['label'] . ': ' . ($c['detail'] !== '' ? $c['detail'] : 'failed.'),
                $blocking
            ))]);
        }

        // Written before the install rather than after it. If the update takes
        // the process down with it - a fatal in half-copied code, a host that
        // kills the request - the marker has already been stored, so the next
        // tick does not walk into the same crash a minute later. The cost is
        // that a crashed unattended update waits until tomorrow, which is the
        // right way round: an administrator can always press the button.
        Meta::set(self::META_AUTO_INSTALL, $today);

        try {
            $result = self::install('', 'schedule');
            $report['status'] = (string)($result['history']['status'] ?? '');
            $report['installed'] = $report['status'] === 'installed';
            $report['note'] = 'unattended install: ' . $report['status'];
        } catch (HttpException $e) {
            // install() throws only for an update that never started: a
            // precondition that failed, a lease a manual install is holding,
            // a release that went away between the check and the attempt.
            // Nothing was touched and there is no crash to walk back into a
            // minute later, so the day's slot is given back rather than spent
            // on an update that never happened. A failure inside the update is
            // the other case, and it keeps the marker for the reason above.
            Meta::set(self::META_AUTO_INSTALL, '');
            Runtime::log('update.scheduled', $e);
            $report['note'] = 'unattended install refused: ' . $e->getMessage();
        } catch (Throwable $e) {
            Runtime::log('update.scheduled', $e);
            $report['note'] = 'unattended install refused: ' . $e->getMessage();
        }

        return $report;
    }

    /**
     * When the unattended jobs are next due, for the Updates screen.
     *
     * @return array<string,mixed>
     */
    public static function schedule(): array
    {
        $settings = self::settings();
        $zone = self::timezone();
        $now = new DateTimeImmutable('now', $zone);

        $due = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $now->format('Y-m-d') . ' ' . $settings['auto_time'], $zone);
        if ($due !== false && ($now >= $due || Meta::get(self::META_AUTO_INSTALL) === $now->format('Y-m-d'))) {
            $due = $due->modify('+1 day');
        }

        return [
            'auto_check' => $settings['auto_check'],
            'auto_install' => $settings['auto_install'],
            'time' => $settings['auto_time'],
            'timezone' => $zone->getName(),
            'next_install_at' => $due === false ? 0 : $due->getTimestamp(),
            'last_check_day' => Meta::get(self::META_AUTO_CHECK),
            'last_install_day' => Meta::get(self::META_AUTO_INSTALL),
        ];
    }

    /* ------------------------------------------------------------ the swap */

    /**
     * Takes the backup, then copies the new files in.
     *
     * The order is the point. Which files the swap will touch is known before
     * it touches any of them - the release's file list against what is on disk
     * - so the whole backup, manifest included, is packed and closed while the
     * installation is still untouched. A swap that dies half way through is
     * then covered completely rather than up to whichever file it had reached,
     * and there is no window in which the record of what happened is behind the
     * work.
     *
     * @param array<int,string> $shipped
     * @param callable(string):void $log
     * @return array{replaced:array<int,string>,added:array<int,string>,deleted:array<int,string>}
     */
    private static function swap(string $stagedRoot, array $shipped, string $backupFile, Release $release, string $from, callable $log): array
    {
        $previous = self::readManifest();

        $replaced = [];
        $added = [];
        $deleted = [];

        foreach ($shipped as $relative) {
            if (is_file(CF_ROOT . '/' . $relative)) {
                $replaced[] = $relative;
            } else {
                $added[] = $relative;
            }
        }

        // Files the previous release shipped and this one does not. Without a
        // manifest of the previous release there is no way to tell those apart
        // from files the administrator put there on purpose, and deleting
        // somebody's own upload is not a risk worth taking for the sake of
        // tidiness.
        if ($previous !== null) {
            foreach (array_diff($previous, $shipped) as $relative) {
                if (self::isProtected($relative) || !is_file(CF_ROOT . '/' . $relative)) {
                    continue;
                }
                $deleted[] = $relative;
            }
        }

        Archive::pack($backupFile, CF_ROOT, array_merge($replaced, $deleted), [
            self::BACKUP_MANIFEST => (string)json_encode([
                'created_at' => time(),
                'from_version' => $from,
                'to_version' => $release->version,
                'tag' => $release->tag,
                'replaced' => $replaced,
                'added' => $added,
                'deleted' => $deleted,
                // The manifest that was in force before this update, so a
                // rollback restores the bookkeeping along with the files.
                'manifest_before' => $previous,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ]);
        $log('Backed up ' . (count($replaced) + count($deleted)) . ' file(s) into '
            . basename($backupFile) . ' (' . Archive::size((int)@filesize($backupFile)) . ').');

        foreach ($shipped as $relative) {
            Archive::copyFile($stagedRoot . '/' . $relative, CF_ROOT . '/' . $relative);
        }
        $log(count($replaced) . ' file(s) replaced, ' . count($added) . ' added.');

        foreach ($deleted as $relative) {
            @unlink(CF_ROOT . '/' . $relative);
        }
        $log(match (true) {
            $previous === null => 'No manifest of the currently installed release exists, so no obsolete files were '
                . 'removed. One is written now, and the next update will be able to clean up after this one.',
            $deleted === [] => 'This release ships every file the previous one did; nothing was removed.',
            default => count($deleted) . ' file(s) the new release no longer ships were removed, and are in the '
                . 'backup: ' . Text::snippet(implode(', ', $deleted), 300),
        });

        return ['replaced' => $replaced, 'added' => $added, 'deleted' => $deleted];
    }

    /**
     * Puts a backup back over the installation.
     *
     * Everything in the archive is written back, whatever the manifest says -
     * the files are the truth and the manifest is only the commentary. The
     * manifest is needed for one thing the files cannot express: which files
     * the update added, since those have no predecessor to restore and have to
     * be deleted instead.
     *
     * The archive is read rather than consumed, so it survives the restore and
     * a second attempt is still possible.
     *
     * @param callable(string):void $log
     */
    private static function restore(string $backupFile, callable $log): void
    {
        if ($backupFile === '' || !is_file($backupFile)) {
            throw new RuntimeException('The backup ' . $backupFile . ' is not there.');
        }

        $manifest = [];
        $raw = Archive::readEntry($backupFile, self::BACKUP_MANIFEST);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $manifest = is_array($decoded) ? $decoded : [];
        }
        if ($manifest === []) {
            $log('The backup manifest could not be read; the files are restored anyway, but files the update added '
                . 'cannot be identified and are left in place.');
        }

        $restored = Archive::unpack(
            $backupFile,
            CF_ROOT,
            static fn(string $name): bool => $name !== self::BACKUP_MANIFEST && !self::isProtected($name)
        );

        $removed = 0;
        foreach ((array)($manifest['added'] ?? []) as $relative) {
            $relative = (string)$relative;
            if (self::isProtected($relative)) {
                continue;
            }
            if (is_file(CF_ROOT . '/' . $relative) && @unlink(CF_ROOT . '/' . $relative)) {
                $removed++;
            }
        }

        $before = $manifest['manifest_before'] ?? null;
        if (is_array($before)) {
            Json::write(self::manifestFile(), ['files' => array_values(array_map('strval', $before))]);
        }

        $log(count($restored) . ' file(s) restored from ' . basename($backupFile)
            . ($removed > 0 ? ', ' . $removed . ' file(s) the update had added removed' : '') . '.');
    }

    /**
     * The files a staged release is allowed to install.
     *
     * @param callable(string):void $log
     * @return array<int,string> relative paths
     */
    private static function shippedFiles(string $stagedRoot, callable $log): array
    {
        $all = Archive::listFiles($stagedRoot);
        $files = [];
        $skipped = 0;

        foreach ($all as $relative) {
            if (self::isProtected($relative)) {
                $skipped++;
                continue;
            }
            $files[] = $relative;
        }

        $log('The release contains ' . count($all) . ' file(s); ' . count($files) . ' will be installed'
            . ($skipped > 0 ? ' and ' . $skipped . ' left alone because they fall under a protected path (data/, .git, the invite code)' : '')
            . '.');

        return $files;
    }

    /* ------------------------------------------------------------- checking */

    /**
     * Does the new installation actually work?
     *
     * @param array<int,string> $shipped
     * @param callable(string):void $log
     * @return array<int,string> problems, empty when everything passed
     */
    private static function smoke(array $shipped, Release $release, string $from, callable $log): array
    {
        $problems = self::smokeFiles($log, $shipped);

        $installed = self::installedVersion();
        if ($installed === '') {
            $problems[] = 'CF_VERSION could not be read out of the new src/bootstrap.php.';
        } elseif (!Release::isNewer($installed, $from)) {
            // The real question is whether the swap took effect at all. A
            // constant that is still the old value means the files did not land.
            $problems[] = 'The installed CF_VERSION is still ' . $installed . ', so the new files did not take.';
        } else {
            $log('The installation now reports CF_VERSION ' . $installed . '.');
            if (Release::normalise($installed) !== Release::normalise($release->version)) {
                // Not a failure. A tag and a constant that disagree is a slip in
                // somebody's release process, not a broken installation, and
                // rolling back a working update over it would help nobody.
                $log('Note: the release is tagged ' . $release->tag . ' but ships CF_VERSION ' . $installed . '.');
            }
        }

        foreach (self::deepCheck($log) as $problem) {
            $problems[] = $problem;
        }

        return $problems;
    }

    /**
     * The half of the smoke check that does not care which release is running:
     * the files parse, the configuration reads, the database answers.
     *
     * The database is only opened here, not migrated. Db::pdo() hands back the
     * handle this process opened before the swap and never runs a migration
     * again, and the class that would run one is the old one already loaded, so
     * a release's schema change cannot be tested from in here at all. That is
     * the deep check's job, in a process that has none of this one's history.
     *
     * @param array<int,string>|null $shipped the files to parse, or null for the whole of src/
     * @param callable(string):void $log
     * @return array<int,string>
     */
    private static function smokeFiles(callable $log, ?array $shipped = null): array
    {
        $problems = [];

        foreach (self::SIGNATURE as $required) {
            if (!is_file(CF_ROOT . '/' . $required) || (int)@filesize(CF_ROOT . '/' . $required) === 0) {
                $problems[] = $required . ' is missing or empty.';
            }
        }

        // token_get_all() with TOKEN_PARSE is a real syntax check and needs no
        // subprocess: it throws a ParseError on anything PHP could not compile.
        // It is what catches a copy that was cut short by a full disk.
        $candidates = $shipped ?? Archive::listFiles(CF_ROOT . '/src');
        $prefix = $shipped === null ? 'src/' : '';
        $parsed = 0;
        $bad = [];

        foreach ($candidates as $relative) {
            if (!str_ends_with(strtolower($relative), '.php')) {
                continue;
            }
            $path = CF_ROOT . '/' . $prefix . $relative;
            $source = @file_get_contents($path);
            if ($source === false) {
                $bad[] = $prefix . $relative . ' could not be read back';
                continue;
            }
            try {
                token_get_all($source, TOKEN_PARSE);
                $parsed++;
            } catch (ParseError $e) {
                $bad[] = $prefix . $relative . ' (' . $e->getMessage() . ')';
            }
        }
        if ($bad !== []) {
            $problems[] = count($bad) . ' installed PHP file(s) do not parse: ' . Text::snippet(implode('; ', $bad), 400);
        } else {
            $log($parsed . ' PHP file(s) parsed cleanly.');
        }

        try {
            $defaults = Json::read(CF_ROOT . '/config/defaults.json');
            if (!is_array($defaults) || $defaults === []) {
                $problems[] = 'config/defaults.json is empty or unreadable.';
            }
        } catch (Throwable $e) {
            $problems[] = 'config/defaults.json is not valid JSON: ' . $e->getMessage();
        }

        try {
            Db::pdo();
            $log('The database answers; the schema it is recorded at is version ' . Meta::int('schema_version')
                . '. Whether this release migrates it is tested by the deep check, not here.');
        } catch (Throwable $e) {
            $problems[] = 'The database could not be reached: ' . $e->getMessage();
        }

        return $problems;
    }

    /**
     * Boots the new code in a child process, loads every class in it, and runs
     * its database migration.
     *
     * This is the check the in-process ones cannot make. Parsing a file proves
     * it compiles; it does not prove that bootstrap.php still runs, that the
     * autoloader still finds anything, or that a class can be declared - a
     * missing parent class or an interface that changed shape is a fatal error
     * at declaration time and nowhere earlier. Nor can this process test a
     * schema change: it is holding the PDO handle it opened before the swap and
     * the Db class it loaded at the same time, so asking it to migrate does
     * nothing at all. The child has neither, which is what makes it the only
     * place a release's migration can be proved.
     *
     * That the migration really runs is also why a skip has to say so. The
     * child writes to the live database, so a release with a broken schema step
     * fails here and is rolled back; where the child cannot be started, that
     * step goes untested, and the log says which of the two happened rather
     * than letting an administrator assume the good one.
     *
     * Skipped on a host that will not let PHP start a program, which is most
     * shared hosting. The in-process checks stand on their own.
     *
     * @param callable(string):void $log
     * @return array<int,string>
     */
    private static function deepCheck(callable $log): array
    {
        $untested = " The database migration was NOT tested: a schema change in this release will first run, and "
            . "first be able to fail, on somebody's next page load.";

        if (!self::canSpawn()) {
            $log('Deep check: SKIPPED - this host does not let PHP start other programs (proc_open is disabled).' . $untested);
            return [];
        }

        $binary = self::phpBinary();
        if ($binary === '') {
            $log('Deep check: SKIPPED - no PHP command-line binary could be identified'
                . (PHP_BINARY !== '' ? ' (PHP_BINARY is "' . PHP_BINARY . '")' : '') . '.' . $untested);
            return [];
        }

        $script = '';
        try {
            $script = self::spool(self::childScript());
            $result = self::spawn($binary, $script, 120);
        } catch (Throwable $e) {
            $log('Deep check: SKIPPED - the check script could not be run (' . $e->getMessage() . ').' . $untested);
            return [];
        } finally {
            if ($script !== '') {
                @unlink($script);
            }
        }

        // The child chooses 3 for a migration that threw, so the two failures
        // that both end in a non-zero exit can be told apart in the log: code
        // that will not boot, and code that boots and cannot migrate.
        if ($result['status'] === 3) {
            return ['The new code booted but could not migrate the database. ' . Text::snippet(trim($result['stderr']), 400)
                . ' The files have been put back; the database may have been left part way through that change.'];
        }

        if ($result['status'] !== 0 || !str_contains($result['stdout'], 'CF_VERSION=')) {
            $detail = trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);

            return ['The new code does not start: PHP exited with ' . $result['status'] . '. '
                . Text::snippet($detail !== '' ? $detail : 'It printed nothing at all.', 400)];
        }

        // A child that exits 0 without this line got past its own error
        // handling somehow, and an unproven migration is not something to
        // assume in favour of.
        if (preg_match('/^SCHEMA=(\d+)/m', $result['stdout'], $schema) !== 1 || (int)$schema[1] <= 0) {
            return ['The new code started but never reported a database schema version, so its migration cannot be '
                . 'trusted. ' . Text::snippet(trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']), 300)];
        }

        if (preg_match('/^UNRESOLVED=(.*)$/m', $result['stdout'], $m) === 1 && trim($m[1]) !== '') {
            // Not fatal. A file that declares no matching symbol is odd but
            // legal, and refusing an otherwise working update over it would be
            // the tail wagging the dog.
            $log('Deep check: every file compiled, but these declared nothing the autoloader could name: '
                . Text::snippet(trim($m[1]), 300));
        }
        $log('Deep check: a fresh PHP process booted the new code, loaded every class in it, and migrated the '
            . 'database to schema version ' . (int)$schema[1] . '.');

        return [];
    }

    /**
     * The script the deep check runs.
     *
     * A nowdoc, so nothing in it is interpolated and the namespace separators
     * survive being written to disk exactly as typed. Only the bootstrap path
     * is substituted, and through var_export(), which is the one way to put a
     * Windows path into PHP source without counting backslashes.
     *
     * It ends by opening the database, because that is what runs the release's
     * migration, and prints the schema version it arrived at so the parent has
     * something to check rather than an exit code to trust. A migration that
     * throws exits 3 with the reason on stderr: an uncaught exception would
     * carry the same message, but a code of its own is what lets the parent say
     * "it booted and could not migrate" instead of "it does not start".
     */
    private static function childScript(): string
    {
        $template = <<<'PHP'
        <?php
        declare(strict_types=1);

        require __BOOTSTRAP__;

        $base = CF_ROOT . '/src';
        $unresolved = [];
        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($walk as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($base) + 1));
            if ($relative === 'bootstrap.php') {
                continue;
            }
            $class = 'CourseForge\\' . str_replace('/', '\\', substr($relative, 0, -4));
            if (
                !class_exists($class)
                && !interface_exists($class)
                && !trait_exists($class)
                && !enum_exists($class)
            ) {
                $unresolved[] = $relative;
            }
        }

        echo 'CF_VERSION=', CF_VERSION, PHP_EOL;
        echo 'UNRESOLVED=', implode(' ', $unresolved), PHP_EOL;

        try {
            CourseForge\Support\Db::pdo();
            $schema = CourseForge\Support\Meta::int('schema_version');
        } catch (Throwable $e) {
            fwrite(STDERR, 'The migration failed: ' . $e->getMessage() . PHP_EOL);
            exit(3);
        }

        if ($schema < 1) {
            fwrite(STDERR, 'The database migrated without recording a schema version.' . PHP_EOL);
            exit(3);
        }

        echo 'SCHEMA=', $schema, PHP_EOL;
        PHP;

        return str_replace(
            '__BOOTSTRAP__',
            var_export(str_replace('\\', '/', CF_ROOT) . '/src/bootstrap.php', true),
            $template
        );
    }

    /**
     * Writes the check script somewhere it can be run and nowhere the web can
     * reach it.
     *
     * The system temporary directory is tried first for exactly that reason. A
     * `.php` file inside the data directory is only protected by an .htaccess,
     * and there are hosts that ignore one in a subdirectory - so the fallback
     * exists, but it is a fallback, and the caller deletes the file either way.
     */
    private static function spool(string $source): string
    {
        $name = 'cf-update-check-' . bin2hex(random_bytes(8)) . '.php';

        foreach ([sys_get_temp_dir(), CF_DATA . '/updates'] as $directory) {
            $directory = rtrim((string)$directory, '/\\');
            if ($directory === '' || !is_dir($directory) || !is_writable($directory)) {
                continue;
            }
            if (@file_put_contents($directory . '/' . $name, $source, LOCK_EX) !== false) {
                return $directory . '/' . $name;
            }
        }

        throw new RuntimeException('There is nowhere writable to put the check script.');
    }

    /**
     * Runs a command and collects both its outputs under a deadline.
     *
     * The pipes are drained in a select loop rather than read one after the
     * other: a child that fills the stderr buffer while nobody is reading it
     * stops, and a straight fread() of stdout would then wait for a process that
     * is itself waiting for us.
     *
     * @return array{status:int,stdout:string,stderr:string}
     */
    private static function spawn(string $binary, string $script, int $timeout): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $process = @proc_open(
            [$binary, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL', $script],
            $descriptors,
            $pipes,
            CF_ROOT
        );
        if (!is_resource($process)) {
            return ['status' => -1, 'stdout' => '', 'stderr' => 'Could not start ' . $binary . '.'];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeout;

        while (true) {
            $read = array_values(array_filter(
                [$pipes[1], $pipes[2]],
                static fn($pipe): bool => is_resource($pipe) && !feof($pipe)
            ));
            if ($read === []) {
                break;
            }
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                proc_terminate($process);
                $stderr .= "\nThe check did not finish within " . $timeout . ' seconds.';
                break;
            }

            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, (int)min(5, max(1, $left))) === false) {
                break;
            }
            foreach ($read as $pipe) {
                $chunk = fread($pipe, 8192);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                if ($pipe === $pipes[1]) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }
        }

        foreach ([$pipes[1], $pipes[2]] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** proc_open is the first thing a shared host turns off. */
    private static function canSpawn(): bool
    {
        if (!function_exists('proc_open') || !function_exists('proc_close')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));

        return !in_array('proc_open', $disabled, true);
    }

    /**
     * The PHP command-line binary, if this process can name one.
     *
     * Under mod_php, PHP_BINARY is the web server executable. Running the web
     * server with a script argument would be an unusually memorable way to break
     * an update, so anything that is not recognisably a `php` binary is refused
     * and the deep check is skipped instead.
     */
    private static function phpBinary(): string
    {
        $binary = (string)PHP_BINARY;
        if ($binary === '' || !is_file($binary)) {
            return '';
        }
        $name = strtolower(basename($binary));

        return preg_match('/^php(\d+(\.\d+)?)?(\.exe)?$/', $name) === 1 ? $binary : '';
    }

    /** CF_VERSION as it stands in the installed bootstrap, read as text. */
    private static function installedVersion(): string
    {
        $source = @file_get_contents(CF_ROOT . '/src/bootstrap.php');
        if ($source === false) {
            return '';
        }
        // Read rather than required: the constant is already defined in this
        // process and cannot be redefined, so the file has to be inspected.
        if (preg_match('/const\s+CF_VERSION\s*=\s*[\'"]([^\'"]+)[\'"]/', $source, $m) === 1) {
            return trim($m[1]);
        }

        return '';
    }

    /* -------------------------------------------------------------- manifest */

    /**
     * The list of files the installed release shipped.
     *
     * @return array<int,string>|null null when nothing has been recorded yet
     */
    private static function readManifest(): ?array
    {
        try {
            $data = Json::read(self::manifestFile());
        } catch (Throwable) {
            return null;
        }
        if (!is_array($data) || !isset($data['files']) || !is_array($data['files'])) {
            return null;
        }

        return array_values(array_map('strval', $data['files']));
    }

    /** @param array<int,string> $files */
    private static function writeManifest(array $files, Release $release): void
    {
        Json::write(self::manifestFile(), [
            'version' => $release->version,
            'tag' => $release->tag,
            'installed_at' => time(),
            'files' => array_values($files),
        ]);
    }

    private static function manifestFile(): string
    {
        return self::stagingRoot() . '/installed.json';
    }

    /* --------------------------------------------------------------- helpers */

    /**
     * Loads every class the update still needs.
     *
     * Autoloading is lazy, and half way through a swap the files behind it have
     * changed. Referencing them here, while the installation is still whole,
     * means the rest of the update runs on one consistent set of classes.
     */
    private static function warmUp(): void
    {
        foreach ([
            Archive::class, Audit::class, Config::class, Db::class, GitHub::class,
            HttpException::class, Json::class, Lock::class, Meta::class, Release::class,
            Runtime::class, Settings::class, Text::class,
        ] as $class) {
            class_exists($class);
        }
    }

    /**
     * Drops every cache that still believes in the old release.
     *
     * opcache_reset() invalidates the compiled copies of files that no longer
     * exist in the form they were compiled from. The request doing the resetting
     * keeps the code it has already compiled, which is the only reason it can
     * carry on and write its own history row.
     *
     * @param callable(string):void $log
     */
    private static function refresh(callable $log): void
    {
        // The return value is what decides the log line: opcache_reset() exists
        // whenever the extension is loaded and answers false when it is loaded
        // but switched off, so calling it is also the test for it.
        if (function_exists('opcache_reset') && @opcache_reset()) {
            $log('The opcode cache was reset.');
        }
        Config::flush();
    }

    /**
     * Deletes the oldest backups beyond the configured count.
     *
     * It also clears out any backup left as a directory of files by a version
     * of CourseForge that wrote them that way. Those are exactly the loose,
     * executable second copy of the application this class now goes out of its
     * way not to create, and nothing can reach them: a rollback only ever
     * restores the newest backup, which from now on is an archive. Leaving them
     * would mean the change closed the hole for new backups and not for the old
     * ones already on the disk.
     *
     * @param callable(string):void $log
     */
    private static function prune(callable $log): void
    {
        $root = self::backupRoot();
        $legacy = 0;
        foreach ((array)@scandir($root) as $entry) {
            // scandir() answers false for a directory that is not there, which
            // casts to a single empty name - and an empty name here would ask
            // for the whole backup root to be deleted.
            $entry = (string)$entry;
            if ($entry === '' || $entry === '.' || $entry === '..' || !is_dir($root . '/' . $entry)) {
                continue;
            }
            if (Archive::remove($root . '/' . $entry)) {
                $legacy++;
            }
        }
        if ($legacy > 0) {
            $log($legacy . ' backup(s) left as directories of loose files by an earlier version were deleted. '
                . 'Backups are archives now, so nothing in one can be executed by the web server.');
        }

        $keep = max(0, (int)self::setting('updates.keep_backups'));
        $backups = self::backups();

        if (count($backups) <= $keep) {
            return;
        }

        $removed = 0;
        foreach (array_slice($backups, $keep) as $backup) {
            if (Archive::remove((string)$backup['path'])) {
                $removed++;
            }
        }

        $log($keep === 0
            ? 'updates.keep_backups is 0, so the ' . $removed . ' backup(s) on disk - including the one just taken - '
                . 'have been deleted. This update cannot be rolled back from the application.'
            : $removed . ' old backup(s) removed; ' . $keep . ' kept.');
    }

    /**
     * True for anything an update must not write to or delete.
     *
     * The data directory is checked by path as well as by name, because
     * COURSEFORGE_DATA_DIR can put it anywhere - including somewhere inside the
     * install root under a different name.
     */
    private static function isProtected(string $relative): bool
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || str_contains($relative, '../')) {
            return true;
        }

        foreach (self::PROTECTED as $guard) {
            if ($relative === $guard || str_starts_with($relative, $guard . '/')) {
                return true;
            }
        }

        $target = Archive::comparable(CF_ROOT . '/' . $relative);
        $data = Archive::comparable(CF_DATA);

        return $target === $data || str_starts_with($target, $data . '/');
    }

    /**
     * Pushes the install lease out in front of the phase about to start.
     *
     * The lease is what stops a second administrator, or a cron tick, beginning
     * a concurrent swap over the same tree. Taken once at the top it would be
     * spent by a slow download, so it is renewed here instead, at each boundary
     * between one phase of the update and the next.
     *
     * A renewal that fails means the lease had already lapsed and somebody else
     * holds it now. Before the first file is replaced that is a reason to stop,
     * and $mayStop says so. After it, stopping is worse than finishing - the
     * installation is mid-swap and this is the process that can put it right -
     * so the loss is written into the log and the update carries on.
     *
     * @param callable(string):void $log
     */
    private static function renewLease(string $owner, callable $log, bool $mayStop): void
    {
        if (Lock::renew(self::LOCK, self::LOCK_SECONDS, $owner)) {
            return;
        }

        $message = 'The install lease ran out and another process has taken it.';
        if ($mayStop) {
            throw new RuntimeException($message . ' Stopping now, before anything on disk is changed.');
        }
        $log('WARNING: ' . $message . ' This update is past the point where stopping would help, so it is '
            . 'finishing. Check the history for a second update running alongside this one.');
    }

    private static function assertLooksLikeCourseForge(string $root): void
    {
        foreach (self::SIGNATURE as $required) {
            if (!is_file($root . '/' . $required)) {
                throw new RuntimeException(
                    'The downloaded archive does not look like CourseForge - it has no ' . $required
                    . '. Nothing has been changed.'
                );
            }
        }
    }

    /**
     * Whether PHP can really write here, by writing.
     *
     * is_writable() reads permission bits and believes them, which is wrong
     * often enough to matter: an immutable flag, a read-only mount, a SELinux
     * context, a Windows ACL. The one reliable test is to write a file.
     *
     * The second element is the precondition's detail line, which is read on the
     * Updates screen and nowhere else - hence Text::path. The probe itself is
     * still built from $directory as it was handed in.
     *
     * @return array{0:bool,1:string}
     */
    private static function probeWritable(string $directory): array
    {
        if (!is_dir($directory)) {
            return [false, Text::path($directory) . ' does not exist.'];
        }

        $probe = rtrim($directory, '/\\') . '/.cf-write-probe-' . bin2hex(random_bytes(4));
        if (@file_put_contents($probe, 'cf') === false) {
            return [false, 'PHP cannot create files in ' . Text::path($directory) . '.'];
        }
        @unlink($probe);

        return [true, Text::path($directory)];
    }

    /** @return array{key:string,label:string,ok:bool,blocking:bool,detail:string} */
    private static function check(string $key, string $label, bool $ok, bool $blocking, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'ok' => $ok, 'blocking' => $blocking, 'detail' => $detail];
    }

    /** Generation runs that have not finished, whoever started them. */
    private static function activeRuns(): int
    {
        try {
            $row = Db::row("SELECT COUNT(*) AS n FROM batch_jobs WHERE status NOT IN ('completed', 'failed', 'canceled')");

            return (int)($row['n'] ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    private static function stagingRoot(): string
    {
        return CF_DATA . '/updates';
    }

    private static function backupRoot(): string
    {
        return CF_DATA . '/backups';
    }

    private static function backupName(string $fromVersion): string
    {
        $version = preg_replace('/[^A-Za-z0-9._-]+/', '-', $fromVersion) ?: 'unknown';

        return $version . '-' . gmdate('Ymd-His');
    }

    /**
     * Where the backup of the version being replaced is written.
     *
     * The random tail is not decoration. Storing the backup as a zip closes the
     * hole that a tree of .php files under `data/` opened, but it puts a new
     * extension there, and the root .htaccess fallback that runs on a host
     * without mod_rewrite names sqlite, json, md, log, ini and txt - not zip.
     * A name nobody can guess means that on such a host the archive still
     * cannot be fetched, which matters to anyone running a private fork.
     */
    private static function backupFile(string $fromVersion): string
    {
        return self::backupRoot() . '/' . self::backupName($fromVersion) . '-' . bin2hex(random_bytes(4)) . '.zip';
    }

    /** Where an audit line came from, in the vocabulary Audit already uses. */
    private static function source(string $trigger): string
    {
        return match ($trigger) {
            'schedule' => 'cron',
            'cli' => 'cli',
            default => 'web',
        };
    }

    private static function timezone(): DateTimeZone
    {
        $name = trim((string)self::setting('updates.timezone'));
        try {
            return new DateTimeZone($name !== '' ? $name : 'UTC');
        } catch (Throwable) {
            // A time zone that PHP does not know cannot stop the scheduler; the
            // Settings screen refuses one on the way in, so this is only
            // reachable through a hand-edited config file.
            return new DateTimeZone('UTC');
        }
    }

    /** As GitHub::setting(): the catalogue is what an unconfigured install is read against. */
    private static function setting(string $key): mixed
    {
        $field = Settings::field($key);

        return Config::get($key, $field['default'] ?? null);
    }

    /* --------------------------------------------------------------- history */

    private static function begin(string $from, string $to, string $channel, string $trigger, string $actor): int
    {
        // "trigger" is a reserved word in SQLite, so it is quoted wherever it
        // appears in a statement.
        Db::run(
            'INSERT INTO update_history (started_at, from_version, to_version, channel, status, "trigger", actor)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [time(), $from, $to, $channel, 'running', $trigger, $actor]
        );

        return Db::lastId();
    }

    /**
     * @param array<int,string> $lines
     * @return array<string,mixed> the finished row
     */
    private static function finish(int $id, string $status, array $lines, string $error, string $backupPath): array
    {
        $log = implode("\n", $lines);

        Db::run(
            'UPDATE update_history SET finished_at = ?, status = ?, log = ?, error = ?, backup_path = ? WHERE id = ?',
            [time(), $status, $log, mb_substr($error, 0, 2000), $backupPath, $id]
        );

        // The caller hands this row straight back to the screen, so it goes
        // through the same respelling as a row read from history().
        $row = Db::row('SELECT * FROM update_history WHERE id = ?', [$id]);

        return $row !== null ? self::readable($row) : [
            'id' => $id,
            'status' => $status,
            'log' => $log,
            'error' => $error,
        ];
    }
}
