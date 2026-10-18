<?php
declare(strict_types=1);
require __DIR__.'/../src/Core.php';
use function DomainLens\{domain,publicIp,registrable,resolveUrl,metadata};
use DomainLens\{Cache,Problem,Http};
$count=0;
function check(bool $ok,string $label): void { global $count; if(!$ok) throw new RuntimeException('FAIL: '.$label); $count++; }
function blocked(callable $fn,string $label): void { try { $fn(); } catch(Problem) { check(true,$label); return; } check(false,$label); }
foreach(['127.0.0.1','127.1','2130706433','localhost','foo.local','foo.internal','https://example.com:443','https://user:pass@example.com','ftp://example.com','https://example.com/path','https://example.com?x=1','example.com\\@evil.com',"example.com\n",'http://[::1]/','foo.home.arpa'] as $input) {
    // Trailing whitespace is intentionally normalized.
    if($input==="example.com\n") check(domain($input)==='example.com','trim whitespace');
    else blocked(fn()=>domain($input),'reject '.$input);
}
check(domain('HTTPS://EXAMPLE.COM/')==='example.com','normalize URL');
check(domain('例子.中国')==='xn--fsqu00a.xn--fiqs8s','IDN');
foreach(['127.0.0.1','10.0.0.1','172.16.0.1','192.168.0.1','169.254.169.254','100.100.100.200','0.0.0.0','192.0.0.9','192.0.2.1','198.18.0.1','198.51.100.1','203.0.113.1','224.0.0.1','255.255.255.255','::1','::','::ffff:8.8.8.8','fe80::1','fc00::1','2001:db8::1','2001::1','2002:0808:0808::1','3fff::1'] as $ip) check(!publicIp($ip),'block '.$ip);
foreach(['8.8.8.8','1.1.1.1','2606:4700:4700::1111'] as $ip) check(publicIp($ip),'allow '.$ip);
check(registrable('www.example.co.uk')==='example.co.uk','multi-label suffix');
check(registrable('a.b.ck')==='a.b.ck','wildcard suffix');
check(registrable('foo.www.ck')==='www.ck','exception suffix');
check(registrable('foo.blogspot.com')==='blogspot.com','RDAP uses ICANN rules only');
blocked(fn()=>registrable('co.uk'),'public suffix');
check(resolveUrl('https://example.com/a/b','../c')==='https://example.com/c','relative redirect');
check(resolveUrl('https://example.com/a/b','//evil.com/x')==='https://evil.com/x','scheme relative');
check(resolveUrl('https://example.com/a/b','?q=2')==='https://example.com/a/b?q=2','query redirect');
blocked(fn()=>resolveUrl('https://example.com/',"/x\r\nHeader:1"),'header injection');
$http=new Http();
foreach(['file:///etc/passwd','gopher://example.com/','http://127.0.0.1/','http://[::1]/','https://example.com:8443/','https://a:b@example.com/'] as $url) blocked(fn()=>$http->get($url),'block transport '.$url);
$m=metadata('<html lang="zh-CN"><head><title>A &amp; B</title><META NAME="DESCRIPTION" content="hello &lt;script&gt;"><meta name="ROBOTS" content="noindex"><meta name="keywords" content="a,b"><base href="https://example.com/dir/"><link rel="alternate canonical" href="../main"></head><body><h1>Hello</h1></body></html>','text/html; charset=UTF-8','https://example.com/');
check($m['title']==='A & B','title decoding');check($m['description']==='hello <script>','untrusted text preserved');check($m['canonical']==='https://example.com/main','base canonical');check($m['robots']==='noindex','case insensitive robots');check($m['keywords']==='a,b','keywords');check($m['h1']===['Hello'],'h1');check($m['lang']==='zh-CN','language');
$gb=mb_convert_encoding('<html><head><meta charset="gbk"><title>中文标题</title></head></html>','GBK','UTF-8');check(metadata($gb,'text/html','https://example.com/')['title']==='中文标题','legacy encoding');
$dir=sys_get_temp_dir().'/domain-lens-test-'.bin2hex(random_bytes(6));$cache=new Cache($dir);$cache->write('../key',['x'=>1],60);check($cache->read('../key')===['x'=>1],'cache round trip');check(dirname($cache->path('../key'))===$dir,'cache traversal');$lock=$cache->lock('x');blocked(fn()=>$cache->lock('x'),'stampede lock');flock($lock,LOCK_UN);fclose($lock);$cache->write('expired',['x'=>1],-1);check($cache->read('expired')===null,'expiration');foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);
echo "PASS: $count checks (no network required)\n";
