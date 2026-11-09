<?php
declare(strict_types=1);

$reference = $_GET['ref'] ?? $_GET['x_reference'] ?? '';
$reference = is_string($reference) && preg_match('/^ORD-[0-9]{8}-[A-F0-9]{8}$/D', $reference) ? $reference : '';
$basePath = dirname(dirname(parse_url($_SERVER['SCRIPT_NAME'] ?? '/', PHP_URL_PATH) ?: '/'));
$basePath = $basePath === '/' || $basePath === '.' ? '' : rtrim($basePath, '/');

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Pago cancelado | Woman Lab</title>
  <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/css/admin.css">
</head>
<body class="admin-auth-page">
  <main class="admin-auth-panel">
    <a class="admin-brand" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/">WOMAN <span>LAB</span></a>
    <h1>Pago cancelado</h1>
    <p>La compra no se completó. Puedes volver a la tienda y revisar tu carrito para intentarlo nuevamente.</p>
    <?php if ($reference !== ''): ?>
      <p>Referencia: <?= htmlspecialchars($reference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    <?php endif; ?>
    <a class="button-secondary" href="<?= htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>/#productos">Volver a la tienda</a>
  </main>
</body>
</html>