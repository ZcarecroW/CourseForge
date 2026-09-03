<?php
declare(strict_types=1);

namespace CourseForge\Support;

use CourseForge\Ai\Run\RunManager;
use CourseForge\Domain\Details;
use CourseForge\Security\Invite;
use CourseForge\Security\Users;
use Throwable;

/**
 * The installation check, as data rather than as printed lines.
 *
 * CourseForge 3.x had one installation check and it lived inside
 * tools/diagnose.php, which meant it could only ever be read by somebody with a
 * shell on the server. That is the wrong audience: the person who most needs to
 * be told that the data directory is read-only, or that the scheduler has never
 * ticked, is the administrator sitting in front of the application, and on a
 * shared host that person has no shell at all.
 *
 * So the checks moved here and return structured rows - key, label, status,
 * detail, hint - and the command-line tool became a printer over them. The
 * Settings screen can render the same rows without a second implementation that
 * would slowly drift away from this one.
 *
 * Two rules hold everywhere below. Nothing here repairs what it reports: Paths
 * runs first, and when the data directory is missing or read-only every section
 * that would reach for the database is reported as unchecked rather than run,
 * because Db::pdo() creates that directory on the way past and a check that
 * heals what it has just called broken tells the administrator nothing. When
 * the directory is usable the database is opened normally, and opening it
 * applies any pending migration - that is Db::pdo()'s doing rather than this
 * class's, and it is why the schema row below reports a version this check has
 * already brought up to date.
 *
 * And nothing here may throw: a diagnostic that dies on a broken install is
 * useless precisely when it is needed, so every section catches its own trouble
 * and reports it as a failed check instead of taking the report down with it.
 */
final class Diagnostics
{
    /** The oldest PHP that can parse the 4.0 code - see phpFloor() for what forces it. */
    public const PHP_FLOOR = 80100;

    /** Below this the release is supported but slower than it needs to be. */
    public const PHP_PREFERRED = 80400;

    /**
     * Every check, in the order a human wants to read them.
     *
     * @return array{sections:array<int,array{key:string,label:string,checks:array<int,array<string,string>>}>,
     *               summary:array{ok:int,warnings:int,problems:int},version:string,generated_at:int}
     */
    public static function run(): array
    {
        // Paths is computed before anything else even though it is printed
        // second. Accounts, Database, Scheduler and MCP all reach Db::pdo(),
        // which creates CF_DATA when it is absent, so the report has to know
        // whether the directory is usable before it lets any of them run.
        $paths = self::paths();
        $usable = self::dataDirUsable($paths);

        $sections = [
            self::runtime(),
            $paths,
            self::configuration(),
            $usable ? self::accounts() : self::notChecked('accounts', 'Accounts'),
            $usable ? self::database() : self::notChecked('database', 'Database'),
            $usable ? self::scheduler() : self::notChecked('scheduler', 'Scheduler'),
            self::updates(),
            $usable ? self::mcp() : self::notChecked('mcp', 'MCP endpoint'),
        ];

        return [
            'sections' => $sections,
            'summary' => self::tally($sections),
            'version' => CF_VERSION,
            'generated_at' => time(),
        ];
    }

    /* -------------------------------------------------------------- runtime */

    private static function runtime(): array
    {
        $checks = [self::phpFloor()];

        foreach (['curl', 'pdo_sqlite', 'mbstring', 'json', 'session'] as $extension) {
            $checks[] = extension_loaded($extension)
                ? self::ok('ext_' . $extension, 'Extension ' . $extension)
                : self::fail('ext_' . $extension, 'Extension ' . $extension, 'not loaded');
        }

        return self::section('runtime', 'Runtime', $checks);
    }

    /**
     * The version floor, which is a fact about the parser rather than a taste
     * for new things.
     *
     * The newest syntax anywhere in the tree is first-class callable syntax -
     * self::estimate(...) in Ai/Batch/JsonlChunker.php, $this->assertOk(...) in
     * Ai/Provider/AnthropicProvider.php - beside readonly promoted constructor
     * properties and the never return type. All three arrived in 8.1 and all
     * three are read by the parser, so an 8.0 interpreter never reaches the line
     * to refuse it politely: it rejects the whole file and serves a blank page.
     *
     * Nothing goes beyond that. There are no readonly classes, no typed class
     * constants, no #[\Override] and no json_validate(), which are what 8.2 and
     * 8.3 would buy; array_is_list(), which an earlier version of this check
     * named as the reason for an 8.3 floor, has been there since 8.0.
     *
     * So the floor is 8.1, which is what README.md and docs.md already promise.
     */
    private static function phpFloor(): array
    {
        if (PHP_VERSION_ID < self::PHP_FLOOR) {
            return self::fail(
                'php_version',
                'PHP version',
                PHP_VERSION . ' - 8.1 or newer is required',
                'Readonly promoted properties, the never return type and first-class callable syntax are all '
                . '8.1 and all three are read by the parser, so an older interpreter rejects the files rather '
                . 'than failing at the point of use.'
            );
        }
        if (PHP_VERSION_ID < self::PHP_PREFERRED) {
            return self::ok('php_version', 'PHP version', PHP_VERSION . ' - supported; 8.4 or 8.5 is faster');
        }
        return self::ok('php_version', 'PHP version', PHP_VERSION);
    }

