<?php
declare(strict_types=1);

/*
 * Diagnóstico de la integración TUU (firma HMAC, lectura de claves y callback).
 *
 *   CLI: php tienda_public/test_tuu_diagnostico.php
 *   Web: https://TU_DOMINIO/test_tuu_diagnostico.php?token=VALOR_DE_TUU_DIAG_TOKEN
 *        (sin TUU_DIAG_TOKEN definido, el acceso web responde 404).
 *
 * No imprime la llave secreta (solo longitud y huella). Elimínalo del servidor tras usarlo.
 */

use WomanLab\Services\TuuCallback;
use WomanLab\Services\TuuClient;
use WomanLab\Services\WebhookSignature;
use WomanLab\Support\TuuConfig;

$isCli = PHP_SAPI === 'cli';
$notFound = static function (): never {
    http_response_code(404);
    exit;
};

$corePath = require __DIR__ . '/api/_core_path.php';
$autoload = dirname($corePath) . '/vendor/autoload.php';
if (!is_file($autoload) || !is_file($corePath . '/config/bootstrap.php')) {
    if (!$isCli) {
        $notFound();
    }
    fwrite(STDERR, "No se encontró el núcleo o vendor/autoload.php (núcleo buscado en: $corePath).\n");
    exit(2);
}
require_once $autoload;

