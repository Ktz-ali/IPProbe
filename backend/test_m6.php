<?php
/**
 * M6 桩数据库全链路测试（不依赖真实 MySQL）
 * 运行: php test_m6.php
 */

require __DIR__ . '/functions.php';

// ---------------- 桩 PDO ----------------

class FakeStmt
{
    public $rows = [];      // SELECT 结果集
    public $inserted = 0;   // INSERT 影响行数
    public $updated = 0;
    public $deleted = 0;    // DELETE 影响行数
    public $params = [];
    public function execute($args = [])
    {
        $this->params = $args;
        return true;
    }
    public function rowCount()
    {
        return $this->inserted + $this->updated + $this->deleted;
    }

    public function fetch()
    {
        return array_shift($this->rows);
    }

    public function fetchAll()
    {
        return $this->rows;
    }

    public function bindValue($k, $v, $t = null)
    {
        $this->params[$k] = $v;
        return true;
    }
}

class FakePDO
{
    public static $probes = [];
    public static $logs = [];
    public static $gpsRate = false;
    public static $hasGpsCol = true;
    public static $hasGuardCol = true;
    public static $ipRows = [];
    public static $onceHit = false;
    public $lastSql = '';
    public $sqlHistory = [];
    public function prepare($sql)
    {
        $this->lastSql = $sql;
        $this->sqlHistory[] = $sql;
        $s = new FakeStmt();
        $s->sql = $sql;

        if (preg_match('/^INSERT IGNORE INTO probes/i', $sql)) {
            $s->inserted = 1; // 成功插入
        } elseif (preg_match('/^INSERT INTO visit_logs/i', $sql)) {
            $s->inserted = 1;
        } elseif (preg_match('/^REPLACE INTO geo_cache/i', $sql)) {
            $s->updated = 1;
        } elseif (preg_match('/^UPDATE visit_logs SET gps_lat/i', $sql)) {
            $s->updated = 1;
        } elseif (preg_match('/^DELETE FROM visit_logs/i', $sql)) {
            $s->deleted = 3;
        } elseif (preg_match('/^SELECT updated_at FROM geo_cache/i', $sql)) {
            $s->rows = FakePDO::$gpsRate ? [['updated_at' => date('Y-m-d H:i:s')]] : [];
        } elseif (preg_match('/^SELECT data FROM geo_cache/i', $sql)) {
            $s->rows = [];
        } elseif (preg_match('/^SELECT \* FROM probes WHERE code = \?$/i', $sql)) {
            $s->rows = self::$probes;
        } elseif (preg_match('/^SELECT code FROM probes WHERE code = \?/i', $sql)) {
            $s->rows = self::$probes;
        } elseif (preg_match('/^SELECT code, name/i', $sql)) {
            $s->rows = self::$probes;
        } elseif (preg_match('/^SELECT enabled FROM probes/i', $sql)) {
            $s->rows = self::$probes;
        } elseif (preg_match('/^SELECT id FROM visit_logs WHERE created_at </i', $sql)) {
            $s->rows = [['id' => 10]];
        } elseif (preg_match('/^SELECT id FROM visit_logs WHERE probe = \? AND ip = \?/i', $sql)) {
            $s->rows = FakePDO::$onceHit ? [['id' => 1]] : [];
        } elseif (preg_match('/^SELECT probe, country, province/i', $sql)) {
            $s->rows = self::$ipRows;
        } elseif (preg_match('/^SELECT probe, ip, country/i', $sql)) {
            $s->rows = self::$logs;
        }
        return $s;
    }

