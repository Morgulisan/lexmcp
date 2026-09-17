<?php
declare(strict_types=1);

namespace Todo;

final class Config
{
    public static function env(string $key, string $fallback = ''): string { $value = getenv($key); return $value === false ? $fallback : $value; }
    public static function url(): string
    {
        $url = rtrim(self::env('TODO_PUBLIC_URL', 'https://todo.mopoliti.de'), '/');
        Support::url($url);
        return $url;
    }
    public static function data(): string { return self::env('TODO_DATA_PATH', dirname(__DIR__, 3) . '/data/todo.mopoliti.de'); }
    public static function crypto(): Crypto
    {
        $key = base64_decode(self::env('TODO_ENCRYPTION_KEY'), true);
        if ($key === false || strlen($key) !== 32) throw new \RuntimeException('Configure TODO_ENCRYPTION_KEY outside the repository.');
        return new Crypto($key);
    }
    public static function owner(): int
    {
        $owner = self::env('TODO_OWNER_ID');
        if (!ctype_digit($owner) || (int)$owner < 1) throw new \RuntimeException('TODO_OWNER_ID is required.');
        return (int)$owner;
    }
}
