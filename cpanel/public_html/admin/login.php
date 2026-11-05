<?php
declare(strict_types=1);

$corePath = getenv('WOMANLAB_CORE_PATH') ?: dirname(__DIR__, 2) . '/womanlab-core';
require_once $corePath . '/config/bootstrap.php';
$user = WomanLab\Support\Auth::user();
if ($user && $user['rol'] === 'admin') {
    header('Location: /admin/');
    exit;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Administración | Woman Lab</title>
  <link rel="stylesheet" href="/css/admin.css">
</head>
<body class="admin-auth-page">
  <main class="admin-auth-panel">
    <a class="admin-brand" href="/">WOMAN <span>LAB</span></a>
    <h1>Administración</h1>
    <form id="adminLoginForm">
      <label for="adminEmail">Correo</label>
      <input id="adminEmail" name="email" type="email" autocomplete="username" required>
      <label for="adminPassword">Contraseña</label>
      <input id="adminPassword" name="password" type="password" autocomplete="current-password" required>
      <p id="loginMessage" role="status" aria-live="polite"></p>
      <button type="submit">Ingresar</button>
    </form>
  </main>
  <script src="/js/admin-login.js" defer></script>
</body>
</html>