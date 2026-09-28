<?php
/**
 * 业务处理层：探针点击记录 + 管理接口
 * 全部使用参数化查询，兼容 MySQL 5.7 / 8.0，PHP 7.4 ~ 8.4
 */

require_once __DIR__ . '/ip2region/Searcher.class.php';

use ip2region\xdb\Searcher;
use ip2region\xdb\IPv4;
use ip2region\xdb\IPv6;
use ip2region\xdb\Util;

// ---------------- 工具 ----------------

/** 安全截断（无 mbstring 时回退 substr） */
function cut_str($s, $n)
{
    $s = (string)$s;
    return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n);
}

/** 随机短码（6 位小写字母+数字） */
function gen_code($n = 6)
{
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $code = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $n; $i++) {
        $code .= $chars[random_int(0, $max)];
    }
    return $code;
}

/** 当前访问基地址（配置为空时自动识别） */
function base_url()
{
    static $url = null;
    if ($url !== null) {
        return $url;
    }
    $c = require __DIR__ . '/config.php';
    if (!empty($c['base_url'])) {
        $url = rtrim($c['base_url'], '/');
        return $url;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    $url = $scheme . '://' . $host;
    return $url;
}

/** 提取客户端真实 IP */
function client_ip()
{
    $ip = '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($parts[0]);
    } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = $_SERVER['HTTP_X_REAL_IP'];
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

/** JSON 输出并结束 */
function json_out($data)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------- ip2region ----------------

$GLOBALS['__xdb_searcher'] = null;
$GLOBALS['__xdb_searcher_v6'] = null;

/** 获取 xdb 查询器（$v6=true 返回 IPv6 库，false 返回 IPv4 库），失败返回 false */
function xdb_searcher($v6 = false)
{
    if ($v6) {
        if ($GLOBALS['__xdb_searcher_v6'] === null) {
            try {
                $dbFile = __DIR__ . '/ip2region_v6.xdb';
                if (is_file($dbFile)) {
                    $vIndex = Util::loadVectorIndexFromFile($dbFile);
                    $GLOBALS['__xdb_searcher_v6'] = Searcher::newWithVectorIndex(
                        IPv6::default(), $dbFile, $vIndex
                    );
                } else {
                    $GLOBALS['__xdb_searcher_v6'] = false;
                }
            } catch (Throwable $e) {
                $GLOBALS['__xdb_searcher_v6'] = false;
            }
        }
        return $GLOBALS['__xdb_searcher_v6'];
    }
    if ($GLOBALS['__xdb_searcher'] === null) {
        try {
            $dbFile = __DIR__ . '/ip2region.xdb';
            if (is_file($dbFile)) {
                $vIndex = Util::loadVectorIndexFromFile($dbFile);
                $GLOBALS['__xdb_searcher'] = Searcher::newWithVectorIndex(
                    IPv4::default(), $dbFile, $vIndex
                );
            } else {
                $GLOBALS['__xdb_searcher'] = false;
            }
        } catch (Throwable $e) {
            $GLOBALS['__xdb_searcher'] = false;
        }
    }
    return $GLOBALS['__xdb_searcher'];
}

/** 返回 [country, province, city, isp]，失败返回空串（支持 IPv4/IPv6 双库） */
function ip_region($ip)
{
    $empty = ['country' => '', 'province' => '', 'city' => '', 'isp' => ''];
    $ip = trim((string)$ip);
    if ($ip === '') {
        return $empty;
    }
    $isV6 = strpos($ip, ':') !== false;
    $s = xdb_searcher($isV6);
    if (!$s) {
        return $empty;
    }
    try {
        $region = $s->search($ip);
    } catch (Throwable $e) {
        return $empty;
    }
    $parts = explode('|', (string)$region);
    return [
        'country'  => isset($parts[0]) ? $parts[0] : '',
        'province' => isset($parts[1]) ? $parts[1] : '',
        'city'     => isset($parts[2]) ? $parts[2] : '',
        'isp'      => isset($parts[3]) ? $parts[3] : '',
    ];
}

// ---------------- 定位增强：经纬度 / IPv6 ----------------

/** IPv4-mapped IPv6（::ffff:x.x.x.x）转 IPv4；普通 IPv4 原样返回；纯 IPv6 返回 false */
function v6_to_v4($ip)
{
    $ip = trim((string)$ip);
    if (strpos($ip, ':') === false) {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : false;
    }
    $lower = strtolower($ip);
    if (strpos($lower, '::ffff:') === 0) {
        $v4 = substr($ip, 7);
        return filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $v4 : false;
    }
    return false;
}

/** 定位配置（online_geo_enable / baidu_ak），static 缓存 */
function geo_config()
{
    static $c = null;
    if ($c === null) {
        $cfg = require __DIR__ . '/config.php';
        $c = isset($cfg['online_geo_enable']) ? (bool)$cfg['online_geo_enable'] : true;
    }
    return $c;
}

/** 百度地图 AK（服务端配置，留空禁用百度能力），static 缓存 */
function geo_baidu_ak()
{
    static $ak = null;
    if ($ak === null) {
        $cfg = require __DIR__ . '/config.php';
        $ak = isset($cfg['baidu_ak']) ? trim((string)$cfg['baidu_ak']) : '';
    }
    return $ak;
}

/** 腾讯位置服务 Key / SK（v1.5.2，服务端配置，留空禁用腾讯能力），static 缓存 */
function geo_tencent_cfg()
{
    static $c = null;
    if ($c === null) {
        $cfg = require __DIR__ . '/config.php';
        $c = [
            'key' => isset($cfg['tencent_key']) ? trim((string)$cfg['tencent_key']) : '',
            'sk'  => isset($cfg['tencent_sk']) ? trim((string)$cfg['tencent_sk']) : '',
        ];
    }
    return $c;
}
/** 腾讯 WebService 签名：参数按参数名升序拼接后接 SK 取 MD5（实测确认的规则；请求 URL 参数顺序需与签名串一致） */
function tencent_sign($path, $params, $sk)
{
    ksort($params);
    $q = '';
    foreach ($params as $k => $v) {
        $q .= ($q === '' ? '' : '&') . $k . '=' . $v;
    }
    return md5($path . '?' . $q . $sk);
}
/** 名称清理：去掉尾部行政区后缀（"江西省"->"江西"，"广西壮族自治区"->"广西"，"九江市"->"九江"） */
function clean_prov_name($p)
{
    $p = preg_replace('/(壮族|回族|维吾尔)?自治区$/', '', $p);
    $p = preg_replace('/特别行政区$/', '', $p);
    return rtrim($p, '省市');
}
/** 在线查询限流（每分钟最多 40 次，文件计数） */
function geo_rate_ok($limit = 40)
{
    $f = sys_get_temp_dir() . '/ip_probe_geo_rl';
    $now = time();
    $win = [];
    if (is_file($f)) {
        $raw = @file_get_contents($f);
        $dec = json_decode((string)$raw, true);
        if (is_array($dec)) {
            $win = $dec;
        }
    }
    $win = array_values(array_filter($win, function ($t) use ($now) {
        return is_int($t) && ($now - $t) < 60;
    }));
    if (count($win) >= $limit) {
        @file_put_contents($f, json_encode($win));
        return false;
    }
    $win[] = $now;
    @file_put_contents($f, json_encode($win));
    return true;
}

/** 缓存读取（7 天内有效），失败返回 null */
function geo_cache_get($pdo, $ip)
{
    try {
        $stmt = $pdo->prepare('SELECT data FROM geo_cache WHERE ip = ? AND updated_at > (NOW() - INTERVAL 7 DAY)');
        $stmt->execute([$ip]);
        $row = $stmt->fetch();
        if ($row) {
            $d = json_decode($row['data'], true);
            if (is_array($d)) {
                return $d;
            }
        }
    } catch (Throwable $e) {
        // 缓存表不存在等场景，忽略
    }
    return null;
}

/** 缓存写入（失败静默） */
function geo_cache_set($pdo, $ip, $data)
{
    try {
        $stmt = $pdo->prepare('REPLACE INTO geo_cache (ip, data, updated_at) VALUES (?, ?, NOW())');
        $stmt->execute([$ip, json_encode($data, JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) {
        // 忽略
    }
}

/** 中国省市英文名 → 中文映射（ipinfo 字段转中文显示） */
function geo_cn_map()
{
    static $m = null;
    if ($m !== null) {
        return $m;
    }
    $m = [
        'prov' => [
            'Beijing' => '北京市', 'Shanghai' => '上海市', 'Tianjin' => '天津市', 'Chongqing' => '重庆市',
            'Jiangxi' => '江西省', 'Guangdong' => '广东省', 'Zhejiang' => '浙江省', 'Jiangsu' => '江苏省',
            'Sichuan' => '四川省', 'Hubei' => '湖北省', 'Hunan' => '湖南省', 'Fujian' => '福建省',
            'Shandong' => '山东省', 'Henan' => '河南省', 'Anhui' => '安徽省', 'Hebei' => '河北省',
            'Liaoning' => '辽宁省', 'Shaanxi' => '陕西省', 'Shanxi' => '山西省', 'Yunnan' => '云南省',
            'Guizhou' => '贵州省', 'Guangxi' => '广西壮族自治区', 'Hainan' => '海南省', 'Jilin' => '吉林省',
            'Heilongjiang' => '黑龙江省', 'Gansu' => '甘肃省', 'Qinghai' => '青海省',
            'Inner Mongolia' => '内蒙古自治区', 'Tibet' => '西藏自治区', 'Xinjiang' => '新疆维吾尔自治区',
            'Ningxia' => '宁夏回族自治区', 'Hong Kong' => '香港特别行政区', 'Macau' => '澳门特别行政区',
            'Taiwan' => '台湾省',
        ],
        'city' => [
            'Nanchang' => '南昌市', 'Jiujiang' => '九江市', 'Ganzhou' => '赣州市', 'Yichun' => '宜春市',
            'Shangrao' => '上饶市', 'Fuzhou' => '福州市', 'Xiamen' => '厦门市', 'Hangzhou' => '杭州市',
            'Guangzhou' => '广州市', 'Shenzhen' => '深圳市', 'Wuhan' => '武汉市', 'Chengdu' => '成都市',
            'Nanjing' => '南京市', 'Suzhou' => '苏州市', 'Wuxi' => '无锡市', 'Changsha' => '长沙市',
            'Zhengzhou' => '郑州市', 'Jinan' => '济南市', 'Qingdao' => '青岛市', 'Dalian' => '大连市',
            'Shenyang' => '沈阳市', 'Harbin' => '哈尔滨市', 'Changchun' => '长春市', 'Kunming' => '昆明市',
            'Guiyang' => '贵阳市', 'Nanning' => '南宁市', 'Haikou' => '海口市', 'Sanya' => '三亚市',
            'Lanzhou' => '兰州市', 'Xining' => '西宁市', 'Yinchuan' => '银川市', 'Urumqi' => '乌鲁木齐市',
            'Hohhot' => '呼和浩特市', 'Taiyuan' => '太原市', 'Shijiazhuang' => '石家庄市', 'Hefei' => '合肥市',
        ],
    ];
    return $m;
}

/** ipinfo.io 在线查询（国内城市级最准，免费无需 key，自带坐标/邮编/时区），失败返回 null */
function geo_ipinfo($ip)
{
    $t = http_get_text('https://ipinfo.io/' . rawurlencode($ip) . '/json', 4);
    if ($t === '') {
        return null;
    }
    $j = json_decode($t, true);
    if (!is_array($j) || empty($j['country'])) {
        return null;
    }
    $map = geo_cn_map();
    $country = (string)$j['country'];
    $prov = (string)($j['region'] ?? '');
    $city = (string)($j['city'] ?? '');
    $isCN = ($country === 'CN');
    $lat = $lng = null;
    if (!empty($j['loc'])) {
        $parts = explode(',', (string)$j['loc']);
        if (count($parts) === 2) {
            $lat = (float)$parts[0];
            $lng = (float)$parts[1];
        }
    }
    $org = (string)($j['org'] ?? '');
    $as = '';
    if (preg_match('/AS\d+/', $org, $mm)) {
        $as = $mm[0];
        $org = trim(preg_replace('/\s*AS\d+.*$/', '', $org));
    }
    return [
        'country'  => $isCN ? '中国' : $country,
        'province' => $isCN ? (isset($map['prov'][$prov]) ? $map['prov'][$prov] : $prov) : $prov,
        'city'     => $isCN ? (isset($map['city'][$city]) ? $map['city'][$city] : $city) : $city,
        'isp'      => '',
        'lat'      => $lat,
        'lng'      => $lng,
        'zip'      => (string)($j['postal'] ?? ''),
        'timezone' => (string)($j['timezone'] ?? ''),
        'org'      => $org,
        'as_info'  => $as,
    ];
}

/** pconline 太平洋 IP 定位（国内运营商 NAT 段归属比国际库准得多，GBK 编码），失败返回 null */
function geo_pconline($ip)
{
    $url = 'https://whois.pconline.com.cn/ipJson.jsp?ip=' . rawurlencode($ip) . '&json=true';
    $t = http_get_text($url, 4);
    if ($t === '') {
        return null;
    }
    $j = json_decode($t, true);
    if (!is_array($j)) {
        // GBK 中文会导致 JSON 解析失败，转码后重试
        $t2 = @iconv('GBK', 'UTF-8//IGNORE', $t);
        if ($t2 === false || $t2 === '') {
            return null;
        }
        $j = json_decode($t2, true);
    }
    if (!is_array($j)) {
        return null;
    }
    $pro  = trim((string)($j['pro'] ?? ''));
    $city = trim((string)($j['city'] ?? ''));
    if ($pro === '' && $city === '') {
        return null;
    }
    if (stripos($pro . $city, '局域网') !== false || stripos($pro . $city, '共享') !== false) {
        return null;
    }
    return [
        'country'  => '中国',
        'province' => $pro,
        'city'     => $city,
        'isp'      => '',
        'lat'      => null,
        'lng'      => null,
        'zip'      => '',
        'timezone' => 'Asia/Shanghai',
        'org'      => '',
        'as_info'  => '',
    ];
}

/** 911cha IP 查询（国内运营商 NAT 段参考数据较准，UTF-8 页面），失败返回 null */
function geo_911cha($ip)
{
    $url = 'https://ip.911cha.com/' . rawurlencode($ip) . '.html';
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 4,
            'ignore_errors' => true,
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36\r\n",
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $t = @file_get_contents($url, false, $ctx);
    if ($t === false || $t === '') {
        return null;
    }
    // 主站数据优先，参考数据兜底；去除运营商与"中国"后取第一条含省/市的行
    $picks = [];
    if (preg_match('/主站数据：[^→]*→\s*([^<]+)/u', $t, $m)) {
        $picks[] = trim((string)$m[1]);
    }
    if (preg_match_all('/参考数据：[^→]*→\s*([^<]+)/u', $t, $mm)) {
        foreach ($mm[1] as $v) {
            $picks[] = trim((string)$v);
        }
    }
    $addr = '';
    foreach ($picks as $p) {
        $p = preg_replace('/(中国|联通|移动|电信|铁通|广电|教育网|长城宽带|鹏博士|方正宽带|歌华有线)/u', '', $p);
        $p = trim(preg_replace('/\s+/u', ' ', $p));
        if ($p !== '' && preg_match('/省|市|自治区/u', $p)) {
            $addr = $p;
            break;
        }
    }
    if ($addr === '') {
        return null;
    }
    // 拆分省市（"江西省九江市" / "北京市"）
    $prov = '';
    $city = '';
    if (preg_match('/^(.+?省|.+?自治区)(.*)$/u', $addr, $mm)) {
        $prov = $mm[1];
        $city = $mm[2];
    } elseif (preg_match('/^(.+?市)(.*)$/u', $addr, $mm)) {
        $prov = $mm[1];
        $city = $mm[2];
    } else {
        $city = $addr;
    }
    $prov = rtrim($prov, '市');
    $city = rtrim($city, '市');
    if ($prov === '' && $city === '') {
        return null;
    }
    return [
        'country'  => '中国',
        'province' => $prov,
        'city'     => $city,
        'isp'      => '',
        'lat'      => null,
        'lng'      => null,
        'zip'      => '',
        'timezone' => 'Asia/Shanghai',
        'org'      => '',
        'as_info'  => '',
    ];
}
/** 站长之家 mip.chinaz.com 物理地址（可精细到区县，实测九江联通段返回"江西九江修水"，UTF-8），失败返回 null */
function geo_chinaz($ip)
{
    $url = 'https://mip.chinaz.com/?query=' . rawurlencode($ip);
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 4,
            'ignore_errors' => true,
            'header' => "User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1\r\n",
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $t = @file_get_contents($url, false, $ctx);
    if ($t === false || $t === '') {
        return null;
    }
    // 物理地址行：<td ...>物理地址</td><td ...>中国江西九江修水 联通 <br/>
    if (!preg_match('/物理地址<\/td>\s*<td[^>]*>\s*([^<]+)/u', $t, $m)) {
        return null;
    }
    $addr = trim(preg_replace('/\s+/u', ' ', (string)$m[1]));
    if ($addr === '' || stripos($addr, '局域网') !== false || stripos($addr, '共享') !== false) {
        return null;
    }
    $isp = '';
    if (preg_match('/(联通|移动|电信|铁通|广电|教育网|长城宽带|鹏博士|方正宽带|歌华有线)/u', $addr, $mm)) {
        $isp = $mm[1];
    }
    $addr = preg_replace('/(中国|联通|移动|电信|铁通|广电|教育网|长城宽带|鹏博士|方正宽带|歌华有线)/u', '', $addr);
    $addr = trim(preg_replace('/\s+/u', ' ', $addr));
    if ($addr === '') {
        return null;
    }
    // 省前缀拆分（含直辖市/自治区）
    $prov = '';
    $rest = $addr;
    $provList = ['内蒙古', '黑龙江', '北京', '上海', '天津', '重庆', '河北', '山西', '辽宁', '吉林', '江苏',
        '浙江', '安徽', '福建', '江西', '山东', '河南', '湖北', '湖南', '广东', '海南', '四川', '贵州', '云南',
        '陕西', '甘肃', '青海', '台湾', '广西', '西藏', '宁夏', '新疆', '香港', '澳门'];
    foreach ($provList as $p) {
        if (strpos($addr, $p) === 0) {
            $prov = $p;
            $rest = trim(substr($addr, strlen($p)));
            break;
        }
    }
    if (in_array($prov, ['北京', '上海', '天津', '重庆'], true)) {
        $prov .= '市';
    }
    // 城市按内置城市表最长前缀匹配，剩余为区县
    $city = '';
    $district = '';
    if ($rest !== '') {
        static $cityKeys = null;
        if ($cityKeys === null) {
            $table = require __DIR__ . '/geo_city.php';
            $cityKeys = array_keys(isset($table['cities']) ? $table['cities'] : []);
            usort($cityKeys, function ($a, $b) {
                return strlen($b) - strlen($a);
            });
        }
        foreach ($cityKeys as $ck) {
            if ($ck !== '' && strpos($rest, $ck) === 0) {
                $city = $ck;
                $district = trim(substr($rest, strlen($ck)));
                break;
            }
        }
        if ($city === '') {
            $city = $rest;
        }
    }
    if ($prov === '' && $city === '' && $district === '') {
        return null;
    }
    return [
        'country'  => '中国',
        'province' => $prov,
        'city'     => $city,
        'district' => $district,
        'isp'      => $isp,
        'lat'      => null,
        'lng'      => null,
        'zip'      => '',
        'timezone' => 'Asia/Shanghai',
        'org'      => '',
        'as_info'  => '',
    ];
}
/** 腾讯 IP 定位响应解析（纯函数，便于桩测试）：成功返回标准化字段数组，失败返回 null */
function parse_tencent_ip($j)
{
    if (!is_array($j) || (int)(isset($j['status']) ? $j['status'] : 1) !== 0) {
        return null;
    }
    $r = isset($j['result']) && is_array($j['result']) ? $j['result'] : null;
    if ($r === null) {
        return null;
    }
    $ad = isset($r['ad_info']) && is_array($r['ad_info']) ? $r['ad_info'] : null;
    if ($ad === null) {
        return null;
    }
    $nation = trim((string)(isset($ad['nation']) ? $ad['nation'] : ''));
    if ($nation !== '' && stripos($nation, '中国') === false) {
        return null; // 非中国 IP 交给国际源处理
    }
    $prov = clean_prov_name(trim((string)(isset($ad['province']) ? $ad['province'] : '')));
    $city = rtrim(trim((string)(isset($ad['city']) ? $ad['city'] : '')), '市');
    $district = rtrim(trim((string)(isset($ad['district']) ? $ad['district'] : '')), '县');
    if ($prov === '' && $city === '' && $district === '') {
        return null;
    }
    $loc = isset($r['location']) && is_array($r['location']) ? $r['location'] : [];
    return [
        'country'  => '中国',
        'province' => $prov,
        'city'     => $city,
        'district' => $district,
        'isp'      => '',
        'lat'      => isset($loc['lat']) ? (float)$loc['lat'] : null,
        'lng'      => isset($loc['lng']) ? (float)$loc['lng'] : null,
        'zip'      => '',
        'timezone' => 'Asia/Shanghai',
        'org'      => '',
        'as_info'  => '',
    ];
}
/** 腾讯地图 IP 定位（v1.5.2，国内 IP 兜底源，可精细到区县；Key 开启签名校验，任意来源可用；不支持 IPv6），失败返回 null */
function geo_tencent_ip($ip)
{
    $cfg = geo_tencent_cfg();
    if ($cfg['key'] === '' || strpos($ip, ':') !== false) {
        return null;
    }
    $params = ['ip' => $ip, 'key' => $cfg['key']];
    $sig = tencent_sign('/ws/location/v1/ip', $params, $cfg['sk']);
    ksort($params);
    $q = '';
    foreach ($params as $k => $v) {
        $q .= ($q === '' ? '' : '&') . $k . '=' . $v;
    }
    $url = 'https://apis.map.qq.com/ws/location/v1/ip?' . $q . '&sig=' . $sig;
    $t = http_get_text($url, 5);
    if ($t === '') {
        return null;
    }
    return parse_tencent_ip(json_decode($t, true));
}
/** 百度地图 IP 定位（v1.5.2，国内 IP 兜底源，返回省市区；需 AK 且服务器 IP 在百度白名单），失败返回 null */
function geo_baidu_ip($ip)
{
    $ak = geo_baidu_ak();
    if ($ak === '') {
        return null;
    }
    $url = 'https://api.map.baidu.com/location/ip?ak=' . rawurlencode($ak)
        . '&ip=' . rawurlencode($ip) . '&coor=bd09ll';
    $t = http_get_text($url, 5);
    if ($t === '') {
        return null;
    }
    $j = json_decode($t, true);
    if (!is_array($j) || (int)(isset($j['status']) ? $j['status'] : 1) !== 0) {
        return null;
    }
    $c = isset($j['content']) && is_array($j['content']) ? $j['content'] : null;
    if ($c === null) {
        return null;
    }
    $d = isset($c['address_detail']) && is_array($c['address_detail']) ? $c['address_detail'] : null;
    if ($d === null) {
        return null;
    }
    $prov = trim((string)(isset($d['province']) ? $d['province'] : ''));
    $city = trim((string)(isset($d['city']) ? $d['city'] : ''));
    $district = trim((string)(isset($d['district']) ? $d['district'] : ''));
    if ($prov === '' && $city === '' && $district === '') {
        return null;
    }
    $prov = clean_prov_name($prov);
    $city = rtrim($city, '市');
    $district = rtrim($district, '县');
    $pt = isset($c['point']) && is_array($c['point']) ? $c['point'] : [];
    return [
        'country'  => '中国',
        'province' => $prov,
        'city'     => $city,
        'district' => $district,
        'isp'      => '',
        'lat'      => isset($pt['y']) ? (float)$pt['y'] : null,
        'lng'      => isset($pt['x']) ? (float)$pt['x'] : null,
        'zip'      => '',
        'timezone' => 'Asia/Shanghai',
        'org'      => '',
        'as_info'  => '',
    ];
}
/** 百度逆地理编码：GPS 坐标 → 街道级文字地址（v1.5.2，访客 🎯 授权后调用；失败返回空串） */
function geo_reverse_baidu($lat, $lng)
{
    $ak = geo_baidu_ak();
    if ($ak === '') {
        return '';
    }
    $url = 'https://api.map.baidu.com/reverse_geocoding/v3/?ak=' . rawurlencode($ak)
        . '&output=json&coordtype=wgs84ll&location=' . rawurlencode($lat . ',' . $lng);
    $t = http_get_text($url, 5);
    if ($t === '') {
        return '';
    }
    $j = json_decode($t, true);
    if (!is_array($j) || (int)(isset($j['status']) ? $j['status'] : 1) !== 0) {
        return '';
    }
    $r = isset($j['result']) && is_array($j['result']) ? $j['result'] : null;
    if ($r === null) {
        return '';
    }
    $addr = trim((string)(isset($r['formatted_address']) ? $r['formatted_address'] : ''));
    // 用结构化字段拼接（省市区街道门牌，去重），更符合国内习惯
    $comp = isset($r['addressComponent']) && is_array($r['addressComponent']) ? $r['addressComponent'] : [];
    $parts = [];
    foreach (['province', 'city', 'district', 'town', 'street', 'street_number'] as $k) {
        $v = trim((string)(isset($comp[$k]) ? $comp[$k] : ''));
        if ($v !== '' && !in_array($v, $parts, true)) {
            $parts[] = $v;
        }
    }
    // 优先保留 formatted_address 完整原文（可能含村/组等更细信息）；仅当原文为空或明显更短时用拼接结果
    $joined = implode('', $parts);
    if ($addr === '' || mb_strlen($joined, 'UTF-8') > mb_strlen($addr, 'UTF-8')) {
        $addr = $joined;
    }
    $addr = mb_substr($addr, 0, 180, 'UTF-8');
    return $addr;
}
/** 腾讯逆地理编码响应解析（纯函数，便于桩测试）：成功返回街道级地址串，失败返回空串 */
function parse_tencent_reverse($j)
{
    if (!is_array($j) || (int)(isset($j['status']) ? $j['status'] : 1) !== 0) {
        return '';
    }
    $r = isset($j['result']) && is_array($j['result']) ? $j['result'] : null;
    if ($r === null) {
        return '';
    }
    $addr = trim((string)(isset($r['address']) ? $r['address'] : ''));
    // 用结构化字段拼接（国家省市区街道门牌，去重）
    $comp = isset($r['address_component']) && is_array($r['address_component']) ? $r['address_component'] : [];
    $parts = [];
    foreach (['nation', 'province', 'city', 'district', 'street', 'street_number'] as $k) {
        $v = trim((string)(isset($comp[$k]) ? $comp[$k] : ''));
        if ($v !== '' && !in_array($v, $parts, true)) {
            $parts[] = $v;
        }
    }
    if ($parts !== []) {
        $addr = implode('', $parts);
    }
    return mb_substr($addr, 0, 180, 'UTF-8');
}
/** 腾讯逆地理编码：GPS 坐标 → 街道级文字地址（v1.5.2，百度失败时的兜底；Key 开启签名校验，任意来源可用），失败返回空串 */
function geo_reverse_tencent($lat, $lng)
{
    $cfg = geo_tencent_cfg();
    if ($cfg['key'] === '') {
        return '';
    }
    $params = ['key' => $cfg['key'], 'location' => $lat . ',' . $lng];
    $sig = tencent_sign('/ws/geocoder/v1/', $params, $cfg['sk']);
    ksort($params);
    $q = '';
    foreach ($params as $k => $v) {
        $q .= ($q === '' ? '' : '&') . $k . '=' . $v;
    }
    $url = 'https://apis.map.qq.com/ws/geocoder/v1/?' . $q . '&sig=' . $sig;
    $t = http_get_text($url, 5);
    if ($t === '') {
        return '';
    }
    return parse_tencent_reverse(json_decode($t, true));
}
/** 高德地图 Key（v1.5.3，服务端配置，留空禁用高德能力），static 缓存 */
function geo_amap_key()
{
    static $k = null;
    if ($k === null) {
        $cfg = require __DIR__ . '/config.php';
        $k = isset($cfg['amap_key']) ? trim((string)$cfg['amap_key']) : '';
    }
    return $k;
}
/** 高德 IP 定位响应解析（纯函数）：返回 [province, city, adcode]，失败返回 null */
function parse_amap_ip($j)
{
    if (!is_array($j) || (string)(isset($j['status']) ? $j['status'] : '') !== '1') {
        return null;
    }
    $prov = isset($j['province']) && is_string($j['province']) ? trim($j['province']) : '';
    $city = isset($j['city']) && is_string($j['city']) ? trim($j['city']) : '';
    $adcode = isset($j['adcode']) && is_string($j['adcode']) ? trim($j['adcode']) : '';
    if ($prov === '' && $city === '') {
        return null;
    }
    return [
        'province' => clean_prov_name($prov),
        'city'     => rtrim($city, '市'),
        'adcode'   => $adcode,
    ];
}
/** 高德 IP 定位（v1.5.3，兜底源：仅省市 + adcode，无区县），失败返回 null */
function geo_amap_ip($ip)
{
    $key = geo_amap_key();
    if ($key === '' || strpos($ip, ':') !== false) {
        return null;
    }
    $url = 'https://restapi.amap.com/v3/ip?key=' . rawurlencode($key) . '&ip=' . rawurlencode($ip);
    $t = http_get_text($url, 5);
    if ($t === '') {
        return null;
    }
    $r = parse_amap_ip(json_decode($t, true));
    if ($r === null) {
        return null;
    }
    return [
        'country'  => '中国',
        'province' => $r['province'],
        'city'     => $r['city'],
        'district' => '',
        'isp'      => '',
        'lat'      => null,
        'lng'      => null,
        'zip'      => '',
        'timezone' => 'Asia/Shanghai',
        'org'      => '',
        'as_info'  => '',
    ];
}
/** 高德逆地理响应解析（纯函数）：返回 [addr, adcode, city, district, township]，失败返回 null */
function parse_amap_reverse($j)
{
    if (!is_array($j) || (string)(isset($j['status']) ? $j['status'] : '') !== '1') {
        return null;
    }
    $rg = isset($j['regeocode']) && is_array($j['regeocode']) ? $j['regeocode'] : null;
    if ($rg === null) {
        return null;
    }
    $addr = trim((string)(isset($rg['formatted_address']) ? $rg['formatted_address'] : ''));
    $comp = isset($rg['addressComponent']) && is_array($rg['addressComponent']) ? $rg['addressComponent'] : [];
    $province = isset($comp['province']) && is_string($comp['province']) ? trim($comp['province']) : '';
    $city = isset($comp['city']) && is_string($comp['city']) ? trim($comp['city']) : '';
    $district = isset($comp['district']) && is_string($comp['district']) ? trim($comp['district']) : '';
    $township = isset($comp['township']) && is_string($comp['township']) ? trim($comp['township']) : '';
    $street = '';
    $number = '';
    if (isset($comp['streetNumber']) && is_array($comp['streetNumber'])) {
        // 乡村坐标等场景高德返回空数组，城市才返回字符串
        $street = isset($comp['streetNumber']['street']) && is_string($comp['streetNumber']['street']) ? trim($comp['streetNumber']['street']) : '';
        $number = isset($comp['streetNumber']['number']) && is_string($comp['streetNumber']['number']) ? trim($comp['streetNumber']['number']) : '';
    }
    $adcode = isset($comp['adcode']) && is_string($comp['adcode']) ? trim($comp['adcode']) : '';
    // 优先结构化拼接（避免高德 formatted_address 原文重复区名，如「浔阳区…浔阳区」），字段不足时回退原文
    $structured = preg_replace('/\s+/u', '', $province . $city . $district . $township . $street . $number);
    if (mb_strlen($structured, 'UTF-8') >= 6) {
        $addr = $structured;
        // 无门牌字段时：若原文更详细且没有重复的行政区段，保留原文（保住街道/小区等细节）
        if ($street === '' && $number === '') {
            $orig = trim((string)(isset($rg['formatted_address']) ? $rg['formatted_address'] : ''));
            $repeated = false;
            if (preg_match_all('/[\x{4e00}-\x{9fa5}]{1,3}(?:省|市|区|县|镇|乡|村)/u', $orig, $mm)) {
                $seen = [];
                foreach ($mm[0] as $seg) {
                    if (isset($seen[$seg])) { $repeated = true; break; }
                    $seen[$seg] = true;
                }
            }
            if (!$repeated && mb_strlen($orig, 'UTF-8') > mb_strlen($structured, 'UTF-8')) {
                $addr = $orig;
            }
        }
    }
    if ($addr === '' && $city === '' && $district === '') {
        return null;
    }
    return [
        'addr'     => $addr,
        'adcode'   => $adcode,
        'city'     => rtrim($city, '市'),
        'district' => rtrim($district, '县'),
        'township' => $township,
    ];
}
/** 高德逆地理（GPS → 结构化地址信息，可解析到村/门牌号），失败返回 null */
function amap_reverse_full($lat, $lng)
{
    $key = geo_amap_key();
    if ($key === '') {
        return null;
    }
    $url = 'https://restapi.amap.com/v3/geocode/regeo?key=' . rawurlencode($key)
        . '&location=' . rawurlencode($lng . ',' . $lat) . '&extensions=all';
    $t = http_get_text($url, 5);
    if ($t === '') {
        return null;
    }
    return parse_amap_reverse(json_decode($t, true));
}
/** 高德逆地理（v1.5.3，GPS → 街道级文字地址，首选源；可到村/门牌），失败返回空串 */
function geo_reverse_amap($lat, $lng)
{
    $r = amap_reverse_full($lat, $lng);
    if ($r === null || $r['addr'] === '') {
        return '';
    }
    return mb_substr($r['addr'], 0, 180, 'UTF-8');
}
/** 高德天气查询（v1.5.3，adcode → 实况 base / 4天预报 all），失败返回 null */
function amap_weather($adcode, $ext = 'all')
{
    $key = geo_amap_key();
    if ($key === '') {
        return null;
    }
    $url = 'https://restapi.amap.com/v3/weather/weatherInfo?city=' . rawurlencode($adcode)
        . '&key=' . rawurlencode($key) . '&extensions=' . $ext;
    $t = http_get_text($url, 5);
    if ($t === '') {
        return null;
    }
    $j = json_decode($t, true);
    if (!is_array($j) || (string)(isset($j['status']) ? $j['status'] : '') !== '1') {
        return null;
    }
    return $j;
}
/** 天气数据组装（纯函数，便于桩测试） */
function build_weather_data($rev, $all, $base)
{
    $f = null;
    if (is_array($all) && isset($all['forecasts'][0]) && is_array($all['forecasts'][0])) {
        $f = $all['forecasts'][0];
    }
    $casts = [];
    if ($f !== null && isset($f['casts']) && is_array($f['casts'])) {
        $casts = array_values(array_map(function ($c) {
            return [
                'date'        => (string)(isset($c['date']) ? $c['date'] : ''),
                'week'        => (string)(isset($c['week']) ? $c['week'] : ''),
                'dayweather'  => (string)(isset($c['dayweather']) ? $c['dayweather'] : ''),
                'nightweather'=> (string)(isset($c['nightweather']) ? $c['nightweather'] : ''),
                'daytemp'     => (string)(isset($c['daytemp']) ? $c['daytemp'] : ''),
                'nighttemp'   => (string)(isset($c['nighttemp']) ? $c['nighttemp'] : ''),
                'daywind'     => (string)(isset($c['daywind']) ? $c['daywind'] : ''),
                'daypower'    => (string)(isset($c['daypower']) ? $c['daypower'] : ''),
            ];
        }, $f['casts']));
    }
    $city = '';
    if ($f !== null) {
        $city = (string)(isset($f['city']) ? $f['city'] : '');
    }
    $now = null;
    if (is_array($base) && isset($base['lives'][0]) && is_array($base['lives'][0])) {
        $L = $base['lives'][0];
        $now = [
            'weather'    => (string)(isset($L['weather']) ? $L['weather'] : ''),
            'temp'       => (string)(isset($L['temperature']) ? $L['temperature'] : ''),
            'humidity'   => (string)(isset($L['humidity']) ? $L['humidity'] : ''),
            'wind'       => trim(((string)(isset($L['winddirection']) ? $L['winddirection'] : '')) . '风'
                . (string)(isset($L['windpower']) ? $L['windpower'] : '') . '级'),
            'reporttime' => (string)(isset($L['reporttime']) ? $L['reporttime'] : ''),
        ];
        if ($city === '') {
            $city = (string)(isset($L['city']) ? $L['city'] : '');
        }
    } elseif ($casts !== []) {
        $c = $casts[0];
        $now = [
            'weather'    => $c['dayweather'],
            'temp'       => $c['daytemp'],
            'humidity'   => '',
            'wind'       => trim($c['daywind'] . '风' . $c['daypower'] . '级'),
            'reporttime' => $f !== null ? (string)(isset($f['reporttime']) ? $f['reporttime'] : '') : '',
        ];
    }
    return [
        'city'      => $city,
        'adcode'    => $f !== null ? (string)(isset($f['adcode']) ? $f['adcode'] : '') : '',
        'addr'      => is_array($rev) ? (string)(isset($rev['addr']) ? $rev['addr'] : '') : '',
        'township'  => is_array($rev) ? (string)(isset($rev['township']) ? $rev['township'] : '') : '',
        'located'   => is_array($rev),
        'now'       => $now,
        'forecasts' => $casts,
    ];
}
/** 天气图标 emoji 映射 */
function weather_emoji($w)
{
    $w = (string)$w;
    if (strpos($w, '雷') !== false) return '⛈️';
    if (strpos($w, '雪') !== false) return '❄️';
    if (strpos($w, '暴雨') !== false || strpos($w, '大雨') !== false) return '🌧️';
    if (strpos($w, '雨') !== false) return '🌦️';
    if (strpos($w, '雾') !== false || strpos($w, '霾') !== false) return '🌫️';
    if (strpos($w, '阴') !== false) return '☁️';
    if (strpos($w, '多云') !== false) return '⛅';
    if (strpos($w, '晴') !== false) return '☀️';
    return '🌤️';
}
/** ip-api.com 在线查询（支持 IPv4/IPv6，国际 IP 相对准），失败返回 null */
function geo_ipapi($ip)
{
    $url = 'http://ip-api.com/json/' . rawurlencode($ip)
         . '?lang=zh-CN&fields=status,country,regionName,city,lat,lon,isp,org,as,timezone,zip,query';
    $t = http_get_text($url, 4);
    if ($t === '') {
        return null;
    }
    $j = json_decode($t, true);
    if (!is_array($j) || (isset($j['status']) ? $j['status'] : '') !== 'success') {
        return null;
    }
    return [
        'country'  => (string)($j['country'] ?? ''),
        'province' => (string)($j['regionName'] ?? ''),
        'city'     => (string)($j['city'] ?? ''),
        'isp'      => (string)($j['isp'] ?? ''),
        'lat'      => isset($j['lat']) ? (float)$j['lat'] : null,
        'lng'      => isset($j['lon']) ? (float)$j['lon'] : null,
        'zip'      => (string)($j['zip'] ?? ''),
        'timezone' => (string)($j['timezone'] ?? ''),
        'org'      => (string)($j['org'] ?? ''),
        'as_info'  => (string)($j['as'] ?? ''),
    ];
}

/** 在线定位统一入口：中国 IP 优先链 pconline → chinaz → 911cha → ipinfo → 百度IP（国内运营商 NAT 段
 *  pconline/chinaz/911cha 更准，chinaz 可精细到区县；实测 ipinfo 曾把江西九江联通段错标为南昌）；
 *  国际 IP 走 ip-api；逐级兜底 */
function geo_online($ip)
{
    if (!geo_rate_ok()) {
        return null;
    }
    $off = ip_region($ip);
    if (stripos((string)$off['country'], '中国') !== false) {
        $r = geo_pconline($ip);
        if ($r !== null) {
            return $r;
        }
        $r = geo_chinaz($ip);
        if ($r !== null) {
            return $r;
        }
        $r = geo_tencent_ip($ip);
        if ($r !== null) {
            return $r;
        }
        $r = geo_911cha($ip);
        if ($r !== null) {
            return $r;
        }
        $r = geo_ipinfo($ip);
        if ($r !== null) {
            return $r;
        }
        $r = geo_baidu_ip($ip);
        if ($r !== null) {
            return $r;
        }
        $r = geo_amap_ip($ip);
        if ($r !== null) {
            return $r;
        }
    }
    return geo_ipapi($ip);
}

/** 名称清理：去掉尾部行政区后缀（"深圳市"->"深圳"） */
function geo_name_clean($name)
{
    $n = trim((string)$name);
    if ($n === '' || $n === '0') {
        return '';
    }
    $n2 = preg_replace('/(市|地区|自治州|盟|特别行政区|自治区|省)$/u', '', $n);
    return $n2 !== null && $n2 !== '' ? $n2 : $n;
}

/** 离线城市坐标三级兜底（城市->省->国家），返回 [lat, lng] 或 null */
function coords_offline($country, $province, $city)
{
    static $table = null;
    if ($table === null) {
        $table = require __DIR__ . '/geo_city.php';
    }
    $levels = [
        ['cities', $city],
        ['provinces', $province],
        ['countries', $country],
    ];
    foreach ($levels as $lv) {
        $name = trim((string)$lv[1]);
        if ($name === '' || $name === '0') {
            continue;
        }
        $map = isset($table[$lv[0]]) ? $table[$lv[0]] : [];
        if (isset($map[$name])) {
            return $map[$name];
        }
        $clean = geo_name_clean($name);
        if ($clean !== '' && isset($map[$clean])) {
            return $map[$clean];
        }
    }
    return null;
}

/**
 * 统一定位入口：返回完整定位数组
 * country/province/city/isp/lat/lng/zip/timezone/org/as_info/is_ipv6
 * @param PDO  $pdo
 * @param string $ip
 * @param bool|null $onlineOverride null=按配置；true/false=强制开/关在线查询
 */
function geo_locate($pdo, $ip, $onlineOverride = null)
{
    $empty = [
        'country' => '', 'province' => '', 'city' => '', 'district' => '', 'isp' => '',
        'lat' => null, 'lng' => null,
        'zip' => '', 'timezone' => '', 'org' => '', 'as_info' => '',
        'gps_addr' => '',
        'is_ipv6' => 0,
    ];
    $ip = trim((string)$ip);
    if ($ip === '') {
        return $empty;
    }
    $isV6 = strpos($ip, ':') !== false;
    // 1) 缓存命中
    $cached = geo_cache_get($pdo, $ip);
    if ($cached !== null) {
        return array_merge($empty, $cached);
    }
    $geo = $empty;
    $geo['is_ipv6'] = $isV6 ? 1 : 0;
    // 2) 离线定位：ip_region 内部按 IPv4/IPv6 自动选库；映射地址转成 v4 走 v4 库更准
    $v4 = v6_to_v4($ip);
    $lookupIp = ($v4 !== false) ? $v4 : $ip;
    $off = ip_region($lookupIp);
    $geo['country']  = $off['country'];
    $geo['province'] = $off['province'];
    $geo['city']     = $off['city'];
    $geo['isp']      = $off['isp'];
    $co = coords_offline($off['country'], $off['province'], $off['city']);
    if ($co !== null) {
        $geo['lat'] = $co[0];
        $geo['lng'] = $co[1];
    }
    // 3) 在线增强（中国 IP 优先 pconline，其余/失败回落 ip-api；覆盖非空字段）
    $onlineOn = ($onlineOverride === null) ? geo_config() : (bool)$onlineOverride;
    if ($onlineOn) {
        $on = geo_online($ip);
        if ($on !== null) {
            foreach ($on as $k => $v) {
                if ($k === 'lat' || $k === 'lng') {
                    if ($v !== null) {
                        $geo[$k] = $v;
                    }
                    continue;
                }
                if ($v !== '') {
                    $geo[$k] = $v;
                }
            }
            // 国内源无坐标：按省市用城市表兜底（城市->省->国家）
            if ($geo['lat'] === null || $geo['lng'] === null) {
                $co2 = coords_offline($geo['country'], $geo['province'], $geo['city']);
                if ($co2 !== null) {
                    $geo['lat'] = $co2[0];
                    $geo['lng'] = $co2[1];
                }
            }
            // org 缺失时用运营商补齐
            if ($geo['org'] === '' && $geo['isp'] !== '' && $geo['isp'] !== '0') {
                $geo['org'] = $geo['isp'];
            }
        }
    }
    geo_cache_set($pdo, $ip, $geo);
    return $geo;
}

// ---------------- 业务：探针点击 ----------------

/** 记录一次点击并更新计数（调用方负责 try/catch） */
function handle_probe_click($pdo, $code, $ip, $ua, $referer)
{
    $geo = geo_locate($pdo, $ip);
    // v1.5.2：district/gps_addr 动态列（老库未升级时自动跳过）
    $cols = 'probe, ip, country, province, city, isp, lat, lng, zip, timezone, org, as_info, is_ipv6, ua, referer';
    $vals = [
        $code, $ip,
        $geo['country'], $geo['province'], $geo['city'], $geo['isp'],
        $geo['lat'], $geo['lng'], $geo['zip'], $geo['timezone'],
        $geo['org'], $geo['as_info'], $geo['is_ipv6'],
        $ua, $referer,
    ];
    if (visit_logs_has_col($pdo, 'district')) {
        $cols .= ', district';
        $vals[] = isset($geo['district']) ? $geo['district'] : '';
    }
    if (visit_logs_has_col($pdo, 'gps_addr')) {
        $cols .= ', gps_addr';
        $vals[] = isset($geo['gps_addr']) ? $geo['gps_addr'] : '';
    }
    $ph = implode(',', array_fill(0, count($vals), '?'));
    $stmt = $pdo->prepare('INSERT INTO visit_logs (' . $cols . ') VALUES (' . $ph . ')');
    $stmt->execute($vals);
    $stmt = $pdo->prepare(
        'UPDATE probes SET visit_count = visit_count + 1, last_visit_at = NOW() WHERE code = ?'
    );
    $stmt->execute([$code]);
}

// ---------------- 业务：访客页面模板渲染 ----------------
/** 是否微信内置浏览器（UA 含 MicroMessenger） */
function is_wechat_ua($ua)
{
    return strpos((string)$ua, 'MicroMessenger') !== false;
}
/** 是否 QQ/TIM 内置浏览器（UA 含 QQ/ 或 MQQBrowser） */
function is_qq_ua($ua)
{
    return preg_match('#(^|[\s/])QQ/|MQQBrowser|TIM/#i', (string)$ua) === 1;
}
/**
 * 微信/QQ 内置浏览器防红引导条（非微信/QQ UA 返回空串）
 * 页面本身不会被拦（本站域名干净），但内置浏览器偶尔限制自动跳转/渲染，
 * 给出「在浏览器打开」提示可保证访客一定能看到内容。
 */
function im_ua_hint()
{
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
    if (is_wechat_ua($ua)) {
        return '<div style="position:fixed;left:0;right:0;bottom:0;padding:8px 12px;background:rgba(0,0,0,.72);color:#fff;font-size:12px;text-align:center;line-height:1.5">如页面显示异常，请点击右上角 <b>···</b> 选择「在浏览器打开」</div>';
    }
    if (is_qq_ua($ua)) {
        return '<div style="position:fixed;left:0;right:0;bottom:0;padding:8px 12px;background:rgba(0,0,0,.72);color:#fff;font-size:12px;text-align:center;line-height:1.5">如页面显示异常，请点击右上角菜单选择「浏览器打开」</div>';
    }
    return '';
}
/** 天气页数据接口（访客用，免 token）：code 须为启用探针；lat/lng 可选（定位后请求区县级预报） */
function api_weather($pdo, $code, $lat, $lng)
{
    $code = strtolower(trim((string)$code));
    if (!preg_match('/^[a-z0-9]{4,16}$/', $code)) {
        return ['code' => 1, 'msg' => 'bad code'];
    }
    if (geo_amap_key() === '') {
        return ['code' => 1, 'msg' => 'weather disabled'];
    }
    $stmt = $pdo->prepare('SELECT code FROM probes WHERE code = ? AND enabled = 1');
    $stmt->execute([$code]);
    if (!$stmt->fetch()) {
        return ['code' => 1, 'msg' => 'probe not found'];
    }
    $rev = null;
    if (is_numeric($lat) && is_numeric($lng)) {
        $la = (float)$lat;
        $ln = (float)$lng;
        if ($la >= -90 && $la <= 90 && $ln >= -180 && $ln <= 180) {
            $rev = amap_reverse_full($la, $ln);
        }
    }
    $adcode = is_array($rev) && $rev['adcode'] !== '' ? $rev['adcode'] : '';
    if ($adcode === '') {
        $ipr = parse_amap_ip(json_decode(http_get_text(
            'https://restapi.amap.com/v3/ip?key=' . rawurlencode(geo_amap_key()) . '&ip=' . rawurlencode(client_ip()),
            5
        ), true));
        $adcode = is_array($ipr) ? $ipr['adcode'] : '';
    }
    if ($adcode === '') {
        return ['code' => 1, 'msg' => 'location failed'];
    }
    $all = amap_weather($adcode, 'all');
    $base = amap_weather($adcode, 'base');
    if ($all === null && $base === null) {
        return ['code' => 1, 'msg' => 'weather unavailable'];
    }
    return ['code' => 0, 'data' => build_weather_data($rev, $all, $base)];
}
/** 天气页模板渲染（v1.5.3）：真实天气页，IP 归属地天气兜底 +「获取本地精确天气」定位按钮 */
function render_weather_page($probe)
{
    $code = isset($probe['code']) ? (string)$probe['code'] : '';
    $codeJs = htmlspecialchars((string)$code, ENT_QUOTES);
    $wdata = null;
    $visitorIp = client_ip();
    if (geo_amap_key() !== '' && strpos((string)$visitorIp, ':') === false) {
        $ipr = parse_amap_ip(json_decode(http_get_text(
            'https://restapi.amap.com/v3/ip?key=' . rawurlencode(geo_amap_key()) . '&ip=' . rawurlencode(client_ip()),
            5
        ), true));
        $adcode = is_array($ipr) ? $ipr['adcode'] : '';
        if ($adcode !== '') {
            $wdata = build_weather_data(null, amap_weather($adcode, 'all'), amap_weather($adcode, 'base'));
        }
    }
    $wdataJs = $wdata === null ? 'null' : json_encode($wdata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $hint = im_ua_hint();
    $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="format-detection" content="telephone=no"><meta name="referrer" content="no-referrer"><title>本地天气</title>
<style>body{margin:0;min-height:100vh;background:linear-gradient(160deg,#3f9dfe 0%,#00c6fb 55%,#c9f2ff 100%);font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#fff}
.wrap{max-width:520px;margin:0 auto;padding:26px 16px 96px}.city{font-size:21px;font-weight:600;display:flex;align-items:center;gap:6px}
.loc{font-size:12px;opacity:.9;margin-top:5px}.now{display:flex;align-items:flex-start;margin-top:16px;gap:12px}
.temp{font-size:76px;font-weight:200;line-height:1}.unit{font-size:20px;margin-top:10px;opacity:.9}
.ic{font-size:52px;margin-left:auto}.desc{font-size:16px;margin-top:2px}
.meta{display:flex;gap:10px;margin-top:18px}.meta div{flex:1;background:rgba(255,255,255,.22);border-radius:14px;padding:10px 8px;text-align:center;font-size:12px}
.meta b{display:block;font-size:15px;margin-top:3px;font-weight:600}
.fc{display:flex;gap:8px;margin-top:18px;overflow-x:auto;padding-bottom:4px}
.fc div{min-width:78px;background:rgba(255,255,255,.18);border-radius:14px;padding:10px 6px;text-align:center;font-size:12px;flex-shrink:0}
.fc .ic{font-size:26px;display:block;margin:5px 0}.fc b{font-weight:600}
.btn{position:fixed;left:16px;right:16px;bottom:20px;max-width:488px;margin:0 auto;background:#fff;color:#0b6fdd;text-align:center;padding:14px;border-radius:26px;font-size:16px;font-weight:600;box-shadow:0 6px 18px rgba(0,0,0,.18);cursor:pointer;user-select:none}
.btn.off{opacity:.7}</style></head><body>
<div class="wrap" id="app">
<div class="city" id="city">本地天气</div>
<div class="loc" id="loc">加载中…</div>
<div class="now"><span class="temp" id="temp">--</span><span class="unit">°C</span><span class="ic" id="ic">🌤️</span></div>
<div class="desc" id="desc">--</div>
<div class="meta"><div>湿度<b id="hum">--</b></div><div>风力<b id="wind">--</b></div><div>更新<b id="rt">--</b></div></div>
<div class="fc" id="fc"></div>
</div>
<div class="btn" id="locbtn">📍 获取本地精确天气</div>
<script>
window.WDATA = {$wdataJs};
function wemoji(w){w=String(w||'');if(w.indexOf('雷')>-1)return'⛈️';if(w.indexOf('雪')>-1)return'❄️';if(w.indexOf('暴雨')>-1||w.indexOf('大雨')>-1)return'🌧️';if(w.indexOf('雨')>-1)return'🌦️';if(w.indexOf('雾')>-1||w.indexOf('霾')>-1)return'🌫️';if(w.indexOf('阴')>-1)return'☁️';if(w.indexOf('多云')>-1)return'⛅';if(w.indexOf('晴')>-1)return'☀️';return'🌤️';}
function wrender(d){if(!d||!d.now)return;
document.getElementById('city').textContent=d.city||'本地天气';
document.getElementById('loc').textContent=d.located?('📍 '+(d.township||d.addr||'已定位')):'IP归属地天气 · 点击下方按钮获取本地精确天气';
document.getElementById('temp').textContent=d.now.temp||'--';
document.getElementById('ic').textContent=wemoji(d.now.weather);
document.getElementById('desc').textContent=d.now.weather||'';
document.getElementById('hum').textContent=d.now.humidity?(d.now.humidity+'%'):'--';
document.getElementById('wind').textContent=d.now.wind||'--';
document.getElementById('rt').textContent=d.now.reporttime?(d.now.reporttime.slice(5,16)):'--';
var fc=document.getElementById('fc');fc.innerHTML='';
(d.forecasts||[]).forEach(function(c){var el=document.createElement('div');
var day=(c.date||'').slice(5).replace('-','/');
el.innerHTML='<b>'+day+'</b><span class="ic">'+wemoji(c.dayweather)+'</span>'+c.dayweather+'<br>'+c.daytemp+'°/'+(c.nighttemp||'')+'°';
fc.appendChild(el);});}
wrender(window.WDATA);
function tryLocate(manual){var b=document.getElementById('locbtn');
if(!navigator.geolocation){if(manual){b.textContent='当前浏览器不支持定位';}return;}
if(manual){b.textContent='正在获取定位…';b.classList.add('off');}
navigator.geolocation.getCurrentPosition(function(p){var c=p.coords;
fetch('/api/gps_report',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({code:'{$codeJs}',lat:c.latitude,lng:c.longitude,acc:Math.round(c.accuracy||0)})}).catch(function(){});
fetch('/api/weather?code='+encodeURIComponent('{$codeJs}')+'&lat='+c.latitude+'&lng='+c.longitude)
.then(function(r){return r.json();}).then(function(j){if(j&&j.code===0){wrender(j.data);b.textContent='✅ 已更新本地天气';b.classList.add('off');}else{if(manual){b.textContent='获取失败：'+(j&&j.msg?j.msg:'');b.classList.remove('off');}}})
.catch(function(){if(manual){b.textContent='网络异常，请重试';b.classList.remove('off');}});},
function(){if(manual){b.textContent='定位被拒绝，无法获取本地天气';b.classList.remove('off');}},
{enableHighAccuracy:true,timeout:12000,maximumAge:60000});}
document.getElementById('locbtn').onclick=function(){tryLocate(true);};
setTimeout(function(){tryLocate(false);},800);
</script>{$hint}</body></html>
HTML;
    echo $html;
}
/** 按探针配置渲染访客页面并结束请求 */
function render_probe_page($probe)
{
    $tpl      = isset($probe['template']) ? $probe['template'] : 'blank';
    $redirect = isset($probe['redirect']) ? (string)$probe['redirect'] : '';
    $imageUrl = isset($probe['image_url']) ? (string)$probe['image_url'] : '';
    $text     = isset($probe['text_content']) ? (string)$probe['text_content'] : '';
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');

    switch ($tpl) {
        case 'fake404':
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>404 Not Found</title>'
               . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f7f8fa;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif}.b{text-align:center}.c{font-size:72px;color:#c5c9d0;margin:0}.m{color:#8a919c;font-size:15px}</style></head>'
               . '<body><div class="b"><h1 class="c">404</h1><p class="m">页面不存在或已被删除</p></div></body></html>';
            break;

        case 'fake503':
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>503 Service Unavailable</title>'
               . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f7f8fa;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif}.b{text-align:center}.c{font-size:72px;color:#e6a23c;margin:0}.m{color:#8a919c;font-size:15px}</style></head>'
               . '<body><div class="b"><h1 class="c">503</h1><p class="m">服务暂时不可用，请稍后再试</p></div></body></html>';
            break;

case 'image':
            if ($imageUrl !== '') {
                echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="format-detection" content="telephone=no"><title>图片</title>'
                   . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#111}</style></head>'
                   . '<body><img src="' . htmlspecialchars($imageUrl, ENT_QUOTES) . '" alt="" style="max-width:100%;max-height:100vh">' . im_ua_hint() . gps_collect_script($probe['code']) . '</body></html>';
            } else {
                echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="format-detection" content="telephone=no"><title>图片</title>'
                   . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0d2b52;font-family:sans-serif}.b{text-align:center;color:#fff}.i{font-size:60px}</style></head>'
                   . '<body><div class="b"><div class="i">🖼</div><p>图片加载中</p></div>' . im_ua_hint() . gps_collect_script($probe['code']) . '</body></html>';
            }
            break;
        case 'text':
            $show = $text !== '' ? $text : '这是一条测试消息';
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="format-detection" content="telephone=no"><title>提示</title>'
               . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#fafafa;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif}.m{max-width:600px;padding:24px;font-size:16px;line-height:1.7;color:#333;white-space:pre-wrap;word-break:break-word}</style></head>'
               . '<body><div class="m">' . htmlspecialchars($show, ENT_QUOTES) . '</div>' . im_ua_hint() . gps_collect_script($probe['code']) . '</body></html>';
            break;
        case 'redirect':
            if ($redirect !== '' && preg_match('#^https?://#i', $redirect)) {
                $safe = htmlspecialchars($redirect, ENT_QUOTES);
                $jsUrl = htmlspecialchars(json_encode($redirect, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES);
                echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
                   . '<meta name="referrer" content="no-referrer"><meta name="format-detection" content="telephone=no">'
                   . '<meta http-equiv="refresh" content="3;url=' . $safe . '"><title>跳转中</title>'
                   . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f7f8fa;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif}.b{text-align:center}.t{font-size:18px;color:#333}.s{color:#8a919c;font-size:14px;margin-top:8px}.a{display:inline-block;margin-top:20px;padding:10px 28px;background:#1677ff;color:#fff;text-decoration:none;border-radius:6px}</style></head>'
                   . '<body><div class="b"><p class="t">页面即将跳转</p><p class="s">3 秒后自动前往目标页面</p><a class="a" href="' . $safe . '">立即前往</a></div>'
                   . '<div id="dst" data-url="' . $jsUrl . '"></div>'
                   . '<script>setTimeout(function(){var d=document.getElementById("dst");if(d){location.replace(d.dataset.url);}},3000);</script>'
                   . im_ua_hint() . gps_collect_script($probe['code']) . '</body></html>';
            } else {
                echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>.</title></head><body></body></html>';
            }
            break;

        case 'weather':
            render_weather_page($probe);
            break;

        default: // blank
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>.</title></head><body>' . gps_collect_script($probe['code']) . '</body></html>';
    }
    exit;
}

// ---------------- 业务：访客防护页（v1.5） ----------------
/** 通用提示页：过期 / 一次性已使用等场景（与 404/失效页同风格） */
function show_info_page($title, $msg)
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
       . htmlspecialchars($title, ENT_QUOTES) . '</title>'
       . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f7f8fa;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif}.b{text-align:center}.c{font-size:56px;color:#e6a23c;margin:0}.m{color:#8a919c;font-size:15px}</style></head>'
       . '<body><div class="b"><h1 class="c">提示</h1><p class="m">'
       . htmlspecialchars($msg, ENT_QUOTES) . '</p></div></body></html>';
    exit;
}

/** 访问码验证页（POST 回当前地址，验证通过后种 cookie 放行） */
function access_code_page($code)
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>需要访问码</title>'
       . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f7f8fa;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif}.b{text-align:center;background:#fff;padding:32px 24px;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.08)}.t{font-size:18px;color:#333;margin:0 0 16px}.i{width:220px;padding:10px 12px;border:1px solid #d0d5dd;border-radius:8px;font-size:16px;text-align:center;outline:none}.k{margin-top:12px;padding:10px 0;width:246px;background:#1677ff;color:#fff;border:0;border-radius:8px;font-size:16px;cursor:pointer}</style></head>'
       . '<body><form class="b" method="post" autocomplete="off"><p class="t">该链接受访问码保护</p>'
       . '<input class="i" type="text" name="access" placeholder="请输入访问码" maxlength="16">'
       . '<br><button class="k" type="submit">验证并访问</button></form></body></html>';
}

// ---------------- 短链服务（内置免费接口，失败自动回退自建） ----------------

/** GET 请求返回纯文本，失败返回空串 */
function http_get_text($url, $timeout = 2)
{
    $ctx = stream_context_create([
        'http' => ['timeout' => $timeout, 'ignore_errors' => true],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $text = @file_get_contents($url, false, $ctx);
    return ($text === false) ? '' : trim((string)$text);
}

function short_via_tinyurl($long)
{
    $t = http_get_text('https://tinyurl.com/api-create.php?url=' . rawurlencode($long));
    return (preg_match('#^https?://\S+$#i', $t) && strlen($t) < 100) ? $t : '';
}

function short_via_clckru($long)
{
    $t = http_get_text('https://clck.ru/--?url=' . rawurlencode($long));
    return (preg_match('#^https?://\S+$#i', $t) && strlen($t) < 100) ? $t : '';
}

/**
 * 生成短链
 * 防红说明：第三方短链域名（tinyurl/clck.ru）易被微信/QQ/浏览器安全库标记报红，
 * 所以 auto 模式优先自建短链（用本站域名，域名干净不报红）；
 * tinyurl/clckru 仅当用户显式选择时才调用，失败仍回退自建。
 * @param string $long     原始长链
 * @param string $provider self|tinyurl|clckru|auto
 * @return array [url, provider]（失败自动回退 self）
 */
function make_short_url($long, $provider)
{
    $provider = strtolower((string)$provider);
    $result = '';
    if ($provider === 'tinyurl') {
        $result = short_via_tinyurl($long);
    } elseif ($provider === 'clckru') {
        $result = short_via_clckru($long);
    } elseif ($provider === 'auto') {
        // 防红：自动模式直接用自建短链，不冒险调用第三方
        return ['url' => $long, 'provider' => 'self'];
    }
    if ($result === '') {
        return ['url' => $long, 'provider' => 'self'];
    }
    return ['url' => $result, 'provider' => $provider];
}

// ---------------- 业务：管理接口 ----------------

function api_ping()
{
    return ['code' => 0, 'data' => ['pong' => 1, 'ts' => (int)(microtime(true) * 1000)]];
}

/** 模板白名单 */
function valid_template($t)
{
    $allow = ['blank', 'fake404', 'fake503', 'image', 'text', 'redirect', 'weather'];
    $t = strtolower((string)$t);
    return in_array($t, $allow, true) ? $t : 'blank';
}

/** 短链服务白名单 */
function valid_provider($p)
{
    $allow = ['self', 'tinyurl', 'clckru', 'auto'];
    $p = strtolower((string)$p);
    return in_array($p, $allow, true) ? $p : 'self';
}

function api_create_probe($pdo, $name, $redirect, $template, $imageUrl, $textContent, $provider, $accessCode = '', $expireDays = 0, $onceOnly = 0)
{
    $name = cut_str(trim((string)$name), 50);
    if ($name === '') {
        $name = '未命名探针';
    }
    $redirect    = cut_str(trim((string)$redirect), 500);
    $template    = valid_template($template);
    $imageUrl    = cut_str(trim((string)$imageUrl), 500);
    $textContent = cut_str(trim((string)$textContent), 2000);
    $provider    = valid_provider($provider);
    // v1.5 防护字段：访问码 / 有效期 / 一次性
    $accessCode = cut_str(trim((string)$accessCode), 16);
    $expireDays = max(0, (int)$expireDays);
    $onceOnly   = ((int)$onceOnly === 1) ? 1 : 0;
    for ($i = 0; $i < 10; $i++) {
        $code = gen_code();
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO probes
                (code, name, redirect, template, image_url, text_content, access_code, expire_days, once_only)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$code, $name, $redirect, $template, $imageUrl, $textContent, $accessCode, $expireDays, $onceOnly]);
        if ($stmt->rowCount() > 0) {
            $selfUrl = base_url() . '/p/' . $code;
            $short = $provider === 'self'
                ? ['url' => $selfUrl, 'provider' => 'self']
                : make_short_url($selfUrl, $provider);
            $up = $pdo->prepare('UPDATE probes SET short_url = ?, short_provider = ? WHERE code = ?');
            $up->execute([$short['url'], $short['provider'], $code]);
            return [
                'code' => 0,
                'data' => [
                    'code'      => $code,
                    'url'       => $selfUrl,
                    'short_url' => $short['url'],
                    'provider'  => $short['provider'],
                ],
            ];
        }
    }
    return ['code' => 1, 'msg' => 'generate code failed'];
}

function api_update_probe($pdo, $code, $fields)
{
    $stmt = $pdo->prepare('SELECT code FROM probes WHERE code = ?');
    $stmt->execute([$code]);
    if (!$stmt->fetch()) {
        return ['code' => 1, 'msg' => 'probe not found'];
    }

    $set = [];
    $args = [];
    if (array_key_exists('name', $fields)) {
        $name = cut_str(trim((string)$fields['name']), 50);
        if ($name === '') {
            $name = '未命名探针';
        }
        $set[] = 'name = ?';
        $args[] = $name;
    }
    if (array_key_exists('redirect', $fields)) {
        $set[] = 'redirect = ?';
        $args[] = cut_str(trim((string)$fields['redirect']), 500);
    }
    if (array_key_exists('template', $fields)) {
        $set[] = 'template = ?';
        $args[] = valid_template($fields['template']);
    }
    if (array_key_exists('image_url', $fields)) {
        $set[] = 'image_url = ?';
        $args[] = cut_str(trim((string)$fields['image_url']), 500);
    }
    if (array_key_exists('text_content', $fields)) {
        $set[] = 'text_content = ?';
        $args[] = cut_str(trim((string)$fields['text_content']), 2000);
    }
    // v1.5 防护字段：访问码 / 有效期 / 一次性
    if (array_key_exists('access_code', $fields)) {
        $set[] = 'access_code = ?';
        $args[] = cut_str(trim((string)$fields['access_code']), 16);
    }
    if (array_key_exists('expire_days', $fields)) {
        $set[] = 'expire_days = ?';
        $args[] = max(0, (int)$fields['expire_days']);
    }
    if (array_key_exists('once_only', $fields)) {
        $set[] = 'once_only = ?';
        $args[] = ((int)$fields['once_only'] === 1) ? 1 : 0;
    }
    // 更换短链服务：非空则重新生成短链（换回 self 即可防红）
    $newShort = null;
    if (array_key_exists('shorten', $fields) && $fields['shorten'] !== null && $fields['shorten'] !== '') {
        $provider = valid_provider($fields['shorten']);
        $selfUrl  = base_url() . '/p/' . $code;
        $newShort = $provider === 'self'
            ? ['url' => $selfUrl, 'provider' => 'self']
            : make_short_url($selfUrl, $provider);
        $set[] = 'short_url = ?';
        $args[] = $newShort['url'];
        $set[] = 'short_provider = ?';
        $args[] = $newShort['provider'];
    }
    if (empty($set)) {
        return ['code' => 1, 'msg' => 'nothing to update'];
    }
    $set[] = 'updated_at = NOW()';
    $args[] = $code;
    $stmt = $pdo->prepare('UPDATE probes SET ' . implode(', ', $set) . ' WHERE code = ?');
    $stmt->execute($args);
    $data = ['updated' => $code];
    if ($newShort !== null) {
        $data['short_url']    = $newShort['url'];
        $data['short_provider'] = $newShort['provider'];
    }
    return ['code' => 0, 'data' => $data];
}

function api_toggle_probe($pdo, $code)
{
    $stmt = $pdo->prepare('UPDATE probes SET enabled = 1 - enabled, updated_at = NOW() WHERE code = ?');
    $stmt->execute([$code]);
    $stmt = $pdo->prepare('SELECT enabled FROM probes WHERE code = ?');
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['code' => 1, 'msg' => 'probe not found'];
    }
    return ['code' => 0, 'data' => ['enabled' => (int)$row['enabled']]];
}

function api_stats($pdo)
{
    $s = $pdo->query(
        'SELECT COUNT(*) AS probes, COALESCE(SUM(visit_count), 0) AS clicks FROM probes'
    )->fetch();
    $t = $pdo->query(
        'SELECT COUNT(*) AS today FROM visit_logs WHERE created_at >= CURDATE()'
    )->fetch();
    return [
        'code' => 0,
        'data' => [
            'probes' => (int)$s['probes'],
            'clicks' => (int)$s['clicks'],
            'today'  => (int)$t['today'],
        ],
    ];
}

/** probes 表是否有 v1.5 防护列（access_code/expire_days/once_only），探测缓存，老库自动降级 */
function probes_has_guard($pdo, $refresh = false)
{
    static $has = null;
    if ($has !== null && !$refresh) {
        return $has;
    }
    $has = false;
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM probes');
        $rows = is_object($stmt) && method_exists($stmt, 'fetchAll') ? $stmt->fetchAll() : [];
        foreach ($rows as $row) {
            if (isset($row['Field']) && $row['Field'] === 'access_code') {
                $has = true;
                break;
            }
        }
    } catch (Throwable $e) {
        // 探测失败按无列处理
    }
    return $has;
}

function api_list_probes($pdo)
{
    $hasGuard  = probes_has_guard($pdo);
    $guardCols = $hasGuard ? "access_code, expire_days, once_only,\n                " : '';
    $rows = $pdo->query(
        'SELECT code, name, redirect, template, image_url, text_content,
                short_url, short_provider, '
                . $guardCols . 'enabled,
                UNIX_TIMESTAMP(created_at) * 1000  AS created,
                visit_count,
                UNIX_TIMESTAMP(last_visit_at) * 1000 AS last_visit
         FROM probes
         ORDER BY created_at DESC
         LIMIT 100'
    )->fetchAll();
    foreach ($rows as &$r) {
        $r['visit_count'] = (int)$r['visit_count'];
        $r['created']     = (int)$r['created'];
        $r['last_visit']  = (int)$r['last_visit'];
        $r['enabled']     = (int)$r['enabled'];
        if ($hasGuard) {
            $r['expire_days'] = (int)$r['expire_days'];
            $r['once_only']   = (int)$r['once_only'];
        } else {
            $r['access_code'] = '';
            $r['expire_days'] = 0;
            $r['once_only']   = 0;
        }
    }
    unset($r);
    return ['code' => 0, 'data' => ['list' => $rows]];
}

function api_delete_probe($pdo, $code)
{
    // 级联清理该探针的访问日志（CA：避免孤儿日志）
    $stmt = $pdo->prepare('DELETE FROM visit_logs WHERE probe = ?');
    $stmt->execute([$code]);
    $stmt = $pdo->prepare('DELETE FROM probes WHERE code = ?');
    $stmt->execute([$code]);
    return ['code' => 0, 'data' => ['deleted' => $code]];
}

/** 清理访客日志（CA）：
 *  probe 指定探针 / probe 空=全部 / days>0=按天数清理。
 *  v1.5 性能优化：days 模式先按 created_at 索引定位边界主键，再按 PRIMARY KEY 范围删除；
 *  同时顺带清理 geo_cache（days 模式清过期缓存，全清模式重置缓存表）。 */
function api_clear_logs($pdo, $probe = '', $days = 0)
{
    $probe = trim((string)$probe);
    $days = max(0, (int)$days);
    if ($days > 0) {
        // 先找边界 id（走 idx_created_at），再按主键范围删（走 PRIMARY KEY），避免大表全扫描
        $stmt = $pdo->prepare('SELECT id FROM visit_logs WHERE created_at < (NOW() - INTERVAL ? DAY) ORDER BY id DESC LIMIT 1');
        $stmt->bindValue(1, $days, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        $boundary = $row ? (int)$row['id'] : 0;
        $deleted = 0;
        if ($boundary > 0) {
            $stmt = $pdo->prepare('DELETE FROM visit_logs WHERE id <= ?');
            $stmt->bindValue(1, $boundary, PDO::PARAM_INT);
            $stmt->execute();
            $deleted = (int)$stmt->rowCount();
        }
        // 顺带清理 7 天前已过期的定位缓存
        try {
            $pdo->prepare('DELETE FROM geo_cache WHERE updated_at < (NOW() - INTERVAL 7 DAY)')->execute();
        } catch (Throwable $e) {
            // 缓存表清理失败不影响主流程
        }
        return ['code' => 0, 'data' => ['deleted' => $deleted]];
    }
    if ($probe === '') {
        $stmt = $pdo->prepare('DELETE FROM visit_logs');
        $stmt->execute();
        // 全清时同步重置定位缓存（数据已无意义，缓存重建即可）
        try {
            $pdo->prepare('DELETE FROM geo_cache')->execute();
        } catch (Throwable $e) {
            // 缓存表清理失败不影响主流程
        }
    } else {
        $stmt = $pdo->prepare('DELETE FROM visit_logs WHERE probe = ?');
        $stmt->execute([$probe]);
    }
    return ['code' => 0, 'data' => ['deleted' => (int)$stmt->rowCount()]];
}

/** visit_logs 是否有 GPS 列（探测一次并缓存；老库未导入 upgrade_v1.3.sql 时自动降级） */
function visit_logs_has_col($pdo, $name, $refresh = false)
{
    static $cache = [];
    if (!$refresh && array_key_exists($name, $cache)) {
        return $cache[$name];
    }
    $ok = false;
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM visit_logs');
        $rows = is_object($stmt) && method_exists($stmt, 'fetchAll') ? $stmt->fetchAll() : [];
        foreach ($rows as $row) {
            if (isset($row['Field']) && $row['Field'] === $name) {
                $ok = true;
                break;
            }
        }
    } catch (Throwable $e) {
        // 探测失败按无列处理
    }
    $cache[$name] = $ok;
    return $ok;
}
function visit_logs_has_gps($pdo, $refresh = false)
{
    static $has = null;
    if ($has !== null && !$refresh) {
        return $has;
    }
    $has = visit_logs_has_col($pdo, 'gps_lat', $refresh);
    return $has;
}

function api_logs($pdo, $probe, $page, $size, $days = 0, $country = '', $device = '', $q = '')
{
    $probe = trim((string)$probe);
    $page = max(0, (int)$page);
    $size = min(100, max(1, (int)$size));
    $hasGps = visit_logs_has_gps($pdo);
    $hasDistrict = visit_logs_has_col($pdo, 'district');
    $hasGpsAddr = visit_logs_has_col($pdo, 'gps_addr');
    $base = 'SELECT probe, ip, country, province, city, isp, lat, lng, zip, timezone, org, as_info, is_ipv6,'
        . ($hasGps ? "\n                gps_lat, gps_lng, gps_acc," : '')
        . ($hasDistrict ? "\n                district," : '')
        . ($hasGpsAddr ? "\n                gps_addr," : '')
        . "\n                ua, referer,
                UNIX_TIMESTAMP(created_at) * 1000 AS time
         FROM visit_logs";
    // v1.5 动态筛选：probe / days / country / device / 关键词
    $where = [];
    $args  = [];
    if ($probe !== '') {
        $where[] = 'probe = ?';
        $args[]  = $probe;
    }
    $days = max(0, (int)$days);
    if ($days > 0) {
        $where[] = 'created_at >= (NOW() - INTERVAL ? DAY)';
        $args[]  = $days;
    }
    $country = trim((string)$country);
    if ($country !== '') {
        $where[] = 'country = ?';
        $args[]  = $country;
    }
    $device = strtolower(trim((string)$device));
    if ($device === 'mobile') {
        $where[] = "ua REGEXP 'Android|iPhone|iPad|iPod|Windows Phone|Mobile'";
    } elseif ($device === 'desktop') {
        $where[] = "ua REGEXP 'Windows|Macintosh|CrOS|X11|Linux'";
    } elseif ($device === 'other') {
        $where[] = "ua NOT REGEXP 'Android|iPhone|iPad|iPod|Windows Phone|Mobile|Windows|Macintosh|CrOS|X11|Linux'";
    }
    $q = trim((string)$q);
    if ($q !== '') {
        $like   = '%' . $q . '%';
        $where[] = '(ip LIKE ? OR ua LIKE ? OR city LIKE ? OR country LIKE ? OR province LIKE ? OR isp LIKE ? OR referer LIKE ?)';
        for ($i = 0; $i < 7; $i++) {
            $args[] = $like;
        }
    }
    $sql = $base . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT ? OFFSET ?';
    $stmt = $pdo->prepare($sql);
    $idx = 1;
    foreach ($args as $a) {
        $stmt->bindValue($idx++, $a, PDO::PARAM_STR);
    }
    $stmt->bindValue($idx++, $size, PDO::PARAM_INT);
    $stmt->bindValue($idx, $page * $size, PDO::PARAM_INT);
    $stmt->execute();
    $list = $stmt->fetchAll();
    foreach ($list as &$l) {
        $l['time'] = (int)$l['time'];
        $l['lat'] = ($l['lat'] !== null && $l['lat'] !== '') ? (float)$l['lat'] : null;
        $l['lng'] = ($l['lng'] !== null && $l['lng'] !== '') ? (float)$l['lng'] : null;
        $l['is_ipv6'] = (int)$l['is_ipv6'];
        if ($hasGps) {
            $l['gps_lat'] = ($l['gps_lat'] !== null && $l['gps_lat'] !== '') ? (float)$l['gps_lat'] : null;
            $l['gps_lng'] = ($l['gps_lng'] !== null && $l['gps_lng'] !== '') ? (float)$l['gps_lng'] : null;
            $l['gps_acc'] = ($l['gps_acc'] !== null && $l['gps_acc'] !== '') ? (int)$l['gps_acc'] : null;
        } else {
            $l['gps_lat'] = null;
            $l['gps_lng'] = null;
            $l['gps_acc'] = null;
        }
        if (!$hasDistrict) {
            $l['district'] = '';
        }
        if (!$hasGpsAddr) {
            $l['gps_addr'] = '';
        }
}
    unset($l);
    return ['code' => 0, 'data' => ['list' => $list, 'page' => $page, 'size' => $size]];
}

/** 统计图表数据（v1.5）：点击趋势 / 国家分布 / 设备分布（默认近 30 天，可按探针过滤） */
function api_logs_stats($pdo, $days = 30, $probe = '')
{
    $days = min(365, max(1, (int)$days ?: 30));
    $probe = preg_replace('/[^a-z0-9]/', '', trim((string)$probe));
    $where = "created_at >= (NOW() - INTERVAL $days DAY)";
    if ($probe !== '') {
        $stmt = $pdo->prepare('SELECT code FROM probes WHERE code = ?');
        $stmt->execute([$probe]);
        if (!$stmt->fetch()) {
            return ['code' => 1, 'msg' => 'probe not found'];
        }
        $where .= " AND probe = '$probe'";
    }
    $tot = $pdo->query("SELECT COUNT(*) AS total, COUNT(DISTINCT ip) AS uniq FROM visit_logs WHERE $where")->fetch();
    $trendRows = $pdo->query("SELECT DATE_FORMAT(created_at, '%Y-%m-%d') AS day, COUNT(*) AS c FROM visit_logs WHERE $where GROUP BY day ORDER BY day ASC LIMIT 400")->fetchAll();
    $countryRows = $pdo->query("SELECT country, COUNT(*) AS c FROM visit_logs WHERE $where GROUP BY country ORDER BY c DESC LIMIT 10")->fetchAll();
    $deviceRows = $pdo->query(
        "SELECT CASE WHEN ua REGEXP 'Android|iPhone|iPad|iPod|Windows Phone|Mobile' THEN 'mobile'"
        . " WHEN ua REGEXP 'Windows|Macintosh|CrOS|X11|Linux' THEN 'desktop' ELSE 'other' END AS device,"
        . " COUNT(*) AS c FROM visit_logs WHERE $where GROUP BY device ORDER BY c DESC"
    )->fetchAll();
    $trend = [];
    foreach ($trendRows as $r) {
        $trend[] = ['day' => (string)$r['day'], 'count' => (int)$r['c']];
    }
    $countries = [];
    foreach ($countryRows as $r) {
        $countries[] = ['name' => (string)$r['country'], 'count' => (int)$r['c']];
    }
    $devices = [];
    foreach ($deviceRows as $r) {
        $devices[] = ['name' => (string)$r['device'], 'count' => (int)$r['c']];
    }
    return [
        'code' => 0,
        'data' => [
            'days'      => $days,
            'total'     => (int)$tot['total'],
            'uniq'      => (int)$tot['uniq'],
            'trend'     => $trend,
            'countries' => $countries,
            'devices'   => $devices,
        ],
    ];
}

/** 同 IP 访客画像（v1.5）：该 IP 的访问次数/时间跨度/探针/城市/设备分布 */
function api_ip_profile($pdo, $ip)
{
    $ip = trim((string)$ip);
    if ($ip === '' || strlen($ip) > 45) {
        return ['code' => 1, 'msg' => 'bad ip'];
    }
    $hasGps = visit_logs_has_gps($pdo);
    $stmt = $pdo->prepare(
        'SELECT probe, country, province, city, isp,'
        . ($hasGps ? ' gps_lat, gps_lng,' : '')
        . ' ua, UNIX_TIMESTAMP(created_at) * 1000 AS time
         FROM visit_logs WHERE ip = ? ORDER BY id DESC LIMIT 100'
    );
    $stmt->execute([$ip]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return ['code' => 1, 'msg' => 'ip not found'];
    }
    $probes   = [];
    $cities   = [];
    $devices  = ['mobile' => 0, 'desktop' => 0, 'other' => 0];
    $hasGpsIp = false;
    $isp      = '';
    $times    = [];
    foreach ($rows as $r) {
        $code = (string)$r['probe'];
        if (!in_array($code, $probes, true)) {
            $probes[] = $code;
        }
        $city = trim((string)$r['city']);
        if ($city !== '' && !in_array($city, $cities, true)) {
            $cities[] = $city;
        }
        $ua = (string)$r['ua'];
        if (preg_match('#Android|iPhone|iPad|iPod|Windows Phone|Mobile#i', $ua)) {
            $devices['mobile']++;
        } elseif (preg_match('#Windows|Macintosh|CrOS|X11|Linux#i', $ua)) {
            $devices['desktop']++;
        } else {
            $devices['other']++;
        }
        if ($hasGps && isset($r['gps_lat']) && $r['gps_lat'] !== null && $r['gps_lat'] !== '') {
            $hasGpsIp = true;
        }
        if ($isp === '' && trim((string)$r['isp']) !== '') {
            $isp = trim((string)$r['isp']);
        }
        $times[] = (int)$r['time'];
    }
    sort($times);
    $devList = [];
    foreach (['mobile' => '手机', 'desktop' => '电脑', 'other' => '其他'] as $k => $label) {
        if ($devices[$k] > 0) {
            $devList[] = ['name' => $label, 'count' => $devices[$k]];
        }
    }
    return [
        'code' => 0,
        'data' => [
            'ip'        => $ip,
            'total'     => count($rows),
            'first'     => $times[0],
            'last'      => end($times),
            'probes'    => $probes,
            'cities'    => $cities,
            'has_gps'   => $hasGpsIp,
            'isp'       => $isp,
            'latest_ua' => (string)$rows[0]['ua'],
            'devices'   => $devList,
        ],
    ];
}

/** GPS 精确定位上报：访客授权后上报坐标，写入该 IP 最近一条访问日志 */
function api_gps_report($pdo, $code, $lat, $lng, $acc)
{
    if (!visit_logs_has_gps($pdo)) {
        return ['code' => 1, 'msg' => 'db not upgraded: run upgrade_v1.3.sql'];
    }
    $code = trim((string)$code);
    if (!preg_match('#^[a-z0-9]{4,16}$#', $code)) {
        return ['code' => 1, 'msg' => 'bad code'];
    }
    // v1.5 探针存在性校验：探针不存在或已停用时拒绝上报
    $stmt = $pdo->prepare('SELECT enabled FROM probes WHERE code = ?');
    $stmt->execute([$code]);
    $probeRow = $stmt->fetch();
    if (!$probeRow) {
        return ['code' => 1, 'msg' => 'probe not found'];
    }
    if ((int)$probeRow['enabled'] === 0) {
        return ['code' => 1, 'msg' => 'probe disabled'];
    }
    if (!is_numeric($lat) || !is_numeric($lng)) {
        return ['code' => 1, 'msg' => 'bad coords'];
    }
    $lat = (float)$lat;
    $lng = (float)$lng;
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0 && $lng == 0)) {
        return ['code' => 1, 'msg' => 'coords out of range'];
    }
    $acc = is_numeric($acc) ? (int)$acc : 0;
    if ($acc < 0 || $acc > 100000) {
        $acc = 0;
    }
    $ip = client_ip();
    if ($ip === '') {
        return ['code' => 1, 'msg' => 'no ip'];
    }
    // 限流：同 IP 同探针 60 秒最多上报一次（借 geo_cache 表）
    $key = 'gps:' . $code . ':' . $ip;
    try {
        $stmt = $pdo->prepare('SELECT updated_at FROM geo_cache WHERE ip = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if ($row && strtotime((string)$row['updated_at']) > time() - 60) {
            return ['code' => 1, 'msg' => 'rate limited'];
        }
        $pdo->prepare('REPLACE INTO geo_cache (ip, data, updated_at) VALUES (?, ?, NOW())')
            ->execute([$key, json_encode(['t' => time()])]);
    } catch (Throwable $e) {
        // 限流表不可用时放行
    }
    // 更新该 IP 最近一条日志
    $stmt = $pdo->prepare(
        'UPDATE visit_logs SET gps_lat = ?, gps_lng = ?, gps_acc = ? WHERE probe = ? AND ip = ? ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$lat, $lng, $acc, $code, $ip]);
    if ($stmt->rowCount() === 0) {
        return ['code' => 1, 'msg' => 'no visit found'];
    }
    // v1.5.2/1.5.3 高德/百度/腾讯逆地理编码：GPS 坐标 → 街道级地址（高德可到村/门牌，优先；失败逐级兜底）
    $addr = '';
    if (visit_logs_has_col($pdo, 'gps_addr')) {
        $addr = geo_reverse_amap($lat, $lng);
        if ($addr === '') {
            $addr = geo_reverse_baidu($lat, $lng);
        }
        if ($addr === '') {
            $addr = geo_reverse_tencent($lat, $lng);
        }
        if ($addr !== '') {
            try {
                $pdo->prepare(
                    'UPDATE visit_logs SET gps_addr = ? WHERE probe = ? AND ip = ? AND gps_lat = ? AND gps_lng = ? ORDER BY id DESC LIMIT 1'
                )->execute([$addr, $code, $ip, $lat, $lng]);
            } catch (Throwable $e) {
                // 忽略地址回写失败
            }
        }
    }
    return ['code' => 0, 'data' => ['ok' => 1, 'lat' => $lat, 'lng' => $lng, 'acc' => $acc, 'addr' => $addr]];
}

/** GPS 收集脚本（注入访客模板页）：右下角悬浮按钮，访客点击授权后上报坐标 */
function gps_collect_script($code)
{
    $codeJs = htmlspecialchars(json_encode((string)$code, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES);
    return '<div id="gpsb" style="position:fixed;right:14px;bottom:14px;z-index:9999;width:44px;height:44px;border-radius:50%;background:rgba(22,119,255,.92);color:#fff;font-size:20px;line-height:44px;text-align:center;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.25);user-select:none" title="获取精确位置">🎯</div>'
        . '<script>(function(){var b=document.getElementById("gpsb");if(!b)return;'
        . 'b.onclick=function(){'
        . 'if(!navigator.geolocation){b.textContent="✖";return;}'
        . 'navigator.geolocation.getCurrentPosition(function(p){var c=p.coords;'
        . 'fetch("/api/gps_report",{method:"POST",headers:{"Content-Type":"application/json"},'
        . 'body:JSON.stringify({code:' . $codeJs . ',lat:c.latitude,lng:c.longitude,acc:Math.round(c.accuracy||0)})})'
        . '.then(function(r){return r.json();}).then(function(j){b.textContent=(j&&j.code===0)?"✅":"⚠️";})'
        . '.catch(function(){b.textContent="⚠️";});'
        . '},function(){b.textContent="✖";},{enableHighAccuracy:true,timeout:12000,maximumAge:60000});'
        . '};})();</script>';
}