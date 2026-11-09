<?php
declare(strict_types=1);

namespace WomanLab\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WomanLab\Services\TuuCallback;

final class TuuCallbackTest extends TestCase
{
    public function testWebhookControllerLoads(): void
    {
        self::assertTrue(class_exists(\WomanLab\Controllers\WebhookController::class));
    }

    public function testValidatesDocumentedFields(): void
    {
        $fields = ['x_account_id' => '123', 'x_reference' => 'order-1', 'x_amount' => '19990.00', 'x_currency' => 'CLP', 'x_result' => 'failed'];
        self::assertNull(TuuCallback::validate($fields, '123'));
        self::assertSame('account_mismatch', TuuCallback::validate($fields, '124'));
        foreach (['x_currency' => 'USD', 'x_amount' => '19990.01', 'x_result' => 'PAID', 'x_reference' => 'order 1'] as $key => $value) {
            $invalid = $fields;
            $invalid[$key] = $value;
            self::assertSame('invalid_webhook', TuuCallback::validate($invalid, '123'));
        }
        unset($fields['x_amount']);
        self::assertSame('invalid_webhook', TuuCallback::validate($fields, '123'));
    }
}