    /* ---------------------------------------------------------------- paths */

    private static function paths(): array
    {
        $checks = [];

        // Every path below is printed, never opened, so each one goes through
        // Text::path first - CF_DATA is built by appending "/data" to a constant
        // that on Windows already carries backslashes, and the report is read by
        // somebody who has to recognise the directory.
        $present = self::isDir(CF_DATA);
        $checks[] = $present
            ? self::ok('data_dir', 'Data directory', Text::path(CF_DATA))
            : self::fail(
                'data_dir',
                'Data directory',
                Text::path(CF_DATA) . ' does not exist',
                'The application creates it on first run when the parent directory allows that. Create it by '
                . 'hand, or point COURSEFORGE_DATA_DIR somewhere PHP can write.'
            );

        // One broken condition, one row. A directory that is absent is not also
        // a permissions problem, and saying so twice would double its weight in
        // the count the exit code is built on - as well as naming the wrong
        // fault, since nobody can grant write access to a path that is not
        // there. The rows below are about the directory, so they wait for it.
        if ($present) {
            $checks[] = self::isWritable(CF_DATA)
                ? self::ok('data_writable', 'Data directory writable')
                : self::fail('data_writable', 'Data directory writable', 'PHP cannot write to ' . Text::path(CF_DATA));

            // Only worth saying when the data directory sits under the document
            // root, which is the default because it is the only thing that
            // works without shell access.
            //
            // What can be established from a shell is that the file is there.
            // Whether a request for /data/app.sqlite is actually refused cannot
            // be: there is no request to make and no way to know which server
            // will serve these files. nginx, Caddy and IIS read no .htaccess at
            // all, so the detail says what was found rather than what it means,
            // and leaves the conclusion to the one person who can reach the URL.
            if (str_starts_with(self::normalise(CF_DATA), self::normalise(CF_ROOT) . '/')) {
                // CourseForge writes this file itself whenever it finds it
                // missing, on the way to opening the database. So an absent one
                // no longer means the release copy failed to arrive - it means
                // PHP tried to write it and could not, which is a permissions
                // problem on the one directory that must not be readable, and
                // is worth failing rather than warning about.
                $checks[] = self::isFile(CF_DATA . '/.htaccess')
                    ? self::ok(
                        'data_private',
                        'data/.htaccess',
                        'present - Apache only; fetch /data/app.sqlite yourself and confirm you are refused'
                    )
                    : self::fail(
                        'data_private',
                        'data/.htaccess',
                        'missing, and CourseForge could not write it',
                        'app.sqlite holds every password hash, and the directory holding it is under the '
                        . 'document root with nothing refusing requests for it. CourseForge writes this file '
                        . 'by itself when it is absent, so its absence means PHP was refused permission. Give '
                        . 'PHP write access to ' . Text::path(CF_DATA) . ', or move the directory out of the '
                        . 'document root with COURSEFORGE_DATA_DIR and stop needing it.'
                    );
            }
        }

        return self::section('paths', 'Paths', $checks);
    }

    /**
     * Whether Paths found a data directory the rest of the report can use.
     *
     * Read back off the rows rather than probed again, so the gate and the
     * report can never disagree about what was found: a missing data_writable
     * row - which is what an absent directory produces - is as good as a failed
     * one.
     *
     * @param array{checks:array<int,array<string,string>>} $paths
     */
    private static function dataDirUsable(array $paths): bool
    {
        $status = [];
        foreach ($paths['checks'] as $check) {
            $status[$check['key']] = $check['status'];
        }

        return ($status['data_dir'] ?? '') === 'ok' && ($status['data_writable'] ?? '') === 'ok';
    }

