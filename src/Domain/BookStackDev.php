<?php
declare(strict_types=1);

namespace CourseForge\Domain;

use CourseForge\Ai\Prompt;
use CourseForge\Support\Config;
use CourseForge\Support\Db;
use CourseForge\Support\HttpException;
use CourseForge\Support\PublicUrl;

/**
 * BookStackDev profiles: the look and feel a BookStack instance is given.
 *
 * BookStackDev is a set of front-end enhancements for BookStack - a Shiki code
 * highlighter that knows every language, Mermaid diagrams, MathJax formulas,
 * link embeds, an audio player, external-link decoration, a light/dark button
 * and a few page-styling opinions - loaded by one script tag in BookStack's
 * custom head. It used to be a folder somebody hosted on a web server and
 * configured by editing the top of a JavaScript file.
 *
 * Here it is a row. A profile holds the configuration, CourseForge serves the
 * loader and every asset from `bs.php`, and the script tag a BookStack
 * administrator pastes carries the profile's key. That link is not a CDN:
 * `bs.php` answers only when the browser says the page it is loading into
 * belongs to an origin the profile allows - the BookStack instances of the
 * CourseForge profiles that use this look, plus any address typed in by hand
 * for an instance CourseForge holds no credentials for. Copy the link into
 * some other wiki and it is refused, by name.
 *
 * The prompts CourseForge writes pages with assume the conventions this look
 * renders - `\( ... \)` for inline math, `$$` for display math, fenced
 * `mermaid` blocks. A profile configured the other way round would publish
 * pages whose formulas never render, so audit() compares the two and says
 * where they disagree, with the wording that would put them back in step.
 */
final class BookStackDev
{
    /** Shiki 4.4.3's bundled themes, read off the registry on 2026-09-02. Suggestions: the picker takes any name. */
    public const SHIKI_THEMES = [
        'andromeeda', 'aurora-x', 'ayu-dark', 'ayu-light', 'ayu-mirage', 'catppuccin-frappe', 'catppuccin-latte',
        'catppuccin-macchiato', 'catppuccin-mocha', 'dark-plus', 'dracula', 'dracula-soft', 'everforest-dark',
        'everforest-light', 'github-dark', 'github-dark-default', 'github-dark-dimmed', 'github-dark-high-contrast',
        'github-light', 'github-light-default', 'github-light-high-contrast', 'gruvbox-dark-hard',
        'gruvbox-dark-medium', 'gruvbox-dark-soft', 'gruvbox-light-hard', 'gruvbox-light-medium',
        'gruvbox-light-soft', 'horizon', 'horizon-bright', 'houston', 'kanagawa-dragon', 'kanagawa-lotus',
        'kanagawa-wave', 'laserwave', 'light-plus', 'material-theme', 'material-theme-darker',
        'material-theme-lighter', 'material-theme-ocean', 'material-theme-palenight', 'min-dark', 'min-light',
        'monokai', 'night-owl', 'night-owl-light', 'nord', 'one-dark-pro', 'one-light', 'plastic', 'poimandres',
        'red', 'rose-pine', 'rose-pine-dawn', 'rose-pine-moon', 'slack-dark', 'slack-ochin', 'snazzy-light',
        'solarized-dark', 'solarized-light', 'synthwave-84', 'tokyo-night', 'vesper', 'vitesse-black',
        'vitesse-dark', 'vitesse-light',
    ];

    public const MERMAID_THEMES = ['default', 'neutral', 'forest', 'base', 'dark'];
    public const POSITIONS = ['bottom-left', 'bottom-right', 'top-left', 'top-right'];

    /** Where the loader and its modules live, relative to the installation root. */
    public const ASSET_DIR = 'assets/bookstackdev';
    public const LOADER = 'js/milelo.de_bookstack.js';

    /** The prompt slot whose wording depends on the formula delimiters this look renders. */
    public const MATH_SLOT = 'feature_mathjax_on';

    /* ------------------------------------------------------------ catalogue */

