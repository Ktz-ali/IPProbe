# IP探针 宝塔面板部署指南（Nginx + MySQL + PHP）

## 一、服务器准备

1. 服务器装宝塔面板（以 AlmaLinux/CentOS 为例，root 登录后执行）：
   ```bash
   curl -sSO https://download.bt.cn/install/install_panel.sh && bash install_panel.sh
   ```
   装完记下面板地址/账号/密码。
2. 面板「软件商店」安装（全部一键）：
   - Nginx 1.26（或更高）
   - MySQL 8.0（5.7 也可）
   - PHP 8.2（8.1/8.3 均可）
3. PHP 设置 → 安装扩展：确认已启用 `pdo_mysql`（默认就有）。

## 二、创建网站

1. 面板「网站」→「添加站点」：
   - 域名：填你的域名（如 `probe.example.com`），域名先解析到本服务器 IP；
   - PHP 版本：选 8.2；
   - 数据库：勾选「MySQL」，记下自动生成的**数据库名/用户名/密码**（安装向导里要填）。
2. 进入站点目录（如 `/www/wwwroot/probe.example.com/`），删除默认文件，
   上传本目录全部内容：
   ```
   index.php  config.php  db.php  functions.php  install.php  install.sql
   geo_city.php  ip2region/  ip2region.xdb  ip2region_v6.xdb
   ```
3. 设置**伪静态**（站点设置 → 伪静态，粘贴）：
   ```nginx
   location / {
       try_files $uri $uri/ /index.php?$query_string;
   }
   ```
   此规则让 `/p/abc123`、`/api/xxx` 全部转发给 index.php。

## 三、Web 安装向导（推荐，一键完成）

1. 浏览器访问 `https://你的域名/` —— 未安装时**自动跳转安装向导**；
2. **第一步 环境检查**：确认 PHP ≥ 7.4、pdo_mysql 扩展、config.php 可写、install.sql 存在，全部通过点「下一步」；
3. **第二步 数据库配置**：填入宝塔创建的数据库信息（主机/端口/库名/用户名/密码）、访问令牌（已预设默认值，可改）、探针链接前缀（留空自动用当前域名）；
4. 点「开始安装」——向导自动完成：测试数据库连接 → 导入 `install.sql` 建表 → 生成 `config.php`（地图 Key 等全部预设自动写入）；
5. 安装完成页显示你的服务器地址与访问令牌，点击「验证系统」确认 `/api/ping` 返回 `{"code":0,...}`。
   > 建议完成后删除站点根目录的 `install.php`（不删也不影响，已安装状态访问只会看到提示）。
   > 手动备选：宝塔「数据库」→ 对应库 →「导入」`install.sql`，再手动改 `config.php` 数据库三项。

## 四、SSL（可选但推荐）

站点设置 → SSL → Let's Encrypt → 一键申请。申请后探针链接即为
`https://你的域名/p/短码`，App 服务器地址填 `https://你的域名`。

## 五、App 对接

- 服务器地址：`https://你的域名`
- 访问令牌：安装向导里填写的（或 config.php 里预设的 `probe_token`，App 内置同值，直接自动连接）

## 接口清单
| 方法 | 路径 | 参数 | 说明 |
|---|---|---|---|
| POST | /api/probe | token, {name?, redirect?, template?, image_url?, text_content?, shorten?, access_code?, expire_days?, once_only?} | 创建探针（template: blank/fake404/fake503/image/text/redirect；shorten: self/tinyurl/clckru/auto；防护：access_code=访问码、expire_days=过期天数、once_only=一次性） |
| POST | /api/probe/{code} | token, {name?, redirect?, template?, image_url?, text_content?, shorten?, access_code?, expire_days?, once_only?} | 编辑探针（shorten 非空时重新生成短链；防护字段同上） |
| POST | /api/probe/{code}/toggle | token | 启用/停用探针 |
| GET | /api/probe/list | token | 探针列表（含模板/短链/启用状态/访问码/有效期/一次性标记） |
| GET | /api/stats | token | 统计概览（探针数/总点击/今日点击） |
| DELETE | /api/probe/{code} | token | 删除探针（级联删除其全部日志） |
| GET | /api/logs | token, probe?, days?, country?, device?, q?, page, size | 分页日志（含 probe/经纬度/邮编/时区/ASN/is_ipv6/GPS）；筛选：probe=指定探针 / days=近 N 天 / country=国家 / device=设备类型 / q=关键词（IP/城市/UA）；支持排序（默认时间倒序） |
| GET | /api/logs/stats | token, probe?, days? | 日志统计：点击趋势、国家分布、设备分布三组聚合 |
| GET | /api/ip_profile | token, ip | 同 IP 画像：访问过的探针、城市、设备、首次/最近访问时间 |
| DELETE | /api/logs | token, probe?, days? | 清理日志：probe=指定探针 / 留空=全部 / days>0=删除 N 天前（索引定位 + 主键范围删，顺带清理 geo_cache） |
| POST | /api/gps_report | {code,lat,lng,acc}（免 token） | GPS 精确定位上报表（校验探针存在且启用；访客授权上报，同 IP 同探针 60 秒限流 1 次） |
| GET | /p/{code} | 无（POST 时可带 access=访问码） | 访客点击，按探针模板渲染页面（过期 / 一次性 / 访问码三重防护，验证通过后才记录点击） |
| GET | /api/ping | 无 | 连通自检 |
## v1.2 升级（老用户）
1. 上传覆盖全部 PHP 文件（含新增的 geo_city.php；保留 config.php 亦可，新配置项已预设）。
2. 数据库升级：**直接导入 `upgrade_all.sql`（一键合并、幂等，任意老版本直接升到最新）**；全新安装用 `schema.sql`。
3. 定位说明：**IPv4/IPv6 双库离线定位**——`ip2region.xdb`（IPv4）+ `ip2region_v6.xdb`（IPv6，37MB）均需上传到站点根目录；离线可查归属，坐标由内置城市经纬度表兜底。在线增强（**中国 IP 优先 pconline 太平洋源 → 911cha → ipinfo，国际 IP 走 ip-api**，缓存 7 天 + 限流 40 次/分）在 config.php `online_geo_enable=true` 时提供更精确的省市/坐标/邮编/时区/ASN，可关闭。
   > v1.5.1 定位源策略修正：国内运营商宽带 NAT 段（联通/移动/电信）pconline 与 911cha 的数据比 ipinfo 准（实测 ipinfo 曾把江西九江联通段错标为南昌，pconline/911cha 均返回九江），故中国 IP 定位链改为 **pconline → 911cha → ipinfo** 逐级兜底；坐标仍由城市经纬度表补齐。

