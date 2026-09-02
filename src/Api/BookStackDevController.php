<?php
declare(strict_types=1);

namespace CourseForge\Api;

use CourseForge\Domain\BookStackDev;
use CourseForge\Security\Access;
use CourseForge\Security\Actor;
use CourseForge\Support\Audit;
use CourseForge\Support\HttpException;
use CourseForge\Support\Request;

/**
 * BookStackDev profiles: the look a BookStack instance is given, and the link
 * that gives it.
 *
 * The same ownership rule as profiles - every route answers with the caller's
 * own list whichever look was just written, see Access::workingSetOwner() -
 * and the same shape: a write hands back the row it touched and the whole
 * list, so the screen never has to ask twice.
 */
final class BookStackDevController
{
    /** @return array<string,mixed> */
    public static function index(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $owner = Access::workingSetOwner($me, $request);

        return self::listing($me, $owner) + [
            'catalogue' => BookStackDev::catalogue(),
            'themes' => BookStackDev::SHIKI_THEMES,
        ];
    }

    /**
     * Every finding across the looks the caller may see, for the two prompt
     * screens - which draw the ones about the layer they edit.
     *
     * @return array<string,mixed>
     */
    public static function audit(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $owner = $me->isAdmin() ? null : $me->username;

        $issues = [];
        foreach (BookStackDev::all($owner) as $row) {
            foreach (BookStackDev::audit($row)['issues'] as $issue) {
                $issue['bookstackdev_id'] = (int)$row['id'];
                $issue['bookstackdev_name'] = (string)$row['name'];
                $issue['owner'] = (string)$row['username'];
                $issues[] = $issue;
            }
        }
        return ['issues' => $issues];
    }

    /** @return array<string,mixed> */
    public static function create(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();

        $row = BookStackDev::create(
            $me->username,
            $request->str('name', 'New look'),
            $request->arr('settings'),
            self::strings($request, 'origins'),
        );
        if ($request->has('instance_ids')) {
            BookStackDev::assignInstances($me->username, (int)$row['id'], self::strings($request, 'instance_ids'));
        }
        Audit::record($me->username, 'bookstackdev.create', (string)$row['name']);

        return ['profile' => BookStackDev::describe(BookStackDev::require($me->username, (int)$row['id']))]
            + self::listing($me, Access::workingSetOwner($me, $request));
    }

    /** @return array<string,mixed> */
    public static function update(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $id = $request->id('id');
        $owner = self::owner($me, $id);

        $fields = [];
        if ($request->has('name')) {
            $fields['name'] = $request->str('name');
        }
        if ($request->has('settings')) {
            $fields['settings'] = $request->arr('settings');
        }
        if ($request->has('origins')) {
            $fields['origins'] = self::strings($request, 'origins');
        }
        $row = BookStackDev::update($owner, $id, $fields);
        if ($request->has('instance_ids')) {
            BookStackDev::assignInstances($owner, $id, self::strings($request, 'instance_ids'));
        }
        Audit::record($me->username, 'bookstackdev.update', (string)$row['name'], implode(', ', array_keys($fields)));

        return ['profile' => BookStackDev::describe(BookStackDev::require($owner, $id))]
            + self::listing($me, Access::workingSetOwner($me, $request));
    }

    /** @return array<string,mixed> */
    public static function delete(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $id = $request->id('id');
        $owner = self::owner($me, $id);

        $row = BookStackDev::require($owner, $id);
        BookStackDev::delete($owner, $id);
        Audit::record($me->username, 'bookstackdev.delete', (string)$row['name']);

        return self::listing($me, Access::workingSetOwner($me, $request));
    }

    /**
     * A new link. The old one is refused from this moment, wherever it was
     * pasted - which is the whole reason to press this.
     *
     * @return array<string,mixed>
     */
    public static function rotateKey(Request $request, ?Actor $actor): array
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $id = $request->id('id');
        $owner = self::owner($me, $id);

        $row = BookStackDev::rotateKey($owner, $id);
        Audit::record($me->username, 'bookstackdev.rotate', (string)$row['name']);

        return ['profile' => BookStackDev::describe($row)] + self::listing($me, Access::workingSetOwner($me, $request));
    }

    /* -------------------------------------------------------------- helpers */

    /** @return array<string,mixed> */
    private static function listing(Actor $me, ?string $owner): array
    {
        return [
            'profiles' => array_map(
                static fn(array $row): array => BookStackDev::describe($row),
                BookStackDev::all($owner)
            ),
            // The instances a look can be put on: the caller's own, or the one
            // account an administrator asked to see.
            'instances' => BookStackDev::instancesOf($owner ?? $me->username),
        ];
    }

    private static function owner(Actor $actor, int $id): string
    {
        return (string)Access::look($actor, $id)['username'];
    }

    /** @return string[] */
    private static function strings(Request $request, string $key): array
    {
        $value = $request->all()[$key] ?? [];
        if (!is_array($value)) {
            throw HttpException::unprocessable('Field "' . $key . '" must be a list.');
        }
        return array_values(array_filter(array_map(
            static fn(mixed $v): string => is_scalar($v) ? trim((string)$v) : '',
            $value
        ), static fn(string $v): bool => $v !== ''));
    }
}
