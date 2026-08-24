<?php
/**
 * Router for PHP's built-in server, so `php -S` behaves like the .htaccess.
 *
 *     php -S 127.0.0.1:8080 -t . tools/router-dev.php
 *
 * Development only. It reproduces the three rules that matter: the private
 * directories are refused, /api/... reaches the front controller even without
 * a query string, and /mcp is an alias for the MCP front door.
 */
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = '/' . ltrim($path, '/');

if (preg_match('#^/(data|src|tools|config|tests)/#', $path)
    || preg_match('#\.(sqlite|sqlite3|sqlite-wal|sqlite-shm|db|db-wal|db-shm|json|md|log|ini|txt|zip|tar|gz|bak|sql|sh)$#', $path)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo '{"ok":false,"error":"Forbidden."}';
    return true;
}

if ($path === '/mcp' || $path === '/mcp/') {
    require __DIR__ . '/../api/mcp.php';
    return true;
}

if ($path === '/api/mcp.php') {
    require __DIR__ . '/../api/mcp.php';
    return true;
}

if (preg_match('#^/api/(.*)$#', $path, $m) && $m[1] !== 'index.php' && !str_ends_with($m[1], '.php')) {
    if ($m[1] !== '' && !isset($_GET['r'])) {
        $_GET['r'] = $m[1];
        $q = (string)($_SERVER["QUERY_STRING"] ?? ""); $_SERVER["QUERY_STRING"] = "r=" . rawurlencode($m[1]) . ($q !== "" ? "&" . $q : "");
    }
    require __DIR__ . '/../api/index.php';
    return true;
}

$file = __DIR__ . '/..' . rawurldecode($path);
if ($path !== '/' && is_file($file)) {
    return false; // let the built-in server serve it
}

if ($path === '/' || !is_file($file)) {
    require __DIR__ . '/../index.html';
    return true;
}

return false;
