<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ob_start();
$failed = false;
foreach (['pdo_mysql', 'curl', 'mbstring', 'fileinfo', 'openssl'] as $extension) {
    $enabled = extension_loaded($extension);
    echo $extension . ': ' . ($enabled ? 'OK' : 'NO DISPONIBLE') . PHP_EOL;
    $failed = $failed || !$enabled;
}

$corePath = getenv('WOMANLAB_CORE_PATH') ?: dirname(__DIR__, 2) . '/womanlab-core';
try {
    if (!is_file($corePath . '/config/bootstrap.php')) {
        throw new RuntimeException('Núcleo no encontrado; verifica WOMANLAB_CORE_PATH.');
    }
    require_once $corePath . '/config/bootstrap.php';
    $db = WomanLab\Support\Database::connection();
    echo 'Base de datos: OK' . PHP_EOL;
    foreach (['productos', 'usuarios'] as $table) {
        $db->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
        echo 'Tabla ' . $table . ': OK' . PHP_EOL;
    }
} catch (Throwable $error) {
    $failed = true;
    $code = $error instanceof PDOException ? (string) ($error->errorInfo[1] ?? $error->getCode()) : '';
    $message = match ($code) {
        '1045' => 'Acceso denegado: revisa usuario, contraseña y permisos.',
        '1044' => 'Acceso denegado a la base: revisa los permisos del usuario.',
        '1049' => 'Base inexistente: revisa DB_NAME y el prefijo de cPanel.',
        '1146' => 'Falta una tabla: importa el esquema en la base seleccionada.',
        '2002', '2003' => 'No se pudo conectar: revisa host, puerto y disponibilidad.',
        default => 'Error de configuración, conexión o consulta; revisa el log privado de PHP.',
    };
    echo 'Base de datos: ' . $message . PHP_EOL;
}

if (extension_loaded('curl')) {
    $handle = curl_init('https://frontend-api.payment.haulmer.dev/');
    if ($handle !== false) {
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        $response = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($response !== false && $status > 0) {
            echo 'HTTPS TUU: OK (HTTP ' . $status . ')' . PHP_EOL;
        } else {
            echo 'HTTPS TUU: fallo de conexión o certificado TLS.' . PHP_EOL;
            $failed = true;
        }
    } else {
        echo 'HTTPS TUU: no se pudo iniciar cURL.' . PHP_EOL;
        $failed = true;
    }
}

ob_end_flush();
exit($failed ? 1 : 0);