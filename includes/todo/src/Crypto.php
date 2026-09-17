<?php
declare(strict_types=1);

namespace Todo;

final class Crypto
{
    public function __construct(private readonly string $key)
    {
        if (strlen($key) !== 32) throw new \RuntimeException('TODO_ENCRYPTION_KEY must decode to 32 bytes.');
    }
    public function encrypt(array $value, string $context): string
    {
        $iv = random_bytes(12);
        $cipher = openssl_encrypt(Support::json($value), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, $context);
        if ($cipher === false) throw new \RuntimeException('Encryption failed.');
        return base64_encode($iv . $tag . $cipher);
    }
    public function decrypt(string $value, string $context): array
    {
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) < 28) throw new \RuntimeException('Invalid encrypted data.');
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $context);
        if ($plain === false) throw new \RuntimeException('Decryption failed.');
        return Support::decode($plain);
    }
}
