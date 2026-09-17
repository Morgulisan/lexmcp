<?php
declare(strict_types=1);

namespace Todo;

trait CatalogActions
{
    private function skills(array $input): array
    {
        $query = mb_strtolower((string)($input['query'] ?? ''));
        return array_values(array_filter($this->db->entities($this->workspace, 'skill'), function ($s) use ($query, $input) {
            return ($this->actor->isUser() || in_array($s['project_id'], $this->actor->projects, true))
                && (!isset($input['id']) || $s['id'] === $input['id'])
                && ($query === '' || str_contains(mb_strtolower($s['name'] . ' ' . $s['slug']), $query));
        }));
    }
    private function saveSkill(array $input): array
    {
        $project = $this->project(Support::text($input, 'project_id', 32));
        $this->enforce('draft', ['id' => 'skill-draft', 'project_id' => $project['id'], 'risk' => 1, 'priority' => 3, 'policy' => []]);
        $slug = Support::text($input, 'slug', 100);
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)) throw new Failure('invalid_slug', 'Skill-Slug ist ungültig.');
        $sequence = 1;
        foreach ($this->db->entities($this->workspace, 'skill') as $skill) if ($skill['project_id'] === $project['id'] && $skill['slug'] === $slug) $sequence = max($sequence, $skill['sequence'] + 1);
        $content = Support::text($input, 'content', 30000);
        Support::noSecrets($content);
        $caps = Support::strings($input['capabilities'] ?? []);
        foreach ($caps as $id) $this->db->entity($this->workspace, 'capability', $id);
        $skill = ['id' => Support::id(), 'version' => 1, 'sequence' => $sequence, 'project_id' => $project['id'], 'slug' => $slug, 'name' => Support::text($input, 'name', 160), 'content' => $content, 'capabilities' => $caps, 'draft' => !$this->actor->isUser() || ($input['draft'] ?? false) === true, 'author' => $this->actor->key(), 'created_at' => $this->now()];
        $this->db->saveEntity($this->workspace, 'skill', $skill);
        $this->audit('skill_save', null, ['id' => $skill['id'], 'sequence' => $sequence, 'draft' => $skill['draft']]);
        return $skill;
    }
    private function sessionBegin(array $input): array
    {
        if ($this->actor->isUser()) throw new Failure('agent_only', 'Agentensitzungen werden über MCP gestartet.');
        $run = Support::text($input, 'run_id', 128);
        Support::noSecrets($run);
        $caps = Support::strings($input['capabilities'] ?? []);
        foreach ($caps as $id) $this->db->entity($this->workspace, 'capability', $id);
        $session = ['id' => Support::id(), 'version' => 1, 'agent_id' => $this->actor->agent, 'run_id' => $run, 'capabilities' => $caps, 'created_at' => $this->now(), 'expires_at' => $this->now() + 12 * 3600, 'revoked_at' => null];
        $this->db->saveEntity($this->workspace, 'session', $session);
        $agent = $this->db->entity($this->workspace, 'agent', $this->actor->agent);
        $agent['last_activity'] = $this->now();
        $this->db->saveEntity($this->workspace, 'agent', $agent);
        $this->audit('session_begin', null, ['id' => $session['id'], 'run_id' => $run]);
        return $session;
    }
    public function revokeConnection(array $input): array
    {
        $this->actor->userOnly();
        if (!$this->db->pdo->inTransaction()) throw new \LogicException('Connection revocation requires the workspace transaction.');
        $agent = $this->db->entity($this->workspace, 'agent', Support::text($input, 'agent_id', 32));
        $this->version($agent, $input);
        $agent['revoked_at'] = $this->now(); $agent['version']++;
        $this->db->saveEntity($this->workspace, 'agent', $agent);
        foreach ($this->db->entities($this->workspace, 'session') as $session) if ($session['agent_id'] === $agent['id']) {
            $session['revoked_at'] = $this->now(); $session['version']++;
            $this->db->saveEntity($this->workspace, 'session', $session);
        }
        foreach ($this->db->tasks($this->workspace) as $task) if (($task['claim']['agent_id'] ?? null) === $agent['id']) {
            $task['claim'] = null;
            if ($task['status'] === 'agent_working') $task['status'] = 'ready';
            $task['version']++;
            $this->db->saveTask($this->workspace, $task);
            $this->audit('claim_revoked', $task['id'], ['agent_id' => $agent['id']]);
        }
        $this->audit('agent_revoke', null, ['agent_id' => $agent['id']]);
        return $agent;
    }
    private function savePolicy(array $input): array
    {
        $this->actor->userOnly();
        $policy = Policy::validate($input['policy'] ?? null);
        $current = $this->db->query('SELECT policy_json FROM todo_workspaces WHERE id=?', [$this->workspace])->fetchColumn();
        if (($input['expected_hash'] ?? null) !== hash('sha256', $current)) throw new Failure('version_conflict', 'Policy wurde geändert.', 409);
        $this->db->query('UPDATE todo_workspaces SET policy_json=? WHERE id=?', [Support::json($policy), $this->workspace]);
        $this->audit('policy_save', null, ['before' => Support::decode($current), 'after' => $policy]);
        return ['policy' => $policy, 'hash' => hash('sha256', Support::json($policy))];
    }
    private function saveView(array $input): array
    {
        $this->actor->userOnly();
        $filters = $input['filters'] ?? [];
        if (!is_array($filters) || array_diff(array_keys($filters), ['query','project_id','status','tag','attention','archived'])) throw new Failure('invalid_filters', 'Ungültige Filter.');
        $view = ['id' => Support::id(), 'version' => 1, 'name' => Support::text($input, 'name', 100), 'filters' => $filters];
        Support::noSecrets(Support::json($view));
        $this->db->saveEntity($this->workspace, 'view', $view);
        $this->audit('view_save', null, ['id' => $view['id']]);
        return $view;
    }
    private function activity(): array
    {
        $this->actor->userOnly();
        $rows = $this->db->query('SELECT * FROM todo_audit WHERE workspace_id=? ORDER BY created_at DESC,id DESC LIMIT 200', [$this->workspace])->fetchAll();
        foreach ($rows as &$row) { $row['detail'] = Support::decode($row['detail_json']); unset($row['detail_json']); }
        return ['audit' => $rows, 'sessions' => $this->db->entities($this->workspace, 'session'), 'claims' => array_values(array_filter(array_map($this->publicTask(...), $this->db->tasks($this->workspace)), fn($t) => $t['claim'] !== null))];
    }
    private function settings(): array
    {
        $this->actor->userOnly();
        $policy = $this->db->query('SELECT policy_json FROM todo_workspaces WHERE id=?', [$this->workspace])->fetchColumn();
        return ['agents' => $this->db->entities($this->workspace, 'agent'), 'policy' => Support::decode($policy), 'policy_hash' => hash('sha256', $policy), 'views' => $this->db->entities($this->workspace, 'view')];
    }
    /** Invoke from cron; never from an agent-controlled tool. */
    public function maintenance(): array
    {
        $this->actor->userOnly();
        $counts = $this->db->transaction($this->workspace, function (): array {
            $counts = ['expired_claims' => 0, 'recurrences' => 0];
            foreach ($this->db->tasks($this->workspace) as $task) {
                if ($task['claim'] !== null && $task['claim']['expires_at'] <= $this->now()) {
                    $task['claim'] = null;
                    if ($task['status'] === 'agent_working') $task['status'] = 'ready';
                    $task['agent_error'] = true; $task['version']++; $task['updated_at'] = $this->now();
                    $this->db->saveTask($this->workspace, $task);
                    $this->audit('claim_expired', $task['id'], ['version' => $task['version']]);
                    $counts['expired_claims']++;
                }
                if ($task['archived_at'] !== null || $task['status'] === 'cancelled' || $task['recurrence'] === null || $task['recurrence_source'] !== null || $task['due_at'] === null) continue;
                $anchor = (new \DateTimeImmutable('@' . $task['due_at']))->setTimezone(new \DateTimeZone($task['timezone']));
                $next = $anchor;
                // Cap catch-up work per run without skipping intervals.
                $lastStep = (int)($task['recurrence_step'] ?? 0);
                $firstStep = $lastStep + 1;
                for ($step = $firstStep; $step < $firstStep + 3660; $step++) {
                    if ($task['recurrence'] === 'monthly') {
                        $month = $anchor->modify('first day of this month')->modify('+' . $step . ' months');
                        $next = $month->setDate((int)$month->format('Y'), (int)$month->format('m'), min((int)$anchor->format('d'), (int)$month->format('t')));
                    } else $next = $anchor->modify('+' . $step . ($task['recurrence'] === 'daily' ? ' days' : ' weeks'));
                    if ($next->getTimestamp() > $this->now()) break;
                    $lastStep = $step;
                    $id = substr(hash('sha256', $task['id'] . ':' . $next->format('Y-m-d')), 0, 32);
                    if ($this->db->query('SELECT id FROM todo_entities WHERE workspace_id=? AND kind=? AND id=?', [$this->workspace, 'recurrence', $id])->fetchColumn() !== false) continue;
                    $copy = $task;
                    $copy['id'] = $id; $copy['version'] = 1; $copy['status'] = 'ready'; $copy['claim'] = null;
                    $copy['recurrence_source'] = $task['id']; $copy['recurrence'] = null; $copy['parent_id'] = null;
                    $copy['due_at'] = $next->getTimestamp();
                    $copy['start_at'] = $task['start_at'] === null ? null : $copy['due_at'] - ($task['due_at'] - $task['start_at']);
                    $copy['created_at'] = $copy['updated_at'] = $this->now();
                    $this->db->saveTask($this->workspace, $copy, true);
                    $this->db->saveEntity($this->workspace, 'recurrence', ['id' => $id, 'version' => 1, 'source' => $task['id'], 'due_at' => $copy['due_at']]);
                    $this->audit('recurrence_created', $id, ['source' => $task['id'], 'due_at' => $copy['due_at']]);
                    $counts['recurrences']++;
                }
                if ($lastStep !== (int)($task['recurrence_step'] ?? 0)) {
                    $task['recurrence_step'] = $lastStep; $task['version']++;
                    $this->db->saveTask($this->workspace, $task);
                    $this->audit('recurrence_schedule_advanced', $task['id'], ['step' => $lastStep, 'version' => $task['version']]);
                }
            }
            return $counts;
        });
        $counts['pending_file_deletions'] = $this->cleanupFiles();
        return $counts;
    }
}
