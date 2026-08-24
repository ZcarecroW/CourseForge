<?php
declare(strict_types=1);

namespace CourseForge\Security;

use CourseForge\Support\Config;
use CourseForge\Support\HttpException;

/** Sign in, sign out and "who is asking?". */
final class Auth
{
    /**
     * The signed-in account, re-read from the database on every request.
     *
     * The session holds the row id and the user name, and the account is looked
     * up by the id. Role, display name and whether the account still exists are
     * read every time, so a demotion, a renamed display name or a deletion
     * takes effect at once instead of when the session happens to expire.
     *
     * The id is what the session is bound to, because a user name is not an
     * identity: it is deliberately recyclable. Deleting an account and creating
     * another with the same name is how a name is handed to a new person -
     * Users::delete even offers to hand the old content over with it - so a
     * session that outlived its account must not be able to reattach itself to
     * whoever is given that name next. Row ids are AUTOINCREMENT and are never
     * reissued, so such a session simply stops resolving.
     *
     * The name is compared as well because it costs nothing: if the two ever
     * disagree, something moved underneath this session and refusing is the
     * safe answer.
     */
    public static function current(): ?Actor
    {
        $username = trim((string)($_SESSION['user'] ?? ''));
        $id = (int)($_SESSION['uid'] ?? 0);
        if ($username === '' || $id <= 0) {
            return null;
        }

        $user = Users::byId($id);
        if ($user === null || (int)$user['disabled'] === 1) {
            return null;
        }
        if (strcasecmp((string)$user['username'], $username) !== 0) {
            return null;
        }

        return Actor::make(
            (string)$user['username'],
            (string)($user['display_name'] ?: $user['username']),
            (string)$user['role']
        );
    }

    public static function require(): Actor
    {
        $actor = self::current();
        if ($actor === null) {
            throw HttpException::unauthorized();
        }
        return $actor;
    }

    public static function requireAdmin(): Actor
    {
        $actor = self::require();
        $actor->requireAdmin();
        return $actor;
    }

    /** @return array{ok:bool,error?:string,locked_for?:int,user?:array<string,mixed>} */
    public static function login(string $username, string $password): array
    {
        $ip = LoginThrottle::ip();
        $username = trim($username);

        // Both counters are consulted: this account being guessed at from
        // anywhere, and this address guessing at anything.
        $remaining = LoginThrottle::lockoutRemaining($ip, $username);
        if ($remaining > 0) {
            return [
                'ok' => false,
                'error' => 'Too many failed attempts. Try again in ' . (int)ceil($remaining / 60) . ' minute(s).',
                'locked_for' => $remaining,
            ];
        }

        try {
            $user = Users::verify($username, $password);
        } catch (HttpException $e) {
            // A disabled account is a real answer, not a throttled guess.
            LoginThrottle::record($ip, $username, false);
            return ['ok' => false, 'error' => $e->getMessage(), 'locked_for' => 0];
        }

        LoginThrottle::record($ip, $username, $user !== null);

        if ($user === null) {
            $left = max(0, Config::int('security.max_login_attempts', 5) - LoginThrottle::failuresInWindow($ip, $username));
            return [
                'ok' => false,
                'error' => 'Invalid credentials.' . ($left > 0 ? ' ' . $left . ' attempt(s) left.' : ''),
                'locked_for' => LoginThrottle::lockoutRemaining($ip, $username),
            ];
        }

        self::establish($user);
        // Only this account's failures, and only from here: the whole point of
        // the throttle is that signing in as one account proves nothing about
        // the guesses somebody has been making at another.
        LoginThrottle::clear($ip, (string)$user['username']);

        return ['ok' => true, 'user' => self::describe()];
    }

    /**
     * Puts an account into the current session. Also used right after setup.
     *
     * Both halves of the identity go in: the row id, which is what current()
     * binds to, and the name, which is what everything else reads. A session
     * written by an older release carries no id and no longer resolves, so the
     * first request after an update asks for a sign-in - the right price for
     * not letting a session outlive the account it belongs to.
     *
     * @param array<string,mixed> $user a users row, or its public view
     */
    public static function establish(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user'] = (string)$user['username'];
        $_SESSION['uid'] = (int)$user['id'];
        $_SESSION['last_seen'] = time();
    }

    /**
     * The signed-in account as the SPA wants it.
     *
     * @return array<string,mixed>|null
     */
    public static function describe(): ?array
    {
        $actor = self::current();
        if ($actor === null) {
            return null;
        }
        $view = $actor->toArray();
        $view['must_change_password'] = self::passwordChangeDue($actor);
        return $view;
    }

    /**
     * Whether this account has to choose a password before doing anything else.
     *
     * An administrator who creates an account, or resets somebody's password,
     * hands over a password that a second person has read - off a screen, out
     * of a chat window - so it is good for exactly one thing: signing in and
     * replacing itself. The flag is what says that has not happened yet, and
     * the front controller turns it into a refusal; the Vue app opens a dialog
     * over it, but a dialog is not a rule.
     */
    public static function passwordChangeDue(Actor $actor): bool
    {
        $row = Users::find($actor->username);
        return $row !== null && (int)($row['must_change_password'] ?? 0) === 1;
    }

    public static function logout(): void
    {
        Session::destroy();
    }
}
