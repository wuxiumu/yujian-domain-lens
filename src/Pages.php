<?php
declare(strict_types=1);
namespace DomainLens;

final class PublicPages {
    public static function baseUrl(): string {
        $path=__DIR__.'/../config/site.local.php';$config=is_file($path)?require $path:[];
        if(!is_array($config))throw new Problem('site_config_error','站点配置格式无效。');
        $url=getenv('APP_BASE_URL')?:($config['base_url']??'https://yujian.chiguashentan.com');
        $parts=is_string($url)?parse_url($url):false;
        if(!$parts || !in_array($parts['scheme']??'', ['https','http'],true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path']??'',['','/'],true)) throw new Problem('site_config_error','请配置不带路径、账号或查询串的站点根地址。');
        return rtrim($url,'/');
    }
    public static function h(mixed $value): string {return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function time(mixed $value): string {
        if(!is_string($value)||$value==='')return '未提供';
        try{return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s');}catch(\Exception){return '未提供';}
    }
    private static function visits(mixed $value): string {
        if(!is_numeric($value))return '暂无数据';
        return number_format((float)$value,0,'.',',');
    }
    private static function emit(string $body,string $type='text/html; charset=utf-8'): void {
        header('Content-Type: '.$type);header('Cache-Control: no-cache');header('X-Content-Type-Options: nosniff');
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $body;
    }
    private static function breadcrumb(array $items): string {
        $html='<nav class="breadcrumbs" aria-label="面包屑" itemscope itemtype="https://schema.org/BreadcrumbList"><ol>';
        foreach($items as $i=>[$name,$url])$html.='<li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem"><a itemprop="item" href="'.self::h($url).'"><span itemprop="name">'.self::h($name).'</span></a><meta itemprop="position" content="'.($i+1).'"></li>';
        return $html.'</ol></nav>';
    }
    private static function page(string $title,string $description,string $canonical,string $body,string $robots='index, follow',?string $modified=null,?string $actualPath=null): void {
        $base=self::baseUrl();$url=$base.$canonical;$actual=$base.($actualPath??$canonical);$h=self::h(...);
        header('X-Robots-Tag: '.$robots);
        $html='<!doctype html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.$h($title).'</title><meta name="description" content="'.$h($description).'"><meta name="robots" content="'.$h($robots).'"><link rel="canonical" href="'.$h($url).'"><meta property="og:type" content="website"><meta property="og:locale" content="zh_CN"><meta property="og:site_name" content="域见 Domain Lens"><meta property="og:title" content="'.$h($title).'"><meta property="og:description" content="'.$h($description).'"><meta property="og:url" content="'.$h($actual).'"><meta name="twitter:card" content="summary"><meta name="twitter:title" content="'.$h($title).'"><meta name="twitter:description" content="'.$h($description).'"><link rel="stylesheet" href="/assets/style.css?v=5"><script src="/assets/pages.js?v=1" defer></script></head><body><header><a class="brand" href="/"><span class="logo">◉</span> 域见 <small>DOMAIN LENS</small></a><nav class="site-nav" aria-label="主导航"><a href="/">域名分析</a><a href="/history">查询历史</a></nav><span class="local">公开数据 · 文件存档</span></header><main class="public-page" itemscope itemtype="https://schema.org/WebPage"><meta itemprop="name" content="'.$h($title).'"><meta itemprop="description" content="'.$h($description).'"><meta itemprop="inLanguage" content="zh-CN"><link itemprop="url" href="'.$h($actual).'">';
        if($modified!==null)$html.='<meta itemprop="dateModified" content="'.$h($modified).'">';
        $html.=$body.'</main><footer><span>◉ 域见 / DOMAIN LENS</span><span>查询及更新时间为北京时间 · 注册日期按 RDAP 原始记录</span></footer></body></html>';
        self::emit($html);
    }
    public static function error(int $status,string $message): void {
        http_response_code($status);
        self::page($status.' · 域见','域名分析报告与历史查询。','/', '<section class="public-empty"><div class="eyebrow">DOMAIN LENS / '.$status.'</div><h1>'.self::h($message).'</h1><p>可返回首页查询域名，或从查询历史中查找已保存的报告。</p><a class="button-link" href="/history">查看历史</a> <a href="/">返回首页 ↗</a></section>','noindex, follow');
    }
    private static function field(string $name,mixed $value,bool $link=false): string {
        if(is_array($value))$value=implode("\n",array_map(static fn($v)=>is_scalar($v)?(string)$v:'',$value));
        $text=$value===null||$value===''?'未提供':(string)$value;$display=self::h($text);
        if($link && is_string($value) && strlen($value)<=4096) {
            $p=parse_url($value);
            if($p && in_array(strtolower($p['scheme']??''),['http','https'],true) && !isset($p['user']) && !isset($p['pass']) && isset($p['host'])) {
                try{domain($p['host']);$display='<a href="'.self::h($value).'" target="_blank" rel="nofollow noopener noreferrer">'.$display.'</a>';}catch(Problem){}
            }
        }
        return '<div class="report-field"><dt>'.self::h($name).'</dt><dd>'.$display.'</dd></div>';
    }
    private static function historyUrl(string $q,int $page): string {
        $params=[];if($q!=='')$params['q']=$q;if($page>1)$params['page']=$page;
        return '/history'.($params?'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986):'');
    }
    private static function history(HistoryStore $store): void {
        $q=$_GET['q']??'';$page=$_GET['page']??'1';
        if(!is_string($q)||!is_string($page)||!preg_match('/^[1-9][0-9]{0,5}$/D',$page)) {self::error(400,'搜索或分页参数无效');return;}
        $data=$store->search($q,(int)$page);$q=$data['query'];
        if($data['page']>$data['pages']){self::error(404,'这一页不存在');return;}
        $title=($q!==''?'“'.mb_substr($q,0,30).'”的查询历史':'域名查询历史').($data['page']>1?' · 第 '.$data['page'].' 页':'').' - 域见';
        $desc='浏览已保存的域名分析报告，按域名、页面标题、描述或关键词搜索；提供注册日期、SEO 与月访问量记录。';
        $body=self::breadcrumb([['首页','/'],['查询历史','/history']]).'<section class="public-heading"><div><div class="eyebrow">SAVED DOMAIN REPORTS</div><h1>查询历史<span>'.number_format($data['total']).'</span></h1><p>每个域名保留最新报告与历史快照。浏览记录不重新请求上游数据。</p></div><a class="button-link" href="/">+ 分析新域名</a></section><form class="history-search" method="get" action="/history"><label for="history-query">搜索历史结果</label><div><input id="history-query" name="q" maxlength="100" value="'.self::h($q).'" placeholder="搜索域名、Title、Description 或 Keywords"><button class="primary" type="submit">搜索 ↗</button>'.($q!==''?'<a href="/history">清除筛选</a>':'').'</div></form>';
        if(!$data['items'])$body.='<section class="public-empty"><span>⌕</span><h2>'.($q!==''?'没有找到匹配的历史结果':'还没有保存的域名报告').'</h2><p>完成一次域名查询后，报告会自动保存在这里。</p><a href="/">开始分析 ↗</a></section>';
        else {
            $body.='<div class="history-list">';
            foreach($data['items'] as $item) {
                $path=HistoryStore::path($item['domain']);$stats='HTTP '.($item['http_status']??'—').' · 注册 '.(!empty($item['created_at'])?substr($item['created_at'],0,10):'未提供');
                $trafficNote=$item['traffic_month']?($item['traffic_month'].' · 第三方估算'):'';
                if($item['monthly_visits']===null) {
                    // Read the saved snapshot so old indexes also expose the original failure. Never query upstream here.
                    $savedTraffic=$store->report($item['domain'],$item['latest'])['result']['traffic']??[];
                    $trafficNote=$savedTraffic['error']['message']??match($savedTraffic['status']??'') {
                        'not_configured'=>'该历史记录未包含流量数据；请检查服务器 API Key 配置或导入已有流量缓存。',
                        'no_data'=>'服务商未提供该域名的流量数据，不代表访问量为 0。',
                        'limit_reached'=>'查询时已达到本站流量 API 调用上限。',
                        default=>'该历史记录未保存月访问量；可导入已有流量缓存或重新查询。',
                    };
                }
                $body.='<article class="history-card"><div class="history-domain"><a href="'.self::h($path).'"><h2>'.self::h($item['domain']).'</h2></a><p>'.self::h($stats).'</p><small>'.(($item['search_count']??0)>0?'最近查询':'缓存记录时间').' <time datetime="'.self::h($item['last_searched_at']).'">'.self::time($item['last_searched_at']).'</time></small></div><div class="history-snippet"><a href="'.self::h($path).'">'.self::h($item['title']?:'域名分析报告').'</a><p>'.self::h($item['description']?:'此记录未提供页面描述，展开报告查看注册信息与查询状态。').'</p><small>数据更新 '.self::time($item['data_updated_at']).'</small></div><div class="history-traffic"><span>Monthly Visits</span><strong>'.self::visits($item['monthly_visits']).'</strong><small>'.self::h($trafficNote).'</small><a href="'.self::h($path).'">查看报告 ↗</a></div></article>';
            }
            $body.='</div>';
        }
        if($data['pages']>1) {
            $body.='<nav class="pagination" aria-label="历史结果分页">';
            if($data['page']>1)$body.='<a rel="prev" href="'.self::h(self::historyUrl($q,$data['page']-1)).'">← 上一页</a>';
            $start=max(1,$data['page']-3);$end=min($data['pages'],$data['page']+3);
            if($start>1)$body.='<a href="'.self::h(self::historyUrl($q,1)).'">1</a><span>…</span>';
            for($n=$start;$n<=$end;$n++)$body.=$n===$data['page']?'<span aria-current="page">'.$n.'</span>':'<a href="'.self::h(self::historyUrl($q,$n)).'">'.$n.'</a>';
            if($end<$data['pages'])$body.='<span>…</span><a href="'.self::h(self::historyUrl($q,$data['pages'])).'">'.$data['pages'].'</a>';
            if($data['page']<$data['pages'])$body.='<a rel="next" href="'.self::h(self::historyUrl($q,$data['page']+1)).'">下一页 →</a>';
            $body.='</nav>';
        }
        $body.='<p class="history-footnote">第 '.$data['page'].' / '.$data['pages'].' 页 · 每页 '.$data['page_size'].' 个域名 · 时间为北京时间。查询缓存过期或被清除，不会删除这些历史报告。</p>';
        self::page($title,$desc,self::historyUrl($q,$data['page']),$body,$q!==''||$data['total']===0?'noindex, follow':'index, follow');
    }
    private static function report(HistoryStore $store,string $host,?string $snapshot): void {
        $report=$store->report($host,$snapshot);if($report===null){self::error(404,'尚未保存这个域名的报告');return;}
        $r=$report['result'];$reg=$r['registration']['data']??null;$seo=$r['seo']['data']??null;$meta=$seo['meta']??[];$traffic=$r['traffic']['data']??null;
        $path=HistoryStore::path($host);$snapshotPath=HistoryStore::path($host,$report['id']);$h=self::h(...);
        $title=$host.' 域名分析与 SEO 报告'.($snapshot!==null?' · 历史快照':'').' - 域见';
        $description='查看 '.$host.' 的域名注册日期、HTTP 状态、Title、Description、Keywords、canonical 与 robots 信息。';
        if($reg&&$reg['created_at'])$description.='注册日期 '.substr($reg['created_at'],0,10).'。';
        if($traffic)$description.=$traffic['month'].' 月访问量估算 '.self::visits($traffic['monthly_visits']).'。';
        $body=self::breadcrumb([['首页','/'],['查询历史','/history'],[$host,$path]]).'<section class="public-heading"><div><div class="eyebrow">'.($snapshot!==null?'HISTORICAL SNAPSHOT':'DOMAIN REPORT').'</div><h1>'.$h($host).'</h1><p>'.($snapshot!==null?'正在浏览固定历史版本。':'已保存的域名分析结果。').' 数据更新 <time datetime="'.$h($report['data_updated_at']).'">'.self::time($report['data_updated_at']).'</time>（北京时间）</p></div><div class="report-actions"><button class="primary" data-share-url="'.$h(self::baseUrl().$snapshotPath).'">分享本次快照 ↗</button><button class="secondary" data-share-url="'.$h(self::baseUrl().$path).'">复制固定链接</button><a href="/?domain='.rawurlencode($host).'#domains">重新分析 ↗</a></div></section>';
        if($snapshot!==null)$body.='<p class="report-notice">历史快照不会自动更新。<a href="'.$h($path).'">查看此域名最新保存的报告 →</a></p>';
        $body.='<section class="report-stats" aria-label="域名核心数据"><article><span>域名注册日期</span><strong>'.($reg&&$reg['created_at']?$h(substr($reg['created_at'],0,10)):'未提供').'</strong><small>'.$h($reg['domain']??($r['registration']['error']['message']??'RDAP 未提供注册日期')).'</small></article><article><span>Monthly Visits · 月访问量</span><strong>'.self::visits($traffic['monthly_visits']??null).'</strong><small>'.($traffic?$h($traffic['month']).' · TabAPI 第三方估算':$h($r['traffic']['error']['message']??'尚无流量数据')).'</small></article><article><span>首页 HTTP 状态</span><strong>'.$h($seo['http_status']??'未提供').'</strong><small>'.$h($r['seo']['error']['message']??$seo['content_type']??'未提供').'</small></article></section><div class="report-grid"><section class="report-panel"><h2>页面 SEO 内容</h2><p class="panel-subtitle">公开 HTML 元数据 · 页面抓取 '.self::time($r['checked_at']??null).'</p><dl>';
        foreach([['Title','title'],['Description','description'],['Keywords','keywords'],['canonical','canonical'],['Meta robots','robots'],['Googlebot','googlebot'],['语言','lang'],['H1','h1']] as [$name,$key])$body.=self::field($name,$meta[$key]??null,$key==='canonical');
        $body.=self::field('X-Robots-Tag',$seo['x_robots_tag']??null).self::field('最终页面',$seo['url']??null,true).self::field('页面提示',$seo['warnings']??null).'</dl>';
        if(!empty($seo['robots_txt'])) {$rob=$seo['robots_txt'];$body.='<details class="report-robots"><summary>robots.txt · '.$h($rob['http_status']??'查询失败').'</summary><p>'.$h($rob['error']['message']??$rob['url']??'').'</p><pre>'.$h($rob['preview']??'未提供内容').'</pre></details>';}
        $body.='</section><aside class="report-aside"><section class="report-panel"><h2>数据来源与时间</h2><dl>'.self::field('RDAP 来源',$reg['source']??null,true).self::field('注册查询状态',$r['registration']['error']['message']??$reg['note']??'查询完成').self::field('流量来源',$r['traffic']['provider']??'未提供').self::field('流量抓取时间',isset($traffic)?self::time($r['traffic']['checked_at']??null):'未提供').self::field('快照保存时间',self::time($report['saved_at'])).'</dl><p class="report-disclaimer">月访问量为第三方估算，不等于网站后台数据；统计月份与查询时间分别展示。打开本页不调用流量接口。</p></section><section class="report-panel"><h2>历史版本</h2><ul class="version-list">';
        foreach($store->versions($host) as $version)$body.='<li><a href="'.$h(HistoryStore::path($host,$version['id'])).'">'.self::time($version['data_updated_at']).'</a>'.($version['id']===$report['id']?'<small>当前版本</small>':'').'</li>';
        $body.='</ul><a class="report-more" href="/history?q='.rawurlencode($host).'">在历史列表中查找 ↗</a></section></aside></div>';
        self::page($title,$description,$path,$body,$snapshot!==null||!HistoryStore::indexable($r)?'noindex, follow':'index, follow',$report['data_updated_at'],$snapshot!==null?$snapshotPath:$path);
    }
    private static function sitemap(HistoryStore $store,?int $number=null): void {
        $base=self::baseUrl();$data=$store->sitemapPage($number??1);$esc=static fn($s)=>htmlspecialchars($s,ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        if($number!==null&&($number<1||$number>$data['pages'])){self::error(404,'站点地图分片不存在');return;}
        $xml='<?xml version="1.0" encoding="UTF-8"?>';
        if($number===null && $data['pages']>1){$xml.='<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';for($i=1;$i<=$data['pages'];$i++)$xml.='<sitemap><loc>'.$esc($base.'/sitemaps/'.$i.'.xml').'</loc></sitemap>';$xml.='</sitemapindex>';}
        else {$xml.='<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';if($number===null||$number===1){$xml.='<url><loc>'.$esc($base.'/').'</loc></url>';if($data['items'])$xml.='<url><loc>'.$esc($base.'/history').'</loc></url>';}
            foreach($data['items'] as $row)$xml.='<url><loc>'.$esc($base.HistoryStore::path($row['domain'])).'</loc><lastmod>'.$esc($row['data_updated_at']).'</lastmod></url>';
            $xml.='</urlset>';
        }
        self::emit($xml,'application/xml; charset=utf-8');
    }
    public static function dispatch(HistoryStore $store): void {
        if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)){header('Allow: GET, HEAD');self::error(405,'此页面仅支持浏览');return;}
        $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
        if($path==='/history'){self::history($store);return;}
        if($path==='/robots.txt'){self::emit("User-agent: *\nAllow: /\nDisallow: /api.php\nSitemap: ".self::baseUrl()."/sitemap.xml\n",'text/plain; charset=utf-8');return;}
        if($path==='/sitemap.xml'){self::sitemap($store);return;}
        if(preg_match('~^/sitemaps/([1-9][0-9]{0,5})\.xml$~D',$path??'',$m)){self::sitemap($store,(int)$m[1]);return;}
        if(preg_match('~^/domain/([^/]+)(?:/([a-f0-9]{24}))?/?$~D',$path??'',$m)){
            try{$host=domain(rawurldecode($m[1]));}catch(Problem){self::error(404,'域名地址无效');return;}
            $canonical=HistoryStore::path($host,$m[2]??null);
            if($path!==$canonical){header('Location: '.$canonical,true,301);return;}
            self::report($store,$host,$m[2]??null);return;
        }
        self::error(404,'页面不存在');
    }
}
