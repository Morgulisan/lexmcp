<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/todo/autoload.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): never { throw new ErrorException($message, 0, $severity, $file, $line); });

use Todo\{Actor, Config, Crypto, Database, Failure, Policy, Service, Support};

$passed = 0; $failed = 0;
function check(bool $value, string $message = 'Assertion failed'): void { if (!$value) throw new RuntimeException($message); }
function rejects(string $reason, callable $action): void {
    try { $action(); } catch (Failure $e) { check($e->reason === $reason, "Expected {$reason}, got {$e->reason}: {$e->getMessage()}"); return; }
    throw new RuntimeException('Expected rejection: ' . $reason);
}
function test(string $name, callable $action): void {
    global $passed, $failed;
    try { $action(); $passed++; echo "PASS {$name}\n"; } catch (Throwable $e) { $failed++; echo "FAIL {$name}: {$e->getMessage()}\n{$e->getTraceAsString()}\n"; }
}
function fixture(string $dsn = 'sqlite::memory:', ?string $key = null): array {
    $db = new Database(new PDO($dsn)); $db->migrate();
    $crypto = new Crypto($key ?? random_bytes(32)); $now = 1800000000;
    $clock = static function () use (&$now): int { return $now; };
    $user = new Service($db, $crypto, new Actor(42), 'test-user', $clock);
    $project = change($user, 'project_save', ['name' => 'Testprojekt']);
    $agents = [];
    foreach (['a','b'] as $id) {
        $db->saveEntity($user->workspace, 'agent', ['id' => $id, 'version' => 1, 'revoked_at' => null]);
        $agents[$id] = new Service($db, $crypto, new Actor(42, $id, [$project['id']], ['todo:read','todo:write','todo:comment'], [], 'client-' . $id, 'run-' . $id), 'test-' . $id, $clock);
    }
    return [$db, $user, $agents['a'], $agents['b'], $project, &$now];
}
function change(Service $s, string $action, array $input = []): array { return $s->mutate($action, $input + ['idempotency_key' => Support::id()]); }
function task(Service $user, array $project, array $fields = []): array {
    $t = change($user, 'task_create', $fields + ['project_id' => $project['id'], 'title' => 'Aufgabe']);
    return change($user, 'update', ['task_id' => $t['id'], 'expected_version' => $t['version'], 'status' => 'ready'])['task'];
}
function input(array $t, array $extra = []): array { return $extra + ['task_id' => $t['id'], 'expected_version' => $t['version']]; }

test('users can create ready tasks atomically while agents must create drafts', function () {
    [$db,$u,$a,,$p] = fixture();
    $input=['project_id'=>$p['id'],'title'=>'Direkt bereit','status'=>'ready','idempotency_key'=>Support::id()];
    $ready=$u->mutate('task_create',$input);
    check($ready['status']==='ready' && $ready['version']===1);
    check($u->mutate('task_create',$input)['id']===$ready['id']);
    check(change($u,'task_create',['project_id'=>$p['id'],'title'=>'Entwurf','status'=>'draft'])['status']==='draft');
    rejects('invalid_transition',fn()=>change($u,'task_create',['project_id'=>$p['id'],'title'=>'Ungültig','status'=>'done']));
    rejects('user_only',fn()=>change($a,'task_create',['project_id'=>$p['id'],'title'=>'Agent','status'=>'ready']));
});

