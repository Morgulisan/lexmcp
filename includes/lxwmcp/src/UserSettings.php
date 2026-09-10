<?php
declare(strict_types=1);

namespace LexMcp;

final class UserSettings
{
    public function __construct(private readonly \PDO $pdo) {}

    public function finalizeEnabled(int $userId): bool
    {
        $stmt = $this->pdo->prepare('SELECT finalize_enabled FROM lxmcp_user_settings WHERE user_id=?');
        $stmt->execute([$userId]);
        $value = $stmt->fetchColumn();
        return $value === false || (int) $value === 1;
    }

    public function setFinalizeEnabled(int $userId, bool $enabled): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO lxmcp_user_settings(user_id,finalize_enabled) VALUES (?,?) ON DUPLICATE KEY UPDATE finalize_enabled=VALUES(finalize_enabled)');
        $stmt->execute([$userId, $enabled ? 1 : 0]);
    }
}
