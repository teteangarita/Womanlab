<?php
declare(strict_types=1);

namespace WomanLab\Services;

interface TuuTransport
{
    public function post(string $url, string $apiKey, array $payload): array;
}