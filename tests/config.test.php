<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * The two configuration layers, and the reduction that keeps them apart.
 *
 * config/defaults.json ships with the release and is never written to;
 * data/config.json holds only what this installation has changed. That split is
 * the whole of what makes an update safe: replacing the release directory
 * brings new defaults and new prompt slots with it, while the administrator's
 * decisions sit in a file the update never touches.
 *
 * The split only holds if the override file really is a set of overrides, so
 * two reductions are tested here. A value written back at its shipped default
 * is removed rather than stored, and its branch is pruned behind it - otherwise
 * "reset to default" would pin the old value for ever and a later release could
 * never change it. And a data/config.json left over from CourseForge 3.x, which
 * was a complete document rather than a set of overrides, is diffed down to one
 * the first time it is read: without that, every 3.x install would freeze the
 * entire prompt library at the version it upgraded from.
 */

use CourseForge\Domain\Details;
use CourseForge\Support\Config;
use CourseForge\Support\Json;
use CourseForge\Support\Settings;

/** The override file, read straight off disk rather than through the cache. */
function storedOverrides(): array
{
    return Json::read(Config::file()) ?? [];
}

test('an installation that has changed nothing overrides nothing', static function (): void {
    @unlink(Config::file());
    Config::flush();

    same([], Config::overrides(), 'a fresh installation');
    same(Config::defaults(), Config::all(), 'the merge of defaults and nothing');
});

test('a changed setting is stored, and only that setting', static function (): void {
    Config::set('app.ai_timeout_seconds', 999);

    same(999, Config::int('app.ai_timeout_seconds', 0), 'the value the application reads');
    ok(Config::isOverridden('app.ai_timeout_seconds'), 'the installation has decided this one');
    same(['app' => ['ai_timeout_seconds' => 999]], storedOverrides(), 'the whole override file');
    same(
        (string)Config::get('app.name'),
        (string)Config::defaults()['app']['name'],
        'everything else still follows the release'
    );
});

test('writing the shipped default back removes the override rather than storing it', static function (): void {
    $shipped = (int)Config::defaults()['app']['ai_timeout_seconds'];
    Config::set('app.ai_timeout_seconds', $shipped);

    ok(!Config::isOverridden('app.ai_timeout_seconds'), 'a value equal to the default is not a decision');
    // Pruned, not left as an empty `app` object: an override file that grows a
    // skeleton of keys holding nothing stops being readable as a list of what
    // this installation actually changed.
    same([], storedOverrides(), 'the branch is pruned behind the removal');
    same($shipped, Config::int('app.ai_timeout_seconds', 0), 'and the shipped value applies again');
});

test('reset drops an override and lets a later release change the value', static function (): void {
    Config::set('app.batch_keep_days', 7);
    same(7, Config::int('app.batch_keep_days', 0), 'the changed value');

    Config::reset('app.batch_keep_days');
    ok(!Config::isOverridden('app.batch_keep_days'), 'nothing is remembered after a reset');
    same(
        (int)Config::defaults()['app']['batch_keep_days'],
        Config::int('app.batch_keep_days', 0),
        'the shipped value again'
    );
});

test('several settings in one write, and the merge is deep', static function (): void {
    Config::setMany(['app.name' => 'Testforge', 'app.default_language' => 'German']);

    same('Testforge', Config::str('app.name', ''), 'the first');
    same('German', Config::str('app.default_language', ''), 'the second');
    same(
        (int)Config::defaults()['app']['default_concurrency'],
        Config::int('app.default_concurrency', 0),
        'a sibling key inside the same branch is not lost to the overlay'
    );

    Config::setMany(['app.name' => null, 'app.default_language' => null]);
    same([], storedOverrides(), 'and null clears them both');
});

test('a 3.x complete document is reduced to overrides the first time it is read', static function (): void {
    // What CourseForge 3.x wrote: the whole merged document, prompt library and
    // all, rather than the handful of things the administrator changed.
    $whole = Config::defaults();
    $whole['app']['name'] = 'Legacy install';
    $whole['_comment'] = 'written by 3.x';
    Json::write(Config::file(), $whole);
    Config::flush();

    same(['app' => ['name' => 'Legacy install']], Config::overrides(), 'only the one real change survives');
    same('Legacy install', Config::str('app.name', ''), 'and the setting itself is kept');
    ok(!isset(storedOverrides()['prompts']), 'the prompt library is no longer frozen in the override file');

    // The reduction is written back, so the next read is already small.
    same(['app' => ['name' => 'Legacy install']], storedOverrides(), 'the reduced file on disk');
});

/* ------------------------------------------- the baseline every course starts from */

