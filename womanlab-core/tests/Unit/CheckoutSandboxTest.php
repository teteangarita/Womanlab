<?php
declare(strict_types=1);

namespace WomanLab\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WomanLab\Controllers\CheckoutController;

final class CheckoutSandboxTest extends TestCase
{
    public function testCheckoutRequiresExplicitCompleteConfigurationWithoutSandboxLock(): void
    {
        $env = [
            'APP_ENV' => 'production',
            'TUU_CHECKOUT_ENABLED' => '1',
            'TUU_API_URL' => 'https://frontend-api.payment.haulmer.dev/v1/payment',
            'TUU_ACCOUNT_ID' => '62224230',
            'TUU_SECRET_KEY' => 'test-secret',
            'TUU_PAYMENT_HOSTS' => 'checkout.tuu.cl',
        ];
        self::assertTrue(CheckoutController::checkoutEnabled($env));
        foreach (['TUU_CHECKOUT_ENABLED' => '0', 'TUU_API_URL' => '', 'TUU_ACCOUNT_ID' => '', 'TUU_SECRET_KEY' => ''] as $name => $value) {
            $invalid = $env;
            $invalid[$name] = $value;
            self::assertFalse(CheckoutController::checkoutEnabled($invalid));
        }
    }
}