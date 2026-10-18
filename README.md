# 域见 · Domain Lens

免费的 PHP 8.1+ 域名分析 MVP，无数据库、无 Composer / npm 依赖、无 API Key。输入多个域名（换行、英文或中文逗号分隔），逐项展示注册时间、页面 SEO 信息和错误。支持中文域名、结果展开、JSON 导出、文件缓存和队列停止。

在线演示：<https://yujian.chiguashentan.com/>

源码仓库：<https://github.com/wuxiumu/yujian-domain-lens>

## 本地启动

需要 PHP 8.1+，扩展 `curl`、`dom`、`intl`、`mbstring`、`json`，并允许 `proc_open`。网页进程需要调用 PHP CLI 来隔离并限制系统 DNS 查询时间；默认路径是 `PHP_BINDIR/php`。PHP CLI 必须启用 DNS 函数。

在本项目目录运行：

```sh
php -S 127.0.0.1:8787 -t public public/router.php
```

打开 <http://127.0.0.1:8787/>，默认示例：

```text
51chigua.com
51chiguai.com
```

停止服务：在启动终端按 Ctrl+C。本地开发服务为单进程，一个慢查询可能使其他请求等待；正式使用请配合 PHP-FPM。

如 CLI 路径不同，启动前指定服务器端环境变量（不能由请求参数指定）：

```sh
DOMAIN_LENS_PHP_CLI=/absolute/path/to/php php -S 127.0.0.1:8787 -t public public/router.php
```

Windows 可在服务环境中设置 `DOMAIN_LENS_PHP_CLI` 为 `php.exe` 的绝对路径。Windows 与共享主机环境未实测。

## 宝塔 PHP 8.2 部署

1. 网站运行目录设置为 `public/`，PHP 版本选择 8.2。
2. 在 PHP 8.2 设置中安装或启用 `curl`、`dom`、`intl`、`mbstring`、`json`。
3. 在“禁用函数”中仅解除以下函数的禁用：`proc_open`、`proc_get_status`、`proc_terminate`、`proc_close`，随后重启 PHP 8.2。无需清空整个禁用列表，也无需启用 `exec`、`system`、`shell_exec`。
4. 确保 PHP 服务账号可写项目的 `var/cache/`，并可执行 PHP CLI。宝塔常见 CLI 路径为 `/www/server/php/82/bin/php`；如默认探测路径不符，在 PHP-FPM 池配置中设置 `env[DOMAIN_LENS_PHP_CLI] = /www/server/php/82/bin/php` 后重启，具体以服务器实际安装路径为准。

报错“当前 PHP 禁用了 proc_open”表示 Web PHP 的禁用函数配置阻止了 DNS 子进程，与 PHP 8.2 版本兼容性无关。本版要求允许上述进程函数，不会跳过超时和 SSRF 检查。

## 查询内容与含义

- **注册时间**：通过 IANA RDAP bootstrap 选择注册局 HTTPS 服务，读取 `registration` 事件。子域名使用 ICANN 公共后缀规则折算为注册主体域名，例如 `www.example.co.uk` → `example.co.uk`。这不是网站上线时间。
- **SEO**：HTTPS 首页的 HTTP 状态、最终地址、最多 4 次重定向、Content-Type、Title、Description、Keywords、canonical、meta robots、Googlebot、X-Robots-Tag、语言、最多 10 个 H1。
- **robots.txt**：从最终页面的 origin 读取，显示状态和最多 16,000 字符预览。仅展示内容，不声称已判断所有搜索引擎是否允许索引。
- canonical 相对地址会按最终页面地址及 `<base>` 解析；canonical 只展示，不抓取。
- 只解析服务器返回的 HTML，不执行 JavaScript。单页应用、验证码、反爬和登录页面可能没有真实页面的元数据。HTTP 403 / 404 / 500 仍保留状态，并标记其元数据可能来自错误页。
- 不提供访问量、访问时长或 SEO 分数，也不会推测、填充或模拟此类数据。
- 一律从 `https://域名/` 开始，不自动降级到 HTTP；接受的 `http://域名/` 输入也仅用于提取域名。站点主动重定向到 HTTP 时会检查后跟随，并显示最终协议。证书验证始终开启。

## 批量、缓存与超时

前端没有域名总数的产品限制，会按输入顺序逐项调用 API，并对相同输入文本去重。规范化后相同的域名会共享缓存。每个 HTTP 请求最多 10 个域名，请求体最多 64 KiB；这是单批保护，不是整个队列上限。每 IP 每分钟最多计入 60 个域名（含缓存命中），到达限额时前端自动等待重试，支持停止队列。大量结果受浏览器内存与磁盘容量限制。

- 系统 DNS：独立 CLI 进程，单主机最多 4 秒；验证本次所有 A / AAAA 结果，优先使用第一个 IPv4（没有时使用 IPv6）。不在失败后轮换地址。
- TCP / TLS 连接：最多 4 秒；RDAP 整条请求链约 18 秒、首页约 22 秒、robots.txt 约 10 秒；每条链临近截止时的 DNS 可能额外占用最多 4 秒。robots 查询单独计时。
- 页面及 RDAP 解压后正文最多 2 MiB；robots.txt 最多 256 KiB；单次响应头最多 64 KiB。超限明确报错，不拿截断页面当完整结果。
- 前端单域名接口等待最多 90 秒；停止按钮在当前域名完成后停止排队，并保留已有结果。
- 完整结果默认缓存 1 小时；注册或页面查询任一失败缓存 5 分钟。robots.txt 的单独错误不会缩短缓存。
- `var/cache/` 不位于 web 根目录，以域名哈希命名，原子替换；每个域名使用非阻塞独占锁防止同时重复抓取。无“绕过缓存”公开参数，避免无意反复请求上游。
- 缓存是按需判定过期，过期文件不自动删除；锁文件也会保留。需要周期性运行下方维护命令（仅清理过期数据；锁文件保留，若需要清理锁文件，应先停止服务）。

