<?php
declare(strict_types=1);

namespace Todo;

final class Config
{
    public static function env(string $key, string $fallback = ''): string { $value = getenv($key); return $value === false || $value === '' ? $fallback : $value; }
    public static function url(): string
    {
        $url = rtrim(self::env('TODO_PUBLIC_URL', 'https://todo.mopoliti.de'), '/');
        Support::url($url);
        return $url;
    }
    public static function data(): string { return self::env('TODO_DATA_PATH', dirname(__DIR__, 3) . '/data/todo.mopoliti.de'); }
    public static function crypto(): Crypto
    {
        $configured = getenv('TODO_ENCRYPTION_KEY');
        if ($configured !== false && $configured !== '') {
            $key = base64_decode($configured, true);
            if ($key === false || strlen($key) !== 32) throw new \RuntimeException('TODO_ENCRYPTION_KEY must contain a base64 encoded 32-byte key.');
            return new Crypto($key);
        }
        return new Crypto(self::persistentKey());
    }
    private static function persistentKey(): string
    {
        $directory = rtrim(self::data(), '/\\');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('Cannot create the private Todo data directory.');
        $path = $directory . DIRECTORY_SEPARATOR . '.encryption.key';
        $handle = fopen($path, 'c+b');
        if ($handle === false) throw new \RuntimeException('Cannot open persistent Todo key storage.');
        try {
            if (!flock($handle, LOCK_EX)) throw new \RuntimeException('Cannot lock persistent Todo key storage.');
            rewind($handle);$encoded=trim((string)stream_get_contents($handle));
            if ($encoded === '') {
                $encoded=base64_encode(random_bytes(32));$contents=$encoded.PHP_EOL;
                if (!ftruncate($handle,0)||!rewind($handle)||fwrite($handle,$contents)!==strlen($contents)||!fflush($handle)) throw new \RuntimeException('Cannot persist the generated Todo key.');
                @chmod($path,0600);
            }
            $key=base64_decode($encoded,true);
            if ($key===false||strlen($key)!==32) throw new \RuntimeException('Persistent Todo key is invalid.');
            return $key;
        } finally {
            flock($handle,LOCK_UN);fclose($handle);
        }
    }
}
