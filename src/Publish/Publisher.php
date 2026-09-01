<?php
declare(strict_types=1);

namespace CourseForge\Publish;

use CourseForge\Domain\Profiles;
use CourseForge\Domain\Projects;
use CourseForge\Support\HttpException;
use CourseForge\Support\Runtime;
use Throwable;

/**
 * Publishes a course into every BookStack instance it points at.
 *
 * A course used to have one destination. It now has a list, and this class is
 * the fan-out: it opens one TargetPublisher per target, runs them in turn, and
 * puts the results back together into the single log and the single set of
 * counts that the Publish tab, the API and the MCP tools have always been
 * handed.
 *
 * Each target is independent. A wiki that is down, or whose token has been
 * revoked, does not stop the others: its failure is recorded, named, and put in
 * the log where the person who asked for the push can see it, and the push
 * carries on to the next one. Only when every target fails is the first error
 * raised - which is exactly what a course with a single destination did before
 * there were several, so nothing about a one-wiki installation changed.
 *
 * The mirror onto the course's own columns runs once at the end. It is what
 * keeps every reader that predates targets - the course list, the sync badges,
 * the link index - answering about the first target rather than about nothing.
 */
final class Publisher
{
    /**
     * @param array<string,mixed> $project
     * @param array<int,array<string,mixed>> $targets
     * @param array<string,array{name:string,base_url:string}> $instances
     */
    private function __construct(
        private readonly string $username,
        private readonly array $project,
        private readonly array $targets,
        private readonly array $instances,
    ) {
    }

    /**
     * @param array<int,int>|null $targetIds the publish_targets ids to push to,
     *        or null for every target that is switched on
     */
    public static function open(string $username, int $projectId, ?array $targetIds = null): self
    {
        $project = Projects::require($username, $projectId);

        if ($project['profile_id'] === null) {
            throw HttpException::unprocessable('This course has no profile assigned.');
        }

        $targets = Targets::selected($projectId, $targetIds);
        if ($targets === []) {
            throw HttpException::unprocessable(
                Targets::all($projectId) === []
                    ? 'Choose a BookStack instance for this course first.'
                    : 'Every BookStack target of this course is switched off, so there is nowhere to publish it.'
            );
        }

        // A destination that is switched off is not pushed to, even when it is
        // asked for by name. Quietly publishing into a wiki somebody has turned
        // off is the one outcome the switch exists to prevent, and quietly
        // skipping it would report a push that did not happen - so this is
        // refused rather than resolved either way.
        $off = array_values(array_filter($targets, static fn(array $t): bool => (int)$t['enabled'] !== 1));
        if ($targetIds !== null && $targetIds !== [] && $off !== []) {
            throw HttpException::unprocessable(
                'The BookStack ' . (count($off) === 1 ? 'instance' : 'instances') . ' '
                . implode(', ', array_map(static fn(array $t): string => '"' . (string)$t['instance_id'] . '"', $off))
                . ' ' . (count($off) === 1 ? 'is' : 'are') . ' switched off for this course, so nothing was '
                . 'published. Switch ' . (count($off) === 1 ? 'it' : 'them') . ' on first, or leave '
                . (count($off) === 1 ? 'it' : 'them') . ' out of the push.'
            );
        }

        return new self($username, $project, $targets, Targets::instancesOf($username, (int)$project['profile_id']));
    }

    /** How many wikis this push covers. */
    public function count(): int
    {
        return count($this->targets);
    }

    /**
     * @param string $scope all | book | chapter | page
     * @return array{log:string[],links:array{resolved:int,pending:int,updated:int},targets:array<int,array<string,mixed>>,failed:int}
     */
    public function push(string $scope = 'all', ?int $itemId = null, bool $force = false): array
    {
        return $this->fanOut(
            static fn(TargetPublisher $publisher): array => $publisher->push($scope, $itemId, $force)
        );
    }

    /**
     * Resolves auto links on their own, without re-publishing anything else.
     *
     * @return array{log:string[],links:array{resolved:int,pending:int,updated:int},targets:array<int,array<string,mixed>>,failed:int}
     */
    public function resolveLinks(bool $force = false): array
    {
        return $this->fanOut(
            static fn(TargetPublisher $publisher): array => $publisher->resolveLinks($force)
        );
    }

    /* ----------------------------------------------------------- internals */

