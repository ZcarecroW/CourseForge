<?php
/**
 * CourseForge 3 – bootstrap.
 *
 * Defines the two paths the whole application is anchored on and registers a
 * PSR-4 style autoloader for the `CourseForge\` namespace. No Composer, no
 * generated files: every class lives at src/<Namespace>/<Class>.php.
 */
declare(strict_types=1);

const CF_VERSION = '3.2.0';

/** Absolute path of the installation root (the folder holding index.html). */
define('CF_ROOT', dirname(__DIR__));

/**
 * Writable directory holding config.json, users.json and app.sqlite.
 * Set COURSEFORGE_DATA_DIR to move it outside the document root.
 */
define('CF_DATA', rtrim((string)(getenv('COURSEFORGE_DATA_DIR') ?: CF_ROOT . '/data'), "/\\"));

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'CourseForge\\')) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen('CourseForge\\')));
    $file = __DIR__ . '/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');
