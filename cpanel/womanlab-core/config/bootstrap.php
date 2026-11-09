<?php
declare(strict_types=1);

use Dotenv\Dotenv;
use WomanLab\Support\Database;

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/vendor/autoload.php';

Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

if (!function_exists('womanlab_base_path')) {
    function womanlab_base_path(): string
    {
        $script = parse_url($_SERVER['SCRIPT_NAME'] ?? '/', PHP_URL_PATH) ?: '/';
        $basePath = dirname(dirname($script));
        return $basePath === '/' || $basePath === '.' ? '' : rtrim($basePath, '/');
    }
}

foreach ([
    'APP_ENV', 'APP_URL', 'APP_TIMEZONE', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_PASS',
    'TUU_API_KEY', 'TUU_API_URL', 'TUU_ACCOUNT_ID', 'TUU_PAYMENT_HOSTS', 'TUU_CHECKOUT_ENABLED',
    'TUU_DTE_URL', 'MAIL_FROM', 'PUBLIC_UPLOAD_DIR',
] as $key) {
    $value = getenv($key);
    if (is_string($value) && ($value !== '' || in_array($key, ['DB_PASSWORD', 'DB_PASS'], true))) {
        $_ENV[$key] = $value;
    }
}

$env = static function (string $key, string $default = ''): string {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return is_string($value) && ($value !== '' || in_array($key, ['DB_PASSWORD', 'DB_PASS'], true)) ? $value : $default;
};

ini_set('display_errors', '0');
ini_set('log_errors', '1');
$logDirectory = dirname(__DIR__) . '/logs';
if (is_dir($logDirectory) && is_writable($logDirectory)) {
    ini_set('error_log', $logDirectory . '/php_errors.log');
}
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
    'host' => $env('DB_HOST', 'localhost'),
    'port' => $env('DB_PORT', '3306'),
    'name' => $env('DB_NAME'),
    'user' => $env('DB_USER'),
    'password' => is_string(getenv('DB_PASS')) ? getenv('DB_PASS') : (is_string(getenv('DB_PASSWORD')) ? getenv('DB_PASSWORD') : $env('DB_PASS', $env('DB_PASSWORD'))),
    'charset' => 'utf8mb4',
]);