    public function query($sql)
    {
        $this->lastSql = $sql;
        $s = new FakeStmt();
        if (preg_match('/COUNT\(\*\) AS probes/i', $sql)) {
            $s->rows = [['probes' => 2, 'clicks' => 17]];
        } elseif (preg_match('/COUNT\(\*\) AS today/i', $sql)) {
            $s->rows = [['today' => 3]];
        } elseif (preg_match('/COUNT\(\*\) AS total/i', $sql)) {
            $s->rows = [['total' => 42, 'uniq' => 7]];
        } elseif (preg_match('/^SELECT DATE_FORMAT\(created_at/i', $sql)) {
            $s->rows = [['day' => '2026-08-01', 'c' => '5'], ['day' => '2026-08-02', 'c' => '3']];
        } elseif (preg_match('/^SELECT country, COUNT\(\*\) AS c/i', $sql)) {
            $s->rows = [['country' => '中国', 'c' => '10'], ['country' => '美国', 'c' => '2']];
        } elseif (preg_match('/^SELECT CASE WHEN ua REGEXP/i', $sql)) {
            $s->rows = [['device' => 'mobile', 'c' => '8'], ['device' => 'desktop', 'c' => '4']];
        } elseif (preg_match('/^SELECT code, name/i', $sql)) {
            $s->rows = self::$probes;
        } elseif (preg_match('/^SHOW COLUMNS FROM visit_logs/i', $sql)) {
            $s->rows = self::$hasGpsCol
                ? [['Field' => 'gps_lat'], ['Field' => 'gps_lng'], ['Field' => 'gps_acc']]
                : [];
        } elseif (preg_match('/^SHOW COLUMNS FROM probes/i', $sql)) {
            $s->rows = self::$hasGuardCol
                ? [['Field' => 'access_code'], ['Field' => 'expire_days'], ['Field' => 'once_only']]
                : [];
        }
        return $s;
    }
}

$pdo = new FakePDO();

// ---------------- 测试 ----------------

$pass = 0;
$fail = 0;
function check($name, $cond, $extra = '')
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "PASS  $name\n";
    } else {
        $fail++;
        echo "FAIL  $name  $extra\n";
    }
}

// 1) 创建（self 短链，不联网）
$r = api_create_probe($pdo, '测试探针', '', 'blank', '', '', 'self');
check('create probe code=0', $r['code'] === 0);
check('create probe url', strpos($r['data']['url'], '/p/') !== false);
check('create probe self short', $r['data']['short_url'] === $r['data']['url'] && $r['data']['provider'] === 'self');

// 2) 创建（模板+内容字段传递）
$r2 = api_create_probe($pdo, '跳转探针', 'https://example.com', 'redirect', 'https://img.example.com/a.jpg', '你好世界', 'self');
check('create probe2 code=0', $r2['code'] === 0);

// 3) 白名单
check('valid_template ok', valid_template('FAKE404') === 'fake404');
check('valid_template bad->blank', valid_template('evil') === 'blank');
check('valid_provider ok', valid_provider('TinyURL') === 'tinyurl');
check('valid_provider bad->self', valid_provider('xxx') === 'self');

// 4) 列表（模拟数据）
FakePDO::$probes = [
    ['code' => 'abc123', 'name' => 'A', 'redirect' => '', 'template' => 'blank', 'image_url' => '', 'text_content' => '', 'short_url' => 'https://tinyurl.com/x1', 'short_provider' => 'tinyurl', 'enabled' => '1', 'created' => '1700000000000', 'visit_count' => '5', 'last_visit' => '1700000100000'],
    ['code' => 'def456', 'name' => 'B', 'redirect' => '', 'template' => 'redirect', 'image_url' => '', 'text_content' => '', 'short_url' => '', 'short_provider' => '', 'enabled' => '0', 'created' => '1700000000000', 'visit_count' => '12', 'last_visit' => null],
];
$r = api_list_probes($pdo);
check('list code=0', $r['code'] === 0);
check('list count=2', count($r['data']['list']) === 2);
check('list enabled int', $r['data']['list'][0]['enabled'] === 1 && $r['data']['list'][1]['enabled'] === 0);
check('list template field', $r['data']['list'][1]['template'] === 'redirect');

