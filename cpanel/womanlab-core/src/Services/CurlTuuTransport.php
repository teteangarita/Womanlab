<?php
declare(strict_types=1);

namespace WomanLab\Services;

use RuntimeException;

final class CurlTuuTransport implements TuuTransport
{
    public function post(string $url, string $apiKey, array $payload): array
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('La extensión cURL no está habilitada.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('No se pudo iniciar cURL.');
        }
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if (isset($payload['x_account_id'])) {
            $headers[] = 'X-REDIRECT: false';
        } else {
            $headers[] = 'ApiKey: ' . $apiKey;
            $headers[] = 'Idempotency-Key: ' . ($payload['reference'] ?? '');
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($body) || $status < 200 || $status >= 300) {
            error_log('TUU request failed: HTTP ' . $status . ' ' . $error);
            throw new RuntimeException('No se pudo completar la solicitud TUU.');
        }
        if (isset($payload['x_account_id']) && filter_var(trim($body), FILTER_VALIDATE_URL)) {
            return ['url' => trim($body)];
        }
        try {
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('TUU devolvió una respuesta inválida.');
        }
        if (is_string($decoded) && isset($payload['x_account_id'])) {
            return ['url' => $decoded];
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('TUU devolvió una respuesta inválida.');
        }
        return $decoded;
    }
}