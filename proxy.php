<?php
// pages/proxy.php
/**
 * 优化版 API 代理层 (增加 Redis 缓存与高可用容错)
 **/
error_reporting(0); // 屏蔽底层 PHP 报错直接输出，避免破坏 JSON 结构

require_once 'includes/config.php'; 
$pdo = getDB();
$redis = getRedis();

// 1. 获取配置中的 API 地址 (优先从缓存拿，减少数据库压力)
$api_base = 'https://yy.jx1314.cc';
try {
    if ($redis) {
        $api_base = $redis->get(CACHE_PREFIX . 'setting:music_api_url') ?: $api_base;
    } else {
        $stmt = $pdo->query("SELECT value FROM settings WHERE key_name = 'music_api_url'");
        $api_base = $stmt->fetchColumn() ?: $api_base;
    }
} catch (Exception $e) {}

$api_base = rtrim($api_base, '/');
$path = isset($_GET['path']) ? $_GET['path'] : '';
$url = $api_base . $path;

// 2. 接收前端传来的 JSON 数据
$inputJSON = file_get_contents('php://input');

// 3. 💥 核心优化：尝试读取 Redis 缓存
$cacheKey = '';
if ($redis) {
    // 将 请求路径 + 请求体 组合成 MD5 作为唯一缓存 Key
    $cacheKey = CACHE_PREFIX . 'api_proxy:' . md5($path . $inputJSON);
    $cachedData = $redis->get($cacheKey);
    
    if ($cachedData) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Cache: HIT-Redis'); // 方便在浏览器网络面板排查
        echo $cachedData;
        exit;
    }
}

// 4. 初始化 cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $inputJSON);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'Referer: https://music.163.com/',
    'Origin: https://music.163.com'
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
curl_setopt($ch, CURLOPT_TIMEOUT, 15); // 将超时时间稍微放宽到 15 秒

// 5. 执行请求
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

header('Content-Type: application/json; charset=utf-8');

if (curl_errno($ch)) {
    // ❌ 错误处理优化：不要再返回 HTTP 500 了，改为返回 200 但带上业务错误码
    // 这样 JS 的 fetch 就不会报 500 红字，而是可以被 try-catch 优雅捕获
    echo json_encode([
        'code' => 500, 
        'msg' => '上游API请求超时或网络不稳定: ' . curl_error($ch)
    ]);
} else {
    header('X-Cache: MISS');
    echo $response;
    
    // 6. 💥 核心优化：请求成功，写入缓存
    if ($redis && $httpCode == 200) {
        $ttl = 3600; // 默认缓存 1 小时
        
        // 针对不同接口设置不同的过期时间
        if (strpos($path, '/lyric') !== false) {
            $ttl = 86400 * 7; // 歌词几乎不变化，缓存 7 天
        } elseif (strpos($path, '/song') !== false) {
            $ttl = 7200; // 歌曲播放链接容易过期，缓存 2 小时
        } elseif (strpos($path, '/playlist') !== false) {
            $ttl = 3600; // 歌单列表，缓存 1 小时
        }
        
        $redis->setex($cacheKey, $ttl, $response);
    }
}

curl_close($ch);
?>