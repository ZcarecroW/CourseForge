<?php
declare(strict_types=1);

namespace CourseForge\Support;

/**
 * A lease, so two cron ticks never do the same work twice.
 *
 * Leases rather than locks, because the holder is a PHP process that can vanish
 * without warning: a shared host enforcing its own time limit, a restart, a
 * killed request. A lock that had to be released explicitly would then be held
 * forever and the queue would stop. An expiry means the worst case is that one
 * page waits until the lease runs out.
 *
 * The whole thing is one conditional UPDATE, which SQLite makes atomic for us -
 * whoever's UPDATE reports a changed row owns the slot.
 */
final class Lock
{
    /**
     * Takes the named lease for $seconds, or returns false if somebody else has it.
     *
     * The owner token is what makes release() safe: a worker whose lease has
     * already expired and been taken by someone else must not release theirs.
     */
    public static function acquire(string $name, int $seconds, string $owner = ''): string|false
    {
        $now = time();
        $owner = $owner !== '' ? $owner : bin2hex(random_bytes(8));
        $until = $now + max(1, $seconds);

        // Create the row if this slot has never been used.
        Db::run('INSERT OR IGNORE INTO locks (name, until, owner) VALUES (?, 0, ?)', [$name, '']);

        $taken = Db::run(
            'UPDATE locks SET until = ?, owner = ? WHERE name = ? AND until < ?',
            [$until, $owner, $name, $now]
        )->rowCount() > 0;

        return $taken ? $owner : false;
    }

    /** Extends a lease we still hold. False means we lost it and must stop. */
    public static function renew(string $name, int $seconds, string $owner): bool
    {
        return Db::run(
            'UPDATE locks SET until = ? WHERE name = ? AND owner = ?',
            [time() + max(1, $seconds), $name, $owner]
        )->rowCount() > 0;
    }

    public static function release(string $name, string $owner): void
    {
        Db::run('UPDATE locks SET until = 0 WHERE name = ? AND owner = ?', [$name, $owner]);
    }

    /** Seconds until the named lease is free, or 0 if it already is. */
    public static function heldFor(string $name): int
    {
        $row = Db::row('SELECT until FROM locks WHERE name = ?', [$name]);
        return $row === null ? 0 : max(0, (int)$row['until'] - time());
    }
}
