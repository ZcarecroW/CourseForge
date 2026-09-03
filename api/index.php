<?php
/**
 * CourseForge 4 - the single API front controller.
 *
 * Reading this file top to bottom gives you the complete HTTP surface of the
 * application: every route, the verbs it answers, which of them are reachable
 * without a session, and which need an administrator.
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use CourseForge\Api\BookStackDevController;
use CourseForge\Api\ConfigController;
use CourseForge\Api\ConnectController;
use CourseForge\Api\PageController;
use CourseForge\Api\ProfileController;
use CourseForge\Api\ProjectController;
use CourseForge\Api\PublishController;
use CourseForge\Api\RunController;
use CourseForge\Api\SecurityController;
use CourseForge\Api\SessionController;
use CourseForge\Api\SettingsController;
use CourseForge\Api\TaskController;
use CourseForge\Api\SetupController;
use CourseForge\Api\TagController;
use CourseForge\Api\UpdateController;
use CourseForge\Api\UserController;
use CourseForge\Security\Auth;
use CourseForge\Security\Session;
use CourseForge\Support\HttpException;
use CourseForge\Support\Request;
use CourseForge\Support\Response;
use CourseForge\Support\Router;
use CourseForge\Support\Runtime;

/* ------------------------------------------------------------------ runtime
 * Never leak HTML or notices into a JSON body: warnings become exceptions and
 * end up in the one error handler at the bottom of this file. */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false; // @-suppressed or masked - keep the default behaviour
    }
    if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
        error_log(sprintf('[CourseForge][deprecated] %s in %s:%d', $message, $file, $line));
        return true;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

/* The boot phase itself must answer with JSON, never with a blank 500. */
try {
    Session::boot();
} catch (Throwable $e) {
    Runtime::log('boot', $e);
    Response::send(['ok' => false, 'error' => 'The server is not configured correctly (see the server log).'], 500);
}

/* ------------------------------------------------------------------- routes */
$router = new Router();

// Reachable while signed out. `setup` exists only until the first account does;
// `redeem` is how an invite issued after that becomes the account it promises,
// with the role written on the invite and no session to authorise it.
$router->add('GET', 'setup', [SetupController::class, 'status'], auth: false);
$router->add('POST', 'setup', [SetupController::class, 'create'], auth: false);
$router->add('POST', 'redeem', [SetupController::class, 'redeem'], auth: false);

$router->add('GET', 'session', [SessionController::class, 'show'], auth: false);
$router->add('POST', 'session', [SessionController::class, 'login'], auth: false);
$router->add('DELETE', 'session', [SessionController::class, 'logout'], auth: false);

// Any signed-in account.
$router->add('GET', 'config', [ConfigController::class, 'show']);
$router->add('PUT', 'account', [SessionController::class, 'updateProfile']);
$router->add('POST', 'account/password', [SessionController::class, 'changePassword']);
$router->add('POST', 'account/tour', [SessionController::class, 'tourSeen']);

$router->add('GET', 'profiles', [ProfileController::class, 'index']);
$router->add('POST', 'profiles', [ProfileController::class, 'create']);
$router->add('PUT', 'profiles/{id}', [ProfileController::class, 'update']);
$router->add('DELETE', 'profiles/{id}', [ProfileController::class, 'delete']);
$router->add('POST', 'profiles/{id}/models', [ProfileController::class, 'models']);
$router->add('POST', 'profiles/{id}/check', [ProfileController::class, 'check']);
$router->add('POST', 'profiles/{id}/shelves', [ProfileController::class, 'shelves']);

// The looks a BookStack instance wears, and the link that puts one on.
$router->add('GET', 'bookstackdev', [BookStackDevController::class, 'index']);
$router->add('GET', 'bookstackdev/audit', [BookStackDevController::class, 'audit']);
$router->add('POST', 'bookstackdev', [BookStackDevController::class, 'create']);
$router->add('PUT', 'bookstackdev/{id}', [BookStackDevController::class, 'update']);
$router->add('DELETE', 'bookstackdev/{id}', [BookStackDevController::class, 'delete']);
$router->add('POST', 'bookstackdev/{id}/key', [BookStackDevController::class, 'rotateKey']);

$router->add('GET', 'connect', [ConnectController::class, 'index']);
$router->add('POST', 'connect', [ConnectController::class, 'create']);
$router->add('PUT', 'connect/{id}', [ConnectController::class, 'update']);
$router->add('DELETE', 'connect', [ConnectController::class, 'delete']);
$router->add('DELETE', 'connect/{id}', [ConnectController::class, 'delete']);

$router->add('GET', 'tags', [TagController::class, 'index']);
$router->add('POST', 'tags', [TagController::class, 'create']);
$router->add('PUT', 'tags/{id}', [TagController::class, 'update']);
$router->add('DELETE', 'tags/{id}', [TagController::class, 'delete']);

