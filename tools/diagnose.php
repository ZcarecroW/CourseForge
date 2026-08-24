<?php
declare(strict_types=1);

/**
 * Installation check.
 *
 *   php tools/diagnose.php
 *
 * Verifies everything CourseForge needs before it can start: PHP version,
 * extensions, writable data directory, readable configuration, a valid user
 * file and a working database. Touches nothing except creating the database if
 * it does not exist yet.
 */

if (PHP_SAPI !== 'cli') {
    exit("This tool is for the command line only.\n");
}

require __DIR__ . '/../src/bootstrap.php';

use CourseForge\Ai\Provider\ClaudeCliProvider;
use CourseForge\Ai\Run\RunManager;
use CourseForge\Domain\Details;
use CourseForge\Security\Users;
use CourseForge\Support\Config;
use CourseForge\Support\Db;

$problems = 0;
$warnings = 0;

function line(string $label, string $status, string $detail = ''): void
{
    printf("  %-9s %-34s %s\n", '[' . $status . ']', $label, $detail);
}
function ok(string $label, string $detail = ''): void
{
    line($label, ' OK ', $detail);
}
function bad(string $label, string $detail = ''): void
{
    global $problems;
    $problems++;
    line($label, 'FAIL', $detail);
}
function warn(string $label, string $detail = ''): void
{
    global $warnings;
    $warnings++;
    line($label, 'WARN', $detail);
}

echo "\nCourseForge " . CF_VERSION . " – installation check\n";
echo str_repeat('-', 72) . "\n\n";

echo "Runtime\n";
PHP_VERSION_ID >= 80100
    ? ok('PHP version', PHP_VERSION)
    : bad('PHP version', PHP_VERSION . ' – 8.1 or newer is required');

foreach (['curl', 'pdo_sqlite', 'mbstring', 'json', 'session'] as $extension) {
    extension_loaded($extension)
        ? ok('Extension ' . $extension)
        : bad('Extension ' . $extension, 'not loaded');
}

echo "\nPaths\n";
is_dir(CF_DATA) ? ok('Data directory', CF_DATA) : bad('Data directory', CF_DATA . ' does not exist');
is_writable(CF_DATA) ? ok('Data directory writable') : bad('Data directory writable', 'PHP cannot write to ' . CF_DATA);

echo "\nConfiguration\n";
try {
    $name = Config::str('app.name', 'CourseForge');
    ok('config.json', 'app.name = "' . $name . '"');

    $slots = Config::promptSlots();
    count($slots) > 0
        ? ok('Prompt library', count($slots) . ' slot(s) in ' . count(Config::promptGroups()) . ' group(s)')
        : bad('Prompt library', 'no prompts defined');

    $catalogue = Details::catalogue();
    count($catalogue['features']) > 0
        ? ok('Detail catalogue', count($catalogue['features']) . ' feature(s), ' . count($catalogue['params']) . ' value(s)')
        : bad('Detail catalogue', 'no features defined');

    foreach (array_keys($catalogue['features']) as $feature) {
        foreach (['on', 'off'] as $state) {
            if (!isset($slots['feature_' . $feature . '_' . $state])) {
                warn('Prompt for ' . $feature, 'feature_' . $feature . '_' . $state . ' is missing');
            }
        }
    }

    Config::bool('app.debug')
        ? warn('Debug mode', 'app.debug is TRUE – exception details are exposed in API responses')
        : ok('Debug mode', 'off');
} catch (Throwable $e) {
    bad('config.json', $e->getMessage());
}

echo "\nUsers\n";
try {
    $users = Users::load()['users'];
    count($users) > 0 ? ok('users.json', count($users) . ' user(s)') : bad('users.json', 'no users defined');
    foreach ($users as $user) {
        if (!empty($user['password_plain'])) {
            warn('User ' . ($user['username'] ?? '?'), 'still has a plaintext password – it is hashed on first sign-in');
        }
    }
} catch (Throwable $e) {
    bad('users.json', $e->getMessage());
}

