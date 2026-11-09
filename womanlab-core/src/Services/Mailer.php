<?php
declare(strict_types=1);

namespace WomanLab\Services;

use RuntimeException;

final class Mailer
{
    public function sendVerification(string $email, string $name, string $token): void
    {
        $baseUrl = rtrim($_ENV['APP_URL'] ?? '', '/');
        $from = $_ENV['MAIL_FROM'] ?? '';
        if ($baseUrl === '' || $from === '') {
            throw new RuntimeException('El envío de correo no está configurado.');
        }

        $url = $baseUrl . '/api/auth.php?action=verify&token=' . rawurlencode($token);
        $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $subject = 'Verifica tu correo | Woman Lab';
        $text = "Hola {$name}, confirma tu correo para activar tu cuenta: {$url}";
        $html = '<!doctype html><html lang="es"><meta charset="utf-8"><p>Hola ' . $safeName
            . ', confirma tu correo para activar tu cuenta.</p><p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
            . '">Verificar correo</a></p></html>';
        $boundary = 'womanlab_' . bin2hex(random_bytes(16));
        $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$text}\r\n"
            . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$html}\r\n"
            . "--{$boundary}--";
        $headers = "From: {$from}\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"{$boundary}\"";

        if (!mail($email, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers)) {
            throw new RuntimeException('No fue posible enviar el correo de verificación.');
        }
    }
}