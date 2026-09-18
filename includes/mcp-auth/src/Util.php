<?php
declare(strict_types=1);

namespace MopolitiAuth;

use JsonException;

final class Util
{
    public static function jsonDecode(string $json): array
    {
        try {
            $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new AppError('invalid_json', 'The request body is not valid JSON.', 400, false, [], null);
        }
        if (!is_array($value)) {
            throw new AppError('invalid_json', 'The JSON root must be an object.', 400);
        }
        return $value;
    }

    public static function jsonEncode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function randomToken(int $bytes = 32): string
    {
        return self::base64UrlEncode(random_bytes($bytes));
    }

    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }

    public static function normalizeName(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        return trim((string) preg_replace('/[\s._-]+/u', '-', $value), '-');
    }

    public static function canonicalJson(mixed $value): string
    {
        return self::jsonEncode(self::sortRecursive($value));
    }

    private static function sortRecursive(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::sortRecursive($item);
        }
        return $value;
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if ($name === 'Content-Type') {
            $key = 'CONTENT_TYPE';
        } elseif ($name === 'Content-Length') {
            $key = 'CONTENT_LENGTH';
        }
        $value = $_SERVER[$key] ?? null;
        if ($name === 'Authorization' && (!is_string($value) || $value === '')) {
            $value = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        }
        return is_string($value) && $value !== '' ? trim($value) : null;
    }

    public static function bearerToken(): ?string
    {
        $header = self::header('Authorization');
        if ($header !== null && preg_match('/^Bearer\s+([^\s]+)$/i', $header, $matches) === 1) {
            return $matches[1];
        }
        return null;
    }

    public static function envBool(string $name, bool $default = false): bool
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return $default;
        }
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public static function requireString(array $input, string $key, int $max = 4096): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || trim($value) === '' || strlen($value) > $max) {
            throw new AppError('validation_error', "Field '{$key}' must be a non-empty string.", 400, false, ['field' => $key]);
        }
        return trim($value);
    }

    public static function assertKeys(array $input, array $allowed, string $context = 'parameters'): void
    {
        $unknown = array_values(array_diff(array_keys($input), $allowed));
        if ($unknown !== []) {
            $label = count($unknown) === 1 ? 'Unknown parameter ' : 'Unknown parameters ';
            $names = implode(', ', array_map([self::class, 'jsonEncode'], $unknown));
            throw new AppError('unknown_parameter', $label . $names . '.', 400, false, ['context' => $context, 'parameters' => $unknown, 'allowed' => $allowed]);
        }
    }
}
