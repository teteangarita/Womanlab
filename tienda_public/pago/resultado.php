<?php
declare(strict_types=1);

$basePath = dirname(dirname(parse_url($_SERVER['SCRIPT_NAME'] ?? '/', PHP_URL_PATH) ?: '/'));
$basePath = $basePath === '/' || $basePath === '.' ? '' : rtrim($basePath, '/');
$base = htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Estado del pago | Woman Lab</title>
  <link rel="stylesheet" href="<?= $base ?>/css/admin.css">
</head>
<body class="admin-auth-page">
  <main class="admin-auth-panel">
    <a class="admin-brand" href="<?= $base ?>/">WOMAN <span>LAB</span></a>
    <h1 id="paymentTitle">Verificando tu pago</h1>
    <p id="paymentMessage" role="status" aria-live="polite">Estamos confirmando el estado de la transacción con TUU.</p>
    <p id="paymentDetails"></p>
    <a class="button-secondary" href="<?= $base ?>/">Volver a Woman Lab</a>
  </main>
  <script src="<?= $base ?>/js/payment-result.js" defer></script>
</body>
</html>