import {test} from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import {once} from 'node:events';
import {createGateway} from '../../includes/mcp-gateway/gateway.mjs';
test('Discovery, identity isolation, key replacement, sessions and failure modes',async()=>{
 const seen=[];
 const backend=http.createServer((req,res)=>{
   let body='';req.on('data',c=>body+=c);req.on('end',()=>{
     seen.push({headers:req.headers,body});res.writeHead(200,{'Content-Type':'text/event-stream','Mcp-Session-Id':'backend-session'});
     res.end('event: message\ndata: {"ok":true}\n\n');
   });
 });backend.listen(0,'127.0.0.1');await once(backend,'listening');
 const config={session_secret_file:'session',services:[{resource:'https://mcp.example/mcp',issuer:'https://auth.example/mcp',upstream:`http://127.0.0.1:${backend.address().port}/mcp`,gateway_client_id:'gateway',gateway_secret_file:'gateway',auth_header:'authorization',users:{'1':'key1','2':'key2'},max_body_bytes:1000}]};
 const secrets={session:'s'.repeat(64),gateway:'g'.repeat(64),key1:'backend-key-1',key2:'backend-key-2'};
 const gateway=createGateway(config,{secretReader:p=>secrets[p],credentialProvider:async(_,token)=>{
   if(token==='down') throw Error('unavailable');
   const value={active:true,sub:'1',connection_id:'conn-1',iss:'https://auth.example/mcp',aud:'https://mcp.example/mcp',exp:Date.now()/1000+600,scope:'mcp:access',backend_key:'backend-key-1'};
   if(token==='other') {value.sub='2';value.backend_key='backend-key-2';}
   if(token==='connection') value.connection_id='conn-2';
   if(token==='unknown') {value.sub='3';value.backend_key=null;}
   if(token==='replaced') value.backend_key='new-backend-key';
   if(token==='wrong-audience') value.aud='https://other.example/mcp';
   if(token==='expired') value.exp=0;
   if(token==='revoked') value.active=false;
   if(token==='scope') value.scope='';
   return value;
 }});gateway.listen(0,'127.0.0.1');await once(gateway,'listening');
 const request=(path='/mcp',token,extra={},body='{}')=>new Promise((resolve,reject)=>{
   const req=http.request({hostname:'127.0.0.1',port:gateway.address().port,path,method:body===null?'GET':'POST',headers:{Host:'mcp.example',...(token?{Authorization:'Bearer '+token}:{}),...extra}},res=>{
     let result='';res.on('data',c=>result+=c);res.on('end',()=>resolve({status:res.statusCode,headers:res.headers,body:result}));
   });req.on('error',reject);req.end(body);
 });
 try {
   let r=await request('/.well-known/oauth-protected-resource/mcp',undefined,{},null);assert.equal(r.status,200);assert.deepEqual(JSON.parse(r.body).authorization_servers,['https://auth.example/mcp']);
   r=await request();assert.equal(r.status,401);assert.match(r.headers['www-authenticate'],/resource_metadata/);assert.equal(seen.length,0);
   for(const token of ['wrong-audience','expired','revoked']) assert.equal((await request('/mcp',token)).status,401);
   assert.equal((await request('/mcp','down')).status,503);
   assert.equal((await request('/mcp','unknown')).status,403);
   assert.equal((await request('/mcp','scope')).status,403);
   assert.equal((await request('/mcp','valid',{Origin:'https://evil.example'})).status,403);
   assert.equal((await request('/mcp?access_token=leak','valid')).status,400);
   r=await request('/mcp','valid',{'X-Api-Key':'injected',Cookie:'leak=yes','Content-Type':'application/json'},'{"jsonrpc":"2.0"}');
   assert.equal(r.status,200);assert.match(r.body,/event: message/);
   assert.equal(seen[0].headers.authorization,'Bearer backend-key-1');assert.equal(seen[0].headers.cookie,undefined);assert.equal(seen[0].headers['x-api-key'],undefined);
   const session=r.headers['mcp-session-id'];assert.notEqual(session,'backend-session');
   assert.equal((await request('/mcp','valid',{'Mcp-Session-Id':session})).status,200);
   assert.equal(seen[1].headers['mcp-session-id'],'backend-session');
   for(const token of ['other','connection','replaced']) assert.equal((await request('/mcp',token,{'Mcp-Session-Id':session})).status,403);
   assert.equal((await request('/mcp','valid',{'Mcp-Session-Id':'backend-session'})).status,403);
   assert.equal((await request('/mcp','valid',{'Content-Length':'1001'},'x'.repeat(1001))).status,413);
 }finally {gateway.closeAllConnections();backend.closeAllConnections();await Promise.all([new Promise(r=>gateway.close(r)),new Promise(r=>backend.close(r))]);}
});
