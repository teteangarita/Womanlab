<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';
$db = WomanLab\Support\Database::connection();
$ids = $db->query(
    "SELECT id FROM ordenes
     WHERE estado = 'PAGADA'
       AND (dte_estado = 'ERROR' OR (dte_estado = 'EMITIENDO' AND actualizado_en < DATE_SUB(NOW(), INTERVAL 15 MINUTE)))
     ORDER BY actualizado_en LIMIT 50"
)->fetchAll(PDO::FETCH_COLUMN);

foreach ($ids as $id) {
    $db->beginTransaction();
    try {
        $query = $db->prepare('SELECT * FROM ordenes WHERE id = ? FOR UPDATE');
        $query->execute([$id]);
        $order = $query->fetch();
        if (!$order || $order['estado'] !== 'PAGADA'
            || !in_array($order['dte_estado'], ['ERROR', 'EMITIENDO'], true)
            || ($order['dte_estado'] === 'EMITIENDO' && strtotime($order['actualizado_en']) >= time() - 900)) {
            $db->commit();
            continue;
        }
        $db->prepare("UPDATE ordenes SET dte_estado = 'EMITIENDO' WHERE id = ?")->execute([$id]);
        $itemsQuery = $db->prepare(
            'SELECT oi.producto_id, oi.cantidad, oi.precio_unitario, p.sku, p.nombre
             FROM orden_items oi JOIN productos p ON p.id = oi.producto_id WHERE oi.orden_id = ? ORDER BY p.id'
        );
        $itemsQuery->execute([$id]);
        $items = $itemsQuery->fetchAll();
        $db->commit();

        $document = (new WomanLab\Services\TuuClient())->issueDocument($order, $items);
        $db->prepare("UPDATE ordenes SET dte_estado = 'EMITIDO', dte_referencia = ? WHERE id = ? AND dte_estado = 'EMITIENDO'")
            ->execute([$document['id'] ?? $document['folio'] ?? null, $id]);
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('DTE retry failed for order ' . $id . ': ' . $error->getMessage());
        $db->prepare("UPDATE ordenes SET dte_estado = 'ERROR' WHERE id = ? AND dte_estado = 'EMITIENDO'")->execute([$id]);
    }
}
fwrite(STDOUT, sprintf("Órdenes DTE revisadas: %d\n", count($ids)));