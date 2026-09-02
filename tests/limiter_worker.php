<?php
declare(strict_types=1);

putenv('LEXMCP_ENV=test');
$src = dirname(__DIR__) . '/includes/lxwmcp/src/';
foreach (['AppError.php','Util.php','Config.php','RateLimiter.php'] as $file) require_once $src . $file;

$pdo = new PDO($argv[1], $argv[2], $argv[3], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$limiter = new LexMcp\RateLimiter($pdo);
$limiter->acquire(str_repeat('b', 64));
file_put_contents($argv[4], sprintf('%.6f', microtime(true)) . PHP_EOL, FILE_APPEND | LOCK_EX);
