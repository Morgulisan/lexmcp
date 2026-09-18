<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/bootstrap.php';
$pdo=mcpAuthDatabase();
mcpAuthMigrate($pdo);
echo "MCP auth schema ready.\n";
