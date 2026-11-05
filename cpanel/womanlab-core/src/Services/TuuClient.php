<?php
declare(strict_types=1);

namespace WomanLab\Services;

use RuntimeException;

final class TuuClient
{
    public function __construct(private readonly ?TuuTransport $transport = null)
    {
    }

    public function createCheckout(array $order, array $items, array $customer): array
    {
        $apiKey = $_ENV['TUU_API_KEY'] ?? '';
        $checkoutUrl = $_ENV['TUU_CHECKOUT_URL'] ?? 'https://api.haulmer.com/v2/payment/checkout';
        $returnUrl = $_ENV['TUU_RETURN_URL'] ?? '';
        $webhookUrl = $_ENV['TUU_WEBHOOK_URL'] ?? '';

        if ($apiKey === '' || $returnUrl === '' || $webhookUrl === '') {
            throw new RuntimeException('La configuración de TUU está incompleta.');
        }

        $payload = [
            'buy_order' => $order['codigo_orden'],
            'amount' => (int) $order['monto_total'],
            'currency' => 'CLP',
            'return_url' => $returnUrl . '?codigo_orden=' . rawurlencode($order['codigo_orden']),
            'webhook_url' => $webhookUrl,
            'customer' => $customer,
            'items' => array_map(static fn (array $item): array => [
                'sku' => $item['sku'],
                'name' => $item['nombre'],
                'quantity' => (int) $item['cantidad'],
                'unit_price' => (int) $item['precio_unitario'],
            ], $items),
        ];

        $response = $this->request($checkoutUrl, $apiKey, $payload);
        $paymentUrl = $response['url'] ?? $response['payment_url'] ?? $response['checkout_url'] ?? null;
        $token = $response['token'] ?? $response['session_token'] ?? null;
        if (!is_string($paymentUrl) || !is_string($token) || $token === '') {
            throw new RuntimeException('La respuesta de TUU no contiene URL o token de sesión.');
        }

        $parsed = parse_url($paymentUrl);
        $allowedHosts = array_filter(array_map('trim', explode(',', $_ENV['TUU_PAYMENT_HOSTS'] ?? '')));
        if (($parsed['scheme'] ?? '') !== 'https'
            || !isset($parsed['host'])
            || !in_array(strtolower($parsed['host']), array_map('strtolower', $allowedHosts), true)) {
            throw new RuntimeException('TUU devolvió un dominio de pago no autorizado.');
        }

        return ['url' => $paymentUrl, 'token' => $token, 'transaction_id' => $response['transaction_id'] ?? null];
    }

    public function issueDocument(array $order, array $items): array
    {
        $endpoint = $_ENV['TUU_DTE_URL'] ?? '';
        $apiKey = $_ENV['TUU_API_KEY'] ?? '';
        if ($endpoint === '') {
            throw new RuntimeException('TUU_DTE_URL no está configurado.');
        }

        return $this->request($endpoint, $apiKey, [
            'reference' => $order['codigo_orden'],
            'amount' => (int) $order['monto_total'],
            'currency' => 'CLP',
            'customer' => [
                'name' => $order['cliente_nombre'],
                'email' => $order['cliente_email'],
                'phone' => $order['cliente_telefono'],
            ],
            'items' => $items,
        ]);
    }

    private function request(string $url, string $apiKey, array $payload): array
    {
        if (!str_starts_with($url, 'https://')) {
            throw new RuntimeException('La API TUU requiere HTTPS.');
        }
        return ($this->transport ?? new CurlTuuTransport())->post($url, $apiKey, $payload);
    }
}