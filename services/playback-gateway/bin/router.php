<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

$config = Delnavazan\Gateway\Config::fromEnvironment();
$app = new Delnavazan\Gateway\App($config, time());
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$body = file_get_contents('php://input');
try {
    $app->handle($method, $path, (string) ($_SERVER['HTTP_RANGE'] ?? ''), is_string($body) ? $body : '');
} catch (Throwable $error) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'playback_gateway_unavailable']);
}
