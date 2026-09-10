<?php
declare(strict_types=1);

namespace LexMcp;

final class Config
{
    /** Add a version here only after its official wire format was reviewed. */
    private const VERIFIED_PROTOCOL_PROFILES = [
        '2026-07-28' => 'modern',
        '2025-11-25' => 'legacy',
        '2025-06-18' => 'legacy',
        '2025-03-26' => 'legacy',
        '2024-11-05' => 'legacy',
    ];

    public static function databaseInclude(): string
    {
        return self::env('LEXMCP_DATABASE_INCLUDE', dirname(__DIR__, 2) . '/api/sql.php');
    }

    public static function publicUrl(): string
    {
        return rtrim(self::env('LEXMCP_PUBLIC_URL', 'https://lxwmcp.mopoliti.de'), '/');
    }

    public static function resourceUrl(): string
    {
        return self::publicUrl() . '/mcp';
    }

    public static function lexwareBaseUrl(): string
    {
        return rtrim(self::env('LEXMCP_LEXWARE_BASE_URL', 'https://api.lexware.io'), '/');
    }

    public static function dataPath(): string
    {
        return rtrim(self::env('LEXMCP_DATA_PATH', dirname(__DIR__, 3) . '/data/lxwmcp'), '/\\');
    }

    public static function contentPath(): string
    {
        return dirname(__DIR__) . '/content';
    }

    public static function aliasFile(): string
    {
        return dirname(__DIR__) . '/config/aliases.json';
    }

    public static function endpointFile(): string
    {
        return dirname(__DIR__) . '/config/endpoints.php';
    }

    public static function encryptionKey(): string
    {
        return self::key('LEXMCP_ENCRYPTION_KEY');
    }

    public static function fingerprintKey(): string
    {
        return self::key('LEXMCP_FINGERPRINT_KEY');
    }

    public static function supportedVersions(): array
    {
        return array_keys(self::protocolProfiles());
    }

    public static function wireProfile(string $version): ?string
    {
        return self::VERIFIED_PROTOCOL_PROFILES[$version] ?? null;
    }

    public static function protocolProfiles(): array
    {
        $raw = self::env('LEXMCP_PROTOCOL_VERSIONS', '2026-07-28,2025-11-25,2025-06-18,2025-03-26,2024-11-05');
        $profiles = [];
        foreach (array_values(array_filter(array_map('trim', explode(',', $raw)))) as $version) {
            if (preg_match('/^20\d{2}-\d{2}-\d{2}$/', $version) !== 1) {
                throw new \RuntimeException('MCP protocol versions must use YYYY-MM-DD.');
            }
            if (!isset(self::VERIFIED_PROTOCOL_PROFILES[$version])) {
                throw new \RuntimeException("MCP protocol version {$version} has no verified wire profile.");
            }
            $profiles[$version] = self::VERIFIED_PROTOCOL_PROFILES[$version];
        }
        if ($profiles === []) {
            throw new \RuntimeException('At least one verified MCP protocol version must be enabled.');
        }
        return $profiles;
    }

    public static function allowedOrigins(): array
    {
        $raw = self::env('LEXMCP_ALLOWED_ORIGINS', self::publicUrl());
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public static function remoteFileHosts(): array
    {
        $configured = getenv('LEXMCP_REMOTE_FILE_HOSTS');
        $raw = $configured === false ? 'drive.google.com,*.mopoliti.de,*.sldo.de,*.tecis.de,*crm.vertrieb-plattform.de' : $configured;
        return array_values(array_filter(array_map(static fn(string $v): string => strtolower(trim($v)), explode(',', $raw))));
    }

    public static function remoteFileHostAllowed(string $host): bool
    {
        $host = strtolower($host);
        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) return false;
        foreach (self::remoteFileHosts() as $pattern) {
            if ($host === $pattern) return true;
            // Only a leading wildcard is supported; the rest is a literal suffix.
            if (str_starts_with($pattern, '*') && strlen($pattern) > 1) {
                $suffix = substr($pattern, 1);
                if (str_ends_with($host, $suffix)) return true;
            }
        }
        return false;
    }

    public static function fileTransferInclude(): string
    {
        return self::env('LEXMCP_FILE_TRANSFER_INCLUDE', dirname(__DIR__, 2) . '/libs/FileTransfer/Storage.php');
    }

    public static function deleteEnabled(): bool
    {
        return Util::envBool('LEXMCP_ENABLE_DELETE');
    }

    public static function production(): bool
    {
        return self::env('LEXMCP_ENV', 'production') === 'production';
    }

    public static function scopes(): array
    {
        return ['accounts:read', 'lexware:read', 'lexware:write', 'lexware:finalize', 'lexware:delete', 'content:read'];
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);
        return $value === false || $value === '' ? $default : $value;
    }

    private static function key(string $name): string
    {
        $encoded = getenv($name);
        if ($encoded === false || $encoded === '') {
            if (!self::production()) {
                return hash('sha256', 'development-only-' . $name, true);
            }
            return self::persistentKey($name);
        }
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new AppError('key_configuration_error', "{$name} must contain a base64 encoded 32-byte key.", 500);
        }
        return $decoded;
    }

    private static function persistentKey(string $name): string
    {
        $files = [
            'LEXMCP_ENCRYPTION_KEY' => '.encryption.key',
            'LEXMCP_FINGERPRINT_KEY' => '.fingerprint.key',
        ];
        if (!isset($files[$name])) {
            throw new AppError('key_configuration_error', "Unknown key {$name}.", 500);
        }

        $directory = self::dataPath();
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new AppError('key_storage_error', 'Cannot create the private LexMCP data directory.', 500);
        }
        $path = $directory . DIRECTORY_SEPARATOR . $files[$name];
        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new AppError('key_storage_error', "Cannot open persistent key storage for {$name}.", 500);
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new AppError('key_storage_error', "Cannot lock persistent key storage for {$name}.", 500);
            }
            rewind($handle);
            $encoded = trim((string) stream_get_contents($handle));
            if ($encoded === '') {
                $encoded = base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES));
                $contents = $encoded . PHP_EOL;
                if (!ftruncate($handle, 0) || !rewind($handle) || fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
                    throw new AppError('key_storage_error', "Cannot persist generated key {$name}.", 500);
                }
                @chmod($path, 0600);
            }
            $decoded = base64_decode($encoded, true);
            if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                throw new AppError('key_storage_error', "Persistent key {$name} is invalid.", 500);
            }
            return $decoded;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
