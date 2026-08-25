<?php
declare(strict_types=1);

namespace CourseForge\Api;

use CourseForge\Ai\PageGenerator;
use CourseForge\Domain\Chapters;
use CourseForge\Domain\Pages;
use CourseForge\Domain\Structure;
use CourseForge\Domain\Profiles;
use CourseForge\Domain\Projects;
use CourseForge\Security\Access;
use CourseForge\Security\Actor;
use CourseForge\Support\HttpException;
use CourseForge\Support\Request;
use CourseForge\Support\Runtime;

/** One page: read it, save it, have the AI write it. */
final class PageController
{
    /** @return array<string,mixed> */
    public static function show(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $projectId = $request->id('id');
        Access::project($me, $projectId); // 404 unless the actor may reach the course

        return ['page' => Pages::detail($projectId, $request->id('pageId'))];
    }

    /** @return array<string,mixed> */
    public static function update(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $projectId = $request->id('id');
        $pageId = $request->id('pageId');

        $owner = (string)Access::project($me, $projectId)['username'];
        Pages::require($projectId, $pageId);

        $fields = [];
        foreach (['title', 'content', 'extra_context'] as $key) {
            if ($request->has($key)) {
                $fields[$key] = $request->raw($key);
            }
        }
        if (isset($fields['title'])) {
            // The same rule the MCP tool is held to, in the same words: a title
            // is stored in the form the outline can carry unchanged, because
            // applyStructure matches by title and a title that reads back
            // differently orphans its own page. See Structure::canonicalTitle.
            $fields['title'] = Structure::canonicalTitle($fields['title']);
            if ($fields['title'] === '') {
                throw HttpException::unprocessable('The page title must not be empty.');
            }
        }
        if (isset($fields['content'])) {
            // Emptying a page puts it back into the generation queue.
            $fields['status'] = trim($fields['content']) === '' ? 'pending' : 'generated';
            $fields['error'] = '';
        }

        Pages::update($pageId, $fields);
        if (isset($fields['title'])) {
            Projects::resyncStructure($owner, $projectId); // keep title-based matching intact
        }
        Projects::touch($projectId);

        return ['page' => Pages::detail($projectId, $pageId)];
    }

    /** @return array<string,mixed> */
    public static function generate(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $projectId = $request->id('id');
        $pageId = $request->id('pageId');

        $project = Access::project($me, $projectId);
        $owner = (string)$project['username'];
        Pages::require($projectId, $pageId);

        if ($project['profile_id'] === null) {
            throw HttpException::unprocessable('Assign a profile to this course first.');
        }
        // The model, the key and the language belong to the course, which means
        // to its owner - an administrator writing a page here spends the
        // owner's account, not their own.
        $profile = Profiles::data($owner, (int)$project['profile_id']);

        if ($request->has('extra_context')) {
            Pages::update($pageId, ['extra_context' => $request->raw('extra_context')]);
        }

        // Critical for parallel generation: free the session lock first.
        Runtime::beginLongRequest();

        return ['page' => PageGenerator::run($profile, $project, $pageId, $request->str('feedback'))];
    }

    /* -------------------------------------------------------------- chapter */

    /** @return array<string,mixed> */
    public static function updateChapter(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $projectId = $request->id('id');
        $chapterId = $request->id('chapterId');

        $owner = (string)Access::project($me, $projectId)['username'];
        Chapters::require($projectId, $chapterId);

        $fields = [];
        if ($request->has('title')) {
            $title = Structure::canonicalTitle($request->str('title'));
            if ($title === '') {
                throw HttpException::unprocessable('The chapter title must not be empty.');
            }
            $fields['title'] = $title;
        }
        if ($request->has('description')) {
            $fields['description'] = $request->raw('description');
        }

        Chapters::update($chapterId, $fields);
        if (isset($fields['title']) || isset($fields['description'])) {
            Projects::resyncStructure($owner, $projectId);
        }
        Projects::touch($projectId);

        return ['project' => Projects::tree($owner, $projectId)];
    }
}
