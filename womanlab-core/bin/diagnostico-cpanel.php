<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$corePath = dirname(__DIR__);
$projectPath = dirname($corePath);
$envPath = $corePath . '/.env';
$autoloadPath = $projectPath . '/vendor/autoload.php';
$errors = 0;
$warnings = 0;

$report = static function (string $status, string $message) use (&$errors, &$warnings): void {
    echo sprintf('[%s] %s%s', $status, $message, PHP_EOL);
    if ($status === 'ERROR') {
        $errors++;
    } elseif ($status === 'AVISO') {
        $warnings++;
    }
};

$mask = static function (string $value): string {
    if ($value === '') {
        return '(vacío/no definido)';
    }
    return strlen($value) <= 2 ? str_repeat('*', strlen($value)) : substr($value, 0, 2) . str_repeat('*', strlen($value) - 2);
};

$configured = static function (string $key, string $default = ''): string {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return is_string($value) && $value !== '' ? $value : $default;
};

echo "Diagnóstico CLI de Woman Lab para cPanel\n";
echo 'Raíz del paquete: ' . $projectPath . PHP_EOL;
echo 'Núcleo: ' . $corePath . PHP_EOL;
echo 'DocumentRoot esperado del paquete: ' . $projectPath . '/public_html' . PHP_EOL;

if (PHP_VERSION_ID >= 80200) {
    $report('OK', 'PHP ' . PHP_VERSION . ' (mínimo requerido: 8.2)');
} else {
    $report('ERROR', 'PHP ' . PHP_VERSION . '; Composer requiere PHP 8.2 o superior.');
}

foreach (['PDO', 'pdo_mysql', 'curl', 'mbstring', 'fileinfo', 'openssl', 'json'] as $extension) {
    $loaded = extension_loaded($extension);
    $report($loaded ? 'OK' : 'ERROR', 'Extensión ' . $extension . ($loaded ? ' disponible' : ' NO disponible'));
}

foreach ([$corePath . '/config/bootstrap.php', $autoloadPath, $envPath] as $path) {
    if (is_file($path) && is_readable($path)) {
        $report('OK', 'Archivo legible: ' . $path);
    } else {
        $report('ERROR', 'Falta o no se puede leer: ' . $path);
    }
}

if (is_file($envPath)) {
    $permissions = fileperms($envPath);
    if (is_int($permissions)) {
        $mode = $permissions & 0777;
        if (($mode & 0077) === 0) {
            $report('OK', sprintf('.env tiene permisos privados (%04o)', $mode));
        } else {
            $report('AVISO', sprintf('.env permite lectura/escritura a grupo u otros (%04o); restringe sus permisos.', $mode));
        }
    }
}

