<?php
declare(strict_types=1);

namespace WomanLab\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WomanLab\Services\TokenService;

final class TokenServiceTest extends TestCase
{
    public function testVerificationTokenIsRandomAndStoredAsHash(): void
    {
        $first = TokenService::createVerificationToken();
        $second = TokenService::createVerificationToken();

        self::assertSame(64, strlen($first['token']));
        self::assertNotSame($first['token'], $first['hash']);
        self::assertNotSame($first['token'], $second['token']);
        self::assertTrue(TokenService::verify($first['token'], $first['hash']));
        self::assertFalse(TokenService::verify($second['token'], $first['hash']));
    }

    public function testPasswordHashVerifiesWithoutStoringPlaintext(): void
    {
        $password = 'womanlab-test-password-2026';
        $hash = password_hash($password, PASSWORD_DEFAULT);

        self::assertNotSame($password, $hash);
        self::assertTrue(password_verify($password, $hash));
        self::assertFalse(password_verify('incorrect-password', $hash));
    }
}