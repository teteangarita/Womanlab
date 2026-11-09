<?php
declare(strict_types=1);

namespace WomanLab\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WomanLab\Services\CurlTuuTransport;

final class CurlTuuTransportTest extends TestCase
{
    public function testConnectionFailureIsLoggedWithoutCredentials(): void
    {
        $logPath = tempnam(sys_get_temp_dir(), 'womanlab-tuu-');
        self::assertIsString($logPath);

        try {
            try {
                (new CurlTuuTransport($logPath))->post('https://127.0.0.1:1', ['x_account_id' => 'account']);
                self::fail('La solicitud debería fallar.');
            } catch (RuntimeException $error) {
                self::assertSame('No se pudo completar la solicitud TUU.', $error->getMessage());
            }
            $log = file_get_contents($logPath);
            self::assertIsString($log);
            self::assertStringContainsString('HTTP 0', $log);
            self::assertStringNotContainsString('secret-test-key', $log);
        } finally {
            unlink($logPath);
        }
    }
}