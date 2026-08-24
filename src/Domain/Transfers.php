<?php
declare(strict_types=1);

namespace CourseForge\Domain;

use CourseForge\Security\Users;
use CourseForge\Support\Db;
use CourseForge\Support\HttpException;

/**
 * Handing a course to another account.
 *
 * Moving a course is not one `UPDATE`. A course sits at the centre of four
 * things that are all owned per account, and three of them break quietly if
 * only the course moves:
 *
 *   - **the profile** carries an API key and belongs to the old owner, so it is
 *     cleared rather than shared. The new owner chooses one of their own.
 *   - **the run history** is stored with the owner's name on it, and
 *     `Runs::forProject()` looks it up by that name - so a course whose runs
 *     stayed behind appears to the new owner never to have been generated.
 *   - **the tag links** point at the old owner's library, and `Tags::require()`
 *     filters by owner - so the new owner gets "Tag not found" on their own
 *     course. Each linked tag is matched by name in the receiving library, or
 *     created there, and the links are re-pointed.
 *   - **the published book** is untouched, because it lives in BookStack and
 *     CourseForge does not own it. Publishing again needs a profile with a
 *     BookStack instance of the same name.
 *
 * This exists as its own class because two front doors do it - the HTTP route
 * and the MCP tool - and a transfer that is complete on one and partial on the
 * other is exactly the kind of difference nobody finds until the data is wrong.
 */
final class Transfers
{
    /**
     * Moves one course, with everything that has to move with it.
     *
     * @return array{from:string,to:string,runs:int,tags:int,tags_created:int,cleared_profile:bool,value_changes:string[],notes:string[]}
     */
    public static function course(int $projectId, string $to): array
    {
        $project = Db::row('SELECT * FROM projects WHERE id = ?', [$projectId]);
        if ($project === null) {
            throw HttpException::notFound('Course not found.');
        }
        $from = (string)$project['username'];

        $target = Users::require($to);
        $to = (string)$target['username'];

        if (strcasecmp($from, $to) === 0) {
            throw HttpException::unprocessable('This course already belongs to ' . $to . '.');
        }
        if ((int)$target['disabled'] === 1) {
            throw HttpException::unprocessable('The account "' . $to . '" is disabled and cannot own a course.');
        }

        // A run in flight is billed to the old owner's AI account and cannot be
        // handed over with the course, so the transfer waits for it.
        foreach (Runs::open($from) as $run) {
            if ((int)$run['project_id'] === $projectId) {
                throw HttpException::unprocessable(
                    'This course has a generation run in progress. Let it finish or stop it first - a run in '
                    . 'flight is billed to ' . $from . "'s AI account and cannot be handed over with the course."
                );
            }
        }

        $hadProfile = $project['profile_id'] !== null;

        $moved = Db::transaction(static function () use ($projectId, $to): array {
            Db::run(
                'UPDATE projects SET username = ?, profile_id = NULL, updated_at = ? WHERE id = ?',
                [$to, time(), $projectId]
            );

            $runs = Db::run('UPDATE batch_jobs SET username = ? WHERE project_id = ?', [$to, $projectId])->rowCount();

            $tags = 0;
            $created = 0;
            $linked = Db::rows(
                'SELECT DISTINCT t.id, t.name, t.value
                   FROM tag_links l JOIN tags t ON t.id = l.tag_id
                  WHERE l.project_id = ?',
                [$projectId]
            );
            $valueChanges = [];
            foreach ($linked as $tag) {
                $name = (string)$tag['name'];
                $value = (string)$tag['value'];
                $existing = Tags::byName($to, $name);
                if ($existing === null) {
                    $existing = Tags::create($to, $name, $value);
                    $created++;
                } elseif ((string)$existing['value'] !== $value) {
                    // Matching by name alone would quietly change what the tag
                    // SAYS. The value is part of the hash that decides whether a
                    // page is in sync with BookStack, so adopting the receiving
                    // library's value marks the whole course dirty and the next
                    // push rewrites the published tag. The link moves; what it
                    // meant is reported rather than silently replaced.
                    $valueChanges[] = $name . ': "' . $value . '" becomes "' . (string)$existing['value'] . '"';
                }
                $tags++;

                // OR IGNORE covers the case the unique index would reject: a
                // link the receiving tag already has on the same item.
                Db::run(
                    'UPDATE OR IGNORE tag_links SET tag_id = ? WHERE tag_id = ? AND project_id = ?',
                    [(int)$existing['id'], (int)$tag['id'], $projectId]
                );
                // Anything the update refused is a duplicate of a link that now
                // exists under the new tag, so the old row has no work left.
                Db::run('DELETE FROM tag_links WHERE tag_id = ? AND project_id = ?', [(int)$tag['id'], $projectId]);
            }

            return ['runs' => $runs, 'tags' => $tags, 'tags_created' => $created, 'value_changes' => $valueChanges];
        });

        $notes = [];
        if ($hadProfile) {
            $notes[] = 'The profile was cleared - it belonged to ' . $from . ', and a profile carries an API key. '
                . $to . ' has to choose one of their own before this course can generate anything.';
        }
        if ($moved['tags'] > 0) {
            $notes[] = $moved['tags'] . ' tag(s) were re-pointed at ' . $to . "'s library, "
                . $moved['tags_created'] . ' of which had to be created there.';
        }
        if ($moved['value_changes'] !== []) {
            $notes[] = 'These tags already existed in ' . $to . "'s library with a different value, so what they "
                . 'say on this course has changed and the pages carrying them are now out of sync with BookStack: '
                . implode('; ', $moved['value_changes']) . '.';
        }
        if ((string)$project['bs_instance_id'] !== '') {
            $notes[] = 'Publishing needs a profile with a BookStack instance called "'
                . (string)$project['bs_instance_id'] . '"; the book itself is untouched.';
        }

        return [
            'from' => $from,
            'to' => $to,
            'runs' => $moved['runs'],
            'tags' => $moved['tags'],
            'tags_created' => $moved['tags_created'],
            'value_changes' => $moved['value_changes'],
            'cleared_profile' => $hadProfile,
            'notes' => $notes,
        ];
    }
}
