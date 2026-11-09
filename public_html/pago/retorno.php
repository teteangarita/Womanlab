<?php
declare(strict_types=1);

$orderCode = $_GET['orden'] ?? '';
if (!is_string($orderCode) || !preg_match('/^[a-f0-9-]{36}$/i', $orderCode)) {
    http_response_code(400);
    exit('Pedido no válido.');
}

header('Cache-Control: no-store');
header('Location: resultado.php?codigo_orden=' . rawurlencode($orderCode), true, 303);
exit;