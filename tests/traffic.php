<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Core.php';
use DomainLens\{TrafficService,Cache,Problem};
$trafficChecks=0;
function tc(bool $ok,string $label): void {global $trafficChecks;if(!$ok)throw new RuntimeException('FAIL: '.$label);$trafficChecks++;}
function invalidTraffic(array $response,string $label): void {try{TrafficService::parse($response,'example.com');}catch(Problem){tc(true,$label);return;}tc(false,$label);}
function fixture(array $changes=[]): array {return ['status'=>200,'body'=>json_encode(array_replace_recursive(['domain'=>'example.com','overview'=>['month'=>'2026-08','visits'=>12290000],'monthly_visits'=>[['month'=>'2026-08','visits'=>12290000]]],$changes))];}
$success=TrafficService::parse(fixture(),'example.com');tc($success['data']['monthly_visits']===12290000,'12.29M retains original number');tc($success['data']['month']==='2026-08','reporting month');tc($success['estimated']===true && $success['provider']==='TabAPI','explicit estimate and actual provider');
$zero=TrafficService::parse(fixture(['overview'=>['visits'=>0]]),'example.com');tc($zero['status']==='ok'&&$zero['data']['monthly_visits']===0,'genuine zero');
$null=TrafficService::parse(fixture(['overview'=>['visits'=>null]]),'example.com');tc($null['status']==='no_data'&&$null['data']===null,'missing is not zero');
$missingMonth=TrafficService::parse(fixture(['overview'=>['month'=>null]]),'example.com');tc($missingMonth['status']==='no_data','no month not displayed as latest');
invalidTraffic(fixture(['domain'=>'evil.com']),'mismatched domain');invalidTraffic(fixture(['overview'=>['visits'=>'12290000']]),'reject numeric strings');invalidTraffic(fixture(['overview'=>['visits'=>-1]]),'reject negative visits');invalidTraffic(fixture(['overview'=>['month'=>'2026-13']]),'reject invalid month');invalidTraffic(fixture(['overview'=>['month'=>'9999-01']]),'reject future month');invalidTraffic(['status'=>200,'body'=>'<html>error</html>'],'reject non JSON');invalidTraffic(fixture(['monthly_visits'=>'wrong']),'reject malformed history');
foreach([401=>'traffic_auth_error',402=>'traffic_credits_exhausted',422=>'traffic_no_data',429=>'traffic_rate_limit',503=>'traffic_upstream_error'] as $status=>$code){$r=TrafficService::parse(['status'=>$status,'body'=>'secret key leaked by upstream'],'example.com');tc($r['error']['code']===$code,'HTTP '.$status);tc(!str_contains(json_encode($r),'secret key'),'no upstream body leak '.$status);}
$dir=sys_get_temp_dir().'/domain-lens-traffic-'.bin2hex(random_bytes(6));$cache=new Cache($dir.'/cache');$calls=0;$testKey='sk_test_not_a_real_key';
$fetch=function($url,$headers)use(&$calls,$testKey){$calls++;tc(str_starts_with($url,'https://tabapi.com/api/v1/domains/'),'fixed API origin');tc(str_ends_with($url,'/traffic?months=3'),'minimum paid history');tc($headers===['Authorization: Bearer '.$testKey],'server side bearer header');return fixture();};
$without=new TrafficService($cache,$dir.'/state',null,1,$fetch);tc($without->get('example.com')['status']==='not_configured'&&$calls===0,'no key makes zero calls');
$service=new TrafficService($cache,$dir.'/state',$testKey,1,$fetch);$first=$service->get('www.example.com');tc($first['status']==='ok'&&$calls===1,'query registrable root');tc($first['cache']['ttl_seconds']===604800,'7 day success cache');$second=$service->get('example.com');tc($second['cache']['hit']&&$calls===1,'subdomain shares cached root result');tc(!str_contains(json_encode($second),$testKey),'key never returned');
$limited=$service->get('example.net');tc($limited['status']==='limit_reached'&&$calls===1,'global attempt budget enforced before network');$restarted=new TrafficService($cache,$dir.'/state',$testKey,1,$fetch);tc($restarted->get('example.org')['status']==='limit_reached'&&$calls===1,'budget persists across requests');
$errorCalls=0;$errorKey='sk_test_failure_key';$errorFetch=function()use(&$errorCalls){$errorCalls++;return ['status'=>502,'body'=>'no'];};$errors=new TrafficService($cache,$dir.'/state',$errorKey,1,$errorFetch);$failed=$errors->get('example.com');tc($failed['status']==='error'&&$failed['cache']['ttl_seconds']===300,'error retry cache');$errors->get('example.com');tc($errorCalls===1,'error cache prevents repeated spend');tc($errors->get('example.net')['status']==='limit_reached','failed attempts conservatively keep reservation');
$badKey='sk_test_corrupt_key';file_put_contents($dir.'/state/tabapi-'.hash('sha256',$badKey).'.json','bad JSON');$corrupted=new TrafficService($cache,$dir.'/state',$badKey,30,$fetch);tc($corrupted->get('example.com')['error']['code']==='traffic_state_error'&&$calls===1,'corrupt budget fails closed');
$permissionKey='sk_test_permission_key';$lockPath=$dir.'/state/tabapi-'.hash('sha256',$permissionKey).'.json.lock';mkdir($lockPath);$warningOutput='';ob_start();$blocked=(new TrafficService($cache,$dir.'/state',$permissionKey,30,$fetch))->get('example.com');$warningOutput=ob_get_clean();rmdir($lockPath);tc($blocked['error']['code']==='traffic_state_error'&&$calls===1,'unopenable budget lock is write error and prevents upstream call');tc($warningOutput===''&&str_contains($blocked['error']['message'],'var/state'),'state error contains actionable path without warning output');
$busyKey='sk_test_busy_key';$busyPath=$dir.'/state/tabapi-'.hash('sha256',$busyKey).'.json.lock';$held=fopen($busyPath,'c');flock($held,LOCK_EX);$busyResult=(new TrafficService($cache,$dir.'/state',$busyKey,30,$fetch))->get('example.com');flock($held,LOCK_UN);fclose($held);tc($busyResult['error']['code']==='busy'&&$calls===1,'real lock contention remains busy and prevents upstream call');
$disabled=new TrafficService($cache,$dir.'/state','sk_test_disabled_key',0,$fetch);tc($disabled->get('example.com')['status']==='limit_reached'&&$calls===1,'admin can stop new calls');
$bad=new TrafficService($cache,$dir.'/state',"sk_test_\r\nInjected: yes",1,$fetch);tc($bad->get('example.com')['error']['code']==='traffic_key_invalid'&&$calls===1,'header injection rejected');
$long=DomainLens\metadata('<html><head><title>'.str_repeat('中',18000).'</title><meta name="description" content="'.str_repeat('文',18000).'"><meta name="keywords" content="'.str_repeat('词',18000).'"></head></html>','text/html; charset=UTF-8','https://example.com/');tc(mb_strlen($long['title'])===16000&&mb_strlen($long['description'])===16000&&mb_strlen($long['keywords'])===16000,'16k character safe field bounds');

