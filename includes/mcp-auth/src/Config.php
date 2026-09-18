<?php
declare(strict_types=1);
namespace MopolitiAuth;

final class Config
{
    private static array $settings=[];
    private static string $id='';
    public static function load(string $id=''): void
    {
        $file=getenv('MCP_AUTH_CONFIG') ?: dirname(__DIR__,3).'/data/mcp-auth/config.php';
        self::$settings=is_file($file)?require $file:require dirname(__DIR__).'/config/services.php';
        if ($id!=='' && (!preg_match('/^[a-z][a-z0-9-]{0,63}$/D',$id) || !isset(self::$settings['services'][$id]))) throw new AppError('not_found','Unknown service.',404);
        self::$id=$id;
        if ($id!=='' && !preg_match('/^[a-f0-9]{64}$/D',self::service()['gateway_secret_hash']??'')) throw new \RuntimeException('Gateway secret hash missing.');
    }
    public static function settings(): array { return self::$settings; }
    public static function serviceId(): string { return self::$id; }
    public static function service(): array { return self::$settings['services'][self::$id]; }
    public static function publicUrl(): string { return rtrim(self::$settings['public_url'],'/').'/'.self::$id; }
    public static function resourceUrl(): string { return self::service()['resource']; }
    public static function path(string $path): string { return (self::$id!==''?'/'.self::$id:'').$path; }
    public static function scopes(): array { return ['mcp:access']; }
    public static function userAllowed(int $id): bool { $allowed=self::service()['allowed_user_ids']??null;return $id>0 && ($allowed===null || in_array($id,$allowed,true)); }
    public static function assertUser(int $id): void { if (!self::userAllowed($id)) throw new AppError('access_denied','Dieses Konto ist für diesen Dienst nicht freigegeben.',403); }
    public static function dataPath(): string { return getenv('MCP_AUTH_DATA_PATH') ?: dirname(__DIR__,3).'/data/mcp-auth'; }
    public static function encryptionKey(bool $allowCreate): string
    {
        $encoded=getenv('MCP_AUTH_ENCRYPTION_KEY');
        if ($encoded===false || $encoded==='') {
            $dir=self::dataPath();
            if (!is_dir($dir) && (!mkdir($dir,0700,true) && !is_dir($dir))) throw new \RuntimeException('Cannot create private key directory.');
            $file=$dir.'/.encryption.key';
            if (!$allowCreate && !is_file($file)) throw new \RuntimeException('Encryption key missing. Restore the original master key.');
            $handle=fopen($file,$allowCreate?'c+b':'rb');
            if ($handle===false) throw new \RuntimeException('Encryption key missing. Restore the original master key.');
            try {
                if (!flock($handle,$allowCreate?LOCK_EX:LOCK_SH)) throw new \RuntimeException('Cannot lock encryption key.');
                $encoded=trim(stream_get_contents($handle));
                if ($encoded==='') {
                    if (!$allowCreate) throw new \RuntimeException('Encryption key missing.');
                    $encoded=base64_encode(random_bytes(32));$data=$encoded."\n";
                    if (!rewind($handle)||!ftruncate($handle,0)||fwrite($handle,$data)!==strlen($data)||!fflush($handle)) throw new \RuntimeException('Cannot persist encryption key.');
                    chmod($file,0600);
                }
            } finally {flock($handle,LOCK_UN);fclose($handle);}
        }
        $key=base64_decode($encoded,true);
        if ($key===false || strlen($key)!==32) throw new \RuntimeException('Invalid encryption key.');
        return $key;
    }
}
