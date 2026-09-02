<?php
declare(strict_types=1);

namespace LexMcp;

use PDO;

final class AccountStore
{
    public function __construct(private readonly PDO $pdo) {}

    public function listForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, alias, organization_id, organization_name, active, created_at, updated_at FROM lxmcp_accounts WHERE user_id = ? ORDER BY alias_normalized');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getForUser(int $userId, string $alias): array
    {
        $normalized = Util::normalizeName($alias);
        $stmt = $this->pdo->prepare('SELECT * FROM lxmcp_accounts WHERE user_id = ? AND alias_normalized = ? AND active = 1');
        $stmt->execute([$userId, $normalized]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($account)) {
            throw new AppError('account_not_found', 'The requested Lexware account does not exist or is inactive.', 404);
        }
        return $account;
    }

    public function apiKey(array $account): string
    {
        return Crypto::decryptApiKey((string) $account['api_key_ciphertext'], (string) $account['api_key_nonce'], (string) $account['id']);
    }

    public function save(int $userId, string $alias, string $apiKey, ?string $organizationId, ?string $organizationName): array
    {
        if (strlen($alias) > 96 || preg_match('/^[\p{L}\p{N} ._-]+$/u', $alias) !== 1 || strlen($apiKey) < 16 || strlen($apiKey) > 512) {
            throw new AppError('validation_error', 'Account alias or API key has an invalid length.', 400);
        }
        $normalized = Util::normalizeName($alias);
        if ($normalized === '') {
            throw new AppError('validation_error', 'Account alias is required.', 400);
        }
        $existing = $this->pdo->prepare('SELECT id FROM lxmcp_accounts WHERE user_id = ? AND alias_normalized = ?');
        $existing->execute([$userId, $normalized]);
        $id = $existing->fetchColumn();
        if (!is_string($id)) {
            $id = Util::uuid();
        }
        $encrypted = Crypto::encryptApiKey($apiKey, $id);
        $sql = 'INSERT INTO lxmcp_accounts (id,user_id,alias,alias_normalized,organization_id,organization_name,api_key_ciphertext,api_key_nonce,api_key_fingerprint,active) VALUES (?,?,?,?,?,?,?,?,?,1) '
            . 'ON DUPLICATE KEY UPDATE alias=VALUES(alias),organization_id=VALUES(organization_id),organization_name=VALUES(organization_name),api_key_ciphertext=VALUES(api_key_ciphertext),api_key_nonce=VALUES(api_key_nonce),api_key_fingerprint=VALUES(api_key_fingerprint),active=1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(1, $id);
        $stmt->bindValue(2, $userId, PDO::PARAM_INT);
        $stmt->bindValue(3, $alias);
        $stmt->bindValue(4, $normalized);
        $stmt->bindValue(5, $organizationId);
        $stmt->bindValue(6, $organizationName);
        $stmt->bindValue(7, $encrypted['ciphertext'], PDO::PARAM_LOB);
        $stmt->bindValue(8, $encrypted['nonce'], PDO::PARAM_LOB);
        $stmt->bindValue(9, Crypto::fingerprint($apiKey));
        $stmt->execute();
        sodium_memzero($apiKey);
        return $this->getForUser($userId, $alias);
    }

    public function setActive(int $userId, string $alias, bool $active): void
    {
        $stmt = $this->pdo->prepare('UPDATE lxmcp_accounts SET active = ? WHERE user_id = ? AND alias_normalized = ?');
        $stmt->execute([$active ? 1 : 0, $userId, Util::normalizeName($alias)]);
        if ($stmt->rowCount() !== 1) {
            throw new AppError('account_not_found', 'Account not found.', 404);
        }
    }
}
