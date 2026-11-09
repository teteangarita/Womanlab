<?php
declare(strict_types=1);

namespace WomanLab\Services;

final class WebhookSignature
{
    public static function valid(string $raw, string $secret, string $signature): bool
    {
        if ($secret === '') {
            return false;
        }
        $signature = preg_replace('/^sha256=/i', '', trim($signature)) ?? '';
        return preg_match('/^[a-f0-9]{64}$/i', $signature) === 1
            && hash_equals(hash_hmac('sha256', $raw, $secret), strtolower($signature));
    }
}