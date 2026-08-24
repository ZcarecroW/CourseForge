<?php
declare(strict_types=1);

namespace CourseForge\Api;

use CourseForge\Security\Actor;
use CourseForge\Security\Auth;
use CourseForge\Security\Invite;
use CourseForge\Security\Session;
use CourseForge\Security\Users;
use CourseForge\Support\Audit;
use CourseForge\Support\Config;
use CourseForge\Support\HttpException;
use CourseForge\Support\Request;

/**
 * The first-run screen.
 *
 * A brand-new installation has no accounts, so there is nobody who could be
 * asked to authorise the creation of the first one. What stands in for that is
 * the invite code in `INVITE-CODE.txt`: a file written next to `index.html`
 * the first time CourseForge is opened, readable by whoever installed it and by
 * nobody on the internet.
 *
 * Both endpoints are reachable while signed out, and both stop existing the
 * moment an account exists - `status` reports `needs_setup: false` and `create`
 * refuses outright, so there is no second chance to slip in through this door.
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
                'This CourseForge already has accounts. Ask an administrator to create one for you.'
            );
        }

        $invite = Invite::verify($request->str('invite_code'));

        $username = $request->requiredStr('username', 'A user name');
        $password = $request->raw('password');
        $confirm = $request->raw('password_confirm');

        if ($confirm !== '' && $confirm !== $password) {
            throw HttpException::unprocessable('The two passwords do not match.');
        }

        $user = Users::create(
            $username,
            $password,
            Actor::ROLE_ADMIN,
            $request->str('display_name'),
            'setup',
        );

        Invite::consume($invite, (string)$user['username']);
        Audit::record((string)$user['username'], 'setup.complete', (string)$user['username'], 'first administrator created');

        Auth::establish((string)$user['username']);

        return ['user' => Auth::describe(), 'csrf' => Session::csrf()];
    }
}
