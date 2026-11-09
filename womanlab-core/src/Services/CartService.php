<?php
declare(strict_types=1);

namespace WomanLab\Services;

use PDO;

final class CartService
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function createPendingOrder(array $items, array $customer, ?int $userId = null): array
    {
        if ($items === [] || count($items) > 12) {
            throw new CheckoutException('El carrito está vacío o supera el límite permitido.');
        }

        $quantities = [];
        $membershipCount = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new CheckoutException('El carrito contiene artículos o cantidades inválidos.');
            }
            $sku = trim((string) ($item['id'] ?? ''));
            $quantity = filter_var($item['cantidad'] ?? null, FILTER_VALIDATE_INT);
            if ($sku === '' || strlen($sku) > 80 || $quantity === false || $quantity < 1 || $quantity > 10) {
                throw new CheckoutException('El carrito contiene artículos o cantidades inválidos.');
            }
            $quantities[$sku] = ($quantities[$sku] ?? 0) + $quantity;
            if ($quantities[$sku] > 10) {
                throw new CheckoutException('La cantidad supera el límite permitido.');
            }
            if (str_starts_with($sku, 'plan-membership-')
                && ($quantity !== 1 || ++$membershipCount > 1)) {
                throw new CheckoutException('Solo se permite una membresía por pedido.');
            }
        }

        ksort($quantities, SORT_STRING);
        $this->db->beginTransaction();
        try {
            $placeholders = implode(',', array_fill(0, count($quantities), '?'));
            $productQuery = $this->db->prepare(
                "SELECT id, sku, nombre, precio, stock, activo FROM productos WHERE sku IN ({$placeholders}) ORDER BY sku FOR UPDATE"
            );
            $productQuery->execute(array_keys($quantities));
            $products = [];
            foreach ($productQuery->fetchAll() as $product) {
                $products[$product['sku']] = $product;
            }

            if (count($products) !== count($quantities)) {
                throw new CheckoutException('Uno o más artículos ya no están disponibles.', 409);
            }

            $total = 0;
            $orderItems = [];
            $reservationQuery = $this->db->prepare(
                "SELECT COALESCE(SUM(oi.cantidad), 0) AS reservado
                 FROM orden_items oi JOIN ordenes o ON o.id = oi.orden_id
                 WHERE oi.producto_id = ? AND o.estado = 'PENDIENTE' AND o.expira_en > NOW()"
            );
            foreach ($quantities as $sku => $quantity) {
                $product = $products[$sku];
                if (!(int) $product['activo']) {
                    throw new CheckoutException('Uno o más artículos ya no están disponibles.', 409);
                }
                $reservationQuery->execute([(int) $product['id']]);
                $reserved = (int) $reservationQuery->fetchColumn();
                if ((int) $product['stock'] - $reserved < $quantity) {
                    throw new CheckoutException('Stock insuficiente para ' . $product['nombre'] . '.', 409);
                }

                $lineTotal = (int) $product['precio'] * $quantity;
                if ($lineTotal > 1_000_000 || $total > 1_000_000 - $lineTotal) {
                    throw new CheckoutException('El monto del pedido supera el máximo permitido.');
                }
                $total += $lineTotal;
                $orderItems[] = [
                    'producto_id' => (int) $product['id'],
                    'sku' => $sku,
                    'nombre' => $product['nombre'],
                    'cantidad' => $quantity,
                    'precio_unitario' => (int) $product['precio'],
                ];
            }

            $code = self::orderCode();
            $insert = $this->db->prepare(
                "INSERT INTO ordenes
                 (codigo_orden, usuario_id, cliente_nombre, cliente_email, cliente_telefono, monto_total, estado, expira_en)
                 VALUES (?, ?, ?, ?, ?, ?, 'PENDIENTE', DATE_ADD(NOW(), INTERVAL 30 MINUTE))"
            );
            $insert->execute([$code, $userId, $customer['nombre'], $customer['email'], $customer['telefono'], $total]);
            $orderId = (int) $this->db->lastInsertId();
            $insertItem = $this->db->prepare(
                'INSERT INTO orden_items (orden_id, producto_id, cantidad, precio_unitario) VALUES (?, ?, ?, ?)'
            );
            foreach ($orderItems as $item) {
                $insertItem->execute([$orderId, $item['producto_id'], $item['cantidad'], $item['precio_unitario']]);
            }

            $this->db->commit();
            return [
                'id' => $orderId,
                'codigo_orden' => $code,
                'monto_total' => $total,
                'items' => $orderItems,
            ];
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public static function orderCode(): string
    {
        return 'ORD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    }
}