<?php
declare(strict_types=1);

$corePath = require __DIR__ . '/_core_path.php';
if (!is_file($corePath . '/config/bootstrap.php')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"error":"server_configuration_error"}';
    exit;
}
require_once $corePath . '/config/bootstrap.php';

try {
    return WomanLab\Support\Database::connection();
} catch (Throwable $error) {
    $code = $error instanceof PDOException ? (string) ($error->errorInfo[1] ?? $error->getCode()) : '';
    error_log('Database connection failed: ' . get_class($error) . ($code !== '' ? ' (code ' . $code . ')' : ''));
    WomanLab\Support\ApiResponse::fail('service_unavailable', 503);
}