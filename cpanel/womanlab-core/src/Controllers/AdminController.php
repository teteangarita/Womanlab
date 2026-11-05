<?php
declare(strict_types=1);

namespace WomanLab\Controllers;

use PDO;
use Throwable;
use WomanLab\Support\ApiResponse;
use WomanLab\Support\Auth;

final class AdminController
{
    public static function dispatch(PDO $db): never
    {
        Auth::requireRole('admin');
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method !== 'GET') {
            Auth::requireCsrf();
        }

        $resource = (string) ($_GET['resource'] ?? 'orders');
        if ($resource === 'products') {
            self::products($db, $method);
        }
        if ($resource === 'orders' && $method === 'GET') {
            self::orders($db);
        }
        if ($resource === 'upload' && $method === 'POST') {
            self::upload();
        }
        ApiResponse::fail('not_found', 404);
    }

    private static function products(PDO $db, string $method): never
    {
        if ($method === 'GET') {
            ApiResponse::json(['products' => $db->query('SELECT * FROM productos ORDER BY id DESC')->fetchAll()]);
        }
        $payload = ApiResponse::input();
        if ($method === 'DELETE') {
            $id = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                ApiResponse::fail('invalid_product_id', 422);
            }
            $db->prepare('UPDATE productos SET activo = 0 WHERE id = ?')->execute([$id]);
            ApiResponse::json(['saved' => true]);
        }
        $sku = trim((string) ($payload['sku'] ?? ''));
        $name = trim(strip_tags((string) ($payload['nombre'] ?? '')));
        $description = trim(strip_tags((string) ($payload['descripcion'] ?? '')));
        $price = filter_var($payload['precio'] ?? null, FILTER_VALIDATE_INT);
        $stock = filter_var($payload['stock'] ?? null, FILTER_VALIDATE_INT);
        $image = trim((string) ($payload['imagen_url'] ?? ''));
        if ($sku === '' || strlen($sku) > 80 || $name === '' || mb_strlen($name) > 160
            || $price === false || $price < 1 || $stock === false || $stock < 0
            || ($image !== '' && (str_contains($image, '..') || !preg_match('#^/?(?:assets|uploads)/[a-zA-Z0-9_./-]+$#', $image)))) {
            ApiResponse::fail('invalid_product', 422);
        }

        if ($method === 'POST') {
            $query = $db->prepare('INSERT INTO productos (sku, nombre, descripcion, precio, stock, imagen_url) VALUES (?, ?, ?, ?, ?, ?)');
            $query->execute([$sku, $name, $description, $price, $stock, $image ?: null]);
        } elseif ($method === 'PUT') {
            $id = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                ApiResponse::fail('invalid_product_id', 422);
            }
            $reservation = $db->prepare(
                "SELECT COALESCE(SUM(oi.cantidad), 0)
                 FROM orden_items oi JOIN ordenes o ON o.id = oi.orden_id
                 WHERE oi.producto_id = (SELECT id FROM productos WHERE id = ?)
                   AND o.estado = 'PENDIENTE' AND o.expira_en > NOW()"
            );
            $reservation->execute([$id]);
            if ($stock < (int) $reservation->fetchColumn()) {
                ApiResponse::fail('stock_below_active_reservations', 409);
            }
            $query = $db->prepare('UPDATE productos SET sku = ?, nombre = ?, descripcion = ?, precio = ?, stock = ?, imagen_url = ? WHERE id = ?');
            $query->execute([$sku, $name, $description, $price, $stock, $image ?: null, $id]);
        } else {
            ApiResponse::fail('method_not_allowed', 405);
        }
        ApiResponse::json(['saved' => true], $method === 'POST' ? 201 : 200);
    }

    private static function orders(PDO $db): never
    {
        $status = strtoupper((string) ($_GET['estado'] ?? ''));
        $from = (string) ($_GET['desde'] ?? '');
        $to = (string) ($_GET['hasta'] ?? '');
        $allowedStatuses = ['', 'PENDIENTE', 'PAGADA', 'RECHAZADA', 'ANULADA'];
        if (!in_array($status, $allowedStatuses, true)
            || ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from))
            || ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))) {
            ApiResponse::fail('invalid_filter', 422);
        }

        $sql = 'SELECT o.*, JSON_ARRAYAGG(JSON_OBJECT("sku", p.sku, "nombre", p.nombre, "cantidad", oi.cantidad, "precio_unitario", oi.precio_unitario)) AS items
                FROM ordenes o JOIN orden_items oi ON oi.orden_id = o.id JOIN productos p ON p.id = oi.producto_id WHERE 1 = 1';
        $params = [];
        if ($status !== '') {
            $sql .= ' AND o.estado = ?';
            $params[] = $status;
        }
        if ($from !== '') {
            $sql .= ' AND o.creado_en >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $sql .= ' AND o.creado_en <= ?';
            $params[] = $to . ' 23:59:59';
        }
        $sql .= ' GROUP BY o.id ORDER BY o.creado_en DESC LIMIT 300';
        $query = $db->prepare($sql);
        $query->execute($params);
        $orders = $query->fetchAll();
        foreach ($orders as &$order) {
            $order['items'] = json_decode((string) $order['items'], true) ?: [];
        }
        ApiResponse::json(['orders' => $orders]);
    }

    private static function upload(): never
    {
        $file = $_FILES['imagen'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $file['size'] > 5_000_000) {
            ApiResponse::fail('invalid_upload', 422);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extensions[$mime])) {
            ApiResponse::fail('unsupported_image', 415);
        }

        $directory = $_ENV['PUBLIC_UPLOAD_DIR'] ?? dirname(__DIR__, 3) . '/public_html/uploads/images';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            ApiResponse::fail('upload_unavailable', 500);
        }
        $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
        if (!move_uploaded_file($file['tmp_name'], rtrim($directory, '/') . '/' . $filename)) {
            ApiResponse::fail('upload_failed', 500);
        }
        ApiResponse::json(['imagen_url' => 'uploads/images/' . $filename], 201);
    }
}