## v1.3 升级（GPS 精确定位，老用户）
1. 上传覆盖全部 PHP 文件。
2. 数据库：导入 `upgrade_all.sql`（幂等合并，一步到位，visit_logs +3 GPS 字段）。
3. 生效说明：访客打开探针页（blank/text/image/redirect 模板）会看到右下角 🎯 按钮，点击并允许定位后 GPS 坐标写入该 IP 最近一条访问日志（同 IP 同探针 60 秒限流 1 次）；fake404/fake503 伪装模板不显示按钮。App 需升级到 v1.3.0 才展示 GPS 坐标。

## v1.4 升级（日志清理，老用户）
1. 上传覆盖全部 PHP 文件（无需数据库变更）。
2. 新能力：`DELETE /api/logs?token=&probe=&days=` 清理日志（probe 指定探针 / 留空全部 / days 删 N 天前）；删除探针自动级联删除其日志。
3. App 升级 v1.4.0：日志工具栏「清空」按钮按当前筛选范围清理；删除探针提示同时删除其日志。
4. 自动清理（可选）：宝塔 → 计划任务 → Shell 脚本，例如每天 3 点清 30 天前日志：
   `curl -s -X DELETE 'https://你的域名/api/logs?token=你的Token&days=30'`

## v1.5 升级（防护 / 筛选 / 统计 / 画像 / 多服务器，老用户）
1. 上传覆盖全部 PHP 文件。
2. **数据库升级：导入 `upgrade_all.sql`（幂等，一键合并）**——probes 表新增 `access_code`（VARCHAR(16)）、`expire_days`（INT UNSIGNED）、`once_only`（TINYINT(1)）三个防护字段，visit_logs 新增 `created_at` 索引。不升级时老库自动降级（列表隐藏防护列、不报错），但**新建带防护的探针必须升级**。
3. 新接口：`GET /api/logs/stats`（趋势/国家/设备聚合）、`GET /api/ip_profile?ip=`（同 IP 画像）；`GET /api/logs` 新增 days/country/device/q 筛选参数且返回 probe 列。
4. 探针三重防护：访问码（access_code，验证通过种 HttpOnly Cookie）、过期（expire_days 天后自动失效）、一次性（同 IP 访问一次后失效）；均需在 App v1.5.0 中设置，访客页自动出现对应门禁页。
5. App 升级 v1.5.0：二维码分享、访问码设置、日志筛选（时间/设备/国家/关键词）、日志详情、统计图表、同 IP 画像、多服务器管理、列表排序、链接过期/一次性、空态引导。

## v1.5.1 升级（定位源修正 + 日志复制/内置 IP 查询，老用户）
1. 上传覆盖全部 PHP 文件（无需数据库变更）。
2. **定位源修正**：中国 IP 在线定位链改为 **pconline → 911cha → ipinfo**（此前 ipinfo 优先会把联通等宽带 NAT 段错标到省会城市，实测九江联通段 ipinfo 返回南昌、pconline/911cha 返回九江）。
3. **必做：清空旧定位缓存**——宝塔 → 数据库 → SQL 框执行 `TRUNCATE TABLE geo_cache;`（旧缓存里存着错误城市，不清则老 IP 仍显示旧结果）。
4. App 升级 v1.5.1：日志卡片新增「复制」（一键复制该条访问记录全部字段）与「911查询」（内置跳转 ip.911cha.com 查询该 IP 归属），详情弹窗新增「复制全部」与「911查询」。
5. 说明：宽带 WiFi 走的是运营商 NAT 出口（如九江宽带从南昌出口上网），纯 IP 库只能定位到出口城市；要拿访客**真实精确位置**请引导访客点击探针页右下角 🎯 按钮授权 GPS（App 日志卡片显示绿色 GPS 精确坐标）。

