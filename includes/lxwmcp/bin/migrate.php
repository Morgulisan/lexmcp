<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

$pdo = $lxmcpPdo;
$pdo->exec('SET NAMES utf8mb4');
foreach (LexMcp\Migrator::applyPending($pdo) as $version) {
    fwrite(STDOUT, "Applied {$version}\n");
}
