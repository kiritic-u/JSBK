<?php
/**
 * user/login.php - 用户认证中心 (修复版)
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

// 确保 Session 正常开启
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// [核心修复1] 防止直接在浏览器访问变成白板，强制跳回首页
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../index.php");
    exit;
}

$pdo = getDB();

function jsonOut($success, $msg, $redirect = '') {
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'msg' => $msg, 'redirect' => $redirect]);
    exit;
}

$action = $_POST['action'] ?? '';

// --- 1. 登录 ---
if ($action == 'login') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $captcha = trim($_POST['captcha']);
    
    // 验证码校验
    if (empty($_SESSION['captcha_code']) || strtolower($captcha) !== strtolower($_SESSION['captcha_code'])) {
        unset($_SESSION['captcha_code']); 
        jsonOut(false, "图形验证码错误");
    }
    unset($_SESSION['captcha_code']); 

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        if ($user['is_banned']) jsonOut(false, "账号已被封禁，请联系管理员");
        
        // [核心修复2] 删除了导致部分服务器登录状态丢失的 session_regenerate_id 语句
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['nickname'] = $user['nickname'];
        $_SESSION['avatar'] = $user['avatar'];
        
        jsonOut(true, "登录成功", "index.php");
    } else {
        jsonOut(false, "账号或密码错误");
    }
}

// --- 2. 注册 (自动登录版) ---
if ($action == 'register') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $email = trim($_POST['email']);
    $email_code = trim($_POST['email_code']);

    if (empty($_SESSION['email_verify_code']) || $email_code != $_SESSION['email_verify_code'] || $email != $_SESSION['email_verify_addr']) {
        jsonOut(false, "邮件验证码错误或失效");
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) jsonOut(false, "用户名已存在");

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $avatar = 'https://api.dicebear.com/7.x/avataaars/svg?seed=' . urlencode($username);
    
    $stmt = $pdo->prepare("INSERT INTO users (username, password, nickname, avatar, email, points, level) VALUES (?, ?, ?, ?, ?, 0, 1)");
    if ($stmt->execute([$username, $hash, $username, $avatar, $email])) {
        
        $new_id = $pdo->lastInsertId();
        $_SESSION['user_id'] = $new_id;
        $_SESSION['username'] = $username;
        $_SESSION['nickname'] = $username;
        $_SESSION['avatar'] = $avatar;
        
        unset($_SESSION['email_verify_code']);
        unset($_SESSION['email_verify_addr']);
        
        jsonOut(true, "欢迎加入！注册并登录成功", "index.php");
    } else {
        jsonOut(false, "注册失败，请稍后重试");
    }
}

// --- 3. 找回密码 (重置即登录版) ---
if ($action == 'reset_password') {
    $email = trim($_POST['email']);
    $new_pwd = $_POST['new_password'];
    $code = trim($_POST['email_code']);

    if (empty($_SESSION['reset_email_code']) || $code != $_SESSION['reset_email_code'] || $email != $_SESSION['reset_email_addr']) {
        jsonOut(false, "验证码错误或已过期");
    }

    $hash = password_hash($new_pwd, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
    if ($stmt->execute([$hash, $email])) {
        
        $u_stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $u_stmt->execute([$email]);
        $user = $u_stmt->fetch();

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['nickname'] = $user['nickname'];
        $_SESSION['avatar'] = $user['avatar'];
        
        unset($_SESSION['reset_email_code']);
        unset($_SESSION['reset_email_addr']);
        
        jsonOut(true, "新密码设置成功，已为您自动登录", "index.php");
    } else {
        jsonOut(false, "重置失败");
    }
}
?>