    /**
     * A section that was not run, because running it would have repaired it.
     *
     * Db::pdo() creates CF_DATA when it is missing and then migrates, so any
     * section that reads the database would quietly undo the fault Paths has
     * just reported and leave the report contradicting itself - a data
     * directory that does not exist, and beneath it a full set of healthy
     * tables. One failed row per skipped section says so plainly.
     */
    private static function notChecked(string $key, string $label): array
    {
        return self::section($key, $label, [self::fail(
            $key,
            $label,
            'not checked - the data directory is unusable',
            'Every check here opens the database, and opening it creates the data directory when that is '
            . 'what is missing. Fix what the Paths section reports, then run this again.'
        )]);
    }

    /* -------------------------------------------------------- configuration */

    private static function configuration(): array
    {
        $checks = [];

        try {
            $defaults = Config::defaultsFile();
            $checks[] = self::isFile($defaults) && self::isReadable($defaults)
                ? self::ok('defaults_file', 'config/defaults.json', number_format(filesize($defaults) / 1024, 0) . ' KB')
                : self::fail(
                    'defaults_file',
                    'config/defaults.json',
                    'missing or unreadable at ' . Text::path($defaults),
                    'This file ships with the release. Restore it from the archive you installed from.'
                );

            // The override file is allowed not to exist yet - that is what a
            // fresh installation looks like - but the directory has to accept
            // one, otherwise every Settings screen is read-only in practice.
            $overrideFile = Config::file();
            $overrideDir = dirname($overrideFile);
            $writable = self::isFile($overrideFile) ? self::isWritable($overrideFile) : self::isWritable($overrideDir);

            if (!self::isDir($overrideDir)) {
                // Not "PHP cannot write it", which is what testing writability
                // on an absent directory would have this row say. There is
                // nothing to write into, Paths has already reported why, and a
                // second copy of the fault with the wrong cause attached is
                // worse than a second copy.
                $checks[] = self::fail(
                    'config_writable',
                    'data/config.json',
                    'not checked - ' . Text::path($overrideDir) . ' does not exist',
                    'See the Paths section. Nothing can be configured from the screens until the data '
                    . 'directory is there.'
                );
            } else {
                $checks[] = $writable
                    ? self::ok(
                        'config_writable',
                        'data/config.json',
                        self::isFile($overrideFile) ? 'writable' : 'not created yet, and the directory accepts one'
                    )
                    : self::fail(
                        'config_writable',
                        'data/config.json',
                        'PHP cannot write ' . Text::path($overrideFile),
                        'Settings changed in the application are stored here. Without it nothing can be '
                        . 'configured from the screens.'
                    );
            }

            $overrides = self::leaves(Config::overrides());
            $checks[] = self::ok(
                'config_overrides',
                'Overrides',
                $overrides === 0 ? 'none - every setting is at its default' : $overrides . ' setting(s) changed'
            );

            $checks[] = self::ok('app_name', 'Installation name', 'app.name = "' . Config::str('app.name', 'CourseForge') . '"');

            $slots = Config::promptSlots();
            $checks[] = count($slots) > 0
                ? self::ok('prompts', 'Prompt library', count($slots) . ' slot(s) in ' . count(Config::promptGroups()) . ' group(s)')
                : self::fail('prompts', 'Prompt library', 'no prompts defined');

            $catalogue = Details::catalogue();
            $checks[] = count($catalogue['features']) > 0
                ? self::ok(
                    'details',
                    'Detail catalogue',
                    count($catalogue['features']) . ' feature(s), ' . count($catalogue['params']) . ' value(s)'
                )
                : self::fail('details', 'Detail catalogue', 'no features defined');

            // Every content feature needs both halves of its prompt pair, or a
            // course that switches the feature off says nothing about it at all.
            foreach (array_keys($catalogue['features']) as $feature) {
                foreach (['on', 'off'] as $state) {
                    $slot = 'feature_' . $feature . '_' . $state;
                    if (!isset($slots[$slot])) {
                        $checks[] = self::warn('prompt_' . $slot, 'Prompt for ' . $feature, $slot . ' is missing');
                    }
                }
            }

            $checks[] = Config::bool('app.debug')
                ? self::warn(
                    'debug',
                    'Debug mode',
                    'app.debug is TRUE - exception details are exposed in API responses',
                    'Turn it off on anything reachable from the internet.'
                )
                : self::ok('debug', 'Debug mode', 'off');
        } catch (Throwable $e) {
            $checks[] = self::fail('configuration', 'Configuration', $e->getMessage());
        }

        return self::section('configuration', 'Configuration', $checks);
    }

