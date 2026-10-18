<?php
declare(strict_types=1);
$removed=0;
// Never delete lock files: deleting an open lock would permit an independent lock.
foreach(glob(__DIR__.'/../var/cache/*.json') ?: [] as $path) {
    $f=@fopen($path,'r+'); if(!$f) continue;
    if(!flock($f,LOCK_EX|LOCK_NB)) { fclose($f); continue; }
    $record=json_decode(stream_get_contents($f),true);
    if(is_array($record) && isset($record['expires']) && $record['expires']<time()-60) {
        $stat=fstat($f);clearstatcache(true,$path);$now=@stat($path);
        if($now && $stat['ino']===$now['ino'] && @unlink($path)) $removed++;
    }
    flock($f,LOCK_UN);fclose($f);
}
echo "Removed $removed expired cache files. Lock files retained.\n";