    /**
     * Every option, grouped by the feature it belongs to.
     *
     * Three things read this list and nothing else describes an option: the
     * screen, which draws a card per group and a control per field from its
     * type; normalise(), which validates a submitted profile against it; and
     * the MCP tool that lists what a client may set. A field's `default` is
     * what a new profile starts with, and what the shipped BookStackDev folder
     * ships - the two are kept the same on purpose.
     *
     * @return array<int,array{key:string,label:string,icon:string,tone:string,summary:string,
     *     description:string,toggle:string,fields:array<int,array<string,mixed>>}>
     */
    public static function catalogue(): array
    {
        return [
            [
                'key' => 'codeBlocks', 'label' => 'Code blocks', 'icon' => 'code', 'tone' => 'accent', 'toggle' => 'enabled',
                'summary' => 'Shiki highlighting in every language it ships, with line numbers, wrapping, a copy button '
                    . 'and a collapse for long listings.',
                'description' => 'Replaces BookStack\'s own code viewer with a Shiki highlighter: VS Code grammars for '
                    . 'about 220 languages and their aliases, fetched the first time a block in that language '
                    . 'appears. A block that names no language is recognised by highlight.js and marked "auto". '
                    . 'Wrapping and line numbers are remembered per browser; a very tall block is collapsed with a '
                    . '"Show all lines" button. Mermaid blocks are left to the diagram renderer.',
                'fields' => [
                    ['key' => 'enabled', 'type' => 'bool', 'label' => 'Highlight code blocks', 'default' => true,
                        'description' => 'Off leaves BookStack\'s CodeMirror viewer exactly as it is.'],
                    ['key' => 'themeLight', 'type' => 'string', 'label' => 'Theme in light mode', 'default' => 'one-light',
                        'suggest' => 'shiki_themes', 'description' => 'Any theme Shiki bundles. Fuzzy search the list, or type a name.'],
                    ['key' => 'themeDark', 'type' => 'string', 'label' => 'Theme in dark mode', 'default' => 'one-dark-pro',
                        'suggest' => 'shiki_themes', 'description' => 'Swapped in the moment BookStack switches to dark mode - no re-render.'],
                    ['key' => 'lineNumbers', 'type' => 'bool', 'label' => 'Line numbers on by default', 'default' => true,
                        'description' => 'A reader can still switch them per browser; this is what a first visit sees.'],
                    ['key' => 'wrap', 'type' => 'bool', 'label' => 'Wrap long lines by default', 'default' => true,
                        'description' => 'Off scrolls a long line sideways instead.'],
                    ['key' => 'collapseHeight', 'type' => 'int', 'label' => 'Collapse blocks taller than', 'unit' => 'px',
                        'default' => 560, 'min' => 0, 'max' => 5000, 'step' => 20,
                        'description' => 'A listing taller than this is folded to this height with a "Show all lines" button. 0 never folds.'],
                    ['key' => 'detect', 'type' => 'bool', 'label' => 'Recognise the language of an unlabelled block', 'default' => true,
                        'description' => 'highlight.js guesses; a weak guess is left as plain text rather than painted wrong.'],
                    ['key' => 'showDetectedHint', 'type' => 'bool', 'label' => 'Mark a recognised language with an "auto" badge', 'default' => true],
                    ['key' => 'detectMinRelevance', 'type' => 'int', 'label' => 'How sure the guess has to be', 'default' => 5,
                        'min' => 1, 'max' => 50, 'step' => 1, 'advanced' => true,
                        'description' => 'highlight.js relevance below which a guess is discarded. Higher is stricter; a three-line block needs twice this.'],
                    ['key' => 'disableCodeMirror', 'type' => 'bool', 'label' => 'Take over BookStack\'s viewer as it mounts', 'default' => true, 'advanced' => true,
                        'description' => 'BookStack mounts a CodeMirror viewer on every block; this replaces it with the highlighter. Editors are never touched.'],
                    ['key' => 'lazy', 'type' => 'bool', 'label' => 'Highlight blocks only as they scroll into view', 'default' => false, 'advanced' => true,
                        'description' => 'Saves work on a very long page. Until a block is reached it is shown as plain text.'],
                    ['key' => 'fallbackLang', 'type' => 'string', 'label' => 'Language when nothing is known', 'default' => 'plaintext', 'advanced' => true],
                    ['key' => 'skipLanguages', 'type' => 'list', 'label' => 'Languages never touched', 'default' => ['mermaid', 'mmd'], 'advanced' => true,
                        'description' => 'Mermaid is here because the diagram renderer owns it. One per line.'],
                    ['key' => 'containers', 'type' => 'string', 'label' => 'Read-only content areas', 'advanced' => true,
                        'default' => '.page-content, .page-revision, .comment-container, .comment-body, .description, .book-content, .chapter-content, .markdown-display',
                        'description' => 'CSS selectors. Only a block inside one of these is highlighted, and only a viewer inside one is taken over.'],
                    ['key' => 'shikiUrl', 'type' => 'string', 'label' => 'Shiki module URL', 'default' => 'https://esm.sh/shiki@4', 'advanced' => true,
                        'description' => 'The first address tried; jsDelivr and unpkg stand behind it. Shiki 4 was current when this was written.'],
                    ['key' => 'hljsUrl', 'type' => 'string', 'label' => 'highlight.js module URL', 'default' => 'https://esm.sh/highlight.js@11', 'advanced' => true],
                    ['key' => 'debug', 'type' => 'bool', 'label' => 'Log what the highlighter decides to the console', 'default' => false, 'advanced' => true],
                ],
            ],
            [
                'key' => 'mermaid', 'label' => 'Mermaid diagrams', 'icon' => 'diagram', 'tone' => 'accent', 'toggle' => 'enabled',
                'summary' => 'Fenced mermaid blocks drawn as diagrams, in a theme that follows light and dark mode.',
                'description' => 'Every ```mermaid block - and every block whose first line reads like a diagram - is '
                    . 'rendered with Mermaid, including blocks that arrive after the page has loaded. Switching '
                    . 'the site to dark mode redraws them in the dark theme. The prompts CourseForge writes pages '
                    . 'with rely on this: with it off, a page\'s diagrams are shown as their source text.',
                'fields' => [
                    ['key' => 'enabled', 'type' => 'bool', 'label' => 'Render Mermaid diagrams', 'default' => true],
                    ['key' => 'themeLight', 'type' => 'enum', 'label' => 'Theme in light mode', 'default' => 'default',
                        'options' => self::MERMAID_THEMES],
                    ['key' => 'themeDark', 'type' => 'enum', 'label' => 'Theme in dark mode', 'default' => 'dark',
                        'options' => self::MERMAID_THEMES],
                    ['key' => 'url', 'type' => 'string', 'label' => 'Mermaid module URL', 'advanced' => true,
                        'default' => 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.esm.min.mjs',
                        'description' => 'The first address tried, with esm.sh and unpkg behind it. Mermaid 11 was current when this was written.'],
                ],
            ],
            [
                'key' => 'math', 'label' => 'MathJax formulas', 'icon' => 'sigma', 'tone' => 'accent', 'toggle' => 'enabled',
                'summary' => 'LaTeX formulas typeset on every page. Which delimiters open one is decided here - and '
                    . 'the page prompts have to agree.',
                'description' => 'MathJax 4 typesets the formulas on a page. The delimiters are the contract with '
                    . 'whoever writes the pages: CourseForge\'s prompts tell the model to write inline math as '
                    . '\\( ... \\) and display math as $$ ... $$, and to leave a single dollar sign alone so prices '
                    . 'stay prices. Change the delimiters and the conventions check below says which prompt has '
                    . 'to change with them.',
                'fields' => [
                    ['key' => 'enabled', 'type' => 'bool', 'label' => 'Typeset formulas', 'default' => true],
                    ['key' => 'inlineParens', 'type' => 'bool', 'label' => 'Inline: \\( ... \\)', 'default' => true,
                        'description' => 'What CourseForge\'s prompts write. Leave it on unless every page comes from somewhere else.'],
                    ['key' => 'inlineDollar', 'type' => 'bool', 'label' => 'Inline: $ ... $', 'default' => false,
                        'description' => 'Off by default on purpose: with it on, "$50 and $80" becomes a formula.'],
                    ['key' => 'displayDollars', 'type' => 'bool', 'label' => 'Display: $$ ... $$', 'default' => true],
                    ['key' => 'displayBrackets', 'type' => 'bool', 'label' => 'Display: \\[ ... \\]', 'default' => false],
                    ['key' => 'url', 'type' => 'string', 'label' => 'MathJax script URL', 'advanced' => true,
                        'default' => 'https://cdn.jsdelivr.net/npm/mathjax@4/tex-mml-chtml.js',
                        'description' => 'MathJax 4 was current when this was written. Its extended fonts are fetched from jsDelivr on demand.'],
                ],
            ],
            [
                'key' => 'theme', 'label' => 'Light and dark toggle', 'icon' => 'contrast', 'tone' => 'accent', 'toggle' => 'enabled',
                'summary' => 'A floating button that switches BookStack between light and dark, for visitors and signed-in users alike.',
                'description' => 'A signed-in user\'s choice is saved through BookStack\'s own preference; a visitor\'s '
                    . 'is remembered in the browser and applied before the first paint, so there is no flash of the '
                    . 'wrong theme. Diagrams and code blocks follow the switch.',
                'fields' => [
                    ['key' => 'enabled', 'type' => 'bool', 'label' => 'Show the toggle button', 'default' => true],
                    ['key' => 'position', 'type' => 'enum', 'label' => 'Corner', 'default' => 'bottom-left',
                        'options' => self::POSITIONS],
                    ['key' => 'offset', 'type' => 'int', 'label' => 'Distance from the edges', 'unit' => 'px',
                        'default' => 20, 'min' => 0, 'max' => 200, 'step' => 2],
                    ['key' => 'size', 'type' => 'int', 'label' => 'Button size', 'unit' => 'px',
                        'default' => 44, 'min' => 24, 'max' => 96, 'step' => 2],
                    ['key' => 'storageKey', 'type' => 'string', 'label' => 'Browser storage key', 'default' => 'bookstack-guest-dark-mode',
                        'advanced' => true, 'description' => 'Where a visitor\'s choice is remembered. Change it only if it collides with another script.'],
                ],
            ],
            [
                'key' => 'page', 'label' => 'Page styling', 'icon' => 'zoom', 'tone' => 'accent', 'toggle' => 'enabled',
                'summary' => 'A slightly larger page, softer text colours, and an optional background image behind everything.',
                'description' => 'Small opinions about how a page reads: the content is zoomed a little with headings '
                    . 'scaled back, text and headings take a softer grey than BookStack\'s, and a background '
                    . 'image can sit behind the whole site at low opacity. Code blocks compensate for the zoom so '
                    . 'their rows stay whole pixels.',
                'fields' => [
                    ['key' => 'enabled', 'type' => 'bool', 'label' => 'Apply page styling', 'default' => true],
                    ['key' => 'zoom', 'type' => 'float', 'label' => 'Content zoom', 'default' => 1.15,
                        'min' => 0.5, 'max' => 2, 'step' => 0.05, 'description' => '1 is BookStack\'s own size.'],
                    ['key' => 'headingZoom', 'type' => 'float', 'label' => 'Headings scaled by', 'default' => 0.9,
                        'min' => 0.5, 'max' => 1.5, 'step' => 0.05, 'description' => 'Applied on top of the content zoom, so headings do not grow twice.'],
                    ['key' => 'tintText', 'type' => 'bool', 'label' => 'Softer text and heading colours', 'default' => true],
                    ['key' => 'backgroundImage', 'type' => 'string', 'label' => 'Background image URL', 'default' => '',
                        'placeholder' => 'https://wiki.example.com/uploads/images/…', 'description' => 'Fixed behind the whole site. Empty for none.'],
                    ['key' => 'backgroundOpacity', 'type' => 'float', 'label' => 'Background image opacity', 'default' => 0.05,
                        'min' => 0, 'max' => 1, 'step' => 0.01],
                ],
            ],
            [
                'key' => 'autoEmbed', 'label' => 'Link embeds', 'icon' => 'film', 'tone' => 'accent', 'toggle' => 'enabled',
                'summary' => 'A link alone on its line becomes the player or preview it points at - YouTube, Vimeo, Spotify, CodePen, Figma and more.',
                'description' => 'A paragraph that holds nothing but one link to YouTube, Vimeo, Dailymotion, Twitch, '
                    . 'Loom, Streamable, Spotify, SoundCloud, CodePen, CodeSandbox, StackBlitz, JSFiddle, a GitHub '
                    . 'Gist, Figma or Google Maps is replaced with a responsive frame. Providers are matched on the '
                    . 'real hostname, and a Gist runs sandboxed.',
                'fields' => [
                    ['key' => 'enabled', 'type' => 'bool', 'label' => 'Embed links', 'default' => true],
                    ['key' => 'pageViewOnly', 'type' => 'bool', 'label' => 'Only on a page, never on a book or shelf overview', 'default' => true],
                    ['key' => 'linksOnly', 'type' => 'bool', 'label' => 'Only a real link, never a bare typed URL', 'default' => true],
                    ['key' => 'scope', 'type' => 'string', 'label' => 'Where an embed may appear', 'default' => '.page-content', 'advanced' => true,
                        'description' => 'CSS selector.'],
                ],
            ],
            [
                'key' => 'audioPlayer', 'label' => 'Audio player', 'icon' => 'headphones', 'tone' => 'accent', 'toggle' => 'enabled',
                'summary' => 'A link to an audio file becomes an inline player, with a download link if the file cannot be played.',
                'description' => 'Recognised by the extension on the link, or on its text for a BookStack attachment. '
                    . 'Navigation links are never touched.',
                'fields' => [
                    ['key' => 'enabled', 'type' => 'bool', 'label' => 'Play audio links inline', 'default' => true],
                    ['key' => 'extensions', 'type' => 'list', 'label' => 'File types', 'default' => ['mp3', 'm4a'],
                        'description' => 'One per line. ogg, wav, flac, aac and opus are understood as well.'],
                    ['key' => 'scope', 'type' => 'string', 'label' => 'Where a link is converted', 'advanced' => true,
                        'default' => '.page-content, .comment-body, .description, .markdown-display', 'description' => 'CSS selectors.'],
                ],
            ],
            [
                'key' => 'externalLinks', 'label' => 'External links', 'icon' => 'external', 'tone' => 'accent', 'toggle' => 'enabled',
                'summary' => 'A link that leaves the wiki opens in a new tab and carries a small arrow after its text.',
                'fields' => [
                    ['key' => 'enabled', 'type' => 'bool', 'label' => 'Decorate external links', 'default' => true],
                    ['key' => 'newTab', 'type' => 'bool', 'label' => 'Open in a new tab', 'default' => true],
                    ['key' => 'icon', 'type' => 'bool', 'label' => 'Show an arrow after the link', 'default' => true],
                    ['key' => 'fontAwesome', 'type' => 'bool', 'label' => 'Use the Font Awesome glyph instead of the built-in one', 'default' => false,
                        'description' => 'Loads the Font Awesome 7 stylesheet from a CDN for one glyph. The built-in arrow costs no request.'],
                    ['key' => 'ignoreHosts', 'type' => 'list', 'label' => 'Hosts treated as internal', 'default' => [],
                        'description' => 'One per line - a mirror, a sub-domain, a sister site.'],
                ],
            ],
            [
                'key' => 'markdown', 'label' => 'Markdown editor', 'icon' => 'paragraph', 'tone' => 'accent', 'toggle' => 'singleLineBreaks',
                'summary' => 'In BookStack\'s Markdown editor, a single line break is a line break.',
                'description' => 'Affects the editor\'s live preview and pages saved from it. A page CourseForge '
                    . 'publishes over the API is rendered by BookStack\'s server, which keeps the standard rule - '
                    . 'so this changes nothing about generated courses.',
                'fields' => [
                    ['key' => 'singleLineBreaks', 'type' => 'bool', 'label' => 'A single newline becomes a line break', 'default' => true],
                ],
            ],
        ];
    }

