# 域见 · Domain Lens

免费的 PHP 8.1+ 域名分析 MVP，无数据库、无 Composer / npm 依赖。注册与 SEO 查询无需 Key；可选流量估算需配置 TabAPI Key。输入多个域名（换行、英文或中文逗号分隔），逐项展示注册时间、页面 SEO 信息和错误。支持中文域名、结果展开、JSON 导出、文件缓存和队列停止。Title / Description / Keywords 可点击弹窗查看；列表分别预览 3 / 4 / 3 行，超出隐藏，单字段最多保留 16,000 字符。

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

## 独立报告、分享与历史列表

- **最新独立报告**：`/domain/51chigua.com`。PHP 服务端直接输出完整报告，未执行 JavaScript 也能阅读注册、SEO、流量与更新时间。
- **固定历史快照**：`/domain/51chigua.com/24位版本标识`。首页结果的“分享本次”和独立页的“分享本次快照”复制这个永久版本链接；“复制固定链接”则复制域名最新报告地址。
- **历史列表**：`/history`，按最近查询时间倒序展示，每页 20 个域名；支持域名（含 IDN 中文写法）、完整 Title / Description / Keywords 的大小写不敏感搜索。分页链接为真实 HTML 链接，如 `/history?page=2`；搜索保留 `q` 参数。
- **结果保存**：每次正常查询或手动刷新后自动写入 `var/history/`。同一份有效缓存的重复查询只更新最近查询时间与次数，不生成重复快照；数据或抓取时间变化时保存新快照。列表每个域名只出现一次，独立页可打开最近 8 个历史版本，较早的已分享版本仍保留文件并可访问。
- 清除 `var/cache/` 的查询缓存不会删除历史报告，也不会让分享链接失效。历史没有自动过期，不与额度记录混用，不依赖数据库。需要备份整个 `var/history/`，不要在更新代码时覆盖它。
- `/history` 和 `/domain/...` 是公开页面，历史为全站共享的公开域名报告；不记录查询者 IP、账号、Cookie 或 API Key。
- **浏览、搜索、分页、爬虫抓取和打开分享链接均只读取文件**，不调用 RDAP、页面抓取或流量 API，也不消耗新积分。未查询的域名和不存在的版本返回真实 HTTP 404，不自动分析；重新分析需返回首页并主动提交。
- 保存历史失败时，正常查询数据仍会返回，并单独提示历史未保存，避免因目录权限或锁冲突丢失当前查询结果。

### 将已有缓存加入历史（升级后执行一次）

```sh
php scripts/import-history.php
```

该脚本读取项目中仍保留的注册 / SEO 与公开流量缓存（也可读取已过期但未删除的文件），按字段各自的原抓取时间合并导入；不使用 API Key，不联网，不消耗积分，可重复执行，不会重复创建相同快照。旧缓存没有完整查询日志，导入项以原始数据更新时间排序并标注“缓存记录时间”；新的真实查询会记录“最近查询”。无法恢复已被删除的旧缓存。

### SEO 与分享配置

每份最新报告有独立的 Title、Description、canonical、Open Graph、Twitter Card、WebPage 和面包屑结构化标记。历史快照 canonical 指向该域名最新报告，并设置 `noindex, follow` 避免同域名版本重复收录；筛选搜索页及空历史列表也设置 noindex。没有有效数据的失败报告不进入站点地图。

`/robots.txt` 提供站点地图地址，`/sitemap.xml` 从已保存的有效报告生成；超过 1,000 个报告时自动拆为 `/sitemaps/1.xml` 等分片，快照不进入站点地图。页面被抓取与实际收录、排名由搜索引擎决定。

默认 canonical 与独立页分享链接使用 `https://yujian.chiguashentan.com`，不会读取访客传入的 Host 头。如果更换域名，可复制 `config/site.example.php` 为忽略 Git 的 `config/site.local.php` 后修改 `base_url`，或通过 `APP_BASE_URL` 环境变量设置站点根地址。本地预览可用：

```sh
APP_BASE_URL=http://127.0.0.1:8788 php -S 127.0.0.1:8788 -t public public/router.php
```

### 宝塔 Nginx 伪静态（新增页面必须配置）

网站运行目录保持 `public/`，确保 PHP 服务账号可写 `var/history/`（包括内部子目录；不要使用 777），在站点设置的“伪静态”中配置前端路由：

