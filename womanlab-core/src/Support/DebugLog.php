<?php
declare(strict_types=1);

namespace WomanLab\Support;

// TEMPORAL: depuración del checkout; eliminar junto con sus llamadas.
final class DebugLog
{
    public static function write(string $step, mixed $data = null): void
    {
        $line = date('c') . ' [' . $step . ']';
        if ($data !== null) {
            $line .= ' ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRETTY_PRINT);
        }
        @file_put_contents(dirname(__DIR__, 2) . '/logs/tuu_debug.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
