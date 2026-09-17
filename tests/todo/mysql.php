<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/includes/todo/autoload.php';
use Todo\{Actor,Crypto,Database,Failure,Service,Support};
$dsn=getenv('TODO_TEST_DSN');
if(!$dsn){echo "SKIP MySQL integration: set TODO_TEST_DSN for a separate test database.\n";exit(0);}
$db=new Database(new PDO($dsn,getenv('TODO_TEST_DB_USER')?:'',getenv('TODO_TEST_DB_PASSWORD')?:''));
if($db->sqlite)throw new RuntimeException('This suite requires MySQL/MariaDB.');
$db->migrate();$owner=random_int(100000000,2000000000);$crypto=new Crypto(hash('sha256','race-test-only',true));
$user=new Service($db,$crypto,new Actor($owner),'mysql-test');$workspace=$user->workspace;
$change=fn(Service $s,string $action,array $args)=>$s->mutate($action,$args+['idempotency_key'=>Support::id()]);
try{
    $project=$change($user,'project_save',['name'=>'MySQL acceptance']);
    $task=$change($user,'task_create',['project_id'=>$project['id'],'title'=>'Atomic claim']);
    $task=$change($user,'update',['task_id'=>$task['id'],'expected_version'=>1,'status'=>'ready'])['task'];
    foreach(['a','b']as$id)$db->saveEntity($workspace,'agent',['id'=>$id,'version'=>1,'revoked_at'=>null]);
    $children=[];
    foreach(['a','b']as$id){$pipes=[];$proc=proc_open([PHP_BINARY,__DIR__.'/claim_worker.php','mysql',$id,$project['id'],$task['id'],(string)$task['version'],(string)$owner],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('Cannot start worker.');fclose($pipes[0]);$children[]=[$proc,$pipes];}
    $results=[];
    foreach($children as[$proc,$pipes]){$results[]=trim(stream_get_contents($pipes[1]));$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($proc)!==0)throw new RuntimeException('Worker failed: '.$errors);}
    if(count(array_filter($results,fn($r)=>$r==='claimed'))!==1)throw new RuntimeException('Parallel claim invariant failed.');
    $task=$user->read('task',['task_id'=>$task['id']])['task'];
    $saved=$change($user,'memory_update',['task_id'=>$task['id'],'expected_version'=>$task['version'],'expected_memory_version'=>0,'content'=>'MySQL UTF-8: äöü ✓']);
    if($user->read('memory',['task_id'=>$task['id']])['content']!=='MySQL UTF-8: äöü ✓')throw new RuntimeException('Memory encoding failed.');
    $key=Support::id();$args=['task_id'=>$task['id'],'expected_version'=>$saved['task']['version'],'content'=>'Result','idempotency_key'=>$key];
    if($user->mutate('complete',$args)!==$user->mutate('complete',$args))throw new RuntimeException('Replay invariant failed.');
    echo "PASS MySQL schema, UTF-8 memory, independent concurrent claims, transactional idempotency\n";
}finally{
    foreach(['todo_operations','todo_audit','todo_tasks','todo_entities']as$table)$db->query('DELETE FROM '.$table.' WHERE workspace_id=?',[$workspace]);
    $db->query('DELETE FROM todo_workspaces WHERE id=?',[$workspace]);
}