    /** The shipped value of every option, grouped. @return array<string,array<string,mixed>> */
    public static function defaults(): array
    {
        $out = [];
        foreach (self::catalogue() as $group) {
            foreach ($group['fields'] as $field) {
                $out[$group['key']][$field['key']] = $field['default'];
            }
        }
        return $out;
    }

    /**
     * A submitted configuration, validated field by field against the catalogue.
     *
     * Every field comes back, whether or not it was sent: an unknown key is
     * dropped, a missing one takes its default, a number is clamped to its
     * range and a choice outside its options falls back. Nothing here throws,
     * because the one thing a look must never do is refuse to load - a value
     * out of range is a value corrected, not a profile lost.
     *
     * @param array<string,mixed> $raw
     * @return array<string,array<string,mixed>>
     */
    public static function normalise(array $raw): array
    {
        $out = [];
        foreach (self::catalogue() as $group) {
            $given = $raw[$group['key']] ?? [];
            if ($given instanceof \stdClass) {
                $given = (array)$given;
            }
            if (!is_array($given)) {
                $given = [];
            }
            foreach ($group['fields'] as $field) {
                $key = $field['key'];
                $out[$group['key']][$key] = self::coerce($field, array_key_exists($key, $given) ? $given[$key] : $field['default']);
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $field */
    private static function coerce(array $field, mixed $value): mixed
    {
        switch ($field['type']) {
            case 'bool':
                if (is_bool($value)) {
                    return $value;
                }
                $read = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                return $read ?? (bool)$field['default'];

            case 'int':
                if (!is_numeric($value) || !is_finite((float)$value)) {
                    return (int)$field['default'];
                }
                return max((int)($field['min'] ?? PHP_INT_MIN), min((int)($field['max'] ?? PHP_INT_MAX), (int)round((float)$value)));

            case 'float':
                if (!is_numeric($value) || !is_finite((float)$value)) {
                    return (float)$field['default'];
                }
                $n = max((float)($field['min'] ?? -INF), min((float)($field['max'] ?? INF), (float)$value));
                return round($n, 3);

            case 'enum':
                $v = is_scalar($value) ? strtolower(trim((string)$value)) : '';
                return in_array($v, (array)$field['options'], true) ? $v : $field['default'];

            case 'list':
                $items = is_array($value) ? $value : preg_split('/[\s,]+/', (string)(is_scalar($value) ? $value : ''));
                $clean = [];
                foreach ((array)$items as $item) {
                    $item = is_scalar($item) ? trim((string)$item) : '';
                    if ($item !== '' && !in_array($item, $clean, true)) {
                        $clean[] = $item;
                    }
                }
                return $clean;

            default:
                $v = is_scalar($value) ? trim((string)$value) : '';
                // A URL field that is emptied goes back to the shipped address
                // rather than to nothing: an empty module URL loads nothing.
                if ($v === '' && str_ends_with((string)$field['key'], 'rl') && (string)$field['default'] !== '') {
                    return (string)$field['default'];
                }
                return mb_substr($v, 0, 2000);
        }
    }

    /**
     * The object the loader reads, in the shape the shipped BookStackDev
     * folder's own CONFIG has - so the vendored modules stay byte-identical to
     * upstream and only this method knows both vocabularies.
     *
     * @param array<string,array<string,mixed>> $settings normalised
     * @return array<string,mixed>
     */
    public static function clientConfig(array $settings): array
    {
        $s = self::normalise($settings);
        $math = $s['math'];
        $inline = [];
        if ($math['inlineParens']) {
            $inline[] = ['\\(', '\\)'];
        }
        if ($math['inlineDollar']) {
            $inline[] = ['$', '$'];
        }
        $display = [];
        if ($math['displayDollars']) {
            $display[] = ['$$', '$$'];
        }
        if ($math['displayBrackets']) {
            $display[] = ['\\[', '\\]'];
        }

        $code = $s['codeBlocks'];

        return [
            'theme' => [
                'toggleButton' => $s['theme']['enabled'],
                'position' => $s['theme']['position'],
                'offset' => $s['theme']['offset'],
                'size' => $s['theme']['size'],
                'storageKey' => $s['theme']['storageKey'] !== '' ? $s['theme']['storageKey'] : 'bookstack-guest-dark-mode',
            ],
            'page' => $s['page'],
            'markdown' => $s['markdown'],
            'math' => [
                'enabled' => $math['enabled'] && ($inline !== [] || $display !== []),
                'url' => $math['url'],
                'inlineMath' => $inline,
                'displayMath' => $display,
            ],
            'mermaid' => $s['mermaid'],
            'autoEmbed' => $s['autoEmbed'],
            'audioPlayer' => $s['audioPlayer'],
            'externalLinks' => $s['externalLinks'],
            'codeBlocks' => [
                'enabled' => $code['enabled'],
                'disableCodeMirror' => $code['disableCodeMirror'],
                'containers' => $code['containers'],
                'themes' => ['light' => $code['themeLight'], 'dark' => $code['themeDark']],
                'wrap' => $code['wrap'],
                'lineNumbers' => $code['lineNumbers'],
                'collapseHeight' => $code['collapseHeight'],
                'lazy' => $code['lazy'],
                'detect' => $code['detect'],
                'detectMinRelevance' => $code['detectMinRelevance'],
                'showDetectedHint' => $code['showDetectedHint'],
                'skipLanguages' => $code['skipLanguages'],
                'fallbackLang' => $code['fallbackLang'],
                'detectSubset' => null,
                'shikiUrl' => $code['shikiUrl'],
                'hljsUrl' => $code['hljsUrl'],
                'debug' => $code['debug'],
            ],
        ];
    }

    /* ------------------------------------------------------------------ rows */

    /** @return array<int,array<string,mixed>> */
    public static function all(?string $username): array
    {
        $rows = $username === null
            ? Db::rows('SELECT * FROM bookstackdev_profiles ORDER BY username COLLATE NOCASE, name COLLATE NOCASE')
            : Db::rows('SELECT * FROM bookstackdev_profiles WHERE username = ? ORDER BY name COLLATE NOCASE', [$username]);
        return array_map([self::class, 'hydrate'], $rows);
    }

    /** @return array<string,mixed>|null */
    public static function find(string $username, int $id): ?array
    {
        $row = Db::row('SELECT * FROM bookstackdev_profiles WHERE username = ? AND id = ?', [$username, $id]);
        return $row === null ? null : self::hydrate($row);
    }

    /** @return array<string,mixed> */
    public static function require(string $username, int $id): array
    {
        return self::find($username, $id) ?? throw HttpException::notFound('BookStackDev profile not found.');
    }

    /** The row a link belongs to, whoever owns it. @return array<string,mixed>|null */
    public static function byKey(string $key): ?array
    {
        if (preg_match('/^[a-f0-9]{24,64}$/', $key) !== 1) {
            return null;
        }
        $row = Db::row('SELECT * FROM bookstackdev_profiles WHERE key = ?', [$key]);
        return $row === null ? null : self::hydrate($row);
    }

    /**
     * @param array<string,mixed> $settings
     * @param string[] $origins
     * @return array<string,mixed>
     */
    public static function create(string $username, string $name, array $settings = [], array $origins = []): array
    {
        $name = self::cleanName($name);
        $now = time();
        Db::run(
            'INSERT INTO bookstackdev_profiles (username, name, key, settings, origins, created_at, updated_at) VALUES (?,?,?,?,?,?,?)',
            [$username, $name, self::newKey(), self::encode(self::normalise($settings)), self::encode(self::cleanOrigins($origins)), $now, $now]
        );
        return self::require($username, Db::lastId());
    }

    /**
     * Changes what the caller names and nothing else.
     *
     * @param array{name?:string,settings?:array<string,mixed>,origins?:array<int,string>} $fields
     * @return array<string,mixed>
     */
    public static function update(string $username, int $id, array $fields): array
    {
        $row = self::require($username, $id);
        $name = array_key_exists('name', $fields) ? self::cleanName((string)$fields['name']) : (string)$row['name'];
        $settings = array_key_exists('settings', $fields)
            ? self::normalise(self::merge($row['settings'], (array)$fields['settings']))
            : $row['settings'];
        $origins = array_key_exists('origins', $fields) ? self::cleanOrigins((array)$fields['origins']) : $row['origins'];

        Db::run(
            'UPDATE bookstackdev_profiles SET name = ?, settings = ?, origins = ?, updated_at = ? WHERE username = ? AND id = ?',
            [$name, self::encode($settings), self::encode($origins), time(), $username, $id]
        );
        return self::require($username, $id);
    }

    /**
     * A partial change laid over the stored configuration, one group deep, so
     * a call that sets one field leaves the other forty as they were.
     *
     * @param array<string,array<string,mixed>> $stored
     * @param array<string,mixed> $patch
     * @return array<string,array<string,mixed>>
     */
    private static function merge(array $stored, array $patch): array
    {
        foreach ($patch as $group => $fields) {
            if ($fields instanceof \stdClass) {
                $fields = (array)$fields;
            }
            if (!is_array($fields)) {
                continue;
            }
            foreach ($fields as $key => $value) {
                $stored[(string)$group][(string)$key] = $value;
            }
        }
        return $stored;
    }

    public static function delete(string $username, int $id): void
    {
        self::require($username, $id);
        // The instances pointing at this look go back to plain BookStack rather
        // than at a row that no longer exists.
        self::assignInstances($username, $id, []);
        Db::run('DELETE FROM bookstackdev_profiles WHERE username = ? AND id = ?', [$username, $id]);
    }

    /**
     * A new key, which is a new link. The old one stops answering at once,
     * which is the point: it is how a link that was copied somewhere it should
     * not have been is taken back.
     *
     * @return array<string,mixed>
     */
    public static function rotateKey(string $username, int $id): array
    {
        self::require($username, $id);
        Db::run(
            'UPDATE bookstackdev_profiles SET key = ?, updated_at = ? WHERE username = ? AND id = ?',
            [self::newKey(), time(), $username, $id]
        );
        return self::require($username, $id);
    }

    /* ------------------------------------------------------------ instances */

    /**
     * The BookStack instances, across the owner's profiles, that wear this look.
     *
     * @return array<int,array{profile_id:int,profile_name:string,instance_id:string,instance_name:string,base_url:string,origin:string}>
     */
    public static function instancesUsing(string $username, int $id): array
    {
        $out = [];
        foreach (Profiles::all($username) as $profile) {
            foreach ((array)($profile['data']['bookstack'] ?? []) as $instance) {
                if ((int)($instance['bookstackdev_id'] ?? 0) !== $id) {
                    continue;
                }
                $out[] = [
                    'profile_id' => (int)$profile['id'],
                    'profile_name' => (string)$profile['name'],
                    'instance_id' => (string)($instance['id'] ?? ''),
                    'instance_name' => (string)($instance['name'] ?? 'BookStack'),
                    'base_url' => (string)($instance['base_url'] ?? ''),
                    'origin' => self::originOf((string)($instance['base_url'] ?? '')),
                ];
            }
        }
        return $out;
    }

    /**
     * Every BookStack instance the owner has, with the look each one wears.
     *
     * @return array<int,array{profile_id:int,profile_name:string,instance_id:string,instance_name:string,base_url:string,origin:string,bookstackdev_id:int|null}>
     */
    public static function instancesOf(string $username): array
    {
        $out = [];
        foreach (Profiles::all($username) as $profile) {
            foreach ((array)($profile['data']['bookstack'] ?? []) as $instance) {
                $look = (int)($instance['bookstackdev_id'] ?? 0);
                $out[] = [
                    'profile_id' => (int)$profile['id'],
                    'profile_name' => (string)$profile['name'],
                    'instance_id' => (string)($instance['id'] ?? ''),
                    'instance_name' => (string)($instance['name'] ?? 'BookStack'),
                    'base_url' => (string)($instance['base_url'] ?? ''),
                    'origin' => self::originOf((string)($instance['base_url'] ?? '')),
                    'bookstackdev_id' => $look > 0 ? $look : null,
                ];
            }
        }
        return $out;
    }

    /**
     * Points exactly the named instances at this look, and nothing else at it.
     *
     * Written through Profiles::update so the credentials on those rows survive
     * the round trip the way they survive every other profile edit.
     *
     * @param string[] $instanceIds
     */
    public static function assignInstances(string $username, int $id, array $instanceIds): void
    {
        $wanted = array_values(array_unique(array_map('strval', $instanceIds)));
        foreach (Profiles::all($username) as $summary) {
            $profile = Profiles::find($username, (int)$summary['id']);
            if ($profile === null) {
                continue;
            }
            $data = $profile['data'];
            $changed = false;
            foreach ((array)($data['bookstack'] ?? []) as $i => $instance) {
                $current = (int)($instance['bookstackdev_id'] ?? 0);
                $shouldWear = in_array((string)($instance['id'] ?? ''), $wanted, true);
                if ($shouldWear && $current !== $id) {
                    $data['bookstack'][$i]['bookstackdev_id'] = $id;
                    $changed = true;
                } elseif (!$shouldWear && $current === $id) {
                    $data['bookstack'][$i]['bookstackdev_id'] = null;
                    $changed = true;
                }
            }
            if ($changed) {
                Profiles::update($username, (int)$profile['id'], (string)$profile['name'], $data);
            }
        }
    }

    /* -------------------------------------------------------------- origins */

    /**
     * The origin of an address: scheme, host and a port that is not the default.
     * '' for anything that is not a web address at all.
     */
    public static function originOf(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) !== 1) {
            $url = 'https://' . $url;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || trim((string)($parts['host'] ?? '')) === '') {
            return '';
        }
        $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return '';
        }
        $host = strtolower((string)$parts['host']);
        if (preg_match('/^[a-z0-9.\-\[\]:]+$/', $host) !== 1) {
            return '';
        }
        $port = (int)($parts['port'] ?? 0);
        $default = $scheme === 'https' ? 443 : 80;
        return $scheme . '://' . $host . ($port > 0 && $port !== $default ? ':' . $port : '');
    }

