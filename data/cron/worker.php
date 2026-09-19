<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__, 2).'/includes/todo/bootstrap.php';
foreach($todoDb->query('SELECT owner_id FROM todo_workspaces')->fetchAll(PDO::FETCH_COLUMN)as$owner){
    $service=new Todo\Service($todoDb,$todoCrypto,new Todo\Actor((int)$owner),'worker-'.Todo\Support::id());
    echo Todo\Support::json($service->maintenance()).PHP_EOL;
}
$todoDb->query('DELETE FROM todo_auth WHERE expires_at<?',[time()]);
$todoDb->query('DELETE FROM todo_limits WHERE window_start<?',[time()-86400]);
