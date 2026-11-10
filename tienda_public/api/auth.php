<?php
declare(strict_types=1);

$db = require __DIR__ . '/_bootstrap.php';
WomanLab\Controllers\AuthController::dispatch($db);