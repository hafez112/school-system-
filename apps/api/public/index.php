<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Logger.php';

header('Content-Type: application/json; charset=utf-8');

$debug = filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOL);
$logger = new Logger('/var/www/storage/logs/app.log');

set_exception_handler(function (Throwable $exception) use ($debug, $logger): void {
    $logger->error($exception->getMessage(), [
        'exception' => $exception::class,
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
    ]);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'INTERNAL_SERVER_ERROR',
            'message' => $debug ? $exception->getMessage() : 'An unexpected error occurred.',
        ],
    ], JSON_UNESCAPED_UNICODE);
});

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$logger->info('Request received', [
    'method' => $requestMethod,
    'uri' => $requestUri,
]);

if ($requestUri === '/health') {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => [
            'status' => 'ok',
            'service' => 'school-system-api',
            'environment' => getenv('APP_ENV') ?: 'local',
        ],
    ], JSON_UNESCAPED_UNICODE);
    return;
}

http_response_code(404);
echo json_encode([
    'success' => false,
    'error' => [
        'code' => 'NOT_FOUND',
        'message' => 'Endpoint not found.',
    ],
], JSON_UNESCAPED_UNICODE);
