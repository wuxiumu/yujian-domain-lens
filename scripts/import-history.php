<?php
declare(strict_types=1);
// Import only existing public cached metadata. No DNS, HTTP, API key or traffic credits are used.
require __DIR__.'/../src/Core.php';
require __DIR__.'/../src/History.php';
$records=[];$traffic=[];
foreach(glob(__DIR__.'/../var/cache/*.json')?:[] as $path){
    if(filesize($path)>4194304)continue;
    $record=json_decode((string)file_get_contents($path),true)['value']??null;
    if(!is_array($record))continue;
    if(isset($record['domain'],$record['registration'],$record['seo']))$records[]=$record;
    elseif(($record['provider']??null)==='TabAPI' && isset($record['data']['domain'],$record['data']['monthly_visits'])) {
        $host=$record['data']['domain'];
        if(!isset($traffic[$host]) || strcmp($record['checked_at']??'',$traffic[$host]['checked_at']??'')>0)$traffic[$host]=$record;
    }
}
usort($records,static fn($a,$b)=>strcmp($a['checked_at']??'',$b['checked_at']??''));
$store=new DomainLens\HistoryStore(__DIR__.'/../var/history');$count=0;$failed=0;
foreach($records as $result){
    try{
        $host=DomainLens\domain($result['domain']);
        try{$root=DomainLens\registrable($host);}catch(DomainLens\Problem){$root=$host;}
        if(isset($traffic[$root]))$result['traffic']=$traffic[$root];
        $store->record($result,false);$count++;
    }catch(Throwable){$failed++;}
}
echo "Imported $count retained cache records; skipped $failed invalid records. No upstream calls.\n";
