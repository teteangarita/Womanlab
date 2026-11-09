<?php
declare(strict_types=1);

namespace WomanLab\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WomanLab\Controllers\CheckoutController;

final class CheckoutSandboxTest extends TestCase
{
    public function testCheckoutRequiresExplicitSandboxConfiguration(): void
    {
        $env = [
            'APP_ENV' => 'sandbox',
            'TUU_CHECKOUT_ENABLED' => '1',
            'TUU_API_URL' => 'https://frontend-api.payment.haulmer.dev/v1/payment',
            'TUU_ACCOUNT_ID' => '62224230',
        ];
        self::assertTrue(CheckoutController::sandboxEnabled($env));
        foreach (['APP_ENV' => 'production', 'TUU_CHECKOUT_ENABLED' => '0', 'TUU_API_URL' => 'https://core.payment.haulmer.com/api/v1/payment', 'TUU_ACCOUNT_ID' => 'other'] as $name => $value) {
            $invalid = $env;
            $invalid[$name] = $value;
            self::assertFalse(CheckoutController::sandboxEnabled($invalid));
        }
    }
}