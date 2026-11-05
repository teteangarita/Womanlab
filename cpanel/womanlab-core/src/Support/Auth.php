<?php
declare(strict_types=1);

namespace WomanLab\Support;

final class Auth
{
    public static function user(): ?array
    {
        return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
    }

    public static function requireRole(string $role = 'cliente'): array
    {
        $user = self::user();
        if ($user === null) {
            ApiResponse::fail('authentication_required', 401);
        }
        if ($role === 'admin' && $user['rol'] !== 'admin') {
            ApiResponse::fail('forbidden', 403);
        }
        return $user;
    }

    public static function requireCsrf(): void
    {
        $received = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $expected = $_SESSION['csrf_token'] ?? '';
        if (!is_string($received) || !is_string($expected) || $expected === '' || !hash_equals($expected, $received)) {
            ApiResponse::fail('csrf_invalid', 403);
        }
    }
}