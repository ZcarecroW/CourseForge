<?php
declare(strict_types=1);

namespace CourseForge\Api;

use CourseForge\Ai\StructureGenerator;
use CourseForge\Domain\Chapters;
use CourseForge\Domain\Pages;
use CourseForge\Domain\Profiles;
use CourseForge\Domain\Projects;
use CourseForge\Domain\Research;
use CourseForge\Domain\Tags;
use CourseForge\Domain\Transfers;
use CourseForge\Publish\Targets;
use CourseForge\Security\Access;
use CourseForge\Security\Actor;
use CourseForge\Support\Audit;
use CourseForge\Support\HttpException;
use CourseForge\Support\Request;
use CourseForge\Support\Runtime;

final class ProjectController
{
    /** Fields a client may change, each with the cast that protects the column. */
    private const WRITABLE = [
        'name', 'topic', 'profile_id',
        'auto_tags', 'tag_pool', 'tag_pool_strict',
    ];

    /**
     * The single-destination fields, which are now the first of a list.
     *
     * They are not written to the course any more - the course's copy of them
     * is a mirror of its first publishing target - so a request naming any of
     * them replaces that target, which is what the field meant when it was the
     * only one there was. `PUT projects/{id}/targets` is the door to the whole
     * list; this one stays because "where does this course publish" is still a
     * sentence with one answer in it for most courses, and because clients
     * written against 4.6 send it.
     */
    private const DESTINATION = ['bs_instance_id', 'shelf_id', 'shelf_name'];

    /**
     * The course list.
     *
     * This is the one listing that widens by itself. An administrator opening
     * the course list is looking at the installation - what is being written on
     * it, what is stuck, how much of it there is - and every row carries an
     * `owner`, so a shared list is readable without a second request per
     * course. Profiles, tags and connections do the opposite and default to the
     * actor's own, because those are working sets rather than an inventory: a
     * picker holding every account's tags buries the twelve that are actually
     * in use. Both widen or narrow with `?owner=name`.
     *
     * @return array<string,mixed>
     */
    public static function index(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        return ['projects' => Projects::all(Access::listingOwner($me, $request->query('owner')))];
    }

    /** @return array<string,mixed> */
    public static function create(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();

        $profileId = $request->intOrNull('profile_id');
        if ($profileId !== null) {
            Profiles::require($me->username, $profileId); // reject a foreign profile id
        }
        $name = $request->str('name', 'Untitled course');
        $project = Projects::create($me->username, $name !== '' ? $name : 'Untitled course', $request->str('topic'), $profileId);

        return ['project' => Projects::tree($me->username, (int)$project['id'])];
    }

    /** @return array<string,mixed> */
    public static function show(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $id = $request->id('id');
        $owner = (string)Access::project($me, $id)['username'];

        return ['project' => Projects::tree($owner, $id)];
    }

    /** @return array<string,mixed> */
    public static function update(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $id = $request->id('id');
        $owner = (string)Access::project($me, $id)['username'];

        $fields = [];
        foreach (self::WRITABLE as $key) {
            if (!$request->has($key)) {
                continue;
            }
            $fields[$key] = match ($key) {
                'name' => $request->str($key) !== '' ? $request->str($key) : 'Untitled course',
                'profile_id' => $request->intOrNull($key),
                'auto_tags', 'tag_pool_strict' => $request->bool($key) ? 1 : 0,
                default => $request->str($key),
            };
        }
        if (($fields['profile_id'] ?? null) !== null) {
            // The profile has to be one of the owner's. An administrator
            // cannot lend their own AI account to somebody else's course by
            // assigning a profile id from their own library.
            Profiles::require($owner, (int)$fields['profile_id']);
        }

        Projects::update($owner, $id, $fields);

        $named = array_filter(self::DESTINATION, static fn(string $key): bool => $request->has($key));
        if ($named !== []) {
            $current = Targets::primary($id);

            // A shelf belongs to a destination. Asked for one on a course with
            // none, and without an instance to make one, there is nothing to
            // put it on - and saying so beats writing nothing and answering 200.
            if (!$request->has('bs_instance_id') && $current === null) {
                throw HttpException::unprocessable(
                    'This course has no BookStack instance yet, so there is nothing for a shelf to belong to. '
                    . 'Choose an instance first.'
                );
            }

            $shelf = [];
            if ($request->has('shelf_id')) {
                $shelf['shelf_id'] = $request->intOrNull('shelf_id');
            }
            if ($request->has('shelf_name')) {
                $shelf['shelf_name'] = $request->str('shelf_name');
            }

            Targets::setPrimary(
                $owner,
                $id,
                $request->has('bs_instance_id')
                    ? $request->str('bs_instance_id')
                    : (string)($current['instance_id'] ?? ''),
                $shelf,
            );
        }

        return ['project' => Projects::tree($owner, $id)];
    }

