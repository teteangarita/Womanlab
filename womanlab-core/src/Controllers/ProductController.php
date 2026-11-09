<?php
declare(strict_types=1);

namespace WomanLab\Controllers;

use PDO;
use WomanLab\Support\ApiResponse;

final class ProductController
{
    public static function index(PDO $db): never
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            ApiResponse::fail('method_not_allowed', 405);
        }
        $products = $db->query(
            'SELECT sku AS id, sku, nombre, descripcion, precio, imagen_url
             FROM productos WHERE activo = 1 ORDER BY id'
        )->fetchAll();
        ApiResponse::json(['products' => $products]);
    }
}