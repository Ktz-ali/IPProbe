<?php
/**
 * IP探针 后端入口（统一路由）
 *
 *   GET  /p/{code}            访客点击 -> 记录并返回空白页
 *   GET  /api/ping            连通自检（免鉴权）
 *   POST /api/probe           创建探针   {name?, redirect?}
 *   GET  /api/probe/list      探针列表
 *   DELETE /api/probe/{code}  删除探针
 *   GET  /api/logs?probe=&page=&size=   访问日志
 *
 * 鉴权：除 /p/ 与 /api/ping 外，需 query 参数 ?token= 或请求头 X-Probe-Token
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
// ---- v1.5.4 安装引导：未安装（数据库密码仍为占位符）时，任意路径统一跳转安装向导 ----
$cfgProbe = require __DIR__ . '/config.php';
if (!isset($cfgProbe['db_pass']) || (string)$cfgProbe['db_pass'] === '请改成你的数据库密码') {
    header('Location: /install.php');
    exit;
}

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
$path   = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
$path   = $path === false || $path === null ? '/' : $path;

// ---- 探针点击：/p/{code} ----
if (preg_match('#/p/([a-z0-9]{4,16})$#', $path, $m)) {
    $code = $m[1];
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM probes WHERE code = ?');
    $stmt->execute([$code]);
    $probe = $stmt->fetch();

    // 探针不存在：伪 404
    if (!$probe) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>404 Not Found</title>'
           . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f7f8fa;font-family:sans-serif}.b{text-align:center}.c{font-size:72px;color:#c5c9d0;margin:0}.m{color:#8a919c}</style></head>'
           . '<body><div class="b"><h1 class="c">404</h1><p class="m">页面不存在或已被删除</p></div></body></html>';
        exit;
    }

    // 已停用：提示失效
    if ((int)$probe['enabled'] === 0) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>链接已失效</title>'
           . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f7f8fa;font-family:sans-serif}.b{text-align:center}.c{font-size:56px;color:#e6a23c;margin:0}.m{color:#8a919c;font-size:15px}</style></head>'
           . '<body><div class="b"><h1 class="c">提示</h1><p class="m">该链接已失效或已被发布者关闭</p></div></body></html>';
        exit;
    }
    // ---- v1.5 防护：过期 / 一次性 / 访问码 ----
    $expireDays = isset($probe['expire_days']) ? (int)$probe['expire_days'] : 0;
    if ($expireDays > 0 && !empty($probe['created_at'])) {
        $createdTs = strtotime((string)$probe['created_at']);
        if ($createdTs !== false && $createdTs + $expireDays * 86400 < time()) {
            show_info_page('链接已过期', '该探针链接已超过有效期，请联系发布者获取新链接');
        }
    }
    $onceOnly = isset($probe['once_only']) ? (int)$probe['once_only'] : 0;
    if ($onceOnly === 1) {
        $visitorIp = client_ip();
        $stmt = $pdo->prepare('SELECT id FROM visit_logs WHERE probe = ? AND ip = ? LIMIT 1');
        $stmt->execute([$code, $visitorIp]);
        if ($stmt->fetch()) {
            show_info_page('链接已使用', '该链接仅可访问一次，你已访问过');
        }
    }
    $accessCode = isset($probe['access_code']) ? trim((string)$probe['access_code']) : '';
    if ($accessCode !== '') {
        $cookieName = 'pc_' . $code;
        $posted     = isset($_POST['access']) ? trim((string)$_POST['access']) : '';
        $authed     = isset($_COOKIE[$cookieName]) && hash_equals($accessCode, (string)$_COOKIE[$cookieName]);
        if (!$authed && $posted !== '') {
            $authed = hash_equals($accessCode, $posted);
        }
        if ($authed) {
            if ($posted !== '') {
                setcookie($cookieName, $accessCode, [
                    'expires'  => time() + 2592000,
                    'path'     => '/',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
        } else {
            echo access_code_page($code);
            exit;
        }
    }

    // 记录点击
    try {
        handle_probe_click(
            $pdo,
            $code,
            client_ip(),
            cut_str(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '', 500),
            cut_str(isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '', 500)
        );
    } catch (Throwable $e) {
        // 记录失败也不影响页面展示
    }

    // 按模板渲染访客页面
    render_probe_page($probe);
}

// ---- 连通自检 ----
if (preg_match('#/api/ping$#', $path)) {
    json_out(api_ping());
}
// ---- 访客免鉴权接口（gps_report / weather）需要数据库：提前建立连接，失败返回 JSON 错误 ----
if (preg_match('#/api/(?:gps_report|weather)$#', $path)) {
    try {
        $pdo = db();
    } catch (Throwable $e) {
        json_out(['code' => 1, 'msg' => 'server error: db unavailable']);
    }
}
// ---- GPS 精确定位上报（访客无 token，独立于管理接口鉴权） ----
if (preg_match('#/api/gps_report$#', $path) && $method === 'POST') {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($body)) {
        $body = [];
    }
    json_out(api_gps_report(
        $pdo,
        isset($body['code']) ? $body['code'] : '',
        isset($body['lat']) ? $body['lat'] : null,
        isset($body['lng']) ? $body['lng'] : null,
        isset($body['acc']) ? $body['acc'] : null
    ));
}

// ---- 天气页数据接口（访客用，免 token；code 须为启用探针，可选 lat/lng 定位参数） ----
if (preg_match('#/api/weather$#', $path)) {
    json_out(api_weather(
        $pdo,
        isset($_GET['code']) ? $_GET['code'] : '',
        isset($_GET['lat']) ? $_GET['lat'] : null,
        isset($_GET['lng']) ? $_GET['lng'] : null
    ));
}
// ---- 鉴权 ----
$config = require __DIR__ . '/config.php';
$token  = isset($_GET['token']) ? $_GET['token'] : '';
if ($token === '' && isset($_SERVER['HTTP_X_PROBE_TOKEN'])) {
    $token = $_SERVER['HTTP_X_PROBE_TOKEN'];
}
if ($config['probe_token'] === '' || !hash_equals($config['probe_token'], (string)$token)) {
    json_out(['code' => 1, 'msg' => 'token error']);
}

// ---- 管理接口 ----
try {
    if (!isset($pdo) || $pdo === null) {
        $pdo = db();
    }

    if (preg_match('#/api/stats$#', $path)) {
        json_out(api_stats($pdo));
    }

    if (preg_match('#/api/probe/list$#', $path)) {
        json_out(api_list_probes($pdo));
    }

    if (preg_match('#/api/probe/([a-z0-9]+)/toggle$#', $path, $m) && $method === 'POST') {
        json_out(api_toggle_probe($pdo, $m[1]));
    }

    if (preg_match('#/api/probe/([a-z0-9]+)$#', $path, $m) && $method === 'DELETE') {
        json_out(api_delete_probe($pdo, $m[1]));
    }

    if (preg_match('#/api/probe/([a-z0-9]+)$#', $path, $m) && $method === 'POST') {
        $raw  = file_get_contents('php://input');
        $body = json_decode($raw === false ? '' : $raw, true);
        if (!is_array($body)) {
            $body = [];
        }
        json_out(api_update_probe($pdo, $m[1], $body));
    }

    if (preg_match('#/api/probe$#', $path) && $method === 'POST') {
        $raw  = file_get_contents('php://input');
        $body = json_decode($raw === false ? '' : $raw, true);
        if (!is_array($body)) {
            $body = [];
        }
        json_out(api_create_probe(
            $pdo,
            isset($body['name']) ? $body['name'] : '',
            isset($body['redirect']) ? $body['redirect'] : '',
            isset($body['template']) ? $body['template'] : 'blank',
            isset($body['image_url']) ? $body['image_url'] : '',
            isset($body['text_content']) ? $body['text_content'] : '',
            isset($body['shorten']) ? $body['shorten'] : 'self',
            isset($body['access_code']) ? $body['access_code'] : '',
            isset($body['expire_days']) ? $body['expire_days'] : 0,
            isset($body['once_only']) ? $body['once_only'] : 0
        ));
    }

    if (preg_match('#/api/logs/stats$#', $path)) {
        json_out(api_logs_stats(
            $pdo,
            isset($_GET['days']) ? $_GET['days'] : 30,
            isset($_GET['probe']) ? $_GET['probe'] : ''
        ));
    }
    if (preg_match('#/api/ip_profile$#', $path)) {
        json_out(api_ip_profile($pdo, isset($_GET['ip']) ? $_GET['ip'] : ''));
    }

    if (preg_match('#/api/logs$#', $path) && $method === 'DELETE') {
        json_out(api_clear_logs(
            $pdo,
            isset($_GET['probe']) ? $_GET['probe'] : '',
            isset($_GET['days']) ? $_GET['days'] : 0
        ));
    }
    if (preg_match('#/api/logs$#', $path)) {
        json_out(api_logs(
            $pdo,
            isset($_GET['probe']) ? $_GET['probe'] : '',
            isset($_GET['page']) ? $_GET['page'] : 0,
            isset($_GET['size']) ? $_GET['size'] : 50,
            isset($_GET['days']) ? $_GET['days'] : 0,
            isset($_GET['country']) ? $_GET['country'] : '',
            isset($_GET['device']) ? $_GET['device'] : '',
            isset($_GET['q']) ? $_GET['q'] : ''
        ));
    }
} catch (Throwable $e) {
    json_out(['code' => 1, 'msg' => 'server error: ' . $e->getMessage()]);
}

json_out(['code' => 1, 'msg' => 'not found']);