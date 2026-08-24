<?php
declare(strict_types=1);

namespace CourseForge\Mcp;

use Closure;
use CourseForge\Security\Actor;

/**
 * One tool, declared once.
 *
 * The definition and the implementation live in the same object because the
 * alternative - a catalogue in one place and a `match` in another - is a pair
 * that drifts. A tool that is renamed, given a new argument or restricted to
 * administrators changes in exactly one place here.
 *
 * The annotations are hints the protocol defines for clients that want to warn
 * a person before something happens. They are advisory, so nothing in
 * CourseForge relies on them for safety - the handler checks the role and the
 * ownership itself - but a client that greys out a destructive tool by default
 * is doing something useful with them, and they cost one line each.
 */
final class Tool
{
    /**
     * @param array<string,mixed> $properties JSON Schema properties for the arguments
     * @param string[] $required
     * @param Closure(Actor, array<string,mixed>):mixed $handler
     */
    public function __construct(
        public readonly string $name,
        public readonly string $scope,
        public readonly string $title,
        public readonly string $description,
        public readonly array $properties,
        public readonly array $required,
        public readonly Closure $handler,
        public readonly bool $readOnly = false,
        public readonly bool $destructive = false,
        public readonly bool $idempotent = false,
        public readonly bool $admin = false,
        public readonly bool $spends = false,
        public readonly ?bool $openWorld = null,
        public readonly int $maxResultChars = 0,
    ) {
    }

    /**
     * The tool as `tools/list` returns it.
     *
     * An input schema with no properties has to serialise as an object, not as
     * an empty array, or a strict client rejects the whole listing - which is
     * why the empty case is a stdClass rather than `[]`.
     *
     * @return array<string,mixed>
     */
    public function describe(): array
    {
        $description = $this->description;
        if ($this->spends) {
            $description .= ' This spends credit on the account\'s own AI provider.';
        }

        return [
            'name' => $this->name,
            'title' => $this->title,
            'description' => $description,
            'inputSchema' => [
                'type' => 'object',
                'properties' => $this->properties === [] ? new \stdClass() : $this->properties,
                'required' => $this->required,
                'additionalProperties' => false,
            ],
            // Every hint is stated explicitly because the protocol's defaults
            // are pessimistic: a tool that says nothing is assumed destructive
            // and open-world, and a client that warns on those would warn on
            // list_courses.
            'annotations' => [
                'title' => $this->title,
                'readOnlyHint' => $this->readOnly,
                'destructiveHint' => $this->destructive,
                'idempotentHint' => $this->idempotent,
                'openWorldHint' => $this->openWorld ?? $this->spends,
            ],
        ] + $this->meta();
    }

    /**
     * Client-specific hints, in the `_meta` slot the protocol reserves for them.
     *
     * Claude Code truncates a tool result at 25,000 tokens by default, which a
     * finished course would blow through several times over. A tool that can
     * legitimately return a great deal says so here rather than being silently
     * cut off half way through a page.
     *
     * @return array<string,mixed>
     */
    private function meta(): array
    {
        if ($this->maxResultChars <= 0) {
            return [];
        }
        return ['_meta' => ['anthropic/maxResultSizeChars' => min($this->maxResultChars, 500000)]];
    }

    /** @param array<string,mixed> $arguments */
    public function run(Actor $actor, array $arguments): mixed
    {
        return ($this->handler)($actor, $arguments);
    }
}
