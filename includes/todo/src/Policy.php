<?php
declare(strict_types=1);

namespace Todo;

final class Policy
{
    public const ACTIONS = ['read','comment','draft','create_subtasks','claim','update','memory','plan','complete','external','destructive'];
    public static function validate(mixed $rules): array
    {
        if (!is_array($rules) || !array_is_list($rules) || count($rules) > 50) throw new Failure('invalid_policy', 'Ungültige Policy.');
        foreach ($rules as $rule) {
            if (!is_array($rule) || array_diff(array_keys($rule), ['action','effect','agent','project','risk_min','priority_max','capability'])) throw new Failure('invalid_policy', 'Unbekanntes Policy-Feld.');
            if (!in_array($rule['action'] ?? '', [...self::ACTIONS, '*'], true) || !in_array($rule['effect'] ?? '', ['allow','deny','approval'], true)) throw new Failure('invalid_policy', 'Ungültige Policy-Aktion.');
            foreach (['risk_min','priority_max'] as $key) if (isset($rule[$key])) Support::number($rule, $key, 1, 5);
            foreach (['agent','project','capability'] as $key) if (isset($rule[$key])) Support::text($rule, $key, 160);
        }
        return $rules;
    }
    /** Restrictions only accumulate: a lower-level allow never removes an upper-level requirement. */
    public static function decision(string $action, Actor $actor, array $task, array ...$levels): string
    {
        $decision = (in_array($action, ['create_subtasks','external','destructive'], true) || (($task['risk'] ?? 1) >= 4 && in_array($action, ['update','complete'], true))) ? 'approval' : 'allow';
        foreach ($levels as $rules) foreach ($rules as $rule) {
            if ($rule['action'] !== '*' && $rule['action'] !== $action) continue;
            if (isset($rule['agent']) && $rule['agent'] !== $actor->agent) continue;
            if (isset($rule['project']) && $rule['project'] !== ($task['project_id'] ?? '')) continue;
            if (isset($rule['risk_min']) && ($task['risk'] ?? 1) < $rule['risk_min']) continue;
            if (isset($rule['priority_max']) && ($task['priority'] ?? 3) > $rule['priority_max']) continue;
            if (isset($rule['capability']) && !in_array($rule['capability'], $actor->capabilities, true)) continue;
            if ($rule['effect'] === 'deny') return 'deny';
            if ($rule['effect'] === 'approval') $decision = 'approval';
        }
        return $decision;
    }
}