// 5) 编辑
FakePDO::$probes = [['code' => 'abc123']];
$r = api_update_probe($pdo, 'abc123', ['name' => '新名字', 'template' => 'text', 'text_content' => '内容']);
check('update code=0', $r['code'] === 0);
check('update sql has fields', strpos($pdo->lastSql, 'name') !== false && strpos($pdo->lastSql, 'template') !== false && strpos($pdo->lastSql, 'updated_at') !== false);
FakePDO::$probes = [];
$r = api_update_probe($pdo, 'nofound', ['name' => 'x']);
check('update not found', $r['code'] === 1);
// 5b) 编辑时更换短链服务（防红：换回 self）
FakePDO::$probes = [['code' => 'abc123']];
$r = api_update_probe($pdo, 'abc123', ['shorten' => 'self']);
check('update shorten code=0', $r['code'] === 0);
check('update shorten sql', strpos($pdo->lastSql, 'short_url') !== false && strpos($pdo->lastSql, 'short_provider') !== false);
$cfgBase = rtrim((string)(require __DIR__ . '/config.php')['base_url'], '/');
check('update shorten returns self url', $r['data']['short_url'] === $cfgBase . '/p/abc123' && $r['data']['short_provider'] === 'self');

// 6) 开关
FakePDO::$probes = [['code' => 'abc123', 'enabled' => 0]];
$r = api_toggle_probe($pdo, 'abc123');
check('toggle code=0', $r['code'] === 0 && $r['data']['enabled'] === 0);
FakePDO::$probes = [];
$r = api_toggle_probe($pdo, 'nofound');
check('toggle not found', $r['code'] === 1);

// 7) 统计
$r = api_stats($pdo);
check('stats code=0', $r['code'] === 0);
check('stats values', $r['data']['probes'] === 2 && $r['data']['clicks'] === 17 && $r['data']['today'] === 3);

// 8) 日志
FakePDO::$logs = [['ip' => '1.2.3.4', 'country' => '中国', 'province' => '广东', 'city' => '深圳', 'isp' => '电信', 'lat' => '22.5431', 'lng' => '114.0579', 'zip' => '518000', 'timezone' => 'Asia/Shanghai', 'org' => '', 'as_info' => 'AS4134', 'is_ipv6' => '0', 'gps_lat' => null, 'gps_lng' => null, 'gps_acc' => null, 'ua' => 'ua', 'referer' => 'ref', 'time' => '1700000000000']];
$r = api_logs($pdo, 'abc123', 0, 50);
check('logs code=0', $r['code'] === 0 && count($r['data']['list']) === 1);
check('logs time int', $r['data']['list'][0]['time'] === 1700000000000);
check('logs lat/lng float', $r['data']['list'][0]['lat'] === 22.5431 && $r['data']['list'][0]['lng'] === 114.0579);
check('logs is_ipv6 int', $r['data']['list'][0]['is_ipv6'] === 0);
check('logs extra fields', $r['data']['list'][0]['zip'] === '518000' && $r['data']['list'][0]['as_info'] === 'AS4134');
$r = api_logs($pdo, '', 0, 50);
check('logs all mode ok', $r['code'] === 0 && count($r['data']['list']) >= 1);
check('logs gps fields present', array_key_exists('gps_lat', $r['data']['list'][0]) && $r['data']['list'][0]['gps_lat'] === null);

// 8c) GPS 精确定位上报
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
FakePDO::$probes = [['code' => 'abc123', 'enabled' => 1]];
$r = api_gps_report($pdo, 'abc123', 29.7048, 116.0021, 15);
check('gps report ok', $r['code'] === 0 && $r['data']['ok'] === 1 && $r['data']['lat'] === 29.7048);
check('gps report sql fields', strpos($pdo->lastSql, 'gps_lat') !== false && strpos($pdo->lastSql, 'gps_acc') !== false);
$r = api_gps_report($pdo, 'abc123', 999, 0, 0);
check('gps coords out of range', $r['code'] === 1);
$r = api_gps_report($pdo, 'bad code!', 1, 1, 0);
check('gps bad code', $r['code'] === 1);
FakePDO::$gpsRate = true;
$r = api_gps_report($pdo, 'abc123', 29.7, 116.0, 15);
check('gps rate limited', $r['code'] === 1);
FakePDO::$gpsRate = false;
// 8c2) v1.5 GPS 上报探针存在性校验
FakePDO::$probes = [];
$r = api_gps_report($pdo, 'zzzz99', 29.7, 116.0, 15);
check('gps probe not found', $r['code'] === 1 && $r['msg'] === 'probe not found');
FakePDO::$probes = [['code' => 'abc123', 'enabled' => 0]];
$r = api_gps_report($pdo, 'abc123', 29.7, 116.0, 15);
check('gps probe disabled', $r['code'] === 1 && $r['msg'] === 'probe disabled');
FakePDO::$probes = [];

