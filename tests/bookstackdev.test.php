<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * BookStackDev profiles: the look a BookStack instance wears, and the link that
 * puts it on.
 *
 * Four things are worth proving here, and they are the four things that would
 * fail quietly. That a configuration survives nonsense and arrives at the
 * loader in the shape the shipped modules read. That the link answers only the
 * origins the profile allows, reading the origin the way a browser sends it,
 * and refuses everything else by name. That a module is served with its
 * sibling imports pointed back through the endpoint, since a relative import
 * against `bs.php` resolves to nothing. And that the conventions check notices
 * when the prompts write formulas the look will never render.
 */

use CourseForge\Domain\BookStackDev;
use CourseForge\Domain\Profiles;
use CourseForge\Mcp\Tools;
use CourseForge\Security\Actor;
use CourseForge\Support\Config;

function lookActor(string $name = 'looks'): Actor
{
    return Actor::make($name, ucfirst($name), Actor::ROLE_USER);
}

/** @return array<string,mixed> */
function lookCall(string $tool, array $args = [], ?Actor $actor = null): array
{
    return (array)(Tools::call($actor ?? lookActor(), $tool, $args)['data'] ?? []);
}

/** A CourseForge profile with two BookStack instances, for the account that owns looks. */
function lookProfile(string $suffix = ''): array
{
    return Profiles::create('looks', 'Wiki profile' . $suffix, ['bookstack' => [
        ['id' => 'alpha' . $suffix, 'name' => 'Alpha', 'base_url' => 'https://Alpha.Example.com/', 'token_id' => 'a', 'token_secret' => 'alpha-secret'],
        ['id' => 'beta' . $suffix, 'name' => 'Beta', 'base_url' => 'https://beta.example.com:8443', 'token_id' => 'b', 'token_secret' => 'beta-secret'],
    ]]);
}

/** @return array{status:int,headers:array<string,string>,body:string} */
function lookRespond(array $query, array $server = []): array
{
    return BookStackDev::respond($query, $server + ['REQUEST_METHOD' => 'GET']);
}

test('a look starts from the shipped configuration and survives nonsense', static function (): void {
    $defaults = BookStackDev::defaults();
    foreach (BookStackDev::catalogue() as $group) {
        ok(isset($defaults[$group['key']]), 'every group has defaults: ' . $group['key']);
        ok(in_array($group['toggle'], array_column($group['fields'], 'key'), true),
            'the toggle of ' . $group['key'] . ' is one of its own fields');
    }

    $settings = BookStackDev::normalise([
        'codeBlocks' => ['collapseHeight' => '99999', 'themeDark' => ' dracula ', 'shikiUrl' => '', 'bogus' => 1],
        'math' => ['inlineDollar' => 'yes', 'inlineParens' => 'nope'],
        'mermaid' => ['themeLight' => 'neon'],
        'audioPlayer' => ['extensions' => 'mp3, ogg wav mp3'],
        'page' => ['zoom' => 9, 'backgroundOpacity' => -1],
        'theme' => ['position' => 'middle', 'size' => 'big'],
        'nothing' => ['here' => true],
    ]);
    same(5000, $settings['codeBlocks']['collapseHeight'], 'a number is clamped to its ceiling');
    same('dracula', $settings['codeBlocks']['themeDark'], 'a theme name is trimmed');
    same('https://esm.sh/shiki@4', $settings['codeBlocks']['shikiUrl'], 'an emptied module URL goes back to the shipped one');
    ok(!isset($settings['codeBlocks']['bogus']) && !isset($settings['nothing']), 'unknown keys are dropped');
    same(true, $settings['math']['inlineDollar'], 'a boolean reads yes');
    same(true, $settings['math']['inlineParens'], 'and an unreadable one keeps its default');
    same('default', $settings['mermaid']['themeLight'], 'a choice outside the options falls back');
    same(['mp3', 'ogg', 'wav'], $settings['audioPlayer']['extensions'], 'a list is split, trimmed and de-duplicated');
    same(2.0, $settings['page']['zoom'], 'a float is clamped');
    same(0.0, $settings['page']['backgroundOpacity'], 'from below as well');
    same('bottom-left', $settings['theme']['position'], 'a corner nobody has falls back');
    same(44, $settings['theme']['size'], 'and a size that is not a number');
    same($defaults['externalLinks'], $settings['externalLinks'], 'a group not mentioned is complete anyway');
});

