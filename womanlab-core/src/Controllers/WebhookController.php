<?php
declare(strict_types=1);

namespace WomanLab\Controllers;

use PDO;
use Throwable;
use WomanLab\Services\TuuCallback;
use WomanLab\Services\WebhookSignature;
use WomanLab\Support\ApiResponse;

final class WebhookController
{
    public static function handle(PDO $db): never
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            ApiResponse::fail('method_not_allowed', 405);
        }
        $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0]));
        if ($contentType !== 'application/x-www-form-urlencoded') {
            ApiResponse::fail('unsupported_media_type', 415);
        }
        $secret = $_ENV['TUU_API_KEY'] ?? '';
        $account = $_ENV['TUU_ACCOUNT_ID'] ?? '';
        if ($secret === '' || $account === '') {
            ApiResponse::fail('webhook_not_configured', 503);
        }
        $raw = file_get_contents('php://input', false, null, 0, 65537);
        $fields = is_string($raw) ? WebhookSignature::parse($raw) : null;
        if ($fields === null) {
            ApiResponse::fail('invalid_form', 400);
        }
        if (!WebhookSignature::valid($fields, $secret)) {
            ApiResponse::fail('invalid_signature', 401);
        }
        $error = TuuCallback::validate($fields, $account);
        if ($error !== null) {
            ApiResponse::fail($error, 422);
        }
        try {
            [$result, $status] = (new TuuCallback($db, CheckoutController::sandboxEnabled($_ENV)))->process($fields);
        } catch (Throwable $error) {
            error_log('TUU callback processing failed: ' . get_class($error));
            ApiResponse::fail('webhook_processing_failed', 500);
        }
        if ($status !== 200) {
            ApiResponse::fail($result, $status);
        }
        ApiResponse::json(['status' => 'ok']);
    }
}