test('generated capabilities are unique and idempotent with descriptions', function () {
    [$db,$u] = fixture();
    $input = ['name'=>'Drive','description'=>'Dateien lesen','idempotency_key'=>Support::id()];
    $first = $u->mutate('capability_save', $input);
    check($first === $u->mutate('capability_save', $input));
    check($first['description'] === 'Dateien lesen');
    check($first['id'] !== change($u,'capability_save',['name'=>'Drive'])['id']);
});
test('date-only tasks use project timezone and include the whole due day across DST', function () {
    [$db,$u,$a,$b,$p] = fixture();
    $t = task($u,$p,['start_at'=>'2026-03-29','due_at'=>'2026-03-29']);
    check($t['due_at'] - $t['start_at'] === 23*3600-1);
    check($t['timezone'] === 'Europe/Berlin');
    rejects('invalid_dates',fn()=>task($u,$p,['due_at'=>'2026-02-30']));
    rejects('invalid_dates',fn()=>task($u,$p,['start_at'=>'2026-03-30','due_at'=>'2026-03-29']));
    $p = change($u,'project_save',['id'=>$p['id'],'expected_version'=>$p['version'],'name'=>$p['name'],'timezone'=>'America/New_York']);
    $updated = $u->read('task',['task_id'=>$t['id']])['task'];
    check($updated['timezone'] === 'America/New_York');
    check($updated['version'] === $t['version']+1);
    check((new DateTimeImmutable('@'.$updated['due_at']))->setTimezone(new DateTimeZone($p['timezone']))->format('Y-m-d H:i:s') === '2026-03-29 23:59:59');
    $new = task($u,$p,['due_at'=>'2026-11-01','start_at'=>'2026-11-01']);
    check($new['due_at'] - $new['start_at'] === 25*3600-1);
    rejects('invalid_timezone',fn()=>change($u,'project_save',['name'=>'Invalid','timezone'=>'Invalid/Zone']));
});
test('database migration is idempotent and preserves existing data', function () {
    $db = new Database(new PDO('sqlite::memory:'));
    $db->migrate();
    $workspace = $db->workspace(42);
    $before = $db->query("SELECT name,policy_json,revision FROM todo_workspaces WHERE id=?", [$workspace])->fetch();
    $tablesBefore = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'todo_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

    $db->migrate();

    check($db->query("SELECT name,policy_json,revision FROM todo_workspaces WHERE id=?", [$workspace])->fetch() === $before, 'Repeated migration changed existing data.');
    check($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'todo_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) === $tablesBefore, 'Repeated migration changed the schema inventory.');
});

test('encryption key is generated once and reused without configuration', function () {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'todo-key-' . bin2hex(random_bytes(8));
    $oldPath = getenv('TODO_DATA_PATH');$oldKey = getenv('TODO_ENCRYPTION_KEY');
    try {
        putenv('TODO_DATA_PATH=' . $directory);putenv('TODO_ENCRYPTION_KEY');
        $cipher = Config::crypto()->encrypt(['ready' => true], 'test');
        check(Config::crypto()->decrypt($cipher, 'test') === ['ready' => true]);
        check(is_file($directory . DIRECTORY_SEPARATOR . '.encryption.key'));
    } finally {
        $oldPath === false ? putenv('TODO_DATA_PATH') : putenv('TODO_DATA_PATH=' . $oldPath);
        $oldKey === false ? putenv('TODO_ENCRYPTION_KEY') : putenv('TODO_ENCRYPTION_KEY=' . $oldKey);
        $keyFile=$directory.DIRECTORY_SEPARATOR.'.encryption.key';if(is_file($keyFile))unlink($keyFile);if(is_dir($directory))rmdir($directory);
    }
});

test('login nonces are independent and single use', function () {
    $db=new Database(new PDO('sqlite::memory:'));$db->migrate();
    $db->pdo->exec('CREATE TABLE UserAccount (ID INTEGER PRIMARY KEY,email TEXT,passwordHash TEXT)');
    $auth=new Todo\Auth($db,new Crypto(random_bytes(32)));$first=$auth->issueLoginNonce();$second=$auth->issueLoginNonce();
    check($first!==$second);
    rejects('login_failed',fn()=>$auth->login(['nonce'=>$first,'mail'=>'nobody@example.invalid','password'=>'invalid']));
    rejects('csrf',fn()=>$auth->login(['nonce'=>$first,'mail'=>'nobody@example.invalid','password'=>'invalid']));
    rejects('login_failed',fn()=>$auth->login(['nonce'=>$second,'mail'=>'nobody@example.invalid','password'=>'invalid']));
});

test('exclusive claims, stale versions and idempotent retry', function () {
    [$db,$u,$a,$b,$p] = fixture(); $t = task($u,$p);
    $args = input($t, ['idempotency_key' => 'claim-1234']);
    $claimed = $a->mutate('claim',$args);
    check($claimed === $a->mutate('claim',$args));
    rejects('version_conflict', fn() => change($b,'claim',input($t)));
    rejects('claimed', fn() => change($b,'claim',input($claimed['task'])));
    rejects('idempotency_conflict', fn() => $a->mutate('claim',$args + ['minutes'=>40]));
    check(!str_contains(Support::json($u->read('task',['task_id'=>$t['id']])), $claimed['claim_token']));
    check($a->read('operation',['idempotency_key'=>'claim-1234'])['result'] === $claimed);
});
test('memory has independent version, bounded size and no implicit disclosure', function () {
    [$db,$u,$a,$b,$p] = fixture(); $t = task($u,$p); $c=change($a,'claim',input($t)); $t=$c['task'];
    $args=input($t,['claim_token'=>$c['claim_token'],'expected_memory_version'=>0,'content'=>'Untrusted internal notes']);
    rejects('claim_required',fn()=>change($b,'memory_update',$args));
    $r=change($a,'memory_update',$args); $t=$r['task'];
    check(!str_contains(Support::json($a->read('tasks')), 'Untrusted internal notes'));
    check(!str_contains(Support::json($u->read('activity')), 'Untrusted internal notes'));
    check($a->read('memory',['task_id'=>$t['id']])['content']==='Untrusted internal notes');
    rejects('memory_conflict',fn()=>change($a,'memory_update',input($t,['claim_token'=>$c['claim_token'],'expected_memory_version'=>0,'content'=>'overwrite'])));
    rejects('invalid_field',fn()=>change($a,'memory_update',input($t,['claim_token'=>$c['claim_token'],'expected_memory_version'=>1,'content'=>str_repeat('ä',2049)])));
    rejects('secret_detected',fn()=>change($a,'memory_update',input($t,['claim_token'=>$c['claim_token'],'expected_memory_version'=>1,'content'=>'password=secret'])));
    change($u,'memory_update',input($t,['expected_memory_version'=>1,'content'=>'']));
    check(count($u->read('memory',['task_id'=>$t['id']])['history'])===2);
});
test('unchanged task, memory, project and policy writes create no versions or audit events',function(){
    [$db,$u,$a,$b,$p]=fixture();$t=task($u,$p);
    $audit=fn()=>(int)$db->query('SELECT COUNT(*) FROM todo_audit WHERE workspace_id=?',[$u->workspace])->fetchColumn();
    $before=$audit();
    $same=change($u,'update',input($t,['title'=>$t['title']]));
    check($same['task']['version']===$t['version']);check($audit()===$before);
    $memory=change($u,'memory_update',input($t,['expected_memory_version'=>0,'content'=>'']));
    check($memory['memory_version']===0 && $memory['task']['version']===$t['version']);
    check($u->read('memory',['task_id'=>$t['id']])['history']===[]);check($audit()===$before);
    $sameProject=change($u,'project_save',['id'=>$p['id'],'expected_version'=>$p['version'],'name'=>$p['name'],'description'=>$p['description'],'policy'=>$p['policy'],'timezone'=>$p['timezone'],'archived'=>$p['archived']]);
    check($sameProject['version']===$p['version']);check($audit()===$before);
    $settings=$u->read('settings');
    change($u,'policy_save',['policy'=>$settings['policy'],'expected_hash'=>$settings['policy_hash']]);
    check($audit()===$before);
    $skillInput=['project_id'=>$p['id'],'slug'=>'gleich','name'=>'Gleich','content'=>'Unverändert','capabilities'=>[],'draft'=>false];
    $skill=change($u,'skill_save',$skillInput);$afterSkill=$audit();
    $sameSkill=change($u,'skill_save',$skillInput);
    check($sameSkill['id']===$skill['id'] && $sameSkill['sequence']===1);check($audit()===$afterSkill);
});
test('higher priority numbers sort first',function(){
    [$db,$u,$a,$b,$p]=fixture();task($u,$p,['title'=>'Niedrig','priority'=>1]);task($u,$p,['title'=>'Hoch','priority'=>5]);
    $items=$u->read('tasks')['items'];check($items[0]['title']==='Hoch');
});
test('questions release claims and answers return task to ready',function(){
    [$db,$u,$a,$b,$p]=fixture();$t=task($u,$p);$c=change($a,'claim',input($t));
    $q=change($a,'question',input($c['task'],['claim_token'=>$c['claim_token'],'content'=>'Welches Ergebnis?']));
    check($q['task']['claim']===null && $q['task']['status']==='waiting_user');
    check($u->read('tasks',['attention'=>true])['total']===1);
    $r=change($u,'answer',input($q['task'],['comment_id'=>$q['comment']['id'],'content'=>'Das erste.']));
    check($r['task']['status']==='ready');
});
test('plan approval is user-only and superseded versions do not authorize work',function(){
    [$db,$u,$a,$b,$p]=fixture();$t=task($u,$p,['risk'=>4]);$c=change($a,'claim',input($t));
    rejects('approval_required',fn()=>change($a,'update',input($c['task'],['claim_token'=>$c['claim_token'],'description'=>'Start'])));
    $plan=change($a,'plan_submit',input($c['task'],['claim_token'=>$c['claim_token'],'content'=>'Prüfen und umsetzen.']));
    rejects('user_only',fn()=>change($a,'approve_plan',input($plan['task'],['plan_id'=>$plan['plan']['id'],'approve'=>true])));
    $r=change($u,'approve_plan',input($plan['task'],['plan_id'=>$plan['plan']['id'],'approve'=>true]));
    $c=change($a,'claim',input($r['task']));
    $r=change($a,'update',input($c['task'],['claim_token'=>$c['claim_token'],'description'=>'Freigegeben']));
    $next=change($a,'plan_submit',input($r['task'],['claim_token'=>$c['claim_token'],'content'=>'Neuer Ansatz.']));
    $ready=change($u,'update',input($next['task'],['status'=>'ready']));
    $c=change($a,'claim',input($ready['task']));
    rejects('approval_required',fn()=>change($a,'complete',input($c['task'],['claim_token'=>$c['claim_token'],'content'=>'Erledigt'])));
});
test('dependency cycles rejected, real child created and claims blocked until done',function(){
    [$db,$u,$a,$b,$p]=fixture();$one=task($u,$p);$two=task($u,$p);
    $r=change($u,'dependency_add',input($one,['dependency_id'=>$two['id']]));
    check(count($u->read('task',['task_id'=>$one['id']])['subtasks'])===1);
    rejects('dependency_cycle',fn()=>change($u,'dependency_add',input($two,['dependency_id'=>$one['id']])));
    rejects('dependencies_open',fn()=>change($a,'claim',input($r['task'])));
    change($u,'complete',input($two,['content'=>'Fertig']));
    check($u->read('task',['task_id'=>$one['id']])['subtasks'][0]['status']==='done');
    change($a,'claim',input($r['task']));
});
test('capability filtering includes pinned skill requirements',function(){
    [$db,$u,$a,$b,$p]=fixture();change($u,'capability_save',['id'=>'drive','name'=>'Drive']);
    $skill=change($u,'skill_save',['project_id'=>$p['id'],'slug'=>'report','name'=>'Report','content'=>'Dokument lesen.','capabilities'=>['drive']]);
    $t=task($u,$p,['skill_ids'=>[$skill['id']]]);
    check($a->read('tasks',['compatible'=>true])['total']===0);
    rejects('capability_missing',fn()=>change($a,'claim',input($t)));
});
test('revocation invalidates existing actor and releases leases',function(){
    [$db,$u,$a,$b,$p]=fixture();$t=task($u,$p);change($a,'claim',input($t));
    change($u,'agent_revoke',['agent_id'=>'a','expected_version'=>1]);
    rejects('revoked',fn()=>$a->read('tasks'));
    $t=$u->read('task',['task_id'=>$t['id']])['task'];check($t['claim']===null);change($b,'claim',input($t));
});
test('agent names and permissions are editable and reduced rights release leases',function(){
    [$db,$u,$a,$b,$p]=fixture();
    $db->saveEntity($u->workspace,'agent',['id'=>'a','version'=>1,'name'=>'Alter Name','client_id'=>'client-a','projects'=>[$p['id']],'scopes'=>['todo:read','todo:write','todo:comment'],'revoked_at'=>null,'created_at'=>1700000000,'last_activity'=>null]);
    $t=task($u,$p);change($a,'claim',input($t));
    $updated=change($u,'agent_update',['agent_id'=>'a','expected_version'=>1,'name'=>'Recherche','projects'=>[$p['id']],'scopes'=>['todo:read','todo:comment']]);
    check($updated['name']==='Recherche' && $updated['version']===2);
    $same=change($u,'agent_update',['agent_id'=>'a','expected_version'=>2,'name'=>'Recherche','projects'=>[$p['id']],'scopes'=>['todo:comment','todo:read']]);
    check($same['version']===2);
    check($u->read('task',['task_id'=>$t['id']])['task']['claim']===null);
    $limited=new Service($db,new Crypto(random_bytes(32)),new Actor(42,'a',[$p['id']],$updated['scopes'],[],'client-a','run-a'),'limited');
    rejects('insufficient_scope',fn()=>change($limited,'task_create',['project_id'=>$p['id'],'title'=>'Nicht erlaubt']));
    rejects('invalid_scope',fn()=>change($u,'agent_update',['agent_id'=>'a','expected_version'=>2,'name'=>'Recherche','projects'=>[$p['id']],'scopes'=>['todo:admin']]));
    rejects('project_required',fn()=>change($u,'agent_update',['agent_id'=>'a','expected_version'=>2,'name'=>'Recherche','projects'=>[],'scopes'=>['todo:read']]));
});
test('tenant and project isolation',function(){
    [$db,$u,$a,$b,$p]=fixture();$other=change($u,'project_save',['name'=>'Privat']);$t=task($u,$other);
    rejects('forbidden',fn()=>$a->read('task',['task_id'=>$t['id']]));check($a->read('tasks')['total']===0);
    $foreign=new Service($db,new Crypto(random_bytes(32)),new Actor(77),'foreign');
    rejects('not_found',fn()=>$foreign->read('task',['task_id'=>$t['id']]));
});
test('policy deny wins and lower allow cannot remove approval',function(){
    $a=new Actor(1,'agent');$t=['risk'=>5,'priority'=>1];
    check(Policy::decision('update',$a,$t,[['action'=>'update','effect'=>'allow']])==='approval');
    check(Policy::decision('comment',$a,$t,[['action'=>'*','effect'=>'deny']],[['action'=>'comment','effect'=>'allow']])==='deny');
});
test('monthly recurrence clamps month-end and repeated workers are idempotent',function(){
    [$db,$u,$a,$b,$p]=fixture();
    $due=(new DateTimeImmutable('2026-01-31 10:00:00',new DateTimeZone('Europe/Berlin')))->getTimestamp();
    task($u,$p,['due_at'=>$due,'recurrence'=>'monthly']);
    $first=$u->maintenance();check($first['recurrences']>0);check($u->maintenance()['recurrences']===0);
    $tasks=$u->read('tasks',['limit'=>200])['items'];
    check(count(array_filter($tasks,fn($t)=>date('Y-m-d',$t['due_at'])==='2026-02-28'))===1);
});

test('expired leases cannot renew and worker exposes agent failure',function(){
    $f=fixture();[$db,$u,$a,$b,$p]=$f;$t=task($u,$p);$c=change($a,'claim',input($t,['minutes'=>1]));
    $f[5]+=61;
    rejects('claim_required',fn()=>change($a,'renew',input($c['task'],['claim_token'=>$c['claim_token']])));
    check($u->maintenance()['expired_claims']===1);
    $t=$u->read('task',['task_id'=>$t['id']])['task'];check($t['status']==='ready'&&$t['agent_error']);
    change($b,'claim',input($t));
});
test('OAuth PKCE, audience binding, rotation and replay revocation',function(){
    [$db,$u,$a,$b,$p]=fixture();$crypto=new Crypto(random_bytes(32));$auth=new Todo\Auth($db,$crypto);
    $client=$auth->register(['client_name'=>'Test client','redirect_uris'=>['http://127.0.0.1/callback']]);
    $verifier=str_repeat('a',43);$challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
    $params=['client_id'=>$client['client_id'],'redirect_uri'=>'http://127.0.0.1:4567/callback','response_type'=>'code','code_challenge_method'=>'S256','code_challenge'=>$challenge,'scope'=>'todo:read todo:write todo:comment','state'=>'state-123'];
    rejects('invalid_redirect_uri',fn()=>$auth->authorization(array_replace($params,['redirect_uri'=>'https://evil.example/callback'])));
    $request=$auth->authorization($params);$location=$auth->consent(42,$request['id'],[$p['id']],true);parse_str(parse_url($location,PHP_URL_QUERY),$response);
    check($response['state']==='state-123');
    $exchange=['grant_type'=>'authorization_code','code'=>$response['code'],'client_id'=>$client['client_id'],'redirect_uri'=>$params['redirect_uri'],'code_verifier'=>$verifier];
    rejects('invalid_grant',fn()=>$auth->token(array_replace($exchange,['code_verifier'=>str_repeat('b',43)])));
    rejects('invalid_grant',fn()=>$auth->token($exchange+['resource'=>'https://evil.example/mcp']));
    $tokens=$auth->token($exchange);$actor=$auth->bearer('Bearer '.$tokens['access_token']);check($actor->projects===[$p['id']]);
    $service=new Service($db,$crypto,$actor,'test-oauth');$t=task($u,$p);$c=change($service,'claim',input($t));
    $refresh=['grant_type'=>'refresh_token','refresh_token'=>$tokens['refresh_token'],'client_id'=>$client['client_id']];
    $rotated=$auth->token($refresh);check($rotated['refresh_token']!==$tokens['refresh_token']);
    rejects('invalid_grant',fn()=>$auth->token($refresh));
    rejects('invalid_token',fn()=>$auth->bearer('Bearer '.$rotated['access_token']));
    check($u->read('task',['task_id'=>$t['id']])['task']['claim']===null);
    $raw=Support::json($db->query('SELECT * FROM todo_auth')->fetchAll());check(!str_contains($raw,$tokens['access_token'])&&!str_contains($raw,$tokens['refresh_token']));
});
test('rate limits enforce boundary and CSRF is constant-time compared',function(){
    [$db]=fixture();$auth=new Todo\Auth($db,new Crypto(random_bytes(32)));
    $auth->limit('unit-test',2);$auth->limit('unit-test',2);rejects('rate_limited',fn()=>$auth->limit('unit-test',2));
    rejects('csrf',fn()=>$auth->csrf(['csrf'=>'expected'],'wrong'));
});
test('MCP catalog is compact and hidden aliases remain callable',function(){
    [$db,$u,$a,$b,$p]=fixture();$crypto=new Crypto(random_bytes(32));$auth=new Todo\Auth($db,$crypto);
    $agent=['id'=>'mcp-agent','version'=>1,'client_id'=>'test-client','projects'=>[$p['id']],'scopes'=>['todo:read','todo:write'],'revoked_at'=>null];$db->saveEntity($u->workspace,'agent',$agent);
    $token=bin2hex(random_bytes(32));$auth->put('access',hash('sha256',$token),['user'=>42,'agent_id'=>$agent['id'],'client_id'=>'test-client'],600);
    $mcp=new Todo\Mcp($db,$crypto,$auth,'mcp-test');check(count($mcp->tools())===5);
    $t=task($u,$p);
    $response=$mcp->handle(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>'read_task_memory','arguments'=>['task_id'=>$t['id']]]],'Bearer '.$token);
    check($response['result']['isError']===false);
    $denied=$mcp->handle(['jsonrpc'=>'2.0','id'=>2,'method'=>'tools/call','params'=>['name'=>'todo_task','arguments'=>['action'=>'approve_plan']]],'Bearer '.$token);
    check($denied['result']['isError']===true);
});

