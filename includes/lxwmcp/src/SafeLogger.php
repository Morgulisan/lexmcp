<?php
declare(strict_types=1);

namespace LexMcp;

final class SafeLogger
{
    private const ALLOWED = ['trace_id', 'event', 'user_id', 'account_id', 'operation', 'status', 'http_status', 'duration_ms', 'error_code'];

    public static function log(string $event, array $context = []): void
    {
        $safe = ['time' => gmdate('c'), 'event' => $event];
        foreach (self::ALLOWED as $key) {
            if ($key !== 'event' && array_key_exists($key, $context) && (is_scalar($context[$key]) || $context[$key] === null)) {
                $safe[$key] = $context[$key];
            }
        }
        error_log(Util::jsonEncode($safe));
    }
}
