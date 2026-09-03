<?php
declare(strict_types=1);

namespace CourseForge\Publish;

/**
 * How long a publisher may keep going, and whether anybody still wants it to.
 *
 * A push run inside a request has all the time in the world - the request has
 * already been marked long-running - and asks nothing. A push run by the
 * scheduler has a tick's worth, and it also has to notice when the task it is
 * working on has been stopped underneath it. Both questions are asked between
 * items, never in the middle of one, so a page is either written whole or not
 * touched.
 *
 * The liveness callback is throttled to once every few seconds, because it is
 * a database write - renewing a lease - and asking before every one of five
 * hundred pages would be five hundred writes for one answer.
 */
final class PublishBudget
{
    private float $lastAsked = 0.0;
    private bool $lost = false;

    /**
     * @param float|null $deadline a microtime() to stop at, or null for no limit
     * @param callable():bool|null $alive answers whether the work is still wanted
     */
    public function __construct(
        private readonly ?float $deadline = null,
        private $alive = null,
        private readonly float $askEvery = 5.0,
    ) {
    }

    /** A budget that never runs out - what a synchronous push uses. */
    public static function unlimited(): self
    {
        return new self(null, null);
    }

    /** True when it is time to stop: out of time, or nobody wants the work any more. */
    public function exhausted(): bool
    {
        if ($this->lost) {
            return true;
        }
        $now = microtime(true);
        if ($this->deadline !== null && $now >= $this->deadline) {
            return true;
        }
        if ($this->alive !== null && ($now - $this->lastAsked) >= $this->askEvery) {
            $this->lastAsked = $now;
            if (($this->alive)() !== true) {
                $this->lost = true;
                return true;
            }
        }
        return false;
    }

    /** Whether the work was taken away, as opposed to merely running out of time. */
    public function lost(): bool
    {
        return $this->lost;
    }
}
