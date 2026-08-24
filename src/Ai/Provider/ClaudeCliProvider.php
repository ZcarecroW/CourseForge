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
        $this->assertUsable();

        $model = trim($request->model);
        if ($model === '') {
            throw HttpException::unprocessable('No model is selected for this request.');
        }

        // The system prompt travels as a file, never as an argument: it is long,
        // it contains typographic punctuation, and a Windows command line
        // mangles both. The user prompt goes down stdin for the same reason.
        $promptFile = self::spool($request->system);

        try {
            $result = $this->execute([
                '-p',
                '--output-format', 'json',
                '--model', $model,
                '--max-turns', '1',
                // Isolation: no built-in tools, no MCP servers from the user's
                // own config, no skills. CourseForge wants text, nothing else.
                '--tools', '',
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
     * @param array<int,string> $arguments
     * @return array{status:int,stdout:string,stderr:string,timeout:bool}
     */
    private function execute(array $arguments, ?string $stdin = null, int $timeout = 0): array
    {
        $timeout = $timeout > 0 ? $timeout : max(60, Config::int('app.ai_timeout_seconds', 1800));

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
