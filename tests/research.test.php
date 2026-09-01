<?php
/**
 * Course-level research: found once, read by everything after it.
 *
 * `web_research` has always existed and has always been per page: every page
 * brief could say "look it up first", and a client with a search tool would.
 * That is right for "cite what you read on this page" and wrong for "which
 * version of WordPress is this course about" - the second is one fact, the
 * whole course needs the same answer, and asking two hundred pages to find it
 * separately gets two hundred answers and pays for the search two hundred
 * times.
 *
 * So findings live on the course, carry the date they were established, and
 * travel into the outline brief and every page brief from then on. What is
 * asserted here is the part that is easy to get wrong later: that the block
 * disappears entirely when there is nothing to say, that it carries its date
 * when there is, that the outline sees it too (an outline designed from stale
 * facts cannot be repaired by researching a page afterwards), and that storing
 * respects the length cap that every page pays for.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use CourseForge\Domain\Details;
use CourseForge\Domain\Projects;
use CourseForge\Domain\Research;
use CourseForge\Mcp\Tools;
use CourseForge\Security\Actor;
use CourseForge\Support\Db;

function researchCourse(string $name): array
{
    Db::run(
        'INSERT INTO projects (username, name, topic, created_at, updated_at) VALUES (?,?,?,?,?)',
        ['zed', $name, 'WordPress plugin development', time(), time()]
    );
    return Projects::require('zed', Db::lastId());
}

test('a course with no research contributes no block at all', function () {
    $project = researchCourse('no research');

    ok(!Research::has($project), 'nothing is stored');
    same('', Research::block($project), 'and the prompt block is empty, not a heading over nothing');
    same('none stored', Research::freshness($project), 'freshness says so plainly');
    same(null, Research::at($project), 'and there is no date');
});

test('stored findings come back with the date they were established', function () {
    $project = researchCourse('with research');
    Research::store('zed', (int)$project['id'], "## Versions\n- WordPress 7.2, released May 2026", Research::SOURCE_CLIENT);

    $project = Projects::require('zed', (int)$project['id']);
    ok(Research::has($project), 'the findings are stored');
    ok(str_contains(Research::block($project), 'WordPress 7.2'), 'the block carries the findings');
    ok(str_contains(Research::block($project), gmdate('Y')), 'and the year they were established');
    same('stored today', Research::freshness($project), 'freshness is reported from the stamp');
    same(0, Research::ageInDays($project), 'which is zero days old');
});

test('an empty string clears the findings and the date with them', function () {
    $project = researchCourse('cleared');
    Research::store('zed', (int)$project['id'], 'Something.', Research::SOURCE_MANUAL);
    Research::store('zed', (int)$project['id'], '   ', Research::SOURCE_MANUAL);

    $project = Projects::require('zed', (int)$project['id']);
    ok(!Research::has($project), 'the findings are gone');
    same(null, Research::at($project), 'and so is the date, so "never" and "unknown when" stay different');
    same('', (string)$project['research_source'], 'the source is cleared too');
});

test('findings past the cap are cut at a line boundary, and say so', function () {
    $project = researchCourse('too long');
    $line = "- A fact about the subject that is long enough to matter to the total.\n";
    $findings = str_repeat($line, (int)ceil((Research::MAX_CHARS * 1.5) / strlen($line)));
    ok(mb_strlen($findings) > Research::MAX_CHARS, 'the fixture is over the cap');

    $result = Research::store('zed', (int)$project['id'], $findings, Research::SOURCE_CLIENT);

    ok($result['truncated'], 'the caller is told it was cut');
    ok($result['characters'] <= Research::MAX_CHARS, 'and what was stored is within the cap');

    $stored = Research::of(Projects::require('zed', (int)$project['id']));
    ok(!str_ends_with($stored, '-'), 'the cut did not land mid-bullet');
    same($stored, rtrim($stored), 'and left no trailing whitespace');
});

test('the freshness of old findings says they are worth refreshing', function () {
    $project = researchCourse('stale');
    Research::store('zed', (int)$project['id'], 'An old fact.', Research::SOURCE_CLIENT);
    Projects::update('zed', (int)$project['id'], ['research_at' => time() - (95 * 86400)]);

    $project = Projects::require('zed', (int)$project['id']);
    same(95, Research::ageInDays($project), 'the age is counted in days');
    ok(str_contains(Research::freshness($project), 'worth refreshing'), 'and three months old says so');
    ok(Research::has($project), 'but nothing expired it - that is a decision for a person');
});

/* ------------------------------------------------------------- the MCP tools */

function researchActor(): Actor
{
    return Actor::make('zed', 'Zed', Actor::ROLE_ADMIN);
}

test('the three research tools are on the surface, in their own scope', function () {
    $registry = Tools::registry();

    foreach (['get_research_brief', 'store_research', 'get_research'] as $name) {
        ok(isset($registry[$name]), $name . ' is registered');
        same('research', $registry[$name]->scope, $name . ' is in the research scope');
    }
    ok($registry['get_research_brief']->readOnly, 'taking the assignment changes nothing');
    ok(!$registry['get_research_brief']->spends, 'and spends nothing - the client does the searching');
});

