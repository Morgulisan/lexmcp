<?php
declare(strict_types=1);

namespace Todo;

trait TaskDeletion
{
    /** Called under the workspace write lock. No file is removed before commit. */
    private function deleteTask(array $task, string $key): array
    {
        $tasks = $this->db->tasks($this->workspace);
        $ids = [$task['id'] => true];
        do {
            $count = count($ids);
            foreach ($tasks as $candidate) {
                if (isset($ids[$candidate['parent_id'] ?? '']) || isset($ids[$candidate['dependency_target'] ?? ''])) $ids[$candidate['id']] = true;
            }
        } while (count($ids) !== $count);

        foreach (array_keys($ids) as $id) $this->queueTaskFiles($id, $key);
        foreach (['memory','comment','plan','review','artifact'] as $kind) {
            foreach ($this->db->entities($this->workspace, $kind) as $item) if (isset($ids[$item['task_id']])) {
                $this->db->query('DELETE FROM todo_entities WHERE workspace_id=? AND kind=? AND id=?', [$this->workspace, $kind, $item['id']]);
            }
        }
        foreach ($tasks as $candidate) {
            if (isset($ids[$candidate['id']])) {
                $this->db->query('DELETE FROM todo_tasks WHERE workspace_id=? AND id=?', [$this->workspace, $candidate['id']]);
                $this->db->query('DELETE FROM todo_audit WHERE workspace_id=? AND task_id=?', [$this->workspace, $candidate['id']]);
                continue;
            }
            $dependencies = array_values(array_filter($candidate['dependencies'], fn($id) => !isset($ids[$id])));
            if ($dependencies !== $candidate['dependencies']) {
                $candidate['dependencies'] = $dependencies; $candidate['version']++; $candidate['updated_at'] = $this->now();
                $this->db->saveTask($this->workspace, $candidate);
                $this->audit('deleted_dependency_removed', $candidate['id'], ['version' => $candidate['version']]);
            }
        }
        // Keep small tombstones so a delayed task_create replay cannot recreate data.
        foreach ($this->db->query('SELECT actor_id,operation_key,response_cipher FROM todo_operations WHERE workspace_id=?', [$this->workspace])->fetchAll() as $operation) {
            $context = $this->workspace . ':' . $operation['actor_id'] . ':' . $operation['operation_key'];
            $response = $this->crypto->decrypt($operation['response_cipher'], $context);
            $responseId = $response['task']['id'] ?? $response['id'] ?? null;
            if ($responseId === null || !isset($ids[$responseId])) continue;
            $tombstone = ['state' => 'resource_deleted', 'deleted_task_id' => $responseId];
            $this->db->query('UPDATE todo_operations SET response_cipher=? WHERE workspace_id=? AND actor_id=? AND operation_key=?', [$this->crypto->encrypt($tombstone, $context), $this->workspace, $operation['actor_id'], $operation['operation_key']]);
        }
        $this->audit('task_deleted', $task['id'], ['deleted_count' => count($ids)]);
        return ['deleted_task_id' => $task['id'], 'deleted_count' => count($ids)];
    }
}