// 8d) 老库降级（未导入 upgrade_v1.3.sql 时自动不查 GPS 列）
FakePDO::$hasGpsCol = false;
visit_logs_has_gps($pdo, true);
$r = api_logs($pdo, 'abc123', 0, 50);
check('logs degraded code=0', $r['code'] === 0);
check('logs degraded gps null', $r['data']['list'][0]['gps_lat'] === null && $r['data']['list'][0]['gps_acc'] === null);
check('logs degraded sql no gps col', strpos($pdo->lastSql, 'gps_lat') === false);
$r = api_gps_report($pdo, 'abc123', 29.7, 116.0, 15);
check('gps report degraded rejected', $r['code'] === 1);
FakePDO::$hasGpsCol = true;
visit_logs_has_gps($pdo, true);

// 9a) 日志清理（CA）
$r = api_clear_logs($pdo, 'abc123', 0);
check('clear probe logs ok', $r['code'] === 0 && $r['data']['deleted'] === 3);
check('clear probe sql where', strpos($pdo->lastSql, 'WHERE probe') !== false);
$r = api_clear_logs($pdo, '', 0);
check('clear all logs ok', $r['code'] === 0 && $r['data']['deleted'] === 3);
check('clear all sql no where', strpos($pdo->lastSql, 'WHERE probe') === false && stripos($pdo->lastSql, 'INTERVAL') === false);
$r = api_clear_logs($pdo, '', 7);
check('clear by days ok', $r['code'] === 0 && $r['data']['deleted'] === 3);
check('clear days sql interval', stripos($pdo->lastSql, 'INTERVAL') !== false);
$histClear = array_slice($pdo->sqlHistory, -4);
$hasPkRange = false;
foreach ($histClear as $sql) {
    if (strpos($sql, 'DELETE FROM visit_logs WHERE id <=') !== false) {
        $hasPkRange = true;
    }
}
check('clear days pk range delete', $hasPkRange);

// 9b) v1.5 探针防护字段（创建/编辑/列表）
$r = api_create_probe($pdo, '防护探针', '', 'blank', '', '', 'self', '8888', 7, 1);
check('create guard probe code=0', $r['code'] === 0);
$guardInsert = '';
foreach (array_slice($pdo->sqlHistory, -6) as $sql) {
    if (stripos($sql, 'INSERT IGNORE INTO probes') !== false) {
        $guardInsert = $sql;
    }
}
check('create guard insert cols', strpos($guardInsert, 'access_code') !== false && strpos($guardInsert, 'expire_days') !== false && strpos($guardInsert, 'once_only') !== false);
FakePDO::$probes = [['code' => 'abc123']];
$r = api_update_probe($pdo, 'abc123', ['access_code' => '9999', 'expire_days' => 30, 'once_only' => 1]);
check('update guard code=0', $r['code'] === 0);
check('update guard sql', strpos($pdo->lastSql, 'access_code') !== false && strpos($pdo->lastSql, 'once_only') !== false);
FakePDO::$probes = [
    ['code' => 'abc123', 'name' => 'A', 'redirect' => '', 'template' => 'blank', 'image_url' => '', 'text_content' => '', 'short_url' => '', 'short_provider' => '', 'access_code' => '8888', 'expire_days' => '7', 'once_only' => '1', 'enabled' => '1', 'created' => '1700000000000', 'visit_count' => '5', 'last_visit' => '1700000100000'],
];
$r = api_list_probes($pdo);
check('list guard code=0', $r['code'] === 0);
check('list guard fields', $r['data']['list'][0]['access_code'] === '8888' && $r['data']['list'][0]['expire_days'] === 7 && $r['data']['list'][0]['once_only'] === 1);

