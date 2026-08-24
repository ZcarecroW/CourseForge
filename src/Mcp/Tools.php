<?php
declare(strict_types=1);

namespace CourseForge\Mcp;

use CourseForge\Mcp\Handlers\AccountTools;
use CourseForge\Mcp\Handlers\AdminTools;
use CourseForge\Mcp\Handlers\CourseTools;
use CourseForge\Mcp\Handlers\DetailTools;
use CourseForge\Mcp\Handlers\PageTools;
use CourseForge\Mcp\Handlers\ProfileTools;
use CourseForge\Mcp\Handlers\PublishTools;
use CourseForge\Mcp\Handlers\RunTools;
use CourseForge\Mcp\Handlers\StructureTools;
use CourseForge\Mcp\Handlers\TagTools;
use CourseForge\Security\Actor;
use CourseForge\Support\HttpException;

/**
 * What CourseForge offers an MCP client.
 *
 * CourseForge 3 offered six tools, all of them variations on "hand Claude a
 * writing brief and take the page back". That inversion is still here and is
 * still the cheapest way to write a course - the work happens inside the Claude
 * application, on somebody's own subscription, and the server never holds a
 * credential.
 *
 * What 4.0 adds is the other half: everything the browser can do. Create a
 * course, have CourseForge design the outline with the profile's own model,
 * start a five-hundred-page batch run that carries on for a day after the
 * client has disconnected, watch it, publish the result, and - for an
 * administrator - manage accounts, settings and updates. A person talking to
 * Claude Code should not have to open the web interface to do something the web
 * interface can do.
 *
 * Three rules hold the surface together:
 *
 *   - **the connection never exceeds the account.** Every tool runs as the
 *     Actor the token resolves to, through the same Access checks the HTTP API
 *     uses. An administrator's token can reach everything; a normal account's
 *     token reaches that account's own courses and nothing else.
 *   - **scopes narrow, never widen.** A connection may be limited to some tool
 *     groups. The `admin` group is unavailable to a normal account whatever the
 *     token asks for.
 *   - **a tool that spends money says so**, in its description and in its
 *     annotations, because "generate the whole course" and "show me the course"
 *     cost very different amounts.
 */
final class Tools
{
    /** @var array<string,Tool>|null */
    private static ?array $index = null;

    /**
     * Every tool, keyed by name.
     *
     * @return array<string,Tool>
     */
    public static function registry(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $tools = [
            ...AccountTools::tools(),
            ...CourseTools::tools(),
            ...StructureTools::tools(),
            ...PageTools::tools(),
            ...DetailTools::tools(),
            ...TagTools::tools(),
            ...RunTools::tools(),
            ...ProfileTools::tools(),
            ...PublishTools::tools(),
            ...AdminTools::tools(),
        ];

        $index = [];
        foreach ($tools as $tool) {
            $index[$tool->name] = $tool;
        }
        return self::$index = $index;
    }

    /**
     * The catalogue this connection may see, in the shape `tools/list` returns.
     *
     * @param string[] $scopes what the connection asked for; empty for everything allowed
     * @return array<int,array<string,mixed>>
     */
    public static function catalogue(Actor $actor, array $scopes = []): array
    {
        $allowed = Scopes::effective($actor, $scopes);

        $out = [];
        foreach (self::registry() as $tool) {
            if (!in_array($tool->scope, $allowed, true)) {
                continue;
            }
            if ($tool->admin && !$actor->isAdmin()) {
                continue;
            }
            $out[] = $tool->describe();
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> the scope catalogue, for the Connect screen */
    public static function scopes(): array
    {
        $counts = [];
        foreach (self::registry() as $tool) {
            $counts[$tool->scope] = ($counts[$tool->scope] ?? 0) + 1;
        }

        return array_map(static function (array $group) use ($counts): array {
            $group['tools'] = $counts[$group['key']] ?? 0;
            return $group;
        }, Scopes::catalogue());
    }

    /**
     * Runs one tool.
     *
     * A tool the connection cannot see is reported as unknown rather than as
     * forbidden. The model does not need to learn that a tool exists which it
     * may not call - it needs to stop trying, and an unknown-tool answer does
     * that without teaching it anything.
     *
     * @param array<string,mixed> $arguments
     * @param string[] $scopes
     * @return array{text:string,data:array<string,mixed>|null}
     */
    public static function call(Actor $actor, string $name, array $arguments, array $scopes = []): array
    {
        $tool = self::registry()[$name] ?? null;
        $allowed = Scopes::effective($actor, $scopes);

        if ($tool === null
            || !in_array($tool->scope, $allowed, true)
            || ($tool->admin && !$actor->isAdmin())) {
            throw HttpException::notFound(
                'There is no tool called "' . $name . '" on this connection. Call tools/list to see what there is.'
            );
        }

        $result = $tool->run($actor, $arguments);

        if (is_string($result)) {
            return ['text' => $result, 'data' => null];
        }
        if (!is_array($result)) {
            return ['text' => (string)json_encode($result), 'data' => null];
        }

        return ['text' => self::json($result), 'data' => $result];
    }

    /** @param array<string,mixed> $data */
    public static function json(array $data): string
    {
        return (string)json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    /** Test seam, and what an update calls after replacing these files. */
    public static function flush(): void
    {
        self::$index = null;
    }
}