```nginx
location / {
    try_files $uri $uri/ /pages.php?$query_string;
}
```

宝塔通常已有 PHP 8.2 解析规则，它需要能执行 `public/pages.php`。如果使用下面的严格 Nginx 示例，必须同时保留 `/api.php` 与 `/pages.php` 的精确 PHP 转发。Apache 使用新增 `.htaccess` 重写规则，需启用 `mod_rewrite` 并允许相应的 `.htaccess` 配置。PHP 内置服务器继续使用原启动命令即可。

## 查询内容与含义

- **注册时间**：通过 IANA RDAP bootstrap 选择注册局 HTTPS 服务，读取 `registration` 事件。子域名使用 ICANN 公共后缀规则折算为注册主体域名，例如 `www.example.co.uk` → `example.co.uk`。这不是网站上线时间。
- **SEO**：HTTPS 首页的 HTTP 状态、最终地址、最多 4 次重定向、Content-Type、Title、Description、Keywords、canonical、meta robots、Googlebot、X-Robots-Tag、语言、最多 10 个 H1。
- **robots.txt**：从最终页面的 origin 读取，显示状态和最多 16,000 字符预览。仅展示内容，不声称已判断所有搜索引擎是否允许索引。
- canonical 相对地址会按最终页面地址及 `<base>` 解析；canonical 只展示，不抓取。
- 只解析服务器返回的 HTML，不执行 JavaScript。单页应用、验证码、反爬和登录页面可能没有真实页面的元数据。HTTP 403 / 404 / 500 仍保留状态，并标记其元数据可能来自错误页。
- 可选展示 TabAPI 提供的月访问量估算，保留原始数值、统计月份、统计主体域名和来源。展示为 `12.29M` 这类缩写，弹窗可查看完整数值。没有配置 Key、没有数据、限流、积分不足分别显示状态，不会用 0 或示例值填充；服务商明确返回 0 时才显示 0。
- 不提供 SEO 分数，不自行推算流量。TabAPI 和 AITDK 扩展的数据不保证相同，不把 TabAPI 冒充为 AITDK 的原始数据。
- 一律从 `https://域名/` 开始，不自动降级到 HTTP；接受的 `http://域名/` 输入也仅用于提取域名。站点主动重定向到 HTTP 时会检查后跟随，并显示最终协议。证书验证始终开启。

## 月访问量：先用免费额度

