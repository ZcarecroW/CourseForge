<?php
/**
 * Two places where leaving something out destroyed it.
 *
 * Both were found by agents driving the running application, and both share a
 * shape worth naming: the code treated an absence as an instruction. An omitted
 * field meant "clear it"; a marker too short to be a title meant "link it to
 * whatever starts with that letter". Neither said so, and neither was
 * recoverable once it had happened.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use CourseForge\Domain\LinkIndex;
use CourseForge\Domain\Profiles;
use CourseForge\Support\Db;
use CourseForge\Support\Json;

/* ------------------------------------------------------- profile credentials */

$freshProfile = static function (): array {
    Db::run('DELETE FROM profiles');
    return Profiles::create('zed', 'Production', [
        'ai' => [['id' => 'a1', 'kind' => 'anthropic', 'api_key' => 'sk-live-KEEPME', 'base_url' => '']],
        'bookstack' => [['id' => 'b1', 'base_url' => 'https://bs', 'token_id' => 't', 'token_secret' => 'bs-KEEPME']],
        'language' => 'Deutsch',
        'concurrency' => 4,
    ]);
};

test('renaming a profile keeps the credentials it holds', function () use ($freshProfile) {
    // The bug: PUT profiles/{id} carrying only a name answered 200 and left the
    // row with ai: [], bookstack: [], language back at its default. A profile is
    // the one row in this application that holds secrets.
    $id = (int)$freshProfile()['id'];
    $stored = Profiles::require('zed', $id);

    // What the controller now does for a body with no `data` key.
    Profiles::update('zed', $id, 'Renamed', Json::merge((array)$stored['data'], []));

    $after = Profiles::require('zed', $id);
    same('Renamed', (string)$after['name'], 'the rename took');
    same('sk-live-KEEPME', (string)$after['data']['ai'][0]['api_key'], 'and the API key survived it');
    same('bs-KEEPME', (string)$after['data']['bookstack'][0]['token_secret'], 'as did the BookStack secret');
    same('Deutsch', (string)$after['data']['language'], 'and the language was not reset to its default');
});

test('a partial data document changes only what it names', function () use ($freshProfile) {
    $id = (int)$freshProfile()['id'];
    $stored = Profiles::require('zed', $id);

    $merged = Json::merge((array)$stored['data'], ['language' => 'English']);
    Profiles::update('zed', $id, (string)$stored['name'], $merged);

    $after = Profiles::require('zed', $id);
    same('English', (string)$after['data']['language'], 'the field that was sent changed');
    same('sk-live-KEEPME', (string)$after['data']['ai'][0]['api_key'], 'and the one that was not did not');
});

/* ------------------------------------------------------------- cross references */

$index = LinkIndex::fromEntries([
    ['type' => 'page', 'id' => 1, 'title' => 'Advanced Vue', 'url' => 'https://x/adv'],
    ['type' => 'page', 'id' => 2, 'title' => 'Reactive state with ref', 'url' => 'https://x/ref'],
    ['type' => 'page', 'id' => 3, 'title' => 'Computed values', 'url' => 'https://x/computed'],
]);

test('a marker too short to be a title does not become a link', function () use ($index) {
    // The bug: with one entry beginning "A", the marker "A" resolved to
    // "Advanced Vue", was published as a real BookStack link, and was counted
    // as resolved rather than dropped. Nobody reading the report would know a
    // guess had been made.
    foreach (['A', 'R', 'C', 'Ad', 'Re'] as $marker) {
        ok($index->lookup($marker) === null, 'the marker "' . $marker . '" must not resolve to anything');
    }
});

test('a genuine prefix still resolves', function () use ($index) {
    $hit = $index->lookup('Reactive state');
    ok($hit !== null, 'a real abbreviation of a title still finds it');
    same('Reactive state with ref', (string)$hit['title'], 'and finds the right one');
});

test('an exact title always resolves, whatever its length', function () use ($index) {
    foreach (['Advanced Vue', 'Computed values', 'Reactive state with ref'] as $title) {
        $hit = $index->lookup($title);
        ok($hit !== null, $title . ' resolves');
        same($title, (string)$hit['title'], 'to itself');
    }
});

test('a title that matches nothing resolves to nothing', function () use ($index) {
    ok($index->lookup('Nothing like this at all') === null, 'no guess is made');
    ok($index->lookup('') === null, 'and an empty marker is not a lookup');
});
