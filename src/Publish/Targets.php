<?php
declare(strict_types=1);

namespace CourseForge\Publish;

use CourseForge\Domain\Profiles;
use CourseForge\Support\Db;
use CourseForge\Support\HttpException;

/**
 * Where a course publishes: one row per BookStack instance it is pushed to.
 *
 * A course used to have a single destination, kept in `bs_instance_id` on the
 * course itself together with the book that push had created. A course now has
 * a list, and each entry owns its own book - because two wikis hold two books,
 * with different ids, different slugs and different URLs, and a page published
 * to both is not even the same text in both: its cross references point at the
 * wiki it is in.
 *
 * The old columns are still written, as a mirror of the first target. That is
 * what keeps everything reading them correct - the course list, the sync
 * badges, the link index the editor previews with - and it is the one direction
 * data flows here: targets are the truth, the columns are a copy. Nothing
 * outside this class and the publisher writes them.
 */
final class Targets
{
    /** Fields a caller may set on a target row. */
    private const WRITABLE = [
        'instance_id', 'idx', 'enabled', 'shelf_id', 'shelf_name',
        'book_id', 'book_slug', 'book_url', 'pushed_hash',
    ];

    /* --------------------------------------------------------------- reads */

    /** @return array<int,array<string,mixed>> */
    public static function all(int $projectId): array
    {
        return Db::rows('SELECT * FROM publish_targets WHERE project_id = ? ORDER BY idx, id', [$projectId]);
    }

    /** The targets a push covers when nothing narrower is asked for.
     *  @return array<int,array<string,mixed>> */
    public static function enabled(int $projectId): array
    {
        return array_values(array_filter(self::all($projectId), static fn(array $t): bool => (int)$t['enabled'] === 1));
    }

    /**
     * The target the course's own columns mirror.
     *
     * The first enabled one. A course that has turned every destination off has
     * still been published to them and the badges should go on saying so, so
     * the fall-back is the first one holding a book - and only if none of them
     * does, the first one at all. Picking a never-published row over one with a
     * book would blank the whole legacy signal because somebody paused a
     * destination, which is the one thing the fall-back exists to prevent.
     *
     * @return array<string,mixed>|null
     */
    public static function primary(int $projectId): ?array
    {
        $all = self::all($projectId);
        foreach ($all as $target) {
            if ((int)$target['enabled'] === 1) {
                return $target;
            }
        }
        foreach ($all as $target) {
            if ($target['book_id'] !== null) {
                return $target;
            }
        }
        return $all[0] ?? null;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $projectId, int $targetId): ?array
    {
        return Db::row('SELECT * FROM publish_targets WHERE project_id = ? AND id = ?', [$projectId, $targetId]);
    }

    /** @return array<string,mixed> */
    public static function require(int $projectId, int $targetId): array
    {
        return self::find($projectId, $targetId)
            ?? throw HttpException::notFound('This course has no publishing target #' . $targetId . '.');
    }

    /** @return array<string,mixed>|null */
    public static function byInstance(int $projectId, string $instanceId): ?array
    {
        return Db::row(
            'SELECT * FROM publish_targets WHERE project_id = ? AND instance_id = ?',
            [$projectId, $instanceId]
        );
    }

    /**
     * The named targets of one course, or every enabled one.
     *
     * A push may be aimed at a subset - one wiki of three, because that is the
     * one that was behind - and this is where an id nobody owns is refused
     * rather than silently skipped.
     *
     * @param array<int,int>|null $targetIds
     * @return array<int,array<string,mixed>>
     */
    public static function selected(int $projectId, ?array $targetIds): array
    {
        if ($targetIds === null || $targetIds === []) {
            return self::enabled($projectId);
        }

        $chosen = [];
        foreach (array_values(array_unique(array_map('intval', $targetIds))) as $id) {
            $chosen[] = self::require($projectId, $id);
        }
        return $chosen;
    }

    /* -------------------------------------------------------------- writes */

