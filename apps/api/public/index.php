<?php

declare(strict_types=1);

use SchoolSystem\Auth\AuthService;
use SchoolSystem\Auth\JwtTokens;
use SchoolSystem\Infrastructure\Database;
use SchoolSystem\Schools\SchoolService;

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
        $claims = bearerClaims($_SERVER['HTTP_AUTHORIZATION'] ?? '', $jwt, $respond);
        $userId = (int) $claims->sub;
        if ($path === '/api/v1/auth/me') {
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

    if (str_starts_with($path, '/api/v1/schools')) {
        $claims = bearerClaims($_SERVER['HTTP_AUTHORIZATION'] ?? '', $jwt, $respond);
        $userId = (int) $claims->sub;
        [$roles, $permissions] = $auth->accessFor($userId);
        $schools = new SchoolService($db, $userId, $roles, $permissions);
        $input = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($input)) $input = [];
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $part): bool => $part !== ''));
        $limit = min(100, max(1, (int) ($_GET['limit'] ?? 50)));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));

        if (count($segments) === 3 && $segments[2] === 'schools' && $method === 'GET') {
            $respond(200, ['success' => true, 'data' => $schools->listSchools($limit, $offset)]);
        }
        if (count($segments) === 3 && $segments[2] === 'schools' && $method === 'POST') {
            $respond(201, ['success' => true, 'data' => $schools->createSchool($input, $ip)]);
        }
        if (count($segments) === 4 && $segments[2] === 'schools' && ctype_digit($segments[3])) {
            $schoolId = (int) $segments[3];
            if ($method === 'GET') $respond(200, ['success' => true, 'data' => $schools->getSchool($schoolId)]);
            if ($method === 'PATCH') $respond(200, ['success' => true, 'data' => $schools->updateSchool($schoolId, $input, $ip)]);
            if ($method === 'DELETE') {
                $schools->archiveSchool($schoolId, $ip);
                $respond(200, ['success' => true, 'data' => ['message' => 'School archived.']]);
            }
        }
        if (count($segments) >= 5 && $segments[2] === 'schools' && ctype_digit($segments[3]) && $segments[4] === 'branches') {
            $schoolId = (int) $segments[3];
            if (count($segments) === 5 && $method === 'GET') $respond(200, ['success' => true, 'data' => $schools->listBranches($schoolId, $limit, $offset)]);
            if (count($segments) === 5 && $method === 'POST') $respond(201, ['success' => true, 'data' => $schools->createBranch($schoolId, $input, $ip)]);
            if (count($segments) === 6 && ctype_digit($segments[5])) {
                $branchId = (int) $segments[5];
                if ($method === 'GET') $respond(200, ['success' => true, 'data' => $schools->getBranch($schoolId, $branchId)]);
                if ($method === 'PATCH') $respond(200, ['success' => true, 'data' => $schools->updateBranch($schoolId, $branchId, $input, $ip)]);
                if ($method === 'DELETE') {
                    $schools->archiveBranch($schoolId, $branchId, $ip);
                    $respond(200, ['success' => true, 'data' => ['message' => 'Branch archived.']]);
                }
            }
        }
    }

    $respond(404, ['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Endpoint not found.']]);
} catch (RuntimeException $e) {
    $logger->warning($e->getMessage(), ['path' => $_SERVER['REQUEST_URI'] ?? '/']);
    $message = strtolower($e->getMessage());
    $status = str_contains($message, 'forbidden') ? 403 : (str_contains($message, 'not found') ? 404 : (str_contains($message, 'invalid email') || str_contains($message, 'refresh token') || str_contains($message, 'unauthenticated') ? 401 : 422));
    $respond($status, ['success' => false, 'error' => ['code' => match ($status) { 401 => 'UNAUTHENTICATED', 403 => 'FORBIDDEN', 404 => 'NOT_FOUND', default => 'VALIDATION_ERROR' }, 'message' => $debug ? $e->getMessage() : match ($status) { 401 => 'Authentication failed.', 403 => 'You do not have permission to perform this action.', 404 => 'Resource not found.', default => 'The request could not be completed.' }]]);
} catch (Throwable $e) {
    $logger->error($e->getMessage(), ['exception' => $e::class, 'file' => $e->getFile(), 'line' => $e->getLine()]);
    $respond(500, ['success' => false, 'error' => ['code' => 'INTERNAL_SERVER_ERROR', 'message' => $debug ? $e->getMessage() : 'An unexpected error occurred.']]);
}

function bearerClaims(string $header, JwtTokens $jwt, callable $respond): object
{
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        $respond(401, ['success' => false, 'error' => ['code' => 'UNAUTHENTICATED', 'message' => 'A Bearer access token is required.']]);
    }
    return $jwt->decode($matches[1]);
}
