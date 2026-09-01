<?php
declare(strict_types=1);

namespace CourseForge\Ai\Provider;

use CourseForge\Ai\AiRequest;
use CourseForge\Support\Config;
use CourseForge\Support\HttpException;
use CourseForge\Support\Text;
use Throwable;

/**
 * A Claude Pro or Max subscription, reached through the Claude Code CLI that is
 * already installed and already signed in on this machine.
 *
 * Why it works this way
 * --------------------
 * There is no HTTP endpoint that bills a subscription. api.anthropic.com bills
 * an API key or a Console workspace, and the `ant` CLI's OAuth flow signs in to
 * the Console too, not to claude.ai. The only sanctioned subscription-backed
 * programmatic surface is Claude Code itself, run locally by the subscriber.
 *
 * So CourseForge runs `claude -p` as a child process. It never reads, stores,
 * forwards or displays a credential of any kind: authentication has already
 * happened, in the CLI, under the user's own account. The alternative - lifting
 * the OAuth token out of ~/.claude and posting it ourselves - is prohibited by
 * Anthropic's terms and is not implemented here on purpose.
 *
 * The consequence is that this provider is for a single person running their
 * own copy. It is deliberately unusable as a shared endpoint: it bills whoever
 * is signed in on the server, so a multi-user install must stay on API keys.
 *
 * Two traps this class exists to avoid
 * ------------------------------------
 *  - ANTHROPIC_API_KEY in the web server's environment silently makes the CLI
 *    bill the API instead of the subscription, with no warning. The child
 *    environment is therefore built from scratch rather than inherited.
 *  - `--bare` looks like the right mode for a scripted caller and is not: bare
 *    mode never reads OAuth credentials, so it cannot see the subscription at
 *    all. Isolation comes from --tools, --strict-mcp-config and a replaced
 *    system prompt instead.
 */
final class ClaudeCliProvider implements Provider
{
    /** Searches to allow when research is on and the course names no number. */
    private const DEFAULT_SEARCHES = 5;

    /**
     * A ceiling on the turn budget, whatever the course asks for.
     *
     * `research_max_searches` tops out at 20 in the catalogue, so this is only
     * reached by a configuration that has been edited by hand - and a run that
     * has taken twenty-odd turns is not researching any more, it is stuck.
     */
    private const MAX_TURNS = 24;

    /** Environment variables that would redirect the CLI away from the subscription. */
    private const HIJACKERS = [
        'ANTHROPIC_API_KEY',
        'ANTHROPIC_AUTH_TOKEN',
        'ANTHROPIC_PROFILE',
        'ANTHROPIC_BASE_URL',
        'ANTHROPIC_FEDERATION_RULE_ID',
        'ANTHROPIC_ORGANIZATION_ID',
        'ANTHROPIC_SERVICE_ACCOUNT_ID',
        'ANTHROPIC_IDENTITY_TOKEN',
        'ANTHROPIC_IDENTITY_TOKEN_FILE',
        'ANTHROPIC_MODEL',
        'CLAUDE_CODE_USE_BEDROCK',
        'CLAUDE_CODE_USE_VERTEX',
        'CLAUDE_CODE_USE_FOUNDRY',
    ];

    /** Variables the CLI genuinely needs to find its own installation and login. */
    private const KEEP = [
        'PATH', 'HOME', 'USERPROFILE', 'HOMEDRIVE', 'HOMEPATH', 'APPDATA', 'LOCALAPPDATA',
        'SystemRoot', 'SYSTEMROOT', 'windir', 'ComSpec', 'COMSPEC', 'TEMP', 'TMP', 'TMPDIR',
        'LANG', 'LC_ALL', 'USER', 'USERNAME', 'SHELL', 'XDG_CONFIG_HOME', 'XDG_CACHE_HOME',
        'CLAUDE_CONFIG_DIR', 'NODE_EXTRA_CA_CERTS',
    ];

    private readonly string $binary;

