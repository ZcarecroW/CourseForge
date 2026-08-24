<?php
/**
 * CourseForge 3 – the single API front controller.
 *
 * Reading this file top to bottom gives you the complete HTTP surface of the
 * application: every route, the verbs it answers and which of them are
 * reachable without a session.
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use CourseForge\Api\ConfigController;
use CourseForge\Api\ConnectController;
use CourseForge\Api\PageController;
use CourseForge\Api\ProfileController;
use CourseForge\Api\ProjectController;
use CourseForge\Api\RunController;
use CourseForge\Api\PublishController;
use CourseForge\Api\SessionController;
use CourseForge\Api\TagController;
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
        return false; // @-suppressed or masked – keep the default behaviour
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

// Reachable while signed out.
$router->add('GET', 'session', [SessionController::class, 'show'], auth: false);
$router->add('POST', 'session', [SessionController::class, 'login'], auth: false);
$router->add('DELETE', 'session', [SessionController::class, 'logout'], auth: false);

$router->add('GET', 'config', [ConfigController::class, 'show']);
$router->add('POST', 'account/password', [SessionController::class, 'changePassword']);

$router->add('GET', 'profiles', [ProfileController::class, 'index']);
$router->add('POST', 'profiles', [ProfileController::class, 'create']);
$router->add('PUT', 'profiles/{id}', [ProfileController::class, 'update']);
$router->add('DELETE', 'profiles/{id}', [ProfileController::class, 'delete']);
$router->add('POST', 'profiles/{id}/models', [ProfileController::class, 'models']);
$router->add('POST', 'profiles/{id}/check', [ProfileController::class, 'check']);
$router->add('POST', 'profiles/{id}/shelves', [ProfileController::class, 'shelves']);

$router->add('GET', 'connect', [ConnectController::class, 'index']);
$router->add('POST', 'connect', [ConnectController::class, 'create']);
$router->add('DELETE', 'connect', [ConnectController::class, 'delete']);

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

$router->add('POST', 'projects/{id}/tags', [ProjectController::class, 'attachTag']);
$router->add('PUT', 'projects/{id}/tags', [ProjectController::class, 'updateTag']);
$router->add('DELETE', 'projects/{id}/tags', [ProjectController::class, 'detachTag']);

$router->add('PUT', 'projects/{id}/chapters/{chapterId}', [PageController::class, 'updateChapter']);
$router->add('GET', 'projects/{id}/pages/{pageId}', [PageController::class, 'show']);
$router->add('PUT', 'projects/{id}/pages/{pageId}', [PageController::class, 'update']);
$router->add('POST', 'projects/{id}/pages/{pageId}/generate', [PageController::class, 'generate']);

$router->add('GET', 'projects/{id}/runs', [RunController::class, 'index']);
$router->add('POST', 'projects/{id}/runs', [RunController::class, 'create']);
$router->add('PUT', 'projects/{id}/runs', [RunController::class, 'poll']);
$router->add('POST', 'projects/{id}/runs/cancel', [RunController::class, 'cancel']);
$router->add('DELETE', 'projects/{id}/runs', [RunController::class, 'delete']);

$router->add('POST', 'projects/{id}/push', [PublishController::class, 'push']);
$router->add('POST', 'projects/{id}/links', [PublishController::class, 'resolveLinks']);

/* ----------------------------------------------------------------- dispatch */
try {
    $request = Request::capture();

    if ($request->method === 'OPTIONS') {
        Response::send(['ok' => true], 200, ['Allow' => 'GET, POST, PUT, DELETE, OPTIONS']);
    }

    if ($request->method !== 'GET' && !Session::csrfValid($request->header('X-CSRF-Token'))) {
        // Ship the current token so the SPA can self-heal on its retry.
        Response::send([
            'ok' => false,
            'error' => 'Your security token was out of date – please try again.',
            'csrf' => Session::csrf(),
        ], 419);
    }

    $route = $router->match($request);
    $username = $route['auth'] ? Auth::requireUser() : (Auth::current()['username'] ?? '');

    Response::ok($route['handler']($route['request'], $username));
} catch (HttpException $e) {
    Response::fail($e->getMessage(), $e->status(), $e->extra());
} catch (RuntimeException $e) {
    // Domain errors that predate HttpException: "not found" is a 404, the rest a 400.
    Response::fail($e->getMessage(), stripos($e->getMessage(), 'not found') !== false ? 404 : 400);
} catch (Throwable $e) {
    Runtime::log('request', $e);

    $payload = ['ok' => false, 'error' => 'Unexpected server error. Please check the server log.'];
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
}
