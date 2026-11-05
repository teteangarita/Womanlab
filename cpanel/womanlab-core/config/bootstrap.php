<?php
declare(strict_types=1);

use Dotenv\Dotenv;
use WomanLab\Support\Database;

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/vendor/autoload.php';

Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

foreach ([
    'APP_URL', 'APP_TIMEZONE', 'TUU_API_KEY', 'TUU_CHECKOUT_URL', 'TUU_PAYMENT_HOSTS',
    'TUU_RETURN_URL', 'TUU_WEBHOOK_URL', 'TUU_WEBHOOK_SECRET', 'TUU_SIGNATURE_HEADER',
    'TUU_DTE_URL', 'MAIL_FROM', 'PUBLIC_UPLOAD_DIR',
] as $key) {
    $value = getenv($key);
    if (is_string($value) && $value !== '') {
        $_ENV[$key] = $value;
    }
}

$env = static function (string $key, string $default = ''): string {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return is_string($value) && $value !== '' ? $value : $default;
};

ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set($env('APP_TIMEZONE', 'America/Santiago'));

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
    'host' => $env('DB_HOST'),
    'port' => $env('DB_PORT', '3306'),
    'name' => $env('DB_NAME'),
    'user' => $env('DB_USER'),
    'password' => $env('DB_PASSWORD'),
    'charset' => 'utf8mb4',
]);
