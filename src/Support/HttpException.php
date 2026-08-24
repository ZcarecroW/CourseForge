<?php
declare(strict_types=1);

namespace CourseForge\Support;

use RuntimeException;

/**
 * An error the client is allowed to see verbatim.
 *
 * Everything the API rejects on purpose – bad input, missing entity, wrong
 * method, unauthenticated request – is thrown as an HttpException. Anything
 * else that escapes is a bug and gets logged and masked by the front
 * controller.
 */
final class HttpException extends RuntimeException
{
    /** @param array<string,mixed> $extra Extra top-level keys for the JSON body. */
    public function __construct(string $message, private readonly int $status = 400, private readonly array $extra = [])
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string,mixed> */
    public function extra(): array
    {
        return $this->extra;
    }

    public static function badRequest(string $message): self
    {
        return new self($message, 400);
    }

    public static function unauthorized(string $message = 'Not authenticated.'): self
    {
        return new self($message, 401);
    }

    public static function forbidden(string $message): self
    {
        return new self($message, 403);
    }

    public static function notFound(string $message): self
    {
        return new self($message, 404);
    }

    /** @param string[] $allowed */
    public static function methodNotAllowed(array $allowed): self
    {
        return new self('Method not allowed for this endpoint.', 405, ['allow' => $allowed]);
    }

    public static function unprocessable(string $message): self
    {
        return new self($message, 422);
    }
}
