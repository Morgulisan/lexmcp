#!/usr/bin/env python3
"""Prepare gateway machine secrets; user API keys live in the encrypted central DB."""
import hashlib
import json
import os
from pathlib import Path
import secrets
import shutil
base=Path('/opt/mcp-gateway');private=base/'secrets'
base.mkdir(mode=0o755,exist_ok=True);private.mkdir(mode=0o750,exist_ok=True)
os.chown(private,0,65534);os.chmod(private,0o750)
def save(path,text):
    temp=path.with_suffix(path.suffix+'.tmp')
    with os.fdopen(os.open(temp,os.O_WRONLY|os.O_CREAT|os.O_TRUNC,0o600),'w') as f:f.write(text)
    os.chown(temp,0,65534);os.chmod(temp,0o440);os.replace(temp,path)
def secret(name):
    file=private/name
    if not file.exists():save(file,secrets.token_hex(32)+'\n')
    return file.read_text().strip()
source=Path(__file__).resolve().parent
config_file=private/'gateway.json'
config=json.loads((config_file if config_file.exists() else source/'gateway.example.json').read_text())
secret('session-key');services={}
for route in config['services']:
    route.pop('users',None)
    name=route['gateway_client_id'].removeprefix('gateway-')
    value=secret('introspection-'+name)
    services[name]={'name':name,'resource':route['resource'],'gateway_client_id':route['gateway_client_id'],'gateway_secret_hash':hashlib.sha256(value.encode()).hexdigest()}
save(config_file,json.dumps(config,indent=2)+'\n')
def php(value):
    if isinstance(value,str):return "'"+value.replace('\\','\\\\').replace("'","\\'")+"'"
    return '['+',\n'.join(php(k)+' => '+php(v) for k,v in value.items())+']'
save(base/'auth-config.php','<?php\nreturn '+php({'public_url':'https://auth.mopoliti.de','services':services})+';\n')
for file in ['gateway.mjs','compose.yaml']:shutil.copy2(source/file,base/file)
# Only remove the known duplicate files from the initial unused gateway setup.
for name in ['whtspp-user-key','obsdn-user-key']:
    file=private/name
    if file.is_file():file.unlink()
print('Prepared gateway with central encrypted credential storage; Nginx unchanged.')
