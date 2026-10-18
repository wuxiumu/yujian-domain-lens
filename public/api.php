<?php
declare(strict_types=1);
// Keep PHP diagnostics in server logs rather than corrupting JSON responses.
ini_set('display_errors','0');
ini_set('log_errors','1');
require __DIR__.'/../src/Core.php';
require __DIR__.'/../src/History.php';
use DomainLens\{Cache,Problem};
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function respond(array $body,int $status=200): never { http_response_code($status); echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE); exit; }
try {
    foreach(['curl','dom','intl','mbstring','json'] as $extension) if(!extension_loaded($extension)) respond(['error'=>['code'=>'missing_extension','message'=>'PHP 缺少必需扩展：'.$extension]],503);
    if($_SERVER['REQUEST_METHOD']!=='POST') { header('Allow: POST'); respond(['error'=>['code'=>'method_not_allowed','message'=>'请使用 POST JSON 请求。']],405); }
    if(!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json')) respond(['error'=>['code'=>'content_type','message'=>'仅接受 application/json。']],415);
    // Same-origin requests only. Requests without Origin remain usable by CLI clients.
    if(isset($_SERVER['HTTP_ORIGIN'])) {
        $scheme=(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off')?'https':'http';
        if($_SERVER['HTTP_ORIGIN']!==$scheme.'://'.($_SERVER['HTTP_HOST']??'')) respond(['error'=>['code'=>'origin_denied','message'=>'禁止跨站调用。']],403);
    }
    $body=file_get_contents('php://input',false,null,0,65537);
    if(strlen($body)>65536) respond(['error'=>['code'=>'body_too_large','message'=>'请求体不能超过 64 KiB。']],413);
    $json=json_decode($body,true,32,JSON_THROW_ON_ERROR);
    $action=is_array($json)?($json['action']??'analyze'):'analyze';
    if(!in_array($action,['analyze','refresh','clear_cache'],true)) respond(['error'=>['code'=>'invalid_action','message'=>'仅支持 analyze、refresh 和 clear_cache 操作。']],422);
    $input=is_array($json)?($json['domains']??null):null;
    if(is_string($input)) $input=preg_split('/[\r\n,，]+/u',$input,-1,PREG_SPLIT_NO_EMPTY);
    if(!is_array($input) || !array_is_list($input) || count($input)<1 || count($input)>10) respond(['error'=>['code'=>'batch_limit','message'=>'接口每次接受 1–10 个域名；前端会自动排队，无总数量限制。']],422);
    foreach($input as $s) if(!is_string($s) || strlen($s)>1000) respond(['error'=>['code'=>'invalid_input','message'=>'每项必须是长度不超过 1000 字节的字符串。']],422);
    $cache=new Cache(__DIR__.'/../var/cache');
    // File-backed rolling-minute rate limit; trust REMOTE_ADDR only, never forwarded headers.
    $rateKey='rate:'.($_SERVER['REMOTE_ADDR']??'unknown'); $rateLock=$cache->lock($rateKey);
    try { $rate=$cache->read($rateKey)??['start'=>time(),'count'=>0]; if(time()-$rate['start']>=60) $rate=['start'=>time(),'count'=>0];
        if($rate['count']+count($input)>60) { header('Retry-After: 60'); respond(['error'=>['code'=>'rate_limit','message'=>'每 IP 每分钟最多查询 60 个域名，请一分钟后重试。']],429); }
        $rate['count']+=count($input); $cache->write($rateKey,$rate,60);
    } finally { flock($rateLock,LOCK_UN); fclose($rateLock); }
    set_time_limit(900); $results=[];
    foreach($input as $s) {
        try {
            if($action==='clear_cache') $results[]=DomainLens\clearDomainCache($s,$cache);
            else {
                if($action==='refresh') DomainLens\clearDomainCache($s,$cache);
                $result=DomainLens\analyze($s,$cache);
                try { $result['history']=(new DomainLens\HistoryStore(__DIR__.'/../var/history'))->record($result); }
                catch(Throwable $e) { $result['history']=['error'=>DomainLens\failure($e)]; }
                $results[]=$result;
            }
        } catch(Throwable $e) { $results[]=['input'=>$s,'error'=>DomainLens\failure($e)]; }
    }
    respond(['results'=>$results]);
} catch(JsonException) { respond(['error'=>['code'=>'invalid_json','message'=>'JSON 格式无效。']],400); }
catch(Throwable $e) { respond(['error'=>DomainLens\failure($e)],$e instanceof Problem && $e->kind==='busy'?429:500); }
