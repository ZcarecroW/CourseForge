<?php
declare(strict_types=1);

namespace CourseForge\Security;

use CourseForge\Support\Config;
use CourseForge\Support\Db;

/**
 * Login rate limiting, backed by the `login_attempts` table.
 *
 * Two different things need protecting here, so there are two counters rather
 * than one:
 *
 *   the account - failed attempts against one user name, from wherever they
 *                 came. This is the only thing standing between an
 *                 administrator's password and an online guess, and it has to
 *                 hold when the guesses arrive from a different address each
 *                 time, which is what a botnet is.
 *   the address - failed attempts from one address, against any account. This
 *                 is what stops one machine working through a list of names.
 *                 It is deliberately the looser of the two, because an address
 *                 is shared - an office behind NAT, a carrier-grade NAT range,
 *                 a VPN exit - and a cap of five there would let one colleague
 *                 mistyping their password lock out the whole building.
 *
 * Both caps are settings - `security.max_login_attempts` and
 * `security.max_address_attempts` - because how shared the address in front of
 * an installation is, is something only the person running it knows. What was
 * fixed in the code before was the *ratio* between them, which is the one part
 * of this an administrator could not see and had no way to reason about.
 *
 * A sign-in that succeeds clears the failures recorded for that account at that
 * address, and nothing else. Somebody proving they know tess's password is
 * evidence about tess and about nobody else: clearing the whole address, which
 * is what this used to do, let anyone holding any ordinary account reset the
 * counter at will and guess an administrator's password indefinitely.
 */
final class LoginThrottle
{
    public static function ip(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /**
     * Seconds left in the lockout, 0 when not locked.
     *
     * The account is locked while it is being guessed at from anywhere; the
     * address is locked while it is guessing at anything. Whichever has longer
     * to run is the answer. Called without a user name - by the signed-out
     * session view, which has nobody to ask about - it reports the address lock
     * alone, and so tells an anonymous caller nothing about any account.
     */
    public static function lockoutRemaining(?string $ip = null, string $username = ''): int
    {
        $ip ??= self::ip();
        $username = trim($username);
        $window = self::window();
        $lock = max(0, Config::int('security.lockout_minutes', 15)) * 60;
        $max = self::maxAttempts();

        $remaining = self::lockFor('ip = ?', [$ip], self::maxAddressAttempts(), $window, $lock);
        // The account lock holds against every address - that is what makes
        // it a lock - except the addresses this account has actually signed
        // in from lately. Five wrong guesses from anywhere on the internet
        // must not be able to keep the real owner out of their own account
        // from their own desk, which is the denial of service the hard lock
        // otherwise hands to anybody who knows a user name.
        if ($username !== '' && !self::trusted($ip, $username)) {
            $remaining = max(
                $remaining,
                self::lockFor('username = ? COLLATE NOCASE', [$username], $max, $window, $lock)
            );
        }
        return $remaining;
    }

    /**
     * Failures inside the window - for one account when a name is given, for
     * the address when it is not. This is what "3 attempt(s) left" counts.
     */
    public static function failuresInWindow(?string $ip = null, string $username = ''): int
    {
        $username = trim($username);
        [$where, $args] = $username !== ''
            ? ['username = ? COLLATE NOCASE', [$username]]
            : ['ip = ?', [$ip ?? self::ip()]];

        $row = Db::row(
            'SELECT COUNT(*) AS failures FROM login_attempts WHERE ' . $where . ' AND ok = 0 AND ts > ?',
            [...$args, time() - self::window()]
        );
        return (int)($row['failures'] ?? 0);
    }

    public static function record(string $ip, string $username, bool $ok): void
    {
        Db::run(
            'INSERT INTO login_attempts (ip, username, ok, ts) VALUES (?,?,?,?)',
            [$ip, trim($username), $ok ? 1 : 0, time()]
        );
        Db::run('DELETE FROM login_attempts WHERE ts < ?', [time() - 86400]);
    }

    /**
     * Forgets the failed attempts of one account at one address.
     *
     * Scoped to both on purpose: a success under one name says nothing about
     * the guesses made at another, and a success here says nothing about what
     * the same account is suffering somewhere else.
     */
    public static function clear(string $ip, string $username): void
    {
        $username = trim($username);
        if ($username === '') {
            return;
        }
        Db::run(
            'DELETE FROM login_attempts WHERE ip = ? AND username = ? COLLATE NOCASE AND ok = 0',
            [$ip, $username]
        );
    }

    /* -------------------------------------------------------------- helpers */

    /** Whether this address has signed in to this account in the last day. */
    private static function trusted(string $ip, string $username): bool
    {
        $row = Db::row(
            'SELECT COUNT(*) AS n FROM login_attempts WHERE ip = ? AND username = ? COLLATE NOCASE AND ok = 1 AND ts > ?',
            [$ip, $username, time() - 86400]
        );
        return (int)($row['n'] ?? 0) > 0;
    }

    private static function window(): int
    {
        return max(1, Config::int('security.attempt_window_minutes', 15)) * 60;
    }

    /** At least one: a cap of zero would lock every account out for ever. */
    private static function maxAttempts(): int
    {
        return max(1, Config::int('security.max_login_attempts', 5));
    }

    /**
     * The address cap, never below the account cap.
     *
     * The two are set independently and an administrator can put them the wrong
     * way round - raise the per-account figure to ten and leave the address at
     * five, and the address locks after five, which shuts the door on everybody
     * behind it before any single account has used the allowance the other
     * setting promises. The floor keeps the looser counter loose, which is the
     * whole reason there are two of them; the Settings screen says so where an
     * administrator will read it.
     */
    private static function maxAddressAttempts(): int
    {
        return max(self::maxAttempts(), Config::int('security.max_address_attempts', 20));
    }

    /**
     * The lockout one counter imposes: nothing until it is full, then
     * `lockout_minutes` measured from the most recent failure, so somebody who
     * keeps guessing keeps the door shut on themselves.
     *
     * @param array<int,string> $args
     */
    private static function lockFor(string $where, array $args, int $max, int $window, int $lock): int
    {
        $row = Db::row(
            'SELECT COUNT(*) AS failures, COALESCE(MAX(ts), 0) AS last
               FROM login_attempts WHERE ' . $where . ' AND ok = 0 AND ts > ?',
            [...$args, time() - $window]
        ) ?? ['failures' => 0, 'last' => 0];

        if ((int)$row['failures'] < $max) {
            return 0;
        }
        return max(0, ((int)$row['last'] + $lock) - time());
    }
}
