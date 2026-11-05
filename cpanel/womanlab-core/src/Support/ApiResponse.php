<?php
declare(strict_types=1);

namespace WomanLab\Support;

final class ApiResponse
{
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    public static function input(): array
    {
        if (!in_array(strtoupper($_SERVER['REQUEST_METHOD'] ?? ''), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            self::json(['error' => 'method_not_allowed'], 405);
        }

        $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
        if (!str_contains($contentType, 'application/json')) {
            self::json(['error' => 'invalid_content_type'], 415);
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || strlen($raw) > 65536) {
            self::json(['error' => 'invalid_request'], 400);
        }

        try {
            $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::json(['error' => 'invalid_json'], 400);
        }

        if (!is_array($payload)) {
            self::json(['error' => 'invalid_json'], 400);
        }

        return $payload;
    }

    public static function fail(string $message, int $status = 400): never
    {
        self::json(['error' => $message], $status);
    }
}