$router->add('GET', 'projects', [ProjectController::class, 'index']);
$router->add('POST', 'projects', [ProjectController::class, 'create']);
$router->add('GET', 'projects/{id}', [ProjectController::class, 'show']);
$router->add('PUT', 'projects/{id}', [ProjectController::class, 'update']);
$router->add('DELETE', 'projects/{id}', [ProjectController::class, 'delete']);

$router->add('POST', 'projects/{id}/structure', [ProjectController::class, 'generateStructure']);
$router->add('PUT', 'projects/{id}/structure', [ProjectController::class, 'applyStructure']);
$router->add('PUT', 'projects/{id}/details', [ProjectController::class, 'updateDetails']);
$router->add('POST', 'projects/{id}/typography', [ProjectController::class, 'typography']);
$router->add('PUT', 'projects/{id}/research', [ProjectController::class, 'updateResearch']);
$router->add('POST', 'projects/{id}/transfer', [ProjectController::class, 'transfer'], admin: true);

$router->add('POST', 'projects/{id}/tags', [ProjectController::class, 'attachTag']);
$router->add('PUT', 'projects/{id}/tags', [ProjectController::class, 'updateTag']);
$router->add('DELETE', 'projects/{id}/tags', [ProjectController::class, 'detachTag']);

$router->add('PUT', 'projects/{id}/chapters/{chapterId}', [PageController::class, 'updateChapter']);
$router->add('GET', 'projects/{id}/pages/{pageId}', [PageController::class, 'show']);
$router->add('PUT', 'projects/{id}/pages/{pageId}', [PageController::class, 'update']);
$router->add('POST', 'projects/{id}/pages/{pageId}/generate', [PageController::class, 'generate']);

$router->add('GET', 'runs', [RunController::class, 'all']);
$router->add('GET', 'projects/{id}/runs', [RunController::class, 'index']);
$router->add('POST', 'projects/{id}/runs', [RunController::class, 'create']);
$router->add('PUT', 'projects/{id}/runs', [RunController::class, 'poll']);
$router->add('POST', 'projects/{id}/runs/estimate', [RunController::class, 'estimate']);
$router->add('POST', 'projects/{id}/runs/cancel', [RunController::class, 'cancel']);
$router->add('DELETE', 'projects/{id}/runs', [RunController::class, 'delete']);

$router->add('PUT', 'projects/{id}/targets', [PublishController::class, 'saveTargets']);
$router->add('POST', 'projects/{id}/push', [PublishController::class, 'push']);
$router->add('POST', 'projects/{id}/links', [PublishController::class, 'resolveLinks']);

// The tasks the scheduler works for a course - a publish, a link pass - and
// the log they leave. `run` is the browser working one slice itself, for an
// installation whose scheduler is not calling in.
$router->add('GET', 'projects/{id}/tasks', [TaskController::class, 'index']);
$router->add('POST', 'projects/{id}/tasks', [TaskController::class, 'create']);
$router->add('DELETE', 'projects/{id}/tasks', [TaskController::class, 'clear']);
$router->add('GET', 'projects/{id}/tasks/{taskId}', [TaskController::class, 'show']);
$router->add('DELETE', 'projects/{id}/tasks/{taskId}', [TaskController::class, 'delete']);
$router->add('POST', 'projects/{id}/tasks/{taskId}/cancel', [TaskController::class, 'cancel']);
$router->add('POST', 'projects/{id}/tasks/{taskId}/retry', [TaskController::class, 'retry']);
$router->add('POST', 'projects/{id}/tasks/{taskId}/run', [TaskController::class, 'run']);

// Administrators only. Every handler checks the role again for itself.
$router->add('GET', 'admin/users', [UserController::class, 'index'], admin: true);
$router->add('POST', 'admin/users', [UserController::class, 'create'], admin: true);
$router->add('PUT', 'admin/users/{id}', [UserController::class, 'update'], admin: true);
$router->add('DELETE', 'admin/users/{id}', [UserController::class, 'delete'], admin: true);
$router->add('POST', 'admin/invite', [UserController::class, 'invite'], admin: true);
$router->add('DELETE', 'admin/invite', [UserController::class, 'revokeInvite'], admin: true);
$router->add('DELETE', 'admin/invite/{id}', [UserController::class, 'revokeInvite'], admin: true);
$router->add('GET', 'admin/audit', [UserController::class, 'audit'], admin: true);

$router->add('GET', 'admin/settings', [SettingsController::class, 'show'], admin: true);
$router->add('PUT', 'admin/settings', [SettingsController::class, 'update'], admin: true);
$router->add('POST', 'admin/settings/reset', [SettingsController::class, 'reset'], admin: true);
$router->add('POST', 'admin/settings/cron-token', [SettingsController::class, 'cronToken'], admin: true);
$router->add('POST', 'admin/settings/cron-url', [SettingsController::class, 'cronUrl'], admin: true);
$router->add('POST', 'admin/settings/php', [SettingsController::class, 'setUpPhp'], admin: true);
$router->add('GET', 'admin/prompts', [SettingsController::class, 'prompts'], admin: true);
$router->add('PUT', 'admin/prompts', [SettingsController::class, 'savePrompts'], admin: true);
$router->add('GET', 'admin/diagnostics', [SettingsController::class, 'diagnostics'], admin: true);