test('the research brief names the subject and says what to establish', function () {
    $project = researchCourse('brief');
    $answer = Tools::call(researchActor(), 'get_research_brief', ['course_id' => (int)$project['id']])['data'];

    ok(str_contains($answer['research_brief'], 'WordPress plugin development'), 'the brief carries the topic');
    ok(str_contains($answer['research_brief'], 'deprecated'), 'and asks what has been removed');
    ok(str_contains($answer['research_brief'], 'Sources'), 'and asks for sources back');
    same('', $answer['existing_research'], 'nothing is stored yet');
    same('store_research', $answer['next_tool'], 'and it points at where to send the answer');
});

test('storing through the tool makes the findings readable through the tool', function () {
    $project = researchCourse('round trip');
    $id = (int)$project['id'];

    $stored = Tools::call(researchActor(), 'store_research', [
        'course_id' => $id,
        'findings' => "## Versions\n- WordPress 7.2\n\n## Sources\n- https://wordpress.org/news/",
    ])['data'];
    ok($stored['stored'], 'it stored');
    ok(!$stored['truncated'], 'and did not need cutting');

    $read = Tools::call(researchActor(), 'get_research', ['course_id' => $id])['data'];
    ok($read['has_research'], 'it reads back');
    ok(str_contains($read['research'], 'WordPress 7.2'), 'with the text intact');
    same('client', $read['source'], 'recorded as coming from the connected client');
    same(0, $read['age_in_days'], 'and dated today');
});

test('the outline brief carries the findings once they exist, and asks for them when they do not', function () {
    $project = researchCourse('outline brief');
    $id = (int)$project['id'];
    Projects::patchDetails('zed', $id, ['web_research' => Details::ON], []);

    $before = Tools::call(researchActor(), 'get_structure_brief', ['course_id' => $id])['data'];
    ok($before['web_research'], 'the brief reports that this course wants research');
    ok($before['research_brief'] !== '', 'and hands over the assignment, because nothing is stored');
    ok(str_contains($before['next_step'], 'store_research'), 'and says to research before designing');

    Tools::call(researchActor(), 'store_research', ['course_id' => $id, 'findings' => 'WordPress 7.2 is current.']);

    $after = Tools::call(researchActor(), 'get_structure_brief', ['course_id' => $id])['data'];
    ok(str_contains($after['stored_research'], 'WordPress 7.2'), 'now the brief carries what was found');
    same('', $after['research_brief'], 'and stops asking for it again');
    ok(str_contains($after['next_step'], 'design the outline against them'), 'and says to design against it');

    // The client and CourseForge's own model are asked for the same thing in
    // the same words, so the findings belong in the instructions themselves
    // and not only in a field beside them.
    ok(
        str_contains($after['system_instructions'], 'WordPress 7.2'),
        'and the system instructions handed to the client carry the findings too'
    );
});

test('a course that does not ask for research is not told to go and do any', function () {
    $project = researchCourse('no research wanted');
    $brief = Tools::call(researchActor(), 'get_structure_brief', ['course_id' => (int)$project['id']])['data'];

    ok(!$brief['web_research'], 'research is off');
    same(0, $brief['max_searches'], 'so no budget is quoted');
    same('', $brief['research_brief'], 'and no assignment is handed over');
    ok(!str_contains($brief['next_step'], 'store_research'), 'the next step is simply to design the outline');
});

test('create_course can switch research on for the whole course in one call', function () {
    $answer = Tools::call(researchActor(), 'create_course', [
        'name' => 'WordPress 2026',
        'topic' => 'Building blocks and plugins against the current WordPress',
        'web_research' => true,
        'research_max_searches' => 12,
    ])['data'];

    ok($answer['web_research'], 'the course comes back with research on');
    ok(str_contains($answer['research'], 'get_research_brief'), 'and is told where to start');

    $project = Projects::require('zed', (int)$answer['course_id']);
    $details = Details::resolve(Projects::settings($project));
    same(true, $details['features']['web_research'], 'the toggle really is on for the course');
    same(12, $details['params']['research_max_searches'], 'and the search budget was written too');
});

test('get_next_step sends an empty research course to research before the outline', function () {
    $project = researchCourse('guided');
    $id = (int)$project['id'];
    Projects::patchDetails('zed', $id, ['web_research' => Details::ON], []);

    $step = Tools::call(researchActor(), 'get_next_step', ['course_id' => $id])['data'];
    same('needs_research', $step['state'], 'research comes before the outline, because the outline is designed from it');
    same('get_research_brief', $step['next']['tool'], 'and that is the tool it names');

    Tools::call(researchActor(), 'store_research', ['course_id' => $id, 'findings' => 'WordPress 7.2 is current.']);

    $after = Tools::call(researchActor(), 'get_next_step', ['course_id' => $id])['data'];
    same('needs_outline', $after['state'], 'once it is stored the guide moves on');
    same('get_structure_brief', $after['next']['tool'], 'to designing the outline');
});

test('a course that never asked for research is never sent to do any', function () {
    $project = researchCourse('unguided');
    $step = Tools::call(researchActor(), 'get_next_step', ['course_id' => (int)$project['id']])['data'];

    same('needs_outline', $step['state'], 'straight to the outline, as it always was');
});

test('create_course without the argument leaves the setting inheriting, not off', function () {
    $answer = Tools::call(researchActor(), 'create_course', ['name' => 'Ordinary course'])['data'];
    $project = Projects::require('zed', (int)$answer['course_id']);

    same([], Projects::settings($project)['features'], 'the course decided nothing of its own');
});
