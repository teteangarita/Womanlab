<?php
declare(strict_types=1);

namespace WomanLab\Services;

use PDO;
use Throwable;

final class TuuCallback
{
    public function __construct(private readonly PDO $db)
    {
    }

    public static function validate(array $fields, string $account): ?string
    {
        foreach (['x_account_id', 'x_reference', 'x_amount', 'x_currency', 'x_result'] as $name) {
            if (!isset($fields[$name]) || !is_string($fields[$name]) || $fields[$name] === '') {
                return 'invalid_webhook';
            }
        }
        if ($account === '' || !hash_equals($account, $fields['x_account_id'])) {
            return 'account_mismatch';
        }
        if (strlen($fields['x_reference']) > 36 || preg_match('/^[A-Za-z0-9-]+$/D', $fields['x_reference']) !== 1
            || preg_match('/^[0-9]{1,10}(?:\.0{1,2})?$/D', $fields['x_amount']) !== 1
            || $fields['x_currency'] !== 'CLP'
            || !in_array($fields['x_result'], ['completed', 'failed', 'pending'], true)) {
            return 'invalid_webhook';
        }
        return null;
    }

    /** Callback POST y retorno GET firmado comparten esta validación y el procesamiento idempotente. */
    public function handleSigned(array $fields, string $secret, string $account): array
    {
        if (!WebhookSignature::valid($fields, $secret)) {
            return ['invalid_signature', 400];
        }
        $error = self::validate($fields, $account);
        if ($error !== null) {
            return [$error, 422];
        }
        return $this->process($fields);
    }

    public function process(array $fields): array
    {
        try {
            $this->db->beginTransaction();
            $query = $this->db->prepare('SELECT id, codigo_orden, monto_total, estado, tuu_callback_signature, expira_en > NOW() AS reservation_active FROM ordenes WHERE codigo_orden = ? FOR UPDATE');
            $query->execute([$fields['x_reference']]);
            $order = $query->fetch(PDO::FETCH_ASSOC);
            if (!$order || !hash_equals((string) $order['codigo_orden'], $fields['x_reference'])) {
                $this->db->rollBack();
                return ['order_not_found', 404];
            }
            $amount = explode('.', $fields['x_amount'], 2)[0];
            if ((ltrim($amount, '0') ?: '0') !== (string) $order['monto_total']) {
                $this->db->rollBack();
                return ['amount_mismatch', 409];
            }
            if ($fields['x_result'] === 'pending') {
                $this->db->commit();
                return ['ok', 200];
            }
            if ($fields['x_result'] === 'completed') {
                $transactionId = $fields['x_transaction_id'] ?? null;
                if ($transactionId !== null && (!is_string($transactionId) || $transactionId === '' || strlen($transactionId) > 255)) {
                    $this->db->rollBack();
                    return ['invalid_transaction', 422];
                }
                if ($order['estado'] === 'PAGADA') {
                    $this->db->commit();
                    return ['ok', 200];
                }
                if ($order['estado'] !== 'PENDIENTE') {
                    $this->db->rollBack();
                    return ['order_state_conflict', 409];
                }
                if (!(int) $order['reservation_active']) {
                    $this->db->rollBack();
                    return ['order_expired', 409];
                }
                $items = $this->db->prepare('SELECT oi.producto_id, oi.cantidad FROM orden_items oi JOIN productos p ON p.id = oi.producto_id WHERE oi.orden_id = ? ORDER BY p.sku FOR UPDATE');
                $items->execute([$order['id']]);
                $rows = $items->fetchAll(PDO::FETCH_ASSOC);
                if ($rows === []) {
                    throw new \RuntimeException('Orden sin artículos.');
                }
                $stock = $this->db->prepare('UPDATE productos SET stock = stock - ? WHERE id = ? AND stock >= ?');
                foreach ($rows as $item) {
                    $stock->execute([(int) $item['cantidad'], (int) $item['producto_id'], (int) $item['cantidad']]);
                    if ($stock->rowCount() !== 1) {
                        throw new \RuntimeException('Stock insuficiente para confirmar pago.');
                    }
                }
                $update = $this->db->prepare("UPDATE ordenes SET estado = 'PAGADA', tuu_transaction_id = ?, tuu_callback_signature = ?, tuu_message = NULL WHERE id = ? AND estado = 'PENDIENTE'");
                $update->execute([$transactionId, $fields['x_signature'], $order['id']]);
                if ($update->rowCount() !== 1) {
                    throw new \RuntimeException('No se pudo confirmar la orden.');
                }
                $this->db->commit();
                return ['ok', 200];
            }
            if ($order['estado'] === 'PENDIENTE') {
                $message = substr((string) ($fields['x_message'] ?? ''), 0, 1000);
                $update = $this->db->prepare("UPDATE ordenes SET estado = 'RECHAZADA', tuu_callback_signature = ?, tuu_message = ? WHERE id = ? AND estado = 'PENDIENTE'");
                $update->execute([$fields['x_signature'], $message, $order['id']]);
                if ($update->rowCount() !== 1) {
                    throw new \RuntimeException('No se pudo actualizar la orden.');
                }
            } elseif ($order['estado'] !== 'RECHAZADA' || !is_string($order['tuu_callback_signature'])
                || !hash_equals($order['tuu_callback_signature'], $fields['x_signature'])) {
                $this->db->rollBack();
                return ['order_state_conflict', 409];
            }
            $this->db->commit();
            return ['ok', 200];
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }
}