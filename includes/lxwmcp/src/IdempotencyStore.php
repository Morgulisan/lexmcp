<?php
declare(strict_types=1);

namespace LexMcp;

use PDO;

final class IdempotencyStore
{
    public function __construct(private readonly PDO $pdo) {}

    public function begin(int $userId, string $accountId, string $operation, string $key, array $payload): array
    {
        $hash = hash('sha256', Util::canonicalJson($payload));
        try {
            $stmt = $this->pdo->prepare("INSERT INTO lxmcp_idempotency(user_id,account_id,operation,idempotency_key,payload_hash,state) VALUES (?,?,?,?,?,'pending')");
            $stmt->execute([$userId, $accountId, $operation, $key, $hash]);
            return ['new' => true, 'payload_hash' => $hash];
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
        }
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT payload_hash,state,result_json,error_code,updated_at FROM lxmcp_idempotency WHERE user_id=? AND account_id=? AND operation=? AND idempotency_key=? FOR UPDATE');
            $stmt->execute([$userId, $accountId, $operation, $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new AppError('idempotency_error', 'Idempotency record could not be loaded.', 500);
            }
            if (!hash_equals((string) $row['payload_hash'], $hash)) {
                throw new AppError('idempotency_conflict', 'The idempotency key was already used with different input.', 409);
            }
            if ($row['state'] === 'complete') {
                $this->pdo->commit();
                return ['new' => false, 'state' => 'complete', 'result' => json_decode((string) $row['result_json'], true), 'payload_hash' => $hash];
            }
            if ($row['state'] === 'uncertain') {
                throw new AppError('operation_uncertain', 'A previous attempt has an uncertain outcome and will not be repeated automatically.', 409, false, ['operation' => $operation], 'Use lexware_get with entity operation_status and this idempotency key.');
            }
            if ($row['state'] === 'failed') {
                $reset = $this->pdo->prepare("UPDATE lxmcp_idempotency SET state='pending',error_code=NULL,result_json=NULL WHERE user_id=? AND account_id=? AND operation=? AND idempotency_key=?");
                $reset->execute([$userId, $accountId, $operation, $key]);
                $this->pdo->commit();
                return ['new' => true, 'payload_hash' => $hash];
            }
            if (strtotime((string) $row['updated_at']) < time() - 120) {
                $mark = $this->pdo->prepare("UPDATE lxmcp_idempotency SET state='uncertain',error_code='stale_pending' WHERE user_id=? AND account_id=? AND operation=? AND idempotency_key=?");
                $mark->execute([$userId, $accountId, $operation, $key]);
                $this->pdo->commit();
                throw new AppError('operation_uncertain', 'A stale in-progress operation has an uncertain outcome.', 409, false, ['operation' => $operation]);
            }
            throw new AppError('operation_in_progress', 'The operation is already in progress.', 409, true);
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function complete(int $userId, string $accountId, string $operation, string $key, array $result): void
    {
        $stmt = $this->pdo->prepare("UPDATE lxmcp_idempotency SET state='complete',result_json=?,error_code=NULL WHERE user_id=? AND account_id=? AND operation=? AND idempotency_key=?");
        $stmt->execute([Util::jsonEncode($result), $userId, $accountId, $operation, $key]);
    }

    public function uncertain(int $userId, string $accountId, string $operation, string $key, string $code): void
    {
        $stmt = $this->pdo->prepare("UPDATE lxmcp_idempotency SET state='uncertain',error_code=? WHERE user_id=? AND account_id=? AND operation=? AND idempotency_key=?");
        $stmt->execute([$code, $userId, $accountId, $operation, $key]);
    }

    public function failed(int $userId, string $accountId, string $operation, string $key, string $code): void
    {
        $stmt = $this->pdo->prepare("UPDATE lxmcp_idempotency SET state='failed',error_code=? WHERE user_id=? AND account_id=? AND operation=? AND idempotency_key=?");
        $stmt->execute([$code, $userId, $accountId, $operation, $key]);
    }

    public function status(int $userId, string $accountId, string $operation, string $key): array
    {
        $stmt = $this->pdo->prepare('SELECT operation,idempotency_key,state,result_json,error_code,created_at,updated_at FROM lxmcp_idempotency WHERE user_id=? AND account_id=? AND operation=? AND idempotency_key=?');
        $stmt->execute([$userId, $accountId, $operation, $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new AppError('operation_not_found', 'No operation exists for this idempotency key.', 404);
        }
        $row['result'] = $row['result_json'] === null ? null : json_decode((string) $row['result_json'], true);
        unset($row['result_json']);
        return $row;
    }
}