    /** @param array<string,mixed> $account */
    public function __construct(private readonly array $account)
    {
        $this->binary = self::resolveBinary(trim((string)($account['cli_path'] ?? '')));
    }

    /**
     * Decides which program may actually be run.
     *
     * The path on the account is ordinary profile data, and a profile is
     * editable by anyone who can sign in - so treating it as "a command to
     * execute" would turn an authenticated session into arbitrary execution on
     * the server. It is therefore not a free-text command: the file name must
     * be the Claude CLI, and an absolute path is only honoured when
     * `app.claude_cli_allowed_paths` lists it. Anything else falls back to the
     * one path the administrator configured.
     */
    private static function resolveBinary(string $configured): string
    {
        $fallback = Config::str('app.claude_cli_path', 'claude');
        if ($configured === '' || $configured === $fallback) {
            return $fallback;
        }

        // Only ever the Claude CLI, whatever directory it lives in.
        $name = strtolower(pathinfo($configured, PATHINFO_FILENAME));
        if ($name !== 'claude') {
            return $fallback;
        }

        $allowed = array_map('strval', (array)Config::get('app.claude_cli_allowed_paths', []));
        foreach ($allowed as $candidate) {
            if ($candidate === $configured) {
                return $configured;
            }
        }

        // A bare name is resolved against PATH by the operating system, which is
        // the same trust boundary the fallback already sits in.
        return $configured === basename($configured) ? $configured : $fallback;
    }

    public function kind(): string
    {
        return Providers::CLAUDE_CLI;
    }

    public function label(): string
    {
        return 'Claude subscription (Claude Code CLI)';
    }

    /** No queue exists behind the CLI; batch pricing lives only on the API surfaces. */
    public function supportsBatch(): bool
    {
        return false;
    }

    /** @return string[] */
    public function batchModels(): array
    {
        return [];
    }

    /**
     * The CLI has no model-list command, so this is a configured list rather
     * than a live one. Aliases come first because they keep pointing at the
     * newest model in each family without an edit here.
     *
     * @return string[]
     */
    public function models(): array
    {
        $this->assertUsable();

        $models = Config::get('app.claude_cli_models');
        $models = is_array($models) ? array_values(array_filter(array_map('strval', $models))) : [];

        return $models !== [] ? $models : [
            'opus', 'sonnet', 'haiku', 'fable',
            'claude-opus-5', 'claude-sonnet-5', 'claude-haiku-4-5', 'claude-fable-5',
        ];
    }

    public function chat(AiRequest $request): string
    {
        $model = trim($request->model);
        if ($model === '') {
            throw HttpException::unprocessable('No model is selected for this request.');
        }
        // The model is the one argument on this command line that a signed-in
        // user types, and it goes to proc_open as a bare argv element. That is
        // safe on POSIX and safe on Windows in front of a real executable, but
        // in front of the .cmd shim npm installs it is cmd.exe that reads the
        // line, and a PHP older than 8.1.28 does not escape for cmd.exe
        // correctly (CVE-2024-1874). A model id is letters, digits and a few
        // separators - `claude-opus-5`, `opus[1m]`, a Bedrock id with a colon
        // - so anything else is refused here rather than handed on.
        if (preg_match('/^[A-Za-z0-9._:\/@+\[\]-]+$/', $model) !== 1) {
            throw HttpException::unprocessable(
                'The model id "' . Text::snippet($model, 60) . '" contains characters a model id never has. '
                . 'Pick one from list_models, or type the id exactly as Anthropic publishes it.'
            );
        }

        $this->assertUsable();

        // The system prompt travels as a file, never as an argument: it is long,
        // it contains typographic punctuation, and a Windows command line
        // mangles both. The user prompt goes down stdin for the same reason.
        $promptFile = self::spool($request->system);

        try {
            $result = $this->execute([
                '-p',
                '--output-format', 'json',
                '--model', $model,
                ...$this->toolArgs($request),
                '--strict-mcp-config',
                '--disable-slash-commands',
                '--system-prompt-file', $promptFile,
            ], $request->user);
        } finally {
            @unlink($promptFile);
        }

        return $this->readResult($result);
    }

