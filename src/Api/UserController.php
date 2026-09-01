<?php
declare(strict_types=1);

namespace CourseForge\Api;

use CourseForge\Security\Actor;
use CourseForge\Security\Invite;
use CourseForge\Security\Users;
use CourseForge\Support\Audit;
use CourseForge\Support\HttpException;
use CourseForge\Support\Request;

/**
 * Account management, for administrators.
 *
 * Every route here is guarded twice: once by the router, which will not call an
 * admin handler for a normal account, and once by `requireAdmin()` on the
 * actor, because a handler that is safe on its own cannot be made unsafe by a
 * routing mistake made later.
 *
 * The two rules worth stating out loud:
 *
 *   - the last enabled administrator cannot be deleted, disabled or demoted,
 *     because an installation with no administrator can only be repaired from
 *     the file system;
 *   - deleting an account never silently deletes its courses. The caller has to
 *     say whether they should be removed or handed to somebody else.
 */
final class UserController
{
    /** @return array<string,mixed> */
    public static function index(Request $request, ?Actor $actor): array
    {
        $me = self::admin($actor);

        $users = Users::all();
        foreach ($users as $i => $user) {
            $users[$i]['content'] = Users::contentSummary((string)$user['username']);
            $users[$i]['is_you'] = strcasecmp((string)$user['username'], $me->username) === 0;
        }

        return [
            'users' => $users,
            'roles' => [
                ['key' => Actor::ROLE_USER, 'label' => 'User', 'hint' => 'Own courses, profiles and tags. Cannot change the installation.'],
                ['key' => Actor::ROLE_ADMIN, 'label' => 'Administrator', 'hint' => 'Everything a user can do, plus accounts, settings and updates.'],
            ],
            'min_password' => Users::MIN_PASSWORD,
            'invite' => Invite::status(),
        ];
    }

    public static function create(Request $request, ?Actor $actor): array
    {
        $me = self::admin($actor);

        $password = $request->raw('password');
        if ($password === '') {
            $password = self::suggestPassword();
            $generated = true;
        }

        $user = Users::create(
            $request->requiredStr('username', 'A user name'),
            $password,
            $request->enum('role', [Actor::ROLE_USER, Actor::ROLE_ADMIN], Actor::ROLE_USER),
            $request->str('display_name'),
            $me->username,
            $request->bool('must_change_password', true),
        );

        Audit::record($me->username, 'user.create', (string)$user['username'], 'role=' . $user['role']);

        // A generated password is shown once, on the card that created it, in
        // the same way a connection token is.
        return ['user' => $user, 'password' => isset($generated) ? $password : ''];
    }

    public static function update(Request $request, ?Actor $actor): array
    {
        $me = self::admin($actor);
        $target = self::target($request);

        if ($request->has('role')) {
            $role = $request->enum('role', [Actor::ROLE_USER, Actor::ROLE_ADMIN], Actor::ROLE_USER);
            if (strcasecmp($target, $me->username) === 0 && $role !== Actor::ROLE_ADMIN) {
                throw HttpException::unprocessable('You cannot take your own administrator rights away.');
            }
            Users::setRole($target, $role);
            Audit::record($me->username, 'user.role', $target, 'role=' . $role);
        }

        if ($request->has('display_name')) {
            Users::setDisplayName($target, $request->str('display_name'));
        }

        if ($request->has('disabled')) {
            $disabled = $request->bool('disabled');
            if ($disabled && strcasecmp($target, $me->username) === 0) {
                throw HttpException::unprocessable('You cannot disable the account you are signed in with.');
            }
            Users::setDisabled($target, $disabled);
            Audit::record($me->username, $disabled ? 'user.disable' : 'user.enable', $target);
        }

        if ($request->has('password')) {
            $password = $request->raw('password');
            if ($password !== '') {
                Users::setPassword($target, $password, $request->bool('must_change_password', true));
                Audit::record($me->username, 'user.password', $target);
            }
        }

        return ['user' => Users::publicView(Users::require($target))];
    }

