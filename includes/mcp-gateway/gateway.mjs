import http from 'node:http';
import https from 'node:https';
import {readFileSync} from 'node:fs';
import {createHmac, timingSafeEqual, createHash} from 'node:crypto';
import {Transform, pipeline} from 'node:stream';
import {pathToFileURL} from 'node:url';

const digest = value => createHash('sha256').update(value).digest('hex');
function problem(res, status, error, headers={}) {
  if (res.headersSent) { res.destroy(); return; }
  res.writeHead(status, {'Content-Type':'application/json','Cache-Control':'no-store',...headers});
  res.end(JSON.stringify({error}));
}
export function createGateway(config, {credentialProvider, secretReader=path=>readFileSync(path,'utf8').trim()}={}) {
  const routes = new Map();
  const sessionSecret=secretReader(config.session_secret_file);
  if (sessionSecret.length<32) throw new Error('Session signing secret too short');
  for (const route of config.services) {
    const url=new URL(route.resource);
    const issuer=new URL(route.issuer);
    const upstream=new URL(route.upstream);
    if (url.protocol!=='https:' || issuer.protocol!=='https:' || !['http:','https:'].includes(upstream.protocol) || url.search || url.hash || url.username || url.password || upstream.username || upstream.password) throw new Error('Invalid service URLs');
    if (routes.has(url.host)) throw new Error('Duplicate public service host');
    route.host=url.host;route.path=url.pathname;route.origin=url.origin;
    route.metadata=`${url.origin}/.well-known/oauth-protected-resource${url.pathname}`;
    route.gatewaySecret=secretReader(route.gateway_secret_file);
    if (route.gatewaySecret.length<32) throw new Error('Gateway secret too short');
    if (!['authorization','x-api-key'].includes(route.auth_header)) throw new Error('Unsupported auth header');
    routes.set(url.host,route);
  }
  function seal(payload) {
    const data=Buffer.from(JSON.stringify(payload)).toString('base64url');
    return data+'.'+createHmac('sha256',sessionSecret).update(data).digest('base64url');
  }
  function unseal(token, identity, route, key) {
    if (typeof token!=='string' || token.length>8192) throw new Error('Invalid session');
    const [data,sig,...extra]=token.split('.');
    const actual=Buffer.from(sig||'','base64url');
    const expected=createHmac('sha256',sessionSecret).update(data||'').digest();
    if (extra.length || actual.length!==expected.length || !timingSafeEqual(actual,expected)) throw new Error('Invalid session');
    const value=JSON.parse(Buffer.from(data,'base64url').toString());
    if (value.sub!==identity.sub || value.connection!==identity.connection_id || value.resource!==route.resource || value.key!==digest(key) || value.exp<=Date.now()/1000 || typeof value.id!=='string' || /[\r\n]/.test(value.id)) throw new Error('Invalid session');
    return value.id;
  }
  const check=credentialProvider ?? (async (route,token)=> {
    const response=await fetch(route.issuer+'/oauth/credential',{
      method:'POST',redirect:'error',signal:AbortSignal.timeout(5000),
      headers:{'Content-Type':'application/x-www-form-urlencoded','Authorization':'Basic '+Buffer.from(route.gateway_client_id+':'+route.gatewaySecret).toString('base64')},
      body:new URLSearchParams({token})
    });
    if (!response.ok) throw new Error('Introspection unavailable');
    const reader=response.body.getReader();let size=0;const chunks=[];
    for (;;) {const {done,value}=await reader.read();if(done) break;size+=value.length;if(size>16384){await reader.cancel();throw new Error('Oversized introspection');}chunks.push(Buffer.from(value));}
    return JSON.parse(Buffer.concat(chunks).toString());
  });
  return http.createServer({maxHeaderSize:16384,requestTimeout:120000},async(req,res)=> {
    res.setHeader('X-Content-Type-Options','nosniff');
    const route=routes.get(req.headers.host);
    if (!route) return problem(res,404,'unknown_host');
    let url;try {url=new URL(req.url,route.origin);}catch{return problem(res,400,'invalid_url');}
    if (url.origin!==route.origin) return problem(res,400,'invalid_url');
    if ([`/.well-known/oauth-protected-resource${route.path}`,'/.well-known/oauth-protected-resource'].includes(url.pathname)) {
      if (req.method!=='GET') return problem(res,405,'method_not_allowed',{Allow:'GET'});
      res.writeHead(200,{'Content-Type':'application/json','Cache-Control':'public, max-age=300'});
      return res.end(JSON.stringify({resource:route.resource,authorization_servers:[route.issuer],scopes_supported:['mcp:access'],bearer_methods_supported:['header']}));
    }
    if (![route.path,route.path+'/'].includes(url.pathname)) return problem(res,404,'not_found');
    if (url.search) return problem(res,400,'query_parameters_not_supported');
    if (!['GET','POST','DELETE'].includes(req.method)) return problem(res,405,'method_not_allowed',{Allow:'GET, POST, DELETE'});
    if (req.headers.origin && req.headers.origin!==route.origin) return problem(res,403,'origin_not_allowed');
    const challenge=`Bearer resource_metadata="${route.metadata}", scope="mcp:access"`;
    const bearer=/^Bearer ([^\s]{1,1024})$/i.exec(req.headers.authorization||'');
    if (!bearer) return problem(res,401,'invalid_token',{'WWW-Authenticate':challenge});
    let identity;
    try {identity=await check(route,bearer[1]);}catch{return problem(res,503,'authorization_unavailable',{'Retry-After':'5'});}
    if (identity.active!==true || identity.iss!==route.issuer || identity.aud!==route.resource || !Number.isFinite(identity.exp) || identity.exp<=Date.now()/1000 || typeof identity.sub!=='string' || typeof identity.connection_id!=='string' || !identity.connection_id) return problem(res,401,'invalid_token',{'WWW-Authenticate':challenge});
    if (!(identity.scope||'').split(' ').includes('mcp:access')) return problem(res,403,'insufficient_scope',{'WWW-Authenticate':challenge+', error="insufficient_scope"'});
    let key=identity.backend_key;delete identity.backend_key;
    if (typeof key!=='string' || !/^[\x21-\x7e]{1,8192}$/.test(key)) return problem(res,403,'account_not_configured');
    const headers={};
    for (const name of ['accept','content-type','mcp-protocol-version','mcp-method','mcp-name']) if (req.headers[name]) headers[name]=req.headers[name];
    if (req.headers['mcp-session-id']) {
      try {headers['mcp-session-id']=unseal(req.headers['mcp-session-id'],identity,route,key);}catch{return problem(res,403,'invalid_session');}
      if (req.headers['last-event-id']) headers['last-event-id']=req.headers['last-event-id'];
    } else if(req.headers['last-event-id']) return problem(res,400,'session_required');
    headers[route.auth_header]=route.auth_header==='authorization'?'Bearer '+key:key;
    const limit=route.max_body_bytes??31457280;
    const length=Number(req.headers['content-length']||0);
    if (length>limit) return problem(res,413,'request_too_large');
    const keyFingerprint=digest(key);
    key=null; // Backend credential is retained only by the outgoing request headers.
    const target=new URL(route.upstream);
    headers.host=target.host;
    const transport=target.protocol==='https:'?https:http;
    const upstream=transport.request(target,{method:req.method,headers},response=> {
      if ([301,302,303,307,308,401,403].includes(response.statusCode)) {response.resume();return problem(res,502,'backend_rejected_request');}
      const outgoing={'Cache-Control':'no-store','X-Accel-Buffering':'no'};
      for (const name of ['content-type','mcp-protocol-version','allow','retry-after']) if(response.headers[name]) outgoing[name]=response.headers[name];
      const session=response.headers['mcp-session-id'];
      if(session) outgoing['mcp-session-id']=seal({id:session,sub:identity.sub,connection:identity.connection_id,resource:route.resource,key:keyFingerprint,exp:Math.floor(Date.now()/1000)+86400});
      res.writeHead(response.statusCode,outgoing);
      pipeline(response,res,()=>{});
    });
    upstream.setTimeout(3600000,()=>upstream.destroy(new Error('Backend timeout')));
    upstream.on('error',()=>problem(res,502,'backend_unavailable'));
    res.on('close',()=>upstream.destroy());req.on('aborted',()=>upstream.destroy());
    let bytes=0;
    const limiter=new Transform({transform(chunk,encoding,callback){bytes+=chunk.length;if(bytes>limit){problem(res,413,'request_too_large');callback(new Error('Body limit'));}else callback(null,chunk);}});
    pipeline(req,limiter,upstream,()=>{});
  });
}

if(process.argv[1] && import.meta.url===pathToFileURL(process.argv[1]).href) {
  const config=JSON.parse(readFileSync(process.env.GATEWAY_CONFIG||'/run/secrets/gateway.json','utf8'));
  createGateway(config).listen(config.port||8787,config.listen||'127.0.0.1',()=>console.log('MCP gateway ready'));
}