    /**
     * What the child is allowed to do, which is either nothing or searching.
     *
     * The default is still total isolation - no built-in tools, no MCP servers
     * from the user's own config, no skills - because CourseForge wants text
     * and every tool that is not text is a way for the run to do something
     * nobody asked for.
     *
     * Web research is the one exception, and it is the reason this provider is
     * worth having for a course about something that moves. Claude Code is
     * already a research tool: told to look, it will read today's WordPress
     * release notes rather than recall last year's. Turning `web_research` on
     * for a course used to reach every provider except this one, which
     * silently wrote from memory - the toggle was on, the prompt asked for
     * current facts and cited sources, and the one provider that could
     * genuinely go and get them was the one that had been told it had no tools.
     *
     * Two flags, because they answer different questions. `--tools` says which
     * built-in tools exist at all; `--allowedTools` says which may run without
     * asking a human, which matters because `-p` has nobody to ask and an
     * unapproved tool call would simply stall. Both are narrowed to the two
     * read-only ones: WebSearch and WebFetch can look things up and can do
     * nothing else.
     *
     * The turn budget grows with the search budget. One turn is exactly enough
     * to answer and not enough to search first, so a research request capped at
     * one turn is a research request that cannot research; each search costs a
     * turn, and two more are left for reading the results and writing the page.
     *
     * @return string[]
     */
    private function toolArgs(AiRequest $request): array
    {
        if (!$request->research) {
            return ['--max-turns', '1', '--tools', ''];
        }

        // 0 means "leave it to the provider", which here has to become a real
        // number: the CLI needs a turn limit and there is no unlimited.
        $searches = $request->maxSearches > 0 ? $request->maxSearches : self::DEFAULT_SEARCHES;

        return [
            '--max-turns', (string)min(self::MAX_TURNS, $searches + 2),
            '--tools', 'WebSearch,WebFetch',
            '--allowedTools', 'WebSearch', 'WebFetch',
        ];
    }

    /**
     * Whether the CLI is installed, signed in, and signed in to a subscription
     * rather than to an API key. Used by the Profiles screen and by
     * tools/diagnose.php.
     *
     * @return array{ok:bool,installed:bool,version:string,logged_in:bool,method:string,plan:string,shadowed:bool,detail:string}
     */
    public function status(): array
    {
        $blank = [
            'ok' => false, 'installed' => false, 'version' => '', 'logged_in' => false,
            'method' => '', 'plan' => '', 'shadowed' => false, 'detail' => '',
        ];

        if (!self::canSpawn()) {
            return array_merge($blank, [
                'detail' => 'PHP may not start other programs on this server (proc_open is disabled), '
                    . 'so the Claude CLI cannot be reached. Use an API key account instead.',
            ]);
        }

        // A missing binary makes proc_open fail outright, which execute() turns
        // into an exception - here that is an answer, not a failure.
        try {
            $version = $this->execute(['--version'], null, 20);
        } catch (Throwable $e) {
            return array_merge($blank, ['detail' => $e->getMessage()]);
        }

        if ($version['status'] !== 0) {
            return array_merge($blank, [
                'detail' => $version['status'] === 127
                    ? 'The "' . $this->binary . '" command was not found. Install Claude Code, or give this account the full path to it.'
                    : trim($version['stderr'] !== '' ? $version['stderr'] : $version['stdout']),
            ]);
        }

        try {
            $auth = $this->execute(['auth', 'status'], null, 30);
        } catch (Throwable $e) {
            return array_merge($blank, [
                'installed' => true,
                'version' => trim($version['stdout']),
                'detail' => $e->getMessage(),
            ]);
        }

        $data = json_decode(trim($auth['stdout']), true);
        $data = is_array($data) ? $data : [];

        $loggedIn = ($data['loggedIn'] ?? false) === true;
        $source = (string)($data['apiKeySource'] ?? 'none');
        $plan = (string)($data['subscriptionType'] ?? '');
        // loggedIn stays true even when an API key has taken over, so the
        // subscription is only genuinely in use when no key source is reported.
        $shadowed = $source !== '' && $source !== 'none';

        return [
            'ok' => $loggedIn && !$shadowed && $plan !== '',
            'installed' => true,
            'version' => trim($version['stdout']),
            'logged_in' => $loggedIn,
            'method' => (string)($data['authMethod'] ?? ''),
            'plan' => $plan,
            'shadowed' => $shadowed,
            'detail' => match (true) {
                !$loggedIn => 'The CLI is installed but not signed in. Run "claude" once on this server and sign in.',
                $shadowed => 'An API key in this server\'s environment (' . $source . ') is overriding the subscription. '
                    . 'CourseForge removes it for its own calls, but the sign-in check still sees it.',
                $plan === '' => 'Signed in, but not to a Pro or Max plan, so generation would be billed elsewhere.',
                default => 'Signed in to a Claude ' . $plan . ' plan.',
            },
        ];
    }

