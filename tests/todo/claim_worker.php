<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/includes/todo/autoload.php';
use Todo\{Actor,Crypto,Database,Failure,Service,Support};
[$script,$path,$agent,$project,$task,$version]=$argv;
$db=new Database($path==='mysql'?new PDO(getenv('TODO_TEST_DSN'),getenv('TODO_TEST_DB_USER')?:'',getenv('TODO_TEST_DB_PASSWORD')?:''):new PDO('sqlite:'.$path));
$service=new Service($db,new Crypto(hash('sha256','race-test-only',true)),new Actor(isset($argv[7])?(int)$argv[7]:42,$agent,[$project],['todo:read','todo:write']),'race-'.$agent,fn()=>1800000000);
try{$service->mutate('claim',['task_id'=>$task,'expected_version'=>(int)$version,'idempotency_key'=>Support::id()]);echo 'claimed';}
catch(Failure $e){echo $e->reason==='claimed'?'claimed_by_other':$e->reason;}