    /** @param array<string,mixed> $fields */
    public static function update(int $targetId, array $fields): void
    {
        $set = [];
        $args = [];
        foreach ($fields as $key => $value) {
            if (in_array($key, self::WRITABLE, true)) {
                $set[] = $key . ' = ?';
                $args[] = $value;
            }
        }
        if ($set === []) {
            return;
        }
        $set[] = 'updated_at = ?';
        $args[] = time();
        $args[] = $targetId;
        Db::run('UPDATE publish_targets SET ' . implode(', ', $set) . ' WHERE id = ?', $args);
    }

    /**
     * The whole list, written in one go.
     *
     * Matching is by instance id, so a target that survives the edit keeps the
     * book it created and everything published into it; a target the list no
     * longer names is deleted, and its record of what it published goes with
     * it. The book itself stays in BookStack - CourseForge only forgets that it
     * made it, which is why the callers say so out loud before doing this.
     *
     * @param array<int,array<string,mixed>> $incoming each with instance_id, and optionally shelf_id, shelf_name, enabled
     * @return array<int,array<string,mixed>> the stored list afterwards
     */
    public static function replaceAll(string $username, int $projectId, array $incoming): array
    {
        $clean = [];
        foreach ($incoming as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $instanceId = trim((string)($entry['instance_id'] ?? $entry['instance'] ?? ''));
            if ($instanceId === '' || isset($clean[$instanceId])) {
                continue; // an empty id is not a destination, and a repeat is the same one twice
            }
            // BookStack shelf ids start at one, so anything at or below zero is
            // an empty box or a client's placeholder rather than a shelf.
            $shelfId = is_numeric($entry['shelf_id'] ?? null) ? (int)$entry['shelf_id'] : 0;
            $clean[$instanceId] = [
                'instance_id' => $instanceId,
                'shelf_id' => $shelfId > 0 ? $shelfId : null,
                'shelf_name' => trim((string)($entry['shelf_name'] ?? '')),
                'enabled' => array_key_exists('enabled', $entry)
                    ? (filter_var($entry['enabled'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0)
                    : 1,
            ];
        }

        // The mirror is inside the transaction with the rows it copies. Two
        // browsers saving at once would otherwise be able to interleave a write
        // and a copy, and leave the course's own columns describing a
        // destination list that no longer exists.
        Db::transaction(static function () use ($username, $projectId, $clean): void {
            $existing = [];
            foreach (self::all($projectId) as $target) {
                $existing[(string)$target['instance_id']] = $target;
            }

            $now = time();
            $idx = 0;
            foreach ($clean as $instanceId => $entry) {
                if (isset($existing[$instanceId])) {
                    self::update((int)$existing[$instanceId]['id'], [
                        'idx' => $idx,
                        'enabled' => $entry['enabled'],
                        'shelf_id' => $entry['shelf_id'],
                        'shelf_name' => $entry['shelf_name'],
                    ]);
                } else {
                    Db::run(
                        'INSERT INTO publish_targets
                             (project_id, instance_id, idx, enabled, shelf_id, shelf_name, created_at, updated_at)
                         VALUES (?,?,?,?,?,?,?,?)',
                        [$projectId, $instanceId, $idx, $entry['enabled'], $entry['shelf_id'], $entry['shelf_name'], $now, $now]
                    );
                }
                $idx++;
            }

            foreach ($existing as $instanceId => $target) {
                if (!isset($clean[$instanceId])) {
                    Db::run('DELETE FROM publish_targets WHERE id = ?', [(int)$target['id']]);
                }
            }

            self::mirror($username, $projectId);
        });

        return self::all($projectId);
    }

    /**
     * Replaces the course's first destination with the one named.
     *
     * This is what the single-destination doors still open onto: the course
     * settings form, and `update_course`'s `bookstack_instance`. Both of them
     * mean "this is where the course publishes", and in 4.6 that was the whole
     * sentence - so naming a different instance has to retire the one it
     * replaces, or a client switching from A to B would find itself publishing
     * to A and B and never be told. Everything after the first is left exactly
     * as it was; a caller that wants to add a destination rather than change
     * one has replaceAll(), which is what the list editor and
     * `set_publish_targets` use.
     *
     * The named instance is switched on, for the same reason: "this is where
     * the course publishes" is not a sentence that can leave a destination
     * paused.
     *
     * `$shelf` carries only the keys the caller actually named. That matters:
     * "no shelf" and "I did not mention the shelf" are different instructions,
     * and a form that changes the instance alone must not quietly take the book
     * off the shelf it was on.
     *
     * @param array{shelf_id?:int|null,shelf_name?:string} $shelf
     * @return array<int,array<string,mixed>> the stored list afterwards
     */
    public static function setPrimary(string $username, int $projectId, string $instanceId, array $shelf = []): array
    {
        $instanceId = trim($instanceId);
        $current = self::primary($projectId);

        if ($instanceId === '') {
            return self::clearPrimary($username, $projectId, $current);
        }

        $existing = self::byInstance($projectId, $instanceId);
        $storedShelfId = ($existing === null || $existing['shelf_id'] === null) ? null : (int)$existing['shelf_id'];

        $list = [[
            'instance_id' => $instanceId,
            'shelf_id' => array_key_exists('shelf_id', $shelf) ? $shelf['shelf_id'] : $storedShelfId,
            'shelf_name' => array_key_exists('shelf_name', $shelf)
                ? (string)$shelf['shelf_name']
                : (string)($existing['shelf_name'] ?? ''),
            'enabled' => 1,
        ]];

        foreach (self::all($projectId) as $target) {
            if ((string)$target['instance_id'] === $instanceId) {
                continue; // it is the one now at the front
            }
            // The destination being replaced. Naming a different instance means
            // the course does not publish to this one any more.
            if ($current !== null && (int)$target['id'] === (int)$current['id']) {
                continue;
            }
            $list[] = [
                'instance_id' => (string)$target['instance_id'],
                'shelf_id' => $target['shelf_id'] === null ? null : (int)$target['shelf_id'],
                'shelf_name' => (string)$target['shelf_name'],
                'enabled' => (int)$target['enabled'],
            ];
        }

        return self::replaceAll($username, $projectId, $list);
    }

    /**
     * What an emptied `bs_instance_id` does.
     *
     * In 4.6 it meant "this course publishes nowhere for now": the course kept
     * its `book_id`, and filling the field back in carried on with the same
     * book. Removing the destination outright would keep that promise on the
     * surface and break it underneath - the record of the book would be gone,
     * and the next push would make a second one beside it.
     *
     * So a destination that has published something is refused, loudly, and
     * pointed at the door that is allowed to forget it. One that has never
     * published anything is removed, because there is nothing to lose and
     * leaving an empty row behind would make the field un-clearable.
     *
     * @param array<string,mixed>|null $current
     * @return array<int,array<string,mixed>>
     */
    private static function clearPrimary(string $username, int $projectId, ?array $current): array
    {
        if ($current === null) {
            return self::all($projectId);
        }
        if ($current['book_id'] !== null) {
            throw HttpException::unprocessable(
                'This course has published a book to "' . (string)$current['instance_id'] . '", so clearing the '
                . 'instance here would lose the record of it and the next push there would make a second book. '
                . 'Edit the list of destinations instead - the Publish tab in the browser, set_publish_targets over '
                . 'MCP - which says what removing one costs before it does it.'
            );
        }

        $list = [];
        foreach (self::all($projectId) as $target) {
            if ((int)$target['id'] === (int)$current['id']) {
                continue;
            }
            $list[] = [
                'instance_id' => (string)$target['instance_id'],
                'shelf_id' => $target['shelf_id'] === null ? null : (int)$target['shelf_id'],
                'shelf_name' => (string)$target['shelf_name'],
                'enabled' => (int)$target['enabled'],
            ];
        }
        return self::replaceAll($username, $projectId, $list);
    }

    /* --------------------------------------------------------------- items */

    /**
     * What one target has published, keyed by chapter id and by page id.
     *
     * One query rather than one per item: the publisher walks a whole course
     * and the tree draws one, and both want the answer for everything at once.
     *
     * @return array{chapter:array<int,array<string,mixed>>,page:array<int,array<string,mixed>>}
     */
    public static function items(int $targetId): array
    {
        $out = ['chapter' => [], 'page' => []];
        foreach (Db::rows('SELECT * FROM publish_items WHERE target_id = ?', [$targetId]) as $row) {
            if ($row['chapter_id'] !== null) {
                $out['chapter'][(int)$row['chapter_id']] = $row;
            } elseif ($row['page_id'] !== null) {
                $out['page'][(int)$row['page_id']] = $row;
            }
        }
        return $out;
    }

    /**
     * @param 'chapter'|'page' $type
     * @return array<string,mixed>|null
     */
    public static function item(int $targetId, string $type, int $entityId): ?array
    {
        $column = $type === 'chapter' ? 'chapter_id' : 'page_id';
        return Db::row('SELECT * FROM publish_items WHERE target_id = ? AND ' . $column . ' = ?', [$targetId, $entityId]);
    }

    /**
     * Writes what a push made of one chapter or one page in one wiki.
     *
     * @param 'chapter'|'page' $type
     * @param array{bs_id?:int|null,bs_slug?:string,bs_url?:string,pushed_hash?:string} $fields
     */
    public static function saveItem(int $targetId, string $type, int $entityId, array $fields): void
    {
        // Read and write together: two pushes of the same course at once would
        // otherwise both find no row and both insert one, and the second would
        // be refused by the unique index in the middle of somebody's publish.
        Db::transaction(static function () use ($targetId, $type, $entityId, $fields): void {
            self::writeItem($targetId, $type, $entityId, $fields);
        });
    }

    /**
     * @param 'chapter'|'page' $type
     * @param array{bs_id?:int|null,bs_slug?:string,bs_url?:string,pushed_hash?:string} $fields
     */
    private static function writeItem(int $targetId, string $type, int $entityId, array $fields): void
    {
        $column = $type === 'chapter' ? 'chapter_id' : 'page_id';
        $existing = self::item($targetId, $type, $entityId);

        if ($existing === null) {
            Db::run(
                'INSERT INTO publish_items (target_id, ' . $column . ', bs_id, bs_slug, bs_url, pushed_hash) VALUES (?,?,?,?,?,?)',
                [
                    $targetId,
                    $entityId,
                    $fields['bs_id'] ?? null,
                    (string)($fields['bs_slug'] ?? ''),
                    (string)($fields['bs_url'] ?? ''),
                    (string)($fields['pushed_hash'] ?? ''),
                ]
            );
            return;
        }

        $set = [];
        $args = [];
        foreach (['bs_id', 'bs_slug', 'bs_url', 'pushed_hash'] as $key) {
            if (array_key_exists($key, $fields)) {
                $set[] = $key . ' = ?';
                $args[] = $fields[$key];
            }
        }
        if ($set === []) {
            return;
        }
        $args[] = (int)$existing['id'];
        Db::run('UPDATE publish_items SET ' . implode(', ', $set) . ' WHERE id = ?', $args);
    }

    /* -------------------------------------------------------------- mirror */

    /**
     * Copies the first target back onto the columns the course, its chapters
     * and its pages have always carried.
     *
     * Everything that reads a published id, slug or URL without knowing about
     * targets reads those columns, and there are a lot of them: the course
     * list, the page badges, the link index the editor previews with, the
     * transfer notes. Rather than teaching each of them to pick a target, the
     * first one is written where they already look.
     *
     * A course with no target at all has its columns cleared, which is the
     * honest answer: there is nowhere it publishes, so there is no book.
     */
    public static function mirror(string $username, int $projectId): void
    {
        $target = self::primary($projectId);

        if ($target === null) {
            Db::run(
                "UPDATE projects SET bs_instance_id = '', shelf_id = NULL, shelf_name = '',
                        book_id = NULL, book_slug = '', book_url = '', pushed_hash = ''
                  WHERE username = ? AND id = ?",
                [$username, $projectId]
            );
            Db::run("UPDATE chapters SET bs_id = NULL, bs_slug = '', bs_url = '', pushed_hash = '' WHERE project_id = ?", [$projectId]);
            Db::run("UPDATE pages    SET bs_id = NULL, bs_slug = '', bs_url = '', pushed_hash = '' WHERE project_id = ?", [$projectId]);
            return;
        }

        Db::run(
            'UPDATE projects SET bs_instance_id = ?, shelf_id = ?, shelf_name = ?,
                    book_id = ?, book_slug = ?, book_url = ?, pushed_hash = ?
              WHERE username = ? AND id = ?',
            [
                (string)$target['instance_id'],
                $target['shelf_id'] === null ? null : (int)$target['shelf_id'],
                (string)$target['shelf_name'],
                $target['book_id'] === null ? null : (int)$target['book_id'],
                (string)$target['book_slug'],
                (string)$target['book_url'],
                (string)$target['pushed_hash'],
                $username,
                $projectId,
            ]
        );

        // Correlated subqueries rather than UPDATE ... FROM: the latter wants
        // SQLite 3.33, and CourseForge runs on whatever the host ships.
        foreach ([['chapters', 'chapter_id'], ['pages', 'page_id']] as [$table, $column]) {
            Db::run(
                "UPDATE {$table} SET
                     bs_id       = (SELECT i.bs_id FROM publish_items i WHERE i.target_id = ? AND i.{$column} = {$table}.id),
                     bs_slug     = COALESCE((SELECT i.bs_slug     FROM publish_items i WHERE i.target_id = ? AND i.{$column} = {$table}.id), ''),
                     bs_url      = COALESCE((SELECT i.bs_url      FROM publish_items i WHERE i.target_id = ? AND i.{$column} = {$table}.id), ''),
                     pushed_hash = COALESCE((SELECT i.pushed_hash FROM publish_items i WHERE i.target_id = ? AND i.{$column} = {$table}.id), '')
                  WHERE project_id = ?",
                [(int)$target['id'], (int)$target['id'], (int)$target['id'], (int)$target['id'], $projectId]
            );
        }
    }

    /* -------------------------------------------------------------- naming */

    /**
     * The instances one profile defines, keyed by id, with only the two fields
     * that are safe to show: what it is called and where it is.
     *
     * @return array<string,array{name:string,base_url:string}>
     */
    public static function instancesOf(string $username, ?int $profileId): array
    {
        if ($profileId === null) {
            return [];
        }
        $profile = Profiles::find($username, $profileId);
        if ($profile === null) {
            return [];
        }

        $out = [];
        foreach ((array)($profile['data']['bookstack'] ?? []) as $instance) {
            $id = (string)($instance['id'] ?? '');
            if ($id !== '') {
                $out[$id] = [
                    'name' => (string)($instance['name'] ?? 'BookStack'),
                    'base_url' => (string)($instance['base_url'] ?? ''),
                ];
            }
        }
        return $out;
    }

    /**
     * One target, described the way every surface wants to describe it: named
     * rather than numbered, and without a credential in sight.
     *
     * @param array<string,mixed> $target
     * @param array<string,array{name:string,base_url:string}> $instances
     * @return array<string,mixed>
     */
    public static function describe(array $target, array $instances): array
    {
        $instanceId = (string)$target['instance_id'];
        $known = $instances[$instanceId] ?? null;

        return [
            'id' => (int)$target['id'],
            'instance_id' => $instanceId,
            // A profile that no longer defines the instance leaves the target
            // pointing at nothing. Saying so is more use than a blank name.
            'instance_name' => $known === null ? '' : $known['name'],
            'base_url' => $known === null ? '' : $known['base_url'],
            'known' => $known !== null,
            'idx' => (int)$target['idx'],
            'enabled' => (int)$target['enabled'] === 1,
            'shelf_id' => $target['shelf_id'] === null ? null : (int)$target['shelf_id'],
            'shelf_name' => (string)$target['shelf_name'],
            'book_id' => $target['book_id'] === null ? null : (int)$target['book_id'],
            'book_slug' => (string)$target['book_slug'],
            'book_url' => (string)$target['book_url'],
            'pushed_hash' => (string)$target['pushed_hash'],
        ];
    }
}
