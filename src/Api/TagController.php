<?php
declare(strict_types=1);

namespace CourseForge\Api;

use CourseForge\Domain\Tags;
use CourseForge\Security\Access;
use CourseForge\Security\Actor;
use CourseForge\Support\HttpException;
use CourseForge\Support\Request;

/**
 * The tag library: the names and values a course can be labelled with.
 *
 * Every route answers with the caller's own library, whoever's row was just
 * written - see `Access::workingSetOwner()`, which is where that rule and its
 * reasoning live.
 */
final class TagController
{
    /** @return array<string,mixed> */
    public static function index(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        return ['tags' => Tags::all(Access::workingSetOwner($me, $request))];
    }

    /** @return array<string,mixed> */
    public static function create(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $tag = Tags::create($me->username, $request->requiredStr('name', 'Tag name'), $request->str('value'));
        return ['tag' => $tag, 'tags' => Tags::all(Access::workingSetOwner($me, $request))];
    }

    /** @return array<string,mixed> */
    public static function update(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();

        // The tag belongs to whoever it belongs to; an administrator editing
        // somebody else's tag edits it in that person's library, not their own.
        $owner = (string)Access::tag($me, $request->id('id'))['username'];

        $tag = Tags::update($owner, $request->id('id'), $request->requiredStr('name', 'Tag name'), $request->str('value'));
        return ['tag' => $tag, 'tags' => Tags::all(Access::workingSetOwner($me, $request))];
    }

    /** @return array<string,mixed> */
    public static function delete(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();

        $owner = (string)Access::tag($me, $request->id('id'))['username'];

        Tags::delete($owner, $request->id('id'));
        return ['tags' => Tags::all(Access::workingSetOwner($me, $request))];
    }
}
