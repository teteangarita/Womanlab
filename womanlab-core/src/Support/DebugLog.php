<?php
declare(strict_types=1);

namespace WomanLab\Support;

// TEMPORAL: depuración del checkout; eliminar junto con sus llamadas.
final class DebugLog
{
    // Los pasos WEBHOOK* se separan en webhook_tuu.log.
    public static function write(string $step, mixed $data = null, ?string $file = null): void
    {
        $file ??= str_starts_with($step, 'WEBHOOK') ? 'webhook_tuu.log' : 'tuu_debug.log';
        $line = date('c') . ' [' . $step . ']';
        if ($data !== null) {
            $line .= ' ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRETTY_PRINT);
        }
        @file_put_contents(dirname(__DIR__, 2) . '/logs/' . basename($file), $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
