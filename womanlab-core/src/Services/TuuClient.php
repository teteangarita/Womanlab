<?php
declare(strict_types=1);

namespace WomanLab\Services;

use RuntimeException;
use WomanLab\Support\DebugLog;

final class TuuClient
{
    public function __construct(private readonly ?TuuTransport $transport = null)
    {
    }

    public function createCheckout(array $order, array $items, array $customer): array
    {
        $secretKey = $_ENV['TUU_SECRET_KEY'] ?? '';
        $checkoutUrl = $_ENV['TUU_API_URL'] ?? '';
        $accountId = $_ENV['TUU_ACCOUNT_ID'] ?? '';
        $appUrl = rtrim($_ENV['APP_URL'] ?? '', '/');

        if ($secretKey === '' || $accountId === '' || $checkoutUrl === '' || $appUrl === '') {
            throw new RuntimeException('La configuración de TUU está incompleta.');
        }

        $appParts = parse_url($appUrl);
        if (!is_array($appParts)) {
            throw new RuntimeException('APP_URL no es una URL válida.');
        }
        $localDevelopment = ($_ENV['APP_ENV'] ?? '') === 'development'
            && ($appParts['scheme'] ?? '') === 'http'
            && in_array(strtolower((string) ($appParts['host'] ?? '')), ['localhost', '127.0.0.1'], true);
        if (!filter_var($appUrl, FILTER_VALIDATE_URL)
            || (($appParts['scheme'] ?? '') !== 'https' && !$localDevelopment) || empty($appParts['host'])
            || isset($appParts['user']) || isset($appParts['pass'])
            || isset($appParts['query']) || isset($appParts['fragment']) || !empty($appParts['path'])) {
            throw new RuntimeException('APP_URL debe ser un origen HTTPS válido, salvo localhost en desarrollo.');
        }

        $nameParts = preg_split('/\s+/u', trim((string) ($customer['name'] ?? '')), 2) ?: [];
        $reference = (string) $order['codigo_orden'];
        $basePath = function_exists('womanlab_base_path') ? womanlab_base_path() : '';
        $publicUrl = $appUrl . $basePath;
        $cancelUrl = $publicUrl . '/pago/cancelado.php';
        $completeUrl = $publicUrl . '/pago/exito.php';
        $webhookUrl = $publicUrl . '/api/webhook_tuu.php';
        $payload = [
            'x_account_id' => $accountId,
            'x_amount' => (int) $order['monto_total'],
            'x_currency' => 'CLP',
            'x_customer_email' => (string) ($customer['email'] ?? ''),
            'x_customer_first_name' => $nameParts[0] ?? '',
            'x_customer_last_name' => $nameParts[1] ?? 'Cliente',
            'x_customer_phone' => (string) (($customer['phone'] ?? '') ?: '+56900000000'),
            'x_description' => 'Compra Womanlab - Orden ' . $reference,
            'x_reference' => $reference,
            'x_shop_name' => 'Womanlab',
            'x_url_callback' => $webhookUrl,
            'x_url_cancel' => $cancelUrl,
            'x_url_complete' => $completeUrl,
        ];
        $payload['x_signature'] = self::signFields($payload, $secretKey);

        $debugFields = array_filter($payload, static fn (string $k): bool => $k !== 'x_signature', ARRAY_FILTER_USE_KEY);
        ksort($debugFields, SORT_STRING);
        $debugCanonical = '';
        foreach ($debugFields as $k => $v) {
            $debugCanonical .= $k . (string) $v;
        }
        DebugLog::write('TUU createCheckout payload final', [
            'checkoutUrl' => $checkoutUrl,
            'order' => $order,
            'customer' => $customer,
            'publicUrl' => $publicUrl,
            'basePath' => $basePath,
            'canonical_string_firmada' => $debugCanonical,
            'payload' => $payload,
        ]);

        $response = $this->request($checkoutUrl, $payload);
        DebugLog::write('TUU createCheckout respuesta decodificada', $response);
        $paymentUrl = $response['url'] ?? $response['checkout_url'] ?? $response['payment_url'] ?? null;
        $token = $response['token'] ?? $response['session_token'] ?? null;
        if (!is_string($paymentUrl) || $paymentUrl === '') {
            throw new RuntimeException('La respuesta de TUU no contiene una URL de pago.');
        }

        $parsed = parse_url($paymentUrl);
        $allowedHosts = array_filter(array_map('trim', explode(',', $_ENV['TUU_PAYMENT_HOSTS'] ?? '')));
        if (($parsed['scheme'] ?? '') !== 'https'
            || !isset($parsed['host'])
            || ($allowedHosts !== [] && !in_array(strtolower($parsed['host']), array_map('strtolower', $allowedHosts), true))) {
            throw new RuntimeException('TUU devolvió un dominio de pago no autorizado.');
        }

        return ['payment_url' => $paymentUrl, 'token' => $token, 'transaction_id' => $response['transaction_id'] ?? null];
    }

    public static function signFields(array $fields, string $secret): string
    {
        $signedFields = array_filter($fields, static fn (string $key): bool => str_starts_with($key, 'x_') && $key !== 'x_signature', ARRAY_FILTER_USE_KEY);
        ksort($signedFields, SORT_STRING);
        $canonical = '';
        foreach ($signedFields as $key => $value) {
            if (!is_scalar($value) && $value !== null) {
                throw new RuntimeException('Campo de firma TUU inválido.');
            }
            $canonical .= $key . (string) $value;
        }
        return hash_hmac('sha256', $canonical, trim($secret));
    }

    /** Solo claves x_* sin x_signature, ksort SORT_STRING, clave.valor sin separadores. */
    public function generarFirma(array $datos, string $secretKey): string
    {
        return self::signFields($datos, $secretKey);
    }

    public function verificarFirma(array $payload, string $secretKey): bool
    {
        if (empty($payload['x_signature']) || trim($secretKey) === '') {
            return false;
        }
        return hash_equals($this->generarFirma($payload, $secretKey), (string) $payload['x_signature']);
    }

    public function issueDocument(array $order, array $items): array
    {
        $endpoint = $_ENV['TUU_DTE_URL'] ?? '';
        $apiKey = $_ENV['TUU_SECRET_KEY'] ?? '';
        if ($endpoint === '') {
            throw new RuntimeException('TUU_DTE_URL no está configurado.');
        }

        return $this->request($endpoint, [
            'reference' => $order['codigo_orden'],
            'amount' => (int) $order['monto_total'],
            'currency' => 'CLP',
            'customer' => [
                'name' => $order['cliente_nombre'],
                'email' => $order['cliente_email'],
                'phone' => $order['cliente_telefono'],
            ],
            'items' => $items,
        ], $apiKey);
    }

    private function request(string $url, array $payload, ?string $apiKey = null): array
    {
        if (!str_starts_with($url, 'https://')) {
            throw new RuntimeException('La API TUU requiere HTTPS.');
        }
        return ($this->transport ?? new CurlTuuTransport())->post($url, $payload, $apiKey);
    }
}