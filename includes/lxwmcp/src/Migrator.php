<?php
declare(strict_types=1);

namespace LexMcp;

use PDO;
use PDOException;
use RuntimeException;

final class Migrator
{
    /** @return list<string> */
    public static function applyPending(PDO $pdo): array
    {
        $applied = [];
        try {
            $versions = $pdo->query('SELECT version FROM lxmcp_schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) !== 1146 && $e->getCode() !== '42S02') {
                throw $e;
            }
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS lxmcp_schema_migrations (' .
                'version VARCHAR(64) PRIMARY KEY, ' .
                'applied_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)' .
                ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            $versions = [];
        }

        foreach ($versions as $version) {
            if (is_string($version)) {
                $applied[$version] = true;
            }
        }

        $completed = [];
        $directory = dirname(__DIR__) . '/migrations';
        $files = glob($directory . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (isset($applied[$version])) {
                continue;
            }
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException("Cannot read migration {$file}");
            }
            $pdo->exec($sql);
            $insert = $pdo->prepare('INSERT IGNORE INTO lxmcp_schema_migrations(version) VALUES (?)');
            $insert->execute([$version]);
            $completed[] = $version;
        }

        return $completed;
    }
}
