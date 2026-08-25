<?php
declare(strict_types=1);

namespace CourseForge\Domain;

use CourseForge\Support\Db;
use CourseForge\Support\HttpException;

/** Chapter rows – ownership is always checked through the owning project. */
final class Chapters
{
    private const WRITABLE = ['idx', 'title', 'description', 'settings', 'bs_id', 'bs_slug', 'bs_url', 'pushed_hash'];

    /** @return array<string,mixed>|null */
    public static function find(int $projectId, int $id): ?array
    {
        return Db::row('SELECT * FROM chapters WHERE project_id = ? AND id = ?', [$projectId, $id]);
    }

    /** @return array<string,mixed> */
    public static function require(int $projectId, int $id): array
    {
        return self::find($projectId, $id) ?? throw HttpException::notFound('Chapter not found.');
    }

    /** @return array<int,array<string,mixed>> */
    public static function ordered(int $projectId): array
    {
        return Db::rows('SELECT * FROM chapters WHERE project_id = ? ORDER BY idx', [$projectId]);
    }

    /** @param array<string,mixed> $fields */
    public static function update(int $id, array $fields): void
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
        $args[] = $id;
        Db::run('UPDATE chapters SET ' . implode(', ', $set) . ' WHERE id = ?', $args);
    }

    /** @return array{features:array<string,int>,params:array<string,int|string>} */
    public static function settings(array $chapter): array
    {
        return Details::decode((string)($chapter['settings'] ?? '{}'));
    }

    /** @param array<string,mixed> $features @param array<string,mixed> $params */
    public static function patchDetails(int $projectId, int $id, array $features, array $params): void
    {
        // The read and the write are one transaction. They used to be two
        // separate statements over a whole JSON column, so two overlapping
        // toggles both started from the same stored document and the second
        // wrote the first one away - silently, with a 200 each. SQLite
        // serialises writers, so inside a transaction the second read sees
        // what the first committed.
        Db::transaction(static function () use ($projectId, $id, $features, $params): void {
            $chapter = self::require($projectId, $id);
            $patched = Details::patch(self::settings($chapter), $features, $params);
            self::update($id, ['settings' => Details::encode($patched)]);
        });
    }
}
