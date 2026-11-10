<?php
declare(strict_types=1);

namespace WomanLab\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WomanLab\Services\TuuClient;

final class TuuFirmaTest extends TestCase
{
    private const SECRET = 'yAk0dXTJLQzkeEWODsQWVpPX0bn7ND50qwoQrXgqqNiUyEpgxIPxPtoCgKeLNeh1upTw72JZx5O9x5IaAtPIGUAVcMNcsUSg3M0M8tgWdUb4F8qkS8I7rHpOUmZqzvfS';

    private array $postBackup = [];

    protected function setUp(): void
    {
        $this->postBackup = $_POST;
    }

    protected function tearDown(): void
    {
        $_POST = $this->postBackup;
    }

    public function testVerificarFirmaAcceptsPostThatAlreadyContainsXSignature(): void
    {
        $_POST = [
            'x_account_id' => '62224230', 'x_amount' => '1500.0', 'x_currency' => 'CLP',
            'x_reference' => 'ORD-20261109-7ADD233F', 'x_result' => 'completed',
            'x_timestamp' => '2026-11-09T19:13:32Z', 'x_message' => 'Transaccion aprobada',
            'x_signature' => 'fb523c45bdf8d629e584418237469e756468b4016d5ae2cb99407d4ec6544729',
        ];
        $client = new TuuClient();

        self::assertTrue($client->verificarFirma($_POST, self::SECRET));
        self::assertTrue($client->verificarFirma($_POST, ' ' . self::SECRET . "\n"));
        self::assertSame($_POST['x_signature'], $client->generarFirma($_POST, self::SECRET));
    }

    public function testVerificarFirmaRejectsTamperingMissingSignatureAndEmptySecret(): void
    {
        $client = new TuuClient();
        $payload = ['x_reference' => 'ORD-1', 'x_amount' => '1500'];
        $payload['x_signature'] = $client->generarFirma($payload, self::SECRET);
        self::assertTrue($client->verificarFirma($payload, self::SECRET));

        $tampered = $payload;
        $tampered['x_amount'] = '1501';
        self::assertFalse($client->verificarFirma($tampered, self::SECRET));
        self::assertFalse($client->verificarFirma(['x_reference' => 'ORD-1'], self::SECRET));
        self::assertFalse($client->verificarFirma($payload, ''));
    }
}
