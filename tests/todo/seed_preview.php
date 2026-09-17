<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('TODO_TEST_PREVIEW')!=='1')exit('Local preview flag required.');
require dirname(__DIR__,2).'/includes/todo/autoload.php';
use Todo\{Actor,Crypto,Database,Service,Support};
$db=new Database(new PDO('sqlite:'.sys_get_temp_dir().'/todo-ui-preview.sqlite'));$db->migrate();
$crypto=new Crypto(hash('sha256','isolated-local-preview-only',true));$user=new Service($db,$crypto,new Actor(424242),'preview-seed');
$write=fn(Service $s,string $action,array $args)=>$s->mutate($action,$args+['idempotency_key'=>Support::id()]);
$projects=$user->read('projects');$project=$projects[0]??$write($user,'project_save',['name'=>'Website-Relaunch']);
$db->saveEntity($user->workspace,'agent',['id'=>'preview-agent','version'=>1,'name'=>'Recherche-Agent','client_id'=>'local-fixture','projects'=>[$project['id']],'scopes'=>['todo:read','todo:write','todo:comment'],'revoked_at'=>null,'last_activity'=>time()]);
$agent=new Service($db,$crypto,new Actor(424242,'preview-agent',[$project['id']],['todo:read','todo:write','todo:comment'],[],'local-fixture','preview-run'),'preview-seed');
foreach([
    ['title'=>'Neue Startseite: Plan freigeben','risk'=>4,'action'=>'plan_submit','content'=>'1. Bestehende Inhalte prüfen.\n2. Entwurf lokal erstellen.\n3. Ergebnis zum Review vorlegen.'],
    ['title'=>'Zielgruppe für den Relaunch klären','risk'=>1,'action'=>'question','content'=>'Soll die Startseite zuerst Bestandskunden oder neue Interessenten ansprechen?'],
    ['title'=>'Inhaltsübersicht prüfen','risk'=>1,'action'=>'review_request','content'=>'Die bestehende Navigation wurde geprüft. Drei doppelte Einstiegspunkte können zusammengeführt werden.'],
]as$fixture){
    $existing=$user->read('tasks',['query'=>$fixture['title']]);if($existing['total'])continue;
    $task=$write($user,'task_create',['project_id'=>$project['id'],'title'=>$fixture['title'],'risk'=>$fixture['risk']]);
    $task=$write($user,'update',['task_id'=>$task['id'],'expected_version'=>$task['version'],'status'=>'ready'])['task'];
    $claim=$write($agent,'claim',['task_id'=>$task['id'],'expected_version'=>$task['version']]);
    $write($agent,$fixture['action'],['task_id'=>$task['id'],'expected_version'=>$claim['task']['version'],'claim_token'=>$claim['claim_token'],'content'=>str_replace('\\n',"\n",$fixture['content'])]);
}
echo "Local UI fixtures ready.\n";
