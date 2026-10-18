#!/usr/bin/env python3
"""API contract checks against a local server; never fetch public domains."""
import json
import sys
import urllib.request
import urllib.error
base = sys.argv[1] if len(sys.argv)>1 else 'http://127.0.0.1:8787'
checks=0
def call(body=None, method='POST', headers=None):
    req=urllib.request.Request(base+'/api.php', data=body, method=method, headers=headers or {'Content-Type':'application/json'})
    try:
        with urllib.request.urlopen(req,timeout=10) as res:return res.status,json.load(res)
    except urllib.error.HTTPError as res:return res.code,json.load(res)
def check(ok):
    global checks
    assert ok
    checks+=1
check(call(method='GET')[0]==405)
check(call(b'{}', headers={'Content-Type':'text/plain'})[0]==415)
check(call(b'{')[0]==400)
check(call(b'null')[0]==422)
check(call(b'{"domains":[]}')[0]==422)
check(call(json.dumps({'domains':['a.com']*11}).encode())[0]==422)
check(call(b'{}'+b' '*65536)[0]==413)
check(call(b'{"domains":["example.com"]}',headers={'Content-Type':'application/json','Origin':'https://evil.example'})[0]==403)
status, body=call(b'{"domains":["127.0.0.1","https://example.com:8080","test.local"]}')
check(status==200 and len(body['results'])==3)
check(all(r['error']['code'] in ('blocked_host','invalid_domain') for r in body['results']))
print(f'PASS: {checks} API contract checks')
