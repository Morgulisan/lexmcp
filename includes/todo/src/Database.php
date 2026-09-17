<?php
declare(strict_types=1);

namespace Todo;

use PDO;

/** All domain writes take the workspace mutex before reading mutable state.
 * This deliberately serializes V1 writes while allowing independent workspaces.
 * No network calls or file scans may run inside this transaction.
 */
final class Database
{
    public readonly bool $sqlite;
    public function __construct(public readonly PDO $pdo)
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        if ($this->sqlite) $pdo->exec('PRAGMA busy_timeout=10000');
        if (!$this->sqlite) $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }
    public function query(string $sql, array $params = []): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement;
    }
    public function migrate(): void
    {
        $suffix = $this->sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
        $statements = [
            'CREATE TABLE IF NOT EXISTS todo_workspaces (id VARCHAR(32) PRIMARY KEY, owner_id BIGINT NOT NULL UNIQUE, name VARCHAR(160) NOT NULL, policy_json MEDIUMTEXT NOT NULL, revision BIGINT NOT NULL DEFAULT 0)',
            'CREATE TABLE IF NOT EXISTS todo_entities (workspace_id VARCHAR(32) NOT NULL, kind VARCHAR(24) NOT NULL, id VARCHAR(64) NOT NULL, version BIGINT NOT NULL, payload MEDIUMTEXT NOT NULL, PRIMARY KEY(workspace_id,kind,id))',
            'CREATE TABLE IF NOT EXISTS todo_tasks (workspace_id VARCHAR(32) NOT NULL, id VARCHAR(32) NOT NULL, project_id VARCHAR(32) NOT NULL, version BIGINT NOT NULL, memory_version BIGINT NOT NULL DEFAULT 0, memory_md TEXT NOT NULL, payload MEDIUMTEXT NOT NULL, PRIMARY KEY(workspace_id,id))',
            'CREATE TABLE IF NOT EXISTS todo_operations (workspace_id VARCHAR(32) NOT NULL, actor_id VARCHAR(64) NOT NULL, operation_key VARCHAR(128) NOT NULL, request_hash VARCHAR(64) NOT NULL, response_cipher MEDIUMTEXT NOT NULL, created_at BIGINT NOT NULL, PRIMARY KEY(workspace_id,actor_id,operation_key))',
            'CREATE TABLE IF NOT EXISTS todo_audit (id VARCHAR(32) PRIMARY KEY, workspace_id VARCHAR(32) NOT NULL, task_id VARCHAR(32), user_id BIGINT NOT NULL, agent_id VARCHAR(32), client_id VARCHAR(64), run_id VARCHAR(128), request_id VARCHAR(64) NOT NULL, action VARCHAR(80) NOT NULL, detail_json MEDIUMTEXT NOT NULL, created_at BIGINT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS todo_auth (kind VARCHAR(24) NOT NULL, id VARCHAR(128) NOT NULL, payload MEDIUMTEXT NOT NULL, expires_at BIGINT NOT NULL, PRIMARY KEY(kind,id))',
            'CREATE TABLE IF NOT EXISTS todo_limits (id VARCHAR(64) PRIMARY KEY, window_start BIGINT NOT NULL, hits BIGINT NOT NULL)',
        ];
        foreach ($statements as $sql) $this->pdo->exec($sql . $suffix);
    }
    public function workspace(int $owner): string
    {
        $id = substr(hash('sha256', 'todo-workspace:' . $owner), 0, 32);
        $sql = $this->sqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $this->query($sql . ' INTO todo_workspaces(id,owner_id,name,policy_json) VALUES (?,?,?,?)', [$id, $owner, 'Mein Workspace', '[]']);
        return $id;
    }
    public function transaction(string $workspace, callable $action): mixed
    {
        $this->pdo->beginTransaction();
        try {
            // UPDATE is a write lock on SQLite and an exclusive row lock on InnoDB.
            $this->query('UPDATE todo_workspaces SET revision=revision+1 WHERE id=?', [$workspace]);
            $result = $action();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }
    public function entities(string $workspace, string $kind): array
    {
        return array_map(fn(array $row): array => Support::decode($row['payload']), $this->query('SELECT payload FROM todo_entities WHERE workspace_id=? AND kind=? ORDER BY id', [$workspace, $kind])->fetchAll());
    }
    public function entity(string $workspace, string $kind, string $id): array
    {
        $row = $this->query('SELECT payload FROM todo_entities WHERE workspace_id=? AND kind=? AND id=?', [$workspace, $kind, $id])->fetch();
        if (!$row) throw new Failure('not_found', 'Eintrag nicht gefunden.', 404);
        return Support::decode($row['payload']);
    }
    public function saveEntity(string $workspace, string $kind, array $value): void
    {
        $existing = $this->query('SELECT id FROM todo_entities WHERE workspace_id=? AND kind=? AND id=?', [$workspace, $kind, $value['id']])->fetchColumn();
        if ($existing === false) {
            $this->query('INSERT INTO todo_entities(workspace_id,kind,id,version,payload) VALUES (?,?,?,?,?)', [$workspace, $kind, $value['id'], $value['version'], Support::json($value)]);
        } else {
            $this->query('UPDATE todo_entities SET version=?,payload=? WHERE workspace_id=? AND kind=? AND id=?', [$value['version'], Support::json($value), $workspace, $kind, $value['id']]);
        }
    }
    public function task(string $workspace, string $id, bool $memory = false): array
    {
        $columns = $memory ? 'payload,version,memory_version,memory_md' : 'payload,version,memory_version';
        $row = $this->query('SELECT ' . $columns . ' FROM todo_tasks WHERE workspace_id=? AND id=?', [$workspace, $id])->fetch();
        if (!$row) throw new Failure('not_found', 'Aufgabe nicht gefunden.', 404);
        return $this->taskPayload($row['payload']) + array_diff_key($row, ['payload' => true]);
    }
    public function tasks(string $workspace): array
    {
        return array_map(fn(array $row): array => $this->taskPayload($row['payload']) + ['version' => (int)$row['version'], 'memory_version' => (int)$row['memory_version']], $this->query('SELECT payload,version,memory_version FROM todo_tasks WHERE workspace_id=? ORDER BY id', [$workspace])->fetchAll());
    }
    private function taskPayload(string $payload): array
    {
        $task = Support::decode($payload);
        // Earlier reversible "deletions" are archives; never purge them implicitly.
        $task['archived_at'] ??= $task['deleted_at'] ?? null;
        unset($task['deleted_at']);
        return $task;
    }
    public function saveTask(string $workspace, array $task, bool $new = false): void
    {
        $version = $task['version'];
        unset($task['version'], $task['memory_md'], $task['memory_version']);
        if ($new) {
            $this->query('INSERT INTO todo_tasks(workspace_id,id,project_id,version,memory_md,payload) VALUES (?,?,?,?,?,?)', [$workspace, $task['id'], $task['project_id'], $version, '', Support::json($task)]);
        } else {
            $this->query('UPDATE todo_tasks SET project_id=?,version=?,payload=? WHERE workspace_id=? AND id=?', [$task['project_id'], $version, Support::json($task), $workspace, $task['id']]);
        }
    }
}