    public static function delete(Request $request, ?Actor $actor): array
    {
        $me = self::admin($actor);
        $target = self::target($request);

        if (strcasecmp($target, $me->username) === 0) {
            throw HttpException::unprocessable('You cannot delete the account you are signed in with.');
        }

        $content = $request->enum('content', ['delete', 'transfer'], 'transfer');
        $transferTo = $content === 'transfer'
            ? ($request->str('transfer_to') !== '' ? $request->str('transfer_to') : $me->username)
            : '';

        $summary = Users::contentSummary($target);
        Users::delete($target, $content, $transferTo);

        Audit::record(
            $me->username,
            'user.delete',
            $target,
            $content === 'transfer'
                ? 'content transferred to ' . $transferTo . ' (' . $summary['courses'] . ' course(s))'
                : 'content deleted (' . $summary['courses'] . ' course(s), ' . $summary['pages'] . ' page(s))'
        );

        return ['users' => Users::all()];
    }

    /**
     * Issues an invite code, written to INVITE-CODE.txt.
     *
     * This is how somebody creates their own account, choosing a password
     * nobody else has ever seen, rather than being handed one over a chat. The
     * holder redeems it at `POST redeem` while signed out, and the account they
     * get carries the role written on the invite row - the code itself is never
     * an argument about what it is worth. Reading the file needs the same
     * access as editing `config/`, so it grants nothing that was not already
     * granted.
     *
     * It is not a way back into an installation whose administrator passwords
     * have all been lost: issuing one needs an administrator session, so by
     * then there is nobody left to issue it. That case is repaired by deleting
     * the rows in `users`, which brings the setup screen and a fresh first-run
     * code back.
     */
    public static function invite(Request $request, ?Actor $actor): array
    {
        $me = self::admin($actor);

        $issued = Invite::issue(
            $request->enum('role', [Actor::ROLE_USER, Actor::ROLE_ADMIN], Actor::ROLE_USER),
            max(1, min(24 * 30, (int)($request->intOrNull('ttl_hours') ?? Invite::DEFAULT_TTL_HOURS))),
            $me->username,
            max(1, min(Invite::MAX_USES, (int)($request->intOrNull('max_uses') ?? 1))),
        );

        Audit::record(
            $me->username,
            'user.invite',
            $issued['role'],
            'written to ' . $issued['path'] . ', good for ' . $issued['max_uses'] . ' account(s)'
        );

        // The code goes back once, so the administrator can pass it on without
        // having to open a file on the server.
        return ['invite' => $issued];
    }

    /**
     * Takes the open invite back.
     *
     * Issuing a second invite always closed the first, so "cancel that one"
     * could be done - but only by leaving a fresh live code in a file on the
     * server that nobody meant to hand out, which is the opposite of what was
     * being asked for. This closes the row and deletes the file, and afterwards
     * the installation has no open invite at all.
     *
     * A code already sent to somebody stops working the moment this returns,
     * which is the entire point: it is what an administrator reaches for when
     * the invite went to the wrong address.
     */
    public static function revokeInvite(Request $request, ?Actor $actor): array
    {
        $me = self::admin($actor);

        $revoked = Invite::revoke();
        if ($revoked === null) {
            throw HttpException::notFound('There is no open invite to revoke.');
        }

        Audit::record(
            $me->username,
            'invite.revoke',
            (string)$revoked['role'],
            'issued by ' . (string)$revoked['issued_by'] . ', ' . (int)$revoked['uses']
                . ' of ' . (int)$revoked['max_uses'] . ' place(s) had been used'
        );

        // The status rather than nothing, so the screen that asked can redraw
        // from the server's answer instead of assuming what it now says.
        return ['invite' => Invite::status()];
    }

    /** @return array<string,mixed> */
    public static function audit(Request $request, ?Actor $actor): array
    {
        self::admin($actor);
        return ['entries' => Audit::recent($request->queryInt('limit', 300), $request->query('action'))];
    }

    /* -------------------------------------------------------------- helpers */

    private static function admin(?Actor $actor): Actor
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $me->requireAdmin();
        return $me;
    }

    /**
     * The account a route points at, by row id.
     *
     * The user name would read better in the URL, but it may hold a space, a
     * plus sign or an at sign, all of which mean something else by the time a
     * query string has been decoded. The id means exactly one thing.
     */
    private static function target(Request $request): string
    {
        $user = Users::byId($request->id('id'));
        if ($user === null) {
            throw HttpException::notFound('There is no such account.');
        }
        return (string)$user['username'];
    }

    /** Four groups of four from an unambiguous alphabet - long enough, typable. */
    private static function suggestPassword(): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $groups = [];
        for ($g = 0; $g < 4; $g++) {
            $chunk = '';
            for ($i = 0; $i < 4; $i++) {
                $chunk .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $groups[] = $chunk;
        }
        return implode('-', $groups);
    }
}
