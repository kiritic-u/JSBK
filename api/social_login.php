<?php
// api/social_login.php (博客端 - 支持聚合与官方双通道 - 积分完善版)
require_once '../includes/config.php';
// 确保引入了 redis (修复 session 问题)
require_once '../includes/redis_helper.php'; 

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = getDB();

// 获取后台配置并去除首尾空格
function getSocialConf($key) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE key_name = ?");
    $stmt->execute([$key]);
    return trim($stmt->fetchColumn() ?? '');
}

// 封装 Curl GET 请求
function curl_get($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $data = curl_exec($ch);
    curl_close($ch);
    return $data;
}

// 动态获取当前网站回调域名 (完美兼容其他人部署)
function getBaseUrl() {
    // 1. 判断是否是 HTTPS (兼容各种反向代理和 CDN)
    $is_https = false;
    if (isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) === 'on') {
        $is_https = true;
    } elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        $is_https = true;
    } elseif (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) {
        $is_https = true;
    }

    // 2. 拼接协议
    $protocol = $is_https ? "https://" : "http://";
    
    // 3. 获取当前域名 (包含端口号，如果有的话)
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // 返回动态拼接的完整网址，例如 https://www.abc.com 或 http://127.0.0.1:8080
    return $protocol . $host;
}

// 获取当前登录模式：aggregated (聚合) 或 official (官方)
$login_mode = getSocialConf('social_login_mode');
if (empty($login_mode)) $login_mode = 'aggregated';

// ----------------------------------------------------
// 【核心修改1】：智能识别请求动作，利用 state 参数接收平台类型
// ----------------------------------------------------
$action = $_GET['act'] ?? '';
$code = $_GET['code'] ?? '';
$type = trim($_GET['type'] ?? '');

// 如果抖音官方回调带回了 state 参数，我们将它识别为登录类型
$state = trim($_GET['state'] ?? '');
if (!empty($state) && in_array($state, ['qq', 'wx', 'douyin'])) {
    $type = $state; 
}

// 如果 URL 里存在 code，强制认为是 callback 回调阶段
if (!empty($code)) {
    $action = 'callback';
    if (empty($type) && strpos($_GET['act'] ?? '', '?type=') !== false) {
        $parts = explode('?type=', $_GET['act']);
        $type = $parts[1] ?? '';
    }
}

$allowed_types = ['qq', 'wx', 'douyin', 'alipay', 'sina', 'baidu', 'huawei', 'xiaomi', 'bilibili', 'dingtalk'];
if (!in_array($type, $allowed_types)) {
    die("不支持的登录方式: " . htmlspecialchars($type));
}

