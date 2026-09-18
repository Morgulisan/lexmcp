<?php
declare(strict_types=1);
date_default_timezone_set('UTC');
spl_autoload_register(static function(string $class): void {
    $prefix='MopolitiAuth\\';
    if (str_starts_with($class,$prefix)) {
        $name=substr($class,strlen($prefix));
        if (preg_match('/^[A-Za-z]+$/D',$name)) require __DIR__.'/src/'.$name.'.php';
    }
});

function mcpAuthDatabase(): PDO
{
    $file=getenv('MCP_AUTH_DATABASE_INCLUDE') ?: dirname(__DIR__).'/api/sql.php';
    require_once $file;
    $pdo=connectToSQL();
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    $pdo->exec("SET time_zone='+00:00'");
    return $pdo;
}

function mcpAuthMigrate(PDO $pdo): void
{
    try {$current=(string)$pdo->query('SELECT MAX(version) FROM mpauth_schema')->fetchColumn();}
    catch (PDOException $e) {
        if ($e->getCode()!=='42S02') throw $e;
        $pdo->exec('CREATE TABLE IF NOT EXISTS mpauth_schema (version VARCHAR(64) PRIMARY KEY) ENGINE=InnoDB');$current='';
    }
    foreach (glob(__DIR__.'/migrations/*.sql') as $file) {
        $version=basename($file);
        if (strcmp($version,$current)<=0) continue;
        $pdo->exec(file_get_contents($file));
        $pdo->prepare('INSERT IGNORE INTO mpauth_schema(version) VALUES (?)')->execute([$version]);
    }
}
