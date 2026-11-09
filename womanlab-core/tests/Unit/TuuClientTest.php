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
        $_ENV['APP_ENV'] = 'production';
        $_ENV['TUU_SECRET_KEY'] = 'test-key';
        $_ENV['TUU_API_URL'] = 'https://frontend-api.payment.haulmer.dev/v1/payment';
        $_ENV['TUU_ACCOUNT_ID'] = '62224230';
        $_ENV['APP_URL'] = 'https://womanlab.test/';
        $_ENV['TUU_PAYMENT_HOSTS'] = 'checkout.tuu.cl';
    }

    public function testReturnsVerifiedSecureCheckoutSession(): void
    {
        $transport = new class implements TuuTransport {
            public function post(string $url, array $payload, ?string $apiKey = null): array
            {
                TestCase::assertNull($apiKey);
                TestCase::assertSame('https://frontend-api.payment.haulmer.dev/v1/payment', $url);
                TestCase::assertSame('62224230', $payload['x_account_id']);
                TestCase::assertSame(40000, $payload['x_amount']);
                TestCase::assertSame('order-test-1', $payload['x_reference']);
                TestCase::assertSame('CLP', $payload['x_currency']);
                TestCase::assertSame(TuuClient::signFields($payload, 'test-key'), $payload['x_signature']);
                TestCase::assertSame('https://womanlab.test/api/webhook_tuu.php', $payload['x_url_callback']);
                TestCase::assertSame('https://womanlab.test/pago/exito.php', $payload['x_url_complete']);
                TestCase::assertSame('https://womanlab.test/pago/cancelado.php', $payload['x_url_cancel']);
                TestCase::assertArrayNotHasKey('secret', $payload);
                return ['url' => 'https://checkout.tuu.cl/session/123', 'token' => 'session-123'];
            }
        };

        $session = (new TuuClient($transport))->createCheckout(
            ['codigo_orden' => 'order-test-1', 'monto_total' => 40000],
            [['sku' => 'plan-month', 'nombre' => 'Plan', 'cantidad' => 1, 'precio_unitario' => 40000]],
            ['name' => 'Cliente', 'email' => 'cliente@example.test', 'phone' => '+56912345678']
        );

        self::assertSame('https://checkout.tuu.cl/session/123', $session['payment_url']);
        self::assertSame('session-123', $session['token']);
    }

    public function testSignatureUsesSortedXFieldsAndExcludesExistingSignature(): void
    {
        $fields = ['x_reference' => 'order-1', 'unrelated' => 'ignored', 'x_amount' => 1500, 'x_signature' => 'old'];
        self::assertSame(hash_hmac('sha256', 'x_amount1500x_referenceorder-1', 'secret'), TuuClient::signFields($fields, 'secret'));
    }

    public function testSignatureOmitsValuesForNullAndEmptyFields(): void
    {
        $fields = ['x_z' => null, 'x_empty' => '', 'not_signed' => 'ignored', 'x_signature' => 'old'];
        self::assertSame(hash_hmac('sha256', 'x_emptyx_z', 'test-key'), TuuClient::signFields($fields, 'test-key'));
    }

    public function testMatchesOfficialSignatureVectorWhenSecretIsProvidedPrivately(): void
    {
        $secret = getenv('TUU_SIGNATURE_VECTOR_SECRET');
        if (!is_string($secret) || $secret === '') {
            self::markTestSkipped('Configura TUU_SIGNATURE_VECTOR_SECRET fuera del repositorio para comprobar el vector oficial.');
        }

        $fields = [
            'x_account_id' => '82121521',
            'x_amount' => '16580',
            'x_currency' => 'CLP',
            'x_customer_email' => 'test.api@haulmer.com',
            'x_customer_first_name' => 'Test',
            'x_customer_last_name' => 'Api',
            'x_customer_phone' => '+56987654321',
            'x_description' => 'polera#222',
            'x_reference' => '2231',
            'x_shop_name' => 'Integración 1',
            'x_url_callback' => 'https://transaction-handler.haulmer.xyz/callbackExample',
            'x_url_cancel' => 'https://transaction-handler.haulmer.xyz/payment/cancel',
            'x_url_complete' => 'https://transaction-handler.haulmer.xyz/payment/complete',
        ];

        self::assertSame('56ef2554442d536c1df9128a41901a8e4335a711f25de9f53282009edf0cd152', TuuClient::signFields($fields, $secret));
    }

    public function testAllowsHttpLocalhostOnlyInDevelopment(): void
    {
        $_ENV['APP_ENV'] = 'development';
        $_ENV['APP_URL'] = 'http://localhost:8000';
        $transport = new class implements TuuTransport {
            public function post(string $url, array $payload, ?string $apiKey = null): array
            {
                TestCase::assertSame('http://localhost:8000/api/webhook_tuu.php', $payload['x_url_callback']);
                return ['payment_url' => 'https://checkout.tuu.cl/session/local'];
            }
        };

        $session = (new TuuClient($transport))->createCheckout(
            ['codigo_orden' => 'order-test-local', 'monto_total' => 1500],
            [],
            ['name' => 'Test', 'email' => 'test@example.test', 'phone' => '+56912345678']
        );

        self::assertSame('https://checkout.tuu.cl/session/local', $session['payment_url']);
    }

    public function testRejectsNonHttpsCallback(): void
    {
        $_ENV['APP_URL'] = 'http://localhost';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_URL debe ser un origen HTTPS válido');
        (new TuuClient())->createCheckout(
            ['codigo_orden' => 'order-test-4', 'monto_total' => 1],
            [],
            ['name' => 'Test', 'email' => 'test@example.test', 'phone' => '+56912345678']
        );
    }

    public function testRejectsUnexpectedPaymentHost(): void
    {
        $transport = new class implements TuuTransport {
            public function post(string $url, array $payload, ?string $apiKey = null): array
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
            public function post(string $url, array $payload, ?string $apiKey = null): array
            {
                return ['url' => 'https://checkout.tuu.cl/session/123'];
            }
        };

        $session = (new TuuClient($transport))->createCheckout(
            ['codigo_orden' => 'order-test-5', 'monto_total' => 1500],
            [],
            ['name' => 'Test Api', 'email' => 'test@example.test', 'phone' => '+56912345678']
        );

        self::assertSame('https://checkout.tuu.cl/session/123', $session['payment_url']);
        self::assertNull($session['token']);
    }

    public function testProviderDeclineIsPropagatedAsFailure(): void
    {
        $transport = new class implements TuuTransport {
            public function post(string $url, array $payload, ?string $apiKey = null): array
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