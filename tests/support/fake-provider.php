<?php
declare(strict_types=1);

/**
 * A scripted AI provider for `php -S`, so a provider class can be driven
 * through its real cURL path rather than around it.
 *
 * Everything it knows is in the directory named by FAKE_PROVIDER_DIR: each
 * request is written there as request-NNN.json for the test to read back, the
 * replies come from script.json in order (the last one repeats), and a GET of a
 * model list is answered from models.json without taking a turn. A reply with
 * `raw` is sent byte for byte, for the cases where `{}` versus `[]` is the point.
 */

// It writes files, so it is nothing at all to a web server that finds it. Only
// the test run starts it, under `php -S` and with this variable set.
if (PHP_SAPI !== 'cli-server' || getenv('FAKE_PROVIDER_DIR') === false) {
    http_response_code(404);
    exit;
}

$dir = (string)getenv('FAKE_PROVIDER_DIR');
$path = (string)parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && str_ends_with($path, '/models')) {
    echo (string)(@file_get_contents($dir . '/models.json') ?: '{"data":[],"has_more":false}');
    return;
}

$turn = count(glob($dir . '/request-*.json') ?: []);
file_put_contents(sprintf('%s/request-%03d.json', $dir, $turn), (string)json_encode([
    'path' => $path,
    'headers' => array_change_key_case(getallheaders(), CASE_LOWER),
    'raw' => (string)file_get_contents('php://input'),
]));

$script = json_decode((string)file_get_contents($dir . '/script.json'), true) ?: [];
$reply = $script[min($turn, count($script) - 1)] ?? ['status' => 500, 'body' => ['error' => 'no script']];

http_response_code((int)($reply['status'] ?? 200));
echo is_string($reply['raw'] ?? null) ? $reply['raw'] : (string)json_encode($reply['body'] ?? []);