echo "\nDatabase\n";
try {
    Db::pdo();
    ok('SQLite database', Db::file());
    $version = Db::row('SELECT value FROM meta WHERE key = ?', ['schema_version'])['value'] ?? '0';
    (int)$version === Db::SCHEMA_VERSION
        ? ok('Schema version', (string)$version)
        : warn('Schema version', $version . ' (expected ' . Db::SCHEMA_VERSION . ')');

    foreach (['projects', 'chapters', 'pages', 'tags', 'profiles', 'batch_jobs', 'batch_items', 'mcp_clients'] as $table) {
        $count = Db::row('SELECT COUNT(*) AS n FROM ' . $table)['n'] ?? 0;
        ok('Table ' . $table, $count . ' row(s)');
    }
} catch (Throwable $e) {
    bad('SQLite database', $e->getMessage());
}

echo "\nScheduler\n";
try {
    $cron = RunManager::cronStatus();
    $waiting = (int)(Db::row("SELECT COUNT(*) AS n FROM batch_jobs WHERE status NOT IN ('completed','failed','canceled')")['n'] ?? 0);

    if (!$cron['configured']) {
        $waiting > 0
            ? bad('cron token', 'not set, but ' . $waiting . ' run(s) are waiting for it')
            : warn('cron token', 'app.cron_token is empty, so background runs cannot be offered');
    } else {
        ok('cron token', 'set');

        if ($cron['last_at'] === 0) {
            $waiting > 0
                ? bad('Last tick', 'never - ' . $waiting . ' run(s) are waiting and nothing is collecting them')
                : warn('Last tick', 'never - point your host at /cron.php?token=... once a minute');
        } elseif ($cron['healthy']) {
            ok('Last tick', $cron['seconds_ago'] . 's ago');
        } else {
            warn('Last tick', $cron['seconds_ago'] . 's ago - it is meant to run every minute');
        }
    }

    ok('Runs open', $waiting === 0 ? 'none' : (string)$waiting);
    ok('Worker slots', (string)max(1, min(8, Config::int('app.cron_workers', 2))));
} catch (Throwable $e) {
    bad('Scheduler', $e->getMessage());
}

echo "\nMCP endpoint\n";
try {
    $off = Config::get('mcp.enabled') !== null && !Config::bool('mcp.enabled', true);
    $off
        ? warn('MCP endpoint', 'switched off in config.json - connected clients will be refused')
        : ok('MCP endpoint', 'api/mcp.php');

    $clients = (int)(Db::row('SELECT COUNT(*) AS n FROM mcp_clients')['n'] ?? 0);
    $clients === 0
        ? ok('Connections', 'none - create one under Connect to let Claude write courses')
        : ok('Connections', $clients . ' token(s) issued');
} catch (Throwable $e) {
    warn('MCP endpoint', $e->getMessage());
}

echo "\nClaude subscription - local installs only (optional)\n";
if (!ClaudeCliProvider::canSpawn()) {
    ok('proc_open', 'disabled - this account type is unavailable, which is normal on a hosted install');
} else {
    ok('proc_open', 'available');
    try {
        // An account with no cli_path falls back to app.claude_cli_path.
        $status = (new ClaudeCliProvider([]))->status();
        if (!$status['installed']) {
            // Expected on any hosted install: this account type only works when
            // CourseForge runs on the same machine you signed in to Claude Code
            // on. A hosted install uses the MCP connector instead.
            ok('Claude Code CLI', 'not installed here - use Connect instead');
        } elseif ($status['ok']) {
            ok('Claude Code CLI', $status['version'] . ' - ' . $status['detail']);
        } else {
            warn('Claude Code CLI', $status['version'] . ' - ' . $status['detail']);
        }
    } catch (Throwable $e) {
        warn('Claude Code CLI', $e->getMessage());
    }
}

echo "\n" . str_repeat('-', 72) . "\n";
if ($problems === 0 && $warnings === 0) {
    echo "Everything checks out.\n\n";
} elseif ($problems === 0) {
    echo $warnings . " warning(s), nothing blocking.\n\n";
} else {
    echo $problems . " problem(s) and " . $warnings . " warning(s) – CourseForge will not run correctly.\n\n";
}

exit($problems === 0 ? 0 : 1);
