<?php
declare(strict_types=1);

namespace CourseForge\Api;

use CourseForge\Security\Actor;
use CourseForge\Support\Audit;
use CourseForge\Support\HttpException;
use CourseForge\Support\Request;
use CourseForge\Support\Runtime;
use CourseForge\Update\Updater;

/**
 * The Updates screen.
 *
 * Five endpoints for one button, which is roughly the ratio this feature needs.
 * "Update CourseForge" is a single click for the administrator and a sequence of
 * things that can each go wrong underneath, so the screen is given the whole
 * picture rather than a yes or a no: which version is installed, what GitHub
 * last said, every precondition with its own verdict, and the log of every
 * attempt that has ever been made on this installation.
 *
 * Two of these handlers replace the files that are executing them. That has one
 * visible consequence, and it is worth stating rather than papering over:
 * CF_VERSION is a constant, so a request that has just installed 4.1.0 is still
 * a 4.0.0 process until it ends. Neither install() nor rollback() therefore
 * recomputes the status afterwards - it would be read from a half-old process
 * and would say something untrue. They return what happened and the screen asks
 * again.
 */
final class UpdateController
{
    /**
     * Everything the screen needs: version, release, preconditions, settings,
     * schedule, backups.
     *
     * @return array<string,mixed>
     */
    public static function status(Request $request, ?Actor $actor): array
    {
        $me = self::admin($actor);

        // The cached path answers from the meta table, but the call after the
        // cache expires goes to GitHub and can take a moment. Releasing the
        // session lock first stops that blocking every other request this
        // administrator has open.
        Runtime::beginLongRequest();

        $status = Updater::status(false);

        // Recorded only when a fresh answer came back from GitHub. The screen
        // polls status(); this installation asking GitHub for a version is an
        // act worth a line, a screen being drawn is not, and a check that never
        // reached GitHub has nothing to report - the explicit check() below
        // records its failures, because somebody is standing there waiting.
        if ($status['cached'] === false) {
            Audit::record($me->username, 'update.check', (string)$status['repository'], 'automatic, cache expired');
        }

        return $status;
    }

    /**
     * Asks GitHub now, whatever the cache says.
     *
     * @return array<string,mixed>
     */
    public static function check(Request $request, ?Actor $actor): array
    {
        $me = self::admin($actor);
        Runtime::beginLongRequest();

        $status = Updater::status(true);
        Audit::record(
            $me->username,
            'update.check',
            (string)$status['repository'],
            $status['error'] !== ''
                ? 'failed: ' . (string)$status['error']
                : 'latest is ' . (string)($status['latest']['version'] ?? 'nothing published')
        );

        return $status;
    }

    /**
     * Installs the newest release.
     *
     * @return array<string,mixed>
     */
    public static function install(Request $request, ?Actor $actor): array
    {
        $me = self::admin($actor);

        // Before anything else: a download and a file-by-file swap is minutes of
        // work on a slow host, and a session lock held across it would freeze
        // the rest of the application for this account.
        Runtime::beginLongRequest();

        Audit::record($me->username, 'update.install.requested', '', 'from ' . CF_VERSION);
        $result = Updater::install($me->username, 'manual');

        return [
            'ok' => $result['ok'],
            'history' => $result['history'],
            'log' => $result['log'],
            'note' => $result['ok']
                ? 'The new files are in place. This request is still running ' . CF_VERSION
                    . ', so reload the screen to see the new version.'
                : 'The update did not finish. The log says why, and the previous version has been put back.',
        ];
    }

    /**
     * Puts the most recent backup back.
     *
     * @return array<string,mixed>
     */
    public static function rollback(Request $request, ?Actor $actor): array
    {
        $me = self::admin($actor);
        Runtime::beginLongRequest();

        Audit::record($me->username, 'update.rollback.requested', '', 'from ' . CF_VERSION);
        $result = Updater::rollback($me->username, 'manual');

        return [
            'ok' => $result['ok'],
            'history' => $result['history'],
            'log' => $result['log'],
            'note' => 'The backup has been restored. This request is still running ' . CF_VERSION
                . ', so reload the screen to see which version is now installed.',
        ];
    }

    /**
     * Every update this installation has attempted.
     *
     * @return array<string,mixed>
     */
    public static function history(Request $request, ?Actor $actor): array
    {
        $me = self::admin($actor);

        $rows = Updater::history($request->queryInt('limit', 25));
        Audit::record($me->username, 'update.history', '', count($rows) . ' row(s) read');

        return [
            'history' => $rows,
            'backups' => Updater::backups(),
            'running' => Updater::running(),
        ];
    }

    /* -------------------------------------------------------------- helpers */

    /**
     * The route table already refuses a non-administrator, and so does this.
     * A route table is a convenience, never the only thing in the way.
     */
    private static function admin(?Actor $actor): Actor
    {
        $me = $actor ?? throw HttpException::unauthorized();
        $me->requireAdmin();

        return $me;
    }
}
