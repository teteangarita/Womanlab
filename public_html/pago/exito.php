<?php
declare(strict_types=1);

$reference = $_GET['ref'] ?? $_GET['x_reference'] ?? '';
if (!is_string($reference) || !preg_match('/^ORD-[0-9]{8}-[A-F0-9]{8}$/D', $reference)) {
    http_response_code(400);
    exit('Referencia de pago no válida.');
}

$basePath = dirname(dirname(parse_url($_SERVER['SCRIPT_NAME'] ?? '/', PHP_URL_PATH) ?: '/'));
$basePath = $basePath === '/' || $basePath === '.' ? '' : rtrim($basePath, '/');
header('Cache-Control: no-store');
header('Location: ' . $basePath . '/pago/resultado.php?codigo_orden=' . rawurlencode($reference), true, 303);
exit;