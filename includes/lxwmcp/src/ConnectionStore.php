<?php
declare(strict_types=1);

namespace LexMcp;

use PDO;

final class ConnectionStore
{
    public function __construct(private readonly PDO $pdo) {}

    public function create(int $userId, string $id, string $clientId, string $name, array $requestedScopes): void
    {
        $scopes = $this->validateScopes($requestedScopes);
        $name = $this->validateName($name);
        $stmt = $this->pdo->prepare('INSERT INTO lxmcp_oauth_connections(id,user_id,client_id,name,requested_scopes_json,allowed_scopes_json) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$id, $userId, $clientId, $name, Util::jsonEncode($scopes), Util::jsonEncode($scopes)]);
    }

    public function listForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id,c.client_id,c.name,c.requested_scopes_json,c.allowed_scopes_json,c.created_at,c.updated_at,o.client_name '
            . 'FROM lxmcp_oauth_connections c LEFT JOIN lxmcp_oauth_clients o ON o.client_id=c.client_id '
            . 'WHERE c.user_id=? AND c.revoked_at IS NULL ORDER BY c.updated_at DESC'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['requested_scopes'] = $this->decodeScopes((string) $row['requested_scopes_json']);
            $row['allowed_scopes'] = $this->decodeScopes((string) $row['allowed_scopes_json']);
            unset($row['requested_scopes_json'], $row['allowed_scopes_json']);
        }
        unset($row);
        return $rows;
    }

    public function update(int $userId, string $id, string $name, array $allowedScopes): void
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $id) !== 1) {
            throw new AppError('validation_error', 'Invalid connection id.', 400);
        }
        $name = $this->validateName($name);
        $scopesJson = Util::jsonEncode($this->validateScopes($allowedScopes));
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('UPDATE lxmcp_oauth_connections SET name=?,allowed_scopes_json=? WHERE id=? AND user_id=? AND revoked_at IS NULL');
            $stmt->execute([$name, $scopesJson, $id, $userId]);
            if ($stmt->rowCount() === 0) {
                $check = $this->pdo->prepare('SELECT 1 FROM lxmcp_oauth_connections WHERE id=? AND user_id=? AND revoked_at IS NULL FOR UPDATE');
                $check->execute([$id, $userId]);
                if ($check->fetchColumn() === false) {
                    throw new AppError('connection_not_found', 'MCP connection not found.', 404);
                }
            }
            // The user is the grant owner. Updating outstanding tokens makes both
            // reductions and deliberate expansions effective immediately.
            $tokens = $this->pdo->prepare('UPDATE lxmcp_oauth_tokens SET scopes_json=? WHERE family_id=? AND user_id=? AND revoked_at IS NULL');
            $tokens->execute([$scopesJson, $id, $userId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function allowedScopes(string $id, int $userId, bool $lock = false): array
    {
        $sql = 'SELECT allowed_scopes_json FROM lxmcp_oauth_connections WHERE id=? AND user_id=? AND revoked_at IS NULL' . ($lock ? ' FOR UPDATE' : '');
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id, $userId]);
        $json = $stmt->fetchColumn();
        if (!is_string($json)) {
            throw new AppError('invalid_token', 'The MCP connection no longer exists.', 401);
        }
        return $this->decodeScopes($json);
    }

    private function validateName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 96 || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            throw new AppError('validation_error', 'Connection name must contain 1 to 96 printable characters.', 400);
        }
        return $name;
    }

    private function validateScopes(array $scopes): array
    {
        foreach ($scopes as $scope) {
            if (!is_string($scope)) {
                throw new AppError('validation_error', 'Permissions must be strings.', 400);
            }
        }
        $scopes = array_values(array_unique($scopes));
        if (array_diff($scopes, Config::scopes()) !== []) {
            throw new AppError('validation_error', 'One or more permissions are unknown.', 400);
        }
        return array_values(array_intersect(Config::scopes(), $scopes));
    }

    private function decodeScopes(string $json): array
    {
        $scopes = json_decode($json, true);
        if (!is_array($scopes)) {
            throw new AppError('invalid_token', 'Stored MCP connection permissions are invalid.', 401);
        }
        return $this->validateScopes($scopes);
    }
}
