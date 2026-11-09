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
use WomanLab\Support\DebugLog;

final class CheckoutController
{
    public static function checkoutEnabled(array $env): bool
    {
        return ($env['TUU_CHECKOUT_ENABLED'] ?? '') === '1'
            && !empty($env['TUU_API_URL'])
            && !empty($env['TUU_ACCOUNT_ID'])
            && !empty($env['TUU_SECRET_KEY']);
    }

    public static function create(PDO $db): never
    {
        try {
            DebugLog::write('CHECKOUT 1 recibido', [
                'method' => $_SERVER['REQUEST_METHOD'] ?? null,
                'uri' => $_SERVER['REQUEST_URI'] ?? null,
                'origin' => $_SERVER['HTTP_ORIGIN'] ?? null,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'APP_URL' => $_ENV['APP_URL'] ?? null,
                'APP_ENV' => $_ENV['APP_ENV'] ?? null,
                'enabled' => self::checkoutEnabled($_ENV),
                'TUU_CHECKOUT_ENABLED' => $_ENV['TUU_CHECKOUT_ENABLED'] ?? null,
                'TUU_API_URL' => $_ENV['TUU_API_URL'] ?? null,
                'TUU_ACCOUNT_ID' => $_ENV['TUU_ACCOUNT_ID'] ?? null,
                'TUU_SECRET_KEY_set' => !empty($_ENV['TUU_SECRET_KEY']),
            ]);
            if (!self::checkoutEnabled($_ENV)) {
                ApiResponse::json(['error' => 'payment_not_available', 'message' => 'El pago en línea aún no está habilitado.'], 503);
            }
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
            DebugLog::write('CHECKOUT 2 body recibido', $payload);
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

            DebugLog::write('CHECKOUT 3 orden pendiente creada', $order);
            try {
                $payment = (new TuuClient())->createCheckout($order, $order['items'], [
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                ]);
                $update = $db->prepare('UPDATE ordenes SET tuu_token = ?, tuu_transaction_id = ? WHERE id = ? AND estado = \'PENDIENTE\'');
                $update->execute([$payment['token'], $payment['transaction_id'], $order['id']]);
                DebugLog::write('CHECKOUT 4 pago creado', $payment);
            } catch (Throwable $error) {
                DebugLog::write('CHECKOUT ERROR creando pago (orden RECHAZADA)', ['class' => $error::class, 'message' => $error->getMessage(), 'file' => $error->getFile() . ':' . $error->getLine()]);
                $db->prepare("UPDATE ordenes SET estado = 'RECHAZADA' WHERE id = ? AND estado = 'PENDIENTE'")->execute([$order['id']]);
                throw $error;
            }

            ApiResponse::json([
                'success' => true,
                'codigo_orden' => $order['codigo_orden'],
                'payment_url' => $payment['payment_url'],
            ], 201);
        } catch (Throwable $error) {
            error_log('Checkout failed: ' . $error->getMessage());
            DebugLog::write('CHECKOUT ERROR final', ['class' => $error::class, 'message' => $error->getMessage(), 'file' => $error->getFile() . ':' . $error->getLine(), 'trace' => $error->getTraceAsString()]);
            if ($error instanceof CheckoutException) {
                ApiResponse::json(['error' => $error->getMessage()], $error->httpStatus);
            }
            ApiResponse::json([
                'error' => 'payment_provider_error',
                'message' => 'No se pudo crear la sesión de pago. Revisa el registro privado del servidor o contacta a TUU.',
            ], 503);
        }
    }
}