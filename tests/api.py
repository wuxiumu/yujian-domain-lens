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
check(call(b'{"domains":["example.com"],"action":"unknown"}')[0]==422)
status, body=call(b'{"domains":["cache-test.example.com","other-cache-test.example.com"],"action":"clear_cache"}')
check(status==200 and len(body['results'])==2 and all(r['cache_cleared'] for r in body['results']))
check(all(r['traffic_domain']=='example.com' and r['cleared_at'] for r in body['results']))
status, body=call(b'{"domains":["127.0.0.1"],"action":"refresh"}')
check(status==200 and body['results'][0]['error']['code']=='invalid_domain')
print(f'PASS: {checks} API contract checks')
