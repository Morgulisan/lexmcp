<?php
declare(strict_types=1);

namespace LexMcp;

final class Crypto
{
    public static function encryptApiKey(string $apiKey, string $accountId): array
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $key = Config::encryptionKey();
        try {
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($apiKey, $accountId, $nonce, $key);
            return ['ciphertext' => $ciphertext, 'nonce' => $nonce];
        } finally {
            sodium_memzero($key);
        }
    }

    public static function decryptApiKey(string $ciphertext, string $nonce, string $accountId): string
    {
        $key = Config::encryptionKey();
        try {
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, $accountId, $nonce, $key);
        } finally {
            sodium_memzero($key);
        }
        if ($plain === false) {
            throw new AppError('account_key_unreadable', 'The account credential cannot be decrypted.', 500);
        }
        return $plain;
    }

    public static function fingerprint(string $apiKey): string
    {
        $key = Config::fingerprintKey();
        try {
            return hash_hmac('sha256', $apiKey, $key);
        } finally {
            sodium_memzero($key);
        }
    }
}
