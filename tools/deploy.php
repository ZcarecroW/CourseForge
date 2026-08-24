<?php
/**
 * Deploying CourseForge over FTP.
 *
 *     php tools/deploy.php --dry-run
 *     php tools/deploy.php
 *     php tools/deploy.php --full          re-upload everything, ignoring the manifest
 *     php tools/deploy.php --prune         also delete remote files the release no longer ships
 *
 * Most PHP hosting still gives you an FTP account and nothing else - no shell,
 * no git, no rsync - and CourseForge has no build step, so deploying it really
 * is a file copy. This is that file copy, made safe to repeat:
 *
 *   - it uploads only what has changed, by comparing a SHA-256 of every file
 *     against a manifest of what was last sent. FTP modification times are not
 *     reliable enough to diff on, and a six-hundred-file re-upload over a slow
 *     link is the difference between a deploy you do and one you avoid;
 *   - it never sends the things that belong to the server rather than to the
 *     release: `data/`, the invite code, the git directory, the editor folders;
 *   - it uploads to a temporary name and renames over the target, so a
 *     connection that drops mid-file cannot leave half a PHP file being served;
 *   - it prefers explicit TLS, so the password does not cross the network in
 *     the clear, and refuses to fall back to plain FTP unless told to.
 *
 * THE CREDENTIALS ARE NEVER IN THIS REPOSITORY. They are read, in this order,
 * from the environment, or from a JSON file the .gitignore already excludes:
 *
 *     CF_DEPLOY_HOST, CF_DEPLOY_USER, CF_DEPLOY_PASS, CF_DEPLOY_PATH, CF_DEPLOY_TLS
 *     data/deploy.json  { "host": "...", "user": "...", "pass": "...", "path": "/", "tls": true }
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("This tool is for the command line only.\n");
}
if (!function_exists('ftp_connect')) {
    exit("PHP's ftp extension is not loaded, so this tool cannot run.\n");
}

require __DIR__ . '/../src/bootstrap.php';

use CourseForge\Support\Json;

$options = [
    'dry-run' => in_array('--dry-run', $argv, true),
    'full' => in_array('--full', $argv, true),
    'prune' => in_array('--prune', $argv, true),
    'quiet' => in_array('--quiet', $argv, true),
    'allow-plain' => in_array('--allow-plaintext', $argv, true),
];

/** Files and directories that are the server's, not the release's. */
const NEVER_SEND = [
    '.git', '.github', '.idea', '.vscode', 'node_modules',
    'data', 'INVITE-CODE.txt', '.gitignore', '.gitattributes',
    'deploy.json', '.deploy', '.netrc', '.env',
];

/** Where the manifest of what was last uploaded lives. Outside the release. */
const MANIFEST = '/deploy-manifest.json';

/* ------------------------------------------------------------ credentials */

function credentials(): array
{
    $file = CF_DATA . '/deploy.json';
    $stored = is_file($file) ? (Json::read($file) ?? []) : [];

    $config = [
        'host' => getenv('CF_DEPLOY_HOST') ?: (string)($stored['host'] ?? ''),
        'user' => getenv('CF_DEPLOY_USER') ?: (string)($stored['user'] ?? ''),
        'pass' => getenv('CF_DEPLOY_PASS') ?: (string)($stored['pass'] ?? ''),
        'path' => rtrim(getenv('CF_DEPLOY_PATH') ?: (string)($stored['path'] ?? '/'), '/'),
        'tls' => filter_var(getenv('CF_DEPLOY_TLS') ?: ($stored['tls'] ?? true), FILTER_VALIDATE_BOOLEAN),
    ];

    foreach (['host', 'user', 'pass'] as $key) {
        if ($config[$key] === '') {
            exit(
                "No FTP " . $key . " configured.\n\n"
                . "Set CF_DEPLOY_HOST, CF_DEPLOY_USER and CF_DEPLOY_PASS in the environment, or write\n"
                . CF_DATA . "/deploy.json - which .gitignore already excludes:\n\n"
                . "  { \"host\": \"ftp.example.com\", \"user\": \"...\", \"pass\": \"...\", \"path\": \"/\" }\n"
            );
        }
    }
    return $config;
}

/* -------------------------------------------------------- the local tree */

/**
 * Every file the release consists of, with its hash.
 *
 * @return array<string,string> path relative to the root => sha256
 */
function localTree(string $root): array
{
    $files = [];
    $walk = static function (string $dir, string $prefix) use (&$walk, &$files, $root): void {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $relative = $prefix === '' ? $entry : $prefix . '/' . $entry;
            if (in_array($entry, NEVER_SEND, true) || in_array($relative, NEVER_SEND, true)) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $walk($path, $relative);
                continue;
            }
            if (str_ends_with($entry, '.sqlite') || str_ends_with($entry, '.tmp')) {
                continue;
            }
            $files[$relative] = hash_file('sha256', $path) ?: '';
        }
    };
    $walk($root, '');
    ksort($files);
    return $files;
}

/* ------------------------------------------------------------------ FTP */

