<?php
declare(strict_types=1);

namespace CourseForge\Api;

use CourseForge\Domain\Projects;
use CourseForge\Publish\Publisher;
use CourseForge\Security\Access;
use CourseForge\Security\Actor;
use CourseForge\Support\HttpException;
use CourseForge\Support\Request;
use CourseForge\Support\Runtime;

final class PublishController
{
    /** @return array<string,mixed> */
    public static function push(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $projectId = $request->id('id');

        // The BookStack instance and its credentials come from the course
        // owner's profile, so the push is made in their name whoever asked
        // for it.
        $owner = (string)Access::project($me, $projectId)['username'];

        $scope = $request->enum('scope', ['all', 'book', 'chapter', 'page'], 'all');
        $targetId = $request->intOrNull('target_id');
        if (in_array($scope, ['chapter', 'page'], true) && ($targetId === null || $targetId <= 0)) {
            throw HttpException::unprocessable('A target id is required when pushing a single ' . $scope . '.');
        }

        $publisher = Publisher::open($owner, $projectId);
        Runtime::beginLongRequest(); // a full push is many HTTP calls

        $result = $publisher->push($scope, $targetId, $request->bool('force'));

        return [
            'log' => $result['log'],
            'links' => $result['links'],
            'project' => Projects::tree($owner, $projectId),
        ];
    }

    /**
     * Turns the (🔗 Title) markers into real BookStack links and re-publishes
     * only the pages whose text actually changed. No AI involved.
     *
     * @return array<string,mixed>
     */
    public static function resolveLinks(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $projectId = $request->id('id');
        $owner = (string)Access::project($me, $projectId)['username'];

        $publisher = Publisher::open($owner, $projectId);
        Runtime::beginLongRequest();

        $result = $publisher->resolveLinks($request->bool('force'));

        return [
            'log' => $result['log'],
            'links' => $result['links'],
            'project' => Projects::tree($owner, $projectId),
        ];
    }
}