/**
 * The bottom of the content-details chain is an ordinary setting.
 *
 * `details.features.<key>.default` has always been what Details::resolve()
 * falls back to; what it never had was a way in from the application, so an
 * installation that wanted every course to start with learning objectives had
 * to edit the shipped file - the one thing the two-layer split exists to make
 * unnecessary. Declaring those paths in the settings catalogue is the whole of
 * the feature, and these tests are about the two ways that could be wrong: the
 * generated entries could disagree with the catalogue they were generated from,
 * and a write could fail to reach the baseline a course actually inherits.
 */
test('every content detail is offered as a setting, and typed as the catalogue types it', static function (): void {
    $content = array_values(array_filter(Settings::catalogue(), static fn(array $f): bool => $f['group'] === 'content'));
    $details = Details::catalogue();

    same(
        count($details['features']) + count($details['params']),
        count($content),
        'one setting per feature and per value, and nothing else in the group'
    );
    ok(
        in_array('content', array_column(Settings::groups(), 'key'), true),
        'and the group is described, so the screen does not have to invent a heading for it'
    );

    foreach ($details['features'] as $feature) {
        $field = Settings::field('details.features.' . $feature['key'] . '.default');
        ok($field !== null, $feature['key'] . ' is reachable by key');
        same('bool', $field['type'], $feature['key'] . ' is a switch, because a feature is one');
        same($feature['label'], $field['label'], $feature['key'] . ' is called what the Details tab calls it');
    }

    foreach ($details['params'] as $param) {
        $field = Settings::field('details.params.' . $param['key'] . '.default');
        ok($field !== null, $param['key'] . ' is reachable by key');
        same($param['type'] === 'text' ? 'string' : 'int', $field['type'], $param['key'] . ' keeps its type');
        if ($param['type'] !== 'text') {
            same((int)$param['min'], (int)$field['min'], $param['key'] . ' keeps its floor');
            same((int)$param['max'], (int)$field['max'], $param['key'] . ' keeps its ceiling');
        }
    }
});

test('changing a course default moves the baseline, and resetting it moves it back', static function (): void {
    @unlink(Config::file());
    Config::flush();

    $shipped = Details::baseline();
    same(false, $shipped['features']['objectives'], 'the shipped baseline is what this release ships');

    Config::setMany([
        'details.features.objectives.default' => Settings::coerce('details.features.objectives.default', true),
        'details.params.min_length.default' => Settings::coerce('details.params.min_length.default', 900),
    ]);

    // Read back through Details rather than through Config: the cache in front
    // of the catalogue is what a write in the same request has to get past, and
    // it is the reason Config carries a revision number at all.
    $now = Details::baseline();
    same(true, $now['features']['objectives'], 'the switch reaches the baseline');
    same(900, $now['params']['min_length'], 'and so does the number');

    same(
        ['details' => ['features' => ['objectives' => ['default' => true]],
                       'params' => ['min_length' => ['default' => 900]]]],
        storedOverrides(),
        'only the two values are stored, each on its own branch'
    );

    Config::reset('details.features.objectives.default');
    Config::reset('details.params.min_length.default');
    same($shipped, Details::baseline(), 'and resetting them puts the release back in charge');
    same([], storedOverrides(), 'with nothing pinned behind them');
});

/**
 * The two paths to the same number do not agree about what "too big" means, and
 * that is deliberate rather than an oversight worth papering over.
 *
 * Details::patch() clamps, because it is the API a course, a chapter and an MCP
 * client write through and a value that is merely too large is not worth
 * failing a whole patch for. Settings::coerce() refuses, because it is a form
 * with one field on screen and a silent clamp there is a number the
 * administrator did not type being shown back as though they had. Both are
 * right where they are; what matters is that neither can store an
 * out-of-catalogue value.
 */
test('a course default is bounded by the catalogue, and says so rather than guessing', static function (): void {
    same(
        422,
        raises(
            static fn(): mixed => Settings::coerce('details.params.min_length.default', 'quite long'),
            'a word where a number belongs'
        )->status(),
        'text is not a length'
    );
    same(
        422,
        raises(
            static fn(): mixed => Settings::coerce('details.params.min_length.default', 999999),
            'a number past the ceiling'
        )->status(),
        'and a length past the catalogue ceiling is refused rather than quietly clamped'
    );
    same(900, Settings::coerce('details.params.min_length.default', '900'), 'a number inside it is taken');
    same(true, Settings::coerce('details.features.objectives.default', 'on'), 'and a switch reads the words a form sends');
});

test('the sandbox is left as the rest of the suite expects it', static function (): void {
    @unlink(Config::file());
    Config::flush();

    same([], Config::overrides(), 'no overrides left behind for the other test files');
});
