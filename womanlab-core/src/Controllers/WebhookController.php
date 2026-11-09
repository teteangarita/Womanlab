<?php
declare(strict_types=1);

namespace WomanLab\Controllers;

use PDO;
use Throwable;
use WomanLab\Services\TuuCallback;
use WomanLab\Services\WebhookSignature;
use WomanLab\Support\ApiResponse;
use WomanLab\Support\DebugLog;

final class WebhookController
{
    public static function handle(PDO $db): never
    {
        $debugHeaders = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_') || in_array($k, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'REQUEST_METHOD', 'REQUEST_URI', 'REMOTE_ADDR'], true)) {
                $debugHeaders[$k] = $v;
            }
        }
        DebugLog::write('WEBHOOK 1 recibido', [
            'headers' => $debugHeaders,
            'raw_body' => file_get_contents('php://input', false, null, 0, 65537),
            'GET' => $_GET,
            'POST' => $_POST,
        ]);
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            DebugLog::write('WEBHOOK RECHAZO', 'method_not_allowed');
            ApiResponse::fail('method_not_allowed', 405);
        }
        $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0]));
        if ($contentType !== 'application/x-www-form-urlencoded') {
            DebugLog::write('WEBHOOK RECHAZO', ['unsupported_media_type' => $contentType]);
            ApiResponse::fail('unsupported_media_type', 415);
        }
        $secret = $_ENV['TUU_SECRET_KEY'] ?? '';
        $account = $_ENV['TUU_ACCOUNT_ID'] ?? '';
        if ($secret === '' || $account === '') {
            DebugLog::write('WEBHOOK RECHAZO', 'webhook_not_configured');
            ApiResponse::fail('webhook_not_configured', 503);
        }
        $raw = file_get_contents('php://input', false, null, 0, 65537);
        $fields = is_string($raw) ? WebhookSignature::parse($raw) : null;
        if ($fields === null) {
            DebugLog::write('WEBHOOK RECHAZO', 'invalid_form');
            ApiResponse::fail('invalid_form', 400);
        }
        if (!WebhookSignature::valid($fields, $secret)) {
            $debugSigned = array_filter($fields, static fn (string $k): bool => str_starts_with($k, 'x_') && $k !== 'x_signature', ARRAY_FILTER_USE_KEY);
            ksort($debugSigned, SORT_STRING);
            $debugCanonical = '';
            foreach ($debugSigned as $k => $v) {
                $debugCanonical .= $k . $v;
            }
            DebugLog::write('WEBHOOK RECHAZO firma inválida', [
                'fields' => $fields,
                'canonical' => $debugCanonical,
                'firma_recibida' => $fields['x_signature'] ?? null,
                'firma_calculada' => hash_hmac('sha256', $debugCanonical, $secret),
            ]);
            error_log('TUU callback rejected: invalid signature from ' . substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 64));
            ApiResponse::fail('invalid_signature', 400);
        }
        $error = TuuCallback::validate($fields, $account);
        if ($error !== null) {
            DebugLog::write('WEBHOOK RECHAZO validación', $error);
            ApiResponse::fail($error, 422);
        }
        try {
            [$result, $status] = (new TuuCallback($db))->process($fields);
        } catch (Throwable $error) {
            DebugLog::write('WEBHOOK ERROR procesando', ['class' => $error::class, 'message' => $error->getMessage(), 'file' => $error->getFile() . ':' . $error->getLine()]);
            error_log('TUU callback processing failed: ' . get_class($error));
            ApiResponse::fail('webhook_processing_failed', 500);
        }
        DebugLog::write('WEBHOOK resultado process()', ['result' => $result, 'status' => $status]);
        if ($status !== 200) {
            ApiResponse::fail($result, $status);
        }
        ApiResponse::json(['status' => 'ok']);
    }
}