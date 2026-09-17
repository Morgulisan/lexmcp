<?php
declare(strict_types=1);

// Local, isolated UI acceptance fixture. Never deploy tests/ to the webroot.
if(PHP_SAPI!=='cli-server'||getenv('TODO_TEST_PREVIEW')!=='1'||!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)){http_response_code(404);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$public=dirname(__DIR__,2).'/html/todo.mopoliti.de';
if(in_array($path,['/assets/app.css','/assets/app.js'],true)){header('Content-Type: '.(str_ends_with($path,'.css')?'text/css':'text/javascript'));readfile($public.$path);return;}
require dirname(__DIR__,2).'/includes/todo/autoload.php';
$db=new Todo\Database(new PDO('sqlite:'.sys_get_temp_dir().'/todo-ui-preview.sqlite'));$db->migrate();
$crypto=new Todo\Crypto(hash('sha256','isolated-local-preview-only',true));
$auth=new Todo\Auth($db,$crypto);$token=str_repeat('a',64);$auth->put('web',hash('sha256',$token),['user'=>424242,'csrf'=>'local-preview-only'],600);$_COOKIE['todo_session']=$token;
if(isset($_SERVER['HTTP_ORIGIN'])){
    if($_SERVER['HTTP_ORIGIN']!=='http://127.0.0.1:18181'){http_response_code(403);exit;}
    $_SERVER['HTTP_ORIGIN']=Todo\Config::url();
}
(new Todo\Application($db,$crypto))->run();
