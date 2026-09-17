<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Support.php';
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Todo\\')) return;
    $name = substr($class, 5);
    if (!preg_match('/^[A-Za-z]+$/D', $name)) return;
    $file = __DIR__ . '/src/' . $name . '.php';
    if (is_file($file)) require_once $file;
});