// ==========================================
// 阶段一：发起登录授权 (Login)
// ==========================================
if ($action === 'login') {
    
    // 【通道 A：第三方聚合登录】
    if ($login_mode === 'aggregated') {
        $apiUrl = getSocialConf('social_login_url');
        if (empty($apiUrl)) $apiUrl = 'https://u.cccyun.cc';
        $apiUrl = rtrim(preg_replace('/\/connect\.php.*/i', '', $apiUrl), '/');
        $appId = getSocialConf('social_appid');
        $appKey = getSocialConf('social_appkey');

        if (empty($appId) || empty($appKey)) die('<div style="padding:20px;color:red;"><h3>未配置聚合登录 AppID 或 AppKey</h3></div>');

        $params = [
            'act' => 'login', 'appid' => $appId, 'appkey' => $appKey, 'type' => $type,
            'redirect_uri' => getBaseUrl() . "/api/social_login.php" 
        ];
        $requestUrl = $apiUrl . "/connect.php?" . http_build_query($params);
        $response = curl_get($requestUrl);
        $res = json_decode($response, true);

        if ($res && isset($res['code']) && $res['code'] == 0 && !empty($res['url'])) {
            header("Location: " . $res['url']); exit;
        } else {
            die("获取聚合登录授权地址失败：" . ($res['msg'] ?? 'API返回为空'));
        }
    } 
    // 【通道 B：官方直连登录】
    else if ($login_mode === 'official') {
        
        // 【核心修改2】：给官方平台的重定向地址，必须绝对纯净，不能带任何 ? 问号参数！
        $clean_redirect_uri = urlencode(getBaseUrl() . "/api/social_login.php");
        
        if ($type === 'douyin') {
            $client_key = getSocialConf('official_dy_clientkey');
            if (empty($client_key)) die("后台未配置抖音官方 Client Key");
            
            // 将 douyin 标志放进 state 参数里带过去
            $url = "https://open.douyin.com/platform/oauth/connect/?client_key={$client_key}&response_type=code&scope=user_info&redirect_uri={$clean_redirect_uri}&state=douyin";
            header("Location: " . $url); exit;
        } 
        else if ($type === 'qq') {
            $client_id = getSocialConf('official_qq_appid');
            if (empty($client_id)) die("后台未配置 QQ 官方 App ID");
            $url = "https://graph.qq.com/oauth2.0/authorize?response_type=code&client_id={$client_id}&redirect_uri={$clean_redirect_uri}&state=qq";
            header("Location: " . $url); exit;
        } 
        else if ($type === 'wx') {
            $appid = getSocialConf('official_wx_appid');
            if (empty($appid)) die("后台未配置微信官方 AppID");
            $url = "https://open.weixin.qq.com/connect/qrconnect?appid={$appid}&redirect_uri={$clean_redirect_uri}&response_type=code&scope=snsapi_login&state=wx#wechat_redirect";
            header("Location: " . $url); exit;
        } else {
            die("当前官方直连通道暂未实现 [{$type}] 登录代码");
        }
    }

} 
// ==========================================
// 阶段二：回调获取用户信息 (Callback)
// ==========================================
elseif ($action === 'callback') {
    
    if (empty($code)) die('回调参数缺失 (缺少 code)');

    $social_uid = '';
    $nickname = '';
    $avatar = '';

    // 【通道 A：第三方聚合登录回调】
    if ($login_mode === 'aggregated') {
        $apiUrl = rtrim(preg_replace('/\/connect\.php.*/i', '', getSocialConf('social_login_url') ?: 'https://u.cccyun.cc'), '/');
        $params = [
            'act' => 'callback', 'appid' => getSocialConf('social_appid'), 
            'appkey' => getSocialConf('social_appkey'), 'type' => $type, 'code' => $code
        ];
        $requestUrl = $apiUrl . "/connect.php?" . http_build_query($params);
        $res = json_decode(curl_get($requestUrl), true);

        if ($res && isset($res['code']) && $res['code'] == 0) {
            $social_uid = $res['social_uid'];
            $nickname = $res['nickname'] ?? ('用户_' . rand(1000, 9999));
            $avatar = $res['faceimg'] ?? ('https://api.dicebear.com/7.x/avataaars/svg?seed=' . urlencode($nickname));
        } else {
            die("<h3 style='color:red;'>聚合授权失败</h3><p>" . ($res['msg'] ?? '获取用户信息失败') . "</p><a href='../index.php'>返回首页</a>");
        }
    } 
    // 【通道 B：官方直连登录回调】
    else if ($login_mode === 'official') {
        if ($type === 'douyin') {
            $client_key = getSocialConf('official_dy_clientkey');
            $client_secret = getSocialConf('official_dy_clientsecret');
            
            // 1. 获取 Access Token
            $token_url = "https://open.douyin.com/oauth/access_token/?client_key={$client_key}&client_secret={$client_secret}&code={$code}&grant_type=authorization_code";
            $token_res = json_decode(curl_get($token_url), true);
            
            if (isset($token_res['data']['error_code']) && $token_res['data']['error_code'] == 0) {
                $access_token = $token_res['data']['access_token'];
                $open_id = $token_res['data']['open_id'];
                
                // 2. 获取抖音用户信息
                $info_url = "https://open.douyin.com/oauth/userinfo/?access_token={$access_token}&open_id={$open_id}";
                $info_res = json_decode(curl_get($info_url), true);
                
                if (isset($info_res['data']['error_code']) && $info_res['data']['error_code'] == 0) {
                    $social_uid = $open_id; // 官方以 open_id 为唯一标识
                    $nickname = $info_res['data']['nickname'] ?? ('抖音用户_' . rand(1000, 9999));
                    $avatar = $info_res['data']['avatar'] ?? ('https://api.dicebear.com/7.x/avataaars/svg?seed=' . urlencode($nickname));
                } else {
                    die("官方抖音接口获取用户信息失败: " . ($info_res['data']['description'] ?? ''));
                }
            } else {
                die("官方抖音接口获取 Token 失败: " . ($token_res['data']['description'] ?? ''));
            }
        }
        else {
            die("暂未实现该官方通道的回调解析");
        }
    }

    // ==========================================
    // 阶段三：账号落地与系统登录 (数据库操作 + 积分派发)
    // ==========================================
    if (!empty($social_uid)) {
        $uid_field = $type . '_uid'; 

        $stmt = $pdo->prepare("SELECT * FROM users WHERE {$uid_field} = ? LIMIT 1");
        $stmt->execute([$social_uid]);
        $user = $stmt->fetch();

        if ($user) {
            // =========================
            // 老用户授权登录
            // =========================
            if ($user['is_banned']) die('<h3>您的账号已被封禁。</h3>');
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['nickname'] = $user['nickname'];
            $_SESSION['avatar'] = $user['avatar'];
            
            // --- 新增：每日登录积分奖励 (第三方老用户) ---
            $stmt_conf = $pdo->query("SELECT value FROM settings WHERE key_name = 'points_login'");
            $val = $stmt_conf ? $stmt_conf->fetchColumn() : false;
            $points_login = ($val !== false && $val !== '') ? intval($val) : 0;
            
            if ($points_login > 0) {
                $stmt_check = $pdo->prepare("SELECT id FROM points_log WHERE user_id = ? AND action = 'daily_login' AND DATE(created_at) = CURDATE()");
                $stmt_check->execute([$user['id']]);
                if (!$stmt_check->fetch()) {
                    $pdo->prepare("UPDATE users SET points = points + ? WHERE id = ?")->execute([$points_login, $user['id']]);
                    $pdo->prepare("INSERT INTO points_log (user_id, action, points_change, description) VALUES (?, 'daily_login', ?, '每日首次登录奖励')")->execute([$user['id'], $points_login]);
                }
            }
            
        } else {
            // =========================
            // 新用户授权注册
            // =========================
            $username = $type . '_' . substr(md5($social_uid), 0, 8);
            $email = $username . '@social.local';
            $password = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);

            $insertStmt = $pdo->prepare("INSERT INTO users (username, email, nickname, password, avatar, {$uid_field}, points, level) VALUES (?, ?, ?, ?, ?, ?, 0, 1)");
            $insertStmt->execute([$username, $email, $nickname, $password, $avatar, $social_uid]);

            $newUserId = $pdo->lastInsertId();
            $_SESSION['user_id'] = $newUserId;
            $_SESSION['username'] = $username;
            $_SESSION['nickname'] = $nickname;
            $_SESSION['avatar'] = $avatar;
            
            // --- 新增：新用户注册积分奖励 (第三方新用户) ---
            $stmt_conf = $pdo->query("SELECT value FROM settings WHERE key_name = 'points_register'");
            $val = $stmt_conf ? $stmt_conf->fetchColumn() : false;
            $points_register = ($val !== false && $val !== '') ? intval($val) : 0;
            
            if ($points_register > 0) {
                $pdo->prepare("UPDATE users SET points = points + ? WHERE id = ?")->execute([$points_register, $newUserId]);
                $pdo->prepare("INSERT INTO points_log (user_id, action, points_change, description) VALUES (?, 'register', ?, '新用户注册奖励')")->execute([$newUserId, $points_register]);
            }
        }

        // 强制闭合并保存 Session (防 Redis 延迟)
        session_write_close();

        // 成功，跳转回用户中心，后续可以再跳回首页或者留在 dashboard 都可以
        header("Location: ../user/dashboard.php");
        exit;
    }

} else {
    die('未知的操作');
}
?>