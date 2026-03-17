<?php
/**
 * user/login.php - 用户认证中心 (完善积分版)
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header("Location: ../index.php"); exit; }

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
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['nickname'] = $user['nickname'];
        $_SESSION['avatar'] = $user['avatar'];
        
        $msg = "登录成功！";
        // 每日登录积分奖励
        $stmt_conf = $pdo->query("SELECT value FROM settings WHERE key_name = 'points_login'");
        $val = $stmt_conf ? $stmt_conf->fetchColumn() : false;
        $points_login = ($val !== false && $val !== '') ? intval($val) : 0;
        
        if ($points_login > 0) {
            $stmt_check = $pdo->prepare("SELECT id FROM points_log WHERE user_id = ? AND action = 'daily_login' AND DATE(created_at) = CURDATE()");
            $stmt_check->execute([$user['id']]);
            if (!$stmt_check->fetch()) {
                $pdo->prepare("UPDATE users SET points = points + ? WHERE id = ?")->execute([$points_login, $user['id']]);
                $pdo->prepare("INSERT INTO points_log (user_id, action, points_change, description) VALUES (?, 'daily_login', ?, '每日首次登录奖励')")->execute([$user['id'], $points_login]);
                $msg = "登录成功！今日首次登录积分 +" . $points_login;
            }
        }
        
        jsonOut(true, $msg, "index.php");
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
        
        $msg = "欢迎加入！注册并登录成功";
        // 新用户注册积分奖励
        $stmt_conf = $pdo->query("SELECT value FROM settings WHERE key_name = 'points_register'");
        $val = $stmt_conf ? $stmt_conf->fetchColumn() : false;
        $points_register = ($val !== false && $val !== '') ? intval($val) : 0;
        
        if ($points_register > 0) {
            $pdo->prepare("UPDATE users SET points = points + ? WHERE id = ?")->execute([$points_register, $new_id]);
            $pdo->prepare("INSERT INTO points_log (user_id, action, points_change, description) VALUES (?, 'register', ?, '新用户注册奖励')")->execute([$new_id, $points_register]);
            $msg = "注册成功！新人奖励积分 +" . $points_register;
        }

        unset($_SESSION['email_verify_code']);
        unset($_SESSION['email_verify_addr']);
        
        jsonOut(true, $msg, "index.php");
    } else {
        jsonOut(false, "注册失败，请稍后重试");
    }
}

// --- 3. 找回密码 ---
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