test('the loader is handed the configuration in the shape the shipped folder uses', static function (): void {
    $config = BookStackDev::clientConfig(BookStackDev::defaults());
    same([['\\(', '\\)']], $config['math']['inlineMath'], 'inline math is the paren pair by default');
    same([['$$', '$$']], $config['math']['displayMath'], 'and display math the double dollar');
    same(['light' => 'one-light', 'dark' => 'one-dark-pro'], $config['codeBlocks']['themes'], 'the two themes travel as one object');
    same(true, $config['theme']['toggleButton'], 'the toggle switch is spelled the way the loader reads it');
    same(null, $config['codeBlocks']['detectSubset'], 'detection covers every language highlight.js has');

    $config = BookStackDev::clientConfig(BookStackDev::normalise([
        'math' => ['inlineParens' => false, 'inlineDollar' => true, 'displayBrackets' => true],
    ]));
    same([['$', '$']], $config['math']['inlineMath'], 'the delimiters follow the switches');
    same([['$$', '$$'], ['\\[', '\\]']], $config['math']['displayMath'], 'in the order the switches are listed');

    $none = BookStackDev::clientConfig(BookStackDev::normalise([
        'math' => ['inlineParens' => false, 'displayDollars' => false],
    ]));
    same(false, $none['math']['enabled'], 'no delimiter at all means MathJax is not even loaded');
});

test('an origin is the scheme, the host and a port that is not the default', static function (): void {
    same('https://wiki.example.com', BookStackDev::originOf('https://Wiki.Example.com/books/one?x=1'), 'path, query and case go');
    same('http://wiki:8080', BookStackDev::originOf('http://wiki:8080/'), 'a port that is not the default stays');
    same('https://wiki.example.com', BookStackDev::originOf('https://wiki.example.com:443'), 'the default port goes');
    same('https://wiki.example.com', BookStackDev::originOf('wiki.example.com'), 'a bare host is read as https');
    same('', BookStackDev::originOf('ftp://wiki.example.com'), 'a scheme a browser page cannot have is nothing');
    same('', BookStackDev::originOf('javascript:alert(1)'), 'and so is a script');
    same('', BookStackDev::originOf('   '), 'and so is nothing');
    same(['https://a.example.com', 'http://b.example.com:81'],
        BookStackDev::cleanOrigins(['a.example.com', 'A.EXAMPLE.COM/', 'http://b.example.com:81', '', 'not a url at all']),
        'a typed list is normalised and de-duplicated');
});

test('a look is made, found by its key, renamed, changed a field at a time, and regenerates its link', static function (): void {
    $look = BookStackDev::create('looks', '  Company   wiki ', ['codeBlocks' => ['themeDark' => 'github-dark']], ['docs.example.com']);
    same('Company wiki', $look['name'], 'the name is tidied');
    ok(preg_match('/^[a-f0-9]{32}$/', $look['key']) === 1, 'the key is 32 hex characters');
    same('github-dark', $look['settings']['codeBlocks']['themeDark'], 'a setting given at creation is stored');
    same('one-light', $look['settings']['codeBlocks']['themeLight'], 'beside the defaults for everything else');
    same(['https://docs.example.com'], $look['origins'], 'and the extra origin is normalised');

    $found = BookStackDev::byKey($look['key']);
    same((int)$look['id'], (int)$found['id'], 'the key finds the row, whoever asks');
    same(null, BookStackDev::byKey('nonsense'), 'a key that is not one finds nothing');

    $renamed = BookStackDev::update('looks', (int)$look['id'], ['name' => 'Docs', 'settings' => ['math' => ['inlineDollar' => true]]]);
    same('Docs', $renamed['name'], 'renamed');
    same(true, $renamed['settings']['math']['inlineDollar'], 'the one field changed');
    same('github-dark', $renamed['settings']['codeBlocks']['themeDark'], 'and the field set earlier kept');
    same(['https://docs.example.com'], $renamed['origins'], 'and the origins untouched by a call that did not name them');

    $rotated = BookStackDev::rotateKey('looks', (int)$look['id']);
    ok($rotated['key'] !== $look['key'], 'a new key');
    same(null, BookStackDev::byKey($look['key']), 'the old one answers to nothing');

    raises(static fn() => BookStackDev::require('somebody', (int)$look['id']), 'another account asking for it');

    BookStackDev::delete('looks', (int)$look['id']);
    same(null, BookStackDev::byKey($rotated['key']), 'and after deletion neither does the new one');
});