    /** @return array<string,mixed> */
    public static function delete(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $id = $request->id('id');
        $owner = (string)Access::project($me, $id)['username'];

        Projects::delete($owner, $id);
        return ['projects' => Projects::all(Access::listingOwner($me, $request->query('owner')))];
    }

    /* ------------------------------------------------------------- transfer */

    /**
     * Hands a course to another account.
     *
     * Administrators only. What has to move with a course, what is deliberately
     * left behind and why - the profile carries an API key, the tag links point
     * at a library the new owner does not own, the published book is not ours to
     * destroy - is all in `Domain\Transfers`, which this shares with the MCP
     * tool rather than reimplementing.
     *
     * What is this handler's own business is the audit line, because this is the
     * door that knows who pressed the button, and the response: `transfer.notes`
     * says in sentences the screen can print what was cleared, what had to be
     * created in the receiving library, and which tags now say something
     * different there.
     *
     * @return array<string,mixed>
     */
    public static function transfer(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $me->requireAdmin();

        $id = $request->id('id');
        $project = Access::project($me, $id);

        // The work itself lives in Domain\Transfers, because the MCP tool does
        // the same thing and a transfer that is complete on one front door and
        // partial on the other is the kind of difference nobody finds until the
        // data is already wrong.
        $result = Transfers::course($id, $request->requiredStr('to', 'The receiving account'));

        Audit::record(
            $me->username,
            'project.transfer',
            (string)$project['name'],
            'from ' . $result['from'] . ' to ' . $result['to']
                . '; tags=' . $result['tags'] . '; runs=' . $result['runs']
        );

        return [
            'project' => Projects::tree($result['to'], $id),
            'projects' => Projects::all(Access::listingOwner($me)),
            'transfer' => $result,
        ];
    }

    /* ------------------------------------------------------------ structure */

    /**
     * Designs a new outline, or revises the current one when feedback is given.
     *
     * The model has already been paid for by the time the answer arrives, so an
     * outline that would delete pages somebody has written is handed back
     * instead of being thrown away: `applied` false, the Markdown in
     * `structure_md`, and the pages at stake in `at_risk`. The client can put it
     * in front of the person, who applies it - or does not - through the apply
     * route, which costs nothing either way.
     */
    public static function generateStructure(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $id = $request->id('id');
        $project = Access::project($me, $id);
        $owner = (string)$project['username'];

        if ($project['profile_id'] === null) {
            throw HttpException::unprocessable('Assign a profile to this course first.');
        }
        if ($request->str('topic') !== '') {
            $project = Projects::update($owner, $id, ['topic' => $request->str('topic')]);
        }
        if (trim((string)$project['topic']) === '') {
            throw HttpException::unprocessable('Enter a course topic first.');
        }

        $profile = Profiles::data($owner, (int)$project['profile_id']);
        Runtime::beginLongRequest();

        $markdown = StructureGenerator::run($profile, $project, $request->str('feedback'));

        $atRisk = Projects::pagesLosingContent($project, $markdown);
        if ($atRisk !== [] && !$request->bool('confirm_removals')) {
            return [
                'project' => Projects::tree($owner, $id),
                'applied' => false,
                'structure_md' => trim($markdown),
                'at_risk' => $atRisk,
            ];
        }

        Projects::applyStructure($project, $markdown, $request->bool('confirm_removals'));

        return ['project' => Projects::tree($owner, $id), 'applied' => true];
    }