AITDK 官网的 **API** 链接目前指向 [TabAPI](https://tabapi.com/)。本项目接入其[公开流量 API](https://tabapi.com/docs)，不会抓取扩展的内部接口或要求提供浏览器 Cookie。

截至 2026-10-18，官方[价格页](https://tabapi.com/pricing)与[流量说明](https://tabapi.com/traffic)列出：

| 方案 | 需要准备 | 费用 / 限制 |
| --- | --- | --- |
| AITDK 扩展手动查 | 安装官方 [AITDK 扩展](https://aitdk.com/extension)，按其要求登录 | 可免费查看流量；扩展不会自动把结果写入本工具 |
| 本工具自动查 | 注册 TabAPI，创建服务器端 API Key | 新账号 100 个免费积分，不需信用卡；不是永久免费，也不是每月赠送 |
| 免费积分用完后 | 自行决定是否购买积分，并核对余额后调整调用上限 | 单次购买最低 $10，$1 = 1,000 积分；流量每返回 1 个月消耗 1 积分，最少请求 3 个月，即通常每次 3 积分 / $0.003 |

100 个积分约可完成 33 次查询（每次均返回 3 个月时）。本项目为留出余量，默认每个 Key **累计最多向服务商发起 30 次请求**，所有访客共享；不是每人 30 次，也不会自动按月重置。未设置 Key 时不发起流量请求，不影响注册和 SEO。

### 宝塔推荐配置方式

复制仓库中的 `config/traffic.example.php` 为 **`config/traffic.local.php`**，填写自己的 Key：

```php
<?php
return [
    'tabapi_key' => 'sk_替换成你自己的Key',
    'max_lookups' => 30,
];
```

此文件在 Git 忽略列表中，位于 `public/` 外；不要把 Key 放到前端、URL、README 或提交到 GitHub。网站运行目录必须保持 `public/`。配置后重新查询即可，流量缓存与 SEO 缓存独立，不需要删除 SEO 缓存。

也可通过 PHP-FPM 环境设置 `TABAPI_KEY` 和 `TRAFFIC_MAX_LOOKUPS`（环境变量优先于本地配置）。如使用 FPM 环境配置，修改后重启 PHP；命令行临时设置变量不会自动影响已有 FPM 服务。

### 缓存、超时和额度保护

- 固定请求 `https://tabapi.com/api/v1/domains/{注册主体域名}/traffic?months=3`，使用服务商允许的最短 3 个月；第三方响应最多 1 MiB，时间预算 12 秒，解析 DNS 最多额外 4 秒。
- 使用现有的公网 IP 校验与固定连接；带认证请求一旦重定向立即拒绝，不把 Bearer Key 转发给其他地址。
- 成功结果缓存 7 天，无数据缓存 1 天，上游错误缓存 5 分钟。子域名按注册主体域名共享流量缓存，同一域名同时查询会加锁。启用 Key 后仍受原有接口限流保护。
- `var/state/` 保存每个 Key 的累计请求次数，必须可写；**不要删除该目录中的额度记录**。它与 `var/cache/` 分开，缓存清理不会重置调用次数。记录损坏或无法保存时停止调用。
- 每次发送前预留 1 次调用，失败也保守计入本地上限，不自动重试花费积分的请求。此计数是本站请求上限，**不等同于服务商余额**；同一账号在其他地方的消耗、Key 更换或人工清空记录不在保护范围内。
- `max_lookups=0` 暂停新的流量请求，已有缓存仍可展示。达到默认上限或服务商返回 402 时显示说明；没有自动充值、购买或扩大额度的逻辑。决定继续使用前，先在 TabAPI 查看余额，必要时自行购买积分，再提高上限。
- 已使用用户提供的 Key 成功验证两个示例域名，并验证重复查询命中磁盘缓存。2026 年 9 月 TabAPI 月访问量估算分别为 35,922 与 3,717，详见实测 JSON。真实 Key 未写入源码、示例配置或 Git。

## 缓存优先、清除和重新获取

所有数据缓存为项目 `var/cache/` 下的 JSON 文件，无需数据库。普通二次查询先读取有效缓存，不请求上游、不消耗新的流量积分。成功流量默认缓存 7 天，页面和注册信息默认缓存 1 小时；缓存过期后自动重新查询。

- 列表直接显示**页面数据更新时间**与**流量数据更新时间**，精确到秒；流量的统计月份另行展示。弹窗包含完整更新时间、缓存命中状态和有效期，时间按浏览器本地时区显示。
- 每行“**清除缓存**”：删除该主机的注册 / SEO 缓存和它所属注册主体域名的流量缓存；保留页面上次结果及原更新时间，并标记待更新。清除操作本身不调用服务商。
- 每行“**重新获取**”：先清除该域名的当前缓存，再重新请求注册、SEO 和流量，成功后原子写回文件，并替换页面结果。它可能消耗 3 个流量积分；如果上游只有旧的统计月份，该月份仍如实展示，不称为实时访问量。
- 结果区“**清空本次缓存**”：清除当前结果列表中有效域名的缓存，分批调用接口；不清除不在本次列表中的其他域名数据。之后重新查询即可读取最新数据。
- 清除数据**不删除 `var/state/` 的累计调用额度记录**，不会绕过免费额度保护。子域名共享注册主体域名的流量，清除其中一个会影响共享流量缓存；每个主机的 SEO 缓存仍独立。
- 清除与写入使用同一组非阻塞锁；数据正在更新时会报忙。仅按规范化域名计算文件哈希，不接受文件路径参数。

API 在原有 `domains` 外增加可选 `action`，默认为 `analyze`，另支持 `refresh` 和 `clear_cache`：

```json
{"domains":["51chigua.com"],"action":"refresh"}
```

```json
{"domains":["51chigua.com","51chiguai.com"],"action":"clear_cache"}
```

清除成功返回每项 `domain`、`traffic_domain`、`cache_cleared: true`、`cleared_at`。刷新返回与正常分析相同的结果结构。两种操作均受原有单批大小和每 IP 限流约束；刷新不会自动购买积分。

## 批量、缓存与超时

前端没有域名总数的产品限制，会按输入顺序逐项调用 API，并对相同输入文本去重。规范化后相同的域名会共享缓存。每个 HTTP 请求最多 10 个域名，请求体最多 64 KiB；这是单批保护，不是整个队列上限。每 IP 每分钟最多计入 60 个域名（含缓存命中），到达限额时前端自动等待重试，支持停止队列。大量结果受浏览器内存与磁盘容量限制。

- 系统 DNS：独立 CLI 进程，单主机最多 4 秒；验证本次所有 A / AAAA 结果，优先使用第一个 IPv4（没有时使用 IPv6）。不在失败后轮换地址。
- TCP / TLS 连接：最多 4 秒；RDAP 整条请求链约 18 秒、首页约 22 秒、robots.txt 约 10 秒；每条链临近截止时的 DNS 可能额外占用最多 4 秒。robots 查询单独计时。
- 页面及 RDAP 解压后正文最多 2 MiB；robots.txt 最多 256 KiB；单次响应头最多 64 KiB。超限明确报错，不拿截断页面当完整结果。
- 前端单域名接口等待最多 90 秒；停止按钮在当前域名完成后停止排队，并保留已有结果。
- 完整结果默认缓存 1 小时；注册或页面查询任一失败缓存 5 分钟。robots.txt 的单独错误不会缩短缓存。
- `var/cache/` 不位于 web 根目录，以域名哈希命名，原子替换；每个域名使用非阻塞独占锁防止同时重复抓取。默认不绕过缓存；明确点击“重新获取”或使用 `action=refresh` 时才删除当前缓存并请求上游。
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

成功返回 `{"results":[...]}`。每项有 `domain`、`checked_at`、`registration: {data,error}`、`seo: {data,error}`、`traffic`、`cache`；错误时 `data` 为 null。输入无效时该项返回 `input` 和 `error`，不会阻断其他项。没有注册事件时 `created_at` 为 null 并附说明。时间戳为 UTC ISO 8601，前端检查时间按浏览器时区显示。

错误结构统一为 `{"code":"…","message":"…"}`。HTTP 200 表示批处理已执行，不保证每个上游成功；应检查每项 error。整个请求格式、方法、大小或限流问题分别返回 400 / 405 / 413 / 415 / 422 / 429，内部故障返回 500。常见上游代码包括 `dns_timeout`、`dns_not_found`、`blocked_ip`、`timeout`、`network_error`、`redirect_limit`、`response_too_large`、`rdap_http_404`、`rdap_http_429`、`rdap_unsupported`。

`traffic.status` 为 `not_configured` / `ok` / `no_data` / `error` / `limit_reached`；成功数据在 `traffic.data`，其中 `monthly_visits` 是原始数字、`month` 是统计月份，`history` 为最近月份。流量错误不影响注册和 SEO 结果；完整结果 JSON 导出包含此字段。

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
    location / { try_files $uri $uri/ /pages.php?$query_string; }
    location = /api.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/api.php;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_read_timeout 900s;
    }
    location = /pages.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/pages.php;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
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
php tests/traffic.php
php tests/history.php
php tests/pages.php
python3 tests/api.py http://127.0.0.1:8787
php scripts/import-history.php
python3 tests/pages_http.py http://127.0.0.1:8787
php scripts/update-data.php
```

单元测试不依赖网络，覆盖地址拦截、非法协议与端口、公共后缀、相对跳转、HTML 元数据、GBK 中文、缓存过期与锁。API 测试覆盖方法、格式、输入、跨站来源和批次限制；真实域名查询依赖网络与注册局，不保证随时有结果。更新脚本通过同一安全 HTTP 客户端下载并校验 IANA / PSL 数据，失败不覆盖对应旧文件。建议每月更新。

随附数据下载于 2026-10-18：

- IANA RDAP bootstrap：<https://data.iana.org/rdap/dns.json>
- Public Suffix List：<https://publicsuffix.org/list/public_suffix_list.dat>（原文件保留 MPL 2.0 许可声明；RDAP 域名计算仅使用 ICANN 部分）
- cURL 地址固定选项：<https://www.php.net/manual/en/function.curl-setopt.php>

源码采用 MIT，PSL 遵循其自身许可。

本次实测结果见 [VERIFICATION.md](VERIFICATION.md) 与 [JSON 快照](examples/results-2026-10-18.json)。快照仅供参考，不参与页面查询。

更新部署时保留服务器上的 `config/traffic.local.php`、`var/cache/`、`var/state/` 和 `var/history/`。真实 Key 不随源码分发；首次部署需在服务器本地配置。清空查询缓存不应删除额度记录。
