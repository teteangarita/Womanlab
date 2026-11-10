<?php
declare(strict_types=1);

namespace WomanLab\Support;

final class TuuConfig
{
    /** Nombres aceptados para la llave secreta, por orden de prioridad. */
    public const SECRET_NAMES = ['TUU_SECRET_KEY', 'TUU_API_KEY', 'TUU_WEBHOOK_SECRET'];

    /** @return array<string, string> */
    public static function readEnvFile(string $path): array
    {
        $lines = is_file($path) && is_readable($path) ? file($path, FILE_IGNORE_NEW_LINES) : false;
        if ($lines === false) {
            return [];
        }
        $values = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }
            $position = strpos($line, '=');
            if ($position === false) {
                continue;
            }
            $key = trim(substr($line, 0, $position));
            $value = trim(substr($line, $position + 1));
            if (preg_match('/^(["\'])(.*)\1/', $value, $quoted) === 1) {
                $value = $quoted[2];
            } else {
                $value = trim(preg_replace('/\s+#.*$/', '', $value) ?? $value);
            }
            if ($key !== '') {
                $values[$key] = $value;
            }
        }
        return $values;
    }

    /** @return array{value: ?string, source: string} */
    public static function get(string $name, ?string $envFile = null): array
    {
        $candidates = [
            'getenv' => getenv($name),
            '$_ENV' => $_ENV[$name] ?? null,
            '$_SERVER' => $_SERVER[$name] ?? null,
        ];
        foreach ($candidates as $source => $value) {
            $value = is_string($value) ? trim($value) : '';
            if ($value !== '') {
                return ['value' => $value, 'source' => $source . '[' . $name . ']'];
            }
        }
        $fileValue = trim(self::readEnvFile($envFile ?? dirname(__DIR__, 2) . '/.env')[$name] ?? '');
        return $fileValue !== ''
            ? ['value' => $fileValue, 'source' => '.env[' . $name . ']']
            : ['value' => null, 'source' => 'none'];
    }

    /** @return array{value: ?string, source: string} */
    public static function resolveSecret(?string $envFile = null): array
    {
        foreach (self::SECRET_NAMES as $name) {
            $found = self::get($name, $envFile);
            if ($found['value'] !== null) {
                return $found;
            }
        }
        return ['value' => null, 'source' => 'none'];
    }

    public static function secretKey(?string $envFile = null): string
    {
        return self::resolveSecret($envFile)['value'] ?? '';
    }
}