test('an instance wears a look, and the link answers for it', static function (): void {
    $profile = lookProfile('-w');
    $look = BookStackDev::create('looks', 'Wearable');
    $id = (int)$look['id'];

    same([], BookStackDev::instancesUsing('looks', $id), 'nothing wears a new look');
    BookStackDev::assignInstances('looks', $id, ['alpha-w']);
    $using = BookStackDev::instancesUsing('looks', $id);
    same(1, count($using), 'one instance wears it');
    same('https://alpha.example.com', $using[0]['origin'], 'and is known by its origin');
    same((int)$profile['id'], $using[0]['profile_id'], 'and by the profile it is on');

    $stored = Profiles::find('looks', (int)$profile['id']);
    same('alpha-secret', $stored['data']['bookstack'][0]['token_secret'], 'the assignment did not cost the instance its token');
    same($id, $stored['data']['bookstack'][0]['bookstackdev_id'], 'and the look is written on the instance');
    same(null, $stored['data']['bookstack'][1]['bookstackdev_id'], 'the other instance is plain');

    $row = BookStackDev::require('looks', $id);
    same(['https://alpha.example.com'], BookStackDev::allowedOrigins($row), 'the link works on the instance');
    $row = BookStackDev::update('looks', $id, ['origins' => ['https://partner.example.org', 'alpha.example.com']]);
    same(['https://alpha.example.com', 'https://partner.example.org'], BookStackDev::allowedOrigins($row),
        'and on a typed address, once each');
    ok(BookStackDev::allows($row, 'https://alpha.example.com'), 'allows() agrees');
    ok(!BookStackDev::allows($row, 'https://beta.example.com:8443'), 'and refuses the instance that does not wear it');

    BookStackDev::assignInstances('looks', $id, ['beta-w']);
    $stored = Profiles::find('looks', (int)$profile['id']);
    same(null, $stored['data']['bookstack'][0]['bookstackdev_id'], 'moving the look off alpha clears it');
    same($id, $stored['data']['bookstack'][1]['bookstackdev_id'], 'and puts it on beta');

    $described = BookStackDev::describe(BookStackDev::require('looks', $id));
    ok(str_contains($described['embed']['snippet'], 'crossorigin="anonymous"'), 'the embed line asks the browser to say where it is');
    ok(str_contains($described['embed']['url'], '/bs.php?k=' . $described['key']), 'and carries the key');

    BookStackDev::delete('looks', $id);
    $stored = Profiles::find('looks', (int)$profile['id']);
    same(null, $stored['data']['bookstack'][1]['bookstackdev_id'], 'a deleted look takes itself off every instance');
});

