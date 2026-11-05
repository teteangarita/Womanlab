<?php
declare(strict_types=1);

namespace WomanLab\Controllers;

use PDO;
use Throwable;
use WomanLab\Services\CartService;
use WomanLab\Services\CheckoutException;
use WomanLab\Services\TuuClient;
use WomanLab\Support\ApiResponse;
use WomanLab\Support\Auth;

final class CheckoutController
{
    public static function create(PDO $db): never
    {
        try {
            $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
            if ($origin !== '') {
                $expected = parse_url($_ENV['APP_URL'] ?? '');
                $received = parse_url($origin);
                $expectedScheme = strtolower((string) ($expected['scheme'] ?? ''));
                $receivedScheme = strtolower((string) ($received['scheme'] ?? ''));
                $expectedPort = $expected['port'] ?? ($expectedScheme === 'https' ? 443 : 80);
                $receivedPort = $received['port'] ?? ($receivedScheme === 'https' ? 443 : 80);
                if ($expectedScheme === '' || $receivedScheme !== $expectedScheme
                    || strtolower((string) ($received['host'] ?? '')) !== strtolower((string) ($expected['host'] ?? ''))
                    || $receivedPort !== $expectedPort) {
                    ApiResponse::fail('origin_not_allowed', 403);
                }
            }
            $payload = ApiResponse::input();
            $customer = $payload['cliente'] ?? null;
            if (!is_array($customer)) {
                ApiResponse::fail('invalid_customer', 422);
            }

            $name = trim(strip_tags((string) ($customer['nombre'] ?? '')));
            $email = strtolower(trim((string) ($customer['email'] ?? '')));
            $phone = preg_replace('/[^0-9+() .-]/', '', (string) ($customer['telefono'] ?? '')) ?? '';
            if ($name === '' || mb_strlen($name) > 160 || !filter_var($email, FILTER_VALIDATE_EMAIL)
                || strlen($email) > 190 || strlen($phone) < 8 || strlen($phone) > 40) {
                ApiResponse::fail('invalid_customer', 422);
            }

            $items = $payload['items'] ?? null;
            if (!is_array($items)) {
                ApiResponse::fail('invalid_items', 422);
            }

            $user = Auth::user();
            $order = (new CartService($db))->createPendingOrder($items, [
                'nombre' => $name,
                'email' => $email,
                'telefono' => $phone,
            ], $user ? (int) $user['id'] : null);

            try {
                $payment = (new TuuClient())->createCheckout($order, $order['items'], [
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                ]);
                $update = $db->prepare('UPDATE ordenes SET tuu_token = ?, tuu_transaction_id = ? WHERE id = ? AND estado = \'PENDIENTE\'');
                $update->execute([$payment['token'], $payment['transaction_id'], $order['id']]);
            } catch (Throwable $error) {
                $db->prepare("UPDATE ordenes SET estado = 'RECHAZADA' WHERE id = ? AND estado = 'PENDIENTE'")->execute([$order['id']]);
                throw $error;
            }

            ApiResponse::json([
                'codigo_orden' => $order['codigo_orden'],
                'redirect_url' => $payment['url'],
            ], 201);
        } catch (Throwable $error) {
            error_log('Checkout failed: ' . $error->getMessage());
            if ($error instanceof CheckoutException) {
                ApiResponse::json(['error' => $error->getMessage()], $error->httpStatus);
            }
            ApiResponse::json(['error' => 'No se pudo iniciar el pago. Revisa el pedido o inténtalo más tarde.'], 503);
        }
    }
}