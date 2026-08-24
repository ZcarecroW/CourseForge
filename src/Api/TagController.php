<?php
declare(strict_types=1);

namespace CourseForge\Api;

use CourseForge\Domain\Tags;
use CourseForge\Support\Request;

final class TagController
{
    /** @return array<string,mixed> */
    public static function index(Request $request, string $username): array
    {
        return ['tags' => Tags::all($username)];
    }

    /** @return array<string,mixed> */
    public static function create(Request $request, string $username): array
    {
        $tag = Tags::create($username, $request->requiredStr('name', 'Tag name'), $request->str('value'));
        return ['tag' => $tag, 'tags' => Tags::all($username)];
    }

    /** @return array<string,mixed> */
    public static function update(Request $request, string $username): array
    {
        $tag = Tags::update($username, $request->id('id'), $request->requiredStr('name', 'Tag name'), $request->str('value'));
        return ['tag' => $tag, 'tags' => Tags::all($username)];
    }

    /** @return array<string,mixed> */
    public static function delete(Request $request, string $username): array
    {
        Tags::delete($username, $request->id('id'));
        return ['tags' => Tags::all($username)];
    }
}
