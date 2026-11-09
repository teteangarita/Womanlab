<?php
declare(strict_types=1);

namespace WomanLab\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use WomanLab\Services\CartService;
use WomanLab\Services\TuuCallback;

final class TuuCallbackTest extends TestCase
{
    private PDO $db;
    private string $sku;
    private array $order;

    protected function setUp(): void
    {
        $dsn = getenv('WOMANLAB_TEST_DSN');
        if (!$dsn) {
            self::markTestSkipped('Configura WOMANLAB_TEST_DSN para ejecutar pruebas MariaDB aisladas.');
        }
        $this->db = new PDO($dsn, getenv('WOMANLAB_TEST_DB_USER') ?: '', getenv('WOMANLAB_TEST_DB_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->sku = 'test-' . bin2hex(random_bytes(8));
        $this->db->prepare('INSERT INTO productos (sku, nombre, descripcion, precio, stock, activo) VALUES (?, ?, ?, ?, ?, 1)')
            ->execute([$this->sku, 'Producto de prueba', 'Solo para pruebas', 12345, 2]);
        $this->order = (new CartService($this->db))->createPendingOrder(
            [['id' => $this->sku, 'cantidad' => 1]],
            ['nombre' => 'Cliente Test', 'email' => 'cliente@example.test', 'telefono' => '+56912345678']
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->db, $this->order)) {
            $this->db->prepare('DELETE FROM ordenes WHERE id = ?')->execute([$this->order['id']]);
        }
        if (isset($this->db, $this->sku)) {
            $this->db->prepare('DELETE FROM productos WHERE sku = ?')->execute([$this->sku]);
        }
    }

    public function testRejectedCallbackIsIdempotentAndDoesNotChangeStock(): void
    {
        $callback = new TuuCallback($this->db);
        $fields = $this->fields('failed');
        self::assertSame(['ok', 200], $callback->process($fields));
        self::assertSame(['ok', 200], $callback->process($fields));
        $changed = $fields;
        $changed['x_signature'] = str_repeat('b', 64);
        self::assertSame(['order_state_conflict', 409], $callback->process($changed));
        self::assertSame('RECHAZADA', $this->orderState());
        self::assertSame(2, $this->stock());
        self::assertSame(['callback_not_supported', 503], $callback->process($this->fields('completed')));
        self::assertSame('RECHAZADA', $this->orderState());
    }

    public function testUnprocessedAndMismatchedCallbacksLeaveOrderAndStockUntouched(): void
    {
        $callback = new TuuCallback($this->db);
        self::assertSame(['callback_not_supported', 503], $callback->process($this->fields('completed')));
        self::assertSame(['callback_not_supported', 503], $callback->process($this->fields('pending')));
        $fields = $this->fields('failed');
        $fields['x_amount'] = '12346';
        self::assertSame(['amount_mismatch', 409], $callback->process($fields));
        $fields['x_reference'] = strtoupper($this->order['codigo_orden']);
        self::assertSame(['order_not_found', 404], $callback->process($fields));
        self::assertSame('PENDIENTE', $this->orderState());
        self::assertSame(2, $this->stock());
        $this->db->prepare("UPDATE ordenes SET estado = 'PAGADA' WHERE id = ?")->execute([$this->order['id']]);
        self::assertSame(['order_state_conflict', 409], $callback->process($this->fields('failed')));
        self::assertSame(2, $this->stock());
    }

    private function fields(string $result): array
    {
        return ['x_account_id' => '123', 'x_reference' => $this->order['codigo_orden'], 'x_amount' => '12345.00', 'x_currency' => 'CLP', 'x_result' => $result, 'x_signature' => str_repeat('a', 64)];
    }

    private function orderState(): string
    {
        $query = $this->db->prepare('SELECT estado FROM ordenes WHERE id = ?');
        $query->execute([$this->order['id']]);
        return $query->fetchColumn();
    }

    private function stock(): int
    {
        $query = $this->db->prepare('SELECT stock FROM productos WHERE sku = ?');
        $query->execute([$this->sku]);
        return (int) $query->fetchColumn();
    }
}