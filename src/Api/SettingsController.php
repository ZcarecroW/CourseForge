<?php
declare(strict_types=1);

namespace CourseForge\Api;

use CourseForge\Ai\Run\RunManager;
use CourseForge\Security\Actor;
use CourseForge\Support\Audit;
use CourseForge\Support\Config;
use CourseForge\Support\Cron;
use CourseForge\Support\Diagnostics;
use CourseForge\Support\HttpException;
use CourseForge\Support\Request;
use CourseForge\Support\Settings;

/**
 * The Settings screen.
 *
 * Reads and writes `data/config.json` through the declared catalogue in
 * `Support\Settings`, which is the only place that knows a setting exists. A
 * key that is not in the catalogue cannot be written here at all - the API
 * surface is the catalogue, not the file.
 *
 * Secrets are asymmetric on purpose: they go in and never come back out. The
 * screen is told whether one is stored, so it can say "leave blank to keep".
 */
final class SettingsController
{
    /** @return array<string,mixed> */
    public static function show(Request $request, ?Actor $actor): array
    {
        $me = self::admin($actor);

        return [
            'groups' => Settings::groups(),
            'settings' => Settings::describe($me),
            'scheduler' => self::scheduler(),
            'prompt_groups' => Config::promptGroups(),
            'prompt_slots' => Config::promptSlots(),
            'defaults_file' => basename(Config::defaultsFile()),
            'overrides_file' => Config::file(),
        ];
    }

    /** Saves any number of settings in one write. */
    public static function update(Request $request, ?Actor $actor): array
    {
        $me = self::admin($actor);

        $values = $request->arr('values');
        if ($values === []) {
            throw HttpException::unprocessable('Nothing to save.');
        }

        $write = [];
        $changed = [];
        foreach ($values as $key => $value) {
            $key = (string)$key;
            $field = Settings::field($key);
            if ($field === null) {
                throw HttpException::unprocessable('There is no setting called "' . $key . '".');
            }
            // An empty secret means "leave what is stored alone", because the
            // form could not have shown it in the first place.
            if ($field['type'] === 'secret' && trim((string)$value) === '') {
                continue;
            }
            $write[$key] = Settings::coerce($key, $value);
            $changed[] = $key;
        }

        if ($write !== []) {
            Config::setMany($write);
            Audit::record($me->username, 'settings.update', implode(', ', $changed));
        }

        return [
            'settings' => Settings::describe($me),
            'scheduler' => self::scheduler(),
            'saved' => $changed,
        ];
    }

    /** Puts one or more settings back to what the release ships. */
    public static function reset(Request $request, ?Actor $actor): array
    {
        $me = self::admin($actor);

        $keys = array_values(array_filter(array_map(
            static fn(mixed $k): string => is_scalar($k) ? (string)$k : '',
            (array)($request->all()['keys'] ?? [])
        )));
        if ($keys === []) {
            throw HttpException::unprocessable('Name the settings to reset.');
        }

        foreach ($keys as $key) {
            if (Settings::field($key) === null) {
                throw HttpException::unprocessable('There is no setting called "' . $key . '".');
            }
            Config::reset($key);
        }
        Audit::record($me->username, 'settings.reset', implode(', ', $keys));

        return ['settings' => Settings::describe($me), 'scheduler' => self::scheduler()];
    }

    /**
     * Generates a cron token and stores it.
     *
     * It is returned once so the screen can show the finished URL to paste into
     * a control panel; afterwards it is a secret like any other.
     */
    public static function cronToken(Request $request, ?Actor $actor): array
    {
        $me = self::admin($actor);

        $token = bin2hex(random_bytes(24));
        Config::set('app.cron_token', $token);
        Audit::record($me->username, 'settings.cron_token', '', 'regenerated');

        return [
            'token' => $token,
            'scheduler' => self::scheduler(),
            'settings' => Settings::describe($me),
        ];
    }

    /** The prompt library, as the release ships it plus this installation's edits. */
    public static function prompts(Request $request, ?Actor $actor): array
    {
        self::admin($actor);

        $defaults = Config::defaults()['prompts'] ?? [];
        $slots = Config::promptSlots();
        foreach ($slots as $key => $slot) {
            $slots[$key]['default'] = (string)($defaults[$key]['value'] ?? '');
            $slots[$key]['is_overridden'] = Config::isOverridden('prompts.' . $key . '.value');
        }

        return ['groups' => Config::promptGroups(), 'slots' => $slots];
    }

    /**
     * Saves installation-wide prompt overrides.
     *
     * These are the base layer. A profile may still override any of them for
     * its own courses, which is where per-course tuning belongs.
     */
    public static function savePrompts(Request $request, ?Actor $actor): array
    {
        $me = self::admin($actor);

        $slots = Config::promptSlots();
        $write = [];
        $reset = [];

        foreach ($request->arr('prompts') as $key => $value) {
            $key = (string)$key;
            if (!isset($slots[$key])) {
                throw HttpException::unprocessable('There is no prompt slot called "' . $key . '".');
            }
            if (!is_scalar($value)) {
                throw HttpException::unprocessable('Prompt "' . $key . '" must be text.');
            }
            $text = (string)$value;
            if (trim($text) === '') {
                $reset[] = $key;
                continue;
            }
            $write['prompts.' . $key . '.value'] = $text;
        }

        foreach ($reset as $key) {
            Config::reset('prompts.' . $key . '.value');
        }
        if ($write !== []) {
            Config::setMany($write);
        }
        if ($write !== [] || $reset !== []) {
            Audit::record($me->username, 'settings.prompts', (string)(count($write) + count($reset)) . ' slot(s)');
        }

        return self::prompts($request, $actor);
    }

    /** The installation check, as JSON, so it can be read without shell access. */
    public static function diagnostics(Request $request, ?Actor $actor): array
    {
        self::admin($actor);
        return ['report' => Diagnostics::run()];
    }

    /* -------------------------------------------------------------- helpers */

    /** Everything the Scheduler card needs, including the URL to paste. */
    private static function scheduler(): array
    {
        $status = RunManager::cronStatus();
        $token = Config::str('app.cron_token', '');

        return $status + [
            'configured' => $token !== '',
            'url' => $token === '' ? '' : Cron::publicUrl($token),
            'cli' => 'php ' . CF_ROOT . '/tools/cron.php --quiet',
            'workers' => Config::int('app.cron_workers', 2),
            'seconds' => Config::int('app.cron_seconds', 50),
        ];
    }

    private static function admin(?Actor $actor): Actor
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $me->requireAdmin();
        return $me;
    }
}
