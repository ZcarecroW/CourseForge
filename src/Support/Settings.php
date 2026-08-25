<?php
declare(strict_types=1);

namespace CourseForge\Support;

use CourseForge\Security\Actor;

/**
 * Every setting this installation has, declared once.
 *
 * CourseForge 3.x expected you to open `data/config.json` in an editor. 4.0
 * says that everything is configurable from the application, which is only
 * true if there is a single list of what "everything" is - otherwise a setting
 * gets added to the code and forgotten by the screen that is supposed to offer
 * it.
 *
 * So this is that list, and three things read it:
 *
 *   - the Settings screen, which renders a field per entry from `type`;
 *   - the API, which validates and coerces a submitted value against it;
 *   - the MCP tools, which describe and change settings without a second
 *     catalogue that could disagree with this one.
 *
 * A `secret` field is never sent to a client. It goes out as a flag saying
 * whether something is stored, and comes back only when it is being changed.
 */
final class Settings
{
    /**
     * @return array<int,array{key:string,label:string,description:string,group:string,type:string,
     *                          default:mixed,secret?:bool,min?:int,max?:int,unit?:string,options?:array,
     *                          admin_only?:bool,advanced?:bool,placeholder?:string}>
     */
    public static function catalogue(): array
    {
        return [
            /* ------------------------------------------------------ general */
            [
                'key' => 'app.name', 'group' => 'general', 'type' => 'string',
                'label' => 'Installation name',
                'description' => 'Shown in the header and in the browser tab. Useful when you run more than one.',
                'default' => 'CourseForge',
            ],
            [
                'key' => 'app.default_language', 'group' => 'general', 'type' => 'string',
                'label' => 'Default course language',
                'description' => 'What a new profile starts with. Every course can still choose its own.',
                'default' => 'English',
            ],
            [
                'key' => 'app.default_concurrency', 'group' => 'general', 'type' => 'int',
                'label' => 'Pages at a time, in the browser',
                'description' => 'How many pages the in-tab generator writes in parallel. Background runs use the cron worker count instead.',
                'default' => 2, 'min' => 1, 'max' => 16,
            ],
            [
                'key' => 'app.public_url', 'group' => 'general', 'type' => 'string',
                'label' => 'Public address',
                'description' => 'The address this installation is reached at. CourseForge works it out from '
                    . 'the request, so leave it empty unless it guesses wrong - behind a reverse proxy, for '
                    . 'instance. It is what the cron URL is built from; the MCP connection line has its own setting below.',
                'placeholder' => 'https://courseforge.example.com',
                'default' => '',
            ],
            [
                'key' => 'app.debug', 'group' => 'general', 'type' => 'bool',
                'label' => 'Debug mode',
                'description' => 'Puts exception details into API responses. Leave this off on anything reachable from the internet.',
                'default' => false, 'advanced' => true,
            ],

            /* ----------------------------------------------------- timeouts */
            [
                'key' => 'app.ai_timeout_seconds', 'group' => 'timeouts', 'type' => 'int',
                'label' => 'AI request timeout', 'unit' => 'seconds',
                'description' => 'A long page on a reasoning model can genuinely take minutes. Below about 600 you will start losing work.',
                'default' => 1800, 'min' => 30, 'max' => 7200,
            ],
            [
                'key' => 'app.ai_models_timeout_seconds', 'group' => 'timeouts', 'type' => 'int',
                'label' => 'Model list timeout', 'unit' => 'seconds',
                'description' => 'For the call that asks a provider which models the account can use.',
                'default' => 240, 'min' => 10, 'max' => 1200,
            ],
            [
                'key' => 'app.connect_timeout_seconds', 'group' => 'timeouts', 'type' => 'int',
                'label' => 'Connection timeout', 'unit' => 'seconds',
                'description' => 'How long to wait for a provider to answer at all, before any content arrives.',
                'default' => 60, 'min' => 5, 'max' => 300,
            ],
            [
                'key' => 'app.bookstack_timeout_seconds', 'group' => 'timeouts', 'type' => 'int',
                'label' => 'BookStack timeout', 'unit' => 'seconds',
                'description' => 'Publishing a large course is one long sequence of API calls.',
                'default' => 240, 'min' => 10, 'max' => 1800,
            ],

            /* ---------------------------------------------------- scheduler */
            [
                'key' => 'app.cron_token', 'group' => 'scheduler', 'type' => 'secret',
                'label' => 'Cron token',
                'description' => 'The secret in the URL your host calls once a minute. Without one, cron.php answers 404 to everybody and background runs are not offered at all.',
                'default' => '', 'admin_only' => true,
            ],
            [
                'key' => 'app.cron_seconds', 'group' => 'scheduler', 'type' => 'int',
                'label' => 'Seconds of work per tick', 'unit' => 'seconds',
                'description' => 'A tick stops handing out new pages after this long, so it finishes before your host cuts the request off. Keep it under the PHP time limit.',
                'default' => 50, 'min' => 5, 'max' => 3000,
            ],
            [
                'key' => 'app.cron_workers', 'group' => 'scheduler', 'type' => 'int',
                'label' => 'Parallel workers',
                'description' => 'How many ticks may work side by side. Two writes roughly two pages a minute. Raise it only as far as your provider rate limits allow.',
                'default' => 2, 'min' => 1, 'max' => 16,
            ],
            [
                'key' => 'app.cron_max_attempts', 'group' => 'scheduler', 'type' => 'int',
                'label' => 'Attempts per page',
                'description' => 'How often a page that fails is handed back to a later tick before it is given up on.',
                'default' => 3, 'min' => 1, 'max' => 10,
            ],
            [
                'key' => 'app.cron_item_timeout_seconds', 'group' => 'scheduler', 'type' => 'int',
                'label' => 'Worker lease', 'unit' => 'seconds',
                'description' => 'How long a page stays claimed by a worker that has stopped answering, before another one may take it.',
                'default' => 1800, 'min' => 60, 'max' => 7200, 'advanced' => true,
            ],

            /* -------------------------------------------------------- batch */
            [
                'key' => 'app.batch_poll_seconds', 'group' => 'batch', 'type' => 'int',
                'label' => 'Minimum time between polls', 'unit' => 'seconds',
                'description' => 'How rarely CourseForge asks a provider whether a queued batch has finished. Batches take hours; polling every minute wastes rate limit for nothing.',
                'default' => 60, 'min' => 10, 'max' => 3600,
            ],
            [
                'key' => 'app.batch_keep_days', 'group' => 'batch', 'type' => 'int',
                'label' => 'Keep finished runs for', 'unit' => 'days',
                'description' => 'Finished run records are removed after this long. The pages they wrote are never touched.',
                'default' => 30, 'min' => 1, 'max' => 3650,
            ],
            [
                'key' => 'app.anthropic_max_tokens', 'group' => 'batch', 'type' => 'int',
                'label' => 'Default output ceiling', 'unit' => 'tokens',
                'description' => 'Used where a provider demands an explicit ceiling and the profile does not set one. Too low truncates a long page mid-sentence.',
                'default' => 16000, 'min' => 1000, 'max' => 200000,
            ],

            /* ------------------------------------------- claude subscription */
            [
                'key' => 'app.claude_cli_path', 'group' => 'claude_cli', 'type' => 'string',
                'label' => 'Path to the claude binary',
                'description' => 'Left as "claude" it is looked up on PATH, which is right on a machine where '
                    . 'you installed it normally. Give a full path when PHP runs with a PATH of its own.',
                'default' => 'claude', 'admin_only' => true,
            ],
            [
                'key' => 'app.claude_cli_allowed_paths', 'group' => 'claude_cli', 'type' => 'list',
                'label' => 'Directories the CLI may be started from',
                'description' => 'Empty means no restriction. Naming directories here refuses to start the '
                    . 'binary from anywhere else, which is worth doing if anything other than you can write '
                    . 'to the machine.',
                'default' => [], 'admin_only' => true, 'advanced' => true,
            ],
            [
                'key' => 'app.claude_cli_models', 'group' => 'claude_cli', 'type' => 'list',
                'label' => 'Models the subscription offers',
                'description' => 'The CLI has no endpoint that lists them, so this is the list the Profiles '
                    . 'screen shows. Add a model here when Anthropic ships one before CourseForge knows about it.',
                'default' => ['opus', 'sonnet', 'haiku', 'fable',
                    'claude-opus-5', 'claude-sonnet-5', 'claude-haiku-4-5', 'claude-fable-5'],
                'admin_only' => true, 'advanced' => true,
            ],

            /* ---------------------------------------------------------- mcp */
            [
                'key' => 'mcp.enabled', 'group' => 'mcp', 'type' => 'bool',
                'label' => 'MCP endpoint enabled',
                'description' => 'The second front door, for Claude Code and other MCP clients. Turning it off refuses every token at once.',
                'default' => true, 'admin_only' => true,
            ],
            [
                'key' => 'mcp.public_url', 'group' => 'mcp', 'type' => 'string',
                'label' => 'Public URL',
                'description' => 'The address an MCP client should use, if CourseForge cannot work it out for itself - behind a proxy, for instance.',
                'placeholder' => 'https://courseforge.example.com/api/mcp.php',
                'default' => '', 'admin_only' => true,
            ],
            [
                'key' => 'mcp.allowed_origins', 'group' => 'mcp', 'type' => 'list',
                'label' => 'Allowed browser origins',
                'description' => 'Origins a browser-based MCP client may connect from. Command-line clients send no Origin and are unaffected. Leave empty to refuse all of them.',
                'default' => [], 'admin_only' => true, 'advanced' => true,
            ],

            /* ----------------------------------------------------- security */
            [
                'key' => 'security.max_login_attempts', 'group' => 'security', 'type' => 'int',
                'label' => 'Failed sign-ins before an account is locked',
                'description' => 'Counted for one account, wherever the attempts came from. This is what stands '
                    . 'between a password and somebody working through a list of guesses at it.',
                'default' => 5, 'min' => 1, 'max' => 100, 'admin_only' => true,
            ],
            [
                'key' => 'security.max_address_attempts', 'group' => 'security', 'type' => 'int',
                'label' => 'Failed sign-ins before an address is locked',
                'description' => 'Counted for one address, against any account, and deliberately looser than the '
                    . 'figure above: an address is shared - an office, a mobile network, a VPN - and a cap of '
                    . 'five there would let one person mistyping their password lock out everybody behind it. '
                    . 'Raise it for a busy shared address; lower it towards the per-account figure for an '
                    . 'installation only you reach. It is never used below that figure, because an address that '
                    . 'locked first would put the door shut before the per-account count could ever fill.',
                'default' => 20, 'min' => 1, 'max' => 1000, 'admin_only' => true,
            ],
            [
                'key' => 'security.attempt_window_minutes', 'group' => 'security', 'type' => 'int',
                'label' => 'Counting window', 'unit' => 'minutes',
                'description' => 'Failures older than this stop counting towards a lockout.',
                'default' => 15, 'min' => 1, 'max' => 1440, 'admin_only' => true,
            ],
            [
                'key' => 'security.lockout_minutes', 'group' => 'security', 'type' => 'int',
                'label' => 'Lockout length', 'unit' => 'minutes',
                'default' => 15, 'min' => 1, 'max' => 1440, 'admin_only' => true,
            ],
            [
                'key' => 'security.session_lifetime_minutes', 'group' => 'security', 'type' => 'int',
                'label' => 'Sign out after inactivity', 'unit' => 'minutes',
                'default' => 480, 'min' => 5, 'max' => 43200, 'admin_only' => true,
            ],

            /* ------------------------------------------------------ updates */
            [
                'key' => 'updates.enabled', 'group' => 'updates', 'type' => 'bool',
                'label' => 'Updates enabled',
                'description' => 'Off means CourseForge never contacts GitHub and never replaces its own files.',
                'default' => true, 'admin_only' => true,
            ],
            [
                'key' => 'updates.repository', 'group' => 'updates', 'type' => 'string',
                'label' => 'GitHub repository',
                'description' => 'Where releases are published, as owner/name. Change it only if you run your own fork.',
                'default' => 'ZcarecroW/CourseForge', 'admin_only' => true,
            ],
            [
                'key' => 'updates.channel', 'group' => 'updates', 'type' => 'enum',
                'label' => 'Channel',
                'description' => 'Stable takes published releases only. Pre-release also takes betas and release candidates.',
                'options' => [
                    ['value' => 'stable', 'label' => 'Stable releases'],
                    ['value' => 'prerelease', 'label' => 'Also pre-releases'],
                ],
                'default' => 'stable', 'admin_only' => true,
            ],
            [
                'key' => 'updates.auto_check', 'group' => 'updates', 'type' => 'bool',
                'label' => 'Check for updates automatically',
                'description' => 'Once a day, from the scheduler, so this screen is current when you open it. Needs cron. Automatic installation asks GitHub on its own and does not depend on this.',
                'default' => true, 'admin_only' => true,
            ],
            [
                'key' => 'updates.auto_install', 'group' => 'updates', 'type' => 'bool',
                'label' => 'Install them automatically',
                'description' => 'Unattended updates at the time below, from the scheduler. Needs cron. A backup is taken first and restored if the new version fails to start.',
                'default' => false, 'admin_only' => true,
            ],
            [
                'key' => 'updates.auto_time', 'group' => 'updates', 'type' => 'time',
                'label' => 'Time of day',
                'description' => 'Local server time. Pick an hour when nobody is generating anything.',
                'default' => '05:00', 'admin_only' => true,
            ],
            [
                'key' => 'updates.timezone', 'group' => 'updates', 'type' => 'string',
                'label' => 'Time zone for that clock',
                'description' => 'An IANA name such as Europe/Berlin. CourseForge itself works in UTC; this is only for reading the hour above.',
                'default' => 'UTC', 'admin_only' => true, 'advanced' => true,
            ],
            [
                'key' => 'updates.keep_backups', 'group' => 'updates', 'type' => 'int',
                'label' => 'Keep backups of', 'unit' => 'versions',
                'description' => 'How many replaced versions stay on disk so an update can be rolled back.',
                'default' => 2, 'min' => 0, 'max' => 10, 'admin_only' => true,
            ],
            [
                'key' => 'updates.github_token', 'group' => 'updates', 'type' => 'secret',
                'label' => 'GitHub token',
                'description' => 'Only needed for a private fork, or to lift the 60-requests-an-hour limit GitHub applies per IP address to anonymous callers.',
                'default' => '', 'admin_only' => true, 'advanced' => true,
            ],
        ];
    }

