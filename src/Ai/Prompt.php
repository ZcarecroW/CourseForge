<?php
declare(strict_types=1);

namespace CourseForge\Ai;

use CourseForge\Domain\Details;
use CourseForge\Support\Config;

/**
 * The prompt library: config defaults, profile overrides, placeholders.
 *
 * Every slot in data/config.json can be overridden per profile. An override
 * that is an empty string is honoured on purpose – the UI documents it as
 * "send nothing for this slot", which is how a user switches a whole block of
 * instructions off without editing the config file.
 */
final class Prompt
{
    /**
     * Config defaults with the profile's overrides layered on top.
     *
     * @param array<string,mixed> $profile
     * @return array<string,string>
     */
    public static function library(array $profile): array
    {
        $prompts = Config::defaultPrompts();
        foreach ((array)($profile['prompts'] ?? []) as $key => $value) {
            if (is_string($value)) {
                $prompts[(string)$key] = $value;
            }
        }
        return $prompts;
    }

    /**
     * Substitutes `{{name}}` placeholders. Unknown braces are left alone, which
     * is what keeps Anki's own `{{c1::…}}` syntax intact.
     *
     * @param array<string,scalar|null> $vars
     */
    public static function render(string $template, array $vars): string
    {
        if (trim($template) === '') {
            return '';
        }
        $search = [];
        $replace = [];
        foreach ($vars as $key => $value) {
            $search[] = '{{' . $key . '}}';
            $replace[] = (string)$value;
        }
        return trim(str_replace($search, $replace, $template));
    }

    /**
     * Renders an optional slot. A blank slot contributes nothing – that is how
     * a whole block of instructions is switched off from the UI.
     *
     * @param array<string,string> $library
     * @param array<string,scalar|null> $vars
     */
    public static function slot(array $library, string $key, array $vars): string
    {
        return self::render((string)($library[$key] ?? ''), $vars);
    }

    /**
     * Renders a slot the pipeline cannot work without, substituting a compact
     * built-in text when the configured value is blank.
     *
     * @param array<string,string> $library
     * @param array<string,scalar|null> $vars
     */
    public static function slotOrDefault(array $library, string $key, array $vars, string $default): string
    {
        $template = (string)($library[$key] ?? '');
        return self::render(trim($template) !== '' ? $template : $default, $vars);
    }

    /** Joins non-empty fragments with a blank line between them. */
    public static function join(string ...$parts): string
    {
        $clean = array_filter(array_map('trim', $parts), static fn(string $p): bool => $p !== '');
        return trim(implode("\n\n", $clean));
    }

    /**
     * The instruction block for one page's content details.
     *
     * Walks the catalogue in its configured order and appends the "enabled" or
     * "disabled" text of every feature, so the model receives one coherent,
     * fully explicit contract instead of a pile of conditionals.
     *
     * @param array<string,string> $library
     * @param array<string,bool> $features
     * @param array<string,scalar|null> $vars
     */
    public static function detailRules(array $library, array $features, array $vars): string
    {
        $parts = [];
        foreach (Details::featureKeys() as $key) {
            $suffix = ($features[$key] ?? false) ? 'on' : 'off';
            $parts[] = self::render((string)($library['feature_' . $key . '_' . $suffix] ?? ''), $vars);
        }
        return self::join(...$parts);
    }
}
