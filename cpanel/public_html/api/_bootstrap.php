<?php
declare(strict_types=1);

$corePath = getenv('WOMANLAB_CORE_PATH') ?: dirname(__DIR__, 2) . '/womanlab-core';
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
    error_log('Database connection failed: ' . get_class($error));
    WomanLab\Support\ApiResponse::fail('service_unavailable', 503);
}