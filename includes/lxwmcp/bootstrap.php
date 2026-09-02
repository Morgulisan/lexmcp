<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80400) {
    throw new RuntimeException('LexMCP requires PHP 8.4 or newer.');
}

foreach (['pdo_mysql', 'curl', 'json', 'sodium', 'mbstring'] as $extension) {
    if (!extension_loaded($extension)) {
        throw new RuntimeException("Missing required PHP extension: {$extension}");
    }
}

$source = __DIR__ . '/src/';
foreach ([
    'AppError.php', 'Util.php', 'Config.php', 'SafeLogger.php', 'Migrator.php', 'Crypto.php',
    'AccountStore.php', 'OAuth.php', 'AliasResolver.php', 'Validator.php',
    'RateLimiter.php', 'LexwareClient.php', 'IdempotencyStore.php',
    'ContentRegistry.php', 'ToolRouter.php', 'McpServer.php', 'Application.php',
] as $file) {
    require_once $source . $file;
}

$databaseInclude = LexMcp\Config::databaseInclude();
if (!is_file($databaseInclude)) {
    throw new RuntimeException('Configured database include does not exist.');
}
require_once $databaseInclude;

if (!function_exists('connectToSQL')) {
    throw new RuntimeException('Database include must define connectToSQL(): PDO.');
}

$lxmcpPdo = connectToSQL();
if (!$lxmcpPdo instanceof PDO) {
    throw new RuntimeException('connectToSQL() must return PDO.');
}
