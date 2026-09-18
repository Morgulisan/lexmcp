<?php
declare(strict_types=1);

namespace Todo;

/** One shared application service for browser and MCP; transport never grants domain privileges. */
final class Service
{
    use TaskActions, CatalogActions, TaskDeletion;
    public const STATUSES = ['draft','ready','agent_working','user_working','waiting_user','waiting_external','review','done','cancelled'];
    public const COMMENTS = ['question','decision','proposal','blocker','progress','handoff','result','general'];
    public readonly string $workspace;
    private readonly \Closure $clock;
    public function __construct(private readonly Database $db, private readonly Crypto $crypto, public readonly Actor $actor, private readonly string $requestId, ?callable $clock = null)
    {
        $this->workspace = $db->workspace($actor->user);
        $this->clock = $clock === null ? time(...) : $clock(...);
    }
    private function now(): int { return ($this->clock)(); }
    private function checkAgent(): void
    {
        if ($this->actor->isUser()) return;
        $agent = $this->db->entity($this->workspace, 'agent', $this->actor->agent);
        if ($agent['revoked_at'] !== null) throw new Failure('revoked', 'Agentenverbindung widerrufen.', 401);
        // A connection's permissions are immutable; narrowing is implemented as revocation.
    }
    public function read(string $action, array $input = []): array
    {
        $this->checkAgent();
        $this->actor->scope('todo:read');
        return match ($action) {
            'tasks' => $this->search($input),
            'task' => $this->detail(Support::text($input, 'task_id', 32)),
            'memory' => $this->readMemory($input),
            'projects' => array_values(array_filter($this->db->entities($this->workspace, 'project'), fn($p) => $this->actor->isUser() || in_array($p['id'], $this->actor->projects, true))),
            'capabilities' => $this->db->entities($this->workspace, 'capability'),
            'skills' => $this->skills($input),
            'activity' => $this->activity(),
            'settings' => $this->settings(),
            'operation' => $this->operation($input),
            default => throw new Failure('unknown_action', 'Unbekannte Leseaktion.'),
        };
    }
    public function mutate(string $action, array $input): array
    {
        $key = Support::text($input, 'idempotency_key', 128);
        if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $key)) throw new Failure('invalid_key', 'Idempotency Key muss 8–128 sichere Zeichen enthalten.');
        $hash = hash('sha256', Support::json([$action, $input]));
        $result = $this->db->transaction($this->workspace, function () use ($action, $input, $key, $hash): array {
            $this->checkAgent();
            // A delayed upload replay must not recreate bytes for a deleted task.
            if ($action === 'file_add') $this->task(Support::text($input, 'task_id', 32));
            $this->actor->scope($action === 'comment' || $action === 'comment_update' ? 'todo:comment' : 'todo:write');
            $existing = $this->db->query('SELECT request_hash,response_cipher FROM todo_operations WHERE workspace_id=? AND actor_id=? AND operation_key=?', [$this->workspace, $this->actor->key(), $key])->fetch();
            $context = $this->workspace . ':' . $this->actor->key() . ':' . $key;
            if ($existing) {
                if (!hash_equals($existing['request_hash'], $hash)) throw new Failure('idempotency_conflict', 'Dieser Schlüssel gehört zu einer anderen Änderung.', 409);
                return $this->crypto->decrypt($existing['response_cipher'], $context);
            }
            $result = match ($action) {
                'project_save' => $this->saveProject($input),
                'capability_save' => $this->saveCapability($input),
                'task_create' => $this->createTask($input),
                'skill_save' => $this->saveSkill($input),
                'session_begin' => $this->sessionBegin($input),
                'agent_update' => $this->updateConnection($input),
                'agent_revoke' => $this->revokeConnection($input),
                'policy_save' => $this->savePolicy($input),
                'view_save' => $this->saveView($input),
                default => $this->changeTask($action, $input),
            };
            $this->db->query('INSERT INTO todo_operations(workspace_id,actor_id,operation_key,request_hash,response_cipher,created_at) VALUES (?,?,?,?,?,?)', [$this->workspace, $this->actor->key(), $key, $hash, $this->crypto->encrypt($result, $context), $this->now()]);
            if (!$this->actor->isUser()) {
                $agent = $this->db->entity($this->workspace, 'agent', $this->actor->agent);
                $agent['last_activity'] = $this->now();
                $this->db->saveEntity($this->workspace, 'agent', $agent);
            }
            return $result;
        });
        if (in_array($action, ['delete', 'dependency_remove'], true) && $this->cleanupFiles($key) > 0) {
            throw new Failure('file_delete_pending', 'Aufgabe gelöscht, aber eine Datei konnte noch nicht entfernt werden. Mit demselben Idempotency Key erneut versuchen; der Worker wiederholt die Löschung ebenfalls.', 503);
        }
        return $result;
    }
    private function operation(array $input): array
    {
        $key = Support::text($input, 'idempotency_key', 128);
        $row = $this->db->query('SELECT response_cipher FROM todo_operations WHERE workspace_id=? AND actor_id=? AND operation_key=?', [$this->workspace, $this->actor->key(), $key])->fetch();
        if (!$row) return ['state' => 'not_found'];
        $pending = array_filter($this->db->entities($this->workspace, 'file_gc'), fn($file) => ($file['operation_key'] ?? null) === $key && ($file['actor_id'] ?? null) === $this->actor->key());
        return ['state' => $pending ? 'cleanup_pending' : 'completed', 'result' => $this->crypto->decrypt($row['response_cipher'], $this->workspace . ':' . $this->actor->key() . ':' . $key)];
    }
    private function queueTaskFiles(string $taskId, string $key): void
    {
        foreach ($this->db->entities($this->workspace, 'artifact') as $file) {
            if ($file['task_id'] !== $taskId || !isset($file['storage_id'])) continue;
            $this->db->saveEntity($this->workspace, 'file_gc', ['id' => $file['storage_id'], 'version' => 1, 'task_id' => $taskId, 'operation_key' => $key, 'actor_id' => $this->actor->key()]);
            $this->db->query('DELETE FROM todo_entities WHERE workspace_id=? AND kind=? AND id=?', [$this->workspace, 'artifact', $file['id']]);
        }
    }
    private function cleanupFiles(?string $key = null): int
    {
        $pending = 0;
        foreach ($this->db->entities($this->workspace, 'file_gc') as $file) {
            if ($key !== null && (($file['operation_key'] ?? null) !== $key || ($file['actor_id'] ?? null) !== $this->actor->key())) continue;
            try { $removed = FileStore::remove($file['id']); } catch (\Throwable) { $removed = false; }
            if (!$removed) { $pending++; continue; }
            $this->db->transaction($this->workspace, fn() => $this->db->query('DELETE FROM todo_entities WHERE workspace_id=? AND kind=? AND id=?', [$this->workspace, 'file_gc', $file['id']]));
        }
        return $pending;
    }
    private function task(string $id, bool $includeArchived = false): array
    {
        $task = $this->db->task($this->workspace, $id);
        $this->actor->project($task['project_id']);
        if (!$includeArchived && $task['archived_at'] !== null) throw new Failure('not_found', 'Aufgabe ist archiviert.', 404);
        $this->enforce('read', $task);
        return $task;
    }
    private function publicTask(array $task): array
    {
        if (isset($task['claim'])) {
            unset($task['claim']['hash']);
            if ($task['claim']['expires_at'] <= $this->now()) {
                $task['claim'] = null;
                if ($task['status'] === 'agent_working') $task['status'] = 'ready';
            }
        }
        return $task;
    }
    private function search(array $input): array
    {
        $items = [];
        $query = isset($input['query']) ? mb_strtolower(Support::text($input, 'query', 300, true)) : '';
        foreach ($this->db->tasks($this->workspace) as $task) {
            if (!$this->actor->isUser() && !in_array($task['project_id'], $this->actor->projects, true)) continue;
            if ($task['archived_at'] !== null && !($input['archived'] ?? false)) continue;
            if ($task['archived_at'] === null && ($input['archived'] ?? false)) continue;
            if (isset($input['project_id']) && $input['project_id'] !== $task['project_id']) continue;
            $task = $this->publicTask($task);
            if (isset($input['status']) && $input['status'] !== $task['status']) continue;
            if (isset($input['tag']) && !in_array($input['tag'], $task['tags'], true)) continue;
            if ($query !== '' && !str_contains(mb_strtolower($task['title'] . ' ' . $task['description'] . ' ' . implode(' ', $task['tags'])), $query)) continue;
            try { $this->enforce('read', $task); } catch (Failure) { continue; }
            if (($input['compatible'] ?? false) && (array_diff($this->requiredCapabilities($task), $this->actor->capabilities) || !$this->dependenciesDone($task))) continue;
            if (($input['attention'] ?? false) && !in_array($task['status'], ['waiting_user','review'], true) && !($task['due_at'] !== null && $task['due_at'] < $this->now() && !in_array($task['status'], ['done','cancelled'], true)) && !($task['agent_error'] ?? false)) continue;
            $items[] = $task;
        }
        usort($items, fn($a, $b) => [-$a['priority'], $a['due_at'] ?? PHP_INT_MAX, $a['id']] <=> [-$b['priority'], $b['due_at'] ?? PHP_INT_MAX, $b['id']]);
        $offset = isset($input['offset']) ? Support::number($input, 'offset', 0, 1000000) : 0;
        $limit = isset($input['limit']) ? Support::number($input, 'limit', 1, 200) : 100;
        return ['items' => array_slice($items, $offset, $limit), 'total' => count($items), 'next_offset' => count($items) > $offset + $limit ? $offset + $limit : null];
    }
    private function detail(string $id): array
    {
        $task = $this->task($id);
        $result = ['task' => $this->publicTask($task)];
        foreach (['comment','plan','review','artifact'] as $kind) $result[$kind . 's'] = array_values(array_filter($this->db->entities($this->workspace, $kind), fn($item) => $item['task_id'] === $id));
        $result['subtasks'] = array_values(array_map($this->publicTask(...), array_filter($this->db->tasks($this->workspace), fn($t) => $t['parent_id'] === $id && $t['archived_at'] === null)));
        return $result;
    }
    private function readMemory(array $input): array
    {
        $task = $this->task(Support::text($input, 'task_id', 32));
        $row = $this->db->task($this->workspace, $task['id'], true);
        $result = ['task_id' => $task['id'], 'version' => (int)$row['memory_version'], 'content' => $row['memory_md'], 'trust' => 'untrusted_task_context'];
        if ($this->actor->isUser()) $result['history'] = array_values(array_filter($this->db->entities($this->workspace, 'memory'), fn($m) => $m['task_id'] === $task['id']));
        return $result;
    }
    private function version(array $record, array $input, string $field = 'expected_version'): void
    {
        if (($input[$field] ?? null) !== (int)$record['version']) throw new Failure('version_conflict', 'Der Eintrag wurde geändert. Bitte neu laden.', 409);
    }
    private function project(string $id): array
    {
        $this->actor->project($id);
        return $this->db->entity($this->workspace, 'project', $id);
    }
    private function saveProject(array $input): array
    {
        $this->actor->userOnly();
        $old = isset($input['id']) ? $this->project(Support::text($input, 'id', 32)) : null;
        if ($old) $this->version($old, $input);
        $project = ['id' => $old['id'] ?? Support::id(), 'version' => ($old['version'] ?? 0) + 1, 'name' => Support::text($input, 'name', 160), 'description' => Support::text($input + ['description' => ''], 'description', 10000, true), 'policy' => Policy::validate($input['policy'] ?? $old['policy'] ?? []), 'archived' => (bool)($input['archived'] ?? false)];
        Support::noSecrets($project['description']);
        $project['timezone'] = Support::text($input + ['timezone' => $old['timezone'] ?? 'Europe/Berlin'], 'timezone', 100);
        if (!in_array($project['timezone'], \DateTimeZone::listIdentifiers(), true)) throw new Failure('invalid_timezone', 'Unbekannte Zeitzone.');
        if ($old) {
            $oldComparable = $old; $projectComparable = $project;
            unset($oldComparable['version'], $projectComparable['version']);
            if ($oldComparable === $projectComparable) return $old;
        }
        if ($old && ($old['timezone'] ?? 'Europe/Berlin') !== $project['timezone']) {
            foreach ($this->db->tasks($this->workspace) as $task) {
                if ($task['project_id'] !== $project['id']) continue;
                foreach (['start_at', 'due_at'] as $field) if ($task[$field] !== null) {
                    $local = (new \DateTimeImmutable('@' . $task[$field]))->setTimezone(new \DateTimeZone($task['timezone']));
                    $task[$field] = (new \DateTimeImmutable($local->format('Y-m-d H:i:s'), new \DateTimeZone($project['timezone'])))->getTimestamp();
                }
                $task['timezone'] = $project['timezone'];
                $task['recurrence_step'] = 0;
                $task['version']++;
                $task['updated_at'] = $this->now();
                $this->db->saveTask($this->workspace, $task);
            }
        }
        $this->db->saveEntity($this->workspace, 'project', $project);
        $this->audit('project_save', null, ['id' => $project['id'], 'version' => $project['version']]);
        return $project;
    }
    private function saveCapability(array $input): array
    {
        $this->actor->userOnly();
        $id = isset($input['id']) ? Support::text($input, 'id', 64) : Support::id();
        if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $id)) throw new Failure('invalid_id', 'Capability-ID ist ungültig.');
        $value = ['id' => $id, 'version' => 1, 'name' => Support::text($input, 'name', 160)];
        foreach ($this->db->entities($this->workspace, 'capability') as $old) if ($old['id'] === $id) { $this->version($old, $input); $value['version'] = $old['version'] + 1; }
        $value['description'] = Support::text($input + ['description' => ''], 'description', 10000, true);
        $this->db->saveEntity($this->workspace, 'capability', $value);
        $this->audit('capability_save', null, ['id' => $id]);
        return $value;
    }
    private function fields(array $input, array $task): array
    {
        $project = $this->project($task['project_id']);
        $input['timezone'] = $project['timezone'] ?? 'Europe/Berlin';
        foreach (['start_at', 'due_at'] as $field) {
            if (!isset($input[$field]) || !is_string($input[$field])) continue;
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $input[$field], new \DateTimeZone($input['timezone']));
            if (!$day || $day->format('Y-m-d') !== $input[$field]) throw new Failure('invalid_dates', 'Ungültiges Datum.');
            $input[$field] = ($field === 'due_at' ? $day->setTime(23, 59, 59) : $day)->getTimestamp();
        }
        foreach (['due_at','recurrence','timezone'] as $scheduleField) if (array_key_exists($scheduleField, $input) && $input[$scheduleField] !== $task[$scheduleField]) $task['recurrence_step'] = 0;
        $allowed = ['title','description','priority','risk','effort','start_at','due_at','timezone','tags','capabilities','skill_ids','recurrence','policy'];
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $input)) continue;
            $value = $input[$field];
            if (in_array($field, ['title','description','timezone'], true)) $value = Support::text($input, $field, $field === 'description' ? 30000 : 200, $field === 'description');
            if (in_array($field, ['priority','risk','effort'], true)) $value = Support::number($input, $field, 1, 5);
            if (in_array($field, ['start_at','due_at'], true) && $value !== null) $value = Support::number($input, $field, 0, 4102444800);
            if (in_array($field, ['tags','capabilities','skill_ids'], true)) $value = Support::strings($value);
            if ($field === 'recurrence' && !in_array($value, [null,'daily','weekly','monthly'], true)) throw new Failure('invalid_recurrence', 'Ungültige Wiederholung.');
            if ($field === 'policy') { $this->actor->userOnly(); $value = Policy::validate($value); }
            $task[$field] = $value;
        }
        if (!in_array($task['timezone'], \DateTimeZone::listIdentifiers(), true)) throw new Failure('invalid_timezone', 'Unbekannte Zeitzone.');
        if ($task['start_at'] !== null && $task['due_at'] !== null && $task['start_at'] > $task['due_at']) throw new Failure('invalid_dates', 'Start liegt nach der Fälligkeit.');
        if ($task['recurrence'] !== null && $task['due_at'] === null) throw new Failure('invalid_recurrence', 'Wiederholungen benötigen eine Fälligkeit.');
        foreach ($task['capabilities'] as $id) $this->db->entity($this->workspace, 'capability', $id);
        foreach ($task['skill_ids'] as $id) {
            $skill = $this->db->entity($this->workspace, 'skill', $id);
            $this->actor->project($skill['project_id']);
            if ($skill['project_id'] !== $task['project_id'] || $skill['draft']) throw new Failure('invalid_skill', 'Nur veröffentlichte Skills desselben Projekts sind zuweisbar.');
        }
        Support::noSecrets($task['title'] . '\n' . $task['description']);
        return $task;
    }
    private function createTask(array $input): array
    {
        $project = $this->project(Support::text($input, 'project_id', 32));
        if ($project['archived']) throw new Failure('archived', 'Projekt ist archiviert.', 409);
        $task = ['id' => Support::id(), 'version' => 1, 'project_id' => $project['id'], 'parent_id' => $input['parent_id'] ?? null, 'title' => Support::text($input, 'title', 200), 'description' => '', 'status' => 'draft', 'priority' => 3, 'risk' => 1, 'effort' => 3, 'start_at' => null, 'due_at' => null, 'timezone' => 'Europe/Berlin', 'tags' => [], 'capabilities' => [], 'skill_ids' => [], 'dependencies' => [], 'claim' => null, 'policy' => [], 'recurrence' => null, 'recurrence_source' => null, 'dependency_target' => null, 'archived_at' => null, 'created_at' => $this->now(), 'updated_at' => $this->now(), 'created_by' => $this->actor->key(), 'agent_error' => false];
        if ($task['parent_id'] !== null) {
            $parent = $this->task(Support::text($input, 'parent_id', 32));
            if ($parent['project_id'] !== $project['id']) throw new Failure('invalid_parent', 'Unteraufgaben gehören zum selben Projekt.');
            if (!$this->actor->isUser()) { $this->requireClaim($parent, $input); $this->version($parent, $input); }
        }
        $task = $this->fields($input, $task);
        $this->enforce('draft', $task);
        if (($input['status'] ?? 'draft') !== 'draft') {
            $this->actor->userOnly();
            if ($input['status'] !== 'ready') throw new Failure('invalid_transition', 'Neue Aufgaben sind Entwürfe oder bereit.');
            $task['status'] = 'ready';
        }
        $this->db->saveTask($this->workspace, $task, true);
        $this->audit('task_create', $task['id'], ['title' => $task['title'], 'version' => 1]);
        return $this->publicTask($task);
    }
    private function requireClaim(array $task, array $input): void
    {
        if ($this->actor->isUser()) return;
        $claim = $task['claim'];
        $token = Support::text($input, 'claim_token', 128);
        if ($claim === null || $claim['agent_id'] !== $this->actor->agent || $claim['expires_at'] <= $this->now() || !hash_equals($claim['hash'], hash('sha256', $token))) throw new Failure('claim_required', 'Ein gültiger eigener Claim ist erforderlich.', 409);
    }
    private function enforce(string $action, array $task): void
    {
        if ($this->actor->isUser()) return;
        $global = Support::decode($this->db->query('SELECT policy_json FROM todo_workspaces WHERE id=?', [$this->workspace])->fetchColumn());
        $project = $this->db->entity($this->workspace, 'project', $task['project_id']);
        $decision = Policy::decision($action, $this->actor, $task, $global, $project['policy'], $task['policy'] ?? []);
        if ($decision === 'deny') throw new Failure('policy_denied', 'Die Policy verbietet diese Aktion.', 403);
        if ($decision === 'approval') {
            $plans = array_values(array_filter($this->db->entities($this->workspace, 'plan'), fn($p) => $p['task_id'] === $task['id']));
            usort($plans, fn($a,$b) => $b['sequence'] <=> $a['sequence']);
            $plan = $plans[0] ?? null;
            if (!$plan || $plan['decision'] !== 'approved' || !in_array($action, $plan['actions'], true) || $plan['risk'] < $task['risk']) throw new Failure('approval_required', 'Für diese Aktion fehlt eine gültige Planfreigabe.', 403);
        }
    }
    private function requiredCapabilities(array $task): array
    {
        $caps = $task['capabilities'];
        foreach ($task['skill_ids'] as $id) $caps = array_merge($caps, $this->db->entity($this->workspace, 'skill', $id)['capabilities']);
        return array_values(array_unique($caps));
    }
    private function dependenciesDone(array $task): bool
    {
        foreach ($task['dependencies'] as $id) {
            try { $dependency = $this->db->task($this->workspace, $id); }
            catch (Failure $error) { if ($error->reason === 'not_found') return false; throw $error; }
            if ($dependency['status'] !== 'done' || $dependency['archived_at'] !== null) return false;
        }
        return true;
    }
    private function audit(string $action, ?string $taskId, array $detail): void
    {
        $this->db->query('INSERT INTO todo_audit(id,workspace_id,task_id,user_id,agent_id,client_id,run_id,request_id,action,detail_json,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [Support::id(), $this->workspace, $taskId, $this->actor->user, $this->actor->agent, $this->actor->client, $this->actor->run, $this->requestId, $action, Support::json($detail), $this->now()]);
    }
}