function connect(array $config, bool $allowPlain): mixed
{
    $connection = $config['tls'] ? @ftp_ssl_connect($config['host'], 21, 30) : false;

    if ($connection === false) {
        if ($config['tls'] && !$allowPlain) {
            exit(
                "Could not open an FTP connection with explicit TLS to " . $config['host'] . ".\n"
                . "Refusing to fall back to plain FTP, which would send the password in the clear.\n"
                . "Pass --allow-plaintext if that is genuinely what you want.\n"
            );
        }
        $connection = @ftp_connect($config['host'], 21, 30);
    }
    if ($connection === false) {
        exit('Could not reach ' . $config['host'] . " on port 21.\n");
    }

    if (!@ftp_login($connection, $config['user'], $config['pass'])) {
        exit("The FTP server refused those credentials.\n");
    }
    ftp_pasv($connection, true);
    return $connection;
}

/** Creates a remote directory and everything above it. Remembers what exists. */
function ensureDirectory(mixed $ftp, string $path, array &$known): void
{
    if ($path === '' || $path === '.' || isset($known[$path])) {
        return;
    }
    $parent = dirname($path);
    if ($parent !== '' && $parent !== '.' && $parent !== '/') {
        ensureDirectory($ftp, $parent, $known);
    }
    if (@ftp_chdir($ftp, $path)) {
        $known[$path] = true;
        return;
    }
    @ftp_mkdir($ftp, $path);
    $known[$path] = true;
}

/* ----------------------------------------------------------------- main */

$root = CF_ROOT;
$config = credentials();
$manifestFile = CF_DATA . MANIFEST;

$local = localTree($root);
$previous = $options['full'] ? [] : (is_file($manifestFile) ? (Json::read($manifestFile) ?? []) : []);

$changed = [];
foreach ($local as $path => $hash) {
    if (($previous[$path] ?? null) !== $hash) {
        $changed[$path] = $hash;
    }
}
$gone = array_diff(array_keys($previous), array_keys($local));

$say = static function (string $line) use ($options): void {
    if (!$options['quiet']) {
        echo $line . "\n";
    }
};

$say('');
$say('CourseForge ' . CF_VERSION . ' - deploy');
$say(str_repeat('-', 72));
$say('  from   ' . $root);
$say('  to     ' . $config['user'] . '@' . $config['host'] . ($config['path'] === '' ? '/' : $config['path']));
$say('  files  ' . count($local) . ' in the release, ' . count($changed) . ' to upload'
    . ($gone === [] ? '' : ', ' . count($gone) . ' no longer shipped'));
$say('');

if ($options['dry-run']) {
    foreach (array_keys($changed) as $path) {
        $say('  would upload  ' . $path);
    }
    foreach ($gone as $path) {
        $say('  would ' . ($options['prune'] ? 'delete   ' : 'leave    ') . ' ' . $path);
    }
    $say('');
    $say('Nothing was sent. Run again without --dry-run.');
    exit(0);
}

if ($changed === [] && ($gone === [] || !$options['prune'])) {
    $say('Everything on the server is already up to date.');
    exit(0);
}

$ftp = connect($config, $options['allow-plain']);
$known = [];
$sent = 0;
$failed = [];

foreach ($changed as $path => $hash) {
    $remote = ($config['path'] === '' ? '' : $config['path']) . '/' . $path;
    ensureDirectory($ftp, dirname($remote), $known);

    // Upload beside the target and rename over it: a connection that drops
    // half way cannot leave a truncated PHP file being served.
    $temporary = $remote . '.uploading';
    $ok = @ftp_put($ftp, $temporary, $root . '/' . $path, FTP_BINARY);
    if ($ok) {
        @ftp_delete($ftp, $remote);
        $ok = @ftp_rename($ftp, $temporary, $remote);
    }

    if ($ok) {
        $sent++;
        $say(sprintf('  [%3d/%3d] %s', $sent, count($changed), $path));
        $previous[$path] = $hash;
    } else {
        $failed[] = $path;
        @ftp_delete($ftp, $temporary);
        $say('  FAILED    ' . $path);
    }
}

if ($options['prune']) {
    foreach ($gone as $path) {
        $remote = ($config['path'] === '' ? '' : $config['path']) . '/' . $path;
        if (@ftp_delete($ftp, $remote)) {
            unset($previous[$path]);
            $say('  deleted   ' . $path);
        }
    }
}

ftp_close($ftp);

// The manifest records what is on the server, so a failed file is retried next
// time rather than assumed to have arrived.
ksort($previous);
Json::write($manifestFile, $previous);

$say('');
$say(str_repeat('-', 72));
if ($failed === []) {
    $say($sent . ' file(s) uploaded. The manifest is at ' . $manifestFile . '.');
} else {
    $say($sent . ' uploaded, ' . count($failed) . ' failed: ' . implode(', ', array_slice($failed, 0, 8))
        . (count($failed) > 8 ? ' and more' : ''));
    $say('Run the command again - only the failures will be retried.');
}
$say('');

exit($failed === [] ? 0 : 1);
