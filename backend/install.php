<?php
/**
 * IP探针 安装向导（v1.5.4）
 * 未安装时访问站点任意路径会自动跳转到本页，按步骤完成安装。
 * 流程：1 环境检查 -> 2 数据库配置 -> 3 执行安装（生成 config.php + 导入 install.sql）-> 完成
 */
header('Content-Type: text/html; charset=utf-8');
$baseDir    = __DIR__;
$configFile = $baseDir . '/config.php';
$PLACEHOLDER = '请改成你的数据库密码';

// ---- 已安装检测 ----
$installed = false;
if (is_file($configFile)) {
    $cfg = @include $configFile;
    if (is_array($cfg) && isset($cfg['db_pass']) && (string)$cfg['db_pass'] !== $PLACEHOLDER) {
        $installed = true;
    }
}

$step    = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$errors  = [];
$success = false;

// ---- 生成 config.php 内容（地图 Key 等预设一并写入） ----
function build_config_php($db_host, $db_port, $db_name, $db_user, $db_pass, $token, $base_url)
{
    $vals = [
        'db_host'            => $db_host,
        'db_port'            => (int)$db_port,
        'db_name'            => $db_name,
        'db_user'            => $db_user,
        'db_pass'            => $db_pass,
        'probe_token'        => $token,
        'base_url'           => $base_url,
        'online_geo_enable'  => true,
        'baidu_ak'           => '',
        'tencent_key'        => '',
        'tencent_sk'         => '',
        'amap_key'           => '',
    ];
    $out = "<?php\n/**\n * IP探针 后端配置（由安装向导自动生成）\n */\nreturn [\n";
    foreach ($vals as $k => $v) {
        $out .= '    ' . var_export($k, true) . ' => ' . var_export($v, true) . ",\n";
    }
    $out .= "];\n";
    return $out;
}

