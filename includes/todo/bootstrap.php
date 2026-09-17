<?php
declare(strict_types=1);

require_once __DIR__ . '/autoload.php';
if (PHP_VERSION_ID < 80400) throw new RuntimeException('PHP 8.4 or newer required.');
foreach (['pdo_mysql','mbstring','openssl','fileinfo','gd','zip','xmlreader'] as $extension) if (!extension_loaded($extension)) throw new RuntimeException('Required extension: ' . $extension);
$databaseInclude = Todo\Config::env('TODO_DATABASE_INCLUDE', dirname(__DIR__) . '/api/sql.php');
if (!is_file($databaseInclude)) throw new RuntimeException('External sql.php not found.');
require_once $databaseInclude;
if (!function_exists('connectToSQL')) throw new RuntimeException('sql.php must define connectToSQL(): PDO.');
$todoPdo = connectToSQL();
if (!$todoPdo instanceof PDO) throw new RuntimeException('connectToSQL() did not return PDO.');
$todoDb = new Todo\Database($todoPdo);
$todoCrypto = Todo\Config::crypto();
