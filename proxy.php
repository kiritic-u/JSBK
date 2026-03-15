<?php
// proxy.php - 针对 BKCS 提速版 Python 后端优化
error_reporting(0); 

$config_path = __DIR__ . '/includes/config.php';
if (!file_exists($config_path)) {
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode(['code' => 500, 'msg' => '配置文件缺失']));
}
require_once $config_path;

// 1. 来源验证 (保持你的安全策略)
$allowedDomains = ['jx1314.cc', 'gz.jx1314.cc', 'localhost', '127.0.0.1'];
$referer = $_SERVER['HTTP_REFERER'] ?? $_SERVER['HTTP_ORIGIN'] ?? '';
$isAllowed = false;
foreach ($allowedDomains as $domain) {
    if (strpos($referer, $domain) !== false) {
        $isAllowed = true;
        break;
    }
}
if (!$isAllowed && $referer !== '') {
    header('HTTP/1.1 403 Forbidden');
    die(json_encode(['code' => 403, 'msg' => '非法调用']));
}

$pdo = getDB();
$redis = getRedis();

// 2. 获取 API 基础地址
$api_base = 'https://yy.jx1314.cc';
try {
    if ($redis) {
        $api_base = $redis->get(CACHE_PREFIX . 'setting:music_api_url') ?: $api_base;
    } else {
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE key_name = 'music_api_url'");
        $stmt->execute();
        $api_base = $stmt->fetchColumn() ?: $api_base;
    }
} catch (Exception $e) {}
$api_base = rtrim($api_base, '/');

// 3. 路径解析
$path = isset($_GET['path']) ? (string)$_GET['path'] : '';
$path = '/' . ltrim($path, '/');

$url_info = parse_url($path);
parse_str($url_info['query'] ?? '', $query_params);

$is_lyric_intercept = false;
if (strpos($path, '/lyric') === 0) {
    $lyricId = $query_params['id'] ?? '0'; 
    if($lyricId === '0'){
        $inputJSON = file_get_contents('php://input');
        $inputData = json_decode($inputJSON, true) ?: [];
        $lyricId = $inputData['id'] ?? '0';
    }
    // 歌词直接请求网易云官方接口，速度最快
    $url = "https://music.163.com/api/song/lyric?id={$lyricId}&lv=1&kv=1&tv=-1";
    $is_lyric_intercept = true;
} else {
    $url = $api_base . $path;
}

// 4. 频率限制 (适度放宽，防止大歌单加载时刷新页面被封)
if ($redis) {
    $clientIP = $_SERVER['REMOTE_ADDR'] ?: 'unknown';
    $rateLimitKey = CACHE_PREFIX . 'rate_limit:proxy:' . $clientIP;
    if ($redis->incr($rateLimitKey) > 150) { 
        header('HTTP/1.1 429 Too Many Requests');
        die(json_encode(['code' => 429, 'msg' => '操作太快了，歇会儿再试']));
    }
    if ($redis->ttl($rateLimitKey) == -1) $redis->expire($rateLimitKey, 60);
}

// 5. 缓存逻辑 (增加歌单缓存权重)
$cacheKey = CACHE_PREFIX . 'api_proxy:' . md5($url . file_get_contents('php://input'));
if ($redis && ($cachedData = $redis->get($cacheKey))) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Cache: HIT-Redis');
    echo $cachedData;
    exit;
}

// 6. 执行请求
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_ENCODING, ""); // 允许 Gzip 压缩传输，大幅提速

// ==========================================
// 【核心调优】：匹配 Python 端的 20s 逻辑
// ==========================================
// 考虑到 Python 端获取大歌单可能需要 20-40 秒
curl_setopt($ch, CURLOPT_TIMEOUT, 90);         // 允许最长等待 90 秒，防止 Cloudflare 524 触发前的缓冲
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);  // 建立连接放宽到 15 秒
// ==========================================

$headers = [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0',
    'Referer: https://music.163.com/',
    'X-Forwarded-For: ' . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'), // 传递客户端真实IP
    'Accept: application/json, text/plain, */*'
];

if (!$is_lyric_intercept && $_SERVER['REQUEST_METHOD'] === 'POST') {
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
    $headers[] = 'Content-Type: application/json';
}

curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

header('Content-Type: application/json; charset=utf-8');

if ($response === false) {
    echo json_encode(['code' => 500, 'msg' => '上游服务响应超时', 'detail' => $error]);
} else {
    // 自动适配网易云/Python 后端返回的 JSON
    echo $response;
    
    // 优化缓存策略：只有正常的响应才缓存
    if ($redis && $httpCode == 200 && strlen($response) > 100) {
        // 歌单数据比较珍贵，缓存 2 小时
        $ttl = (strpos($path, '/playlist') !== false) ? 7200 : 3600;
        $redis->setex($cacheKey, $ttl, $response);
    }
}