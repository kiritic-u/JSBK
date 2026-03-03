<?php
/**
 * 核心驱动引擎 (Single Entry Point)
 */

// =========================================================================
// 1. 系统核心安全常量：用于防止直接越权访问物理 PHP 文件
// =========================================================================
define('IN_BKCS', true);

// =========================================================================
// 2. 环境健康检查
// =========================================================================
if (!file_exists('includes/config.php')) {
    if (file_exists('install/index.php')) {
        header('Location: install/index.php');
        exit;
    } else {
        die('系统未安装，且找不到安装程序 (install/index.php)。请上传安装包。');
    }
}

if (!is_dir(__DIR__ . '/pages')) {
    die("<h1>严重错误：找不到 pages 目录</h1>");
}

// =========================================================================
// 3. 核心组件加载 (⚠️ 注意：顺序极其重要！)
// =========================================================================
// 第一步：先加载 Config，建立数据库连接并启动 Session (让系统认出谁是管理员)
require_once 'includes/config.php';

// 第二步：加载安全响应头
require_once 'includes/security_headers.php';

// 第三步：加载 WAF 防火墙 (此时 WAF 可以通过 Session 识别出管理员并放行操作)
require_once 'includes/waf.php';

// =========================================================================
// 4. 现代化路由分发引擎 (Router)
// =========================================================================
$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// 静态核心路由映射表 (性能最高，且支持多别名指向同一文件)
$routes = [
    '/'            => 'pages/home.php',
    '/index.php'   => 'pages/home.php',
    '/home'        => 'pages/home.php',
    '/album'       => 'pages/album.php',
    '/chat'        => 'pages/chat.php',
    '/admin-login' => 'pages/admin_login.php',
    '/logout'      => 'pages/logout.php',
    '/music'       => 'pages/music.php',
    '/love'        => 'pages/love.php',
    '/about'       => 'pages/about.php',
    '/friends'     => 'pages/friends.php'
];

// 执行分发
if (array_key_exists($request_uri, $routes)) {
    // 命中静态路由
    $file = $routes[$request_uri];
    if (file_exists($file)) {
        require $file;
    } else {
        die("<h1>核心路由错误</h1><p>页面文件 '$file' 丢失。</p>");
    }
} else {
    // 动态路由嗅探：如果路由表里没有，尝试去 pages 目录下找同名文件
    // 例如访问 /article，自动寻找 pages/article.php
    $dynamic_file = __DIR__ . '/pages' . $request_uri . '.php';
    if (trim($request_uri, '/') !== '' && file_exists($dynamic_file)) {
        require $dynamic_file;
    } else {
        // 彻底找不到，优雅地抛出 404
        http_response_code(404);
        echo '<div style="text-align:center;padding:100px;font-family:sans-serif;">
                <h1 style="font-size:80px;margin-bottom:10px;">404</h1>
                <p style="color:#666;">你访问的页面路径不存在: ' . htmlspecialchars($request_uri) . '</p>
                <a href="/" style="display:inline-block;margin-top:20px;padding:10px 20px;background:#000;color:#fff;text-decoration:none;border-radius:8px;">返回首页</a>
              </div>';
    }
}
?>