// 9c) v1.5 日志筛选（days/country/device/q）
FakePDO::$probes = [['code' => 'abc123', 'enabled' => 1]];
$r = api_logs($pdo, 'abc123', 0, 50, 7, '', '', '');
check('logs days filter sql', stripos($pdo->lastSql, 'INTERVAL') !== false && stripos($pdo->lastSql, 'created_at') !== false);
$r = api_logs($pdo, '', 0, 50, 0, '中国', 'mobile', 'test');
check('logs combo filter ok', $r['code'] === 0);
check('logs combo filter sql', stripos($pdo->lastSql, 'REGEXP') !== false && stripos($pdo->lastSql, 'LIKE') !== false && stripos($pdo->lastSql, 'country = ?') !== false);
$r = api_logs($pdo, '', 0, 50);
check('logs no filter sql clean', stripos($pdo->lastSql, 'WHERE') === false);
check('logs includes probe col', stripos($pdo->lastSql, 'SELECT probe, ip') !== false);

// 9d) v1.5 统计图表接口
$r = api_logs_stats($pdo, 30, '');
check('stats detail code=0', $r['code'] === 0);
check('stats detail totals', $r['data']['total'] === 42 && $r['data']['uniq'] === 7);
check('stats detail trend', count($r['data']['trend']) === 2 && $r['data']['trend'][0]['count'] === 5);
check('stats detail countries', $r['data']['countries'][0]['name'] === '中国' && $r['data']['countries'][0]['count'] === 10);
check('stats detail devices', $r['data']['devices'][0]['name'] === 'mobile');
FakePDO::$probes = [];
$r = api_logs_stats($pdo, 30, 'nofound');
check('stats detail probe not found', $r['code'] === 1);

// 9e) v1.5 同 IP 画像
FakePDO::$ipRows = [
    ['probe' => 'abc123', 'country' => '中国', 'province' => '广东', 'city' => '深圳', 'isp' => '电信', 'gps_lat' => '22.5', 'gps_lng' => '114.0', 'ua' => 'Mozilla/5.0 (Linux; Android 14) AppleWebKit', 'time' => '1700000100000'],
    ['probe' => 'def456', 'country' => '中国', 'province' => '广东', 'city' => '广州', 'isp' => '电信', 'gps_lat' => null, 'gps_lng' => null, 'ua' => 'Mozilla/5.0 (Windows NT 10.0)', 'time' => '1700000000000'],
];
$r = api_ip_profile($pdo, '1.2.3.4');
check('ip profile code=0', $r['code'] === 0);
check('ip profile total', $r['data']['total'] === 2);
check('ip profile probes', $r['data']['probes'] === ['abc123', 'def456']);
check('ip profile first/last', $r['data']['first'] === 1700000000000 && $r['data']['last'] === 1700000100000);
check('ip profile has gps', $r['data']['has_gps'] === true);
check('ip profile devices', $r['data']['devices'][0]['name'] === '手机' && $r['data']['devices'][1]['name'] === '电脑');
FakePDO::$ipRows = [];
$r = api_ip_profile($pdo, '9.9.9.9');
check('ip profile not found', $r['code'] === 1 && $r['msg'] === 'ip not found');
check('ip profile bad ip', api_ip_profile($pdo, '')['code'] === 1);

// 9f) v1.5 访客防护页辅助函数
check('access_code_page html', strpos(access_code_page('abc123'), '访问码') !== false && strpos(access_code_page('abc123'), 'name="access"') !== false);
// 删除探针级联清理日志
$countBefore = count($pdo->sqlHistory);
$r = api_delete_probe($pdo, 'abc123');
check('delete probe ok', $r['code'] === 0);
$hist = array_slice($pdo->sqlHistory, $countBefore);
check('delete probe cascade logs', count($hist) >= 2 && strpos($hist[0], 'DELETE FROM visit_logs') !== false);

