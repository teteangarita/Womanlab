<?php
declare(strict_types=1);

namespace WomanLab\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WomanLab\Services\TuuClient;
use WomanLab\Services\TuuTransport;

final class TuuClientTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['TUU_API_KEY'] = 'test-key';
        $_ENV['TUU_CHECKOUT_URL'] = 'https://api.haulmer.com/v2/payment/checkout';
        $_ENV['TUU_RETURN_URL'] = 'https://womanlab.test/pago/resultado.php';
        $_ENV['TUU_WEBHOOK_URL'] = 'https://womanlab.test/api/webhook_tuu.php';
        $_ENV['TUU_PAYMENT_HOSTS'] = 'checkout.tuu.cl';
    }

    public function testReturnsVerifiedSecureCheckoutSession(): void
    {
        $transport = new class implements TuuTransport {
            public function post(string $url, string $apiKey, array $payload): array
            {
                TestCase::assertSame('test-key', $apiKey);
                TestCase::assertSame(40000, $payload['amount']);
                TestCase::assertSame('order-test-1', $payload['buy_order']);
                return ['url' => 'https://checkout.tuu.cl/session/123', 'token' => 'session-123'];
            }
        };

        $session = (new TuuClient($transport))->createCheckout(
            ['codigo_orden' => 'order-test-1', 'monto_total' => 40000],
            [['sku' => 'plan-month', 'nombre' => 'Plan', 'cantidad' => 1, 'precio_unitario' => 40000]],
            ['name' => 'Cliente', 'email' => 'cliente@example.test', 'phone' => '+56912345678']
        );

        self::assertSame('https://checkout.tuu.cl/session/123', $session['url']);
        self::assertSame('session-123', $session['token']);
    }

    public function testRejectsUnexpectedPaymentHost(): void
    {
        $transport = new class implements TuuTransport {
            public function post(string $url, string $apiKey, array $payload): array
            {
                return ['url' => 'https://attacker.example/pay', 'token' => 'session-123'];
            }
        };

        $this->expectException(RuntimeException::class);
        (new TuuClient($transport))->createCheckout(
            ['codigo_orden' => 'order-test-2', 'monto_total' => 1],
            [],
            ['name' => 'Test', 'email' => 'test@example.test', 'phone' => '+56912345678']
        );
    }

    public function testProviderDeclineIsPropagatedAsFailure(): void
    {
        $transport = new class implements TuuTransport {
            public function post(string $url, string $apiKey, array $payload): array
            {
                throw new RuntimeException('TUU rechazó la sesión simulada.');
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TUU rechazó la sesión simulada.');
        (new TuuClient($transport))->createCheckout(
            ['codigo_orden' => 'order-test-3', 'monto_total' => 1],
            [],
            ['name' => 'Test', 'email' => 'test@example.test', 'phone' => '+56912345678']
        );
    }
}