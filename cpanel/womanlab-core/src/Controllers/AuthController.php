<?php
declare(strict_types=1);

namespace WomanLab\Controllers;

use PDO;
use Throwable;
use WomanLab\Services\Mailer;
use WomanLab\Services\TokenService;
use WomanLab\Support\ApiResponse;

final class AuthController
{
    public static function dispatch(PDO $db): never
    {
        $action = (string) ($_GET['action'] ?? '');
        if ($action === 'verify' && strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
            self::verify($db, (string) ($_GET['token'] ?? ''));
        }

        $payload = ApiResponse::input();
        if ($action === 'register') {
            self::register($db, $payload);
        }
        if ($action === 'login') {
            self::login($db, $payload);
        }
        if ($action === 'logout') {
            self::logout();
        }
        if ($action === 'me') {
            ApiResponse::json(['user' => $_SESSION['user'] ?? null, 'csrf_token' => self::csrfToken()]);
        }
        ApiResponse::fail('not_found', 404);
    }

    private static function register(PDO $db, array $payload): never
    {
        $name = trim(strip_tags((string) ($payload['nombre'] ?? '')));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $password = (string) ($payload['password'] ?? '');
        if ($name === '' || mb_strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || strlen($email) > 190 || strlen($password) < 12 || strlen($password) > 200) {
            ApiResponse::fail('invalid_registration', 422);
        }

        $verification = TokenService::createVerificationToken();
        $insert = $db->prepare(
            'INSERT INTO usuarios (nombre, email, password_hash, token_verificacion, token_verificacion_expira_en)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))'
        );
        $db->beginTransaction();
        try {
            $insert->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $verification['hash']]);
            (new Mailer())->sendVerification($email, $name, $verification['token']);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Registration failed: ' . $error->getMessage());
            ApiResponse::fail('registration_failed', 409);
        }
        ApiResponse::json(['message' => 'Revisa tu correo para verificar la cuenta.'], 201);
    }

    private static function verify(PDO $db, string $token): never
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            ApiResponse::fail('invalid_or_expired_token', 400);
        }
        $query = $db->prepare(
            'SELECT id, token_verificacion FROM usuarios WHERE token_verificacion = ? AND token_verificacion_expira_en > NOW() LIMIT 1'
        );
        $query->execute([hash('sha256', $token)]);
        $user = $query->fetch();
        if (!$user || !TokenService::verify($token, (string) $user['token_verificacion'])) {
            ApiResponse::fail('invalid_or_expired_token', 400);
        }
        $db->prepare(
            'UPDATE usuarios SET email_verificado_en = NOW(), token_verificacion = NULL, token_verificacion_expira_en = NULL WHERE id = ?'
        )->execute([$user['id']]);
        ApiResponse::json(['message' => 'Correo verificado. Ya puedes iniciar sesión.']);
    }

    private static function login(PDO $db, array $payload): never
    {
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $password = (string) ($payload['password'] ?? '');
        $query = $db->prepare('SELECT id, nombre, email, password_hash, rol, email_verificado_en, activo FROM usuarios WHERE email = ? LIMIT 1');
        $query->execute([$email]);
        $user = $query->fetch();
        if (!$user || !(int) $user['activo'] || !password_verify($password, $user['password_hash']) || !$user['email_verificado_en']) {
            ApiResponse::fail('invalid_credentials_or_unverified_email', 401);
        }

        session_regenerate_id(true);
        unset($user['password_hash'], $user['email_verificado_en']);
        $_SESSION['user'] = $user;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        ApiResponse::json(['user' => $user, 'csrf_token' => $_SESSION['csrf_token']]);
    }

    private static function logout(): never
    {
        $received = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($received) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $received)) {
            ApiResponse::fail('csrf_invalid', 403);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        ApiResponse::json(['message' => 'Sesión cerrada.']);
    }

    private static function csrfToken(): string
    {
        $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
        return $_SESSION['csrf_token'];
    }
}