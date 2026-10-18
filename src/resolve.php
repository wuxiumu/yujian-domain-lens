<?php
// Called only as a CLI child, never served by the web document root.
declare(strict_types=1);
if(PHP_SAPI!=='cli' || !isset($argv[1])) exit(1);
$records=@dns_get_record($argv[1],DNS_A|DNS_AAAA);
$ips=[];
foreach($records ?: [] as $record) {
    if(isset($record['ip'])) $ips[]=$record['ip'];
    if(isset($record['ipv6'])) $ips[]=$record['ipv6'];
}
// Prefer IPv4 where available, while still validating all IPv6 answers in the parent.
usort($ips,static fn($a,$b)=>(int)str_contains($a,':')<=>(int)str_contains($b,':'));
echo json_encode(array_values(array_unique($ips)));
