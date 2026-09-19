<?php
declare(strict_types=1);

// Run only against the explicitly started, loopback-only preview fixture.
if(PHP_SAPI!=='cli'||getenv('TODO_TEST_PREVIEW')!=='1')exit('Local preview flag required.');
function request(string $path, string $method='GET', ?string $body=null, array $headers=[]): array {
    $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$body??'','ignore_errors'=>true,'timeout'=>10]]);
    $result=file_get_contents('http://127.0.0.1:18181'.$path,false,$context);
    preg_match('/HTTP\/\S+ (\d+)/',$http_response_header[0]??'',$status);
    return [(int)($status[1]??0),$result,$http_response_header];
}
function status(string $name, int $expected, array $response): void {
    if($response[0]!==$expected)throw new RuntimeException($name.': expected '.$expected.', got '.$response[0]);
    echo 'PASS '.$name.PHP_EOL;
}
$json=['Content-Type: application/json'];$csrf=[...$json,'X-CSRF-Token: local-preview-only'];
status('Browser API rejects missing CSRF',403,request('/api','POST','{"action":"tasks"}',$json));
status('Browser API rejects foreign Origin',403,request('/api','POST','{"action":"tasks"}',[...$csrf,'Origin: https://evil.example']));
status('MCP rejects missing bearer',401,request('/mcp','POST','{"jsonrpc":"2.0","id":1,"method":"tools/list"}',$json));
status('Discovery is public',200,request('/.well-known/oauth-protected-resource'));
status('Forged file metadata is forbidden',403,request('/api','POST','{"mode":"write","action":"file_add","input":{}}',$csrf));
status('Unknown API mode is rejected',400,request('/api','POST','{"mode":"invalid","action":"tasks"}',$csrf));
status('Oversized JSON is rejected',413,request('/api','POST',str_repeat(' ',1048577),$csrf));
$tasks=json_decode(request('/api','POST','{"action":"tasks"}',$csrf)[1],true)['items'];
if($tasks){
    $task=$tasks[0];$boundary='todo-http-test-boundary';$body='';
    foreach(['task_id'=>$task['id'],'expected_version'=>$task['version'],'idempotency_key'=>'http-test-'.bin2hex(random_bytes(8))]as$key=>$value)$body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"{$key}\"\r\n\r\n{$value}\r\n";
    $body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"file\"; filename=\"test.csv\"\r\nContent-Type: text/csv\r\n\r\nName,Amount\r\nAlice,1\r\n--".$boundary."--\r\n";
    status('Unsupported extension is rejected',415,request('/upload','POST',str_replace('test.csv','test.exe',$body),['Content-Type: multipart/form-data; boundary='.$boundary,'X-CSRF-Token: local-preview-only']));
    status('Forged image content is rejected',415,request('/upload','POST',str_replace('test.csv','test.png',$body),['Content-Type: multipart/form-data; boundary='.$boundary,'X-CSRF-Token: local-preview-only']));
    status('Validated uploads fall back without configured malware scanner',200,request('/upload','POST',$body,['Content-Type: multipart/form-data; boundary='.$boundary,'X-CSRF-Token: local-preview-only']));
}
