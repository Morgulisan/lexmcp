<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/includes/mcp-auth/bootstrap.php';
use MopolitiAuth\{Config,OAuth,Util,AppError};
ob_start();
$pdo=new PDO('mysql:host=mcp-auth-test-db;dbname=mcp_auth_test','root','test-only-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("SET time_zone='+00:00'");
$pdo->exec(file_get_contents(dirname(__DIR__,2).'/includes/mcp-auth/migrations/001_initial.sql'));
$settings=['public_url'=>'https://auth.example','services'=>[]];
foreach(['one','two'] as $id) $settings['services'][$id]=['name'=>$id,'resource'=>'https://'.$id.'.example/mcp','gateway_client_id'=>'gateway-'.$id,'gateway_secret_hash'=>hash('sha256','test-secret-'.$id),'allowed_user_ids'=>[42]];
file_put_contents('/tmp/mcp-auth-test-config.php','<?php return '.var_export($settings,true).';');
putenv('MCP_AUTH_CONFIG=/tmp/mcp-auth-test-config.php');Config::load('one');
$checks=0;
function check(bool $condition,string $message): void {global $checks;if(!$condition)throw new RuntimeException($message);$checks++;}
function rejects(callable $fn,string $error): void {try{$fn();}catch(AppError $e){check($e->errorCode===$error,'Unexpected error '.$e->errorCode);return;}throw new RuntimeException('Expected rejection '.$error);}
$oauth=new OAuth($pdo);
$meta=$oauth->authorizationServerMetadata();
check($meta['issuer']==='https://auth.example/one','issuer');
check($meta['authorization_endpoint']==='https://auth.example/one/oauth/authorize','authorization endpoint');
$client=$oauth->register(['redirect_uris'=>['https://client.example/callback'],'client_name'=>'Test client']);
rejects(fn()=>$oauth->register(['redirect_uris'=>['https://user:password@client.example/callback']]),'invalid_client_metadata');
rejects(fn()=>$oauth->register(['redirect_uris'=>['javascript:alert(1)']]),'invalid_client_metadata');
$verifier=str_repeat('a',43);$challenge=Util::base64UrlEncode(hash('sha256',$verifier,true));
$params=['client_id'=>$client['client_id'],'redirect_uri'=>'https://client.example/callback','response_type'=>'code','code_challenge_method'=>'S256','code_challenge'=>$challenge,'resource'=>Config::resourceUrl(),'scope'=>'mcp:access'];
rejects(fn()=>$oauth->beginAuthorization(array_replace($params,['resource'=>'https://two.example/mcp'])),'invalid_target');
rejects(fn()=>$oauth->beginAuthorization(array_replace($params,['redirect_uri'=>'https://evil.example/callback'])),'invalid_request');
function issueCode(): string {global $pdo,$client,$challenge;$code=Util::randomToken();$q=$pdo->prepare('INSERT INTO mpauth_oauth_codes(code_hash,client_id,user_id,redirect_uri,resource,scopes_json,code_challenge,expires_at) VALUES (?,?,42,?,?,?,?,DATE_ADD(NOW(6),INTERVAL 5 MINUTE))');$q->execute([Util::tokenHash($code),$client['client_id'],'https://client.example/callback',Config::resourceUrl(),'["mcp:access"]',$challenge]);return $code;}
function exchange(string $code): array {global $oauth,$client,$verifier;return $oauth->token(['grant_type'=>'authorization_code','code'=>$code,'client_id'=>$client['client_id'],'redirect_uri'=>'https://client.example/callback','code_verifier'=>$verifier,'resource'=>Config::resourceUrl()]);}
function inspectToken(string $token): array {global $oauth;$_SERVER['PHP_AUTH_USER']='gateway-one';$_SERVER['PHP_AUTH_PW']='test-secret-one';return $oauth->introspect(['token'=>$token]);}
$code=issueCode();
rejects(fn()=>$oauth->token(['grant_type'=>'authorization_code','code'=>$code,'client_id'=>$client['client_id'],'redirect_uri'=>'https://client.example/callback','code_verifier'=>str_repeat('b',43)]),'invalid_grant');
$tokens=exchange($code);$identity=inspectToken($tokens['access_token']);
check($identity['active']&&$identity['sub']==='42'&&$identity['aud']===Config::resourceUrl(),'active token identity');
check($identity['exp']>time()+3500&&$identity['exp']<=time()+3601,'expiry');
check(inspectToken($tokens['refresh_token'])['active']===false,'refresh cannot access');
$_SERVER['PHP_AUTH_PW']='wrong';rejects(fn()=>$oauth->introspect(['token'=>$tokens['access_token']]),'invalid_client');
Config::load('two');$_SERVER['PHP_AUTH_USER']='gateway-two';$_SERVER['PHP_AUTH_PW']='test-secret-two';
check($oauth->introspect(['token'=>$tokens['access_token']])['active']===false,'cross-service token rejected');Config::load('one');
function refreshToken(string $token,string $clientOverride=''): array {global $oauth,$client;return $oauth->token(['grant_type'=>'refresh_token','refresh_token'=>$token,'client_id'=>$clientOverride?:$client['client_id'],'resource'=>Config::resourceUrl()]);}
$rotated=refreshToken($tokens['refresh_token']);
check($rotated['access_token']!==$tokens['access_token'],'fresh access token');
rejects(fn()=>refreshToken($tokens['refresh_token'],'wrong-client'),'invalid_grant');
check(inspectToken($rotated['access_token'])['active'],'wrong client does not revoke family');
$retried=refreshToken($tokens['refresh_token']);
check(inspectToken($rotated['access_token'])['active']&&inspectToken($retried['access_token'])['active'],'immediate refresh retry keeps family active');
$pdo->prepare('UPDATE mpauth_oauth_tokens SET used_at=NOW(6)-INTERVAL 61 SECOND WHERE token_hash=?')->execute([Util::tokenHash($tokens['refresh_token'])]);
rejects(fn()=>refreshToken($tokens['refresh_token']),'invalid_grant');
check(!inspectToken($rotated['access_token'])['active']&&!inspectToken($retried['access_token'])['active'],'late refresh replay revokes family');
$code=issueCode();$tokens=exchange($code);rejects(fn()=>exchange($code),'invalid_grant');
check(!inspectToken($tokens['access_token'])['active'],'code replay revokes family');
$tokens=exchange(issueCode());$oauth->revoke(['token'=>$tokens['refresh_token'],'client_id'=>$client['client_id']]);
check(!inspectToken($tokens['access_token'])['active'],'revoke refresh revokes access');
$tokens=exchange(issueCode());
$pdo->prepare('UPDATE mpauth_oauth_tokens SET expires_at=NOW(6)-INTERVAL 1 SECOND WHERE token_hash=?')->execute([Util::tokenHash($tokens['access_token'])]);
check(!inspectToken($tokens['access_token'])['active'],'expired token rejected');
rejects(fn()=>Config::assertUser(99),'access_denied');
// Exercise real browser login and consent against the existing legacy schema.
$pdo->exec('CREATE TABLE UserAccount (ID BIGINT PRIMARY KEY,email VARCHAR(320),passwordHash VARCHAR(64))');
$hash='mopo'.'test-password';for($i=0;$i<9293;$i++)$hash=hash('sha256',$hash);
$pdo->prepare('INSERT INTO UserAccount VALUES (42,?,?)')->execute(['test@example.com',$hash]);
$_COOKIE['mpauth_login_nonce']='test-browser-nonce';
$oauth->loginForAccounts(['login_nonce'=>'test-browser-nonce','mail'=>'test@example.com','password'=>'test-password']);
check($oauth->currentWebUser()===42,'legacy account login');
$oauth->beginAuthorization($params);
$request=$pdo->query('SELECT request_id FROM mpauth_oauth_requests ORDER BY created_at DESC LIMIT 1')->fetchColumn();
$post=['request_id'=>$request,'csrf_token'=>$oauth->csrfToken(),'action'=>'approve'];
$oauth->continueAuthorization($post);
rejects(fn()=>$oauth->continueAuthorization($post),'invalid_request');
check((int)$pdo->query('SELECT COUNT(*) FROM mpauth_oauth_requests')->fetchColumn()===0,'consent consumed once');
$raw=$pdo->query('SELECT token_hash FROM mpauth_oauth_tokens')->fetchAll(PDO::FETCH_COLUMN);
check(!in_array($tokens['access_token'],$raw,true),'tokens stored hashed');
// Exercise the public HTTP router and CGI Basic-auth fallback with the same isolated DB.
file_put_contents('/tmp/mcp-auth-test-sql.php', '<?php function connectToSQL(): PDO { return new PDO("mysql:host=mcp-auth-test-db;dbname=mcp_auth_test", "root", "test-only-password"); }');
putenv('MCP_AUTH_DATABASE_INCLUDE=/tmp/mcp-auth-test-sql.php');
function route(string $path,string $method='GET',array $post=[]): array {
    $_SERVER['REQUEST_URI']=$path;$_SERVER['REQUEST_METHOD']=$method;$_SERVER['REMOTE_ADDR']='127.0.0.1';$_POST=$post;
    http_response_code(200);ob_start();(new MopolitiAuth\Application())->run();$body=ob_get_clean();return [http_response_code(),json_decode($body,true),$body];
}
[$status,$body]=route('/.well-known/oauth-authorization-server/one');
check($status===200&&$body['issuer']==='https://auth.example/one','RFC 8414 path discovery');
[$status,$body]=route('/one/.well-known/openid-configuration');
check($status===200&&$body['token_endpoint']==='https://auth.example/one/oauth/token','OIDC-style discovery alias');
$tokens=exchange(issueCode());unset($_SERVER['PHP_AUTH_USER'],$_SERVER['PHP_AUTH_PW']);
$_SERVER['HTTP_AUTHORIZATION']='Basic '.base64_encode('gateway-one:test-secret-one');
[$status,$body]=route('/one/oauth/introspect','POST',['token'=>$tokens['access_token']]);
check($status===200&&$body['active']&&$body['sub']==='42','CGI introspection authentication');
[$status,$body]=route('/two/oauth/introspect','POST',['token'=>$tokens['access_token']]);
check($status===401,'service credentials cannot inspect another service');
[$status,$body]=route('/one/oauth/token');check($status===405,'token endpoint rejects GET');
// Key storage: ciphertext only, authenticated identity binding, self-service isolation.
putenv('MCP_AUTH_DATA_PATH=/tmp/mcp-auth-test-private');
Config::load('one');$store=new MopolitiAuth\KeyStore($pdo);
$key='private-test-backend-key-42';$store->save(42,$key,'');
$row=$pdo->query("SELECT * FROM mpauth_credentials WHERE user_id=42 AND service_id='one'")->fetch();
check($row['ciphertext']!==$key&&!str_contains($row['ciphertext'],$key),'database contains ciphertext only');
check(strlen($row['nonce'])===24,'XChaCha nonce');
check($store->decrypt(42)===$key,'key decrypt roundtrip');
check($store->metadata(99)===null&&$store->decrypt(99)===null,'other user cannot see key');
check(!array_key_exists('ciphertext',$store->metadata(42)),'metadata excludes secret material');
rejects(fn()=>$store->save(42,'replacement','stale-version'),'key_changed');
rejects(fn()=>$store->save(42,"line\nbreak",$row['version']),'invalid_key');
$_SERVER['PHP_AUTH_USER']='gateway-one';$_SERVER['PHP_AUTH_PW']='test-secret-one';
check(!array_key_exists('backend_key',$oauth->introspect(['token'=>$tokens['access_token']])),'standard introspection does not disclose key');
check($oauth->credential(['token'=>$tokens['access_token']])['backend_key']===$key,'gateway credential bound to access-token subject');
$_SERVER['PHP_AUTH_PW']='wrong';rejects(fn()=>$oauth->credential(['token'=>$tokens['access_token']]),'invalid_client');
$pdo->prepare('INSERT INTO UserAccount VALUES (99,?,?)')->execute(['other@example.com',$hash]);
$pdo->exec("INSERT INTO mpauth_credentials SELECT 99,service_id,version,ciphertext,nonce,updated_at FROM mpauth_credentials WHERE user_id=42 AND service_id='one'");
rejects(fn()=>$store->decrypt(99),'key_unreadable');
$pdo->exec('DELETE FROM mpauth_credentials WHERE user_id=99');
$pdo->exec("INSERT INTO mpauth_credentials SELECT user_id,'two',version,ciphertext,nonce,updated_at FROM mpauth_credentials WHERE user_id=42 AND service_id='one'");
Config::load('two');rejects(fn()=>$store->decrypt(42),'key_unreadable');Config::load('one');
$pdo->exec("DELETE FROM mpauth_credentials WHERE service_id='two'");
$altered=$row['ciphertext'];$altered[0]=chr(ord($altered[0])^1);
$pdo->prepare("UPDATE mpauth_credentials SET ciphertext=? WHERE user_id=42 AND service_id='one'")->execute([$altered]);
rejects(fn()=>$store->decrypt(42),'key_unreadable');
$pdo->prepare("UPDATE mpauth_credentials SET ciphertext=? WHERE user_id=42 AND service_id='one'")->execute([$row['ciphertext']]);
rename('/tmp/mcp-auth-test-private/.encryption.key','/tmp/mcp-auth-test-private/saved-key');
try {$store->save(42,'replacement',$row['version']);throw new Exception('Missing key was regenerated');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'Encryption key missing'),'missing master key fails closed');}
rename('/tmp/mcp-auth-test-private/saved-key','/tmp/mcp-auth-test-private/.encryption.key');
[$status,,$html]=route('/');check($status===200&&str_contains($html,'Meine MCP-Zugänge'),'root is key management portal');
check(!str_contains($html,$key)&&str_contains($html,'Key hinterlegt'),'portal never renders stored key');
[$status]=route('/accounts','POST',['action'=>'save_key','service'=>'one','api_key'=>'attacker-key','version'=>$row['version'],'csrf_token'=>'wrong']);
check($status===403,'key changes require CSRF');
[$status]=route('/accounts','POST',['action'=>'save_key','service'=>'one','api_key'=>'updated-key-42','version'=>$row['version'],'csrf_token'=>$oauth->csrfToken(),'user_id'=>99]);
check($status===303,'key save redirects');Config::load('one');
check($store->decrypt(42)==='updated-key-42'&&$store->decrypt(99)===null,'submitted user id cannot change key ownership');
$new=$store->metadata(42);check($new['version']!==$row['version'],'replacement changes key version');
rejects(fn()=>$store->remove(42,$row['version']),'key_changed');
$store->remove(42,$new['version']);check($store->metadata(42)===null,'key deleted');
$_SERVER['PHP_AUTH_USER']='gateway-one';$_SERVER['PHP_AUTH_PW']='test-secret-one';
check(!$oauth->introspect(['token'=>$tokens['access_token']])['active'],'deleting key revokes service connections');
unset($_COOKIE['mpauth_session']);[$status,,$html]=route('/');
check($status===200&&str_contains($html,'autocomplete="current-password"')&&!str_contains($html,'name="api_key"'),'keys require login');
check(str_contains($html,'width=device-width,initial-scale=1')&&str_contains($html,'@media(max-width:480px)'),'login page includes mobile viewport and responsive layout');
ob_end_clean();echo "OAuth integration: $checks checks passed.\n";