test('the endpoint serves the loader to an allowed origin and refuses everybody else', static function (): void {
    $profile = lookProfile('-e');
    $look = BookStackDev::create('looks', 'Served', [], ['https://partner.example.org']);
    BookStackDev::assignInstances('looks', (int)$look['id'], ['alpha-e']);
    $key = (string)$look['key'];

    same(404, lookRespond([])['status'], 'no key, no look');
    same(404, lookRespond(['k' => str_repeat('0', 32)])['status'], 'an unknown key, no look');

    $ok = lookRespond(['k' => $key], ['HTTP_ORIGIN' => 'https://alpha.example.com']);
    same(200, $ok['status'], 'the instance wearing the look is served');
    same('https://alpha.example.com', $ok['headers']['Access-Control-Allow-Origin'], 'and told so, for the browser');
    same('Origin', $ok['headers']['Vary'], 'and caches keep origins apart');
    ok(str_starts_with($ok['headers']['Content-Type'], 'text/javascript'), 'as JavaScript');
    ok(str_contains($ok['body'], 'window.__cfBookStackDev = {'), 'with the boot object in front');
    ok(str_contains($ok['body'], '"key":"' . $key . '"'), 'carrying the key for the modules');
    ok(str_contains($ok['body'], '"inlineMath":[["\\\\(","\\\\)"]]'), 'and the configuration in the loader\'s shape');
    ok(str_contains($ok['body'], 'window.__mileloLoaded'), 'followed by the loader itself');
    ok(str_contains($ok['headers']['Cache-Control'], 'max-age=300'), 'held for a few minutes, since it carries the settings');

    $partner = lookRespond(['k' => $key], ['HTTP_ORIGIN' => 'https://partner.example.org']);
    same(200, $partner['status'], 'a typed origin is served too');

    $referer = lookRespond(['k' => $key], ['HTTP_REFERER' => 'https://alpha.example.com/books/vue/page/setup']);
    same(200, $referer['status'], 'a tag pasted without crossorigin still identifies its page by the Referer');

    $wrong = lookRespond(['k' => $key], ['HTTP_ORIGIN' => 'https://beta.example.com:8443']);
    same(403, $wrong['status'], 'an instance that does not wear the look is refused');
    ok(str_contains($wrong['body'], 'https://beta.example.com:8443'), 'by name');
    ok(str_contains($wrong['body'], 'Served'), 'and the look is named too');
    ok(!isset($wrong['headers']['Access-Control-Allow-Origin']), 'without a CORS grant');

    same(403, lookRespond(['k' => $key])['status'], 'a request that says nothing about its page is refused');
    same(403, lookRespond(['k' => $key], ['HTTP_ORIGIN' => 'null'])['status'], 'and so is a sandboxed one');
    same(204, lookRespond(['k' => $key], ['REQUEST_METHOD' => 'OPTIONS'])['status'], 'a preflight is answered');
    same(405, lookRespond(['k' => $key], ['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'https://alpha.example.com'])['status'],
        'and nothing but GET is served');

    BookStackDev::delete('looks', (int)$look['id']);
    Profiles::delete('looks', (int)$profile['id']);
});

test('a module is served with its sibling imports pointed back through the endpoint', static function (): void {
    $profile = lookProfile('-m');
    $look = BookStackDev::create('looks', 'Modules');
    BookStackDev::assignInstances('looks', (int)$look['id'], ['alpha-m']);
    $key = (string)$look['key'];
    $origin = ['HTTP_ORIGIN' => 'https://alpha.example.com'];

    $module = lookRespond(['k' => $key, 'f' => 'js/shiki/index.js'], $origin);
    same(200, $module['status'], 'a module of the highlighter is served');
    ok(!str_contains($module['body'], "'./config.js'"), 'with no relative import left');
    ok(str_contains($module['body'], 'bs.php?k=' . $key . '&f=js%2Fshiki%2Fconfig.js&v=' . rawurlencode(CF_VERSION)),
        'each one pointed at the endpoint, with the key and the version');
    ok(str_contains($module['headers']['Cache-Control'], 'max-age=3600'), 'unstamped, it is held for an hour');

    $stamped = lookRespond(['k' => $key, 'f' => 'js/shiki/index.js', 'v' => CF_VERSION], $origin);
    ok(str_contains($stamped['headers']['Cache-Control'], 'immutable'), 'stamped with this release, for a year');

    $css = lookRespond(['k' => $key, 'f' => 'css/base.css'], $origin);
    same(200, $css['status'], 'a stylesheet is served');
    ok(str_starts_with($css['headers']['Content-Type'], 'text/css'), 'as CSS');
    ok(str_contains($css['body'], '--milelo-zoom'), 'and it is the one with the configurable zoom');

    $again = lookRespond(['k' => $key, 'f' => 'css/base.css'], $origin + ['HTTP_IF_NONE_MATCH' => $css['headers']['ETag']]);
    same(304, $again['status'], 'a browser that holds it is told so');
    same('', $again['body'], 'without the body');

    foreach (['../src/bootstrap.php', 'js/../../bs.php', 'js/nope.js', 'src/bootstrap.php', 'css/base.css/', 'JS/theme-toggle.js'] as $bad) {
        same(404, lookRespond(['k' => $key, 'f' => $bad], $origin)['status'], 'refused: ' . $bad);
    }
    same(403, lookRespond(['k' => $key, 'f' => 'js/theme-toggle.js'], ['HTTP_ORIGIN' => 'https://elsewhere.test'])['status'],
        'a module is as origin-locked as the loader');

    BookStackDev::delete('looks', (int)$look['id']);
    Profiles::delete('looks', (int)$profile['id']);
});

test('the conventions check says where the prompts and the look disagree', static function (): void {
    $profile = lookProfile('-c');
    $profileId = (int)$profile['id'];
    $look = BookStackDev::create('looks', 'Checked');
    $id = (int)$look['id'];

    $audit = BookStackDev::audit(BookStackDev::require('looks', $id));
    same(0, $audit['checked'], 'nothing to compare while nothing wears the look');
    same(true, $audit['ok'], 'which is fine');

    BookStackDev::assignInstances('looks', $id, ['alpha-c']);
    $audit = BookStackDev::audit(BookStackDev::require('looks', $id));
    same(1, $audit['checked'], 'the profile behind the instance is checked');
    same(true, $audit['ok'], 'and the shipped look agrees with the shipped prompts');

    $row = BookStackDev::update('looks', $id, ['settings' => ['math' => ['inlineParens' => false, 'inlineDollar' => true]]]);
    $audit = BookStackDev::audit($row);
    same(false, $audit['ok'], 'formulas written as \\( \\) will not render on a look that typesets $ $');
    same('math_prompt_mismatch', $audit['issues'][0]['code'], 'and the finding says which kind of disagreement');
    same('warning', $audit['issues'][0]['level'], 'loudly, because the shipped prompt is what would be sent');
    same('installation', $audit['issues'][0]['layer'], 'and that the text being sent is the installation\'s');
    same($profileId, $audit['issues'][0]['profile_id'], 'for this profile');
    ok(str_contains($audit['issues'][0]['recommended'], 'single dollar signs $ ... $'), 'with wording that asks for $ ... $');
    ok(str_contains($audit['issues'][0]['recommended'], 'Never use \\( ... \\)'), 'and forbids the delimiter this look does not render');
    ok(str_contains($audit['issues'][0]['recommended'], 'escaped as \\$'), 'and says how a price is written now');

    // Profiles::update() is the low-level door and writes the whole blob, so
    // the stored data travels with the change - the API merges for its callers.
    $data = Profiles::find('looks', $profileId)['data'];
    $data['prompts'] = [BookStackDev::MATH_SLOT => $audit['issues'][0]['recommended']];
    Profiles::update('looks', $profileId, 'Wiki profile-c', $data);
    $audit = BookStackDev::audit($row);
    same(true, $audit['ok'], 'writing the recommended wording into the profile settles it');

    $data['prompts'] = [BookStackDev::MATH_SLOT => 'Write formulas however you like.'];
    Profiles::update('looks', $profileId, 'Wiki profile-c', $data);
    $audit = BookStackDev::audit($row);
    same('math_prompt_custom', $audit['issues'][0]['code'] ?? '', 'a hand-written prompt that never mentions the delimiter is worth a word');
    same('info', $audit['issues'][0]['level'], 'but only a word');
    same('profile', $audit['issues'][0]['layer'], 'and it names the profile as the layer holding the text');

    $row = BookStackDev::update('looks', $id, ['settings' => ['math' => ['enabled' => false]]]);
    $audit = BookStackDev::audit($row);
    same('math_off', $audit['issues'][0]['code'] ?? '', 'formulas off while the profile writes them is a warning');
    same('warning', $audit['issues'][0]['level'], 'a loud one');

    $row = BookStackDev::update('looks', $id, ['settings' => ['math' => ['enabled' => true, 'inlineParens' => false, 'inlineDollar' => false, 'displayDollars' => false, 'displayBrackets' => false], 'mermaid' => ['enabled' => false]]]);
    $codes = array_column(BookStackDev::audit($row)['issues'], 'code');
    ok(in_array('math_no_delimiters', $codes, true), 'formulas on with no delimiter is a warning');
    ok(in_array('mermaid_off', $codes, true), 'and so are diagrams off while the profile writes them');

    $data['details'] = ['features' => ['mathjax' => -1, 'mermaid' => -1]];
    Profiles::update('looks', $profileId, 'Wiki profile-c', $data);
    same(true, BookStackDev::audit($row)['ok'], 'a profile that writes neither has nothing to disagree about');

    ok(str_contains(BookStackDev::mathPrompt(BookStackDev::defaults()), 'Never use single dollar signs'),
        'the conventional wording keeps the shipped rule about dollars');
    ok(str_contains((string)Config::shipped('prompts.' . BookStackDev::MATH_SLOT . '.value'), 'Never use single dollar signs'),
        'which is what the shipped prompt says');

    BookStackDev::delete('looks', $id);
    Profiles::delete('looks', $profileId);
});

test('a connected client can do all of it', static function (): void {
    $profile = lookProfile('-t');
    $profileId = (int)$profile['id'];

    $options = lookCall('list_bookstackdev_options');
    ok(count($options['groups']) >= 9, 'the options are listed by group');
    ok(in_array('one-dark-pro', $options['shiki_themes'], true), 'with the Shiki themes');

    $made = lookCall('create_bookstackdev_profile', [
        'name' => 'Client look',
        'settings' => ['codeBlocks' => ['themeDark' => 'github-dark']],
        'instance_ids' => ['alpha-t'],
        'origins' => ['partner.example.org'],
    ]);
    same(true, $made['created'], 'created');
    $id = (int)$made['bookstackdev_id'];
    ok(str_contains($made['embed']['snippet'], 'bs.php?k='), 'with the embed line');
    same('github-dark', $made['settings']['codeBlocks']['themeDark'], 'and the setting');
    same(1, count($made['instances']), 'and the instance wearing it');
    same(['https://alpha.example.com', 'https://partner.example.org'], $made['allowed_origins'], 'and every address it works on');

    $listed = lookCall('list_bookstackdev_profiles');
    same(1, count(array_filter($listed['looks'], static fn(array $l): bool => (int)$l['bookstackdev_id'] === $id)), 'listed');

    $changed = lookCall('update_bookstackdev_profile', ['bookstackdev_id' => $id, 'settings' => ['math' => ['inlineDollar' => true]]]);
    same(['settings'], $changed['changed'], 'one field changed');
    same('github-dark', $changed['settings']['codeBlocks']['themeDark'], 'and the earlier one kept');
    same(true, $changed['settings']['math']['inlineDollar'], 'as the new one is stored');

    $error = raises(
        static fn() => lookCall('update_bookstackdev_profile', ['bookstackdev_id' => $id, 'settings' => ['codeBlocks' => ['themDark' => 'x']]]),
        'a mistyped field'
    );
    ok(str_contains($error->getMessage(), 'themeDark'), 'is refused with the fields that exist');

    raises(static fn() => lookCall('update_bookstackdev_profile', ['bookstackdev_id' => $id, 'instance_ids' => ['nope']]),
        'an instance nobody has');

    // The other door onto the same field: the profile tool.
    $updated = lookCall('update_profile', ['profile_id' => $profileId, 'bookstack_id' => 'beta-t', 'bookstackdev_id' => $id]);
    ok(in_array('bookstackdev_id', $updated['changed'], true), 'update_profile puts a look on an instance');
    $got = lookCall('get_profile', ['profile_id' => $profileId]);
    same($id, $got['bookstack'][1]['bookstackdev_id'], 'and get_profile reports it');
    same(2, count(lookCall('get_bookstackdev_profile', ['bookstackdev_id' => $id])['instances']), 'so both instances wear it now');

    lookCall('update_profile', ['profile_id' => $profileId, 'bookstack_id' => 'beta-t', 'bookstackdev_id' => 0]);
    same(null, lookCall('get_profile', ['profile_id' => $profileId])['bookstack'][1]['bookstackdev_id'], '0 takes it off again');
    raises(static fn() => lookCall('update_profile', ['profile_id' => $profileId, 'bookstack_id' => 'beta-t', 'bookstackdev_id' => 99999]),
        'a look that does not exist');

    $added = lookCall('add_bookstack_instance', [
        'profile_id' => $profileId, 'url' => 'https://gamma.example.com', 'token_id' => 'g', 'token_secret' => 's', 'bookstackdev_id' => $id,
    ]);
    same($id, $added['instances'][2]['bookstackdev_id'], 'a new instance can be given a look in the same call');

    $check = lookCall('check_bookstackdev_conventions', ['bookstackdev_id' => $id]);
    same(false, $check['ok'], 'the check runs, and the $ $ look disagrees with the shipped prompt');
    ok(str_contains($check['next'], 'set_profile_prompts'), 'and says how to settle it');

    $rotated = lookCall('rotate_bookstackdev_link', ['bookstackdev_id' => $id]);
    ok($rotated['embed']['url'] !== $made['embed']['url'], 'a new link');

    raises(static fn() => lookCall('get_bookstackdev_profile', ['bookstackdev_id' => $id], lookActor('stranger')),
        'another account reading it');

    raises(static fn() => lookCall('delete_bookstackdev_profile', ['bookstackdev_id' => $id, 'confirm_name' => 'Wrong']),
        'deleting with the wrong name');
    $deleted = lookCall('delete_bookstackdev_profile', ['bookstackdev_id' => $id, 'confirm_name' => 'Client look']);
    same(true, $deleted['deleted'], 'deleted with the right one');
    same(null, lookCall('get_profile', ['profile_id' => $profileId])['bookstack'][0]['bookstackdev_id'], 'and taken off the instance');

    Profiles::delete('looks', $profileId);
});
