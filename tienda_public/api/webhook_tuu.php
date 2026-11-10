<?php
declare(strict_types=1);

// TEMPORAL: registra la llamada antes del bootstrap para detectar fallos de DB/autoload.
@file_put_contents((require __DIR__ . '/_core_path.php') . '/logs/webhook_tuu.log',
    date('c') . ' [WEBHOOK 0 llamada al endpoint] ' . ($_SERVER['REQUEST_METHOD'] ?? '?') . ' ' . ($_SERVER['REQUEST_URI'] ?? '')
    . ' ip=' . ($_SERVER['REMOTE_ADDR'] ?? '?') . ' ct=' . ($_SERVER['CONTENT_TYPE'] ?? '')
    . ' ua=' . ($_SERVER['HTTP_USER_AGENT'] ?? '') . PHP_EOL, FILE_APPEND | LOCK_EX);

// Endpoint server-to-server sin estado: el bootstrap no inicia sesión ni envía cookies.
define('WOMANLAB_STATELESS', true);
$db = require __DIR__ . '/_bootstrap.php';
WomanLab\Controllers\WebhookController::handle($db);