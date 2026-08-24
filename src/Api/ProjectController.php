<?php
declare(strict_types=1);

namespace CourseForge\Api;

use CourseForge\Ai\StructureGenerator;
use CourseForge\Domain\Chapters;
use CourseForge\Domain\Pages;
use CourseForge\Domain\Profiles;
use CourseForge\Domain\Projects;
use CourseForge\Domain\Tags;
use CourseForge\Support\HttpException;
use CourseForge\Support\Request;
use CourseForge\Support\Runtime;

final class ProjectController
{
    /** Fields a client may change, each with the cast that protects the column. */
    private const WRITABLE = [
        'name', 'topic', 'profile_id', 'bs_instance_id', 'shelf_id', 'shelf_name',
        'auto_tags', 'tag_pool', 'tag_pool_strict',
    ];

    /** @return array<string,mixed> */
    public static function index(Request $request, string $username): array
    {
        return ['projects' => Projects::all($username)];
    }

    /** @return array<string,mixed> */
    public static function create(Request $request, string $username): array
    {
        $profileId = $request->intOrNull('profile_id');
        if ($profileId !== null) {
            Profiles::require($username, $profileId); // reject a foreign profile id
        }
        $name = $request->str('name', 'Untitled course');
        $project = Projects::create($username, $name !== '' ? $name : 'Untitled course', $request->str('topic'), $profileId);

        return ['project' => Projects::tree($username, (int)$project['id'])];
    }

    /** @return array<string,mixed> */
    public static function show(Request $request, string $username): array
    {
        return ['project' => Projects::tree($username, $request->id('id'))];
    }

    /** @return array<string,mixed> */
    public static function update(Request $request, string $username): array
    {
        $id = $request->id('id');
        Projects::require($username, $id);

        $fields = [];
        foreach (self::WRITABLE as $key) {
            if (!$request->has($key)) {
                continue;
            }
            $fields[$key] = match ($key) {
                'name' => $request->str($key) !== '' ? $request->str($key) : 'Untitled course',
                'profile_id', 'shelf_id' => $request->intOrNull($key),
                'auto_tags', 'tag_pool_strict' => $request->bool($key) ? 1 : 0,
                default => $request->str($key),
            };
        }
        if (($fields['profile_id'] ?? null) !== null) {
            Profiles::require($username, (int)$fields['profile_id']);
        }

        Projects::update($username, $id, $fields);
        return ['project' => Projects::tree($username, $id)];
    }

    /** @return array<string,mixed> */
    public static function delete(Request $request, string $username): array
    {
        $id = $request->id('id');
        Projects::require($username, $id);
        Projects::delete($username, $id);
        return ['projects' => Projects::all($username)];
    }

    /* ------------------------------------------------------------ structure */

    /** Designs a new outline, or revises the current one when feedback is given. */
    public static function generateStructure(Request $request, string $username): array
    {
        $id = $request->id('id');
        $project = Projects::require($username, $id);

        if ($project['profile_id'] === null) {
            throw HttpException::unprocessable('Assign a profile to this course first.');
        }
        if ($request->str('topic') !== '') {
            $project = Projects::update($username, $id, ['topic' => $request->str('topic')]);
        }
        if (trim((string)$project['topic']) === '') {
            throw HttpException::unprocessable('Enter a course topic first.');
        }

        $profile = Profiles::data($username, (int)$project['profile_id']);
        Runtime::beginLongRequest();

        $markdown = StructureGenerator::run($profile, $project, $request->str('feedback'));
        Projects::applyStructure($project, $markdown);

        return ['project' => Projects::tree($username, $id)];
    }

    /** Parses the edited Markdown into chapters and pages. */
    public static function applyStructure(Request $request, string $username): array
    {
        $id = $request->id('id');
        $project = Projects::require($username, $id);

        $markdown = $request->raw('structure_md');
        if (trim($markdown) === '') {
            throw HttpException::unprocessable('The structure must not be empty.');
        }

        $result = Projects::applyStructure($project, $markdown);
        return [
            'project' => Projects::tree($username, $id),
            'removed' => ['pages' => $result['removed_pages'], 'chapters' => $result['removed_chapters']],
        ];
    }

    /* -------------------------------------------------------------- details */

    /**
     * Patches the content details of the course, one chapter or one page.
     * A feature sent as 0 and a parameter sent as null both mean "inherit".
     */
    public static function updateDetails(Request $request, string $username): array
    {
        $id = $request->id('id');
        Projects::require($username, $id);

        $target = $request->enum('target', ['course', 'chapter', 'page'], 'course');
        $features = $request->arr('features');
        $params = $request->arr('params');

        match ($target) {
            'chapter' => Chapters::patchDetails($id, $request->requiredId('target_id', 'Chapter id'), $features, $params),
            'page' => Pages::patchDetails($id, $request->requiredId('target_id', 'Page id'), $features, $params),
            default => Projects::patchDetails($username, $id, $features, $params),
        };

        Projects::touch($id);
        return ['project' => Projects::tree($username, $id)];
    }

    /* ----------------------------------------------------------------- tags */

    public static function attachTag(Request $request, string $username): array
    {
        $id = $request->id('id');
        $project = Projects::require($username, $id);

        Tags::attach(
            $username,
            $project,
            $request->enum('target', ['course', 'chapter', 'page'], 'course'),
            $request->intOrNull('target_id'),
            $request->requiredStr('name', 'Tag name'),
            $request->str('value'),
            $request->bool('inherit')
        );
        return self::tagResult($username, $id);
    }

    /** "enabled" toggles one assignment (used for AI tags); "inherit" flows down. */
    public static function updateTag(Request $request, string $username): array
    {
        $id = $request->id('id');
        $project = Projects::require($username, $id);

        $target = $request->enum('target', ['course', 'chapter', 'page'], 'course');
        $targetId = $request->intOrNull('target_id');
        $tagId = $request->requiredId('tag_id', 'Tag id');

        if ($request->has('enabled')) {
            Tags::setEnabled($username, $project, $target, $targetId, $tagId, $request->bool('enabled'));
        } else {
            Tags::setInherit($username, $project, $target, $targetId, $tagId, $request->bool('inherit'));
        }
        return self::tagResult($username, $id);
    }

    public static function detachTag(Request $request, string $username): array
    {
        $id = $request->id('id');
        $project = Projects::require($username, $id);

        Tags::detach(
            $username,
            $project,
            $request->enum('target', ['course', 'chapter', 'page'], 'course'),
            $request->intOrNull('target_id'),
            $request->requiredId('tag_id', 'Tag id')
        );
        return self::tagResult($username, $id);
    }

    /** @return array<string,mixed> */
    private static function tagResult(string $username, int $projectId): array
    {
        Projects::touch($projectId);
        return ['project' => Projects::tree($username, $projectId), 'tags' => Tags::all($username)];
    }
}
