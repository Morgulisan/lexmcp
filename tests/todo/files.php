<?php
declare(strict_types=1);

use Todo\{FileStore,FileValidator,Support};

function validateBytes(string $bytes, string $name): array {
    $path=tempnam(sys_get_temp_dir(),'todo-format-');
    try { file_put_contents($path,$bytes); return FileValidator::validate($path,$name); }
    finally { unlink($path); }
}
function officeBytes(string $type, array $extra=[]): string {
    $path=tempnam(sys_get_temp_dir(),'todo-office-');$zip=new ZipArchive();$zip->open($path,ZipArchive::OVERWRITE);
    $main=$type==='docx'?'word/document.xml':'xl/workbook.xml';
    $part=$type==='docx'?'wordprocessingml.document.main+xml':'spreadsheetml.sheet.main+xml';
    $entries=[
        '[Content_Types].xml'=>'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/'.$main.'" ContentType="application/vnd.openxmlformats-officedocument.'.$part.'"/></Types>',
        '_rels/.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="'.$main.'"/></Relationships>',
        $main=>$type==='docx'?'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p/></w:body></w:document>':'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rSheet"/></sheets></workbook>',
    ];
    if($type==='xlsx')$entries += [
        'xl/_rels/workbook.xml.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rSheet" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
        'xl/worksheets/sheet1.xml'=>'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData/></worksheet>',
    ];
    foreach(array_replace($entries,$extra)as$name=>$content)$zip->addFromString($name,$content);
    $zip->close();try{return file_get_contents($path);}finally{unlink($path);}
}
function withFileStorage(callable $test): void {
    $old=getenv('TODO_DATA_PATH');$directory=sys_get_temp_dir().'/todo-files-'.Support::id();mkdir($directory,0700);putenv('TODO_DATA_PATH='.$directory);
    try{$test($directory);}finally{
        foreach(glob($directory.'/*')?:[]as$entry){if(is_file($entry))unlink($entry);elseif(is_dir($entry))rmdir($entry);}
        rmdir($directory);putenv($old===false?'TODO_DATA_PATH':'TODO_DATA_PATH='.$old);
    }
}
function attachFixture(Todo\Service $user, array $task): array {
    $id=bin2hex(random_bytes(32));$path=FileStore::path($id);file_put_contents($path,'fixture bytes');
    $result=change($user,'file_add',input($task,['storage_id'=>$id,'sha256'=>hash_file('sha256',$path),'size'=>filesize($path),'mime'=>'application/pdf','title'=>'fixture.pdf']));
    return [$result['task'],$path];
}

test('storage defaults to private data/todo.mopoliti.de',function(){
    $old=getenv('TODO_DATA_PATH');putenv('TODO_DATA_PATH');
    try{check(str_ends_with(str_replace('\\','/',Todo\Config::data()),'/data/todo.mopoliti.de'));}
    finally{if($old!==false)putenv('TODO_DATA_PATH='.$old);}
});
test('deleting removes files and task contents before returning, replays cannot recreate data',function(){withFileStorage(function(){
    [$db,$u,$a,$b,$p]=fixture();$key='create-for-deletion';
    $create=['project_id'=>$p['id'],'title'=>'Private title','idempotency_key'=>$key];$t=$u->mutate('task_create',$create);
    [$t,$path]=attachFixture($u,$t);
    $t=change($u,'memory_update',input($t,['content'=>'Private memory','expected_memory_version'=>0]))['task'];
    $child=change($u,'task_create',['project_id'=>$p['id'],'title'=>'Child','parent_id'=>$t['id']]);
    [$child,$childPath]=attachFixture($u,$child);
    $args=input($t,['idempotency_key'=>'delete-with-files']);$result=$u->mutate('delete',$args);
    check($result['deleted_count']===2&&!file_exists($path)&&!file_exists($childPath));
    check($db->tasks($u->workspace)===[]);
    foreach(['memory','artifact','comment','plan','review','file_gc']as$kind)check($db->entities($u->workspace,$kind)===[]);
    rejects('not_found',fn()=>change($u,'restore',input($t)));
    check($u->mutate('task_create',$create)['state']==='resource_deleted');
    check($u->mutate('delete',$args)===$result);
    check(!str_contains(Support::json($u->read('activity')),'Private title'));
});});
test('archive preserves files and memory indefinitely and can be restored',function(){withFileStorage(function(){
    $f=fixture();[$db,$u,$a,$b,$p]=$f;$t=task($u,$p);[$t,$path]=attachFixture($u,$t);
    $t=change($u,'memory_update',input($t,['content'=>'Keep me','expected_memory_version'=>0]))['task'];
    $archived=change($u,'archive',input($t))['task'];$f[5]+=366*86400;$u->maintenance();
    check(is_file($path)&&$u->read('tasks')['total']===0&&$u->read('tasks',['archived'=>true])['total']===1);
    change($u,'restore',input($archived));check($u->read('memory',['task_id'=>$t['id']])['content']==='Keep me');
});});
test('failed immediate file deletion remains visible and same-key retry finishes it',function(){withFileStorage(function(){
    [$db,$u,$a,$b,$p]=fixture();$t=task($u,$p);[$t,$path]=attachFixture($u,$t);
    unlink($path);mkdir($path); // Deterministic filesystem failure on every platform.
    $args=input($t,['idempotency_key'=>'delete-retry-files']);
    rejects('file_delete_pending',fn()=>$u->mutate('delete',$args));
    check($u->read('operation',['idempotency_key'=>$args['idempotency_key']])['state']==='cleanup_pending');
    check($db->tasks($u->workspace)===[]);
    rmdir($path);file_put_contents($path,'retry bytes');$u->mutate('delete',$args);
    check(!file_exists($path)&&$u->read('operation',['idempotency_key'=>$args['idempotency_key']])['state']==='completed');
});});
test('deleting a prerequisite removes generated children and dangling dependencies',function(){
    [$db,$u,$a,$b,$p]=fixture();$one=task($u,$p);$two=task($u,$p);
    change($u,'dependency_add',input($one,['dependency_id'=>$two['id']]));change($u,'delete',input($two));
    $remaining=$u->read('task',['task_id'=>$one['id']]);check($remaining['subtasks']===[]&&$remaining['task']['dependencies']===[]);
});
test('stale deletion keeps files intact and worker retries failed cleanup',function(){withFileStorage(function(){
    [$db,$u,$a,$b,$p]=fixture();$t=task($u,$p);[$t,$path]=attachFixture($u,$t);
    rejects('version_conflict',fn()=>change($u,'delete',input($t,['expected_version'=>$t['version']-1])));
    check(is_file($path)&&count($db->tasks($u->workspace))===1);
    unlink($path);mkdir($path);
    rejects('file_delete_pending',fn()=>change($u,'delete',input($t)));
    rmdir($path);file_put_contents($path,'pending bytes');
    check($u->maintenance()['pending_file_deletions']===0&&!file_exists($path));
    check($db->entities($u->workspace,'file_gc')===[]);
});});

