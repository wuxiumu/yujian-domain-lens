<?php
declare(strict_types=1);
namespace DomainLens;

/** Optional official API integration. No key means no network request and no estimated number. */
final class TrafficService {
    public function __construct(
        private Cache $cache,
        private string $stateDir,
        private ?string $key = null,
        private int $maxLookups = 30,
        private ?\Closure $fetch = null,
    ) {}

    private function result(string $status, ?array $data = null, ?array $error = null): array {
        return ['status'=>$status,'provider'=>'TabAPI','estimated'=>true,'data'=>$data,'error'=>$error,
            'documentation_url'=>'https://tabapi.com/traffic','checked_at'=>gmdate('c')];
    }

    public function cacheKeyFor(string $host): ?string { return $this->key ? 'traffic:v1:'.hash('sha256',$this->key).':'.registrable(domain($host)) : null; }

    public function get(string $input): array {
        if (!$this->key) return $this->result('not_configured',null,['code'=>'traffic_not_configured','message'=>'尚未配置流量 API；可先通过 AITDK 免费扩展查询。']);
        try {
            if (!preg_match('/^sk_[A-Za-z0-9_-]{8,250}$/D',$this->key)) throw new Problem('traffic_key_invalid','流量 API Key 格式不正确，请检查服务器配置。');
            $host=registrable(domain($input));
            $fingerprint=hash('sha256',$this->key);
            $cacheKey=$this->cacheKeyFor($host);
            $hit=$this->cache->read($cacheKey);
            if ($hit!==null) { $hit['cache']['hit']=true; return $hit; }
            $lock=$this->cache->lock($cacheKey);
            try {
                $hit=$this->cache->read($cacheKey);
                if ($hit!==null) { $hit['cache']['hit']=true; return $hit; }
                $this->reserveLookup($fingerprint);
                try {
                    $url='https://tabapi.com/api/v1/domains/'.rawurlencode($host).'/traffic?months=3';
                    // Fixed provider origin; Http rejects any authenticated redirect before another hop.
                    $fetch=$this->fetch ?? static fn($url,$headers)=>(new Http())->get($url,1048576,12,$headers);
                    $response=$fetch($url,['Authorization: Bearer '.$this->key]);
                    $result=self::parse($response,$host);
                } catch (\Throwable $e) { $result=$this->result('error',null,failure($e)); }
                $ttl=match($result['status']) { 'ok'=>604800, 'no_data'=>86400, default=>300 };
                $result['cache']=['hit'=>false,'expires_at'=>gmdate('c',time()+$ttl),'ttl_seconds'=>$ttl];
                $this->cache->write($cacheKey,$result,$ttl);
                return $result;
            } finally { flock($lock,LOCK_UN); fclose($lock); }
        } catch (\Throwable $e) {
            return $this->result($e instanceof Problem && $e->kind==='traffic_local_limit'?'limit_reached':'error',null,failure($e));
        }
    }

    /** Permanent attempt budget per key; not cleared with expiring page cache. Reserve BEFORE sending. */
    private function reserveLookup(string $fingerprint): void {
        if ($this->maxLookups<=0) throw new Problem('traffic_local_limit','流量 API 调用已由管理员暂停。');
        if (!is_dir($this->stateDir) && !@mkdir($this->stateDir,0700,true) && !is_dir($this->stateDir)) throw new Problem('traffic_state_error','无法创建流量额度记录目录。');
        $path=$this->stateDir.'/tabapi-'.$fingerprint.'.json';
        $lock=@fopen($path.'.lock','c');
        if (!$lock) throw new Problem('traffic_state_error','流量额度目录不可写，请检查 var/state 及已有文件的 PHP 服务账号写权限；未请求流量 API。');
        if (!flock($lock,LOCK_EX|LOCK_NB)) { fclose($lock); throw new Problem('busy','流量额度记录正在更新，请稍后重试。'); }
        try {
            $record=is_file($path)?json_decode((string)@file_get_contents($path),true):['used'=>0];
            if (!is_array($record) || !isset($record['used']) || !is_int($record['used']) || $record['used']<0) throw new Problem('traffic_state_error','流量额度记录损坏，已停止调用，请管理员检查。');
            if ($record['used'] >= $this->maxLookups) throw new Problem('traffic_local_limit','已达到本站流量 API 调用上限；未继续请求，请管理员核对免费积分余额。');
            $record['used']++; $record['updated_at']=gmdate('c');
            $tmp=@tempnam($this->stateDir,'budget-');
            if (!$tmp || @file_put_contents($tmp,json_encode($record),LOCK_EX)===false || !@rename($tmp,$path)) throw new Problem('traffic_state_error','流量额度记录保存失败，已停止调用。');
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }

    public static function parse(array $response,string $expectedDomain): array {
        $base=['provider'=>'TabAPI','estimated'=>true,'documentation_url'=>'https://tabapi.com/traffic','checked_at'=>gmdate('c')];
        $status=$response['status'] ?? 0;
        if ($status!==200) {
            [$code,$message]=match($status) {
                401,403=>['traffic_auth_error','流量 API 认证失败，请管理员检查 Key 与权限。'],
                402=>['traffic_credits_exhausted','流量 API 积分不足；未自动购买或充值。'],
                404,422=>['traffic_no_data','服务商没有该域名足够的流量数据；不代表访问量为 0。'],
                429=>['traffic_rate_limit','流量服务商限流，请稍后再试。'],
                default=>['traffic_upstream_error','流量服务商暂时不可用（HTTP '.(int)$status.'）。'],
            };
            return $base+['status'=>in_array($status,[404,422],true)?'no_data':'error','data'=>null,'error'=>compact('code','message')];
        }
        $json=json_decode($response['body'] ?? '',true);
        if (!is_array($json) || !isset($json['domain']) || !is_string($json['domain']) || domain($json['domain'])!==$expectedDomain || !is_array($json['overview']??null)) throw new Problem('traffic_invalid_response','流量服务返回了无效数据或不匹配的域名。');
        $visits=$json['overview']['visits'] ?? null;
        $month=$json['overview']['month'] ?? null;
        if ($visits===null || $month===null) return $base+['status'=>'no_data','data'=>null,'error'=>['code'=>'traffic_no_data','message'=>'服务商未提供月访问量或统计月份；不代表访问量为 0。']];
        if (!self::validVisits($visits) || !self::validMonth($month)) throw new Problem('traffic_invalid_response','流量访问量或统计月份格式无效。');
        if(!is_array($json['monthly_visits'] ?? [])) throw new Problem('traffic_invalid_response','流量历史数据格式无效。');
        $history=[];
        foreach ($json['monthly_visits'] ?? [] as $entry) {
            if(count($history)>=3) break;
            if(is_array($entry) && self::validMonth($entry['month']??null) && self::validVisits($entry['visits']??null)) $history[]=['month'=>$entry['month'],'visits'=>$entry['visits']];
        }
        return $base+['status'=>'ok','data'=>['domain'=>$expectedDomain,'month'=>$month,'monthly_visits'=>$visits,'history'=>$history],'error'=>null];
    }

    private static function validVisits(mixed $visits): bool {
        return (is_int($visits) || is_float($visits)) && is_finite((float)$visits) && $visits>=0 && $visits<=PHP_INT_MAX;
    }
    private static function validMonth(mixed $month): bool {
        return is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$month)===1 && $month<=gmdate('Y-m');
    }
}
