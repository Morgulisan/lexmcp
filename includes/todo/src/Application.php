<?php
declare(strict_types=1);

namespace Todo;

final class Application
{
    private readonly Auth $auth;
    private readonly string $requestId;
    public function __construct(private readonly Database $db, private readonly Crypto $crypto)
    {
        $this->auth=new Auth($db,$crypto);$this->requestId=Support::id();
    }
    public function run(): void
    {
        header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('Cache-Control: no-store');header('X-Frame-Options: DENY');header('X-Request-ID: '.$this->requestId);
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self' https: http://127.0.0.1:* http://[::1]:*");
        try {
            $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);$method=$_SERVER['REQUEST_METHOD']??'GET';
            $this->auth->limit('ip:'.($_SERVER['REMOTE_ADDR']??'unknown'),300);
            $origin=$_SERVER['HTTP_ORIGIN']??null;
            if($origin!==null&&$origin!==Config::url())throw new Failure('origin_denied','Unzulässige Herkunft.',403);
            if($method==='OPTIONS'){header('Allow: GET, POST, OPTIONS');http_response_code(204);return;}
            if(str_starts_with($path,'/.well-known/')){
                if($method!=='GET')throw new Failure('method_not_allowed','GET erforderlich.',405);
                if(!in_array($path,['/.well-known/oauth-protected-resource','/.well-known/oauth-protected-resource/mcp','/.well-known/oauth-authorization-server','/.well-known/oauth-authorization-server/mcp','/.well-known/openid-configuration'],true))throw new Failure('not_found','Nicht gefunden.',404);
                $this->json($this->auth->metadata(str_contains($path,'protected-resource')));return;
            }
            if(in_array($path,['/oauth/register','/oauth/token','/oauth/revoke'],true)){
                $this->post($method);$data=$this->body();
                $this->auth->limit('oauth:'.($_SERVER['REMOTE_ADDR']??''),30);
                if($path==='/oauth/register'){$this->json($this->auth->register($data),201);return;}
                if($path==='/oauth/token'){$this->json($this->auth->token($data));return;}
                $this->auth->revokeToken($data);$this->json(new \stdClass());return;
            }
            if($path==='/mcp'){
                $authorization=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
                // Authenticate even unsupported methods and notifications.
                if($method!=='POST')$this->auth->bearer($authorization);
                $this->post($method);
                $reply=(new Mcp($this->db,$this->crypto,$this->auth,$this->requestId))->handle($this->body(),$authorization);
                if($reply===null){http_response_code(202);return;}$this->json($reply);return;
            }
            if($path==='/login'&&$method==='POST'){
                $this->auth->limit('login:'.($_SERVER['REMOTE_ADDR']??''),10,300);$this->auth->login($this->body());
                $request=is_string($_POST['request_id']??null)?$_POST['request_id']:'';
                header('Location: '.($request!==''?'/oauth/authorize?request_id='.rawurlencode($request):'/'),true,303);return;
            }
            $session=$this->auth->webSession();
            if($path==='/oauth/authorize'){
                if($method==='POST'){
                    if(!$session)throw new Failure('login_required','Bitte anmelden.',401);
                    $data=$this->body();$this->auth->csrf($session,(string)($data['csrf']??''));
                    $url=$this->auth->consent($session['user'],Support::text($data,'request_id',32),$data['projects']??[],($data['decision']??'')==='approve');
                    header('Location: '.$url,true,303);return;
                }
                if($method!=='GET')throw new Failure('method_not_allowed','GET erforderlich.',405);
                $request=isset($_GET['request_id'])?$this->auth->get('request',Support::text($_GET,'request_id',32)):$this->auth->authorization($_GET);
                if(!$request)throw new Failure('invalid_request','Anfrage abgelaufen.');
                if(!$session){$this->login($request['id']);return;}
                $projects=(new Service($this->db,$this->crypto,new Actor($session['user']),$this->requestId))->read('projects');
                $this->consentPage($request,$session,$projects);return;
            }
            if(!$session){if($path==='/api')throw new Failure('login_required','Bitte neu anmelden.',401);$this->login();return;}
            $this->auth->limit('user:'.$session['user'],600);
            if($path==='/upload'){
                $this->post($method);$this->auth->csrf($session,$_SERVER['HTTP_X_CSRF_TOKEN']??'');
                $this->auth->limit('upload:'.$session['user'],10);
                $input=['task_id'=>Support::text($_POST,'task_id',32),'expected_version'=>(int)($_POST['expected_version']??0),'idempotency_key'=>Support::text($_POST,'idempotency_key',128)];
                $service=new Service($this->db,$this->crypto,new Actor($session['user']),$this->requestId);
                $this->json(FileStore::upload($service,$input,$_FILES['file']??[]));return;
            }
            if($path==='/download'){
                if($method!=='GET')throw new Failure('method_not_allowed','GET erforderlich.',405);
                $service=new Service($this->db,$this->crypto,new Actor($session['user']),$this->requestId);
                $file=$this->db->entity($service->workspace,'artifact',Support::text($_GET,'id',32));
                $service->read('task',['task_id'=>$file['task_id']]);
                if(!isset($file['storage_id']))throw new Failure('not_found','Datei nicht gefunden.',404);
                $filePath=FileStore::path($file['storage_id']);if(!is_file($filePath))throw new Failure('not_found','Datei nicht gefunden.',404);
                header('Content-Type: application/octet-stream');header('Content-Length: '.filesize($filePath));header("Content-Disposition: attachment; filename=download; filename*=UTF-8''".rawurlencode($file['title']));readfile($filePath);return;
            }
            if($path==='/logout'){$this->post($method);$data=$this->body();$this->auth->csrf($session,(string)($data['csrf']??''));$this->auth->logout();header('Location: /',true,303);return;}
            if($path==='/api'){
                $this->post($method);$data=$this->body();$this->auth->csrf($session,$_SERVER['HTTP_X_CSRF_TOKEN']??'');
                $service=new Service($this->db,$this->crypto,new Actor($session['user']),$this->requestId);
                $action=Support::text($data,'action',80);$input=$data['input']??[];
                if($action==='file_add')throw new Failure('forbidden','Dateien nur über den Upload-Endpunkt hinzufügen.',403);
                if(!is_array($input))throw new Failure('invalid_input','Ungültige Eingabe.');
                if(!in_array($data['mode']??'read',['read','write'],true))throw new Failure('invalid_mode','Ungültiger API-Modus.');
                $result=($data['mode']??'read')==='read'?$service->read($action,$input):$service->mutate($action,$input);
                $this->json($result);return;
            }
            if($path!=='/')throw new Failure('not_found','Nicht gefunden.',404);
            $csrf=$session['csrf'];require dirname(__DIR__).'/views/app.php';
        }catch(Failure $error){
            if($error->status===401)header('WWW-Authenticate: Bearer resource_metadata="'.Config::url().'/.well-known/oauth-protected-resource", scope="todo:read"');
            if($error->status===429)header('Retry-After: 60');
            $this->json(['error'=>$error->reason,'message'=>$error->getMessage(),'request_id'=>$this->requestId],$error->status);
        }catch(\Throwable){$this->json(['error'=>'internal_error','message'=>'Anfrage konnte nicht verarbeitet werden.','request_id'=>$this->requestId],500);}
    }
    private function post(string $method): void {if($method!=='POST'){header('Allow: POST');throw new Failure('method_not_allowed','POST erforderlich.',405);}}
    private function body(): array
    {
        if((int)($_SERVER['CONTENT_LENGTH']??0)>1048576)throw new Failure('too_large','Anfrage ist zu groß.',413);
        if(str_contains($_SERVER['CONTENT_TYPE']??'','application/json')){
            $body=file_get_contents('php://input',false,null,0,1048577);
            if($body===false||strlen($body)>1048576)throw new Failure('too_large','Anfrage ist zu groß.',413);
            try{$result=json_decode($body,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new Failure('invalid_json','Ungültiges JSON.');}
            if(!is_array($result)||array_is_list($result))throw new Failure('invalid_json','JSON-Objekt erforderlich.');return $result;
        }
        return $_POST;
    }
    private function json(mixed $value,int $status=200):void{http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo Support::json($value);}
    public static function h(string $text):string{return htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private function login(string $request=''):void
    {
        $nonce=bin2hex(random_bytes(32));setcookie('todo_login_nonce',$nonce,['expires'=>time()+600,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
        $h=self::h(...);
        echo '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Anmelden · Gemeinsam</title><link rel="stylesheet" href="/assets/app.css"><body class="auth-page"><main class="auth-card"><div class="brand">g<span>Gemeinsam</span></div><h1>Ein Ort für alles,<br>was vorangeht.</h1><p>Deine Aufgaben. Deine Agenten. Gemeinsam erledigt.</p><form action="/login" method="post"><input type="hidden" name="nonce" value="'.$h($nonce).'"><input type="hidden" name="request_id" value="'.$h($request).'"><label>E-Mail<input name="mail" type="email" autocomplete="username"></label><label>Passwort<input name="password" type="password" autocomplete="current-password"></label><details><summary>Mit bestehendem MST-Token anmelden</summary><label>MST-Token<input name="legacy_token" type="password" autocomplete="off"></label></details><button class="primary">Anmelden</button></form><small>Mit deinem bestehenden zentralen Benutzerkonto.</small></main></body></html>';
    }
    private function consentPage(array $request,array $session,array $projects):void
    {
        $h=self::h(...);
        echo '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Agent verbinden</title><link rel="stylesheet" href="/assets/app.css"><body class="auth-page"><main class="auth-card"><h1>Agent verbinden</h1><p><strong>'.$h($request['client_name']).'</strong> möchte Zugriff erhalten.</p><p>Berechtigungen: '.$h(implode(', ',$request['scopes'])).'</p><form method="post" action="/oauth/authorize"><input type="hidden" name="csrf" value="'.$h($session['csrf']).'"><input type="hidden" name="request_id" value="'.$h($request['id']).'"><fieldset><legend>Freigegebene Projekte</legend>';
        foreach($projects as$project)echo '<label class="check"><input type="checkbox" name="projects[]" value="'.$h($project['id']).'">'.$h($project['name']).'</label>';
        echo '</fieldset><p>Du kannst die Verbindung jederzeit in den Einstellungen widerrufen.</p><div class="actions"><button class="primary" name="decision" value="approve">Zugriff erlauben</button><button name="decision" value="deny">Ablehnen</button></div></form></main></body></html>';
    }
}
