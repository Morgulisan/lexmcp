<?php
declare(strict_types=1);
namespace MopolitiAuth;
use PDO;

final class Application
{
    private function json(array $data,int $status=200): void {
        http_response_code($status);header('Content-Type: application/json');echo Util::jsonEncode($data);
    }
    public function run(): void
    {
        header('Cache-Control: no-store');header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        try {
            $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
            $discovery=preg_match('#^/\.well-known/(oauth-authorization-server|openid-configuration)/([a-z][a-z0-9-]*)$#D',$path,$match);
            if (in_array($path,['/','/accounts'],true)) {$id='';$route='/accounts';}
            elseif ($discovery) { $id=$match[2];$route='/metadata'; }
            elseif (preg_match('#^/([a-z][a-z0-9-]*)(/.*)?$#D',$path,$match)) { $id=$match[1];$route=$match[2]??'/'; }
            else throw new AppError('not_found','Not found.',404);
            Config::load($id);
            $pdo=mcpAuthDatabase();mcpAuthMigrate($pdo);$oauth=new OAuth($pdo);
            $method=$_SERVER['REQUEST_METHOD']??'GET';
            if ($route==='/metadata' || in_array($route,['/.well-known/openid-configuration','/.well-known/oauth-authorization-server','/oauth/discovery'],true)) {
                if ($method!=='GET') throw new AppError('invalid_request','GET required.',405);
                $this->json($oauth->authorizationServerMetadata());return;
            }
            if ((int)($_SERVER['CONTENT_LENGTH']??0)>65536) throw new AppError('invalid_request','Request too large.',413);
            $gatewayRoute=in_array($route,['/oauth/introspect','/oauth/credential'],true);
            $this->limit($pdo,$gatewayRoute?1000:300,$gatewayRoute?'introspect':'request');
            if (in_array($route,['/oauth/register','/oauth/token','/oauth/revoke','/oauth/introspect','/oauth/credential'],true)) {
                if ($method!=='POST') throw new AppError('invalid_request','POST required.',405);
                if ($route==='/oauth/register') {
                    $this->limit($pdo,30,'register');
                    $raw=file_get_contents('php://input',false,null,0,65537);
                    if (strlen($raw)>65536) throw new AppError('invalid_request','Request too large.',413);
                    $this->json($oauth->register(Util::jsonDecode($raw)),201);return;
                }
                if ($route==='/oauth/token') { $this->json($oauth->token($_POST));return; }
                if ($route==='/oauth/revoke') { $oauth->revoke($_POST);$this->json([]);return; }
                // CGI/FastCGI does not always populate PHP_AUTH_USER/PW.
                $header=Util::header('Authorization')??'';
                if (str_starts_with($header,'Basic ')) {
                    $basic=base64_decode(substr($header,6),true);
                    if (is_string($basic) && str_contains($basic,':')) [$_SERVER['PHP_AUTH_USER'],$_SERVER['PHP_AUTH_PW']]=explode(':',$basic,2);
                }
                $this->json($route==='/oauth/credential'?$oauth->credential($_POST):$oauth->introspect($_POST));return;
            }
            if ($route==='/oauth/authorize') {
                if ($method==='GET') $oauth->beginAuthorization($_GET);
                elseif ($method==='POST') {
                    if (($_POST['action']??'')==='login') $this->limit($pdo,10,'login');
                    $oauth->continueAuthorization($_POST);
                } else throw new AppError('invalid_request','Method not allowed.',405);
                return;
            }
            if ($route==='/accounts') { $this->accounts($pdo,$oauth,$method);return; }
            throw new AppError('not_found','Not found.',404);
        } catch (AppError $e) {
            if (isset($pdo)&&$pdo->inTransaction()) $pdo->rollBack();
            $this->json(['error'=>$e->errorCode,'error_description'=>$e->getMessage()],$e->httpStatus);
        } catch (\Throwable $e) {
            if (isset($pdo)&&$pdo->inTransaction()) $pdo->rollBack();
            error_log('[mcp-auth] '.$e::class); // Never log SQL arguments, passwords or tokens.
            $this->json(['error'=>'server_error','error_description'=>'OAuth service unavailable.'],503);
        }
    }
    private function limit(PDO $pdo,int $max,string $kind='request'): void
    {
        $window=intdiv(time(),60);$key=hash('sha256',$kind.':'.($_SERVER['REMOTE_ADDR']??'').':'.$window);
        $q=$pdo->prepare('INSERT IGNORE INTO mpauth_limits(bucket,hits,expires_at) VALUES (?,0,?)');$q->execute([$key,($window+2)*60]);
        $q=$pdo->prepare('UPDATE mpauth_limits SET hits=hits+1 WHERE bucket=? AND hits<?');$q->execute([$key,$max]);
        if ($q->rowCount()!==1) {header('Retry-After: 60');throw new AppError('rate_limited','Too many requests.',429);}
    }
    private function accounts(PDO $pdo,OAuth $oauth,string $method): void
    {
        if (!in_array($method,['GET','POST'],true)) throw new AppError('invalid_request','Method not allowed.',405);
        $pageId=Config::serviceId();$target=Config::path('/accounts');
        if ($method==='POST' && ($_POST['action']??'')==='login') {$this->limit($pdo,10,'login');$oauth->loginForAccounts($_POST);unset($_POST['password'],$_POST['legacy_token']);header('Location: '.$target,true,303);return;}
        $user=$oauth->currentWebUser();
        if ($user===null) {$oauth->renderLogin('', $target);return;}
        if ($method==='POST' && in_array($_POST['action']??'',['save_key','delete_key'],true)) {
            $oauth->assertCsrf($_POST);
            $service=Util::requireString($_POST,'service',64);
            if ($pageId!=='' && $service!==$pageId) throw new AppError('invalid_request','Falscher Dienst.');
            Config::load($service);$store=new KeyStore($pdo);
            $version=is_string($_POST['version']??null)?$_POST['version']:'';
            if ($_POST['action']==='delete_key') $store->remove($user,$version);
            else {
                $key=is_string($_POST['api_key']??null)?$_POST['api_key']:'';unset($_POST['api_key']);
                try {$store->save($user,$key,$version);} finally {sodium_memzero($key);}
            }
            header('Location: '.$target,true,303);return;
        }
        if ($method==='POST' && ($_POST['action']??'')==='revoke') {
            $oauth->assertCsrf($_POST);
            $q=$pdo->prepare('UPDATE mpauth_oauth_connections SET revoked_at=NOW(6) WHERE id=? AND user_id=?');
            $q->execute([Util::requireString($_POST,'connection_id',36),$user]);
        }
        if ($method==='POST' && ($_POST['action']??'')==='logout') {
            $oauth->assertCsrf($_POST);
            $pdo->prepare('DELETE FROM mpauth_web_sessions WHERE session_hash=?')->execute([Util::tokenHash($_COOKIE['mpauth_session']??'')]);
            setcookie('mpauth_session','',['expires'=>1,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
            header('Location: '.Config::path('/accounts'),true,303);return;
        }
        $csrf='<input type="hidden" name="csrf_token" value="'.OAuth::h($oauth->csrfToken()).'">';
        $body='<header><p class="muted">MOPOLITI · DEIN KONTO</p><h1>Meine MCP-Zugänge</h1><p>Hinterlege für jeden Dienst deinen eigenen API-Key und verbinde anschließend deinen MCP-Client.</p></header>';
        $services=Config::settings()['services'];
        foreach ($services as $id=>$service) {
            if ($pageId!=='' && $pageId!==$id) continue;
            Config::load($id);$stored=(new KeyStore($pdo))->metadata($user);
            $body.='<section class="connection"><h2>'.OAuth::h($service['name']).'</h2><p class="muted">'.OAuth::h($service['resource']).'</p>';
            $body.='<p>'.($stored?'Key hinterlegt · zuletzt geändert '.OAuth::h($stored['updated_at']).' UTC':'Noch kein API-Key hinterlegt.').'</p>';
            if (Config::userAllowed($user)) {
                $hidden=$csrf.'<input type="hidden" name="service" value="'.OAuth::h($id).'"><input type="hidden" name="version" value="'.OAuth::h($stored['version']??'').'">';
                $body.='<form method="post" action="'.OAuth::h($target).'">'.$hidden.'<label>API-Key<input type="password" name="api_key" required maxlength="8192" autocomplete="new-password" spellcheck="false"></label><p class="muted">Verschlüsselt gespeichert. Ein hinterlegter Key wird nicht wieder angezeigt.</p><button name="action" value="save_key">'.($stored?'Key ersetzen':'Key speichern').'</button></form>';
                if ($stored) $body.='<details><summary>Key entfernen</summary><p>Entfernen widerruft auch deine bestehenden OAuth-Verbindungen für diesen Dienst.</p><form method="post" action="'.OAuth::h($target).'">'.$hidden.'<button name="action" value="delete_key">Key und Freigaben entfernen</button></form></details>';
            } else $body.='<p>Dein Konto ist für diesen Dienst noch nicht freigegeben.</p>';
            $body.='</section>';
        }
        Config::load($pageId);
        $q=$pdo->prepare('SELECT DISTINCT c.id,c.name,t.resource FROM mpauth_oauth_connections c JOIN mpauth_oauth_tokens t ON t.family_id=c.id WHERE c.user_id=? AND c.revoked_at IS NULL'.($pageId!==''?' AND t.resource=?':'').' ORDER BY c.name');
        $q->execute($pageId!==''?[$user,Config::resourceUrl()]:[$user]);
        $body.='<h2>Verbundene Anwendungen</h2>';$connections=$q->fetchAll();
        if (!$connections) $body.='<p class="muted">Noch keine Anwendungen verbunden.</p>';
        foreach ($connections as $c) $body.='<section class="connection"><strong>'.OAuth::h($c['name']).'</strong><p class="muted">'.OAuth::h($c['resource']).'</p><form method="post" action="'.OAuth::h($target).'">'.$csrf.'<input type="hidden" name="connection_id" value="'.OAuth::h($c['id']).'"><button name="action" value="revoke">Verbindung widerrufen</button></form></section>';
        $body.='<footer><p class="muted">Benutzer-ID: '.(int)$user.'</p><form method="post" action="'.OAuth::h($target).'">'.$csrf.'<button name="action" value="logout">Abmelden</button></form></footer>';
        $oauth->html('Meine MCP-Zugänge',$body);
    }
}