    /* ------------------------------------------------------------- accounts */

    private static function accounts(): array
    {
        $checks = [];

        try {
            // Asked first, and the counts read after it, because needsSetup()
            // is not a question: on an installation with an empty users table
            // it imports data/users.json, and a count taken before that can be
            // three accounts out of date by the time it is printed.
            $pending = Users::needsSetup();
            $total = Users::count();
            $admins = Users::adminCount();

            $checks[] = $total > 0
                ? self::ok('accounts', 'Accounts', $total . ' account(s)')
                : self::warn(
                    'accounts',
                    'Accounts',
                    'none yet',
                    'Open the application in a browser: it asks for the invite code and creates the first '
                    . 'administrator.'
                );

            if ($total > 0 && $admins === 0) {
                $checks[] = self::fail(
                    'administrators',
                    'Administrators',
                    'none - nobody can reach the admin screens',
                    'Every administrator has been demoted or disabled. This can only be repaired in the database.'
                );
            } elseif ($total > 0) {
                $checks[] = self::ok('administrators', 'Administrators', $admins . ' of ' . $total);
            }

            $checks[] = $pending
                ? self::warn('setup', 'Setup', 'still pending')
                : self::ok('setup', 'Setup', 'complete');

            $invite = Invite::status();
            if (!$invite['open']) {
                $checks[] = self::ok('invite', 'Invites', 'none open');
            } else {
                $lines = [];
                $places = 0;
                foreach ((array)$invite['invites'] as $row) {
                    $expires = (int)$row['expires_at'];
                    $left = (int)$row['uses_left'];
                    $places += $left;
                    $lines[] = ((string)$row['label'] !== '' ? '"' . $row['label'] . '": ' : '')
                        . ($left === 1 ? 'one' : $left) . ' more ' . $row['role'] . ' account' . ($left === 1 ? '' : 's')
                        . ((int)$row['max_uses'] > 1 ? ' (' . $row['uses'] . ' of ' . $row['max_uses'] . ' used)' : '')
                        . ', ' . ($expires > 0 ? 'until ' . gmdate('Y-m-d H:i', $expires) . ' UTC' : 'with no expiry');
                }
                $detail = count($lines) . ' open - ' . implode('; ', $lines);

                $checks[] = $pending
                    ? self::ok('invite', 'Invites', $detail)
                    : self::warn(
                        'invite',
                        'Invites',
                        $detail,
                        'Every open invite is a way to an account for whoever holds its code'
                            . ($places > 1 ? ' - ' . $places . ' accounts between them' : '')
                            . '. Revoke the ones nobody is waiting for under Administration › Accounts.'
                    );
            }

            // The file matters only for the invite that was written to one:
            // the first-run invite. Invites issued from the application are
            // shown once and never touch the disk.
            $fileInvite = false;
            foreach ((array)($invite['invites'] ?? []) as $row) {
                if ((string)$row['path'] !== '') {
                    $fileInvite = true;
                }
            }
            $checks = array_merge($checks, self::inviteFile($pending, $fileInvite));

            // A file still sitting here means one of two different things, and
            // the difference is worth stating rather than leaving somebody to
            // work out. Users::needsSetup() imports it when the users table is
            // empty and renames it to users.json.imported on success - which
            // has already been tried a few lines above, since $pending is what
            // calls it. So on an installation with no accounts the file being
            // here is the import having found nothing usable in it; on one with
            // accounts the import was never offered the file at all. Either
            // way what is left is a password hash in the data directory.
            if (self::isFile(CF_DATA . '/users.json')) {
                $checks[] = self::warn(
                    'legacy_users',
                    'Legacy users.json',
                    'still present in ' . Text::path(CF_DATA),
                    $total > 0
                        ? 'It is ignored now that accounts exist - importing only happens while there are none. '
                        . 'Delete it: it holds a password hash.'
                        : 'It was offered to the CourseForge 3 import just now and produced no account, so it is '
                        . 'either unreadable or holds nothing with a user name and a password hash in it. Create '
                        . 'the first administrator with the invite code instead, then delete the file.'
                );
            }
        } catch (Throwable $e) {
            $checks[] = self::fail('accounts', 'Accounts', $e->getMessage());
        }

        return self::section('accounts', 'Accounts', $checks);
    }

