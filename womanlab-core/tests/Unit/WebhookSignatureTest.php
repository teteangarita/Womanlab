<?php
declare(strict_types=1);

namespace WomanLab\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WomanLab\Services\WebhookSignature;

final class WebhookSignatureTest extends TestCase
{
    public function testValidatesRawBodyAndRejectsTampering(): void
    {
        $signature = hash_hmac('sha256', 'x_amount40000x_messagePago éxitosox_referenceorder-1', 'test-secret');
        $raw = 'x_reference=order-1&x_message=Pago+%C3%A9xitoso&x_amount=40000&ignored=yes&x_signature=' . $signature;
        $fields = WebhookSignature::parse($raw);
        self::assertIsArray($fields);
        self::assertTrue(WebhookSignature::valid($fields, 'test-secret'));
        $fields['x_amount'] = '40001';
        self::assertFalse(WebhookSignature::valid($fields, 'test-secret'));
        self::assertFalse(WebhookSignature::valid($fields, ''));
        $fields['x_amount'] = '40000';
        $fields['x_signature'] = str_repeat('0', 64);
        self::assertFalse(WebhookSignature::valid($fields, 'test-secret'));
    }

    public function testRejectsAmbiguousOrMalformedForms(): void
    {
        foreach (['x_amount=1&x_amount=2', 'x_amount=1&x%5Famount=2', 'x_a[]=1', 'x_a=%zz', 'x_a=%FF', 'x_a', ''] as $raw) {
            self::assertNull(WebhookSignature::parse($raw));
        }
    }
}