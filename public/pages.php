<?php
declare(strict_types=1);
require __DIR__.'/../src/Core.php';
require __DIR__.'/../src/History.php';
require __DIR__.'/../src/Pages.php';
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'");
try { DomainLens\PublicPages::dispatch(new DomainLens\HistoryStore(__DIR__.'/../var/history')); }
catch(Throwable $e) {
    try { DomainLens\PublicPages::error($e instanceof DomainLens\Problem && in_array($e->kind,['invalid_search','invalid_page'],true)?400:500,$e instanceof DomainLens\Problem?$e->getMessage():'报告暂时无法读取，请稍后重试。'); }
    catch(Throwable) {http_response_code(500);header('Content-Type: text/plain; charset=utf-8');echo '站点配置错误，请管理员检查。';}
}