if(!extension_loaded('fileinfo')||!extension_loaded('gd')||!extension_loaded('zip')){
    echo "SKIP upload formats: enable fileinfo, gd and zip.\n";
    return;
}
test('actual image decoding checks PNG JPEG GIF WebP BMP and rejects renamed/truncated content',function(){
    $image=imagecreatetruecolor(2,2);
    foreach(['png'=>'imagepng','jpg'=>'imagejpeg','gif'=>'imagegif','webp'=>'imagewebp','bmp'=>'imagebmp']as$extension=>$encoder){
        ob_start();$encoder($image);$bytes=ob_get_clean();check(validateBytes($bytes,'image.'.$extension)['extension']===$extension);
    }
    rejects('invalid_file_format',fn()=>validateBytes('not an image','fake.png'));
    ob_start();imagepng($image);$png=ob_get_clean();unset($image);
    rejects('invalid_file_format',fn()=>validateBytes($png,'fake.jpg'));
    rejects('invalid_file_format',fn()=>validateBytes(substr($png,0,40),'broken.png'));
    rejects('file_type',fn()=>validateBytes('<svg/>','image.svg'));
});
test('PDF checks header, final marker and cross-reference offset',function(){
    $pdf="%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\n";
    $offset=strlen($pdf);$pdf.="xref\n0 3\n0000000000 65535 f \n0000000009 00000 n \n0000000063 00000 n \ntrailer\n<< /Size 3 /Root 1 0 R >>\nstartxref\n{$offset}\n%%EOF\n";
    check(validateBytes($pdf,'document.pdf')['mime']==='application/pdf');
    rejects('invalid_file_format',fn()=>validateBytes('%PDF-1.7 fake','fake.pdf'));
    rejects('invalid_file_format',fn()=>validateBytes(str_replace("startxref\n{$offset}","startxref\n0",$pdf),'broken.pdf'));
});
test('DOCX/XLSX validate package type, XML and main parts, not just ZIP signature',function(){
    check(validateBytes(officeBytes('docx'),'document.docx')['extension']==='docx');
    check(validateBytes(officeBytes('xlsx'),'sheet.xlsx')['extension']==='xlsx');
    rejects('invalid_file_format',fn()=>validateBytes(officeBytes('docx'),'renamed.xlsx'));
    rejects('invalid_file_format',fn()=>validateBytes(officeBytes('docx',['word/document.xml'=>'<invalid>']),'broken.docx'));
    rejects('invalid_file_format',fn()=>validateBytes(officeBytes('docx',['word/vbaProject.bin'=>'macro']),'macro.docx'));
    rejects('invalid_file_format',fn()=>validateBytes(officeBytes('docx',['evil.xml'=>'<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>']),'entity.docx'));
    rejects('invalid_file_format',fn()=>validateBytes(officeBytes('docx',['../outside.xml'=>'<x/>']),'traversal.docx'));
    rejects('invalid_file_format',fn()=>validateBytes(officeBytes('xlsx',['xl/worksheets/sheet1.xml'=>'<x/>']),'broken.xlsx'));
    rejects('file_type',fn()=>validateBytes('old format','legacy.xls'));
});
test('CSV checks encoding, delimiters, rectangular rows and quotes',function(){
    foreach(["Name,Amount\nAlice,1\n","Name;Amount\n\"Alice; Bob\";1\n","Name\tAmount\nAlice\t1\n","\xFF\xFE".mb_convert_encoding("Name;Amount\nÄnne;1\n",'UTF-16LE','UTF-8')]as$bytes)check(validateBytes($bytes,'data.csv')['mime']==='text/csv');
    foreach(["not a table", "a,b\n1,2,3\n", "a,b\n1,\"unclosed", "a,b\n1,2\0", '<?php echo "a,b";']as$bytes)rejects('invalid_file_format',fn()=>validateBytes($bytes,'bad.csv'));
});