    /** @return array<int,array{key:string,label:string,description:string}> */
    public static function groups(): array
    {
        return [
            ['key' => 'general', 'label' => 'General', 'description' => 'What this installation is called and how it behaves by default.'],
            ['key' => 'scheduler', 'label' => 'Scheduler', 'description' => 'The cron endpoint that keeps writing courses after the browser is closed.'],
            ['key' => 'batch', 'label' => 'Batch and runs', 'description' => 'How queued generation is polled and how long its records are kept.'],
            ['key' => 'updates', 'label' => 'Updates', 'description' => 'Checking GitHub for a new version, and installing it.'],
            ['key' => 'mcp', 'label' => 'MCP', 'description' => 'The endpoint Claude and other MCP clients connect to.'],
            ['key' => 'claude_cli', 'label' => 'Claude subscription', 'description' => 'The locally installed Claude Code CLI, for an installation running on your own machine.'],
            ['key' => 'security', 'label' => 'Security', 'description' => 'Sign-in throttling and session lifetime.'],
            ['key' => 'timeouts', 'label' => 'Timeouts', 'description' => 'How long CourseForge waits for somebody else.'],
        ];
    }

    /** @return array<string,array<string,mixed>> keyed by setting key */
    public static function byKey(): array
    {
        static $index = null;
        if ($index === null) {
            $index = [];
            foreach (self::catalogue() as $field) {
                $index[$field['key']] = $field;
            }
        }
        return $index;
    }

