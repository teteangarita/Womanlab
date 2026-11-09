<?php
declare(strict_types=1);

namespace WomanLab\Controllers;

use PDO;
use PDOException;
use WomanLab\Support\ApiResponse;

final class CustomerController
{
    public static function dispatch(PDO $db, string $method): never
    {
        if ($method === 'GET') {
            self::index($db);
        }

        $payload = ApiResponse::input();
        $id = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
        if ($method === 'DELETE') {
            if (!$id) ApiResponse::fail('invalid_customer_id', 422);
            $query = $db->prepare("UPDATE usuarios SET activo = 0 WHERE id = ? AND rol = 'cliente'");
            $query->execute([$id]);
            ApiResponse::json(['saved' => true]);
        }

        $name = trim(strip_tags((string) ($payload['nombre'] ?? '')));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $password = (string) ($payload['password'] ?? '');
        $active = filter_var($payload['activo'] ?? 1, FILTER_VALIDATE_INT);
        if ($name === '' || mb_strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || strlen($email) > 190 || !in_array($active, [0, 1], true)
            || ($password !== '' && (strlen($password) < 12 || strlen($password) > 200))) {
            ApiResponse::fail('invalid_customer', 422);
        }

        try {
            if ($method === 'POST') {
                if ($password === '') ApiResponse::fail('password_required', 422);
                $query = $db->prepare(
                    "INSERT INTO usuarios (nombre, email, password_hash, rol, email_verificado_en, activo)
                     VALUES (?, ?, ?, 'cliente', NOW(), 1)"
                );
                $query->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
                ApiResponse::json(['saved' => true, 'id' => (int) $db->lastInsertId()], 201);
            }
            if ($method === 'PUT') {
                if (!$id) ApiResponse::fail('invalid_customer_id', 422);
                if ($password !== '') {
                    $query = $db->prepare("UPDATE usuarios SET nombre = ?, email = ?, password_hash = ?, activo = ? WHERE id = ? AND rol = 'cliente'");
                    $query->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $active, $id]);
                } else {
                    $query = $db->prepare("UPDATE usuarios SET nombre = ?, email = ?, activo = ? WHERE id = ? AND rol = 'cliente'");
                    $query->execute([$name, $email, $active, $id]);
                }
                ApiResponse::json(['saved' => true]);
            }
            ApiResponse::fail('method_not_allowed', 405);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') ApiResponse::fail('email_already_exists', 409);
            throw $error;
        }
    }

    private static function index(PDO $db): never
    {
        $queryText = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($queryText) > 120) ApiResponse::fail('invalid_search', 422);
        $sql = "SELECT id, nombre, email, email_verificado_en, activo, creado_en
                FROM usuarios WHERE rol = 'cliente'";
        $params = [];
        if ($queryText !== '') {
            $sql .= ' AND (nombre LIKE ? OR email LIKE ?)';
            $term = '%' . addcslashes($queryText, '%_\\') . '%';
            $params = [$term, $term];
        }
        $sql .= ' ORDER BY creado_en DESC LIMIT 500';
        $query = $db->prepare($sql);
        $query->execute($params);
        ApiResponse::json(['customers' => $query->fetchAll()]);
    }
}