// 8b) 定位增强 + IPv6
check('v6_to_v4 plain v4', v6_to_v4('1.2.3.4') === '1.2.3.4');
check('v6_to_v4 mapped', v6_to_v4('::ffff:1.2.3.4') === '1.2.3.4');
check('v6_to_v4 pure v6 false', v6_to_v4('2408:8207::1') === false);
check('coords_offline city hit', coords_offline('中国', '广东省', '深圳市') === [22.5431, 114.0579]);
check('coords_offline city clean', coords_offline('中国', '广东省', '深圳') === [22.5431, 114.0579]);
$co = coords_offline('中国', '河北省', '某未知小城');
check('coords_offline province fallback', $co === [38.0428, 114.5149]);
$co2 = coords_offline('United States', 'California', '0');
check('coords_offline country fallback(en)', $co2 === [39.50, -98.35]);
check('coords_offline unknown null', coords_offline('火星', 'X', 'Y') === null);
// geo_locate 离线（IPv4：8.8.8.8 -> 美国，国家兜底坐标）
$g = geo_locate($pdo, '8.8.8.8', false);
check('geo_locate v4 country', $g['country'] === 'United States');
check('geo_locate v4 coords', $g['lat'] === 39.50 && $g['lng'] === -98.35);
check('geo_locate v4 is_ipv6=0', $g['is_ipv6'] === 0);
// geo_locate 离线（IPv4 映射 IPv6）
$g2 = geo_locate($pdo, '::ffff:8.8.8.8', false);
check('geo_locate mapped v6 -> v4', $g2['country'] === 'United States' && $g2['lat'] === 39.50);
// geo_locate 离线（纯 IPv6：v6 库离线定位，无需在线即可有归属与坐标）
$g3 = geo_locate($pdo, '2408:8207:2462:1ab0::1', false);
check('geo_locate pure v6 flagged', $g3['is_ipv6'] === 1);
check('geo_locate pure v6 offline region', $g3['country'] === '中国' && $g3['province'] === '北京市');
check('geo_locate pure v6 offline coords', $g3['lat'] === 39.9042 && $g3['lng'] === 116.4074);
// ip_region 双库选择
$r6 = ip_region('2404:6800:4005:80c::200e');
check('ip_region v6 hk', $r6['country'] === '中国' && $r6['province'] === '香港特别行政区');
$r7 = ip_region('8.8.8.8');
check('ip_region v4 google', $r7['country'] === 'United States');
// handle_probe_click 落库新字段
handle_probe_click($pdo, 'abc123', '1.2.3.4', 'ua-test', '');
$insertSql = '';
foreach ($pdo->sqlHistory as $sql) {
    if (stripos($sql, 'INSERT INTO visit_logs') !== false) {
        $insertSql = $sql;
    }
}
check('click insert has geo cols', strpos($insertSql, 'lat') !== false && strpos($insertSql, 'lng') !== false && strpos($insertSql, 'is_ipv6') !== false);

// 9) 真实短链（联网；clck.ru 可能临时限流，失败回退 self 也算正确行为）
$r = make_short_url('https://example.com/p/test888', 'tinyurl');
check('tinyurl real or fallback', (strpos($r['url'], 'tinyurl.com/') !== false && $r['provider'] === 'tinyurl') || $r['provider'] === 'self', $r['url']);
$r = make_short_url('https://example.com/p/test888', 'clckru');
check('clckru real or fallback', (strpos($r['url'], 'clck.ru/') !== false && $r['provider'] === 'clckru') || $r['provider'] === 'self', $r['url']);
$r = make_short_url('https://example.com/p/test888', 'auto');
check('auto now self (防红)', $r['url'] === 'https://example.com/p/test888' && $r['provider'] === 'self', $r['url']);
$r = make_short_url('https://example.com/p/test888', 'badprovider');
check('bad provider fallback self', $r['url'] === 'https://example.com/p/test888' && $r['provider'] === 'self');