    /**
     * Runs one piece of work against every target and puts the answers together.
     *
     * @param callable(TargetPublisher):array{log:string[],links:array{resolved:int,pending:int,updated:int}} $work
     * @return array{log:string[],links:array{resolved:int,pending:int,updated:int},targets:array<int,array<string,mixed>>,failed:int}
     */
    private function fanOut(callable $work): array
    {
        $many = count($this->targets) > 1;
        $perTarget = [];
        $failures = [];

        // Once, not once per wiki: it is the same profile every time, and a
        // profile that has been deleted since the push started is not a
        // per-target failure - it is the whole push having no credentials.
        $credentials = Profiles::data($this->username, (int)$this->project['profile_id']);

        foreach ($this->targets as $target) {
            $name = $this->nameOf($target);
            $prefix = $many ? $this->labelFor($target) : '';

            try {
                $client = BookStackClient::fromProfile($credentials, (string)$target['instance_id']);
                $publisher = new TargetPublisher($this->project, $target, $client, $prefix);
                $result = $work($publisher);

                $perTarget[] = [
                    'target_id' => (int)$target['id'],
                    'instance_id' => (string)$target['instance_id'],
                    'name' => $name,
                    'ok' => true,
                    'error' => '',
                    'log' => $result['log'],
                    'links' => $result['links'],
                ];
            } catch (Throwable $e) {
                $failures[] = $e;
                $message = $e->getMessage();
                Runtime::log('publish.target', $e);

                $perTarget[] = [
                    'target_id' => (int)$target['id'],
                    'instance_id' => (string)$target['instance_id'],
                    'name' => $name,
                    'ok' => false,
                    'error' => $message,
                    'log' => [$prefix . 'Failed: ' . $message],
                    'links' => ['resolved' => 0, 'pending' => 0, 'updated' => 0],
                ];
            }
        }

        // The mirror runs whatever happened, and before anything is raised. A
        // wiki that failed half way through has already created a book and some
        // of its chapters, and those are recorded; leaving the course's own
        // columns describing the state before the attempt would hide exactly
        // the work somebody now has to look at.
        Targets::mirror($this->username, (int)$this->project['id']);
        Projects::touch((int)$this->project['id']);

        // Nothing at all got through. There is no partial success to report and
        // no half-finished book to describe, so the caller is told what went
        // wrong rather than handed a log saying it did not work - which is what
        // a single-destination course has always done.
        if (count($failures) === count($this->targets)) {
            throw $failures[0];
        }

        return self::combine($perTarget) + ['targets' => $perTarget, 'failed' => count($failures)];
    }

    /**
     * The one log and the one set of link counts, out of one result per wiki.
     *
     * `resolved` and `pending` describe the text, and two wikis that both hold
     * the whole course resolve the same references - adding those up would
     * report twice the cross references the course has, so the largest is the
     * honest summary. `updated` counts writes, and a page rewritten in three
     * wikis really was written three times, so those do add up.
     *
     * @param array<int,array<string,mixed>> $results
     * @return array{log:string[],links:array{resolved:int,pending:int,updated:int}}
     */
    public static function combine(array $results): array
    {
        $log = [];
        $links = ['resolved' => 0, 'pending' => 0, 'updated' => 0];

        foreach ($results as $result) {
            $log = [...$log, ...(array)($result['log'] ?? [])];
            $links['resolved'] = max($links['resolved'], (int)($result['links']['resolved'] ?? 0));
            $links['pending'] = max($links['pending'], (int)($result['links']['pending'] ?? 0));
            $links['updated'] += (int)($result['links']['updated'] ?? 0);
        }

        return ['log' => $log, 'links' => $links];
    }

    /** @param array<string,mixed> $target */
    private function nameOf(array $target): string
    {
        $instanceId = (string)$target['instance_id'];
        $name = $this->instances[$instanceId]['name'] ?? '';
        return $name !== '' ? $name : $instanceId;
    }

    /**
     * The label a log line carries when a push covers more than one wiki.
     *
     * Square brackets come out of the name, because the label is read back off
     * the line afterwards - `get_publish_status` sorts the log by its verb, and
     * a wiki called "Docs [old]" would close the label early and leave every
     * one of its lines unclassified.
     */
    private function labelFor(array $target): string
    {
        return '[' . str_replace(['[', ']'], '', $this->nameOf($target)) . '] ';
    }
}