$envFile = $corePath . '/.env';
if (!$isCli) {
    $expectedToken = TuuConfig::get('TUU_DIAG_TOKEN', $envFile)['value'] ?? '';
    $givenToken = $_GET['token'] ?? '';
    if ($expectedToken === '' || !is_string($givenToken) || !hash_equals($expectedToken, $givenToken)) {
        $notFound();
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
}
ob_start();

$useColor = $isCli && function_exists('stream_isatty') && stream_isatty(STDOUT);
$paint = static fn (string $text, string $code): string => $useColor ? "\033[{$code}m{$text}\033[0m" : $text;
$failures = 0;
$warnings = 0;

$line = static function (string $text = ''): void {
    echo $text . PHP_EOL;
};
$title = static function (string $text) use ($line): void {
    $line();
    $line(str_repeat('=', 78));
    $line($text);
    $line(str_repeat('=', 78));
};
$check = static function (bool $ok, string $label, string $detail = '') use (&$failures, $line, $paint): void {
    if (!$ok) {
        $failures++;
    }
    $line(($ok ? $paint('[ OK  ]', '32') : $paint('[FALLA]', '31')) . ' ' . $label);
    if ($detail !== '') {
        $line('         ' . str_replace(PHP_EOL, PHP_EOL . '         ', $detail));
    }
};
$warn = static function (string $label, string $detail = '') use (&$warnings, $line, $paint): void {
    $warnings++;
    $line($paint('[AVISO]', '33') . ' ' . $label);
    if ($detail !== '') {
        $line('         ' . str_replace(PHP_EOL, PHP_EOL . '         ', $detail));
    }
};
$describe = static function (string $name, ?string $value): string {
    if ($value === null || $value === '') {
        return '(vacío o no definido)';
    }
    if ($name === 'TUU_SECRET_KEY') {
        return sprintf('definida, %d caracteres, huella sha256 %s', strlen($value), substr(hash('sha256', $value), 0, 8));
    }
    return $value;
};
// Implementación independiente de la documentación TUU, para contrastarla con TuuClient.
$docSign = static function (array $data, string $secret): array {
    $filtered = array_filter($data, static fn ($key): bool => str_starts_with((string) $key, 'x_') && $key !== 'x_signature', ARRAY_FILTER_USE_KEY);
    ksort($filtered, SORT_STRING);
    $canonical = '';
    foreach ($filtered as $key => $value) {
        $canonical .= $key . ($value === null ? '' : (string) $value);
    }
    return [$canonical, hash_hmac('sha256', $canonical, $secret)];
};
$diff = static function (string $internal, string $expected): string {
    $limit = min(strlen($internal), strlen($expected));
    $position = 0;
    while ($position < $limit && $internal[$position] === $expected[$position]) {
        $position++;
    }
    if ($position === $limit && strlen($internal) === strlen($expected)) {
        return 'cadenas idénticas';
    }
    return sprintf("primera diferencia en el byte %d\n  interna : …%s\n  esperada: …%s", $position, substr($internal, $position, 40), substr($expected, $position, 40));
};

$line('Diagnóstico TUU - ' . date('c') . ' - PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ')');
$line('Núcleo: ' . $corePath);
$line('Archivo .env: ' . $envFile . (is_file($envFile) ? '' : ' (NO existe)'));

// ---------------------------------------------------------------- A
$title('A. Carga de variables de entorno y claves (antes del bootstrap)');
$fileVars = TuuConfig::readEnvFile($envFile);
$hints = [
    'getenv()' => 'las variables no llegan al proceso PHP (normal si solo existen en .env; el bootstrap las carga con Dotenv).',
    '$_ENV' => 'variables_order de php.ini probablemente no incluye "E"; no es crítico si Dotenv las carga.',
    '$_SERVER' => 'el servidor web no exporta las variables al entorno de la petición.',
    '.env' => 'falta womanlab-core/.env, no es legible o no define esa variable (revisa el nombre exacto).',
];
foreach (['TUU_SECRET_KEY', 'TUU_ACCOUNT_ID', 'TUU_API_URL'] as $name) {
    $line();
    $line($name);
    $sources = [
        'getenv()' => getenv($name),
        '$_ENV' => $_ENV[$name] ?? null,
        '$_SERVER' => $_SERVER[$name] ?? null,
        '.env' => $fileVars[$name] ?? null,
    ];
    foreach ($sources as $source => $value) {
        $value = is_string($value) ? $value : null;
        if ($value !== null && $value !== '') {
            $line('  ' . $paint('[ OK  ]', '32') . ' ' . str_pad($source, 10) . $describe($name, $value));
        } else {
            $line('  ' . $paint('[AVISO]', '33') . ' ' . str_pad($source, 10) . 'vacío o false -> ' . $hints[$source]);
            $warnings++;
        }
    }
}
$aliasInFile = array_values(array_filter(array_slice(TuuConfig::SECRET_NAMES, 1), static fn (string $alias): bool => ($fileVars[$alias] ?? '') !== ''));
if (($fileVars['TUU_SECRET_KEY'] ?? '') === '' && $aliasInFile !== []) {
    $warn('La llave está en .env bajo otro nombre: ' . implode(', ', $aliasInFile), 'El código la acepta como alias, pero renómbrala a TUU_SECRET_KEY.');
}

try {
    require_once $corePath . '/config/bootstrap.php';
    $line();
    $line('Bootstrap del núcleo cargado.');
} catch (Throwable $error) {
    $check(false, 'No se pudo cargar config/bootstrap.php', get_class($error) . ': ' . $error->getMessage());
}

$secretInfo = TuuConfig::resolveSecret($envFile);
$secret = $secretInfo['value'] ?? '';
$account = TuuConfig::get('TUU_ACCOUNT_ID', $envFile)['value'] ?? '';
$apiUrl = TuuConfig::get('TUU_API_URL', $envFile)['value'] ?? '';
$check($secret !== '', 'Llave secreta resuelta por algún mecanismo', 'origen: ' . $secretInfo['source'] . ' -> ' . $describe('TUU_SECRET_KEY', $secret));
$check(($_ENV['TUU_SECRET_KEY'] ?? '') !== '', 'Tras el bootstrap, $_ENV[TUU_SECRET_KEY] está disponible para checkout y webhook');
$check($account !== '', 'TUU_ACCOUNT_ID definido', $account);
$check(str_starts_with($apiUrl, 'https://'), 'TUU_API_URL definido y con HTTPS', $apiUrl);

// ---------------------------------------------------------------- B
$title('B. Concatenación y firma HMAC-SHA256');
$docSecret = 'KsHcnuUXF5ehiqbuzPJ8Vq2yw267xc48fuBZM5EnxMyIYfiIlLju3Lw0jF1RTDuibsHp3RQBhP8nuY9FvND2WBwsaKU1sId2VkVp5LmNFOikTInoSnFLhyijQXhGUDua';
$docVector = [
    'x_account_id' => '82121521', 'x_amount' => '16580', 'x_currency' => 'CLP',
    'x_customer_email' => 'test.api@haulmer.com', 'x_customer_first_name' => 'Test', 'x_customer_last_name' => 'Api',
    'x_customer_phone' => '+56987654321', 'x_description' => 'polera#222', 'x_reference' => '2231',
    'x_shop_name' => 'Integración 1',
    'x_url_callback' => 'https://transaction-handler.haulmer.xyz/callbackExample',
    'x_url_cancel' => 'https://transaction-handler.haulmer.xyz/payment/cancel',
    'x_url_complete' => 'https://transaction-handler.haulmer.xyz/payment/complete',
];
$docExpected = '56ef2554442d536c1df9128a41901a8e4335a711f25de9f53282009edf0cd152';
[$docCanonical, $docHash] = $docSign($docVector, $docSecret);
$line('B1. Vector publicado en la documentación TUU');
$line('  cadena : ' . $docCanonical);
$line('  hash   : ' . $docHash);
$line('  esperado: ' . $docExpected);
if (hash_equals($docExpected, $docHash)) {
    $check(true, 'El vector de la documentación coincide');
} else {
    $warn(
        'El vector de la documentación NO es reproducible con los campos publicados',
        "El ejemplo oficial termina en \"// ... otros campos x_*\": omite campos y no puede validarse tal cual.\nSe valida el algoritmo con un callback real de sandbox (B2)."
    );
}
$check(hash_equals($docHash, TuuClient::signFields($docVector, $docSecret)), 'TuuClient::signFields coincide con la implementación de referencia (vector B1)');

$line();
$line('B2. Retorno real firmado por el sandbox de TUU (orden ORD-20261109-7ADD233F)');
$sandboxSecret = 'yAk0dXTJLQzkeEWODsQWVpPX0bn7ND50qwoQrXgqqNiUyEpgxIPxPtoCgKeLNeh1upTw72JZx5O9x5IaAtPIGUAVcMNcsUSg3M0M8tgWdUb4F8qkS8I7rHpOUmZqzvfS';
$realFields = [
    'x_account_id' => '62224230', 'x_amount' => '1500.0', 'x_currency' => 'CLP',
    'x_reference' => 'ORD-20261109-7ADD233F', 'x_result' => 'completed',
    'x_timestamp' => '2026-11-09T19:13:32Z', 'x_message' => 'Transaccion aprobada',
];
$realSignature = 'fb523c45bdf8d629e584418237469e756468b4016d5ae2cb99407d4ec6544729';
[$realCanonical, $realHash] = $docSign($realFields, $sandboxSecret);
$line('  cadena : ' . $realCanonical);
$line('  hash   : ' . $realHash);
$check(hash_equals($realSignature, $realHash), 'La concatenación y el HMAC reproducen la firma real de TUU');
$check(hash_equals($realSignature, TuuClient::signFields($realFields, $sandboxSecret)), 'TuuClient::signFields reproduce la firma real de TUU');
$check(WebhookSignature::valid($realFields + ['x_signature' => $realSignature], $sandboxSecret), 'WebhookSignature::valid acepta ese callback real');

$line();
$line('B3. Tipos de dato (string vs int vs float vs null) en x_amount');
foreach (['string "1500"' => '1500', 'int 1500' => 1500, 'string "1500.0"' => '1500.0', 'float 1500.0' => 1500.0, 'null' => null] as $label => $amount) {
    [$canonical, $hash] = $docSign(['x_account_id' => '62224230', 'x_amount' => $amount], $sandboxSecret);
    $line(sprintf('  %-16s -> %-40s %s', $label, $canonical, substr($hash, 0, 12)));
}
[, $hashString] = $docSign(['x_amount' => '1500'], $sandboxSecret);
[, $hashInt] = $docSign(['x_amount' => 1500], $sandboxSecret);
[, $hashFloat] = $docSign(['x_amount' => 1500.0], $sandboxSecret);
[, $hashTuuString] = $docSign(['x_amount' => '1500.0'], $sandboxSecret);
$check($hashString === $hashInt, 'int 1500 y string "1500" generan la misma firma');
$check($hashFloat !== $hashTuuString, 'Un float 1500.0 NO equivale al string "1500.0" que envía TUU: usa siempre el valor recibido como string', 'PHP convierte float 1500.0 en "1500"; TUU firma "1500.0".');

// ---------------------------------------------------------------- C
$title('C. Simulación del callback de la orden ORD-20261109-3808E44C');
$orderFields = [
    'x_account_id' => '62224230', 'x_amount' => '1500', 'x_currency' => 'CLP',
    'x_reference' => 'ORD-20261109-3808E44C', 'x_result' => 'completed',
];
if ($secret === '') {
    $check(false, 'No hay llave secreta: no se puede simular el callback (revisa la sección A)');
} else {
    $signed = $orderFields + ['x_signature' => TuuClient::signFields($orderFields, $secret)];
    $raw = http_build_query($signed, '', '&', PHP_QUERY_RFC1738);
    $line('C1. Callback firmado con la llave configurada (origen: ' . $secretInfo['source'] . ')');
    $line('  cuerpo form-urlencoded: ' . $raw);
    $parsed = WebhookSignature::parse($raw);
    $check($parsed !== null, 'WebhookSignature::parse acepta el cuerpo');
    $check($parsed !== null && WebhookSignature::valid($parsed, $secret), 'WebhookSignature::valid (el validador de webhook_tuu.php) devuelve true');
    $validation = TuuCallback::validate($parsed ?? [], $account);
    $check($validation === null, 'TuuCallback::validate aprueba cuenta, referencia, monto, moneda y resultado', $validation === null ? '' : 'rechazo: ' . $validation);

    $line();
    $line('C2. Firma recibida en la prueba manual con curl (sin x_timestamp ni x_message)');
    $received = '7ad799ddf6c6d489b4f4f3ea3c6c04cfb92d6bbafec2976f9d78990c7aa2016d';
    $internal = TuuClient::canonicalString($orderFields);
    $computed = hash_hmac('sha256', $internal, $secret);
    $line('  cadena interna : ' . $internal);
    $line('  hash calculado : ' . $computed);
    $line('  hash recibido  : ' . $received);
    if (hash_equals($computed, $received)) {
        $check(true, 'La firma del curl coincide con la calculada internamente');
    } else {
        $variants = [
            'x_amount=1500.0' => ['x_amount' => '1500.0'] + $orderFields,
            'x_amount=1500.00' => ['x_amount' => '1500.00'] + $orderFields,
            'con x_message="Transaccion aprobada"' => $orderFields + ['x_message' => 'Transaccion aprobada'],
        ];
        $match = null;
        foreach ($variants as $label => $variant) {
            if (hash_equals($received, hash_hmac('sha256', TuuClient::canonicalString($variant), $secret))) {
                $match = [$label, TuuClient::canonicalString($variant)];
                break;
            }
        }
        if ($match !== null) {
            $warn('El validador rechaza esa firma: se calculó sobre otra cadena (' . $match[0] . ')', $diff($internal, $match[1]));
        } else {
            $warn(
                'El validador rechaza esa firma: no corresponde a esos 5 campos con la llave configurada',
                "Ninguna variante conocida la reproduce. Un callback real incluye x_timestamp y x_message, que no pueden inventarse.\nFirma desde el log real: revisa 'cadena_firmada' y 'firma_calculada' en womanlab-core/logs/webhook_tuu.log."
            );
        }
    }
}

$title('Resumen');
$line(sprintf('Fallas: %d | Avisos: %d', $failures, $warnings));
$line($failures === 0 ? $paint('Todas las aserciones pasaron.', '32') : $paint('Hay aserciones fallidas.', '31'));
ob_end_flush();
exit($failures === 0 ? 0 : 1);
