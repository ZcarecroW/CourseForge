<?php
declare(strict_types=1);

namespace CourseForge\Domain;

use CourseForge\Support\Db;
use CourseForge\Support\HttpException;
use CourseForge\Support\Text;

/**
 * The user's tag library and its assignments to a course, chapter or page.
 *
 * A link may carry `inherit = 1`: tags inherited from the course reach every
 * chapter and page, tags inherited from a chapter reach that chapter's pages.
 * "own" means directly assigned, "effective" means own + inherited.
 *
 * Two more flags exist for AI generated tags:
 *   auto = 1     the link came from a {{Tag}} marker in the structure
 *   enabled = 0  the link is kept but ignored everywhere (prompt, push, hashes)
 */
final class Tags
{
    /* ------------------------------------------------------------ library */

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function shape(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'value' => (string)$row['value'],
            'usage_count' => (int)($row['usage_count'] ?? 0),
            'created_at' => (int)$row['created_at'],
            'updated_at' => (int)$row['updated_at'],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(string $username): array
    {
        $rows = Db::rows(
            'SELECT t.*, (SELECT COUNT(*) FROM tag_links l WHERE l.tag_id = t.id) AS usage_count
               FROM tags t WHERE t.username = ? ORDER BY t.name COLLATE NOCASE',
            [$username]
        );
        return array_map(static fn(array $row): array => self::shape($row), $rows);
    }

    /** @return array<string,mixed>|null */
    public static function find(string $username, int $id): ?array
    {
        $row = Db::row('SELECT * FROM tags WHERE username = ? AND id = ?', [$username, $id]);
        return $row === null ? null : self::shape($row);
    }

    /** @return array<string,mixed> */
    public static function require(string $username, int $id): array
    {
        return self::find($username, $id) ?? throw HttpException::notFound('Tag not found.');
    }

    /** @return array<string,mixed>|null */
    public static function byName(string $username, string $name): ?array
    {
        $row = Db::row('SELECT * FROM tags WHERE username = ? AND name = ? COLLATE NOCASE', [$username, Text::tidy($name)]);
        return $row === null ? null : self::shape($row);
    }

    /** @return array<string,mixed> */
    public static function create(string $username, string $name, string $value = ''): array
    {
        $name = Text::tidy($name);
        if ($name === '') {
            throw HttpException::unprocessable('A tag needs a name.');
        }
        if (self::byName($username, $name) !== null) {
            throw HttpException::unprocessable('A tag named "' . $name . '" already exists.');
        }
        $now = time();
        Db::run('INSERT INTO tags (username, name, value, created_at, updated_at) VALUES (?,?,?,?,?)',
            [$username, $name, trim($value), $now, $now]);
        return self::require($username, Db::lastId());
    }

    /** @return array<string,mixed> */
    public static function update(string $username, int $id, string $name, string $value): array
    {
        self::require($username, $id);
        $name = Text::tidy($name);
        if ($name === '') {
            throw HttpException::unprocessable('A tag needs a name.');
        }
        $clash = self::byName($username, $name);
        if ($clash !== null && (int)$clash['id'] !== $id) {
            throw HttpException::unprocessable('Another tag named "' . $name . '" already exists.');
        }
        Db::run('UPDATE tags SET name = ?, value = ?, updated_at = ? WHERE username = ? AND id = ?',
            [$name, trim($value), time(), $username, $id]);
        return self::require($username, $id);
    }

    public static function delete(string $username, int $id): void
    {
        self::require($username, $id);
        Db::run('DELETE FROM tag_links WHERE tag_id = ?', [$id]);
        Db::run('DELETE FROM tags WHERE username = ? AND id = ?', [$username, $id]);
    }

    /** Returns the tag with this name, creating it on the fly when needed. */
    public static function ensure(string $username, string $name, string $value = ''): array
    {
        $existing = self::byName($username, $name);
        if ($existing === null) {
            return self::create($username, $name, $value);
        }
        if (trim($value) !== '' && $existing['value'] !== trim($value)) {
            return self::update($username, (int)$existing['id'], (string)$existing['name'], $value);
        }
        return $existing;
    }

    /* -------------------------------------------------------------- links */

    /**
     * @param array<string,mixed> $project
     * @return array{0:string,1:int} [entity_type, entity_id]
     */
    public static function resolveEntity(array $project, string $target, ?int $targetId): array
    {
        $projectId = (int)$project['id'];
        $target = strtolower(trim($target));

        if ($target === '' || $target === 'course' || $target === 'project') {
            return ['project', $projectId];
        }
        if ($target === 'chapter') {
            Chapters::require($projectId, (int)$targetId);
            return ['chapter', (int)$targetId];
        }
        if ($target === 'page') {
            Pages::require($projectId, (int)$targetId);
            return ['page', (int)$targetId];
        }
        throw HttpException::unprocessable('Unknown target "' . $target . '" – expected course, chapter or page.');
    }

    /** @param array<string,mixed> $project */
    public static function attach(string $username, array $project, string $target, ?int $targetId, string $name, string $value, bool $inherit, bool $auto = false): void
    {
        [$type, $entityId] = self::resolveEntity($project, $target, $targetId);
        $tag = self::ensure($username, $name, $value);
        $inherit = $inherit && $type !== 'page'; // a page has no children

        $existing = Db::row('SELECT id FROM tag_links WHERE tag_id = ? AND entity_type = ? AND entity_id = ?',
            [$tag['id'], $type, $entityId]);

        if ($existing !== null) {
            // Attaching by hand promotes an AI link to a real one and re-enables it.
            Db::run('UPDATE tag_links SET inherit = ?, project_id = ?, auto = ?, enabled = 1 WHERE id = ?',
                [$inherit ? 1 : 0, (int)$project['id'], $auto ? 1 : 0, (int)$existing['id']]);
            return;
        }
        Db::run(
            'INSERT INTO tag_links (tag_id, project_id, entity_type, entity_id, inherit, auto, enabled) VALUES (?,?,?,?,?,?,1)',
            [$tag['id'], (int)$project['id'], $type, $entityId, $inherit ? 1 : 0, $auto ? 1 : 0]
        );
    }

    /** @param array<string,mixed> $project */
    public static function setInherit(string $username, array $project, string $target, ?int $targetId, int $tagId, bool $inherit): void
    {
        self::require($username, $tagId);
        [$type, $entityId] = self::resolveEntity($project, $target, $targetId);
        if ($type === 'page') {
            throw HttpException::unprocessable('A page has no children, so its tags cannot be inherited.');
        }
        self::updateLink('inherit', $inherit ? 1 : 0, $tagId, $type, $entityId);
    }

    /** Deactivate or reactivate one assignment – mainly used for AI tags. */
    public static function setEnabled(string $username, array $project, string $target, ?int $targetId, int $tagId, bool $enabled): void
    {
        self::require($username, $tagId);
        [$type, $entityId] = self::resolveEntity($project, $target, $targetId);
        self::updateLink('enabled', $enabled ? 1 : 0, $tagId, $type, $entityId);
    }

    /** @param array<string,mixed> $project */
    public static function detach(string $username, array $project, string $target, ?int $targetId, int $tagId): void
    {
        self::require($username, $tagId);
        [$type, $entityId] = self::resolveEntity($project, $target, $targetId);
        Db::run('DELETE FROM tag_links WHERE tag_id = ? AND entity_type = ? AND entity_id = ?', [$tagId, $type, $entityId]);
    }

    private static function updateLink(string $column, int $value, int $tagId, string $type, int $entityId): void
    {
        $stmt = Db::run(
            'UPDATE tag_links SET ' . $column . ' = ? WHERE tag_id = ? AND entity_type = ? AND entity_id = ?',
            [$value, $tagId, $type, $entityId]
        );
        if ($stmt->rowCount() === 0) {
            throw HttpException::unprocessable('This tag is not attached to that item.');
        }
    }

    /* ---------------------------------------------------------- AI markers */

    /**
     * Applies the tag markers parsed out of the structure Markdown.
     * Existing tags are reused, manual links stay manual, and a deactivated AI
     * link survives a regenerated structure.
     *
     * @param array<string,mixed> $project
     * @param array{project:array<int,string[]>,chapter:array<int,string[]>,page:array<int,string[]>} $map
     */
    public static function syncAuto(string $username, array $project, array $map): void
    {
        $projectId = (int)$project['id'];

        foreach (['project', 'chapter', 'page'] as $type) {
            foreach ((array)($map[$type] ?? []) as $entityId => $names) {
                $entityId = (int)$entityId;
                $keep = [];

                foreach ((array)$names as $name) {
                    $name = Text::tidy((string)$name);
                    if ($name === '') {
                        continue;
                    }
                    $tag = self::ensure($username, $name); // reuses an existing tag row
                    $keep[] = (int)$tag['id'];

                    $link = Db::row('SELECT id FROM tag_links WHERE tag_id = ? AND entity_type = ? AND entity_id = ?',
                        [$tag['id'], $type, $entityId]);
                    if ($link === null) {
                        Db::run(
                            'INSERT INTO tag_links (tag_id, project_id, entity_type, entity_id, inherit, auto, enabled) VALUES (?,?,?,?,0,1,1)',
                            [$tag['id'], $projectId, $type, $entityId]
                        );
                    } else {
                        // Never downgrade a manual link and never re-enable a disabled one.
                        Db::run('UPDATE tag_links SET project_id = ? WHERE id = ?', [$projectId, (int)$link['id']]);
                    }
                }

                if ($keep === []) {
                    Db::run('DELETE FROM tag_links WHERE project_id = ? AND entity_type = ? AND entity_id = ? AND auto = 1',
                        [$projectId, $type, $entityId]);
                    continue;
                }
                $placeholders = implode(',', array_fill(0, count($keep), '?'));
                Db::run(
                    "DELETE FROM tag_links WHERE project_id = ? AND entity_type = ? AND entity_id = ?
                       AND auto = 1 AND tag_id NOT IN ({$placeholders})",
                    [$projectId, $type, $entityId, ...$keep]
                );
            }
        }
    }

    /**
     * AI-created tag names per entity – used to re-render the structure
     * Markdown without losing the markers.
     *
     * @return array{project:array<int,string[]>,chapter:array<int,string[]>,page:array<int,string[]>}
     */
    public static function autoNames(int $projectId): array
    {
        $out = ['project' => [], 'chapter' => [], 'page' => []];
        $rows = Db::rows(
            'SELECT l.entity_type, l.entity_id, t.name
               FROM tag_links l JOIN tags t ON t.id = l.tag_id
              WHERE l.project_id = ? AND l.auto = 1 ORDER BY t.name COLLATE NOCASE',
            [$projectId]
        );
        foreach ($rows as $row) {
            $type = (string)$row['entity_type'];
            if (isset($out[$type])) {
                $out[$type][(int)$row['entity_id']][] = (string)$row['name'];
            }
        }
        return $out;
    }

    /** Drops links whose chapter or page was removed by a structure change. */
    public static function prune(int $projectId): void
    {
        Db::run(
            "DELETE FROM tag_links WHERE project_id = ? AND entity_type = 'chapter'
                AND entity_id NOT IN (SELECT id FROM chapters WHERE project_id = ?)",
            [$projectId, $projectId]
        );
        Db::run(
            "DELETE FROM tag_links WHERE project_id = ? AND entity_type = 'page'
                AND entity_id NOT IN (SELECT id FROM pages WHERE project_id = ?)",
            [$projectId, $projectId]
        );
    }

    /* ----------------------------------------------------------- resolving */

    /**
     * @return array{own:array<string,array<int,array<int,array<string,mixed>>>>,
     *               effective:array<string,array<int,array<int,array<string,mixed>>>>}
     */
    public static function resolved(int $projectId): array
    {
        $rows = Db::rows(
            'SELECT l.entity_type, l.entity_id, l.inherit, l.auto, l.enabled, t.id, t.name, t.value
               FROM tag_links l JOIN tags t ON t.id = l.tag_id
              WHERE l.project_id = ? ORDER BY t.name COLLATE NOCASE',
            [$projectId]
        );

        $own = ['project' => [], 'chapter' => [], 'page' => []];
        foreach ($rows as $row) {
            $type = (string)$row['entity_type'];
            if (!array_key_exists($type, $own)) {
                continue;
            }
            $own[$type][(int)$row['entity_id']][] = [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'value' => (string)$row['value'],
                'inherit' => (int)$row['inherit'] === 1,
                'auto' => (int)$row['auto'] === 1,
                'enabled' => (int)$row['enabled'] === 1,
            ];
        }

        // A deactivated link never reaches a prompt, a hash or BookStack.
        $active = static fn(array $list): array => array_values(
            array_filter($list, static fn(array $t): bool => $t['enabled'])
        );
        $inheritable = static fn(array $list): array => array_values(
            array_filter($list, static fn(array $t): bool => $t['inherit'] && $t['enabled'])
        );

        $fromCourse = $inheritable($own['project'][$projectId] ?? []);
        $effective = [
            'project' => [$projectId => $active($own['project'][$projectId] ?? [])],
            'chapter' => [],
            'page' => [],
        ];

        foreach (Db::rows('SELECT id FROM chapters WHERE project_id = ?', [$projectId]) as $chapter) {
            $id = (int)$chapter['id'];
            $effective['chapter'][$id] = self::merge($fromCourse, $active($own['chapter'][$id] ?? []));
        }
        foreach (Db::rows('SELECT id, chapter_id FROM pages WHERE project_id = ?', [$projectId]) as $page) {
            $id = (int)$page['id'];
            $effective['page'][$id] = self::merge(
                $fromCourse,
                $inheritable($own['chapter'][(int)$page['chapter_id']] ?? []),
                $active($own['page'][$id] ?? [])
            );
        }

        return ['own' => $own, 'effective' => $effective];
    }

    /** @return array<int,array<string,mixed>> */
    private static function merge(array ...$lists): array
    {
        $out = [];
        foreach ($lists as $list) {
            foreach ($list as $tag) {
                $out[mb_strtolower((string)$tag['name'])] = $tag;
            }
        }
        ksort($out);
        return array_values($out);
    }

    /** Stable fingerprint so a tag change marks the item as out of sync. */
    public static function signature(array $tags): string
    {
        $parts = array_map(static fn(array $t): string => $t['name'] . '=' . (string)($t['value'] ?? ''), $tags);
        sort($parts, SORT_STRING);
        return implode('|', $parts);
    }

    /** BookStack API shape. */
    public static function apiPayload(array $tags): array
    {
        return array_map(
            static fn(array $tag): array => ['name' => (string)$tag['name'], 'value' => (string)($tag['value'] ?? '')],
            $tags
        );
    }

    /** Comma separated names – handy as a prompt variable. */
    public static function names(array $tags): string
    {
        return implode(', ', array_map(static fn(array $t): string => (string)$t['name'], $tags));
    }
}
