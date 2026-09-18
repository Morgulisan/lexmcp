<?php
declare(strict_types=1);
namespace MopolitiAuth;
use PDO;

final class KeyStore
{
    public function __construct(private readonly PDO $pdo) {}
    public function metadata(int $user): ?array
    {
        $q=$this->pdo->prepare('SELECT version,updated_at FROM mpauth_credentials WHERE user_id=? AND service_id=?');
        $q->execute([$user,Config::serviceId()]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
    }
    private function aad(int $user,string $version): string
    {
        return Util::jsonEncode(['v'=>1,'user'=>$user,'service'=>Config::serviceId(),'resource'=>Config::resourceUrl(),'version'=>$version]);
    }
    public function save(int $user,#[\SensitiveParameter] string $key,string $expectedVersion): void
    {
        Config::assertUser($user);
        if (!preg_match('/^[\x21-\x7e]{1,8192}$/D',$key)) throw new AppError('invalid_key','Der Key darf keine Leerzeichen oder Zeilenumbrüche enthalten und maximal 8192 Zeichen lang sein.');
        $this->pdo->beginTransaction();
        try {
            $this->lockUser($user);
            $old=$this->metadata($user);
            if (($old['version']??'')!==$expectedVersion) throw new AppError('key_changed','Der Key wurde inzwischen geändert. Bitte Seite neu laden.',409);
            // Never silently create a replacement master key if encrypted rows already exist.
            $allowCreate=(int)$this->pdo->query('SELECT COUNT(*) FROM mpauth_credentials')->fetchColumn()===0;
            $master=Config::encryptionKey($allowCreate);
            $nonce=random_bytes(24);$version=Util::uuid();
            try {$cipher=sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($key,$this->aad($user,$version),$nonce,$master);}
            finally {sodium_memzero($master);sodium_memzero($key);}
            if ($old===null) {
                $q=$this->pdo->prepare('INSERT INTO mpauth_credentials(user_id,service_id,version,ciphertext,nonce) VALUES (?,?,?,?,?)');
                try {$q->execute([$user,Config::serviceId(),$version,$cipher,$nonce]);}
                catch (\PDOException $e) {if ($e->getCode()==='23000') throw new AppError('key_changed','Der Key wurde inzwischen geändert. Bitte Seite neu laden.',409);throw $e;}
            } else {
                $q=$this->pdo->prepare('UPDATE mpauth_credentials SET version=?,ciphertext=?,nonce=?,updated_at=NOW(6) WHERE user_id=? AND service_id=? AND version=?');
                $q->execute([$version,$cipher,$nonce,$user,Config::serviceId(),$expectedVersion]);
                if ($q->rowCount()!==1) throw new AppError('key_changed','Der Key wurde inzwischen geändert. Bitte Seite neu laden.',409);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function remove(int $user,string $expectedVersion): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->lockUser($user);$old=$this->metadata($user);
            if (($old['version']??'')!==$expectedVersion) throw new AppError('key_changed','Der Key wurde inzwischen geändert. Bitte Seite neu laden.',409);
            if ($old!==null) {
                $q=$this->pdo->prepare('DELETE FROM mpauth_credentials WHERE user_id=? AND service_id=? AND version=?');$q->execute([$user,Config::serviceId(),$expectedVersion]);
                if ($q->rowCount()!==1) throw new AppError('key_changed','Der Key wurde inzwischen geändert. Bitte Seite neu laden.',409);
            }
            $q=$this->pdo->prepare('UPDATE mpauth_oauth_connections SET revoked_at=NOW(6) WHERE user_id=? AND id IN (SELECT family_id FROM mpauth_oauth_tokens WHERE user_id=? AND resource=?)');
            $q->execute([$user,$user,Config::resourceUrl()]);$this->pdo->commit();
        } catch (\Throwable $e) {if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    private function lockUser(int $user): void
    {
        $q=$this->pdo->prepare('SELECT ID FROM UserAccount WHERE ID=? FOR UPDATE');$q->execute([$user]);
        if ($q->fetchColumn()===false) throw new AppError('login_required','Konto nicht mehr vorhanden.',401);
    }
    public function decrypt(int $user): ?string
    {
        $q=$this->pdo->prepare('SELECT version,ciphertext,nonce FROM mpauth_credentials WHERE user_id=? AND service_id=?');
        $q->execute([$user,Config::serviceId()]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $master=Config::encryptionKey(false);
        try {$key=sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($row['ciphertext'],$this->aad($user,$row['version']),$row['nonce'],$master);}
        finally {sodium_memzero($master);}
        if ($key===false) throw new AppError('key_unreadable','Der gespeicherte Key kann nicht entschlüsselt werden.',503);
        return $key;
    }
}