// Whether this server keeps the data directory private, and the one way past
// the lock that holds until it does.
$router->add('GET', 'admin/security', [SecurityController::class, 'show'], admin: true);
$router->add('POST', 'admin/security/check', [SecurityController::class, 'check'], admin: true);
$router->add('POST', 'admin/security/acknowledge', [SecurityController::class, 'acknowledge'], admin: true);
$router->add('DELETE', 'admin/security/acknowledge', [SecurityController::class, 'revoke'], admin: true);

$router->add('GET', 'admin/update', [UpdateController::class, 'status'], admin: true);
$router->add('POST', 'admin/update/check', [UpdateController::class, 'check'], admin: true);
$router->add('POST', 'admin/update/install', [UpdateController::class, 'install'], admin: true);
$router->add('POST', 'admin/update/rollback', [UpdateController::class, 'rollback'], admin: true);
$router->add('GET', 'admin/update/history', [UpdateController::class, 'history'], admin: true);

/* ----------------------------------------------------------------- dispatch */

/**
 * The routes an account that owes a password change may still reach: who am I,
 * choose the password, and sign out - plus the setup screen, which belongs to
 * an installation with no accounts at all and so cannot be owing anything.
 */
const PASSWORD_CHANGE_ROUTES = ['session', 'setup', 'account/password'];

/**
 * A bug, or something broken underneath the application: written to the server
 * log in full and answered with as little as possible, because the client can
 * do nothing with a stack trace except learn from it. app.debug puts the detail
 * in the body as well, for an installation being worked on.
 */
$unexpected = static function (Throwable $e, string $message): never {
    Runtime::log('request', $e);

    $payload = ['ok' => false, 'error' => $message];
    if (Runtime::debug()) {
        $payload['error'] = $e::class . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        $payload['detail'] = [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 12),
        ];
    }
    Response::send($payload, 500);
};

try {
    $request = Request::capture();

    if ($request->method === 'OPTIONS') {
        Response::send(['ok' => true], 200, ['Allow' => 'GET, POST, PUT, DELETE, OPTIONS']);
    }

    if ($request->method !== 'GET' && !Session::csrfValid($request->header('X-CSRF-Token'))) {
        // Ship the current token so the SPA can self-heal on its retry.
        Response::send([
            'ok' => false,
            'error' => 'Your security token was out of date - please try again.',
            'csrf' => Session::csrf(),
        ], 419);
    }

    $route = $router->match($request);

    $actor = $route['auth'] ? Auth::require() : Auth::current();
    if ($route['admin']) {
        $actor?->requireAdmin();
    }

    /* An account holding a password an administrator chose for it may do three
     * things: find out who it is, replace that password, and sign out. The rule
     * is stated in the interface ("before it can do anything else") and used to
     * be enforced by a dialog the Vue app refused to close, which curl and any
     * MCP client walked straight past - including to mint a connection token
     * that outlived the password it was owed. It is a rule, so it lives with
     * the other checks a request has to pass. */
    if ($actor !== null
        && !in_array($request->path, PASSWORD_CHANGE_ROUTES, true)
        && Auth::passwordChangeDue($actor)) {
        throw new HttpException(
            'Choose a new password before you use the rest of CourseForge.',
            403,
            ['must_change_password' => true]
        );
    }

    Response::ok($route['handler']($route['request'], $actor));
} catch (HttpException $e) {
    Response::fail($e->getMessage(), $e->status(), $e->extra());
} catch (PDOException $e) {
    /* PDOException extends RuntimeException, so this has to come first or the
     * branch below answers it. A database that is locked, read-only or missing
     * a table is not the caller's mistake and its driver message is not the
     * caller's business: it goes to the log the 500 tells the operator to read,
     * and 5xx is what monitoring watches for. */
    $unexpected($e, 'The database could not be read or written. Please check the server log.');
} catch (RuntimeException $e) {
    // Domain errors that predate HttpException: "not found" is a 404, the rest a 400.
    // A message naming a path on disk is a message for the log, not the client.
    $message = $e->getMessage();
    if (str_contains($message, CF_ROOT) || str_contains($message, CF_DATA)
        || str_contains($message, str_replace('\\', '/', CF_ROOT))) {
        Runtime::log('request', $e);
        $message = 'The server could not read or write one of its files. Please check the server log.';
    }
    Response::fail($message, stripos($message, 'not found') !== false ? 404 : 400);
} catch (Throwable $e) {
    $unexpected($e, 'Unexpected server error. Please check the server log.');
}
