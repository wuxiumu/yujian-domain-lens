<?php
declare(strict_types=1);
namespace DomainLens;
require_once __DIR__.'/Traffic.php';

final class Problem extends \RuntimeException {
    public function __construct(public readonly string $kind, string $message) { parent::__construct($message); }
}
function domain(string $input): string {
    $s = trim($input);
    if ($s === '' || strlen($s) > 1000 || preg_match('/[\x00-\x20\x7f\\\\]/', $s)) throw new Problem('invalid_domain', '请输入域名，不能包含空格或控制字符。');
    if (str_contains($s, '://')) {
        $p = parse_url($s);
        if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || isset($p['user']) || isset($p['pass']) || isset($p['port']) || isset($p['query']) || isset($p['fragment']) || !in_array($p['path'] ?? '', ['', '/'], true)) throw new Problem('invalid_domain', '仅接受域名或 HTTP(S) 首页地址；不能指定端口、路径或账号。');
        $s = $p['host'] ?? '';
    }
    $s = strtolower(rtrim($s, '.'));
    if (function_exists('idn_to_ascii')) $s = idn_to_ascii($s, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: '';
    if (strlen($s) > 253 || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $s) || filter_var($s, FILTER_VALIDATE_IP)) throw new Problem('invalid_domain', '域名格式无效；不支持 IP 地址和单标签主机名。');
    foreach (['localhost','local','internal','test','invalid','example','onion','home.arpa'] as $suffix) if ($s === $suffix || str_ends_with($s, '.'.$suffix)) throw new Problem('blocked_host', '禁止本地、保留或内部域名。');
    return $s;
}
function inCidr(string $ip, string $cidr): bool {
    [$net,$bits] = explode('/', $cidr); $a = inet_pton($ip); $b = inet_pton($net);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) return false;
    $whole = intdiv((int)$bits,8); $rem = (int)$bits % 8;
    return substr($a,0,$whole) === substr($b,0,$whole) && (!$rem || ((ord($a[$whole]) ^ ord($b[$whole])) & (255 << (8-$rem))) === 0);
}
function publicIp(string $ip): bool {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    if (str_contains($ip, ':')) {
        if (!inCidr($ip,'2000::/3')) return false;
        $denied = ['2001::/23','2001:db8::/32','2002::/16','3fff::/20'];
    } else $denied = ['0.0.0.0/8','100.64.0.0/10','192.0.0.0/24','192.0.2.0/24','192.88.99.0/24','198.18.0.0/15','198.51.100.0/24','203.0.113.0/24','224.0.0.0/4','240.0.0.0/4'];
    foreach ($denied as $cidr) if (inCidr($ip,$cidr)) return false;
    return true;
}
/** Isolate system DNS in a bounded CLI process, validate every answer, then pin cURL. */
final class Http {
    private array $dns = [];
    public function addresses(string $host): array {
        if (isset($this->dns[$host])) return $this->dns[$host];
        if (!function_exists('proc_open')) throw new Problem('dns_unavailable','当前 PHP 禁用了 proc_open，无法进行有时限的安全 DNS 查询。');
        $binary = getenv('DOMAIN_LENS_PHP_CLI') ?: PHP_BINDIR.'/php';
        $pipes=[];
        $process=proc_open([$binary,__DIR__.'/resolve.php',$host],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
        if(!is_resource($process)) throw new Problem('dns_unavailable','无法启动 DNS 解析进程，请检查 PHP CLI 路径。');
        fclose($pipes[0]); stream_set_blocking($pipes[1],false); stream_set_blocking($pipes[2],false);
        $raw=''; $start=microtime(true); $timedOut=false;
        try {
            do {
                $raw.=stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
                if(strlen($raw)>65536) throw new Problem('dns_error','DNS 响应过大。');
                $status=proc_get_status($process);
                if(!$status['running']) { $raw.=stream_get_contents($pipes[1]); break; }
                if(microtime(true)-$start>=4) { $timedOut=true; proc_terminate($process,9); break; }
                usleep(10000);
            } while(true);
        } finally { fclose($pipes[1]); fclose($pipes[2]); proc_close($process); }
        if($timedOut) throw new Problem('dns_timeout','DNS 查询超过 4 秒，请稍后重试。');
        $ips=json_decode($raw,true);
        if(!is_array($ips) || !$ips) throw new Problem('dns_not_found','域名没有可用的公网 A / AAAA 记录，或 DNS 查询失败。');
        foreach($ips as $ip) if(!is_string($ip) || !publicIp($ip)) throw new Problem('blocked_ip','DNS 返回非公网地址，已阻止访问。');
        return $this->dns[$host] = array_values(array_unique($ips));
    }
    public function get(string $url, int $limit = 2097152, float $budget = 20, array $extraHeaders = []): array {
        $start = microtime(true); $chain = [];
        for ($hop=0;$hop<=4;$hop++) {
            $p = parse_url($url);
            if (!$p || !in_array(strtolower($p['scheme'] ?? ''),['http','https'],true) || isset($p['user']) || isset($p['pass']) || isset($p['port'])) throw new Problem('blocked_url','禁止的协议、账号或自定义端口。');
            $host = domain($p['host'] ?? '');
            $url = strtolower($p['scheme']).'://'.$host.($p['path'] ?? '/').(isset($p['query'])?'?'.$p['query']:'');
            $ips = $this->addresses($host); $ip = $ips[0];
            $remaining = $budget - (microtime(true)-$start);
            if ($remaining <= 0) throw new Problem('timeout','查询达到时间上限。');
            $port = strtolower($p['scheme']) === 'https' ? 443 : 80;
            $body = ''; $headers = []; $size = 0; $headerSize = 0; $tooLarge = false;
            $c = curl_init($url);
            curl_setopt_array($c,[CURLOPT_PROXY=>'',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT_MS=>min(4000,(int)($remaining*1000)),CURLOPT_TIMEOUT_MS=>(int)($remaining*1000),CURLOPT_RESOLVE=>["$host:$port:".(str_contains($ip,':') ? "[$ip]" : $ip)],CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_ENCODING=>'',CURLOPT_USERAGENT=>'DomainLens/1.0 (+public metadata analysis)',CURLOPT_HTTPHEADER=>['Accept: text/html, application/rdap+json, application/json;q=0.9, */*;q=0.5',...$extraHeaders],CURLOPT_HEADERFUNCTION=>static function($c,$line) use (&$headers,&$headerSize,&$tooLarge) {
                $headerSize += strlen($line); if ($headerSize>65536) { $tooLarge=true; return 0; }
                if (preg_match('/^HTTP\//',$line)) $headers=[];
                if (str_contains($line,':')) { [$k,$v]=explode(':',$line,2); $headers[strtolower(trim($k))][] = trim($v); }
                return strlen($line);
            },CURLOPT_WRITEFUNCTION=>static function($c,$chunk) use (&$body,&$size,&$tooLarge,$limit) { $size+=strlen($chunk); if ($size>$limit) { $tooLarge=true; return 0; } $body.=$chunk; return strlen($chunk); }]);
            $ok=curl_exec($c); $err=curl_errno($c); $status=curl_getinfo($c,CURLINFO_RESPONSE_CODE); $peer=curl_getinfo($c,CURLINFO_PRIMARY_IP); curl_close($c);
            if ($tooLarge) throw new Problem('response_too_large','响应超过大小限制（页面 2 MiB，robots.txt 256 KiB）。');
            if ($ok===false) throw new Problem($err===CURLE_OPERATION_TIMEDOUT?'timeout':'network_error', $err===CURLE_OPERATION_TIMEDOUT?'请求超时。':'连接失败或 TLS 证书验证失败。');
            if (!publicIp($peer) || inet_pton($peer)!==inet_pton($ip)) throw new Problem('blocked_ip','连接地址与已验证公网地址不符。');
            $chain[]=['url'=>$url,'status'=>$status];
            if (in_array($status,[301,302,303,307,308],true) && isset($headers['location'][0])) {
                if ($extraHeaders) throw new Problem('provider_redirect','认证接口发生重定向，已阻止以防泄露凭据。');
                if ($hop===4) throw new Problem('redirect_limit','重定向超过 4 次。');
                $url = resolveUrl($url,$headers['location'][0]); continue;
            }
            return compact('url','status','headers','body','chain');
        }
        throw new Problem('redirect_limit','重定向次数过多。');
    }
}
function resolveUrl(string $base,string $ref): string {
    $ref=trim($ref);
    if (preg_match('/[\x00-\x20\x7f\\\\]/',$ref)) throw new Problem('blocked_url','重定向地址含非法字符。');
    $ref=explode('#',$ref,2)[0];
    if (preg_match('/^[a-z][a-z0-9+.-]*:/i',$ref)) return $ref;
    $p=parse_url($base); $origin=$p['scheme'].'://'.$p['host'];
    if (str_starts_with($ref,'//')) return $p['scheme'].':'.$ref;
    if ($ref==='') return explode('#',$base,2)[0];
    if ($ref[0]==='?') return $origin.($p['path'] ?? '/').$ref;
    if ($ref[0]==='/') return $origin.$ref;
    $path=substr($p['path'] ?? '/',0,strrpos($p['path'] ?? '/','/')+1).$ref;
    $parts=[]; foreach(explode('/',$path) as $piece) { if($piece==='..') array_pop($parts); elseif($piece!=='.') $parts[]=$piece; }
    return $origin.implode('/',$parts);
}
final class Cache {
    public function __construct(private string $dir) { if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new Problem('cache_error','无法创建缓存目录。'); }
    public function path(string $key): string { return $this->dir.'/'.hash('sha256',$key).'.json'; }
    public function read(string $key): ?array { $p=$this->path($key); $d=is_file($p)?json_decode((string)file_get_contents($p),true):null; return is_array($d) && ($d['expires']??0)>time() ? $d['value']:null; }
    public function write(string $key,array $value,int $ttl): void {
        $p=$this->path($key); $tmp=tempnam($this->dir,'tmp-');
        if (!$tmp || file_put_contents($tmp,json_encode(['expires'=>time()+$ttl,'value'=>$value],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE),LOCK_EX)===false || !rename($tmp,$p)) throw new Problem('cache_error','文件缓存写入失败。');
    }
    public function remove(string $key): void { $p=$this->path($key); if(is_file($p) && !unlink($p)) throw new Problem('cache_error','缓存删除失败，请检查目录权限。'); }
    public function lock(string $key) { $f=fopen($this->path('lock:'.$key),'c'); if(!$f || !flock($f,LOCK_EX|LOCK_NB)) throw new Problem('busy','相同域名正在查询，请稍后重试。'); return $f; }
}
function registrable(string $host): string {
    static $rules=null;
    if ($rules===null) {
        $rules=[];
        foreach(file(__DIR__.'/../data/public_suffix_list.dat',FILE_IGNORE_NEW_LINES) as $line) {
            if(str_contains($line,'BEGIN PRIVATE DOMAINS')) break;
            $line=trim($line); if($line==='' || str_starts_with($line,'//')) continue;
            $mark=''; if($line[0]==='!') { $mark='!'; $line=substr($line,1); }
            $rules[$mark.(idn_to_ascii($line,IDNA_DEFAULT,INTL_IDNA_VARIANT_UTS46) ?: $line)]=true;
        }
    }
    $labels=explode('.',$host); $count=count($labels); $suffix=1;
    for($i=0;$i<$count;$i++) {
        $candidate=implode('.',array_slice($labels,$i));
        if(isset($rules['!'.$candidate])) { $suffix=$count-$i-1; break; }
        if(isset($rules[$candidate]) || ($i+1<$count && isset($rules['*.'.implode('.',array_slice($labels,$i+1))]))) $suffix=max($suffix,$count-$i);
    }
    if($count<=$suffix) throw new Problem('public_suffix','输入的是公共后缀，不是可注册域名。');
    return implode('.',array_slice($labels,-($suffix+1)));
}
function failure(\Throwable $e): array { return ['code'=>$e instanceof Problem?$e->kind:'internal_error','message'=>$e instanceof Problem?$e->getMessage():'服务器处理失败，请稍后重试。']; }
function rdap(string $host,Http $http): array {
    $registered=registrable($host); $tld=substr($registered,strrpos($registered,'.')+1);
    $bootstrap=json_decode((string)file_get_contents(__DIR__.'/../data/rdap-bootstrap.json'),true,512,JSON_THROW_ON_ERROR); $endpoint=null;
    foreach($bootstrap['services'] as [$tlds,$urls]) if(in_array($tld,$tlds,true)) { foreach($urls as $u) if(str_starts_with($u,'https://')) { $endpoint=$u; break; } break; }
    if(!$endpoint) throw new Problem('rdap_unsupported','此后缀没有可用的 HTTPS RDAP 服务。');
    $response=$http->get(rtrim($endpoint,'/').'/domain/'.rawurlencode($registered),2097152,18);
    if($response['status']!==200) throw new Problem('rdap_http_'.$response['status'],'RDAP 返回 HTTP '.$response['status'].($response['status']===404?'，未找到注册记录。':'，可能限流或服务不可用。'));
    $json=json_decode($response['body'],true);
    if(!is_array($json) || !isset($json['objectClassName']) || $json['objectClassName']!=='domain') throw new Problem('rdap_invalid','RDAP 返回无效的域名记录。');
    $created=null; foreach($json['events'] ?? [] as $event) if(($event['eventAction']??'')==='registration') { $created=$event['eventDate']??null; break; }
    return ['domain'=>$registered,'created_at'=>$created,'status'=>$json['status']??[],'source'=>$response['url'],'note'=>$created?null:'RDAP 记录未提供 registration 事件。'];
}
function metadata(string $html,string $contentType,string $url): array {
    if(preg_match('/charset\s*=\s*["\x27]?([a-z0-9_-]+)/i',$contentType,$m) || preg_match('/<meta[^>]+charset\s*=\s*["\x27]?([a-z0-9_-]+)/i',substr($html,0,8192),$m)) {
        try { $html=mb_convert_encoding($html,'UTF-8',$m[1]); } catch(\ValueError) { /* Parse using UTF-8 if unsupported. */ }
    }
    // Prevent a legacy charset meta tag from overriding the converted UTF-8 bytes.
    $html=preg_replace('/(<meta\b[^>]*charset\s*=\s*["\x27]?)[a-z0-9_-]+/i', '${1}UTF-8', $html) ?? $html;
    $doc=new \DOMDocument(); $old=libxml_use_internal_errors(true);
    try { $doc->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING); } finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
    $xp=new \DOMXPath($doc); $out=['title'=>null,'description'=>null,'keywords'=>null,'canonical'=>null,'robots'=>null,'googlebot'=>null,'lang'=>null,'h1'=>[]];
    $clean=static fn($s)=>mb_substr(trim(preg_replace('/\s+/u',' ',mb_convert_encoding($s,'UTF-8','UTF-8')) ?? ''),0,16000);
    $title=$doc->getElementsByTagName('title')->item(0); if($title) $out['title']=$clean($title->textContent);
    $out['lang']=$doc->getElementsByTagName('html')->item(0)?->getAttribute('lang') ?: null;
    foreach($doc->getElementsByTagName('meta') as $meta) { $name=strtolower(trim($meta->getAttribute('name'))); if(in_array($name,['description','keywords','robots','googlebot'],true) && $out[$name]===null) $out[$name]=$clean($meta->getAttribute('content')); }
    $base=$url; $baseNode=$doc->getElementsByTagName('base')->item(0);
    if($baseNode && $baseNode->hasAttribute('href')) { try { $base=resolveUrl($url,$baseNode->getAttribute('href')); } catch(Problem) {} }
    foreach($doc->getElementsByTagName('link') as $link) if(in_array('canonical',preg_split('/\s+/',strtolower(trim($link->getAttribute('rel')))),true)) { try { $out['canonical']=resolveUrl($base,$link->getAttribute('href')); } catch(Problem) { $out['canonical']=$clean($link->getAttribute('href')); } break; }
    foreach($doc->getElementsByTagName('h1') as $h) { if(count($out['h1'])>=10) break; $out['h1'][]=$clean($h->textContent); }
    return $out;
}
function seo(string $host,Http $http): array {
    $r=$http->get('https://'.$host.'/',2097152,22); $type=$r['headers']['content-type'][0]??'';
    $out=['url'=>$r['url'],'http_status'=>$r['status'],'redirects'=>$r['chain'],'content_type'=>$type,'x_robots_tag'=>$r['headers']['x-robots-tag']??[],'meta'=>null,'warnings'=>[]];
    if($r['status']>=400) $out['warnings'][]='页面返回错误状态；以下元数据可能来自错误页或反爬页面。';
    if(preg_match('~^(text/html|application/xhtml\+xml)(?:;|$)~i',$type)) $out['meta']=metadata($r['body'],$type,$r['url']);
    else $out['warnings'][]='响应未声明 HTML 类型，跳过元数据解析。';
    $p=parse_url($r['url']); $robotsUrl=$p['scheme'].'://'.$p['host'].'/robots.txt';
    try { $robots=$http->get($robotsUrl,262144,10); $out['robots_txt']=['url'=>$robots['url'],'http_status'=>$robots['status'],'preview'=>mb_substr(mb_convert_encoding($robots['body'],'UTF-8','UTF-8'),0,16000),'truncated'=>mb_strlen(mb_convert_encoding($robots['body'],'UTF-8','UTF-8'))>16000]; }
    catch(\Throwable $e) { $out['robots_txt']=['url'=>$robotsUrl,'error'=>failure($e)]; }
    return $out;
}
function analyzeCore(string $input,Cache $cache): array {
    $host=domain($input); $key='v3:'.$host; $hit=$cache->read($key);
    if($hit!==null) { $hit['cache']['hit']=true; return $hit; }
    $lock=$cache->lock($host);
    try {
        $hit=$cache->read($key); if($hit!==null) { $hit['cache']['hit']=true; return $hit; }
        $http=new Http(); $result=['domain'=>$host,'checked_at'=>gmdate('c')];
        try { $result['registration']=['data'=>rdap($host,$http),'error'=>null]; } catch(\Throwable $e) { $result['registration']=['data'=>null,'error'=>failure($e)]; }
        try { $result['seo']=['data'=>seo($host,$http),'error'=>null]; } catch(\Throwable $e) { $result['seo']=['data'=>null,'error'=>failure($e)]; }
        $result['checked_at']=gmdate('c');
        $ttl=($result['registration']['error'] || $result['seo']['error'])?300:3600;
        $result['cache']=['hit'=>false,'ttl_seconds'=>$ttl,'expires_at'=>gmdate('c',time()+$ttl)];
        $cache->write($key,$result,$ttl); return $result;
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}

/** Server-only configuration; local secrets stay outside the document root and Git. */
function trafficSettings(): array {
    $path=__DIR__.'/../config/traffic.local.php';
    $config=is_file($path) ? require $path : [];
    if(!is_array($config)) throw new Problem('traffic_config_error','流量配置文件格式不正确。');
    $configured=getenv('TRAFFIC_MAX_LOOKUPS');
    $limit=$configured===false ? ($config['max_lookups']??30) : (ctype_digit($configured)?(int)$configured:-1);
    $key=getenv('TABAPI_KEY') ?: ($config['tabapi_key']??null);
    if(($key!==null && !is_string($key)) || !is_int($limit) || $limit<0 || $limit>100000) throw new Problem('traffic_config_error','流量 Key 或调用上限配置无效。');
    return ['key'=>$key,'limit'=>$limit];
}

/** Clear the selected host's SEO and the shared root-domain traffic, without resetting budgets. */
function clearDomainCache(string $input,Cache $cache): array {
    $host=domain($input); $root=registrable($host); $settings=trafficSettings();
    $traffic=new TrafficService($cache,__DIR__.'/../var/state',$settings['key'],$settings['limit']);
    $trafficKey=$traffic->cacheKeyFor($root); $locks=[];
    try {
        $locks[]=$cache->lock($host);
        if($trafficKey!==null) $locks[]=$cache->lock($trafficKey);
        $cache->remove('v3:'.$host);
        if($trafficKey!==null) $cache->remove($trafficKey);
        return ['domain'=>$host,'traffic_domain'=>$root,'cache_cleared'=>true,'cleared_at'=>gmdate('c')];
    } finally { foreach(array_reverse($locks) as $lock) { flock($lock,LOCK_UN); fclose($lock); } }
}

/** Keep traffic separate so configuring a key takes effect even on a cached SEO result. */
function analyze(string $input,Cache $cache): array {
    $result=analyzeCore($input,$cache);
    try {
        $settings=trafficSettings();
        $traffic=new TrafficService($cache,__DIR__.'/../var/state',$settings['key'],$settings['limit']);
        $result['traffic']=$traffic->get($result['domain']);
    } catch(\Throwable $e) {
        $result['traffic']=['status'=>'error','provider'=>'TabAPI','estimated'=>true,'data'=>null,'error'=>failure($e)];
    }
    return $result;
}
