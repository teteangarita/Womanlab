<?php
declare(strict_types=1);

namespace WomanLab\Services;

final class WebhookSignature
{
    /** Toma $_POST o, si está vacío, el cuerpo crudo, sin depender del Content-Type. */
    public static function capture(array $post, string $raw): array
    {
        $payload = !empty($post) ? $post : [];
        if (empty($payload)) {
            parse_str($raw, $payload);
        }
        return $payload;
    }

    public static function parse(string $raw): ?array
    {
        if ($raw === '' || strlen($raw) > 65536) {
            return null;
        }
        $fields = [];
        foreach (explode('&', $raw) as $pair) {
            if ($pair === '' || !str_contains($pair, '=') || preg_match('/%(?![0-9a-fA-F]{2})/', $pair)) {
                return null;
            }
            [$encodedKey, $encodedValue] = explode('=', $pair, 2);
            $key = urldecode($encodedKey);
            $value = urldecode($encodedValue);
            if ($key === '' || str_contains($key, '[') || str_contains($key, ']')
                || array_key_exists($key, $fields) || !preg_match('//u', $key) || !preg_match('//u', $value)) {
                return null;
            }
            $fields[$key] = $value;
        }
        return $fields;
    }

    public static function valid(array $fields, string $secret): bool
    {
        $signature = is_string($fields['x_signature'] ?? null) ? strtolower($fields['x_signature']) : '';
        if ($secret === '' || !is_string($signature) || preg_match('/^[a-f0-9]{64}$/', $signature) !== 1) {
            return false;
        }
        return hash_equals(TuuClient::signFields($fields, $secret), $signature);
    }
}