    /**
     * Parses the edited Markdown into chapters and pages.
     *
     * `confirm_removals` is the caller saying it knows the apply deletes pages
     * that have text on them. Without it the domain refuses and answers 422
     * with those page titles in `at_risk`, which is the same refusal the MCP
     * tool gets - one rule, so the two front doors cannot disagree about when
     * written work may be destroyed.
     */
    public static function applyStructure(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $id = $request->id('id');
        $project = Access::project($me, $id);
        $owner = (string)$project['username'];

        $markdown = $request->raw('structure_md');
        if (trim($markdown) === '') {
            throw HttpException::unprocessable('The structure must not be empty.');
        }

        $result = Projects::applyStructure($project, $markdown, $request->bool('confirm_removals'));
        return [
            'project' => Projects::tree($owner, $id),
            'removed' => ['pages' => $result['removed_pages'], 'chapters' => $result['removed_chapters']],
        ];
    }

    /* -------------------------------------------------------------- details */

    /**
     * Patches the content details of the course, one chapter or one page.
     * A feature sent as 0 and a parameter sent as null both mean "inherit".
     */
    public static function updateDetails(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $id = $request->id('id');
        $owner = (string)Access::project($me, $id)['username'];

        $target = $request->enum('target', ['course', 'chapter', 'page'], 'course');
        $features = $request->arr('features');
        $params = $request->arr('params');

        match ($target) {
            'chapter' => Chapters::patchDetails($id, $request->requiredId('target_id', 'Chapter id'), $features, $params),
            'page' => Pages::patchDetails($id, $request->requiredId('target_id', 'Page id'), $features, $params),
            default => Projects::patchDetails($owner, $id, $features, $params),
        };

        Projects::touch($id);
        return ['project' => Projects::tree($owner, $id)];
    }

    /* ------------------------------------------------------------- research */

    /**
     * Stores what was found out about the subject, or clears it.
     *
     * The browser's door to the same column `store_research` writes over MCP.
     * A person pastes in what they already know, or edits what a client found;
     * either way it lands in one place, is stamped with today's date, and is
     * read by the outline and by every page from then on.
     *
     * Recorded as coming from a person rather than from a client, because that
     * is the only difference worth keeping: nothing downstream behaves
     * differently, but "who said this" is the first question about a fact that
     * turns out to be wrong.
     */
    public static function updateResearch(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $id = $request->id('id');
        $owner = (string)Access::project($me, $id)['username'];

        // raw(), not str(): findings are Markdown and the line breaks are what
        // makes them readable.
        $result = Research::store($owner, $id, $request->raw('research'), Research::SOURCE_MANUAL);

        return ['project' => Projects::tree($owner, $id)] + $result;
    }

    /* ----------------------------------------------------------------- tags */

    public static function attachTag(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $id = $request->id('id');
        $project = Access::project($me, $id);
        $owner = (string)$project['username'];

        // Every tag call runs against the owner's library, never the actor's:
        // a tag an administrator adds to somebody else's course has to be a
        // tag that course's owner can then see, edit and take off again.
        Tags::attach(
            $owner,
            $project,
            $request->enum('target', ['course', 'chapter', 'page'], 'course'),
            $request->intOrNull('target_id'),
            $request->requiredStr('name', 'Tag name'),
            $request->str('value'),
            $request->bool('inherit')
        );
        return self::tagResult($owner, $id);
    }

    /** "enabled" toggles one assignment (used for AI tags); "inherit" flows down. */
    public static function updateTag(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $id = $request->id('id');
        $project = Access::project($me, $id);
        $owner = (string)$project['username'];

        $target = $request->enum('target', ['course', 'chapter', 'page'], 'course');
        $targetId = $request->intOrNull('target_id');
        $tagId = $request->requiredId('tag_id', 'Tag id');

        if ($request->has('enabled')) {
            Tags::setEnabled($owner, $project, $target, $targetId, $tagId, $request->bool('enabled'));
        } else {
            Tags::setInherit($owner, $project, $target, $targetId, $tagId, $request->bool('inherit'));
        }
        return self::tagResult($owner, $id);
    }

    public static function detachTag(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $id = $request->id('id');
        $project = Access::project($me, $id);
        $owner = (string)$project['username'];

        Tags::detach(
            $owner,
            $project,
            $request->enum('target', ['course', 'chapter', 'page'], 'course'),
            $request->intOrNull('target_id'),
            $request->requiredId('tag_id', 'Tag id')
        );
        return self::tagResult($owner, $id);
    }

    /** @return array<string,mixed> */
    private static function tagResult(string $owner, int $projectId): array
    {
        Projects::touch($projectId);
        return ['project' => Projects::tree($owner, $projectId), 'tags' => Tags::all($owner)];
    }
}