    /** @return array<string,mixed>|null */
    public static function field(string $key): ?array
    {
        return self::byKey()[$key] ?? null;
    }

    /**
     * The catalogue with the current values filled in, ready for the UI.
     *
     * A secret never travels: `value` is empty and `is_set` says whether one is
     * stored, which is all a form needs in order to render "leave blank to keep".
     *
     * @return array<int,array<string,mixed>>
     */
    public static function describe(Actor $actor): array
    {
        $out = [];
        foreach (self::catalogue() as $field) {
            if (($field['admin_only'] ?? false) && !$actor->isAdmin()) {
                continue;
            }
            $key = $field['key'];
            $value = Config::get($key, $field['default']);

            if (($field['type'] ?? '') === 'secret') {
                $field['is_set'] = is_string($value) && $value !== '';
                $field['value'] = '';
            } else {
                $field['value'] = $value;
            }
            $field['is_overridden'] = Config::isOverridden($key);
            $out[] = $field;
        }
        return $out;
    }

    /**
     * Validates and coerces one submitted value.
     *
     * @throws HttpException when the key is unknown or the value is not usable
     */
    public static function coerce(string $key, mixed $value): mixed
    {
        $field = self::field($key);
        if ($field === null) {
            throw HttpException::unprocessable('There is no setting called "' . $key . '".');
        }

        $label = (string)$field['label'];

        return match ($field['type']) {
            'bool' => self::coerceBool($value, $label),

            'int' => self::coerceInt($value, $field, $label),

            'enum' => self::coerceEnum($value, $field, $label),

            'time' => self::coerceTime($value, $label),

            'list' => array_values(array_filter(array_map(
                static fn(mixed $v): string => is_scalar($v) ? trim((string)$v) : '',
                is_array($value) ? $value : (preg_split('/[\s,]+/', (string)$value) ?: [])
            ), static fn(string $v): bool => $v !== '')),

            'secret', 'string', 'text' => self::coerceString($value, $field, $label),

            default => throw HttpException::unprocessable('Setting "' . $key . '" has an unknown type.'),
        };
    }

