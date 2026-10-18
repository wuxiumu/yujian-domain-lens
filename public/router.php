<?php
// Local development router; production should use public/ as document root.
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'");
header('Referrer-Policy: no-referrer'); header('X-Content-Type-Options: nosniff');
if($path==='/' || $path==='/index.html') { header('Content-Type: text/html; charset=utf-8'); readfile(__DIR__.'/index.html'); return true; }
if($path==='/api.php') { require __DIR__.'/api.php'; return true; }
if(in_array($path,['/assets/app.js','/assets/pages.js','/assets/style.css'],true)) { header('Content-Type: '.(str_ends_with($path,'.js')?'application/javascript':'text/css').'; charset=utf-8'); readfile(__DIR__.$path); return true; }
require __DIR__.'/pages.php'; return true;
