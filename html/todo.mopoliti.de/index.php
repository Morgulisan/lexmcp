<?php
declare(strict_types=1);

try {
    require dirname(__DIR__,2).'/includes/todo/bootstrap.php';
    $todoDb->migrate();
    (new Todo\Application($todoDb,$todoCrypto))->run();
} catch (Throwable $error) {
    error_log('[todo bootstrap] ' . $error::class . ': ' . $error->getMessage());
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Die Anwendung ist noch nicht eingerichtet. Bitte Konfiguration und Datenbankmigration prüfen.';
}
