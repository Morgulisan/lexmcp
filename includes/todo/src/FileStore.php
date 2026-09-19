<?php
declare(strict_types=1);

namespace Todo;

final class FileStore
{
    public static function path(string $id): string
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$id))throw new Failure('invalid_file','Ungültige Datei.');
        $root=Config::data();
        if(!is_dir($root)&&!mkdir($root,0700,true)&&!is_dir($root))throw new \RuntimeException('Private data directory is unavailable.');
        $real=realpath($root);$web=realpath(dirname(__DIR__,3).'/html');
        if(!$real||($web&&str_starts_with(strtolower(str_replace('\\','/',$real)).'/',strtolower(str_replace('\\','/',$web)).'/')))throw new \RuntimeException('Uploads must be outside the webroot.');
        return $real.DIRECTORY_SEPARATOR.$id.'.bin';
    }
    public static function upload(Service $service, array $input, array $file): array
    {
        $service->actor->userOnly();
        $service->read('task',['task_id'=>Support::text($input,'task_id',32)]);
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??''))throw new Failure('upload_failed','Datei konnte nicht hochgeladen werden.');
        $size=filesize($file['tmp_name']);
        if($size===false||$size<1||$size>10*1024*1024)throw new Failure('file_size','Dateien dürfen maximal 10 MiB groß sein.',413);
        $format = FileValidator::validate($file['tmp_name'], (string)($file['name'] ?? ''));
        $scanner=Config::env('TODO_MALWARE_SCANNER');
        $scannerRequired=Config::env('TODO_MALWARE_SCAN_REQUIRED','0')==='1';
        if($scanner===''||!is_file($scanner)||!is_callable('proc_open')){
            if($scannerRequired)throw new Failure('scanner_unavailable','Der Malware-Scanner ist nicht eingerichtet. Upload wurde nicht gespeichert.',503);
            error_log('Todo upload: malware scanner unavailable; accepting file after format validation.');
            $scanner='';
        }
        if($scanner!=='')self::scan($scanner,$file['tmp_name'],$scannerRequired);
        $hash=hash_file('sha256',$file['tmp_name']);$key=Support::text($input,'idempotency_key',128);
        $id=hash('sha256',$service->workspace.':'.$input['task_id'].':'.$key.':'.$hash);
        $path=self::path($id);
        $lock=fopen(dirname($path).DIRECTORY_SEPARATOR.'uploads.lock','c');
        if($lock===false||!flock($lock,LOCK_EX))throw new Failure('upload_busy','Upload-Sperre nicht verfügbar.',503);
        try{
            $existed=is_file($path);
            if(!$existed&&!move_uploaded_file($file['tmp_name'],$path))throw new Failure('upload_failed','Datei konnte nicht gespeichert werden.',500);
            if(!$existed)chmod($path,0600);
            try{
                return $service->mutate('file_add',$input+['storage_id'=>$id,'sha256'=>$hash,'size'=>$size,'mime'=>$format['mime'],'title'=>mb_substr(basename(str_replace('\\','/',$file['name'])),0,200)]);
            }catch(\Throwable $error){if(!$existed&&is_file($path))unlink($path);throw $error;}
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    private static function scan(string $scanner, string $path, bool $required): void
    {
        $pipes=[];$process=proc_open([$scanner,'--no-summary','--',$path],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
        if(!is_resource($process)){
            if($required)throw new Failure('scanner_unavailable','Malware-Prüfung nicht verfügbar.',503);
            error_log('Todo upload: malware scanner could not be started; accepting file after format validation.');
            return;
        }
        fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$deadline=microtime(true)+30;$exit=-1;
        do{
            stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);$status=proc_get_status($process);
            if(!$status['running']){$exit=$status['exitcode'];break;}
            if(microtime(true)>$deadline){proc_terminate($process);break;}usleep(100000);
        }while(true);
        fclose($pipes[1]);fclose($pipes[2]);proc_close($process);
        if($exit===1)throw new Failure('malware_detected','Die Datei wurde als unsicher abgewiesen.',422);
        if($exit!==0){
            if($required)throw new Failure('scanner_failed','Die Malware-Prüfung konnte nicht abgeschlossen werden.',422);
            error_log('Todo upload: malware scan failed with exit code '.$exit.'; accepting file after format validation.');
        }
    }
    public static function remove(string $id): bool
    {
        $path = self::path($id);
        $lock = fopen(dirname($path) . DIRECTORY_SEPARATOR . 'uploads.lock', 'c');
        if ($lock === false) return false;
        try {
            if (!flock($lock, LOCK_EX)) return false;
            if (!file_exists($path) && !is_link($path)) return true;
            return is_file($path) && !is_link($path) && @unlink($path);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