if (is_file($autoloadPath) && is_file($corePath . '/config/bootstrap.php')) {
    $processDbPass = getenv('DB_PASS');
    $processDbPassword = getenv('DB_PASSWORD');

    try {
        require_once $corePath . '/config/bootstrap.php';

        $dbHost = $configured('DB_HOST', 'localhost');
        $dbPort = $configured('DB_PORT', '3306');
        $dbName = $configured('DB_NAME');
        $dbUser = $configured('DB_USER');

        echo 'Configuración efectiva DB_HOST: ' . $dbHost . PHP_EOL;
        echo 'Configuración efectiva DB_PORT: ' . $dbPort . PHP_EOL;
        echo 'Configuración efectiva DB_NAME: ' . ($dbName !== '' ? $dbName : '(vacío/no definido)') . PHP_EOL;
        echo 'Configuración efectiva DB_USER: ' . $mask($dbUser) . PHP_EOL;

        if (is_string($processDbPass)) {
            $passwordSource = 'variable de entorno DB_PASS';
            $passwordSet = $processDbPass !== '';
        } elseif (is_string($processDbPassword)) {
            $passwordSource = 'variable de entorno DB_PASSWORD';
            $passwordSet = $processDbPassword !== '';
        } elseif (array_key_exists('DB_PASS', $_ENV)) {
            $passwordSource = '.env (DB_PASS)';
            $passwordSet = $_ENV['DB_PASS'] !== '';
        } elseif (isset($_ENV['DB_PASSWORD'])) {
            $passwordSource = '.env (DB_PASSWORD)';
            $passwordSet = $_ENV['DB_PASSWORD'] !== '';
        } else {
            $passwordSource = 'ninguna';
            $passwordSet = false;
        }

        echo 'Origen de contraseña efectiva: ' . $passwordSource . PHP_EOL;
        $report($passwordSet ? 'OK' : 'ERROR', 'Contraseña de base de datos ' . ($passwordSet ? 'configurada (no se muestra)' : 'vacía o no encontrada'));

        if ($dbName === '' || $dbUser === '') {
            $report('ERROR', 'DB_NAME y DB_USER deben estar configurados.');
        } else {
            $db = WomanLab\Support\Database::connection();
            $actualDatabase = (string) $db->query('SELECT DATABASE()')->fetchColumn();
            $report('OK', 'Conexión PDO establecida con la base ' . $actualDatabase);

            foreach (['usuarios', 'productos', 'site_content', 'ordenes', 'orden_items'] as $table) {
                try {
                    $db->query('SELECT 1 FROM `' . $table . '` LIMIT 0');
                    $report('OK', 'Tabla accesible: ' . $table);
                } catch (PDOException $error) {
                    $driverCode = (string) ($error->errorInfo[1] ?? '');
                    $report('ERROR', 'Tabla ' . $table . ' no accesible (código MySQL ' . ($driverCode !== '' ? $driverCode : 'desconocido') . '). Importa schema.sql si la instalación es nueva.');
                }
            }
        }
    } catch (PDOException $error) {
        $driverCode = (string) ($error->errorInfo[1] ?? '');
        $hint = match ($driverCode) {
            '1045' => 'MySQL rechazó la autenticación. Revisa DB_USER, contraseña y el host desde el que ese usuario puede conectarse.',
            '1044' => 'El usuario fue autenticado, pero no tiene permisos sobre DB_NAME. Asígnalo a esa base en cPanel.',
            '1049' => 'DB_NAME no existe o no coincide con el nombre completo con prefijo de cPanel.',
            '2002', '2003' => 'No se pudo alcanzar MySQL. Confirma DB_HOST y DB_PORT con el hosting.',
            default => 'Falló la conexión PDO. Revisa el log privado de PHP para más información.',
        };
        $report('ERROR', 'Conexión a la base fallida (SQLSTATE ' . (string) ($error->errorInfo[0] ?? $error->getCode()) . ', código MySQL ' . ($driverCode !== '' ? $driverCode : 'desconocido') . '). ' . $hint);
    } catch (Throwable $error) {
        $report('ERROR', 'No se pudo cargar la configuración (' . get_class($error) . '). Verifica autoload, ruta del núcleo y log privado de PHP.');
    }
}

$logPath = $corePath . '/logs';
if (is_dir($logPath) && is_writable($logPath)) {
    $report('OK', 'Directorio de logs existe y PHP CLI puede escribir: ' . $logPath);
} else {
    $report('AVISO', 'Directorio de logs ausente o no escribible: ' . $logPath);
}

$uploadPath = $configured('PUBLIC_UPLOAD_DIR');
if ($uploadPath !== '' && is_dir($uploadPath) && is_writable($uploadPath)) {
    $report('OK', 'Directorio de imágenes existe y PHP CLI puede escribir.');
} elseif ($uploadPath !== '') {
    $report('AVISO', 'PUBLIC_UPLOAD_DIR no existe o no es escribible: ' . $uploadPath);
} else {
    $report('AVISO', 'PUBLIC_UPLOAD_DIR no está configurado.');
}

if (extension_loaded('curl')) {
    $handle = curl_init('https://frontend-api.payment.haulmer.dev/');
    if ($handle !== false) {
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlError = curl_errno($handle);
        curl_close($handle);
        if ($response !== false && $status > 0) {
            $report('OK', 'Conexión HTTPS con TUU establecida (HTTP ' . $status . '; un 404 en esta ruta base puede ser normal).');
        } else {
            $report('AVISO', 'No se pudo validar HTTPS con TUU (cURL ' . $curlError . ').');
        }
    } else {
        $report('AVISO', 'No se pudo iniciar cURL para probar HTTPS con TUU.');
    }
}

echo sprintf('Resultado: %d error(es), %d aviso(s).%s', $errors, $warnings, PHP_EOL);
exit($errors > 0 ? 1 : 0);