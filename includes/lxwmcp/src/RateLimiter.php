<?php
declare(strict_types=1);

namespace LexMcp;

use DateTimeImmutable;
use PDO;

final class RateLimiter
{
    private const SLOT_MICROSECONDS = 550000;

    public function __construct(private readonly PDO $pdo) {}

    public function acquire(string $fingerprint, int $maxWaitSeconds = 60): void
    {
        $deadline = microtime(true) + $maxWaitSeconds;
        while (true) {
            $this->pdo->beginTransaction();
            try {
                $this->pdo->prepare('INSERT IGNORE INTO lxmcp_rate_limits(api_key_fingerprint,next_allowed_at) VALUES (?,NOW(6))')->execute([$fingerprint]);
                $stmt = $this->pdo->prepare('SELECT next_allowed_at,blocked_until,NOW(6) db_now FROM lxmcp_rate_limits WHERE api_key_fingerprint=? FOR UPDATE');
                $stmt->execute([$fingerprint]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row)) {
                    throw new \RuntimeException('Rate limit row missing.');
                }
                $now = self::date((string) $row['db_now']);
                $slot = self::date((string) $row['next_allowed_at']);
                if ($slot < $now) {
                    $slot = $now;
                }
                if (is_string($row['blocked_until']) && $row['blocked_until'] !== '') {
                    $blocked = self::date($row['blocked_until']);
                    if ($blocked > $slot) {
                        $slot = $blocked;
                    }
                }
                $next = $slot->modify('+' . self::SLOT_MICROSECONDS . ' microseconds');
                $update = $this->pdo->prepare('UPDATE lxmcp_rate_limits SET next_allowed_at=? WHERE api_key_fingerprint=?');
                $update->execute([$next->format('Y-m-d H:i:s.u'), $fingerprint]);
                $this->pdo->commit();
                $wait = (float) $slot->format('U.u') - (float) $now->format('U.u');
                if (microtime(true) + $wait > $deadline) {
                    throw new AppError('rate_queue_timeout', 'The global Lexware request queue timed out.', 503, true);
                }
                if ($wait > 0) {
                    usleep((int) ceil($wait * 1000000));
                }
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }

            $check = $this->pdo->prepare('SELECT blocked_until,NOW(6) db_now FROM lxmcp_rate_limits WHERE api_key_fingerprint=?');
            $check->execute([$fingerprint]);
            $row = $check->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || $row['blocked_until'] === null || self::date((string) $row['blocked_until']) <= self::date((string) $row['db_now'])) {
                return;
            }
        }
    }

    public function block(string $fingerprint, int $seconds): void
    {
        $seconds = max(1, min($seconds, 300));
        $sql = 'UPDATE lxmcp_rate_limits SET blocked_until=GREATEST(COALESCE(blocked_until,NOW(6)),DATE_ADD(NOW(6),INTERVAL ? SECOND)) WHERE api_key_fingerprint=?';
        $this->pdo->prepare($sql)->execute([$seconds, $fingerprint]);
    }

    private static function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value);
    }
}
