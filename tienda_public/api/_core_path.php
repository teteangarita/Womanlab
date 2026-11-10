<?php
declare(strict_types=1);

$configuredCore = getenv('WOMANLAB_CORE_PATH');
if (is_string($configuredCore) && $configuredCore !== '') {
    return rtrim($configuredCore, '/');
}

// El núcleo vive fuera del DocumentRoot: se busca en los tres directorios superiores.
$candidates = [];
$directory = dirname(__DIR__);
for ($level = 0; $level < 3; $level++) {
    $directory = dirname($directory);
    $candidates[] = $directory . '/womanlab-core';
}
foreach ($candidates as $candidate) {
    if (is_file($candidate . '/config/bootstrap.php')) {
        return $candidate;
    }
}

return $candidates[0];