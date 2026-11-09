<?php
declare(strict_types=1);

namespace WomanLab\Services;

use RuntimeException;

final class CurlTuuTransport implements TuuTransport
{
    public function __construct(private readonly ?string $errorLogPath = null)
    {
    }

    public function post(string $url, array $payload, ?string $apiKey = null): array
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
        } elseif ($apiKey !== null && $apiKey !== '') {
            $headers[] = 'ApiKey: ' . $apiKey;
        }
        if (!isset($payload['x_account_id']) && isset($payload['reference'])) {
            $headers[] = 'Idempotency-Key: ' . ($payload['reference'] ?? '');
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        if (!is_string($body) || !in_array($status, [200, 201], true)) {
            $safeBody = is_string($body) ? self::sanitizeResponse($body, $payload, $apiKey) : '';
            $message = date('c') . ' TUU request failed: HTTP ' . $status
                . ($error !== '' ? ' (transport error: ' . substr($error, 0, 200) . ')' : '')
                . ($safeBody !== '' ? ' response=' . $safeBody : '') . PHP_EOL;
            $logPath = $this->errorLogPath ?? dirname(__DIR__, 2) . '/logs/tuu_errors.log';
            if (@file_put_contents($logPath, $message, FILE_APPEND | LOCK_EX) === false) {
                error_log(trim($message));
            }
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

    private static function sanitizeResponse(string $body, array $payload, ?string $apiKey): string
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $redact = static function (array $value) use (&$redact): array {
                foreach ($value as $key => $item) {
                    if (preg_match('/secret|api.?key|signature|token|password|email|phone|card/i', (string) $key)) {
                        $value[$key] = '[REDACTED]';
                    } elseif (is_array($item)) {
                        $value[$key] = $redact($item);
                    }
                }
                return $value;
            };
            $encoded = json_encode($redact($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($encoded)) {
                $body = $encoded;
            }
        }

        foreach (['x_signature', 'x_customer_email', 'x_customer_phone'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $body = str_replace($value, '[REDACTED]', $body);
            }
        }
        if ($apiKey !== null && $apiKey !== '') {
            $body = str_replace($apiKey, '[REDACTED]', $body);
        }
        return substr($body, 0, 2048);
    }
}