    /**
     * Every origin the link answers for: the instances wearing the look, plus
     * the addresses typed in by hand.
     *
     * @param array<string,mixed> $row
     * @return string[]
     */
    public static function allowedOrigins(array $row): array
    {
        $origins = [];
        foreach (self::instancesUsing((string)$row['username'], (int)$row['id']) as $instance) {
            if ($instance['origin'] !== '') {
                $origins[] = $instance['origin'];
            }
        }
        foreach ((array)$row['origins'] as $origin) {
            $origins[] = (string)$origin;
        }
        return array_values(array_unique($origins));
    }

    /** @param array<string,mixed> $row */
    public static function allows(array $row, string $origin): bool
    {
        $origin = self::originOf($origin);
        return $origin !== '' && in_array($origin, self::allowedOrigins($row), true);
    }

    /**
     * The embed line, and the address in it.
     *
     * `crossorigin="anonymous"` is not decoration: it is what makes the browser
     * send an Origin header with the request for the script, which is what the
     * endpoint checks the link against. Without it the endpoint has only the
     * Referer to go on, which most browsers still send for a cross-site script
     * and which a strict referrer policy on the wiki would take away.
     *
     * @param array<string,mixed> $row
     * @return array{url:string,snippet:string}
     */
    public static function embed(array $row): array
    {
        $url = PublicUrl::file('bs.php') . '?k=' . rawurlencode((string)$row['key']);
        return [
            'url' => $url,
            'snippet' => '<script src="' . htmlspecialchars($url, ENT_QUOTES) . '" crossorigin="anonymous"></script>',
        ];
    }

