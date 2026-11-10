<?php
declare(strict_types=1);

namespace WomanLab\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WomanLab\Services\TuuClient;
use WomanLab\Support\TuuConfig;

final class TuuConfigTest extends TestCase
{
    private array $envBackup = [];

    protected function setUp(): void
    {
        foreach (TuuConfig::SECRET_NAMES as $name) {
            $this->envBackup[$name] = $_ENV[$name] ?? null;
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }
    }

    public function testReadsEnvFileWithQuotesCommentsAndExport(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($path, "# comentario\nTUU_ACCOUNT_ID=62224230\nexport TUU_SECRET_KEY=\"abc def\"\nTUU_API_URL=https://x.test/v1 # nota\nVACIA=\n");
        try {
            $values = TuuConfig::readEnvFile($path);
        } finally {
            unlink($path);
        }
        self::assertSame('62224230', $values['TUU_ACCOUNT_ID']);
        self::assertSame('abc def', $values['TUU_SECRET_KEY']);
        self::assertSame('https://x.test/v1', $values['TUU_API_URL']);
        self::assertSame('', $values['VACIA']);
        self::assertSame([], TuuConfig::readEnvFile('/ruta/inexistente/.env'));
    }

    public function testResolvesSecretFromEnvironmentAliasAndFileInOrder(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($path, "TUU_API_KEY=desde-archivo\n");
        try {
            self::assertSame(['value' => 'desde-archivo', 'source' => '.env[TUU_API_KEY]'], TuuConfig::resolveSecret($path));
            $_ENV['TUU_API_KEY'] = 'desde-env';
            self::assertSame('desde-env', TuuConfig::secretKey($path));
            $_ENV['TUU_SECRET_KEY'] = 'principal';
            self::assertSame(['value' => 'principal', 'source' => '$_ENV[TUU_SECRET_KEY]'], TuuConfig::resolveSecret($path));
        } finally {
            unlink($path);
        }
        unset($_ENV['TUU_SECRET_KEY'], $_ENV['TUU_API_KEY']);
        self::assertSame(['value' => null, 'source' => 'none'], TuuConfig::resolveSecret('/ruta/inexistente/.env'));
    }

    public function testSignatureMatchesRealSandboxCallback(): void
    {
        $secret = 'yAk0dXTJLQzkeEWODsQWVpPX0bn7ND50qwoQrXgqqNiUyEpgxIPxPtoCgKeLNeh1upTw72JZx5O9x5IaAtPIGUAVcMNcsUSg3M0M8tgWdUb4F8qkS8I7rHpOUmZqzvfS';
        $fields = [
            'x_account_id' => '62224230', 'x_amount' => '1500.0', 'x_currency' => 'CLP',
            'x_reference' => 'ORD-20261109-7ADD233F', 'x_result' => 'completed',
            'x_timestamp' => '2026-11-09T19:13:32Z', 'x_message' => 'Transaccion aprobada',
            'x_signature' => 'ignorada',
        ];
        self::assertSame('fb523c45bdf8d629e584418237469e756468b4016d5ae2cb99407d4ec6544729', TuuClient::signFields($fields, $secret));
        self::assertSame(
            'x_account_id62224230x_amount1500.0x_currencyCLPx_messageTransaccion aprobadax_referenceORD-20261109-7ADD233Fx_resultcompletedx_timestamp2026-11-09T19:13:32Z',
            TuuClient::canonicalString($fields)
        );
    }
}