// 10) v1.5.2 腾讯地图集成（纯函数桩测试，不依赖 HTTP）
// 签名：参数按参数名升序，与传参顺序无关
$sig1 = tencent_sign('/ws/geocoder/v1/', ['location' => '29.0315,114.9350', 'key' => 'KEY123'], 'SK456');
$sig2 = tencent_sign('/ws/geocoder/v1/', ['key' => 'KEY123', 'location' => '29.0315,114.9350'], 'SK456');
check('tencent_sign param order insensitive', $sig1 === $sig2);
check('tencent_sign known value', $sig1 === md5('/ws/geocoder/v1/?key=KEY123&location=29.0315,114.9350SK456'));
// 腾讯 IP 定位解析
$tj = ['status' => 0, 'result' => ['location' => ['lat' => 29.02533, 'lng' => 114.54688],
    'ad_info' => ['nation' => '中国', 'province' => '江西省', 'city' => '九江市', 'district' => '修水县', 'adcode' => 360424]]];
$tr = parse_tencent_ip($tj);
check('parse_tencent_ip ok province', $tr['province'] === '江西');
check('parse_tencent_ip ok city', $tr['city'] === '九江');
check('parse_tencent_ip ok district', $tr['district'] === '修水');
check('parse_tencent_ip ok coords', abs($tr['lat'] - 29.02533) < 1e-9 && abs($tr['lng'] - 114.54688) < 1e-9);
check('parse_tencent_ip bad status', parse_tencent_ip(['status' => 347]) === null);
check('parse_tencent_ip foreign', parse_tencent_ip(['status' => 0, 'result' => ['ad_info' => ['nation' => '美国', 'province' => 'CA', 'city' => 'LA', 'district' => '']]]) === null);
check('parse_tencent_ip empty', parse_tencent_ip(['status' => 0, 'result' => ['ad_info' => ['nation' => '中国', 'province' => '', 'city' => '', 'district' => '']]]) === null);
// 腾讯逆地理解析
$tj2 = ['status' => 0, 'result' => ['address' => '江西省九江市武宁县Y150',
    'address_component' => ['nation' => '中国', 'province' => '江西省', 'city' => '九江市', 'district' => '武宁县', 'street' => 'Y150', 'street_number' => '']]];
check('parse_tencent_reverse ok', parse_tencent_reverse($tj2) === '中国江西省九江市武宁县Y150');
check('parse_tencent_reverse bad', parse_tencent_reverse(['status' => 111]) === '');
check('parse_tencent_reverse fallback addr', parse_tencent_reverse(['status' => 0, 'result' => ['address' => '某省某市某路1号']]) === '某省某市某路1号');
// 省名清理
check('clean_prov plain', clean_prov_name('江西省') === '江西');
check('clean_prov municipality', clean_prov_name('北京市') === '北京');
check('clean_prov auto region', clean_prov_name('广西壮族自治区') === '广西');
check('clean_prov inner mongolia', clean_prov_name('内蒙古自治区') === '内蒙古');
check('clean_prov hk', clean_prov_name('香港特别行政区') === '香港');
// 11) v1.5.3 高德地图集成（纯函数桩测试，不依赖 HTTP）
$aj = ['status' => '1', 'province' => '江西省', 'city' => '九江市', 'adcode' => '360400', 'rectangle' => 'x;y'];
$ar = parse_amap_ip($aj);
check('parse_amap_ip ok province', $ar['province'] === '江西');
check('parse_amap_ip ok city', $ar['city'] === '九江');
check('parse_amap_ip ok adcode', $ar['adcode'] === '360400');
check('parse_amap_ip bad', parse_amap_ip(['status' => '0', 'info' => 'INVALID_USER_KEY']) === null);
// IPv6/香港出口等场景高德返回空数组字段 -> 不应报 Array to string conversion
$arEmpty = parse_amap_ip(['status' => '1', 'info' => 'OK', 'province' => [], 'city' => [], 'adcode' => []]);
check('parse_amap_ip empty-array fields', $arEmpty === null);
$arj = ['status' => '1', 'regeocode' => ['formatted_address' => '江西省九江市修水县义宁镇散原路169号恒丰花园',
    'addressComponent' => ['city' => '九江市', 'province' => '江西省', 'adcode' => '360424', 'district' => '修水县', 'towncode' => '360424100000', 'township' => '义宁镇']]];