test('independent processes cannot claim the same task concurrently',function(){
    $path=tempnam(sys_get_temp_dir(),'todo-race-');
    try{
        [$db,$u,$a,$b,$p]=fixture('sqlite:'.$path,hash('sha256','race-test-only',true));$t=task($u,$p);$processes=[];
        foreach(['a','b']as$agent){$pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/claim_worker.php',$path,$agent,$p['id'],$t['id'],(string)$t['version']],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);check(is_resource($process));fclose($pipes[0]);$processes[]=[$process,$pipes];}
        $results=[];foreach($processes as[$process,$pipes]){$results[]=trim(stream_get_contents($pipes[1]));$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($process)===0,$errors);}
        check(count(array_filter($results,fn($r)=>$r==='claimed'))===1,Support::json($results));
        check(count(array_filter($results,fn($r)=>in_array($r,['version_conflict','claimed_by_other'],true)))===1,Support::json($results));
    }finally{unset($u,$a,$b,$db);if(is_file($path))unlink($path);}
});
test('purged repeated tasks do not reappear on a repeated worker run',function(){
    $f=fixture();[$db,$u,$a,$b,$p]=$f;$t=task($u,$p,['due_at'=>$f[5]-86400,'recurrence'=>'daily']);$u->maintenance();
    $generated=array_values(array_filter($u->read('tasks')['items'],fn($x)=>$x['recurrence_source']===$t['id']))[0];
    change($u,'delete',input($generated));$f[5]+=31*86400;$u->maintenance();
    rejects('not_found',fn()=>$u->read('task',['task_id'=>$generated['id']]));$u->maintenance();
    rejects('not_found',fn()=>$u->read('task',['task_id'=>$generated['id']]));
});

