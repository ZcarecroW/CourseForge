<?php
declare(strict_types=1);

/**
 * CourseForge 4 - the self-update system, on the command line.
 *
 *   php tools/update.php --status
 *   php tools/update.php --check
 *   php tools/update.php --install
 *   php tools/update.php --rollback
 *
 * The same work the Updates screen does, for a host that gives you a real
 * crontab. An unattended nightly update through the scheduler needs nothing
 * here - tools/cron.php already drives it - but a machine you have a shell on
 * is a much better place to run an update from than a browser tab that can be
 * closed half way through, and this is also the only way in if the web
 * application has stopped answering.
 *
 * Nothing is interactive and nothing asks for confirmation, because the point
 * of it is to be runnable from a scheduler. --install installs.
 */

if (PHP_SAPI !== 'cli') {
    exit("This tool is for the command line only.\n");
}

require __DIR__ . '/../src/bootstrap.php';

use CourseForge\Update\Updater;

$options = getopt('', ['status', 'check', 'install', 'rollback', 'quiet', 'help']);

if ($options === false || isset($options['help']) || $options === []) {
    echo "Usage: php tools/update.php [--status | --check | --install | --rollback] [--quiet]\n\n"
        . "  --status    what is installed, what is available, and whether an update could run\n"
        . "  --check     ask GitHub now, ignoring the cached answer\n"
        . "  --install   install the newest release on the configured channel\n"
        . "  --rollback  restore the most recent backup\n"
        . "  --quiet     print only what went wrong\n\n"
        . "Settings live under Updates in the application, or in data/config.json.\n";
    exit(isset($options['help']) ? 0 : 2);
}

$quiet = isset($options['quiet']);

/** Prints unless --quiet was given. Anything that matters goes to say() instead. */
$tell = static function (string $line = '') use ($quiet): void {
    if (!$quiet) {
        echo $line . "\n";
    }
};
$say = static function (string $line = ''): void {
    echo $line . "\n";
};
$fail = static function (string $line): void {
    fwrite(STDERR, $line . "\n");
};

try {
    if (isset($options['status']) || isset($options['check'])) {
        $force = isset($options['check']);
        $status = Updater::status($force);

        $tell('');
        $tell('CourseForge ' . $status['version'] . ' - update status');
        $tell(str_repeat('-', 72));
        $tell('');
        $tell('  Repository   ' . ($status['repository'] !== '' ? $status['repository'] : '(none configured)'));
        $tell('  Channel      ' . $status['channel']);
        $tell('  Last answer  ' . ($status['checked_at'] > 0
            ? gmdate('Y-m-d H:i', (int)$status['checked_at']) . ' UTC' . ($status['cached'] ? ' (cached)' : ' (just now)')
            : 'GitHub has never answered'));
        // Only worth a line when the two differ, which is exactly when the last
        // attempt did not get an answer.
        if ($status['error'] !== '' && (int)$status['attempted_at'] > (int)$status['checked_at']) {
            $tell('  Last tried   ' . gmdate('Y-m-d H:i', (int)$status['attempted_at']) . ' UTC, unsuccessfully');
        }

        $latest = $status['latest'];
        if (is_array($latest)) {
            $tell('  Latest       ' . $latest['version'] . ' - ' . $latest['name']
                . ($latest['prerelease'] ? ' [pre-release]' : ''));
            $tell('  Published    ' . ((int)$latest['published_at'] > 0 ? gmdate('Y-m-d', (int)$latest['published_at']) : 'unknown'));
            $tell('  Archive      ' . ($latest['asset_name'] !== '' ? $latest['asset_name'] : 'GitHub zipball (no asset published)'));
        } else {
            $tell('  Latest       nothing has been read from GitHub yet');
        }

        $tell('');
        $tell($status['available'] ? '  An update is available.' : '  This installation is up to date.');

        if ($status['error'] !== '') {
            $fail('  ' . $status['error']);
        }

        $tell('');
        $tell('Preconditions');
        foreach ($status['preconditions'] as $check) {
            $mark = $check['ok'] ? ' OK ' : ($check['blocking'] ? 'FAIL' : 'WARN');
            $tell(sprintf('  [%s]  %-38s %s', $mark, $check['label'], $check['detail']));
        }

        $schedule = $status['schedule'];
        $tell('');
        $tell('Unattended');
        $tell('  Check daily      ' . ($schedule['auto_check'] ? 'yes' : 'no'));
        $tell('  Install daily    ' . ($schedule['auto_install']
            ? 'yes, at ' . $schedule['time'] . ' ' . $schedule['timezone']
            : 'no'));
        if ($schedule['auto_install'] && (int)$schedule['next_install_at'] > 0) {
            $tell('  Next slot        ' . gmdate('Y-m-d H:i', (int)$schedule['next_install_at']) . ' UTC');
        }

        $tell('');
        $tell('Backups');
        if ($status['backups'] === []) {
            $tell('  none - a rollback would have nothing to restore');
        } else {
            foreach ($status['backups'] as $backup) {
                $tell('  ' . $backup['name'] . '  (' . $backup['from_version'] . ', '
                    . $backup['files'] . ' file(s), ' . gmdate('Y-m-d H:i', (int)$backup['created_at']) . ' UTC)');
            }
        }
        $tell('');

        // A failed check is worth a non-zero exit; being up to date is not.
        exit($status['error'] === '' ? 0 : 1);
    }

    if (isset($options['install']) || isset($options['rollback'])) {
        $rollback = isset($options['rollback']);

        $tell('');
        $tell($rollback ? 'Restoring the most recent backup...' : 'Installing the newest release...');
        $tell('');

        $result = $rollback
            ? Updater::rollback('cli', 'cli')
            : Updater::install('cli', 'cli');

        $row = $result['history'];
        $log = (string)$result['log'];

        // The log is the whole point of running this by hand, so it is printed
        // even with --quiet when something went wrong.
        if (!$quiet || !$result['ok']) {
            foreach (explode("\n", $log) as $line) {
                ($result['ok'] ? $say : $fail)('  ' . $line);
            }
        }

        $say('');
        $say(sprintf(
            '  %s: %s -> %s  [%s]',
            $rollback ? 'Rollback' : 'Update',
            (string)$row['from_version'],
            (string)$row['to_version'],
            (string)$row['status']
        ));
        if ((string)$row['error'] !== '') {
            $fail('  ' . (string)$row['error']);
        }
        if ((string)$row['backup_path'] !== '') {
            $tell('  Backup: ' . (string)$row['backup_path']);
        }
        $say('');

        exit($result['ok'] ? 0 : 1);
    }
} catch (Throwable $e) {
    // Everything that never started arrives here: a failed precondition, a lock
    // held by the web application, a repository nobody can reach.
    $fail('  ' . $e->getMessage());
    exit(1);
}

$fail('Nothing to do. Try --help.');
exit(2);