    /* -------------------------------------------------------------- serving */

    /**
     * What bs.php answers, as a value: a status, the headers and the body.
     *
     * Kept out of bs.php so it can be tested without a web server. Two kinds of
     * request: `k` alone is the loader with the profile's configuration written
     * in front of it, and `k` with `f` is one of the modules the loader asks
     * for. Both are answered only for a browser whose page is on an allowed
     * origin - read off the Origin header, or the Referer when there is none -
     * and the refusal says why, in a comment a person reading the network tab
     * can act on.
     *
     * @param array<string,mixed> $query   $_GET
     * @param array<string,mixed> $server  $_SERVER
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    public static function respond(array $query, array $server): array
    {
        $js = static fn(int $status, string $body, array $extra = []): array => [
            'status' => $status,
            'headers' => $extra + [
                'Content-Type' => 'text/javascript; charset=utf-8',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store',
                'Vary' => 'Origin',
            ],
            'body' => $body,
        ];

        $method = strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'OPTIONS') {
            return [
                'status' => 204,
                'headers' => [
                    'Access-Control-Allow-Origin' => self::requestOrigin($server) ?: '*',
                    'Access-Control-Allow-Methods' => 'GET, HEAD, OPTIONS',
                    'Access-Control-Max-Age' => '86400',
                    'Vary' => 'Origin',
                ],
                'body' => '',
            ];
        }
        if ($method !== 'GET' && $method !== 'HEAD') {
            return $js(405, '/* CourseForge BookStackDev: only GET is served here. */', ['Allow' => 'GET, HEAD, OPTIONS']);
        }

        $key = is_string($query['k'] ?? null) ? trim($query['k']) : '';
        $row = $key === '' ? null : self::byKey($key);
        if ($row === null) {
            return $js(404, '/* CourseForge BookStackDev: no look answers to this link. It may have been regenerated - copy the current one from CourseForge. */');
        }

        $origin = self::requestOrigin($server);
        if ($origin === '') {
            return $js(403, '/* CourseForge BookStackDev: the browser did not say which site is loading this script, so it '
                . 'cannot be matched against the sites this look is allowed on. Paste the line exactly as CourseForge '
                . 'shows it, with crossorigin="anonymous". */');
        }
        if (!self::allows($row, $origin)) {
            return $js(403, '/* CourseForge BookStackDev: the look "' . self::comment((string)$row['name']) . '" is not allowed on '
                . self::comment($origin) . '. Add that address under BookStackDev in CourseForge, or use a link '
                . 'generated for this wiki. */');
        }

        $cors = [
            'Access-Control-Allow-Origin' => $origin,
            'Cross-Origin-Resource-Policy' => 'cross-origin',
            'Timing-Allow-Origin' => $origin,
        ];

        $file = is_string($query['f'] ?? null) ? trim($query['f']) : '';
        if ($file === '') {
            $body = self::loader($row);
            return self::cached($js(200, $body, $cors + ['Cache-Control' => 'public, max-age=300']), $body, $server);
        }

        $path = self::assetFile($file);
        if ($path === null) {
            return $js(404, '/* CourseForge BookStackDev: there is no such file in this release. */', $cors);
        }
        $body = (string)file_get_contents($path);
        if (str_ends_with($file, '.js')) {
            $body = self::rewriteImports($body, $file, (string)$row['key']);
        }
        $stamped = (string)($query['v'] ?? '') === CF_VERSION;
        $type = str_ends_with($file, '.css') ? 'text/css; charset=utf-8' : 'text/javascript; charset=utf-8';
        return self::cached($js(200, $body, $cors + [
            'Content-Type' => $type,
            'Cache-Control' => $stamped ? 'public, max-age=31536000, immutable' : 'public, max-age=3600',
        ]), $body, $server);
    }

    /**
     * The origin the page making this request lives on, or ''.
     *
     * The Origin header when the browser sent one - it always does for a
     * request marked crossorigin, and for every module script. The Referer's
     * origin otherwise, which a script tag pasted without the attribute still
     * carries under the default referrer policy. A literal "null" origin is a
     * sandboxed document and is not one this look can be allowed on.
     */
    private static function requestOrigin(array $server): string
    {
        $origin = trim((string)($server['HTTP_ORIGIN'] ?? ''));
        if ($origin !== '' && strtolower($origin) !== 'null') {
            return self::originOf($origin);
        }
        $referer = trim((string)($server['HTTP_REFERER'] ?? ''));
        return $referer === '' ? '' : self::originOf($referer);
    }

    /**
     * An ETag on the body, and a 304 for a browser that already holds it.
     *
     * @param array{status:int,headers:array<string,string>,body:string} $response
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    private static function cached(array $response, string $body, array $server): array
    {
        $etag = '"' . md5($body) . '"';
        $response['headers']['ETag'] = $etag;
        $held = trim((string)($server['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($held !== '' && in_array($etag, array_map('trim', explode(',', $held)), true)) {
            $response['status'] = 304;
            $response['body'] = '';
        }
        return $response;
    }

    /** The loader with this profile's configuration written in front of it. */
    public static function loader(array $row): string
    {
        $boot = [
            'base' => PublicUrl::file('bs.php'),
            'key' => (string)$row['key'],
            'version' => CF_VERSION,
            'config' => self::clientConfig($row['settings']),
        ];
        $file = CF_ROOT . '/' . self::ASSET_DIR . '/' . self::LOADER;
        return '/* CourseForge BookStackDev - the look "' . self::comment((string)$row['name']) . '" */' . "\n"
            . 'window.__cfBookStackDev = ' . json_encode($boot, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ";\n"
            . (string)file_get_contents($file);
    }

    /**
     * The file behind an asset name, or null for anything that is not one.
     *
     * Only the folder's own .js and .css files, named with a relative path that
     * cannot leave it - checked by shape and by the resolved location both.
     */
    public static function assetFile(string $relative): ?string
    {
        if (preg_match('#^(?:css|js)/[a-z0-9_.\-]+(?:/[a-z0-9_.\-]+)*\.(?:js|css)$#', $relative) !== 1
            || str_contains($relative, '..')) {
            return null;
        }
        $dir = realpath(CF_ROOT . '/' . self::ASSET_DIR);
        $file = realpath(CF_ROOT . '/' . self::ASSET_DIR . '/' . $relative);
        if ($dir === false || $file === false || !str_starts_with($file, $dir . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return is_file($file) ? $file : null;
    }

    /**
     * Sibling imports, pointed back through the endpoint.
     *
     * The highlighter is six modules that import each other as `./config.js`,
     * and a relative import resolves against the URL the module was fetched
     * from - which is `bs.php`, a file, not a folder. So every `./x.js` is
     * rewritten to the endpoint address that serves it, key and all. Nothing
     * else in the module text is touched.
     */
    public static function rewriteImports(string $js, string $file, string $key): string
    {
        $dir = str_contains($file, '/') ? substr($file, 0, strrpos($file, '/') + 1) : '';
        $base = PublicUrl::file('bs.php');
        return (string)preg_replace_callback(
            '#(\bfrom\s+|\bimport\s+)([\'"])\./([a-z0-9_.\-]+\.js)\2#',
            static fn(array $m): string => $m[1] . $m[2] . $base . '?k=' . rawurlencode($key)
                . '&f=' . rawurlencode($dir . $m[3]) . '&v=' . rawurlencode(CF_VERSION) . $m[2],
            $js
        );
    }

    /* ---------------------------------------------------------- conventions */

    /**
     * Where this look and the prompts that write for it disagree.
     *
     * Checked per CourseForge profile that publishes into an instance wearing
     * the look, because that is where the prompts live: a profile's own
     * override where it has one, the installation's text otherwise. Two kinds
     * of finding. A feature the pages are told to use and this look does not
     * render - formulas or diagrams switched off - which nobody can fix with
     * wording. And formula delimiters the look renders that the prompt does
     * not ask for, which comes with the wording that would.
     *
     * @param array<string,mixed> $row
     * @return array{ok:bool,checked:int,issues:array<int,array<string,mixed>>}
     */
    public static function audit(array $row): array
    {
        $settings = $row['settings'];
        $issues = [];
        $seen = [];

        foreach (self::instancesUsing((string)$row['username'], (int)$row['id']) as $instance) {
            $profileId = $instance['profile_id'];
            if (isset($seen[$profileId])) {
                continue;
            }
            $seen[$profileId] = true;

            $profile = Profiles::find((string)$row['username'], $profileId);
            if ($profile === null) {
                continue;
            }
            $data = (array)$profile['data'];
            $name = (string)$profile['name'];
            $features = self::profileFeatures($data);
            $library = Prompt::library($data);
            $overrides = (array)($data['prompts'] ?? []);
            $overridesMath = isset($overrides[self::MATH_SLOT]) && is_string($overrides[self::MATH_SLOT]);

            if (!$settings['math']['enabled'] && ($features['mathjax'] ?? false)) {
                $issues[] = self::issue('warning', 'math_off', $profileId, $name, null,
                    'Formulas are switched off in this look, but courses on the profile "' . $name . '" are written '
                    . 'with MathJax formulas on - their formulas will show as raw LaTeX. Switch formulas on here, or '
                    . 'switch the "MathJax formulas" element off in that profile\'s content defaults.');
            }
            if (!$settings['mermaid']['enabled'] && ($features['mermaid'] ?? false)) {
                $issues[] = self::issue('warning', 'mermaid_off', $profileId, $name, null,
                    'Diagrams are switched off in this look, but courses on the profile "' . $name . '" are written '
                    . 'with Mermaid diagrams on - their diagrams will show as source text. Switch diagrams on here, '
                    . 'or switch the "Mermaid diagrams" element off in that profile\'s content defaults.');
            }

            if ($settings['math']['enabled'] && ($features['mathjax'] ?? false)) {
                $recommended = self::mathPrompt($settings);
                $current = (string)($library[self::MATH_SLOT] ?? '');
                $shipped = (string)Config::shipped('prompts.' . self::MATH_SLOT . '.value', '');
                $conventional = self::mathIsConventional($settings);

                if (!$settings['math']['inlineParens'] && !$settings['math']['inlineDollar']
                    && !$settings['math']['displayDollars'] && !$settings['math']['displayBrackets']) {
                    $issues[] = self::issue('warning', 'math_no_delimiters', $profileId, $name, null,
                        'Formulas are on in this look, but no delimiter is: nothing on a page can open a formula. '
                        . 'Switch at least one inline or display delimiter on.');
                } elseif (trim($current) === '') {
                    // An emptied slot sends nothing, which is a decision; only worth a word.
                    $issues[] = self::issue('info', 'math_prompt_empty', $profileId, $name, self::MATH_SLOT,
                        'The profile "' . $name . '" sends no MathJax instructions at all, so the model writes '
                        . 'formulas however it likes. The recommended wording tells it the delimiters this look renders.',
                        $recommended, $overridesMath ? 'profile' : 'installation');
                } elseif (!$conventional && $current === $shipped) {
                    $issues[] = self::issue('warning', 'math_prompt_mismatch', $profileId, $name, self::MATH_SLOT,
                        'This look renders formulas with ' . self::delimiterWords($settings) . ', but the profile "'
                        . $name . '" still sends the shipped MathJax prompt, which asks for \\( ... \\) inline and '
                        . '$$ ... $$ display only. Pages written with it will carry formulas this look does not render.',
                        $recommended, $overridesMath ? 'profile' : 'installation');
                } elseif (!$conventional && !self::promptMatches($current, $settings)) {
                    $issues[] = self::issue('info', 'math_prompt_custom', $profileId, $name, self::MATH_SLOT,
                        'The profile "' . $name . '" sends a MathJax prompt of its own. Make sure it asks for '
                        . self::delimiterWords($settings) . ', which is what this look renders; the recommended '
                        . 'wording does.',
                        $recommended, $overridesMath ? 'profile' : 'installation');
                }
            }
        }

        return ['ok' => $issues === [], 'checked' => count($seen), 'issues' => $issues];
    }

    /**
     * @param array<string,mixed> $data a profile's data blob
     * @return array<string,bool> feature key => on, as every course on the profile starts
     */
    private static function profileFeatures(array $data): array
    {
        $features = Details::baseline()['features'];
        foreach (Profiles::detailsOf($data)['features'] as $key => $state) {
            $features[$key] = (int)$state > 0;
        }
        return $features;
    }

    /** @return array<string,mixed> */
    private static function issue(string $level, string $code, int $profileId, string $profileName, ?string $slot,
                                  string $message, ?string $recommended = null, ?string $layer = null): array
    {
        $issue = [
            'level' => $level,
            'code' => $code,
            'profile_id' => $profileId,
            'profile_name' => $profileName,
            'message' => $message,
        ];
        if ($slot !== null) {
            $issue['slot'] = $slot;
            $issue['layer'] = $layer ?? 'installation';
            $issue['recommended'] = (string)$recommended;
        }
        return $issue;
    }

    /** Whether the delimiters are the ones every shipped prompt already assumes. */
    public static function mathIsConventional(array $settings): bool
    {
        $m = $settings['math'];
        return $m['inlineParens'] && !$m['inlineDollar'] && $m['displayDollars'] && !$m['displayBrackets'];
    }

    /** The delimiters in words: "\( ... \) or $ ... $ inline and $$ ... $$ display". */
    public static function delimiterWords(array $settings): string
    {
        $m = $settings['math'];
        $inline = [];
        if ($m['inlineParens']) {
            $inline[] = '\\( ... \\)';
        }
        if ($m['inlineDollar']) {
            $inline[] = '$ ... $';
        }
        $display = [];
        if ($m['displayDollars']) {
            $display[] = '$$ ... $$';
        }
        if ($m['displayBrackets']) {
            $display[] = '\\[ ... \\]';
        }
        $parts = [];
        if ($inline !== []) {
            $parts[] = implode(' or ', $inline) . ' inline';
        }
        if ($display !== []) {
            $parts[] = implode(' or ', $display) . ' display';
        }
        return $parts === [] ? 'no delimiters at all' : implode(' and ', $parts);
    }

    /**
     * A rough reading of whether a hand-written prompt asks for what the look
     * renders: every enabled delimiter is mentioned, and no disabled one is
     * mentioned as the thing to use. Only ever used to decide whether to say
     * something, never to refuse anything.
     */
    private static function promptMatches(string $prompt, array $settings): bool
    {
        $m = $settings['math'];
        $has = static fn(string $needle): bool => str_contains($prompt, $needle);
        if ($m['inlineParens'] && !$has('\\(')) {
            return false;
        }
        if ($m['inlineDollar'] && !preg_match('/(?<!\$)\$(?!\$)/', $prompt)) {
            return false;
        }
        if ($m['displayDollars'] && !$has('$$')) {
            return false;
        }
        if ($m['displayBrackets'] && !$has('\\[')) {
            return false;
        }
        return true;
    }

    /**
     * The MathJax prompt this look wants: the shipped wording with the delimiter
     * sentences rewritten for whichever delimiters are switched on.
     *
     * @param array<string,array<string,mixed>> $settings normalised
     */
    public static function mathPrompt(array $settings): string
    {
        $m = $settings['math'];
        $parens = (bool)$m['inlineParens'];
        $dollar = (bool)$m['inlineDollar'];
        $dollars = (bool)$m['displayDollars'];
        $brackets = (bool)$m['displayBrackets'];

        $inlineOpen = $parens ? '\\(' : '$';
        $inlineClose = $parens ? '\\)' : '$';
        $example = 'for example: The value of ' . $inlineOpen . 'x' . $inlineClose . ' is 5, and '
            . $inlineOpen . 'a + b' . $inlineClose . ' grows linearly.';

        if ($parens && $dollar) {
            $inline = 'Inline math uses \\( ... \\), ' . $example . ' Single dollar signs $ ... $ render as well, but prefer \\( ... \\).';
        } elseif ($parens) {
            $inline = 'Inline math uses \\( ... \\), ' . $example . ' Never use single dollar signs for inline math.';
        } elseif ($dollar) {
            $inline = 'Inline math uses single dollar signs $ ... $, ' . $example . ' Never use \\( ... \\) for inline math.';
        } else {
            $inline = 'Do not write inline formulas: every formula is a display formula on its own lines.';
        }

        if ($dollars && $brackets) {
            $display = 'Display or block math uses $$ ... $$ on its own lines; \\[ ... \\] renders as well, but prefer $$ ... $$.';
        } elseif ($dollars) {
            $display = 'Display or block math uses $$ ... $$ on its own lines. Never use \\[ ... \\].';
        } elseif ($brackets) {
            $display = 'Display or block math uses \\[ ... \\] on its own lines. Never use $$ ... $$.';
        } else {
            $display = 'Do not write display formulas: keep every formula inline.';
        }

        $price = $dollar
            ? 'A single dollar sign opens a formula here, so a literal price is escaped as \\$: "The price is \\$50, and the tax rate is ' . $inlineOpen . 't = 0.08' . $inlineClose . '."'
            : 'A single dollar sign has no special meaning here, so prices are written literally and are never escaped: "The price is $50, and the tax rate is ' . ($parens ? '\\(t = 0.08\\)' : 't = 0.08') . '."';

        $verifyInline = $parens && $dollar ? '\\( ... \\) or $ ... $' : ($parens ? '\\( ... \\)' : ($dollar ? '$ ... $' : 'no inline delimiters'));
        $verifyDisplay = $dollars && $brackets ? '$$ ... $$ or \\[ ... \\]' : ($dollars ? '$$ ... $$' : ($brackets ? '\\[ ... \\]' : 'no display delimiters'));

        return "Formulas (MathJax):\n"
            . "- Use formulas only when they genuinely help. Never dress up plain prose or simple arithmetic as LaTeX.\n"
            . '- ' . $inline . "\n"
            . '- ' . $display . "\n"
            . '- ' . $price . "\n"
            . "- Inside the delimiters use standard LaTeX: \\frac{a}{b}, x^2, x^{10}, x_i, x_{i,j}, \\sqrt{x}, \\sqrt[n]{x}, \\alpha, \\beta, \\pi, \\theta, \\sum_{i=1}^{n}, \\int_{a}^{b}, \\begin{matrix} a & b \\\\ c & d \\end{matrix}.\n"
            . "- Explain every non-trivial formula in one or two sentences and name its symbols.\n"
            . '- Before finishing, verify that every inline formula uses ' . $verifyInline . ' and every block formula uses ' . $verifyDisplay . '.';
    }

    /* ------------------------------------------------------------- describe */

    /**
     * One profile, as the API and the MCP tools hand it out.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function describe(array $row, bool $withAudit = true): array
    {
        $instances = self::instancesUsing((string)$row['username'], (int)$row['id']);
        $out = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'owner' => (string)$row['username'],
            'key' => (string)$row['key'],
            'settings' => $row['settings'],
            'origins' => $row['origins'],
            'instances' => $instances,
            'allowed_origins' => self::allowedOrigins($row),
            'embed' => self::embed($row),
            'created_at' => (int)$row['created_at'],
            'updated_at' => (int)$row['updated_at'],
        ];
        if ($withAudit) {
            $out['audit'] = self::audit($row);
        }
        return $out;
    }

    /* -------------------------------------------------------------- helpers */

    private static function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        return $name === '' ? 'New look' : mb_substr($name, 0, 120);
    }

    /** @param array<int,mixed> $origins @return string[] */
    public static function cleanOrigins(array $origins): array
    {
        $out = [];
        foreach ($origins as $origin) {
            $clean = is_scalar($origin) ? self::originOf((string)$origin) : '';
            if ($clean !== '' && !in_array($clean, $out, true)) {
                $out[] = $clean;
            }
        }
        return $out;
    }

    private static function newKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** Text that may sit inside a JavaScript block comment. */
    private static function comment(string $text): string
    {
        return str_replace(['*/', "\n", "\r"], ['* /', ' ', ' '], $text);
    }

    /** @param array<string,mixed> $value */
    private static function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function hydrate(array $row): array
    {
        $settings = json_decode((string)$row['settings'], true);
        $origins = json_decode((string)$row['origins'], true);
        return [
            'id' => (int)$row['id'],
            'username' => (string)$row['username'],
            'name' => (string)$row['name'],
            'key' => (string)$row['key'],
            'settings' => self::normalise(is_array($settings) ? $settings : []),
            'origins' => self::cleanOrigins(is_array($origins) ? $origins : []),
            'created_at' => (int)$row['created_at'],
            'updated_at' => (int)$row['updated_at'],
        ];
    }
}
