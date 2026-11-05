<?php
declare(strict_types=1);

namespace WomanLab\Services;

final class TokenService
{
    public static function createVerificationToken(): array
    {
        $token = bin2hex(random_bytes(32));
        return ['token' => $token, 'hash' => hash('sha256', $token)];
    }

    public static function verify(string $token, string $storedHash): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $token) === 1
            && preg_match('/^[a-f0-9]{64}$/', $storedHash) === 1
            && hash_equals($storedHash, hash('sha256', $token));
    }
}