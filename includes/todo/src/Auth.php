<?php
declare(strict_types=1);

namespace Todo;

final class Auth
{
    public const SCOPES = ['todo:read','todo:comment','todo:write'];
    public function __construct(private readonly Database $db, private readonly Crypto $crypto) {}
    public function put(string $kind, string $id, array $value, int $ttl): void
    {
        $cipher = $this->crypto->encrypt($value, $kind . ':' . $id);
        $old = $this->db->query('SELECT id FROM todo_auth WHERE kind=? AND id=?', [$kind,$id])->fetchColumn();
        if ($old === false) $this->db->query('INSERT INTO todo_auth(kind,id,payload,expires_at) VALUES (?,?,?,?)', [$kind,$id,$cipher,time()+$ttl]);
        else $this->db->query('UPDATE todo_auth SET payload=?,expires_at=? WHERE kind=? AND id=?', [$cipher,time()+$ttl,$kind,$id]);
    }
    public function get(string $kind, string $id): ?array
    {
        $row = $this->db->query('SELECT payload FROM todo_auth WHERE kind=? AND id=? AND expires_at>?', [$kind,$id,time()])->fetch();
        return $row ? $this->crypto->decrypt($row['payload'], $kind . ':' . $id) : null;
    }
    public function limit(string $key, int $maximum, int $seconds = 60): void
    {
        $window = intdiv(time(), $seconds) * $seconds;
        $id = hash('sha256', $key . ':' . $window);
        $verb = $this->db->sqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $this->db->query($verb . ' INTO todo_limits(id,window_start,hits) VALUES (?,?,0)', [$id,$window]);
        $changed = $this->db->query('UPDATE todo_limits SET hits=hits+1 WHERE id=? AND hits<?', [$id,$maximum])->rowCount();
        if ($changed !== 1) throw new Failure('rate_limited', 'Zu viele Anfragen. Bitte später erneut versuchen.', 429);
    }
    public function metadata(bool $resource = false): array
    {
        $base = Config::url();
        if ($resource) return ['resource'=>$base.'/mcp','authorization_servers'=>[$base],'scopes_supported'=>self::SCOPES,'bearer_methods_supported'=>['header']];
        return ['issuer'=>$base,'authorization_endpoint'=>$base.'/oauth/authorize','token_endpoint'=>$base.'/oauth/token','registration_endpoint'=>$base.'/oauth/register','revocation_endpoint'=>$base.'/oauth/revoke','response_types_supported'=>['code'],'grant_types_supported'=>['authorization_code','refresh_token'],'code_challenge_methods_supported'=>['S256'],'token_endpoint_auth_methods_supported'=>['none'],'scopes_supported'=>self::SCOPES,'authorization_response_iss_parameter_supported'=>true];
    }
    public function register(array $input): array
    {
        $uris = $input['redirect_uris'] ?? null;
        if (!is_array($uris) || !array_is_list($uris) || !$uris || count($uris)>10) throw new Failure('invalid_client_metadata', 'Redirect-Adresse fehlt.');
        foreach ($uris as $uri) { Support::text(['uri'=>$uri],'uri',2048); self::redirectUri($uri); }
        if (($input['token_endpoint_auth_method'] ?? 'none') !== 'none' || array_diff($input['grant_types'] ?? ['authorization_code'], ['authorization_code','refresh_token']) || ($input['response_types'] ?? ['code']) !== ['code']) throw new Failure('invalid_client_metadata', 'Nur öffentliche PKCE-Clients werden unterstützt.');
        $client = ['client_id'=>Support::id(),'client_name'=>Support::text($input+['client_name'=>'MCP-Agent'],'client_name',160),'redirect_uris'=>$uris,'token_endpoint_auth_method'=>'none','grant_types'=>['authorization_code','refresh_token'],'response_types'=>['code']];
        $this->put('client',$client['client_id'],$client,365*86400);
        return $client;
    }
    public static function redirectUri(string $uri): void
    {
        $p = parse_url($uri);
        $loopback = ($p['scheme'] ?? '') === 'http' && in_array($p['host'] ?? '', ['127.0.0.1','[::1]'], true);
        if (!is_array($p) || !isset($p['host']) || (!$loopback && ($p['scheme'] ?? '') !== 'https') || isset($p['fragment']) || isset($p['user']) || isset($p['pass']) || preg_match('/[\x00-\x20]/', $uri)) throw new Failure('invalid_redirect_uri','Ungültige Redirect-Adresse.');
    }
    private function redirectMatches(string $uri, array $registered): bool
    {
        foreach ($registered as $candidate) {
            if ($candidate === $uri) return true;
            $a = parse_url($uri); $b = parse_url($candidate);
            if (($a['scheme'] ?? '') === 'http' && ($b['scheme'] ?? '') === 'http' && in_array($a['host'] ?? '', ['127.0.0.1','[::1]'], true)) {
                unset($a['port'],$b['port']); if ($a === $b) return true;
            }
        }
        return false;
    }
    public function authorization(array $input): array
    {
        $id = Support::text($input,'client_id',128);
        $client = $this->get('client',$id);
        if (!$client) throw new Failure('invalid_client','Client ist nicht registriert.');
        $redirect = Support::text($input,'redirect_uri',2048); self::redirectUri($redirect);
        if (!$this->redirectMatches($redirect,$client['redirect_uris'])) throw new Failure('invalid_redirect_uri','Redirect-Adresse ist nicht registriert.');
        if (($input['response_type'] ?? '') !== 'code' || ($input['code_challenge_method'] ?? '') !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $input['code_challenge'] ?? '')) throw new Failure('invalid_request','Authorization Code mit PKCE-S256 erforderlich.');
        if (($input['resource'] ?? Config::url().'/mcp') !== Config::url().'/mcp') throw new Failure('invalid_target','Falsche MCP-Ressource.');
        $scopes = preg_split('/\s+/',trim($input['scope'] ?? 'todo:read')) ?: [];
        if (!$scopes || array_diff($scopes,self::SCOPES) || !in_array('todo:read',$scopes,true)) throw new Failure('invalid_scope','Ungültige Scopes.');
        $request = ['id'=>Support::id(),'client_id'=>$id,'client_name'=>$client['client_name'],'redirect_uri'=>$redirect,'challenge'=>$input['code_challenge'],'scopes'=>array_values(array_unique($scopes)),'state'=>Support::text($input+['state'=>''],'state',2048,true)];
        $this->put('request',$request['id'],$request,600);
        return $request;
    }
    public function consent(int $user, string $requestId, array $projects, bool $approve): string
    {
        $workspace = $this->db->workspace($user);
        return $this->db->transaction($workspace,function() use($workspace,$user,$requestId,$projects,$approve):string {
            $request=$this->get('request',$requestId);
            if (!$request) throw new Failure('invalid_request','Anfrage abgelaufen.');
            $result=['state'=>$request['state'],'iss'=>Config::url()];
            if ($approve) {
                $projects=Support::strings($projects);
                if (!$projects) throw new Failure('project_required','Mindestens ein Projekt wählen.');
                foreach ($projects as $id) $this->db->entity($workspace,'project',$id);
                $agent=['id'=>Support::id(),'version'=>1,'client_id'=>$request['client_id'],'name'=>$request['client_name'],'projects'=>$projects,'scopes'=>$request['scopes'],'revoked_at'=>null,'created_at'=>time(),'last_activity'=>null];
                $this->db->saveEntity($workspace,'agent',$agent);
                $code=bin2hex(random_bytes(32));
                $this->put('code',hash('sha256',$code),$request+['user'=>$user,'agent_id'=>$agent['id'],'used'=>false],120);
                $result['code']=$code;
            } else $result['error']='access_denied';
            $this->db->query('DELETE FROM todo_auth WHERE kind=? AND id=?',['request',$requestId]);
            return $request['redirect_uri'] . (str_contains($request['redirect_uri'],'?')?'&':'?') . http_build_query($result);
        });
    }
    public function token(array $input): array
    {
        $grant=$input['grant_type']??'';
        $kind=match($grant){'authorization_code'=>'code','refresh_token'=>'refresh',default=>throw new Failure('unsupported_grant_type','Unbekannter Grant.')};
        $raw=Support::text($input,$kind==='code'?'code':'refresh_token',256);
        $hash=hash('sha256',$raw);
        $record=$this->get($kind,$hash);
        if (!$record) throw new Failure('invalid_grant','Grant ist ungültig oder abgelaufen.');
        $workspace=$this->db->workspace($record['user']);
        $result=$this->db->transaction($workspace,function()use($input,$kind,$hash,$workspace):array{
            $record=$this->get($kind,$hash);
            if (!$record || ($input['client_id']??'')!==$record['client_id'] || ($input['resource']??Config::url().'/mcp')!==Config::url().'/mcp') throw new Failure('invalid_grant','Grant ist ungültig.');
            $agent=$this->db->entity($workspace,'agent',$record['agent_id']);
            if ($record['used'] || $agent['revoked_at']!==null) {
                $service=new Service($this->db,$this->crypto,new Actor($record['user']),Support::id());
                $service->revokeConnection(['agent_id'=>$agent['id'],'expected_version'=>$agent['version']]);
                return ['replay'=>true]; // Commit replay revocation before reporting the error.
            }
            if ($kind==='code') {
                $verifier=Support::text($input,'code_verifier',128);
                if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/D',$verifier) || ($input['redirect_uri']??'')!==$record['redirect_uri'] || !hash_equals($record['challenge'],rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='))) throw new Failure('invalid_grant','PKCE-Prüfung fehlgeschlagen.');
            }
            $record['used']=true;$this->put($kind,$hash,$record,30*86400);
            $access=bin2hex(random_bytes(32));$refresh=bin2hex(random_bytes(32));
            $token=['user'=>$record['user'],'client_id'=>$record['client_id'],'agent_id'=>$record['agent_id'],'used'=>false];
            $this->put('access',hash('sha256',$access),$token,600);
            $this->put('refresh',hash('sha256',$refresh),$token,30*86400);
            return ['access_token'=>$access,'token_type'=>'Bearer','expires_in'=>600,'refresh_token'=>$refresh,'scope'=>implode(' ',$agent['scopes']),'resource'=>Config::url().'/mcp'];
        });
        if (isset($result['replay'])) throw new Failure('invalid_grant','Grant bereits verwendet; Verbindung widerrufen.');
        return $result;
    }
    public function bearer(string $header, ?string $sessionId = null): Actor
    {
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/D',$header,$match)) throw new Failure('invalid_token','Bearer-Token erforderlich.',401);
        $token=$this->get('access',hash('sha256',$match[1]));
        if (!$token) throw new Failure('invalid_token','Token ist ungültig oder abgelaufen.',401);
        $workspace=$this->db->workspace($token['user']);$agent=$this->db->entity($workspace,'agent',$token['agent_id']);
        if ($agent['revoked_at']!==null) throw new Failure('invalid_token','Verbindung widerrufen.',401);
        $caps=[];$run=null;
        if ($sessionId!==null) {
            $session=$this->db->entity($workspace,'session',$sessionId);
            if ($session['agent_id']!==$agent['id'] || $session['revoked_at']!==null || $session['expires_at']<=time()) throw new Failure('invalid_session','Agentensitzung ist ungültig.',401);
            $caps=$session['capabilities'];$run=$session['run_id'];
        }
        $this->limit('user:'.$token['user'],600);$this->limit('client:'.$token['client_id'],300);$this->limit('agent:'.$agent['id'],180);
        return new Actor($token['user'],$agent['id'],$agent['projects'],$agent['scopes'],$caps,$agent['client_id'],$run);
    }
    public function revokeToken(array $input): void
    {
        $raw=Support::text($input,'token',256);$hash=hash('sha256',$raw);
        $token=$this->get('access',$hash)??$this->get('refresh',$hash);
        if (!$token) return;
        if (($input['client_id']??'')!==$token['client_id']) return;
        $service=new Service($this->db,$this->crypto,new Actor($token['user']),Support::id());
        $agent=$this->db->entity($service->workspace,'agent',$token['agent_id']);
        $service->mutate('agent_revoke',['agent_id'=>$agent['id'],'expected_version'=>$agent['version'],'idempotency_key'=>Support::id()]);
    }
    public function webSession(): ?array
    {
        $raw=$_COOKIE['todo_session']??'';
        return is_string($raw)&&preg_match('/^[a-f0-9]{64}$/D',$raw)?$this->get('web',hash('sha256',$raw)):null;
    }
    public function issueLoginNonce(): string
    {
        $nonce=bin2hex(random_bytes(32));
        $this->put('login_nonce',hash('sha256',$nonce),['issued_at'=>time()],600);
        return $nonce;
    }
    public function login(array $input): void
    {
        $nonce=$input['nonce']??'';
        if(!is_string($nonce)||!preg_match('/^[a-f0-9]{64}$/D',$nonce)||$this->get('login_nonce',hash('sha256',$nonce))===null)throw new Failure('csrf','Ungültiger Formularschlüssel.',403);
        $this->db->query('DELETE FROM todo_auth WHERE kind=? AND id=?',['login_nonce',hash('sha256',$nonce)]);
        $legacy=$input['legacy_token']??'';
        if (is_string($legacy)&&str_starts_with($legacy,'MST:')&&strlen($legacy)<=4096) $user=$this->db->query('SELECT user_id FROM access_tokens WHERE access_token=? AND expires>NOW()',[$legacy])->fetchColumn();
        else {
            $mail=Support::text($input,'mail',320);$password=Support::text($input,'password',4096);
            $hash='mopo'.$password;for($i=0;$i<9293;$i++)$hash=hash('sha256',$hash);
            $user=$this->db->query('SELECT ID FROM UserAccount WHERE email=? AND passwordHash=? LIMIT 1',[$mail,$hash])->fetchColumn();
            unset($password,$hash);
        }
        if ($user===false) throw new Failure('login_failed','Anmeldung fehlgeschlagen.',401);
        $token=bin2hex(random_bytes(32));$this->put('web',hash('sha256',$token),['user'=>(int)$user,'csrf'=>bin2hex(random_bytes(32))],43200);
        setcookie('todo_session',$token,['expires'=>time()+43200,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
        $_COOKIE['todo_session']=$token;
    }
    public function csrf(array $session, string $provided): void
    {
        if (!hash_equals($session['csrf'],$provided)) throw new Failure('csrf','Ungültiger Formularschlüssel.',403);
    }
    public function logout(): void
    {
        $this->db->query('DELETE FROM todo_auth WHERE kind=? AND id=?',['web',hash('sha256',$_COOKIE['todo_session']??'')]);
        setcookie('todo_session','',['expires'=>1,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
    }
}