    /**
     * INVITE-CODE.txt, and whether a stranger can read it.
     *
     * Whether it is actually reachable over HTTP cannot be answered from the
     * command line - there is no request, no server, and no way to know which
     * server will eventually serve these files. So this checks the one thing it
     * can, that the shipped .htaccess refuses .txt, and then says plainly that
     * .htaccess is an Apache file: nginx, Caddy and IIS read none of it, and on
     * those the code is a public URL until the administrator says otherwise.
     *
     * @return array<int,array<string,string>>
     */
    private static function inviteFile(bool $pending, bool $open): array
    {
        $checks = [];

        // Both places Invite::write() will try, because which one it used
        // depends on what was writable at the time.
        $candidates = [CF_ROOT . '/' . Invite::FILE, CF_DATA . '/' . Invite::FILE];
        $present = array_values(array_filter($candidates, static fn(string $p): bool => self::isFile($p)));
        // $present is what the checks below are made on; $where is what is read,
        // so only the second one is respelled.
        $where = implode(', ', array_map(static fn(string $p): string => Text::path($p), $present));

        if ($present === [] && !$open) {
            $checks[] = self::ok('invite_file', Invite::FILE, 'not on disk');
        } elseif ($present === [] && $pending) {
            $checks[] = self::warn(
                'invite_file',
                Invite::FILE,
                'not written yet',
                'It is written the first time the application is opened, next to index.html if that is '
                . 'writable and in data/ if it is not.'
            );
        } elseif ($present === []) {
            $checks[] = self::warn(
                'invite_file',
                Invite::FILE,
                'gone, but an invite is still open',
                'The plain code only ever existed in that file, so nobody can use the invite now. Issue a new '
                . 'one from Settings.'
            );
        } elseif (!$open) {
            $checks[] = self::warn(
                'invite_file',
                Invite::FILE,
                $where . ' - no invite is open',
                'The code in it has been spent or superseded, so it creates nothing. Delete the file.'
            );
        } else {
            $checks[] = $pending
                ? self::ok('invite_file', Invite::FILE, $where)
                : self::warn(
                    'invite_file',
                    Invite::FILE,
                    $where,
                    'Setup is done, so this file is only a liability. Delete it once the invite has been used.'
                );
        }

        $htaccess = self::readIfPossible(CF_ROOT . '/.htaccess');
        $denied = self::htaccessDeniesTxt($htaccess);

        if ($htaccess === '') {
            // No .htaccess is the normal state of an nginx, Caddy or IIS
            // install, where the file would be dead weight, so this cannot be a
            // failure - it was one, and it was a failure nobody on those
            // servers could ever clear. It says instead that nothing was
            // established, which is the truth.
            $checks[] = self::warn(
                'htaccess_txt',
                '.htaccess refuses .txt',
                'no .htaccess in the installation root - nothing to read',
                'On Apache, restore the shipped file: without it INVITE-CODE.txt, the log and every .json '
                . 'file are ordinary downloads. On nginx, Caddy or IIS the same refusal has to be written '
                . 'into the server configuration instead.'
            );
        } else {
            $checks[] = $denied
                ? self::ok('htaccess_txt', '.htaccess refuses .txt', 'yes')
                : self::fail(
                    'htaccess_txt',
                    '.htaccess refuses .txt',
                    'no rule found',
                    'Restore the shipped .htaccess. Without it INVITE-CODE.txt, the log and every .json file '
                    . 'are ordinary downloads.'
                );
        }

        // Only worth saying when there is something to expose.
        if ($present !== []) {
            $checks[] = self::warn(
                'invite_http',
                'Invite over HTTP',
                $denied ? 'refused by .htaccess - Apache only' : 'nothing is refusing it',
                'nginx, Caddy and IIS ignore .htaccess entirely. Fetch /' . Invite::FILE . ' yourself and check '
                . 'you are refused before you trust this.'
            );
        }

        return $checks;
    }

    /**
     * Whether the root .htaccess has a block that refuses .txt.
     *
     * The deny has to be found inside the block that names .txt, not merely
     * somewhere in the same file. Two independent searches over the whole text
     * would read a FilesMatch block that grants .txt, plus an unrelated
     * "Require all denied" further down, as a file that protects the invite
     * code - which is the one wrong answer this check must not give.
     *
     * Both spellings count: mod_authz_core says "Require all denied" and the
     * shipped file also carries the 2.2 form for hosts without it.
     */
    private static function htaccessDeniesTxt(string $htaccess): bool
    {
        if ($htaccess === '') {
            return false;
        }

        $blocks = [];
        if (preg_match_all('#<FilesMatch\s([^>]*)>(.*?)</FilesMatch>#is', $htaccess, $blocks, PREG_SET_ORDER) < 1) {
            return false;
        }

        foreach ($blocks as $block) {
            if (preg_match('/\btxt\b/i', $block[1]) === 1
                && preg_match('/Require\s+all\s+denied|Deny\s+from\s+all/i', $block[2]) === 1) {
                return true;
            }
        }

        return false;
    }