// ---- 导入 install.sql（按 ; 分块、去注释，逐块执行） ----
function import_sql_file($pdo, $sqlFile)
{
    if (!is_file($sqlFile)) {
        throw new RuntimeException('install.sql 不存在，请确认已上传');
    }
    $sql = (string)file_get_contents($sqlFile);
    foreach (explode(';', $sql) as $chunk) {
        $chunk = preg_replace('/^--[^\n]*\n/m', '', (string)$chunk);
        $chunk = trim($chunk);
        if ($chunk === '') {
            continue;
        }
        $pdo->exec($chunk);
    }
}
// ---- 期望表结构（列名 => ADD COLUMN 定义）----
function expect_columns()
{
    return [
        'probes' => [
            'code'           => "`code` VARCHAR(16) NOT NULL COMMENT '短码，主键'",
            'name'           => "`name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '探针名称'",
            'redirect'       => "`redirect` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '跳转地址'",
            'template'       => "`template` VARCHAR(16) NOT NULL DEFAULT 'blank' COMMENT '访问模板'",
            'image_url'      => "`image_url` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '图片页图片URL'",
            'text_content'   => "`text_content` VARCHAR(2000) NOT NULL DEFAULT '' COMMENT '文字页内容'",
            'short_url'      => "`short_url` VARCHAR(200) NOT NULL DEFAULT '' COMMENT '生成的短链'",
            'short_provider' => "`short_provider` VARCHAR(16) NOT NULL DEFAULT '' COMMENT '短链服务商'",
            'access_code'    => "`access_code` VARCHAR(16) NOT NULL DEFAULT '' COMMENT '访问码'",
            'expire_days'    => "`expire_days` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '有效期天数'",
            'once_only'      => "`once_only` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '一次性'",
            'enabled'        => "`enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否启用'",
            'visit_count'    => "`visit_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '点击次数'",
            'created_at'     => "`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间'",
            'last_visit_at'  => "`last_visit_at` DATETIME NULL DEFAULT NULL COMMENT '最近点击时间'",
            'updated_at'     => "`updated_at` DATETIME NULL DEFAULT NULL COMMENT '更新时间'",
        ],
        'visit_logs' => [
            'id'         => "`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT",
            'probe'      => "`probe` VARCHAR(16) NOT NULL COMMENT '所属探针短码'",
            'ip'         => "`ip` VARCHAR(45) NOT NULL DEFAULT '' COMMENT '访客IP'",
            'country'    => "`country` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '国家'",
            'province'   => "`province` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '省'",
            'city'       => "`city` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '市'",
            'isp'        => "`isp` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '运营商'",
            'lat'        => "`lat` DECIMAL(9,6) NULL DEFAULT NULL COMMENT '纬度'",
            'lng'        => "`lng` DECIMAL(9,6) NULL DEFAULT NULL COMMENT '经度'",
            'zip'        => "`zip` VARCHAR(16) NOT NULL DEFAULT '' COMMENT '邮编'",
            'timezone'   => "`timezone` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '时区'",
            'org'        => "`org` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '组织'",
            'as_info'    => "`as_info` VARCHAR(128) NOT NULL DEFAULT '' COMMENT 'ASN'",
            'gps_lat'    => "`gps_lat` DECIMAL(9,6) NULL DEFAULT NULL COMMENT 'GPS纬度'",
            'gps_lng'    => "`gps_lng` DECIMAL(9,6) NULL DEFAULT NULL COMMENT 'GPS经度'",
            'gps_acc'    => "`gps_acc` INT UNSIGNED NULL DEFAULT NULL COMMENT 'GPS精度'",
            'district'   => "`district` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '区县'",
            'gps_addr'   => "`gps_addr` VARCHAR(191) NOT NULL DEFAULT '' COMMENT 'GPS地址'",
            'is_ipv6'    => "`is_ipv6` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否IPv6'",
            'ua'         => "`ua` VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'User-Agent'",
            'referer'    => "`referer` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '来源页'",
            'created_at' => "`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '访问时间'",
        ],
        'geo_cache' => [
            'ip'         => "`ip` VARCHAR(45) NOT NULL COMMENT 'IP'",
            'data'       => "`data` MEDIUMTEXT NOT NULL COMMENT '定位结果JSON'",
            'updated_at' => "`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新时间'",
        ],
    ];
}
// ---- 修复数据库结构：幂等建表 + 老表缺列自动补齐 ----
function fix_schema($pdo)
{
    $baseDir = dirname(__FILE__);
    // 1) 幂等建表（全新库直接建全；老库已存在的表跳过）
    import_sql_file($pdo, $baseDir . '/install.sql');
    // 2) 老表补列：逐表对比 information_schema，缺失则 ALTER ADD COLUMN
    $fixed = [];
    foreach (expect_columns() as $table => $cols) {
        $stmt = $pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        $existing = array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $missing = [];
        foreach ($cols as $col => $ddl) {
            if (!in_array(strtolower($col), $existing, true)) {
                $missing[$col] = $ddl;
            }
        }
        foreach ($missing as $col => $ddl) {
            $pdo->exec("ALTER TABLE `" . $table . "` ADD COLUMN " . $ddl);
            $fixed[] = $table . '.' . $col;
        }
    }
    return $fixed;
}
// ---- POST：已安装状态 -> 检查并修复数据库结构 ----
$fixResult   = null; // [ok => bool, fixed => [], error => string]
if ((isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') && $installed && isset($_POST['action']) && $_POST['action'] === 'fix') {
    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            isset($cfg['db_host']) ? $cfg['db_host'] : '127.0.0.1',
            (int)(isset($cfg['db_port']) ? $cfg['db_port'] : 3306),
            isset($cfg['db_name']) ? $cfg['db_name'] : ''
        );
        $pdo = new PDO($dsn, isset($cfg['db_user']) ? $cfg['db_user'] : '', isset($cfg['db_pass']) ? $cfg['db_pass'] : '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $fixResult = ['ok' => true, 'fixed' => fix_schema($pdo)];
    } catch (Throwable $e) {
        $fixResult = ['ok' => false, 'fixed' => [], 'error' => $e->getMessage()];
    }
}
// ---- POST：执行安装 ----
if ((isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') && !$installed) {
    $db_host  = trim(isset($_POST['db_host']) ? $_POST['db_host'] : '127.0.0.1');
    $db_port  = (int)(isset($_POST['db_port']) ? $_POST['db_port'] : 3306);
    $db_name  = trim(isset($_POST['db_name']) ? $_POST['db_name'] : '');
    $db_user  = trim(isset($_POST['db_user']) ? $_POST['db_user'] : '');
    $db_pass  = (string)(isset($_POST['db_pass']) ? $_POST['db_pass'] : '');
    $token    = trim(isset($_POST['token']) ? $_POST['token'] : '');
    $base_url = trim(isset($_POST['base_url']) ? $_POST['base_url'] : '');

    if ($db_host === '') $errors[] = '请填写数据库主机';
    if ($db_port < 1 || $db_port > 65535) $errors[] = '数据库端口无效';
    if ($db_name === '') $errors[] = '请填写数据库名';
    if ($db_user === '') $errors[] = '请填写数据库用户名';
    if ($token === '') $errors[] = '请填写访问令牌';
    if ($token !== '' && strlen($token) < 8) $errors[] = '访问令牌至少 8 位（建议 16 位以上随机串）';

    if (!$errors) {
        try {
            // 1) 测试数据库连接
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db_host, $db_port, $db_name);
            $pdo = new PDO($dsn, $db_user, $db_pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            // 2) 导入 install.sql（幂等建表）并自动补齐老表缺列
            import_sql_file($pdo, $baseDir . '/install.sql');
            fix_schema($pdo);
            // 3) 写入 config.php（连接成功后才写，避免污染）
            $newCfg = build_config_php($db_host, $db_port, $db_name, $db_user, $db_pass, $token, $base_url);
            if (@file_put_contents($configFile, $newCfg) === false) {
                throw new RuntimeException('config.php 写入失败：请检查站点目录写权限');
            }
            $success = true;
            $step = 3;
        } catch (Throwable $e) {
            $errors[] = '安装失败：' . $e->getMessage();
            $step = 2;
        }
    } else {
        $step = 2;
    }
}

// ---- 环境检查 ----
$checks = [
    'PHP 版本 ≥ 7.4'       => version_compare(PHP_VERSION, '7.4.0', '>='),
    'pdo_mysql 扩展'       => extension_loaded('pdo_mysql'),
    'mbstring 扩展（推荐）' => extension_loaded('mbstring'),
    'config.php 可写'      => is_writable($configFile) || is_writable($baseDir),
    'install.sql 存在'     => is_file($baseDir . '/install.sql'),
];
$allOk = !in_array(false, $checks, true);
$tokenDefault = '';
$hostNow = isset($_SERVER['HTTP_HOST']) ? htmlspecialchars($_SERVER['HTTP_HOST'], ENT_QUOTES) : '';
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseDefault = $scheme . '://' . $hostNow;

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES);
}
function ck($name)
{
    return isset($_POST[$name]) ? h($_POST[$name]) : '';
}
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>IP探针 · 安装向导</title>
<style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;background:linear-gradient(160deg,#070b1a 0%,#0b1030 55%,#101b45 100%);font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#e8ecff;display:flex;align-items:center;justify-content:center;padding:20px}
.card{width:100%;max-width:560px;background:rgba(255,255,255,.05);border:1px solid rgba(90,160,255,.25);border-radius:18px;padding:32px 28px;box-shadow:0 10px 40px rgba(0,0,0,.4)}
h1{margin:0 0 6px;font-size:24px;background:linear-gradient(90deg,#00e5ff,#7c4dff);-webkit-background-clip:text;background-clip:text;color:transparent}
.sub{font-size:13px;color:#8b93b8;margin:0 0 22px}
.steps{display:flex;gap:8px;margin-bottom:24px}
.steps div{flex:1;text-align:center;padding:8px 4px;border-radius:10px;font-size:12px;background:rgba(255,255,255,.06);color:#8b93b8;border:1px solid transparent}
.steps div.on{background:rgba(0,229,255,.12);color:#00e5ff;border-color:rgba(0,229,255,.4)}
.steps div.done{background:rgba(0,255,156,.1);color:#00ff9c;border-color:rgba(0,255,156,.35)}
.row{margin-bottom:14px}
label{display:block;font-size:13px;color:#aab2d8;margin-bottom:6px}
input{width:100%;padding:11px 12px;border-radius:10px;border:1px solid rgba(120,150,255,.3);background:rgba(10,14,35,.7);color:#e8ecff;font-size:14px;outline:none}
input:focus{border-color:#00e5ff}
.chk{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-radius:10px;background:rgba(255,255,255,.04);margin-bottom:8px;font-size:14px}
.chk b.ok{color:#00ff9c}.chk b.bad{color:#ff4d6b}
.btn{display:block;width:100%;padding:13px;border:0;border-radius:12px;background:linear-gradient(90deg,#00c6ff,#7c4dff);color:#fff;font-size:16px;font-weight:600;cursor:pointer;margin-top:8px;text-align:center;text-decoration:none}
.btn:hover{filter:brightness(1.1)}
.btn.gray{background:rgba(255,255,255,.12)}
.err{background:rgba(255,77,107,.12);border:1px solid rgba(255,77,107,.4);color:#ff9db0;padding:10px 14px;border-radius:10px;font-size:13px;margin-bottom:14px;line-height:1.7}
.okbox{background:rgba(0,255,156,.08);border:1px solid rgba(0,255,156,.35);color:#7dffcf;padding:12px 16px;border-radius:10px;font-size:13px;margin-bottom:16px;line-height:1.8;word-break:break-all}
.hint{font-size:12px;color:#8b93b8;line-height:1.8;margin-top:14px}
code{background:rgba(255,255,255,.1);padding:2px 6px;border-radius:6px;color:#00e5ff}
a{color:#00e5ff}
</style>
</head>
<body>
<div class="card">
<?php if ($installed): ?>
    <h1>✅ 系统已安装</h1>
    <p class="sub">IP探针后端已安装完成，无需重复安装。</p>
    <?php if ($fixResult !== null): ?>
        <?php if ($fixResult['ok']): ?>
            <div class="okbox">
                <?php if (count($fixResult['fixed']) > 0): ?>
                    ✅ 数据库结构已修复，自动补齐了 <?php echo count($fixResult['fixed']); ?> 个缺失字段：<br>
                    <code><?php echo h(implode('、', $fixResult['fixed'])); ?></code>
                <?php else: ?>
                    ✅ 检查完成：数据库结构完整，无需修复。
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="err">修复失败：<?php echo h($fixResult['error']); ?></div>
        <?php endif; ?>
    <?php endif; ?>
    <div class="okbox">若探针页报错（如 Unknown column 'access_code'）或创建探针失败，通常是<b>数据库表还是旧结构</b>——点下面按钮一键补齐缺失字段（不丢数据、可重复执行）。</div>
    <form method="post" action="" style="margin-bottom:10px">
        <input type="hidden" name="action" value="fix">
        <button class="btn" type="submit">🔧 检查并修复数据库结构</button>
    </form>
    <div class="okbox">如需重新安装：请先删除或清空站点根目录 <code>config.php</code> 中的数据库密码（改回占位符），再刷新本页。</div>
    <a class="btn" href="/api/ping">前往系统自检（/api/ping）</a>
<?php elseif ($success): ?>
    <h1>🎉 安装完成</h1>
    <p class="sub">数据库已建表，配置已写入。下面是你的系统信息：</p>
    <div class="steps">
        <div class="done">1 环境检查</div>
        <div class="done">2 数据库配置</div>
        <div class="on">3 完成</div>
    </div>
    <div class="okbox">
        服务器地址：<b><?php echo h($baseDefault); ?></b><br>
        访问令牌：<b><?php echo h(isset($_POST['token']) ? $_POST['token'] : $tokenDefault); ?></b><br>
        探针链接前缀：<b><?php echo h(isset($_POST['base_url']) && $_POST['base_url'] !== '' ? $_POST['base_url'] : $baseDefault); ?></b>
    </div>
    <div class="hint">
        1. 在 App「设置」中确认服务器地址与访问令牌（App 内置默认值与本向导一致，直接自动连接即可）。<br>
        2. 点击下方按钮验证接口连通。<br>
        3. <b>建议安装完成后删除本 install.php</b>（不影响系统运行；不删也不影响，已安装状态访问只会看到提示）。
    </div>
    <a class="btn" href="/api/ping">验证系统（/api/ping）</a>
<?php elseif ($step == 1): ?>
    <h1>🚀 IP探针 安装向导</h1>
    <p class="sub">第一步 · 环境检查（全部通过后进入数据库配置）</p>
    <div class="steps">
        <div class="on">1 环境检查</div>
        <div>2 数据库配置</div>
        <div>3 完成</div>
    </div>
    <?php foreach ($checks as $name => $ok): ?>
        <div class="chk"><span><?php echo h($name); ?></span><b class="<?php echo $ok ? 'ok' : 'bad'; ?>"><?php echo $ok ? '通过' : '未通过'; ?></b></div>
    <?php endforeach; ?>
    <?php if (!$allOk): ?>
        <div class="err">存在未通过项：请检查 PHP 版本 / 扩展（宝塔 PHP 设置中安装并启用 pdo_mysql、mbstring），并确认站点目录可写、install.sql 已上传。</div>
    <?php endif; ?>
    <a class="btn" href="?step=2" <?php echo $allOk ? '' : 'onclick="alert(\'请先解决未通过项\');return false;"'; ?>>下一步：数据库配置 →</a>
<?php else: ?>
    <h1>🗄️ 数据库配置</h1>
    <p class="sub">第二步 · 填写宝塔创建的数据库信息（站点需先在宝塔建站并创建 MySQL 数据库）</p>
    <div class="steps">
        <div class="done">1 环境检查</div>
        <div class="on">2 数据库配置</div>
        <div>3 完成</div>
    </div>
    <?php if ($errors): ?>
        <div class="err"><?php foreach ($errors as $e) { echo '· ' . h($e) . '<br>'; } ?></div>
    <?php endif; ?>
    <form method="post" action="?step=3" autocomplete="off">
        <div class="row"><label>数据库主机（本地填 127.0.0.1）</label><input name="db_host" value="<?php echo ck('db_host') !== '' ? ck('db_host') : '127.0.0.1'; ?>"></div>
        <div class="row"><label>数据库端口</label><input name="db_port" value="<?php echo ck('db_port') !== '' ? ck('db_port') : '3306'; ?>"></div>
        <div class="row"><label>数据库名（宝塔创建数据库时填的库名）</label><input name="db_name" value="<?php echo ck('db_name'); ?>" placeholder="如 ip_probe"></div>
        <div class="row"><label>数据库用户名</label><input name="db_user" value="<?php echo ck('db_user'); ?>" placeholder="宝塔创建的数据库用户名"></div>
        <div class="row"><label>数据库密码</label><input type="password" name="db_pass" value="<?php echo ck('db_pass'); ?>" placeholder="宝塔创建的数据库密码"></div>
        <div class="row"><label>访问令牌（App 连接凭证；请设置 16 位以上随机串，App 端填同一个）</label><input name="token" value="<?php echo ck('token'); ?>" placeholder="16 位以上随机串"></div>
        <div class="row"><label>探针链接前缀（留空自动使用当前域名）</label><input name="base_url" value="<?php echo ck('base_url'); ?>" placeholder="<?php echo h($baseDefault); ?>"></div>
        <button class="btn" type="submit">开始安装 →</button>
        <a class="btn gray" href="?step=1">← 上一步</a>
    </form>
    <div class="hint">安装将自动执行：测试数据库连接 → 导入 install.sql 建表 → 生成 config.php。全程不覆盖任何已有数据（建表语句幂等）。</div>
<?php endif; ?>
</div>
</body>
</html>
