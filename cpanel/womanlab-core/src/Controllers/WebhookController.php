<?php
declare(strict_types=1);

namespace WomanLab\Controllers;

use PDO;
use Throwable;
use WomanLab\Services\TuuClient;
use WomanLab\Support\ApiResponse;

final class WebhookController
{
    public static function handle(PDO $db): never
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            ApiResponse::fail('method_not_allowed', 405);
        }

        $raw = file_get_contents('php://input');
        $secret = $_ENV['TUU_WEBHOOK_SECRET'] ?? '';
        $headerName = $_ENV['TUU_SIGNATURE_HEADER'] ?? 'HTTP_X_TUU_SIGNATURE';
        $signature = $_SERVER[$headerName] ?? '';
        if (!is_string($raw) || strlen($raw) > 65536 || $secret === '' || !is_string($signature)) {
            ApiResponse::fail('webhook_not_configured', 503);
        }

        $signature = preg_replace('/^sha256=/i', '', trim($signature)) ?? '';
        $expected = hash_hmac('sha256', $raw, $secret);
        if (!preg_match('/^[a-f0-9]{64}$/i', $signature) || !hash_equals($expected, strtolower($signature))) {
            ApiResponse::fail('invalid_signature', 401);
        }

        try {
            $event = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            ApiResponse::fail('invalid_json', 400);
        }
        $code = (string) ($event['buy_order'] ?? $event['order_code'] ?? '');
        $status = strtoupper((string) ($event['status'] ?? $event['event'] ?? ''));
        $transactionId = trim((string) ($event['transaction_id'] ?? $event['transactionId'] ?? ''));
        $amount = filter_var($event['amount'] ?? null, FILTER_VALIDATE_INT);
        if ($code === '' || strlen($code) > 36 || !in_array($status, ['PAID', 'REJECTED', 'FAILED', 'CANCELLED'], true)
            || $amount === false) {
            ApiResponse::fail('invalid_webhook', 422);
        }

        $db->beginTransaction();
        $order = null;
        $items = [];
        $issueDocument = false;
        $duplicate = false;
        try {
            $query = $db->prepare('SELECT * FROM ordenes WHERE codigo_orden = ? FOR UPDATE');
            $query->execute([$code]);
            $order = $query->fetch();
            if (!$order) {
                $db->rollBack();
                ApiResponse::fail('order_not_found', 404);
            }
            if ((int) $amount !== (int) $order['monto_total']) {
                $db->rollBack();
                ApiResponse::fail('amount_mismatch', 409);
            }

            if ($order['estado'] === 'PENDIENTE'
                || ($status === 'PAID' && $order['estado'] === 'ANULADA')) {
                if ($status !== 'PAID' && strtotime($order['expira_en']) < time()) {
                    $db->prepare("UPDATE ordenes SET estado = 'ANULADA' WHERE id = ?")->execute([$order['id']]);
                    $db->commit();
                    ApiResponse::fail('order_expired', 409);
                }

                $itemQuery = $db->prepare(
                    'SELECT oi.producto_id, oi.cantidad, oi.precio_unitario, p.sku, p.nombre
                     FROM orden_items oi JOIN productos p ON p.id = oi.producto_id
                     WHERE oi.orden_id = ? ORDER BY p.id FOR UPDATE'
                );
                $itemQuery->execute([$order['id']]);
                $items = $itemQuery->fetchAll();

                if ($status === 'PAID') {
                    if ($transactionId === '' || strlen($transactionId) > 255) {
                        $db->rollBack();
                        ApiResponse::fail('invalid_transaction', 422);
                    }
                    $stockUpdate = $db->prepare('UPDATE productos SET stock = stock - ? WHERE id = ? AND stock >= ?');
                    foreach ($items as $item) {
                        $quantity = (int) $item['cantidad'];
                        $stockUpdate->execute([$quantity, (int) $item['producto_id'], $quantity]);
                        if ($stockUpdate->rowCount() !== 1) {
                            throw new \RuntimeException('Stock insuficiente al confirmar pago.');
                        }
                    }
                    $db->prepare(
                        "UPDATE ordenes SET estado = 'PAGADA', tuu_transaction_id = ?, dte_estado = 'EMITIENDO'
                         WHERE id = ? AND estado IN ('PENDIENTE', 'ANULADA')"
                    )->execute([$transactionId, $order['id']]);
                    $issueDocument = true;
                } elseif ($order['estado'] === 'PENDIENTE') {
                    $db->prepare("UPDATE ordenes SET estado = 'RECHAZADA' WHERE id = ? AND estado = 'PENDIENTE'")
                        ->execute([$order['id']]);
                } else {
                    $duplicate = true;
                }
            } elseif ($status === 'PAID' && $order['estado'] === 'PAGADA'
                && in_array($order['dte_estado'], ['PENDIENTE', 'ERROR'], true)) {
                if ($transactionId === '' || $transactionId !== $order['tuu_transaction_id']) {
                    $db->rollBack();
                    ApiResponse::fail('transaction_mismatch', 409);
                }
                $claim = $db->prepare(
                    "UPDATE ordenes SET dte_estado = 'EMITIENDO'
                     WHERE id = ? AND dte_estado IN ('PENDIENTE', 'ERROR')"
                );
                $claim->execute([$order['id']]);
                if ($claim->rowCount() === 1) {
                    $itemQuery = $db->prepare(
                        'SELECT oi.producto_id, oi.cantidad, oi.precio_unitario, p.sku, p.nombre
                         FROM orden_items oi JOIN productos p ON p.id = oi.producto_id
                         WHERE oi.orden_id = ? ORDER BY p.id'
                    );
                    $itemQuery->execute([$order['id']]);
                    $items = $itemQuery->fetchAll();
                    $issueDocument = true;
                } else {
                    $duplicate = true;
                }
            } else {
                $duplicate = true;
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('TUU webhook failed: ' . $error->getMessage());
            ApiResponse::fail('webhook_processing_failed', 500);
        }

        if ($issueDocument && is_array($order)) {
            try {
                $document = (new TuuClient())->issueDocument($order, $items);
                $reference = $document['id'] ?? $document['folio'] ?? null;
                $db->prepare(
                    "UPDATE ordenes SET dte_estado = 'EMITIDO', dte_referencia = ?
                     WHERE id = ? AND estado = 'PAGADA' AND dte_estado = 'EMITIENDO'"
                )->execute([$reference, $order['id']]);
            } catch (Throwable $error) {
                error_log('TUU DTE emission failed for ' . $code . ': ' . $error->getMessage());
                $db->prepare("UPDATE ordenes SET dte_estado = 'ERROR' WHERE id = ? AND estado = 'PAGADA' AND dte_estado = 'EMITIENDO'")
                    ->execute([$order['id']]);
                ApiResponse::fail('payment_confirmed_document_pending', 503);
            }
        }
        ApiResponse::json(['received' => true, 'duplicate' => $duplicate]);
    }
}