<?php
declare(strict_types=1);

namespace CourseForge\Api;

use CourseForge\Security\Actor;
use CourseForge\Security\Auth;
use CourseForge\Security\Invite;
use CourseForge\Security\LoginThrottle;
use CourseForge\Security\Session;
use CourseForge\Security\Users;
use CourseForge\Support\Audit;
use CourseForge\Support\Config;
use CourseForge\Support\Db;
use CourseForge\Support\HttpException;
use CourseForge\Support\Request;

/**
 * Creating an account while signed out, which an invite code is what makes
 * possible.
 *
 * A brand-new installation has no accounts, so there is nobody who could be
 * asked to authorise the creation of the first one. What stands in for that is
 * the invite code in `INVITE-CODE.txt`: a file written next to `index.html`
 * the first time CourseForge is opened, readable by whoever installed it and by
 * nobody on the internet.
 *
 * There are two doors, and they are deliberately different:
 *
 *   `create` is the first run, and closes for good the moment an account
 *            exists. The account it makes is always an administrator, whatever
 *            the open invite happens to say, because an installation whose only
 *            account cannot manage accounts is bricked.
 *   `redeem` is the invite an administrator issued from the Accounts screen. It
 *            makes an account with the role written on that invite row and
 *            nothing else - a stolen code can only ever create what the
 *            administrator who issued it decided to give away.
 *
 * Both spend the invite, and neither can spend one twice.
 */
final class SetupController
{
    /** @return array<string,mixed> */
    public static function status(Request $request, ?Actor $actor): array
    {
        $needsSetup = Users::needsSetup();
        if ($needsSetup) {
            // Writing the file is what makes setup possible at all, so it
            // happens here rather than somewhere the user never reaches.
            Invite::ensureBootstrap();
        }

        $invite = Invite::status();

        return [
            'needs_setup' => $needsSetup,
            'app' => ['name' => Config::str('app.name', 'CourseForge'), 'version' => CF_VERSION],
            'min_password' => Users::MIN_PASSWORD,
            // The path is not a secret - the code inside it is - and without it
            // nobody knows where to look on a host that put it in data/.
            'invite_file' => $needsSetup ? (string)($invite['path'] ?? '') : '',
            'invite_open' => (bool)($invite['open'] ?? false),
        ];
    }

    /** Creates the first administrator and signs them in. */
    public static function create(Request $request, ?Actor $actor): array
    {
        if (!Users::needsSetup()) {
            throw HttpException::forbidden(
                'This CourseForge already has accounts. Ask an administrator to create one for you, or for an '
                . 'invite code you can redeem yourself.'
            );
        }

        ['user' => $user] = self::accountFromInvite($request, 'setup');
        Audit::record((string)$user['username'], 'setup.complete', (string)$user['username'], 'first administrator created');

        return ['user' => Auth::describe(), 'csrf' => Session::csrf()];
    }

    /**
     * Redeems an invite an administrator issued, on an installation that
     * already has accounts.
     *
     * This is the other half of the Accounts screen's invite button: the code
     * goes to somebody who has no account, and they turn it into one with a
     * password only they know. Signed out on purpose - the whole point is that
     * the holder has no way in yet - which makes the code the only credential
     * involved, so:
     *
     *   - the role is read from the invite row and from nowhere else. A request
     *     field called `role` is ignored, so a stolen code cannot ask for more
     *     than the administrator who issued it decided to give away;
     *   - a wrong code counts against this address's login throttle, which is
     *     what keeps an anonymous caller from grinding at codes, and is recorded
     *     against no user name at all, so guessing here cannot lock anybody out;
     *   - the invite is spent in the same transaction that creates the account,
     *     so one code is one account however many requests arrive together.
     */
    public static function redeem(Request $request, ?Actor $actor): array
    {
        if ($actor !== null) {
            throw HttpException::forbidden(
                'You are already signed in. An invite creates a new account, so sign out before redeeming one.'
            );
        }

        ['user' => $user, 'invite' => $invite] = self::accountFromInvite($request, 'invite');
        Audit::record(
            (string)$user['username'],
            'invite.redeem',
            (string)$user['username'],
            'role=' . $user['role'] . ', invite issued by ' . ((string)($invite['issued_by'] ?? '') ?: 'first start')
        );

        return ['user' => Auth::describe(), 'csrf' => Session::csrf()];
    }

    /* -------------------------------------------------------------- helpers */

    /**
     * Turns an open invite into an account and signs it in.
     *
     * The order is the point of this method. Everything that can be refused -
     * the code, the name, the password - is refused before anything is written,
     * because an invite burnt by a mistyped password is an invite nobody can
     * use and, on a first run, an installation nobody can get into. Then the
     * invite is spent and the account created inside one transaction, with the
     * spend conditional on the invite still being open, so two requests holding
     * the same code produce one account rather than two administrators. The
     * file is removed only once that has committed: a transaction can be rolled
     * back, an unlinked file cannot.
     *
     * @return array{user:array<string,mixed>,invite:array<string,mixed>}
     */
    private static function accountFromInvite(Request $request, string $createdBy): array
    {
        $ip = LoginThrottle::ip();
        $locked = LoginThrottle::lockoutRemaining($ip);
        if ($locked > 0) {
            throw HttpException::forbidden(
                'Too many failed attempts from this address. Try again in ' . (int)ceil($locked / 60) . ' minute(s).'
            );
        }

        try {
            $invite = Invite::verify(
                $request->str('invite_code'),
                $createdBy === 'setup' ? Invite::AUDIENCE_INSTALLER : Invite::AUDIENCE_HOLDER
            );
        } catch (HttpException $e) {
            // Against the address and against no account: a user name typed
            // here must never be able to lock out the account that bears it.
            LoginThrottle::record($ip, '', false);
            throw $e;
        }

        $username = Users::validateUsername($request->requiredStr('username', 'A user name'));
        $password = $request->raw('password');
        $confirm = $request->raw('password_confirm');
        if ($confirm !== '' && $confirm !== $password) {
            throw HttpException::unprocessable('The two passwords do not match.');
        }
        Users::validatePassword($password);
        if (Users::find($username) !== null) {
            throw HttpException::unprocessable('An account called "' . $username . '" already exists.');
        }

        // The invite decides the role - except for the very first account,
        // which is an administrator whichever door it came through and whatever
        // the invite says. An installation whose only account cannot manage
        // accounts cannot make a second one, and nothing could repair that from
        // a browser.
        $role = Users::needsSetup()
            ? Actor::ROLE_ADMIN
            : Actor::normaliseRole((string)($invite['role'] ?? Actor::ROLE_USER));
        $displayName = $request->str('display_name');

        $user = Db::transaction(static function () use ($invite, $username, $password, $role, $displayName, $createdBy): array {
            if (!Invite::consume($invite, $username)) {
                throw HttpException::forbidden('That invite code has already been used.');
            }
            return Users::create($username, $password, $role, $displayName, $createdBy);
        });

        Invite::discard($invite);
        Auth::establish($user);

        return ['user' => $user, 'invite' => $invite];
    }
}
