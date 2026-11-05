<?php
declare(strict_types=1);

use Dotenv\Dotenv;
use WomanLab\Support\Database;

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/vendor/autoload.php';

Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'America/Santiago');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('womanlab_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

Database::configure([
    'host' => $_ENV['DB_HOST'] ?? '',
    'port' => $_ENV['DB_PORT'] ?? '3306',
    'name' => $_ENV['DB_NAME'] ?? '',
    'user' => $_ENV['DB_USER'] ?? '',
    'password' => $_ENV['DB_PASSWORD'] ?? '',
    'charset' => 'utf8mb4',
]);
