<?php
declare(strict_types=1);
namespace DomainLens;

/** File-backed public reports. Reads never fetch remote data or consume traffic credits. */
final class HistoryStore {
    public function __construct(private string $directory) {}

    private function index(): array {
        $path=$this->directory.'/index.json';
        if(!is_file($path)) return ['schema'=>1,'items'=>[]];
        $index=$this->read($path,33554432);
        if(($index['schema']??null)!==1 || !is_array($index['items']??null)) throw new Problem('history_corrupt','历史索引格式无效，请管理员检查备份。');
        return $index;
    }
    private function read(string $path,int $limit=2097152): array {
        $raw=@file_get_contents($path,false,null,0,$limit+1);
        $data=$raw!==false && strlen($raw)<=$limit ? json_decode($raw,true) : null;
        if(!is_array($data)) throw new Problem('history_corrupt','历史记录无法读取或格式损坏。');
        return $data;
    }
    private function write(string $path,array $data): void {
        if(!is_dir(dirname($path)) && !@mkdir(dirname($path),0700,true) && !is_dir(dirname($path))) throw new Problem('history_write_error','无法创建历史报告目录。');
        $raw=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
        $temp=@tempnam(dirname($path),'write-');
        if(!$temp || @file_put_contents($temp,$raw,LOCK_EX)===false || !@rename($temp,$path)) throw new Problem('history_write_error','历史报告保存失败，请检查目录写权限。');
    }
    private function folder(string $host): string { return $this->directory.'/reports/'.hash('sha256',$host); }
    public static function path(string $host,?string $snapshot=null): string {
        return '/domain/'.rawurlencode(domain($host)).($snapshot!==null?'/'.$snapshot:'');
    }
    public static function dataUpdatedAt(array $result): string {
        $times=[$result['checked_at']??''];
        if(isset($result['traffic']['data'])) $times[]=$result['traffic']['checked_at']??'';
        $valid=[];
        foreach($times as $time) if(is_string($time) && strtotime($time)!==false) $valid[]=strtotime($time);
        return gmdate('c',$valid?max($valid):time());
    }
    private static function publicResult(array $result,string $host): array {
        $copy=['domain'=>$host,'checked_at'=>$result['checked_at']??gmdate('c'),
            'registration'=>$result['registration']??['data'=>null,'error'=>null],
            'seo'=>$result['seo']??['data'=>null,'error'=>null],
            'traffic'=>$result['traffic']??['status'=>'not_configured','data'=>null,'error'=>null]];
        // Runtime cache flags and search timestamps must not manufacture new versions on cache hits.
        unset($copy['registration']['cache'],$copy['seo']['cache'],$copy['traffic']['cache']);
        if(!isset($copy['traffic']['data'])) unset($copy['traffic']['checked_at']);
        return $copy;
    }
    public function record(array $result,bool $isSearch=true): array {
        $host=domain($result['domain']??'');
        if(!is_string($result['checked_at']??null) || strtotime($result['checked_at'])===false) throw new Problem('history_invalid_result','查询结果缺少有效更新时间，未保存历史。');
        $payload=self::publicResult($result,$host);
        $id=substr(hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)),0,24);
        if(!is_dir($this->directory) && !@mkdir($this->directory,0700,true) && !is_dir($this->directory)) throw new Problem('history_write_error','无法创建历史记录目录。');
        $lock=@fopen($this->directory.'/history.lock','c');
        if(!$lock)throw new Problem('history_write_error','历史目录不可写，请检查 var/history 的服务账号写权限。');
        if(!flock($lock,LOCK_EX|LOCK_NB)) {fclose($lock);throw new Problem('history_busy','历史记录正在更新，请稍后重试。');}
        try {
            $index=$this->index(); $previous=$index['items'][$host]??null;
            $folder=$this->folder($host); $path=$folder.'/'.$id.'.json'; $updated=self::dataUpdatedAt($payload);
            $now=gmdate('c'); $searched=$isSearch?$now:$updated;
            $savedAt=is_file($path)?($this->read($path)['saved_at']??$now):$now;
            if(!is_file($path)) {
                $this->write($path,['schema'=>1,'domain'=>$host,'id'=>$id,'saved_at'=>$now,'data_updated_at'=>$updated,'origin'=>$isSearch?'search':'cache_import','result'=>$payload]);
                $manifestPath=$folder.'/versions.json';$manifest=is_file($manifestPath)?$this->read($manifestPath):['items'=>[]];
                $manifest['items'][$id]=['id'=>$id,'saved_at'=>$now,'data_updated_at'=>$updated];
                $this->write($manifestPath,$manifest);
            }
            $isLatest=$previous===null || strcmp($updated,$previous['data_updated_at'])>=0;
            if($isLatest) {
                $meta=$payload['seo']['data']['meta']??[];$traffic=$payload['traffic']['data']??null;
                $summary=['domain'=>$host,'display_domain'=>idn_to_utf8($host,IDNA_DEFAULT,INTL_IDNA_VARIANT_UTS46)?:$host,
                    'search_text'=>($meta['title']??'').' '.($meta['description']??'').' '.($meta['keywords']??''),
                    'title'=>mb_substr($meta['title']??'',0,200),'description'=>mb_substr($meta['description']??'',0,320),'keywords'=>mb_substr($meta['keywords']??'',0,500),
                    'created_at'=>$payload['registration']['data']['created_at']??null,'http_status'=>$payload['seo']['data']['http_status']??null,
                    'monthly_visits'=>$traffic['monthly_visits']??null,'traffic_month'=>$traffic['month']??null,
                    'data_updated_at'=>$updated,'latest'=>$id,'indexable'=>self::indexable($payload)];
            } else $summary=$previous;
            $summary['first_searched_at']=min($previous['first_searched_at']??$searched,$searched);
            $summary['last_searched_at']=max($previous['last_searched_at']??$searched,$searched);
            $summary['search_count']=($previous['search_count']??0)+($isSearch?1:0);
            $index['items'][$host]=$summary;$this->write($this->directory.'/index.json',$index);
            return ['url'=>self::path($host),'snapshot_url'=>self::path($host,$id),'snapshot_id'=>$id,'saved_at'=>$savedAt];
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function indexable(array $result): bool {
        return !empty($result['registration']['data']['created_at']) || isset($result['traffic']['data']['monthly_visits']) ||
            (($result['seo']['data']['http_status']??0)>=200 && ($result['seo']['data']['http_status']??999)<300 && !empty($result['seo']['data']['meta']['title']));
    }
    public function report(string $input,?string $snapshot=null): ?array {
        $host=domain($input);
        if($snapshot===null) { $snapshot=$this->index()['items'][$host]['latest']??null;if($snapshot===null)return null; }
        if(preg_match('/^[a-f0-9]{24}$/D',$snapshot)!==1) throw new Problem('invalid_snapshot','历史版本标识无效。');
        $path=$this->folder($host).'/'.$snapshot.'.json';if(!is_file($path))return null;
        $report=$this->read($path);
        if(($report['domain']??null)!==$host || ($report['id']??null)!==$snapshot || !is_array($report['result']??null)) throw new Problem('history_corrupt','历史报告与域名不匹配。');
        return $report;
    }
    public function versions(string $input,int $limit=8): array {
        $path=$this->folder(domain($input)).'/versions.json';if(!is_file($path))return [];
        $items=array_values($this->read($path)['items']??[]);
        usort($items,static fn($a,$b)=>strcmp($b['data_updated_at'],$a['data_updated_at'])?:strcmp($b['id'],$a['id']));
        return array_slice($items,0,max(1,min(50,$limit)));
    }
    public function search(string $query='',int $page=1,int $pageSize=20): array {
        if($page<1 || $pageSize<1 || $pageSize>100)throw new Problem('invalid_page','分页参数无效。');
        if(!mb_check_encoding($query,'UTF-8') || mb_strlen($query)>100)throw new Problem('invalid_search','搜索词最多 100 个字符。');
        $query=trim($query);$items=array_values($this->index()['items']);
        if($query!=='')$items=array_values(array_filter($items,static fn($v)=>mb_stripos($v['domain'].' '.$v['display_domain'].' '.($v['search_text']??($v['title'].' '.$v['description'].' '.$v['keywords'])),$query)!==false));
        usort($items,static fn($a,$b)=>strcmp($b['last_searched_at'],$a['last_searched_at'])?:strcmp($a['domain'],$b['domain']));
        $total=count($items);$pages=max(1,(int)ceil($total/$pageSize));
        return ['query'=>$query,'page'=>$page,'page_size'=>$pageSize,'total'=>$total,'pages'=>$pages,'items'=>array_slice($items,($page-1)*$pageSize,$pageSize)];
    }
    public function sitemapPage(int $page,int $size=1000): array {
        $items=array_values(array_filter($this->index()['items'],static fn($r)=>$r['indexable']??false));
        usort($items,static fn($a,$b)=>strcmp($a['domain'],$b['domain']));
        return ['pages'=>max(1,(int)ceil(count($items)/$size)),'items'=>array_slice($items,($page-1)*$size,$size)];
    }
}