$originalKey=getenv('TABAPI_KEY');
try {
    putenv('TABAPI_KEY='.$testKey);
    $cache->write('v3:example.com',['domain'=>'example.com','registration'=>[]],3600);
    $trafficLock=$cache->lock($service->cacheKeyFor('example.com'));
    try {DomainLens\clearDomainCache('example.com',$cache);tc(false,'clear respects live traffic lock');}
    catch(Problem $e){tc($e->kind==='busy'&&$cache->read('v3:example.com')!==null,'busy clear does not partially delete');}
    finally {flock($trafficLock,LOCK_UN);fclose($trafficLock);}
    $cleared=DomainLens\clearDomainCache('example.com',$cache);
    tc($cleared['cache_cleared']===true&&isset($cleared['cleared_at']),'clear returns timestamp');
    tc($cache->read('v3:example.com')===null&&$cache->read($service->cacheKeyFor('example.com'))===null,'clear removes SEO and traffic files');
    tc($service->get('example.com')['status']==='limit_reached'&&$calls===1,'clearing data does not reset budget');
    tc(DomainLens\clearDomainCache('example.com',$cache)['cache_cleared']===true,'clear is idempotent');
} finally {if($originalKey===false)putenv('TABAPI_KEY');else putenv('TABAPI_KEY='.$originalKey);}
$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($iterator as $item){$item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());}rmdir($dir);
echo "PASS: $trafficChecks traffic and long-content checks (no live credentials or network)\n";
