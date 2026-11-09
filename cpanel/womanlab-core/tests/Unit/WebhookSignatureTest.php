<?php
declare(strict_types=1);

namespace WomanLab\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WomanLab\Services\WebhookSignature;

final class WebhookSignatureTest extends TestCase
{
    public function testValidatesRawBodyAndRejectsTampering(): void
    {
        $raw = '{"status":"PAID","amount":40000}';
        $signature = hash_hmac('sha256', $raw, 'test-secret');

        self::assertTrue(WebhookSignature::valid($raw, 'test-secret', 'sha256=' . $signature));
        self::assertFalse(WebhookSignature::valid($raw . ' ', 'test-secret', $signature));
        self::assertFalse(WebhookSignature::valid($raw, '', $signature));
        self::assertFalse(WebhookSignature::valid($raw, 'test-secret', 'invalid'));
    }
}