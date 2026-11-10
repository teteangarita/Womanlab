<?php
declare(strict_types=1);

namespace WomanLab\Tests\Unit;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use WomanLab\Services\TuuCallback;
use WomanLab\Services\TuuClient;

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

    private function signedReturn(string $secret): array
    {
        $fields = [
            'x_account_id' => '62224230', 'x_amount' => '1500.0', 'x_currency' => 'CLP',
            'x_reference' => 'ORD-20261109-3808E44C', 'x_result' => 'completed',
            'x_timestamp' => '2026-11-09T19:13:32Z', 'x_message' => 'Transaccion aprobada',
        ];
        return $fields + ['x_signature' => TuuClient::signFields($fields, $secret)];
    }

    private function pdoWithOrder(string $state, int $expectedCommits): PDO
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetch')->willReturn([
            'id' => 4, 'codigo_orden' => 'ORD-20261109-3808E44C', 'monto_total' => 1500,
            'estado' => $state, 'tuu_callback_signature' => null, 'reservation_active' => 1,
        ]);
        $statement->method('fetchAll')->willReturn([['producto_id' => 7, 'cantidad' => 1]]);
        $statement->method('rowCount')->willReturn(1);
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $pdo->expects(self::exactly($expectedCommits))->method('commit');
        return $pdo;
    }

    public function testSignedReturnRejectsBadSignatureWithoutTouchingDatabase(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method('beginTransaction');
        $fields = $this->signedReturn('secret');
        $fields['x_amount'] = '9999.0';
        self::assertSame(['invalid_signature', 400], (new TuuCallback($pdo))->handleSigned($fields, 'secret', '62224230'));
        self::assertSame(['invalid_signature', 400], (new TuuCallback($pdo))->handleSigned($this->signedReturn('otra'), 'secret', '62224230'));
    }

    public function testSignedReturnRejectsOtherAccount(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method('beginTransaction');
        self::assertSame(['account_mismatch', 422], (new TuuCallback($pdo))->handleSigned($this->signedReturn('secret'), 'secret', '111'));
    }

    public function testSignedReturnConfirmsPendingOrderAndPostIsIdempotent(): void
    {
        $fields = $this->signedReturn('secret');
        self::assertSame(['ok', 200], (new TuuCallback($this->pdoWithOrder('PENDIENTE', 1)))->handleSigned($fields, 'secret', '62224230'));
        self::assertSame(['ok', 200], (new TuuCallback($this->pdoWithOrder('PAGADA', 1)))->handleSigned($fields, 'secret', '62224230'));
    }
}