<?php
declare(strict_types=1);

$corePath = require dirname(__DIR__) . '/api/_core_path.php';
require_once $corePath . '/config/bootstrap.php';
$basePath = womanlab_base_path();
$user = WomanLab\Support\Auth::user();
if (!$user || $user['rol'] !== 'admin') {
    header('Location: ' . $basePath . '/admin/login.php');
    exit;
}
require $corePath . '/templates/admin/dashboard.php';