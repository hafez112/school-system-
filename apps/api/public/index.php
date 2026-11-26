<?php

declare(strict_types=1);

use SchoolSystem\Auth\AuthService;
use SchoolSystem\Auth\JwtTokens;
use SchoolSystem\Infrastructure\Database;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Logger.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$debug = filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOL);
$logger = new Logger('/var/www/storage/logs/app.log');

$respond = static function (int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

try {
    $db = Database::connect();
    $jwt = new JwtTokens();
    $auth = new AuthService($db, $jwt);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    if ($method === 'GET' && $path === '/health') {
        $respond(200, ['success' => true, 'data' => ['status' => 'ok', 'service' => 'school-system-api']]);
    }

    if ($method === 'POST' && $path === '/api/v1/auth/login') {
        $input = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($input) || !filter_var($input['email'] ?? null, FILTER_VALIDATE_EMAIL) || !is_string($input['password'] ?? null)) {
            $respond(422, ['success' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'A valid email and password are required.']]);
        }
        $respond(200, ['success' => true, 'data' => $auth->login($input['email'], $input['password'], $ip, $agent)]);
    }

    if ($method === 'POST' && $path === '/api/v1/auth/refresh') {
        $input = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($input) || !is_string($input['refresh_token'] ?? null)) {
            $respond(422, ['success' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'refresh_token is required.']]);
        }
        $respond(200, ['success' => true, 'data' => $auth->refresh($input['refresh_token'], $ip, $agent)]);
    }

    if ($method === 'POST' && in_array($path, ['/api/v1/auth/logout', '/api/v1/auth/me'], true)) {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            $respond(401, ['success' => false, 'error' => ['code' => 'UNAUTHENTICATED', 'message' => 'A Bearer access token is required.']]);
        }
        $claims = $jwt->decode($matches[1]);
        $userId = (int) $claims->sub;
        if ($path === '/api/v1/auth/me' && $method === 'POST') {
            $stmt = $db->prepare('SELECT id, email, full_name, is_active FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            if (!$user || !(bool) $user['is_active']) $respond(401, ['success' => false, 'error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Account is unavailable.']]);
            [$roles, $permissions] = $auth->accessFor($userId);
            $respond(200, ['success' => true, 'data' => ['user' => $user, 'roles' => $roles, 'permissions' => $permissions]]);
        }
        $input = json_decode(file_get_contents('php://input') ?: '', true);
        if (is_array($input) && is_string($input['refresh_token'] ?? null)) $auth->logout($input['refresh_token'], $userId, $ip);
        $respond(200, ['success' => true, 'data' => ['message' => 'Logged out.']]);
    }

    $respond(404, ['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Endpoint not found.']]);
} catch (RuntimeException $e) {
    $logger->warning($e->getMessage(), ['path' => $_SERVER['REQUEST_URI'] ?? '/']);
    $status = str_contains(strtolower($e->getMessage()), 'invalid email') || str_contains(strtolower($e->getMessage()), 'refresh token') ? 401 : 400;
    $respond($status, ['success' => false, 'error' => ['code' => $status === 401 ? 'UNAUTHENTICATED' : 'REQUEST_ERROR', 'message' => $debug ? $e->getMessage() : ($status === 401 ? 'Authentication failed.' : 'The request could not be completed.')]]);
} catch (Throwable $e) {
    $logger->error($e->getMessage(), ['exception' => $e::class, 'file' => $e->getFile(), 'line' => $e->getLine()]);
    $respond(500, ['success' => false, 'error' => ['code' => 'INTERNAL_SERVER_ERROR', 'message' => $debug ? $e->getMessage() : 'An unexpected error occurred.']]);
}
