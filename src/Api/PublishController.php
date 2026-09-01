<?php
declare(strict_types=1);

namespace CourseForge\Api;

use CourseForge\Domain\Projects;
use CourseForge\Publish\Publisher;
use CourseForge\Publish\Targets;
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

        // The BookStack instances and their credentials come from the course
        // owner's profile, so the push is made in their name whoever asked
        // for it.
        $owner = (string)Access::project($me, $projectId)['username'];

        $scope = $request->enum('scope', ['all', 'book', 'chapter', 'page'], 'all');
        $targetId = $request->intOrNull('target_id');
        if (in_array($scope, ['chapter', 'page'], true) && ($targetId === null || $targetId <= 0)) {
            throw HttpException::unprocessable('A target id is required when pushing a single ' . $scope . '.');
        }

        $publisher = Publisher::open($owner, $projectId, self::targetIds($request));
        Runtime::beginLongRequest(); // a full push is many HTTP calls, times the number of wikis

        $result = $publisher->push($scope, $targetId, $request->bool('force'));

        return [
            'log' => $result['log'],
            'links' => $result['links'],
            'targets' => $result['targets'],
            'failed' => $result['failed'],
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

        $publisher = Publisher::open($owner, $projectId, self::targetIds($request));
        Runtime::beginLongRequest();

        $result = $publisher->resolveLinks($request->bool('force'));

        return [
            'log' => $result['log'],
            'links' => $result['links'],
            'targets' => $result['targets'],
            'failed' => $result['failed'],
            'project' => Projects::tree($owner, $projectId),
        ];
    }

    /**
     * Writes the whole list of destinations at once.
     *
     * Sending the list rather than one add and one remove per click is what
     * makes the order meaningful and the edit atomic: the first entry is the
     * one the course's own columns mirror, and two browsers cannot leave a
     * course half-reordered between them.
     *
     * A destination that survives the edit keeps the book it created; one the
     * list no longer names is forgotten, book and all. The book itself stays
     * where it is in BookStack - nothing here reaches a wiki.
     *
     * @return array<string,mixed>
     */
    public static function saveTargets(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $projectId = $request->id('id');
        $project = Access::project($me, $projectId);
        $owner = (string)$project['username'];

        // The field has to be there. An absent one would reach replaceAll() as
        // an empty list, which means "this course publishes nowhere" and throws
        // every destination away - far too much for a request that forgot a key
        // or sent null.
        if (!$request->has('targets')) {
            throw HttpException::unprocessable(
                'Field "targets" is required. Send the whole list of destinations, or an empty list to publish nowhere.'
            );
        }
        $incoming = $request->arr('targets');
        if (!array_is_list($incoming)) {
            throw HttpException::unprocessable('Field "targets" must be a list of destinations.');
        }

        // Every instance named has to be one the course's profile defines,
        // because that is where the credentials for it are - unless the course
        // already publishes to it. A profile that has stopped defining an
        // instance leaves a destination pointing at nothing, and refusing the
        // whole save on account of it would make that destination impossible to
        // keep while anything else on the list is edited.
        $known = Targets::instancesOf($owner, $project['profile_id'] === null ? null : (int)$project['profile_id']);
        foreach (Targets::all($projectId) as $target) {
            $known[(string)$target['instance_id']] ??= ['name' => '', 'base_url' => ''];
        }

        foreach ($incoming as $i => $entry) {
            if (!is_array($entry)) {
                throw HttpException::unprocessable('Entry ' . ($i + 1) . ' of "targets" is not a destination.');
            }
            $instanceId = trim((string)($entry['instance_id'] ?? ''));
            if ($instanceId !== '' && !isset($known[$instanceId])) {
                throw HttpException::unprocessable(
                    'BookStack instance "' . $instanceId . '" is not part of this course\'s profile. '
                    . 'Add it under Profiles → Accounts first.'
                );
            }
        }

        Targets::replaceAll($owner, $projectId, $incoming);

        return ['project' => Projects::tree($owner, $projectId)];
    }

    /**
     * The wikis one request is aimed at, or null for "every one that is on".
     *
     * @return array<int,int>|null
     */
    private static function targetIds(Request $request): ?array
    {
        if (!$request->has('target_ids')) {
            return null;
        }

        $given = $request->arr('target_ids');
        if ($given === []) {
            return null; // an empty list reads as "you decide", which is all of them
        }

        // Anything else has to be usable. Dropping a malformed id and carrying
        // on would turn "publish to this one wiki" into "publish to all of
        // them", which is the opposite of what was asked for.
        $ids = [];
        foreach ($given as $id) {
            if (!is_numeric($id) || (int)$id <= 0 || (float)$id !== (float)(int)$id) {
                throw HttpException::unprocessable('target_ids must hold destination ids, as whole numbers.');
            }
            $ids[] = (int)$id;
        }
        return $ids;
    }
}