    /* ------------------------------------------------------------- database */

    private static function database(): array
    {
        $checks = [];

        try {
            Db::pdo();
            $checks[] = self::ok('sqlite', 'SQLite database', Text::path(Db::file()));

            // Read after the migration rather than before it, because there is
            // no before: Db::pdo() migrates as it opens, so by the time any
            // section can ask, the answer has already been brought up to date.
            // What that leaves is worth reporting anyway, and it is the case a
            // rollback produces - a database written by a newer release, which
            // migrate() will not lower and this release cannot read properly.
            $version = (int)(Db::row('SELECT value FROM meta WHERE key = ?', ['schema_version'])['value'] ?? 0);
            if ($version === Db::SCHEMA_VERSION) {
                $checks[] = self::ok('schema', 'Schema version', (string)$version);
            } elseif ($version > Db::SCHEMA_VERSION) {
                $checks[] = self::warn(
                    'schema',
                    'Schema version',
                    $version . ', newer than the ' . Db::SCHEMA_VERSION . ' this release writes',
                    'A newer CourseForge has already migrated this database and nothing here will lower it '
                    . 'again. Put the newer release back, or restore the backup taken before the downgrade.'
                );
            } else {
                $checks[] = self::warn(
                    'schema',
                    'Schema version',
                    $version . ' (expected ' . Db::SCHEMA_VERSION . ')',
                    'The migration has already run inside this check and did not raise the version. The log '
                    . 'will say why.'
                );
            }

            $tables = [
                'projects', 'chapters', 'pages', 'tags', 'profiles',
                'batch_jobs', 'batch_items', 'mcp_clients', 'audit_log', 'update_history',
            ];
            foreach ($tables as $table) {
                try {
                    $count = (int)(Db::row('SELECT COUNT(*) AS n FROM ' . $table)['n'] ?? 0);
                    $checks[] = self::ok('table_' . $table, 'Table ' . $table, $count . ' row(s)');
                } catch (Throwable $e) {
                    $checks[] = self::fail('table_' . $table, 'Table ' . $table, $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            $checks[] = self::fail('sqlite', 'SQLite database', $e->getMessage());
        }

        return self::section('database', 'Database', $checks);
    }

    /* ------------------------------------------------------------ scheduler */

    private static function scheduler(): array
    {
        $checks = [];

        try {
            $cron = RunManager::cronStatus();
            $waiting = (int)(Db::row(
                "SELECT COUNT(*) AS n FROM batch_jobs WHERE status NOT IN ('completed','failed','canceled')"
            )['n'] ?? 0);

            if (!$cron['configured']) {
                $checks[] = $waiting > 0
                    ? self::fail('cron_token', 'cron token', 'not set, but ' . $waiting . ' run(s) are waiting for it')
                    : self::warn('cron_token', 'cron token', 'app.cron_token is empty, so background runs cannot be offered');
            } else {
                $checks[] = self::ok('cron_token', 'cron token', 'set');

                if ($cron['last_at'] === 0) {
                    $checks[] = $waiting > 0
                        ? self::fail(
                            'cron_tick',
                            'Last tick',
                            'never - ' . $waiting . ' run(s) are waiting and nothing is collecting them'
                        )
                        : self::warn('cron_tick', 'Last tick', 'never - point your host at /cron.php?token=... once a minute');
                } elseif ($cron['healthy']) {
                    $checks[] = self::ok('cron_tick', 'Last tick', self::ago((int)$cron['seconds_ago']));
                } else {
                    $checks[] = self::warn(
                        'cron_tick',
                        'Last tick',
                        self::ago((int)$cron['seconds_ago']) . ' - it is meant to run every minute'
                    );
                }
            }

            $checks[] = self::ok('runs_open', 'Runs open', $waiting === 0 ? 'none' : (string)$waiting);
            $checks[] = self::ok('cron_workers', 'Worker slots', (string)max(1, min(8, Config::int('app.cron_workers', 2))));
        } catch (Throwable $e) {
            $checks[] = self::fail('scheduler', 'Scheduler', $e->getMessage());
        }

        return self::section('scheduler', 'Scheduler', $checks);
    }

    /* -------------------------------------------------------------- updates */

    private static function updates(): array
    {
        $checks = [];

        try {
            $enabled = (bool)filter_var(self::updateSetting('updates.enabled'), FILTER_VALIDATE_BOOLEAN);
            $checks[] = $enabled
                ? self::ok(
                    'updates_enabled',
                    'Updates',
                    self::updateSettingText('updates.repository') . ', '
                    . self::updateSettingText('updates.channel') . ' channel'
                )
                : self::ok('updates_enabled', 'Updates', 'switched off - CourseForge never contacts GitHub');

            // An update replaces the release directory in place, so PHP has to
            // own it. Plenty of hosts deliberately deny that, which is a valid
            // way to run and not a fault - hence a warning, not a failure.
            $checks[] = self::isWritable(CF_ROOT)
                ? self::ok('install_writable', 'Installation writable', 'PHP can replace its own files')
                : self::warn(
                    'install_writable',
                    'Installation writable',
                    'PHP cannot write to ' . Text::path(CF_ROOT),
                    'One-click and unattended updates are unavailable. Update by uploading the release yourself.'
                );

            $checks[] = class_exists('ZipArchive')
                ? self::ok('ziparchive', 'ZipArchive', 'available')
                : self::warn(
                    'ziparchive',
                    'ZipArchive',
                    'not loaded',
                    'A downloaded release cannot be unpacked without it. Enable the zip extension.'
                );

            $checks[] = self::opcache();
        } catch (Throwable $e) {
            $checks[] = self::fail('updates', 'Updates', $e->getMessage());
        }

        return self::section('updates', 'Updates', $checks);
    }

    /**
     * An update setting, with Support\Settings' declaration behind it.
     *
     * The update settings are declared in the catalogue, which is what every
     * screen and every API call reads and what Update\GitHub::setting() reads
     * to decide which repository it actually talks to. Repeating the literals
     * here would give this check a private copy of them, and the day somebody
     * changes the catalogue is the day the report starts naming a repository
     * the updater is not using.
     */
    private static function updateSetting(string $key): mixed
    {
        return Config::get($key, Settings::field($key)['default'] ?? null);
    }

    /** The same, as something printable. */
    private static function updateSettingText(string $key): string
    {
        $value = self::updateSetting($key);

        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * opcache, which is the reason an update can appear to do nothing.
     *
     * The new files are on disk and the old ones are still being executed from
     * the cache, sometimes for hours. opcache_reset() fixes that in one call and
     * is exactly the sort of function a hardened host puts in disable_functions,
     * so the two are asked about separately. This reports the SAPI it is running
     * in, and the command line almost never has the same opcache settings as the
     * web server.
     */
    private static function opcache(): array
    {
        $status = false;
        if (function_exists('opcache_get_status')) {
            try {
                $status = @opcache_get_status(false);
            } catch (Throwable) {
                $status = false;
            }
        }
        $on = is_array($status) && ($status['opcache_enabled'] ?? false) === true;

        if (!$on) {
            return self::ok(
                'opcache',
                'opcache',
                'not enabled under ' . PHP_SAPI . ' - nothing to invalidate after an update'
            );
        }
        if (!function_exists('opcache_reset')) {
            return self::warn(
                'opcache',
                'opcache',
                'enabled, but opcache_reset() is disabled',
                'After an update the old files keep running until the pool is restarted. Remove opcache_reset '
                . 'from disable_functions, or restart PHP yourself after every update.'
            );
        }
        return self::ok('opcache', 'opcache', 'enabled under ' . PHP_SAPI . ', opcache_reset() available');
    }

    /* --------------------------------------------------------- MCP endpoint */

    private static function mcp(): array
    {
        $checks = [];

        // Two guards rather than one, because the two rows fail for unrelated
        // reasons and each is worth having without the other. A database that
        // cannot be read should not also cost the administrator the line that
        // says whether the endpoint is switched on - and it is a failure when
        // it happens, the same as everywhere else in this report. It used to be
        // a warning, which let a dead database exit 0.
        try {
            $off = Config::get('mcp.enabled') !== null && !Config::bool('mcp.enabled', true);
            $checks[] = $off
                ? self::warn('mcp_enabled', 'MCP endpoint', 'switched off in config.json - connected clients will be refused')
                : self::ok('mcp_enabled', 'MCP endpoint', 'api/mcp.php');
        } catch (Throwable $e) {
            $checks[] = self::fail('mcp_enabled', 'MCP endpoint', $e->getMessage());
        }

        try {
            $clients = (int)(Db::row('SELECT COUNT(*) AS n FROM mcp_clients')['n'] ?? 0);
            $checks[] = self::ok(
                'mcp_clients',
                'Connections',
                $clients === 0 ? 'none - create one under Connect to let Claude write courses' : $clients . ' token(s) issued'
            );
        } catch (Throwable $e) {
            $checks[] = self::fail('mcp_clients', 'Connections', $e->getMessage());
        }

        return self::section('mcp', 'MCP endpoint', $checks);
    }

    /* -------------------------------------------------------------- helpers */

    /**
     * An age in seconds, in the words the rest of CourseForge uses for it.
     *
     * A row reading "2087s ago" asks the reader to do a division, next to a
     * screen that says "29 minutes ago" everywhere else. The phrasing belongs
     * here rather than in the browser, because these rows are printed as they
     * arrive by the Settings screen and by the command-line printer over the
     * same data, and only one of those two runs JavaScript. The steps match
     * relativeTime() in assets/js/core/format.js so the two surfaces never
     * describe the same moment differently.
     */
    private static function ago(int $seconds): string
    {
        if ($seconds < 15) {
            return 'just now';
        }

        $steps = [[60, 'second', 1], [3600, 'minute', 60], [86400, 'hour', 3600], [2592000, 'day', 86400]];
        foreach ($steps as [$limit, $unit, $divisor]) {
            if ($seconds < $limit) {
                $value = intdiv($seconds, $divisor);
                return $value . ' ' . $unit . ($value === 1 ? '' : 's') . ' ago';
            }
        }

        return 'on ' . gmdate('j M Y', time() - $seconds) . ' UTC';
    }

    /** @param array<int,array<string,string>> $checks */
    private static function section(string $key, string $label, array $checks): array
    {
        return ['key' => $key, 'label' => $label, 'checks' => array_values($checks)];
    }

    /** @return array<string,string> */
    private static function check(string $key, string $label, string $status, string $detail, string $hint): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail, 'hint' => $hint];
    }

    private static function ok(string $key, string $label, string $detail = '', string $hint = ''): array
    {
        return self::check($key, $label, 'ok', $detail, $hint);
    }

    private static function warn(string $key, string $label, string $detail = '', string $hint = ''): array
    {
        return self::check($key, $label, 'warn', $detail, $hint);
    }

    private static function fail(string $key, string $label, string $detail = '', string $hint = ''): array
    {
        return self::check($key, $label, 'fail', $detail, $hint);
    }

    /** @param array<int,array{checks:array<int,array<string,string>>}> $sections */
    private static function tally(array $sections): array
    {
        $summary = ['ok' => 0, 'warnings' => 0, 'problems' => 0];
        foreach ($sections as $section) {
            foreach ($section['checks'] as $check) {
                $summary[match ($check['status']) {
                    'fail' => 'problems',
                    'warn' => 'warnings',
                    default => 'ok',
                }]++;
            }
        }
        return $summary;
    }

    /**
     * How many settings this installation has actually changed.
     *
     * A list counts as one value rather than as its members, the same way
     * Config treats it: an override of mcp.allowed_origins is one decision,
     * however many origins it names.
     *
     * @param array<string,mixed> $doc
     */
    private static function leaves(array $doc): int
    {
        $n = 0;
        foreach ($doc as $value) {
            $n += (is_array($value) && !array_is_list($value)) ? self::leaves($value) : 1;
        }
        return $n;
    }

    /**
     * The file-system probes, wrapped.
     *
     * open_basedir turns an ordinary is_dir() on a path outside the allowance
     * into a warning, and a host that promotes warnings to exceptions would
     * otherwise take the whole report down over a path that is merely absent.
     */
    private static function isDir(string $path): bool
    {
        try {
            return $path !== '' && @is_dir($path);
        } catch (Throwable) {
            return false;
        }
    }

    private static function isFile(string $path): bool
    {
        try {
            return $path !== '' && @is_file($path);
        } catch (Throwable) {
            return false;
        }
    }

    private static function isReadable(string $path): bool
    {
        try {
            return $path !== '' && @is_readable($path);
        } catch (Throwable) {
            return false;
        }
    }

    private static function isWritable(string $path): bool
    {
        try {
            return $path !== '' && @is_writable($path);
        } catch (Throwable) {
            return false;
        }
    }

    private static function readIfPossible(string $path): string
    {
        if (!self::isFile($path) || !self::isReadable($path)) {
            return '';
        }
        try {
            return (string)@file_get_contents($path);
        } catch (Throwable) {
            return '';
        }
    }

    /** Forward slashes and no trailing separator, so two paths can be compared. */
    private static function normalise(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
