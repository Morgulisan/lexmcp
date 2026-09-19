<?php
declare(strict_types=1);

namespace Todo;

trait TaskActions
{
    private function changeTask(string $action, array $input): array
    {
        $task = $this->task(Support::text($input, 'task_id', 32), in_array($action, ['archive','restore','delete'], true));
        $this->version($task, $input);
        $before = $this->publicTask($task);
        $originalTask = $task;
        $extra = [];
        $userActions = ['approve_plan','review_decide','answer','delete','archive','restore','cancel','takeover'];
        if (in_array($action, $userActions, true)) $this->actor->userOnly();
        if (!in_array($action, ['claim','comment','comment_update','reopen'], true)) $this->requireClaim($task, $input);
        switch ($action) {
            case 'update':
                foreach (['priority','risk','effort'] as $field) if (!$this->actor->isUser() && isset($input[$field]) && $input[$field] !== $task[$field]) {
                    $reason = Support::text($input, 'reason', 2000);
                    $this->addComment($task, 'decision', $reason);
                    break;
                }
                $candidate = $this->fields($input, $task);
                // Check both old and proposed risk; lowering a score cannot evade approval.
                $this->enforce('update', $task);
                $this->enforce('update', $candidate);
                if (isset($input['status'])) {
                    $status = Support::text($input, 'status', 32);
                    $allowed = $this->actor->isUser() ? ['draft','ready','user_working','waiting_external'] : ['waiting_external'];
                    if (!in_array($status, $allowed, true)) throw new Failure('invalid_transition', 'Diesen Status über die passende Workflow-Aktion setzen.');
                    if ($task['claim'] !== null && $task['claim']['expires_at'] > $this->now() && $this->actor->isUser()) throw new Failure('claim_active', 'Zuerst den aktiven Claim übernehmen.', 409);
                    $candidate['status'] = $status;
                    if ($status === 'waiting_external') $candidate['claim'] = null;
                }
                if ($candidate === $originalTask) return ['task' => $before];
                $task = $candidate;
                break;
            case 'claim':
                if ($this->actor->isUser()) throw new Failure('agent_only', 'Nutzer verwenden Übernehmen.');
                $this->enforce('claim', $task);
                $project = $this->project($task['project_id']);
                if ($project['archived'] || $task['dependency_target'] !== null) throw new Failure('unavailable', 'Diese Aufgabe kann nicht geclaimt werden.', 409);
                if ($task['claim'] !== null && $task['claim']['expires_at'] > $this->now()) throw new Failure('claimed', 'Aufgabe ist bereits geclaimt.', 409);
                if (!in_array($task['status'], ['ready','agent_working'], true) || ($task['start_at'] !== null && $task['start_at'] > $this->now())) throw new Failure('not_ready', 'Aufgabe ist nicht bereit.', 409);
                if (!$this->dependenciesDone($task)) throw new Failure('dependencies_open', 'Harte Abhängigkeiten sind noch offen.', 409);
                if (array_diff($this->requiredCapabilities($task), $this->actor->capabilities)) throw new Failure('capability_missing', 'Benötigte Capabilities fehlen.', 403);
                $token = bin2hex(random_bytes(32));
                $duration = isset($input['minutes']) ? Support::number($input, 'minutes', 1, 120) : 30;
                $task['claim'] = ['agent_id' => $this->actor->agent, 'hash' => hash('sha256', $token), 'expires_at' => $this->now() + $duration * 60, 'run_id' => $this->actor->run];
                $task['status'] = 'agent_working';
                $task['agent_error'] = false;
                $extra['claim_token'] = $token;
                break;
            case 'renew':
                if ($this->actor->isUser()) throw new Failure('agent_only', 'Nur Agenten verlängern Claims.');
                $duration = isset($input['minutes']) ? Support::number($input, 'minutes', 1, 120) : 30;
                $task['claim']['expires_at'] = $this->now() + $duration * 60;
                break;
            case 'release':
                $task['claim'] = null;
                $task['status'] = 'ready';
                if (isset($input['reason'])) $this->addComment($task, 'handoff', Support::text($input, 'reason', 4000));
                break;
            case 'takeover':
                $task['claim'] = null;
                $task['status'] = 'user_working';
                break;
            case 'memory_update':
                $this->enforce('memory', $task);
                $memory = $this->db->task($this->workspace, $task['id'], true);
                if (($input['expected_memory_version'] ?? null) !== (int)$memory['memory_version']) throw new Failure('memory_conflict', 'Memory wurde geändert. Bitte neu laden.', 409);
                $content = Support::text($input, 'content', 2048, true);
                if (($input['mode'] ?? 'replace') === 'append') $content = $content === '' ? $memory['memory_md'] : $memory['memory_md'] . ($memory['memory_md'] === '' ? '' : "\n") . $content;
                elseif (($input['mode'] ?? 'replace') !== 'replace') throw new Failure('invalid_mode', 'Memory-Modus muss replace oder append sein.');
                Support::text(['content' => $content], 'content', 2048, true);
                Support::noSecrets($content);
                if ($content === $memory['memory_md']) return ['task' => $before, 'memory_version' => (int)$memory['memory_version']];
                $version = (int)$memory['memory_version'] + 1;
                $this->db->query('UPDATE todo_tasks SET memory_md=?,memory_version=? WHERE workspace_id=? AND id=?', [$content, $version, $this->workspace, $task['id']]);
                $this->db->saveEntity($this->workspace, 'memory', ['id' => Support::id(), 'version' => $version, 'task_id' => $task['id'], 'content' => $content, 'author' => $this->actor->key(), 'created_at' => $this->now()]);
                $extra['memory_version'] = $version;
                $task['memory_version'] = $version;
                break;
            case 'comment':
                $this->enforce('comment', $task);
                $type = $input['type'] ?? 'general';
                if (!in_array($type, self::COMMENTS, true) || $type === 'question') throw new Failure('invalid_type', 'Rückfragen über question stellen; Kommentar-Typ prüfen.');
                $extra['comment'] = $this->addComment($task, $type, Support::text($input, 'content', 10000));
                break;
            case 'reopen':
                if ($task['status'] !== 'done') throw new Failure('invalid_transition', 'Nur erledigte Aufgaben können wieder geöffnet werden.', 409);
                if (!$this->actor->isUser() && (!is_string($input['content'] ?? null) || trim($input['content']) === '')) throw new Failure('invalid_field', 'Agenten müssen das Wiederöffnen begründen.');
                $content = Support::text($input, 'content', 10000);
                $extra['comment'] = $this->addComment($task, 'general', $content);
                $task['status'] = 'ready';
                $task['claim'] = null;
                break;
            case 'comment_update':
                $this->enforce('comment', $task);
                $comment = $this->db->entity($this->workspace, 'comment', Support::text($input, 'comment_id', 32));
                if ($comment['task_id'] !== $task['id'] || (!$this->actor->isUser() && $comment['author'] !== $this->actor->key()) || $comment['type'] === 'question') throw new Failure('forbidden', 'Dieser Kommentar ist nicht bearbeitbar.', 403);
                $oldComment = $comment;
                $comment['content'] = Support::text($input, 'content', 10000);
                Support::noSecrets($comment['content']);
                if ($comment['content'] === $oldComment['content']) return ['task' => $before, 'comment' => $oldComment];
                $comment['version']++;
                $this->db->saveEntity($this->workspace, 'comment', $comment);
                $extra['comment'] = $comment;
                $this->audit('comment_revised', $task['id'], ['before' => $oldComment, 'after' => $comment]);
                break;
            case 'question':
            case 'handoff':
                $this->enforce('comment', $task);
                $extra['comment'] = $this->addComment($task, $action, Support::text($input, 'content', 10000));
                $task['claim'] = null;
                $task['status'] = 'waiting_user';
                $task['agent_error'] = ($input['error'] ?? false) === true;
                break;
            case 'answer':
                if ($task['status'] !== 'waiting_user') throw new Failure('invalid_transition', 'Aufgabe wartet nicht auf eine Nutzerantwort.', 409);
                $comment = $this->db->entity($this->workspace, 'comment', Support::text($input, 'comment_id', 32));
                if ($comment['task_id'] !== $task['id'] || !in_array($comment['type'], ['question','handoff'], true) || $comment['closed']) throw new Failure('invalid_question', 'Keine offene Rückfrage.');
                $comment['answer'] = Support::text($input, 'content', 10000);
                Support::noSecrets($comment['answer']);
                $comment['closed'] = true;
                $comment['version']++;
                $this->db->saveEntity($this->workspace, 'comment', $comment);
                $open = array_filter($this->db->entities($this->workspace, 'comment'), fn($c) => $c['task_id'] === $task['id'] && in_array($c['type'], ['question','handoff'], true) && !$c['closed']);
                if (!$open) { $task['status'] = 'ready'; $task['agent_error'] = false; }
                break;
            case 'plan_submit':
                $this->enforce('plan', $task);
                $plans = array_filter($this->db->entities($this->workspace, 'plan'), fn($p) => $p['task_id'] === $task['id']);
                $sequence = $plans ? max(array_column($plans, 'sequence')) + 1 : 1;
                $content = Support::text($input, 'content', 20000);
                Support::noSecrets($content);
                $actions = Support::strings($input['actions'] ?? ['update','complete']);
                if (array_diff($actions, Policy::ACTIONS)) throw new Failure('invalid_actions', 'Unbekannte Planaktionen.');
                $planRisk = isset($input['risk']) ? max($task['risk'], Support::number($input, 'risk', 1, 5)) : $task['risk'];
                $plan = ['id' => Support::id(), 'version' => 1, 'sequence' => $sequence, 'task_id' => $task['id'], 'content' => $content, 'actions' => $actions, 'risk' => $planRisk, 'author' => $this->actor->key(), 'decision' => 'pending', 'created_at' => $this->now()];
                $this->db->saveEntity($this->workspace, 'plan', $plan);
                $task['status'] = 'waiting_user';
                $task['claim'] = null;
                $extra['plan'] = $plan;
                break;
            case 'approve_plan':
                if ($task['status'] !== 'waiting_user') throw new Failure('invalid_transition', 'Aufgabe wartet nicht auf eine Planentscheidung.', 409);
                $plan = $this->db->entity($this->workspace, 'plan', Support::text($input, 'plan_id', 32));
                $plans = array_filter($this->db->entities($this->workspace, 'plan'), fn($p) => $p['task_id'] === $task['id']);
                if ($plan['task_id'] !== $task['id'] || $plan['sequence'] !== max(array_column($plans, 'sequence')) || $plan['decision'] !== 'pending') throw new Failure('stale_plan', 'Nur die aktuelle offene Planversion ist freigabefähig.', 409);
                $approved = ($input['approve'] ?? false) === true;
                $plan['decision'] = $approved ? 'approved' : 'changes_requested';
                $plan['decided_by'] = $this->actor->user;
                $plan['decided_at'] = $this->now();
                $plan['feedback'] = Support::text($input + ['feedback' => ''], 'feedback', 10000, $approved);
                Support::noSecrets($plan['feedback']);
                $plan['version']++;
                $this->db->saveEntity($this->workspace, 'plan', $plan);
                $task['status'] = 'ready';
                break;
            case 'complete':
            case 'review_request':
                $this->enforce($action === 'complete' ? 'complete' : 'comment', $task);
                $this->assertCompletion($task);
                $content = Support::text($input + ['content' => ''], 'content', 10000, $action === 'complete' && $this->actor->isUser());
                if (trim($content) !== '') $this->addComment($task, 'result', $content);
                $task['status'] = $action === 'complete' ? 'done' : 'review';
                $task['claim'] = null;
                if ($action === 'review_request') {
                    foreach ($this->db->entities($this->workspace, 'review') as $previous) if ($previous['task_id'] === $task['id'] && $previous['decision'] === 'pending') {
                        $previous['decision'] = 'superseded'; $previous['version']++;
                        $this->db->saveEntity($this->workspace, 'review', $previous);
                    }
                    $this->db->saveEntity($this->workspace, 'review', ['id' => Support::id(), 'version' => 1, 'task_id' => $task['id'], 'decision' => 'pending', 'author' => $this->actor->key(), 'created_at' => $this->now()]);
                }
                break;
            case 'review_decide':
                if ($task['status'] !== 'review') throw new Failure('invalid_review', 'Aufgabe wartet nicht auf Review.', 409);
                $review = $this->db->entity($this->workspace, 'review', Support::text($input, 'review_id', 32));
                if ($review['task_id'] !== $task['id'] || $review['decision'] !== 'pending') throw new Failure('invalid_review', 'Review ist nicht mehr offen.', 409);
                $review['decision'] = ($input['approve'] ?? false) === true ? 'approved' : 'changes_requested';
                if ($review['decision'] === 'approved') $this->assertCompletion($task);
                $review['feedback'] = Support::text($input + ['content' => ''], 'content', 10000, true);
                Support::noSecrets($review['feedback']);
                $review['decided_by'] = $this->actor->user;
                $review['decided_at'] = $this->now();
                $this->addComment($task, 'decision', ($review['decision'] === 'approved' ? 'Review angenommen.' : 'Überarbeitung angefordert.') . ($review['feedback'] === '' ? '' : "\n" . $review['feedback']));
                $review['version']++;
                $this->db->saveEntity($this->workspace, 'review', $review);
                $task['status'] = $review['decision'] === 'approved' ? 'done' : 'ready';
                break;
            case 'dependency_add':
            case 'dependency_remove':
                $this->enforce('update', $task);
                $target = $this->task(Support::text($input, 'dependency_id', 32));
                if ($target['project_id'] !== $task['project_id']) throw new Failure('invalid_dependency', 'Abhängigkeiten müssen im selben Projekt liegen.');
                if ($action === 'dependency_add') {
                    $this->assertAcyclic($task['id'], $target['id']);
                    if (!in_array($target['id'], $task['dependencies'], true)) {
                        $task['dependencies'][] = $target['id'];
                        $child = $this->createTask(['project_id' => $task['project_id'], 'title' => 'Erledigen von Aufgabe ' . $target['title']]);
                        $child['parent_id'] = $task['id'];
                        $child['dependency_target'] = $target['id'];
                        $child['dependencies'] = [$target['id']];
                        $child['status'] = $target['status'] === 'done' ? 'done' : 'waiting_external';
                        $this->db->saveTask($this->workspace, $child);
                    }
                } else {
                    $task['dependencies'] = array_values(array_diff($task['dependencies'], [$target['id']]));
                    foreach ($this->db->tasks($this->workspace) as $child) if ($child['parent_id'] === $task['id'] && $child['dependency_target'] === $target['id']) {
                        $this->deleteTask($child, $input['idempotency_key']);
                    }
                }
                break;
            case 'artifact_add':
                $this->enforce('update', $task);
                $artifact = ['id' => Support::id(), 'version' => 1, 'task_id' => $task['id'], 'title' => Support::text($input, 'title', 200), 'url' => Support::url(Support::text($input, 'url', 2048)), 'author' => $this->actor->key(), 'created_at' => $this->now()];
                Support::noSecrets(Support::json($artifact));
                $this->db->saveEntity($this->workspace, 'artifact', $artifact);
                $extra['artifact'] = $artifact;
                break;
            case 'file_add':
                $this->actor->userOnly();
                $artifact = ['id' => substr(Support::text($input, 'storage_id', 64),0,32), 'version' => 1, 'task_id' => $task['id'], 'title' => Support::text($input, 'title', 200), 'storage_id' => $input['storage_id'], 'sha256' => Support::text($input, 'sha256', 64), 'size' => Support::number($input, 'size', 1, 10485760), 'mime' => Support::text($input, 'mime', 160), 'author' => $this->actor->key(), 'created_at' => $this->now()];
                Support::noSecrets($artifact['title']);
                $this->db->saveEntity($this->workspace, 'artifact', $artifact);
                $extra['artifact'] = $artifact;
                break;
            case 'delete':
                return $this->deleteTask($task, $input['idempotency_key']);
            case 'archive':
                $task['archived_at'] = $this->now(); $task['claim'] = null;
                if ($task['status'] === 'agent_working') $task['status'] = 'ready';
                break;
            case 'restore':
                if ($task['archived_at'] === null) throw new Failure('not_archived', 'Aufgabe ist nicht archiviert.', 409);
                $task['archived_at'] = null; break;
            case 'cancel': $task['status'] = 'cancelled'; $task['claim'] = null; break;
            default: throw new Failure('unknown_action', 'Unbekannte Schreibaktion.');
        }
        $task['version']++;
        $task['updated_at'] = $this->now();
        $this->db->saveTask($this->workspace, $task);
        if ($task['status'] === 'done') $this->syncDependencyChildren($task['id']);
        $after = $this->publicTask($task);
        // Memory and bearer secrets never enter audit payloads.
        $this->audit($action, $task['id'], ['before' => $before, 'after' => $after]);
        return ['task' => $after] + $extra;
    }
    private function addComment(array $task, string $type, string $content): array
    {
        Support::noSecrets($content);
        $comment = ['id' => Support::id(), 'version' => 1, 'task_id' => $task['id'], 'type' => $type, 'content' => $content, 'author' => $this->actor->key(), 'created_at' => $this->now(), 'closed' => false, 'answer' => null];
        $this->db->saveEntity($this->workspace, 'comment', $comment);
        return $comment;
    }
    private function assertCompletion(array $task): void
    {
        if (!$this->dependenciesDone($task)) throw new Failure('dependencies_open', 'Abhängigkeiten sind nicht erledigt.', 409);
        foreach ($this->db->tasks($this->workspace) as $child) if ($child['parent_id'] === $task['id'] && $child['archived_at'] === null && !in_array($child['status'], ['done','cancelled'], true)) throw new Failure('subtasks_open', 'Unteraufgaben sind noch offen.', 409);
    }
    private function assertAcyclic(string $source, string $target): void
    {
        $pending = [$target]; $seen = [];
        while ($pending) {
            $id = array_pop($pending);
            if ($id === $source) throw new Failure('dependency_cycle', 'Diese Abhängigkeit erzeugt einen Kreis.', 409);
            if (isset($seen[$id])) continue;
            $seen[$id] = true;
            $task = $this->db->task($this->workspace, $id);
            $pending = array_merge($pending, $task['dependencies']);
            // A task cannot depend on its own descendant, whose parent completion it blocks.
            foreach ($this->db->tasks($this->workspace) as $child) if ($child['parent_id'] === $id) $pending[] = $child['id'];
        }
        $ancestor = $this->db->task($this->workspace, $target);
        while ($ancestor['parent_id'] !== null) {
            if ($ancestor['parent_id'] === $source) throw new Failure('dependency_cycle', 'Keine Abhängigkeit auf eigene Unteraufgaben.', 409);
            $ancestor = $this->db->task($this->workspace, $ancestor['parent_id']);
        }
    }
    private function syncDependencyChildren(string $target): void
    {
        foreach ($this->db->tasks($this->workspace) as $child) if ($child['dependency_target'] === $target && $child['archived_at'] === null && $child['status'] !== 'done') {
            $child['status'] = 'done'; $child['version']++; $child['updated_at'] = $this->now();
            $this->db->saveTask($this->workspace, $child);
            $this->audit('dependency_completed', $child['id'], ['target' => $target]);
        }
    }
}
