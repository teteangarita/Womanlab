<?php
declare(strict_types=1);

namespace WomanLab\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use WomanLab\Services\CartService;
use WomanLab\Services\CheckoutException;

final class CartServiceTest extends TestCase
{
    private PDO $db;
    private string $sku;

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
        $insert = $this->db->prepare('INSERT INTO productos (sku, nombre, descripcion, precio, stock, activo) VALUES (?, ?, ?, ?, ?, 1)');
        $insert->execute([$this->sku, 'Producto de prueba', 'Solo para pruebas', 12345, 2]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db, $this->sku)) return;
        $query = $this->db->prepare('SELECT oi.orden_id FROM orden_items oi JOIN productos p ON p.id = oi.producto_id WHERE p.sku = ?');
        $query->execute([$this->sku]);
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $orderId) {
            $this->db->prepare('DELETE FROM orden_items WHERE orden_id = ?')->execute([$orderId]);
            $this->db->prepare('DELETE FROM ordenes WHERE id = ?')->execute([$orderId]);
        }
        $this->db->prepare('DELETE FROM productos WHERE sku = ?')->execute([$this->sku]);
    }

    public function testServerUsesDatabasePriceAndIgnoresClientPrice(): void
    {
        $order = (new CartService($this->db))->createPendingOrder(
            [['id' => $this->sku, 'cantidad' => 2, 'precio' => 1]],
            ['nombre' => 'Cliente Test', 'email' => 'cliente@example.test', 'telefono' => '+56912345678']
        );

        self::assertSame(24690, $order['monto_total']);
        self::assertSame(12345, $order['items'][0]['precio_unitario']);
    }

    public function testPendingOrderReservesStockAgainstAnotherCheckout(): void
    {
        $service = new CartService($this->db);
        $customer = ['nombre' => 'Cliente Test', 'email' => 'cliente@example.test', 'telefono' => '+56912345678'];
        $service->createPendingOrder([['id' => $this->sku, 'cantidad' => 2]], $customer);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('Stock insuficiente');
        $service->createPendingOrder([['id' => $this->sku, 'cantidad' => 1]], $customer);
    }
}