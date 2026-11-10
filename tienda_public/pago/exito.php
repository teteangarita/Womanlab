<?php
declare(strict_types=1);

$reference = $_GET['x_reference'] ?? $_GET['ref'] ?? '';
if (!is_string($reference) || !preg_match('/^ORD-[0-9]{8}-[A-F0-9]{8}$/D', $reference)) {
    http_response_code(400);
    exit('Referencia de pago no válida.');
}

$basePath = dirname(dirname(parse_url($_SERVER['SCRIPT_NAME'] ?? '/', PHP_URL_PATH) ?: '/'));
$basePath = $basePath === '/' || $basePath === '.' ? '' : rtrim($basePath, '/');

if (isset($_GET['x_reference'], $_GET['x_result'], $_GET['x_signature']) && $_GET['x_result'] === 'completed') {
    // Respaldo del callback POST: el retorno firmado confirma la orden con la misma lógica idempotente.
    try {
        $corePath = require __DIR__ . '/../api/_core_path.php';
        require_once $corePath . '/config/bootstrap.php';
        $secret = \WomanLab\Support\TuuConfig::secretKey();
        $account = \WomanLab\Support\TuuConfig::get('TUU_ACCOUNT_ID')['value'] ?? '';
        if ($secret !== '' && $account !== '') {
            [$result, $status] = (new \WomanLab\Services\TuuCallback(\WomanLab\Support\Database::connection()))
                ->handleSigned(array_filter($_GET, 'is_string'), $secret, $account);
            \WomanLab\Support\DebugLog::write('RETORNO exito.php', ['x_reference' => $reference, 'result' => $result, 'status' => $status]);
        }
    } catch (Throwable $error) {
        error_log('TUU return confirmation failed: ' . get_class($error));
    }
}
header('Cache-Control: no-store');
header('Location: ' . $basePath . '/pago/resultado.php?codigo_orden=' . rawurlencode($reference), true, 303);
exit;