    /**
     * A boolean, or a refusal.
     *
     * filter_var without FILTER_NULL_ON_FAILURE answers false for everything it
     * cannot read, so "banana", 2, -1 and null were all stored as OFF with an
     * HTTP 200. Every other type in this catalogue refuses what it cannot read
     * - int says "must be a number", enum lists its values - so a caller had no
     * reason to expect this one to be the lax one, and the failure mode was a
     * setting silently turned off rather than an error.
     */
    private static function coerceBool(mixed $value, string $label): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $read = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($read === null) {
            throw HttpException::unprocessable($label . ' must be true or false.');
        }
        return $read;
    }

    private static function coerceInt(mixed $value, array $field, string $label): int
    {
        if (!is_numeric($value)) {
            throw HttpException::unprocessable($label . ' must be a number.');
        }
        // A JSON body carrying 1e400 arrives as the float INF, which is numeric
        // and cannot be cast to an integer - the cast is a warning, and a
        // warning here is a 500 for input that is merely out of range.
        if (!is_finite((float)$value)) {
            throw HttpException::unprocessable($label . ' must be a number, and that one is too large to be one.');
        }
        $n = (int)$value;
        $min = (int)($field['min'] ?? PHP_INT_MIN);
        $max = (int)($field['max'] ?? PHP_INT_MAX);
        if ($n < $min || $n > $max) {
            throw HttpException::unprocessable($label . ' must be between ' . $min . ' and ' . $max . '.');
        }
        return $n;
    }

    private static function coerceEnum(mixed $value, array $field, string $label): string
    {
        $allowed = array_map(static fn(array $o): string => (string)$o['value'], (array)($field['options'] ?? []));
        $v = is_scalar($value) ? (string)$value : '';
        if (!in_array($v, $allowed, true)) {
            throw HttpException::unprocessable($label . ' must be one of: ' . implode(', ', $allowed) . '.');
        }
        return $v;
    }

    private static function coerceTime(mixed $value, string $label): string
    {
        $v = is_scalar($value) ? trim((string)$value) : '';
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $v) !== 1) {
            throw HttpException::unprocessable($label . ' must be a time of day as HH:MM, between 00:00 and 23:59.');
        }
        return $v;
    }

    private static function coerceString(mixed $value, array $field, string $label): string
    {
        if (is_array($value)) {
            throw HttpException::unprocessable($label . ' must be text.');
        }
        $v = trim((string)$value);
        if ($field['key'] === 'updates.repository' && $v !== '' && preg_match('#^[\w.-]+/[\w.-]+$#', $v) !== 1) {
            throw HttpException::unprocessable('A repository is written as owner/name, for instance ZcarecroW/CourseForge.');
        }
        if ($field['key'] === 'updates.timezone' && $v !== '' && !in_array($v, timezone_identifiers_list(), true)) {
            throw HttpException::unprocessable('That is not a time zone PHP knows. Use an IANA name such as Europe/Berlin.');
        }
        // Both public URLs, not just one. app.public_url's whole job is to be
        // the base of an address somebody pastes into a hosting control panel,
        // and it was the one string in this catalogue with no check at all - so
        // "not a url" was stored and handed back as
        // "not a url/cron.php?token=..." beside configured: true.
        if (in_array($field['key'], ['mcp.public_url', 'app.public_url'], true)
            && $v !== ''
            && !filter_var($v, FILTER_VALIDATE_URL)) {
            throw HttpException::unprocessable('The public URL has to be a full address, starting with https://.');
        }
        if ($field['key'] === 'app.cron_token' && $v !== '' && mb_strlen($v) < 16) {
            throw HttpException::unprocessable(
                'A cron token shorter than 16 characters is worth less than no token at all. Use the Generate button.'
            );
        }
        return $v;
    }
}
