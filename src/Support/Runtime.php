<?php
declare(strict_types=1);

namespace CourseForge\Support;

use CourseForge\Security\Session;

/** Process-level switches for the endpoints that talk to slow third parties. */
final class Runtime
{
    private static bool $long = false;

    /**
     * Marks the current request as long-running.
     *
     *  - releases the session lock  → concurrent generations really run in parallel
     *  - removes the PHP time limit → a generation may take many minutes
     *  - keeps running on abort     → the result is stored even if the tab closes
     *
     * Must be called AFTER the CSRF and auth checks and BEFORE any $_SESSION write.
     */
    public static function beginLongRequest(): void
    {
        if (self::$long) {
            return;
        }
        self::$long = true;

        Session::release();
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('default_socket_timeout', '-1');
        ignore_user_abort(true);
    }

    public static function isLongRequest(): bool
    {
        return self::$long;
    }

    public static function debug(): bool
    {
        try {
            return Config::bool('app.debug');
        } catch (\Throwable) {
            return false;
        }
    }

    public static function log(string $context, \Throwable $e): void
    {
        error_log(sprintf(
            '[CourseForge][%s] %s: %s in %s:%d',
            $context,
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }
}