test('project draft prohibition also protects skill authoring',function(){
    [$db,$u,$a,$b,$p]=fixture();change($u,'project_save',['id'=>$p['id'],'expected_version'=>$p['version'],'name'=>$p['name'],'policy'=>[['action'=>'draft','effect'=>'deny']]]);
    rejects('policy_denied',fn()=>change($a,'skill_save',['project_id'=>$p['id'],'slug'=>'draft','name'=>'Draft','content'=>'Instructions']));
});
test('risk increase requires a reason and an approved sufficient risk ceiling',function(){
    [$db,$u,$a,$b,$p]=fixture();$t=task($u,$p,['risk'=>4]);$c=change($a,'claim',input($t));
    $plan=change($a,'plan_submit',input($c['task'],['claim_token'=>$c['claim_token'],'content'=>'Risiko neu bewerten.','risk'=>5]));
    $approved=change($u,'approve_plan',input($plan['task'],['plan_id'=>$plan['plan']['id'],'approve'=>true]));$c=change($a,'claim',input($approved['task']));
    rejects('invalid_field',fn()=>change($a,'update',input($c['task'],['claim_token'=>$c['claim_token'],'risk'=>5])));
    $result=change($a,'update',input($c['task'],['claim_token'=>$c['claim_token'],'risk'=>5,'reason'=>'Zusätzliche externe Auswirkung festgestellt.']));check($result['task']['risk']===5);
});

test('review approval rechecks newly added dependencies',function(){
    [$db,$u,$a,$b,$p]=fixture();$one=task($u,$p);$two=task($u,$p);
    $reviewed=change($u,'review_request',input($one,['content'=>'Bitte prüfen.']));
    $review=$u->read('task',['task_id'=>$one['id']])['reviews'][0];
    $withDependency=change($u,'dependency_add',input($reviewed['task'],['dependency_id'=>$two['id']]));
    rejects('dependencies_open',fn()=>change($u,'review_decide',input($withDependency['task'],['review_id'=>$review['id'],'approve'=>true])));
});

require __DIR__ . '/files.php';
echo "\n{$passed} passed, {$failed} failed\n";
exit($failed ? 1 : 0);
