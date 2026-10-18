#!/usr/bin/env python3
"""Read-only checks against an imported history; no upstream requests or traffic key required."""
import sys,urllib.request,urllib.error,re,xml.etree.ElementTree as ET,hashlib
from pathlib import Path
base=(sys.argv[1] if len(sys.argv)>1 else 'http://127.0.0.1:8788').rstrip('/')
checks=0
def fetch(path,method='GET',headers=None):
    req=urllib.request.Request(base+path,method=method,headers=headers or {})
    try:
        with urllib.request.urlopen(req,timeout=10) as r:return r.status,r.headers,r.read().decode()
    except urllib.error.HTTPError as r:return r.code,r.headers,r.read().decode()
def check(value,label):
    global checks
    assert value,label
    checks+=1
index=Path(__file__).resolve().parent.parent/'var/history/index.json'
before=hashlib.sha256(index.read_bytes()).hexdigest()
status,headers,history=fetch('/history');check(status==200 and '<h1>查询历史' in history,'history page')
status,headers,report=fetch('/domain/51chiguai.com');check(status==200,'domain report')
check('<title>51chiguai.com ' in report and 'https://schema.org/WebPage' in report,'SSR SEO')
check('rel="canonical" href="'+base+'/domain/51chiguai.com"' in report,'canonical URL')
check('og:description' in report and 'twitter:card' in report,'share metadata')
check('name="robots" content="index, follow"' in report,'latest report indexable')
share=re.search(r'data-share-url="([^"]+/domain/51chiguai\.com/[a-f0-9]{24})"',report).group(1)
status,headers,snapshot=fetch(urllib.parse.urlparse(share).path)
check(status==200 and 'noindex' in headers.get('X-Robots-Tag',''),'fixed snapshot readable and noindex')
status,_,filtered=fetch('/history?q=51chiguai.com');check(status==200 and '/domain/51chiguai.com' in filtered and '/domain/51chigua.com' not in filtered,'history filter')
status,headers,_=fetch('/history?q=51');check('noindex' in headers.get('X-Robots-Tag',''),'filtered pages noindex')
check(fetch('/history?page=999')[0]==404,'invalid range')
check(fetch('/history?page=0')[0]==400,'invalid page')
check(fetch('/domain/missing-example.com')[0]==404,'unknown report')
check(fetch('/domain/127.0.0.1')[0]==404,'IP report denied')
_,_,xss=fetch('/history?q=%3Cscript%3Ealert(1)%3C%2Fscript%3E');check('<script>alert' not in xss,'query XSS denied')
status,headers,xml=fetch('/sitemap.xml');check(status==200 and ET.fromstring(xml) is not None,'valid XML')
check('/domain/51chiguai.com' in xml and '/'+share.rsplit('/',1)[-1] not in xml,'sitemap excludes duplicate snapshots')
status,_,robots=fetch('/robots.txt');check(status==200 and 'Sitemap: '+base+'/sitemap.xml' in robots,'robots sitemap')
status,headers,poison=fetch('/domain/51chiguai.com',headers={'Host':'evil.example'});check(status==200 and 'rel="canonical" href="'+base+'/domain/51chiguai.com"' in poison and 'evil.example' not in poison,'Host header cannot poison canonical')
status,_,body=fetch('/history',method='HEAD');check(status==200 and body=='','HEAD supported')
check(fetch('/history?q='+urllib.parse.quote('中'*101))[0]==400,'oversize search rejected')
check(fetch('/history?q%5B%5D=x')[0]==400,'array query rejected')
check(hashlib.sha256(index.read_bytes()).hexdigest()==before,'browsing does not mutate history')
print(f'PASS: {checks} read-only page HTTP checks')
