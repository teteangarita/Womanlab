<?php
declare(strict_types=1);

$configuredCore = getenv('WOMANLAB_CORE_PATH');
if (is_string($configuredCore) && $configuredCore !== '') {
    return rtrim($configuredCore, '/');
}

$publicRoot = dirname(__DIR__);
$parent = dirname($publicRoot);
if (basename($parent) === 'public_html') {
    return dirname($parent) . '/womanlab-core';
}

return $parent . '/womanlab-core';