$arr = parse_amap_reverse($arj);
check('parse_amap_reverse ok addr', strpos($arr['addr'], '恒丰花园') !== false);
check('parse_amap_reverse ok township', $arr['township'] === '义宁镇');
check('parse_amap_reverse ok adcode', $arr['adcode'] === '360424');
// 重复区名原文 -> 结构化去重
$arj2 = ['status' => '1', 'regeocode' => ['formatted_address' => '江西省九江市浔阳区人民路街道浔阳区九江市浔阳区人民政府',
    'addressComponent' => ['city' => '九江市', 'province' => '江西省', 'adcode' => '360403', 'district' => '浔阳区', 'township' => '人民路街道']]];
$arr2 = parse_amap_reverse($arj2);
check('parse_amap_reverse dedup', $arr2['addr'] === '江西省九江市浔阳区人民路街道');
// 带门牌字段 -> 结构化含街道门牌
$arj3 = ['status' => '1', 'regeocode' => ['formatted_address' => '江西省九江市修水县义宁镇散原路169号',
    'addressComponent' => ['city' => '九江市', 'province' => '江西省', 'adcode' => '360424', 'district' => '修水县', 'township' => '义宁镇',
        'streetNumber' => ['street' => '散原路', 'number' => '169号']]]];
$arr3 = parse_amap_reverse($arj3);
check('parse_amap_reverse streetnum', $arr3['addr'] === '江西省九江市修水县义宁镇散原路169号');
// 乡村坐标：streetNumber 各字段为空数组 -> 不报错，结构化仍可用
$arj4 = ['status' => '1', 'regeocode' => ['formatted_address' => '江西省九江市修水县黄沙镇',
    'addressComponent' => ['city' => '九江市', 'province' => '江西省', 'adcode' => '360424', 'district' => '修水县', 'township' => '黄沙镇',
        'streetNumber' => ['street' => [], 'number' => [], 'direction' => [], 'distance' => []]]]];
$arr4 = parse_amap_reverse($arj4);
check('parse_amap_reverse rural empty streetNumber', $arr4['addr'] === '江西省九江市修水县黄沙镇');
check('parse_amap_reverse bad', parse_amap_reverse(['status' => '0']) === null);
// 天气组装
$wAll = ['forecasts' => [['city' => '修水县', 'adcode' => '360424', 'reporttime' => '2026-08-28 00:37:31',
    'casts' => [['date' => '2026-08-28', 'week' => '5', 'dayweather' => '阴', 'nightweather' => '阵雨', 'daytemp' => '36', 'nighttemp' => '25', 'daywind' => '北', 'daypower' => '1-3']]]]];
$wBase = ['lives' => [['city' => '九江市', 'weather' => '晴', 'temperature' => '27', 'humidity' => '85', 'winddirection' => '东', 'windpower' => '≤3', 'reporttime' => '2026-08-28 00:37:31']]];
$wd = build_weather_data(null, $wAll, $wBase);
check('weather city', $wd['city'] === '修水县');
check('weather now temp', $wd['now']['temp'] === '27' && $wd['now']['humidity'] === '85');
check('weather now wind', $wd['now']['wind'] === '东风≤3级');
check('weather forecasts len', count($wd['forecasts']) === 1 && $wd['forecasts'][0]['daytemp'] === '36');
check('weather located false', $wd['located'] === false);
$wd2 = build_weather_data(['addr' => 'xx', 'adcode' => '360424', 'township' => '何市镇'], $wAll, $wBase);
check('weather located true', $wd2['located'] === true && $wd2['township'] === '何市镇');
check('weather fallback now', build_weather_data(null, $wAll, null)['now']['temp'] === '36');
check('weather_emoji', weather_emoji('雷阵雨') === '⛈️' && weather_emoji('晴') === '☀️' && weather_emoji('多云') === '⛅');
echo "\n===== RESULT: $pass passed, $fail failed =====\n";
exit($fail > 0 ? 1 : 0);