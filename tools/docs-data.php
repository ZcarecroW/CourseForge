<?php
/**
 * Writes what the documentation site generates its reference pages from.
 *
 * The MCP tools, the settings, the prompt library, the content details and the
 * provider presets are each declared once in the code. The site reads them from
 * here rather than from a copy written out by hand, so a tool added, a setting
 * renamed or a slot reworded reaches the reference pages on the next build.
 *
 *   php tools/docs-data.php [output.json]     default: stdout
 *
 * Nothing is read from or written to data/: the bootstrap is pointed at an
 * empty scratch directory first, so the shipped defaults are what is described.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$scratch = sys_get_temp_dir() . '/courseforge-docs-' . getmypid();
@mkdir($scratch, 0700, true);
putenv('COURSEFORGE_DATA_DIR=' . $scratch);

require dirname(__DIR__) . '/src/bootstrap.php';

use CourseForge\Ai\Provider\Presets;
use CourseForge\Domain\Details;
use CourseForge\Mcp\Prompts;
use CourseForge\Mcp\Scopes;
use CourseForge\Mcp\Tools;
use CourseForge\Support\Settings;

$defaults = json_decode((string)file_get_contents(CF_ROOT . '/config/defaults.json'), true, 512, JSON_THROW_ON_ERROR);

$tools = [];
foreach (Tools::registry() as $tool) {
    $arguments = [];
    foreach ($tool->properties as $name => $schema) {
        $type = $schema['type'] ?? (isset($schema['enum']) ? 'string' : 'any');
        $arguments[] = [
            'name' => $name,
            'type' => is_array($type) ? implode(' | ', $type) : (string)$type,
            'required' => in_array($name, $tool->required, true),
            'description' => (string)($schema['description'] ?? ''),
            'enum' => $schema['enum'] ?? null,
        ];
    }
    $tools[] = [
        'name' => $tool->name,
        'group' => $tool->scope,
        'title' => $tool->title,
        'description' => $tool->description,
        'arguments' => $arguments,
        'read_only' => $tool->readOnly,
        'destructive' => $tool->destructive,
        'admin' => $tool->admin,
        'spends' => $tool->spends,
    ];
}

$slots = [];
foreach ($defaults['prompts'] as $key => $slot) {
    $slots[] = [
        'key' => $key,
        'group' => $slot['group'] ?? '',
        'order' => $slot['order'] ?? 0,
        'label' => $slot['label'] ?? $key,
        'description' => $slot['description'] ?? '',
        'placeholders' => $slot['placeholders'] ?? [],
        'value' => $slot['value'] ?? '',
    ];
}

$presets = [];
foreach (Presets::all() as $key => $row) {
    $presets[] = [
        'key' => $key,
        'label' => $row['label'] ?? $key,
        'base_url' => $row['base_url'] ?? '',
        'batch' => $row['batch'] ?? false,
        'local' => (bool)($row['local'] ?? false),
        'requires_key' => (bool)($row['requires_key'] ?? true),
        'verified' => (bool)($row['verified'] ?? false),
        'hint' => $row['hint'] ?? '',
        'docs' => $row['docs'] ?? '',
    ];
}

$settings = array_map(static function (array $field): array {
    // A secret's default is never interesting and an empty string reads as a
    // value; the content defaults carry their catalogue icon, which the page
    // has no use for.
    unset($field['icon']);
    return $field;
}, Settings::catalogue());

$data = [
    'version' => CF_VERSION,
    'tool_groups' => array_map(static fn(array $g): array => [
        'key' => $g['key'],
        'label' => $g['label'],
        'description' => $g['description'],
        'admin' => $g['admin'] ?? false,
        'spends' => $g['spends'] ?? false,
        'tools' => $g['tools'],
    ], Tools::scopes()),
    'tools' => $tools,
    'mcp_prompts' => Prompts::catalogue(),
    'setting_groups' => Settings::groups(),
    'settings' => $settings,
    'prompt_groups' => $defaults['prompt_groups'],
    'prompt_slots' => $slots,
    'details' => Details::catalogue(),
    'presets' => $presets,
];

$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

@rmdir($scratch);

if (isset($argv[1])) {
    file_put_contents($argv[1], $json);
    fwrite(STDERR, sprintf("%d tools, %d settings, %d prompt slots, %d presets -> %s\n",
        count($tools), count($settings), count($slots), count($presets), $argv[1]));
} else {
    echo $json;
}
