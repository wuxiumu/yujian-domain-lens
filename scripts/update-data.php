<?php
declare(strict_types=1);
require __DIR__.'/../src/Core.php';
$http=new DomainLens\Http();
foreach(['rdap-bootstrap.json'=>'https://data.iana.org/rdap/dns.json','public_suffix_list.dat'=>'https://publicsuffix.org/list/public_suffix_list.dat'] as $file=>$url) {
    $r=$http->get($url,2097152,25);
    if($r['status']!==200) throw new RuntimeException('Update failed: HTTP '.$r['status']);
    if($file==='rdap-bootstrap.json') { $d=json_decode($r['body'],true,512,JSON_THROW_ON_ERROR); if(empty($d['services'])) throw new RuntimeException('Invalid bootstrap'); }
    else if(!str_contains($r['body'],'BEGIN ICANN DOMAINS')) throw new RuntimeException('Invalid PSL');
    $path=__DIR__.'/../data/'.$file; $tmp=tempnam(dirname($path),'update-');
    file_put_contents($tmp,$r['body']); rename($tmp,$path); echo "Updated $file\n";
}