    /* ------------------------------------------------------------ internals */

    /**
     * Runs the CLI and returns its three outputs.
     *
     * Two implementations, because one of them cannot be made to work on
     * Windows. Everywhere else the child's pipes are drained with
     * stream_select() under a deadline, which is the right shape: it writes the
     * prompt and reads the answer in the same loop, so neither side can fill a
     * pipe buffer and wait for the other.
     *
     * On Windows neither stream_set_blocking(false) nor stream_select() has any
     * effect on a pipe from proc_open. PHP blocks inside fread() until the child
     * writes something, so the deadline is only ever re-checked when the child
     * chooses to speak - and a child that has hung says nothing at all. This was
     * measured rather than inferred: a thirty-minute limit reported itself after
     * thirty-eight, and only because the process was killed by hand. A timeout
     * that cannot fire while the thing it is timing is stuck is not a timeout.
     *
     * So Windows gets files instead of pipes. proc_open writes the child's
     * output straight to disk, PHP polls proc_get_status() on the clock, and the
     * deadline is exact. It also removes the pipe-buffer deadlock outright,
     * since there is no buffer to fill.
     *
     * @param array<int,string> $arguments
     * @return array{status:int,stdout:string,stderr:string,timeout:bool}
     */
    private function execute(array $arguments, ?string $stdin = null, int $timeout = 0): array
    {
        $timeout = $timeout > 0 ? $timeout : max(60, Config::int('app.ai_timeout_seconds', 1800));

        if (DIRECTORY_SEPARATOR === '\\') {
            return $this->executeViaFiles($arguments, $stdin ?? '', $timeout);
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open(
            array_merge([$this->binary], $arguments),
            $descriptors,
            $pipes,
            CF_DATA,
            $this->environment(),
        );

        if (!is_resource($process)) {
            throw HttpException::badRequest(
                'Could not start "' . $this->binary . '". Check that Claude Code is installed and that PHP may run it.'
            );
        }

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeout;
        $timedOut = false;

        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        // The prompt is written inside the same loop that drains stdout, and
        // under the same deadline. A single blocking fwrite() would deadlock on
        // a prompt larger than the pipe buffer - the child stops reading to
        // write its own output, nobody is draining that output, and both sides
        // wait forever with the time limit already lifted.
        $pending = $stdin ?? '';
        if ($pending === '') {
            fclose($pipes[0]);
            $pipes[0] = null;
        }

        while (true) {
            $read = array_filter([$pipes[1], $pipes[2]], static fn($pipe): bool => is_resource($pipe) && !feof($pipe));
            $write = is_resource($pipes[0]) ? [$pipes[0]] : [];
            if ($read === [] && $write === []) {
                break;
            }

            $except = null;
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                $timedOut = true;
                break;
            }

            if (@stream_select($read, $write, $except, (int)min(5, max(1, $left))) === false) {
                break;
            }

            if ($write !== [] && is_resource($pipes[0])) {
                $written = fwrite($pipes[0], substr($pending, 0, 65536));
                if ($written === false) {
                    fclose($pipes[0]);
                    $pipes[0] = null;
                } else {
                    $pending = substr($pending, $written);
                    if ($pending === '') {
                        fclose($pipes[0]);   // EOF tells the CLI the prompt is complete
                        $pipes[0] = null;
                    }
                }
            }

            foreach ($read as $pipe) {
                $chunk = fread($pipe, 65536);
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

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if ($timedOut) {
            proc_terminate($process);
            proc_close($process);
            throw HttpException::badRequest(
                'The Claude CLI did not answer within ' . $timeout . ' seconds and was stopped.'
            );
        }

        return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr, 'timeout' => false];
    }

    /**
     * The Windows path: the child's streams are files, and the wait is a poll.
     *
     * Every file is made in the system temporary directory rather than in
     * CF_DATA, so a prompt never lands anywhere the web server might serve, and
     * every one of them is removed in the `finally` whatever happens - including
     * when the deadline fires and the process is terminated under it.
     *
     * @param array<int,string> $arguments
     * @return array{status:int,stdout:string,stderr:string,timeout:bool}
     */
    private function executeViaFiles(array $arguments, string $stdin, int $timeout): array
    {
        $prefix = sys_get_temp_dir() . '/cf-cli-' . bin2hex(random_bytes(6));
        $inFile = $prefix . '.in';
        $outFile = $prefix . '.out';
        $errFile = $prefix . '.err';

        if (@file_put_contents($inFile, $stdin) === false) {
            throw HttpException::badRequest(
                'Could not write the prompt to a temporary file in ' . sys_get_temp_dir() . '.'
            );
        }

        try {
            $process = @proc_open(
                array_merge([$this->binary], $arguments),
                [
                    0 => ['file', $inFile, 'r'],
                    1 => ['file', $outFile, 'w'],
                    2 => ['file', $errFile, 'w'],
                ],
                $pipes,
                CF_DATA,
                $this->environment(),
            );

            if (!is_resource($process)) {
                throw HttpException::badRequest(
                    'Could not start "' . $this->binary . '". Check that Claude Code is installed and that PHP may run it.'
                );
            }

            $deadline = microtime(true) + $timeout;
            $status = proc_get_status($process);

            while ($status['running'] === true) {
                if (microtime(true) >= $deadline) {
                    // terminate() asks; on Windows it is a hard kill, which is
                    // what is wanted for a child that has stopped answering.
                    proc_terminate($process);
                    proc_close($process);
                    throw HttpException::badRequest(
                        'The Claude CLI did not answer within ' . $timeout . ' seconds and was stopped.'
                    );
                }
                // 200ms: fine enough that the deadline is honoured to within a
                // fifth of a second, coarse enough to cost nothing over half an
                // hour of waiting.
                usleep(200000);
                $status = proc_get_status($process);
            }

            // The exit code is only reported once, on the first call that sees
            // the process finished; proc_close would answer -1 after that.
            $exit = $status['exitcode'];
            proc_close($process);

            return [
                'status' => is_int($exit) ? $exit : -1,
                'stdout' => (string)@file_get_contents($outFile),
                'stderr' => (string)@file_get_contents($errFile),
                'timeout' => false,
            ];
        } finally {
            foreach ([$inFile, $outFile, $errFile] as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * Reads the single JSON object `--output-format json` prints.
     *
     * Two things make this less obvious than it looks: a run that failed still
     * prints a parseable object and still says `subtype: "success"`, so the
     * signal is `is_error`; and a non-zero exit code can accompany a perfectly
     * readable result, so both have to be checked.
     *
     * @param array{status:int,stdout:string,stderr:string,timeout:bool} $result
     */
    private function readResult(array $result): string
    {
        $payload = json_decode(trim($result['stdout']), true);

        if (!is_array($payload)) {
            $detail = trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);

            // An exit status above 128 is a signal, and the two that get sent
            // here are a host time limit and somebody killing the process
            // tree. Both leave a JSON object that stops mid-field, and
            // reporting that as "did not return JSON" sends the reader looking
            // for a bug in the CLI when what happened is that the answer was
            // cut off. It is worth telling apart: the work was done and paid
            // for, and running it again with more time will succeed.
            if ($result['status'] > 128) {
                throw HttpException::badRequest(
                    'The Claude CLI was stopped by the system (signal ' . ($result['status'] - 128) . ') '
                    . 'part way through its answer, so what came back is incomplete. That is usually a time '
                    . 'limit on the process rather than anything wrong with the request - raise the AI '
                    . 'request timeout, or generate fewer pages at once.'
                );
            }

            throw HttpException::badRequest(
                'The Claude CLI did not return JSON (exit code ' . $result['status'] . '). '
                . ($detail !== '' ? Text::snippet($detail) : 'It produced no output at all.')
            );
        }

        if (($payload['is_error'] ?? false) === true) {
            $status = $payload['api_error_status'] ?? null;
            throw HttpException::badRequest(
                'Claude Code reported an error'
                . ($status !== null ? ' (HTTP ' . (int)$status . ')' : '')
                . ': ' . Text::snippet((string)($payload['result'] ?? 'no detail given'), 400)
            );
        }

        if (($payload['subtype'] ?? '') === 'error_max_turns') {
            throw HttpException::badRequest('Claude Code stopped at its turn limit before finishing the page.');
        }

        $content = trim((string)($payload['result'] ?? ''));
        if ($content === '') {
            throw HttpException::badRequest(
                'Claude Code returned an empty result (stop reason: ' . (string)($payload['stop_reason'] ?? 'unknown') . ').'
            );
        }

        // The ceiling is checked whether or not there is text, which is the
        // whole point: a page cut off at the output limit comes back looking
        // like a finished one, with several thousand perfectly good words and
        // no last paragraph. Every other provider in this file's neighbourhood
        // treats that as a failure rather than storing it, because a course
        // page that stops mid-sentence is worse than one that has to be
        // written again - the first is published and read, the second is
        // retried. This provider was the exception only because it read the
        // stop reason inside the empty-result branch.
        $stop = strtolower(trim((string)($payload['stop_reason'] ?? '')));
        if ($stop === 'max_tokens') {
            throw HttpException::badRequest(
                'Claude Code hit its output ceiling part way through, so the page it returned stops mid-answer '
                . '(' . Text::words($content) . ' words). It has not been stored. Lower the maximum length for '
                . 'this course, or split the page in two.'
            );
        }

        return $content;
    }

    /**
     * A deliberately small environment.
     *
     * Inheriting the web server's environment is what would quietly move the
     * bill from the subscription to an API key, so the child gets only the
     * variables it needs to find its installation and its own login.
     *
     * @return array<string,string>
     */
    private function environment(): array
    {
        $environment = [];
        foreach (self::KEEP as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                $environment[$name] = $value;
            }
        }
        foreach (self::HIJACKERS as $name) {
            unset($environment[$name]); // belt and braces: KEEP never lists one
        }
        return $environment;
    }

    private function assertUsable(): void
    {
        if (!self::canSpawn()) {
            throw HttpException::unprocessable(
                'This server does not allow PHP to start other programs (proc_open is disabled), so the Claude '
                . 'subscription account cannot be used. Switch this slot to an API key account.'
            );
        }
    }

    /** proc_open is the first thing shared hosts turn off. */
    public static function canSpawn(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        return !in_array('proc_open', $disabled, true);
    }

    /** Writes the system prompt somewhere the CLI can read it, and nowhere the web can. */
    private static function spool(string $text): string
    {
        $directory = CF_DATA . '/tmp';
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw HttpException::badRequest('Could not create ' . $directory . ' to hand the prompt to the CLI.');
        }

        $path = $directory . '/prompt-' . bin2hex(random_bytes(12)) . '.txt';
        if (@file_put_contents($path, $text) === false) {
            throw HttpException::badRequest('Could not write the prompt file at ' . $path . '.');
        }
        return $path;
    }
}
