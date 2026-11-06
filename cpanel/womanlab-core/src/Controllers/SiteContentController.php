<?php
declare(strict_types=1);

namespace WomanLab\Controllers;

use PDO;
use PDOException;
use Throwable;
use WomanLab\Support\ApiResponse;

final class SiteContentController
{
    private const SECTIONS = ['membership', 'staff', 'service', 'contact'];

    public static function publicIndex(PDO $db): never
    {
        $query = $db->query(
            'SELECT c.section, c.slug, c.title, c.description,
                CASE WHEN c.product_sku IS NOT NULL THEN p.precio ELSE c.price END AS price,
                c.product_sku, c.image_url, c.link_url, c.details_json, c.sort_order
             FROM site_content c
             LEFT JOIN productos p ON p.sku = c.product_sku
             WHERE c.activo = 1 AND (c.product_sku IS NULL OR p.activo = 1)
             ORDER BY c.section, c.sort_order, c.id'
        );
        $content = [];
        foreach ($query->fetchAll() as $row) {
            $row['price'] = $row['price'] === null ? null : (int) $row['price'];
            $row['details'] = json_decode((string) $row['details_json'], true) ?: [];
            unset($row['details_json']);
            $content[$row['section']][] = $row;
        }
        ApiResponse::json(['content' => $content]);
    }

    public static function admin(PDO $db, string $method): never
    {
        $section = strtolower(trim((string) ($_GET['section'] ?? '')));
        if (!in_array($section, self::SECTIONS, true)) ApiResponse::fail('invalid_content_section', 422);
        if ($method === 'GET') {
            $query = $db->prepare(
                'SELECT c.id, c.section, c.slug, c.title, c.description,
                        CASE WHEN c.product_sku IS NOT NULL AND p.id IS NOT NULL THEN p.precio ELSE c.price END AS price,
                        c.product_sku, c.image_url, c.link_url, c.details_json, c.sort_order, c.activo
                 FROM site_content c LEFT JOIN productos p ON p.sku = c.product_sku
                 WHERE c.section = ? ORDER BY c.sort_order, c.id'
            );
            $query->execute([$section]);
            $rows = $query->fetchAll();
            foreach ($rows as &$row) {
                $row['price'] = $row['price'] === null ? null : (int) $row['price'];
                $row['details'] = json_decode((string) $row['details_json'], true) ?: [];
                unset($row['details_json']);
            }
            ApiResponse::json(['entries' => $rows]);
        }

        $payload = ApiResponse::input();
        $id = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
        if ($method === 'DELETE') {
            if (!$id) ApiResponse::fail('invalid_content_id', 422);
            $db->beginTransaction();
            try {
                $query = $db->prepare('SELECT product_sku FROM site_content WHERE id = ? AND section = ? FOR UPDATE');
                $query->execute([$id, $section]);
                $entry = $query->fetch();
                if (!$entry) ApiResponse::fail('content_not_found', 404);
                $db->prepare('UPDATE site_content SET activo = 0 WHERE id = ?')->execute([$id]);
                if ($entry['product_sku']) {
                    $db->prepare('UPDATE productos SET activo = 0 WHERE sku = ?')->execute([$entry['product_sku']]);
                }
                $db->commit();
            } catch (Throwable $error) {
                if ($db->inTransaction()) $db->rollBack();
                throw $error;
            }
            ApiResponse::json(['saved' => true]);
        }

        $slug = strtolower(trim((string) ($payload['slug'] ?? '')));
        $title = trim(strip_tags((string) ($payload['title'] ?? '')));
        $description = trim(strip_tags((string) ($payload['description'] ?? '')));
        $priceInput = $payload['price'] ?? '';
        $price = $priceInput === '' || $priceInput === null ? null : filter_var($priceInput, FILTER_VALIDATE_INT);
        $productSku = trim((string) ($payload['product_sku'] ?? '')) ?: null;
        $image = trim((string) ($payload['image_url'] ?? '')) ?: null;
        $link = trim((string) ($payload['link_url'] ?? '')) ?: null;
        $order = filter_var($payload['sort_order'] ?? 0, FILTER_VALIDATE_INT);
        $detailsInput = $payload['details'] ?? [];
        if (is_string($detailsInput)) {
            try {
                $details = json_decode($detailsInput, true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                ApiResponse::fail('invalid_content_details', 422);
            }
        } else {
            $details = $detailsInput;
        }

        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/', $slug) || $title === '' || mb_strlen($title) > 160
            || mb_strlen($description) > 5000 || ($price !== null && ($price === false || $price < 0))
            || $order === false || !is_array($details) || strlen(json_encode($details) ?: '') > 8000
            || ($image !== null && (str_contains($image, '..') || !preg_match('#^/?(?:assets|uploads)/[a-zA-Z0-9_./-]+$#', $image)))
            || ($link !== null && (!filter_var($link, FILTER_VALIDATE_URL) || !in_array(parse_url($link, PHP_URL_SCHEME), ['http', 'https'], true)))) {
            ApiResponse::fail('invalid_content_entry', 422);
        }
        if ($productSku !== null && ($price === null || !in_array($section, ['membership', 'service'], true))) {
            ApiResponse::fail('invalid_linked_product', 422);
        }

        $db->beginTransaction();
        try {
            if ($productSku !== null) {
                $productQuery = $db->prepare('SELECT id FROM productos WHERE sku = ? FOR UPDATE');
                $productQuery->execute([$productSku]);
                if (!$productQuery->fetchColumn()) ApiResponse::fail('linked_product_not_found', 422);
                $updateProduct = $db->prepare('UPDATE productos SET nombre = ?, descripcion = ?, precio = ?, activo = 1 WHERE sku = ?');
                $updateProduct->execute([$title, $description, $price, $productSku]);
            }

            if ($method === 'POST') {
                $query = $db->prepare(
                    'INSERT INTO site_content (section, slug, title, description, price, product_sku, image_url, link_url, details_json, sort_order, activo)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
                );
                $query->execute([$section, $slug, $title, $description, $price, $productSku, $image, $link, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $order]);
            } elseif ($method === 'PUT') {
                if (!$id) ApiResponse::fail('invalid_content_id', 422);
                $query = $db->prepare(
                    'UPDATE site_content SET slug = ?, title = ?, description = ?, price = ?, product_sku = ?, image_url = ?, link_url = ?, details_json = ?, sort_order = ?, activo = 1
                     WHERE id = ? AND section = ?'
                );
                $query->execute([$slug, $title, $description, $price, $productSku, $image, $link, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $order, $id, $section]);
                if ($query->rowCount() === 0) {
                    $exists = $db->prepare('SELECT id FROM site_content WHERE id = ? AND section = ?');
                    $exists->execute([$id, $section]);
                    if (!$exists->fetchColumn()) ApiResponse::fail('content_not_found', 404);
                }
            } else {
                ApiResponse::fail('method_not_allowed', 405);
            }
            $db->commit();
        } catch (PDOException $error) {
            if ($db->inTransaction()) $db->rollBack();
            if ($error->getCode() === '23000') ApiResponse::fail('content_slug_or_product_exists', 409);
            throw $error;
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        ApiResponse::json(['saved' => true], $method === 'POST' ? 201 : 200);
    }
}