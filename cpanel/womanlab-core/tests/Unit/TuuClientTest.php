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
        $_ENV['TUU_API_URL'] = 'https://frontend-api.payment.haulmer.dev/v1/payment';
        $_ENV['TUU_ACCOUNT_ID'] = '62224230';
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
                TestCase::assertSame('https://frontend-api.payment.haulmer.dev/v1/payment', $url);
                TestCase::assertSame('62224230', $payload['x_account_id']);
                TestCase::assertSame(40000, $payload['x_amount']);
                TestCase::assertSame('order-test-1', $payload['x_reference']);
                TestCase::assertSame('CLP', $payload['x_currency']);
                TestCase::assertSame(TuuClient::signFields($payload, 'test-key'), $payload['x_signature']);
                TestCase::assertSame('https://womanlab.test/api/webhook_tuu.php', $payload['x_url_callback']);
                TestCase::assertArrayNotHasKey('secret', $payload);
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

    public function testSignatureUsesSortedXFieldsAndExcludesExistingSignature(): void
    {
        $fields = ['x_reference' => 'order-1', 'unrelated' => 'ignored', 'x_amount' => 1500, 'x_signature' => 'old'];
        self::assertSame(hash_hmac('sha256', 'x_amount1500x_referenceorder-1', 'secret'), TuuClient::signFields($fields, 'secret'));
    }

    public function testRejectsNonHttpsCallback(): void
    {
        $_ENV['TUU_WEBHOOK_URL'] = 'http://localhost/callback';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('deben usar HTTPS');
        (new TuuClient())->createCheckout(
            ['codigo_orden' => 'order-test-4', 'monto_total' => 1],
            [],
            ['name' => 'Test', 'email' => 'test@example.test', 'phone' => '+56912345678']
        );
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

    public function testAcceptsSecureUrlWithoutInventingSessionToken(): void
    {
        $transport = new class implements TuuTransport {
            public function post(string $url, string $apiKey, array $payload): array
            {
                return ['url' => 'https://checkout.tuu.cl/session/123'];
            }
        };

        $session = (new TuuClient($transport))->createCheckout(
            ['codigo_orden' => 'order-test-5', 'monto_total' => 1500],
            [],
            ['name' => 'Test Api', 'email' => 'test@example.test', 'phone' => '+56912345678']
        );

        self::assertSame('https://checkout.tuu.cl/session/123', $session['url']);
        self::assertNull($session['token']);
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