```sh
php scripts/prune-cache.php
```

## API

```sh
curl -sS http://127.0.0.1:8787/api.php \
  -H 'Content-Type: application/json' \
  -d '{"domains":["51chigua.com","51chiguai.com"]}'
```

也可传字符串：`{"domains":"51chigua.com,51chiguai.com"}`。

成功返回 `{"results":[...]}`。每项有 `domain`、`checked_at`、`registration: {data,error}`、`seo: {data,error}`、`cache`；错误时 `data` 为 null。输入无效时该项返回 `input` 和 `error`，不会阻断其他项。没有注册事件时 `created_at` 为 null 并附说明。时间戳为 UTC ISO 8601，前端检查时间按浏览器时区显示。

错误结构统一为 `{"code":"…","message":"…"}`。HTTP 200 表示批处理已执行，不保证每个上游成功；应检查每项 error。整个请求格式、方法、大小或限流问题分别返回 400 / 405 / 413 / 415 / 422 / 429，内部故障返回 500。常见上游代码包括 `dns_timeout`、`dns_not_found`、`blocked_ip`、`timeout`、`network_error`、`redirect_limit`、`response_too_large`、`rdap_http_404`、`rdap_http_429`、`rdap_unsupported`。

## SSRF 防护

输入只接受域名或不带端口、路径、账号、查询串的 HTTP(S) 首页 URL。禁止 IP 字面量、单标签主机名、本地及保留后缀、控制字符和反斜线。出站仅允许 HTTP / HTTPS 默认端口。RDAP 服务、首页、robots.txt 以及每次重定向都经过相同检查。

DNS 使用系统解析器，在子进程中限时执行，不通过 shell 拼接命令。结果中出现任何非公网地址（包括回环、私网、链路本地、云元数据、CGNAT、组播、文档和保留网段）都拒绝连接。IPv6 仅放行保守筛选后的全球单播，拒绝 IPv4 映射、6to4 和特殊用途地址。验证后的 IP 通过 cURL `CURLOPT_RESOLVE` 固定，防止连接前 DNS 再解析，TLS 的 SNI / Host 仍为原域名；连接后再次核对实际 IP。禁用自动重定向与环境代理，逐跳重新验证主机与 IP，TLS 证书校验不关闭。

前端用 DOM `textContent` 展示抓取内容；不将外部 HTML 注入本页面。链接仅接受 HTTP(S)。API 不开放 CORS，存在 Origin 时要求同源；限流只信任 REMOTE_ADDR，不信任用户自填的转发头。

## 部署与运维

文档根目录**必须是 `public/`**，不要把项目根目录设为公开目录。仅 PHP 服务账号需写 `var/cache/`，不要赋予 777。项目无需数据库。内置开发路由严格限制可访问文件。

Apache 可使用随附 `.htaccess`（需允许 Options 与相关响应头配置）。Nginx / PHP-FPM 示例：

```nginx
server {
    listen 80;
    server_name localhost;
    root /absolute/path/domain-lens/public;
    index index.html;
    client_max_body_size 64k;
    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy no-referrer always;
    add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'" always;
    location / { try_files $uri $uri/ =404; }
    location = /api.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/api.php;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_read_timeout 700s;
    }
    location ~ \.php$ { return 404; }
    location ~ /\. { deny all; }
}
```

调整实际 FPM socket、PHP CLI 路径与写权限。前端每次仅发送 1 个域名；直接 API 使用完整 10 项批次时需使网关与 FPM 请求上限配合。反向代理应保留正确 Host / HTTPS 状态；本 MVP 的 IP 限额在代理之后会共用代理 IP 配额。外部开放服务可在网关增加身份验证、全局并发限制及出口网络 ACL；内置按 IP 限额不替代公网服务的容量控制。

本项目不使用网络代理；仅允许代理出网的环境会出现连接失败，不能通过关闭证书或地址检查来绕过。

## 验证与数据更新

```sh
php tests/run.php
python3 tests/api.py http://127.0.0.1:8787
php scripts/update-data.php
```

单元测试不依赖网络，覆盖地址拦截、非法协议与端口、公共后缀、相对跳转、HTML 元数据、GBK 中文、缓存过期与锁。API 测试覆盖方法、格式、输入、跨站来源和批次限制；真实域名查询依赖网络与注册局，不保证随时有结果。更新脚本通过同一安全 HTTP 客户端下载并校验 IANA / PSL 数据，失败不覆盖对应旧文件。建议每月更新。

随附数据下载于 2026-10-18：

- IANA RDAP bootstrap：<https://data.iana.org/rdap/dns.json>
- Public Suffix List：<https://publicsuffix.org/list/public_suffix_list.dat>（原文件保留 MPL 2.0 许可声明；RDAP 域名计算仅使用 ICANN 部分）
- cURL 地址固定选项：<https://www.php.net/manual/en/function.curl-setopt.php>

源码采用 MIT，PSL 遵循其自身许可。

本次实测结果见 [VERIFICATION.md](VERIFICATION.md) 与 [JSON 快照](examples/results-2026-10-18.json)。快照仅供参考，不参与页面查询。
