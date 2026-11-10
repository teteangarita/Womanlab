<?php
declare(strict_types=1);

$orderCode = $_GET['orden'] ?? $_GET['ref'] ?? $_GET['x_reference'] ?? '';
if (!is_string($orderCode) || !preg_match('/^ORD-[0-9]{8}-[A-F0-9]{8}$/D', $orderCode)) {
    http_response_code(400);
    exit('Pedido no válido.');
}

header('Cache-Control: no-store');
header('Location: resultado.php?codigo_orden=' . rawurlencode($orderCode), true, 303);
exit;