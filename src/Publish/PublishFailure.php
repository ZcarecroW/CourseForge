<?php
declare(strict_types=1);

namespace CourseForge\Publish;

use CourseForge\Support\HttpException;
use RuntimeException;
use Throwable;

/**
 * A push that stopped part way, carrying where it had got to.
 *
 * When a wiki stops answering in the middle of a course, the exception says
 * why and this wrapper says where: the last item that was written and
 * recorded before it happened. A task keeps that and hands it back to the
 * next attempt, which is what lets the attempt carry on from that item rather
 * than walk the whole course again. The message is the wiki's own, unchanged,
 * and the original exception is underneath for anybody who wants it.
 */
final class PublishFailure extends RuntimeException
{
    /** @param array<string,mixed> $state the publisher's place in the work when it failed */
    private function __construct(string $message, public readonly array $state, Throwable $previous)
    {
        parent::__construct($message, 0, $previous);
    }

    /** @param array<string,mixed> $state */
    public static function wrap(Throwable $cause, array $state): self
    {
        return $cause instanceof self ? $cause : new self($cause->getMessage(), $state, $cause);
    }

    /** The cause, for callers that want to raise it as it was. */
    public function cause(): Throwable
    {
        return $this->getPrevious() ?? $this;
    }

    /** The HTTP status the cause would have answered with. */
    public function status(): int
    {
        $cause = $this->getPrevious();
        return $cause instanceof HttpException ? $cause->status() : 500;
    }
}
