<?php
declare(strict_types=1);

$db = require __DIR__ . '/_bootstrap.php';
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    WomanLab\Support\ApiResponse::fail('method_not_allowed', 405);
}
$code = (string) ($_GET['codigo_orden'] ?? '');
if (!preg_match('/^ORD-[0-9]{8}-[A-F0-9]{8}$/D', $code)) {
    WomanLab\Support\ApiResponse::fail('invalid_order_code', 400);
}
$query = $db->prepare('SELECT codigo_orden, monto_total, estado, actualizado_en FROM ordenes WHERE codigo_orden = ? LIMIT 1');
$query->execute([$code]);
$order = $query->fetch();
if (!$order) {
    WomanLab\Support\ApiResponse::fail('order_not_found', 404);
}
WomanLab\Support\ApiResponse::json($order);