## v1.5.2 升级（chinaz/百度/腾讯地图定位增强 + GPS 街道地址，老用户）
1. 上传覆盖全部 PHP 文件。
2. **数据库升级：导入 `upgrade_all.sql`（幂等）**——visit_logs 新增 `district`（区县）与 `gps_addr`（GPS 街道地址）两列；不升级时老库自动降级（日志不显示区县/街道地址，不报错）。
3. **必做：清空旧定位缓存**——宝塔 → 数据库 → SQL 框执行 `TRUNCATE TABLE geo_cache;`（不清则老 IP 仍显示旧城市）。
4. **地图 Key 配置（config.php，可选但推荐）**：
   - `baidu_ak`：百度地图 Web服务 AK（已预设，应用名「IP探针」）。百度控制台已关闭 IP 校验，任何服务器均可调用；若日后重新开启白名单，需把本服务器 IP 加入白名单。
   - `tencent_key` / `tencent_sk`：腾讯位置服务 WebService Key + SK（已预设，应用名「遥辉网络」）。控制台已开启**签名校验**模式，不依赖来源 IP，任何服务器均可调用；SK 为服务端密钥切勿泄露。
   - 两者留空即禁用对应能力，不影响其它定位源。
5. 定位链升级：中国 IP 走 **pconline → chinaz（可精细到区县）→ 腾讯IP（可精细到区县）→ 911cha → ipinfo → 百度IP → ip-api** 逐级兜底；国际 IP 走 ip-api。
6. GPS 街道地址：访客 🎯 授权后，坐标先经百度逆地理编码解析为街道级文字地址（省市区+乡镇/街道/门牌），失败自动切换腾讯逆地理兜底，写入 `gps_addr` 列（App 日志卡片 GPS 行下方展示）。
7. App 升级 v1.5.2：日志卡片归属行新增区县展示、GPS 行下方新增街道地址；详情弹窗新增「区县」「GPS地址」两行；复制文本与 CSV 导出同步新增两字段。
8. 说明：纯 IP 定位即使精细到区县，仍是运营商出口/库数据粒度；访客**真实精确位置**唯一通道是探针页 🎯 授权 GPS（街道地址也由此解析）。
## v1.5.3 升级（高德地图 + 天气页模板，老用户）
1. 上传覆盖全部 PHP 文件（无需数据库变更；若尚未升级 v1.5.2 仍建议先导入 `upgrade_all.sql` 获得 district/gps_addr 列）。
2. config.php 新增 `amap_key`（高德 Web服务 Key，已预设），用途：①GPS 坐标逆地理**首选**（可解析到村/门牌号，写入 gps_addr）；②国内 IP 定位兜底源（省市 + adcode）；③**天气页模板数据源**（IP 归属地实况 + 定位后区县级 4 天预报）。留空则禁用高德能力。
3. **新模板「天气页」（weather）**：新建/编辑探针模板可选。访客打开看到真实天气页面（渐变 UI、当前温度/天气/湿度/风力/4 天预报，数据来自高德天气 API），底部「📍 获取本地精确天气」按钮——点击授权定位后：①天气自动切换为该区县级预报；②GPS 坐标与街道地址写入日志。未定位时显示 IP 归属地天气兜底。**天气应用请求定位完全自然，访客零怀疑**。
4. 新接口 `GET /api/weather?code=&lat=&lng=`（访客用，免 token；校验探针存在且启用）。
5. App 升级 v1.5.3：模板选择新增「天气页（推荐：真实天气+定位自然）」。
6. 定位链升级：pconline → chinaz → 腾讯IP → 911cha → ipinfo → 百度IP → 高德IP → ip-api。
7. 部署后建议执行 `TRUNCATE TABLE geo_cache;` 清空旧定位缓存。
## v1.5.4 部署改进（SQL 合一 + Web 安装向导）
1. **SQL 合并**：全部数据库脚本合并为单个 `install.sql`（含所有最新表结构，幂等可重复执行），不再按版本提供升级脚本。老版本升级方式：上传覆盖全部 PHP 文件后，宝塔导入一次 `install.sql` 即可（建表语句幂等，不影响已有数据）。
2. **Web 安装向导**：新增 `install.php`。未安装时（config.php 数据库密码仍为占位符）访问站点任意路径自动跳转向导：环境检查 → 填写数据库信息 → 自动建表并生成 config.php（地图 Key 等预设一并写入）。安装完成建议删除 install.php。
3. 老库升级到最新后，若已有旧定位缓存建议 `TRUNCATE TABLE geo_cache;`。
## 常见问题

- **404**：伪静态没配（见二.3）。
- **token error**：config.php 的 probe_token 和请求的 token 不一致。
- **server error**：数据库连接信息不对（db_name/user/pass），或没导入 schema.sql。
- **归属地为空**：确认 `ip2region.xdb` 已上传到站点根目录（与 index.php 同级）。
- **5.7/8.0 区别**：无，SQL 全兼容。