<?php
/**
 * user/dashboard.php - 现代化个人中心 (居中卡片悬浮风格)
 */
ini_set('display_errors', 0); // 生产环境关闭错误显示防破坏 JSON
error_reporting(0);

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once '../includes/config.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['user_id'])) { header("Location: ../index.php"); exit; }

$pdo = getDB();
$user_id = $_SESSION['user_id'];
$msg = '';
$msg_type = '';

// --- 加载配置 ---
$stmt_set = $pdo->query("SELECT * FROM settings");
$site_config = [];
while ($row = $stmt_set->fetch()) { $site_config[$row['key_name']] = $row['value']; }
if (!function_exists('conf')) {
    function conf($key, $default = '') {
        global $site_config;
        return isset($site_config[$key]) && $site_config[$key] !== '' ? $site_config[$key] : $default;
    }
}

// 拦截充值成功回调
if (isset($_GET['recharge_success']) && $_GET['recharge_success'] == '1') {
    $msg = "🎉 支付成功！积分已充值到您的账户。";
    $msg_type = 'success';
}

// 处理资料更新
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nickname = trim($_POST['nickname']);
    $password = $_POST['password'];
    $avatar = trim($_POST['avatar']); 

    if (empty($nickname)) {
        $msg = "昵称不能为空"; $msg_type = 'error';
    } else {
        try {
            if (!empty($password)) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET nickname = ?, avatar = ?, password = ? WHERE id = ?");
                $stmt->execute([$nickname, $avatar, $hash, $user_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET nickname = ?, avatar = ? WHERE id = ?");
                $stmt->execute([$nickname, $avatar, $user_id]);
            }
            $_SESSION['nickname'] = $nickname; $_SESSION['avatar'] = $avatar;
            $msg = "个人资料已更新 ✨"; $msg_type = 'success';
        } catch (Exception $e) {
            $msg = "保存失败：" . $e->getMessage(); $msg_type = 'error';
        }
    }
}

// 获取用户数据及等级计算
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if (!$user) { session_destroy(); header("Location: login.php"); exit; }
    
    $current_points = max(0, intval($user['points']));
    $exchange_rate = intval(conf('points_exchange_rate', '100')); 
    
    $user_levels_json = conf('user_levels_config', '[]');
    $user_levels = json_decode($user_levels_json, true);
    if (empty($user_levels)) {
        $user_levels = [
            ['level' => 1, 'points' => 0, 'name' => '青铜会员'],
            ['level' => 2, 'points' => 100, 'name' => '白银会员'],
            ['level' => 3, 'points' => 500, 'name' => '黄金会员'],
            ['level' => 4, 'points' => 1500, 'name' => '钻石会员'],
            ['level' => 5, 'points' => 5000, 'name' => '星耀会员'],
        ];
    }
    
    usort($user_levels, function($a, $b) { return $a['points'] <=> $b['points']; });
    $current_level_data = $user_levels[0]; 
    $next_level_data = null;

    foreach ($user_levels as $idx => $lvl) {
        if ($current_points >= $lvl['points']) {
            $current_level_data = $lvl;
            $next_level_data = isset($user_levels[$idx + 1]) ? $user_levels[$idx + 1] : null;
        } else { break; }
    }

    $current_level_num = $current_level_data['level'];
    $current_level_name = $current_level_data['name'];

    if ($next_level_data) {
        $next_level_points = $next_level_data['points'];
        $points_needed = $next_level_points - $current_points;
        $level_base_points = $current_level_data['points']; 
        $gap = max(1, $next_level_points - $level_base_points);
        $progress_percent = min(100, max(0, (($current_points - $level_base_points) / $gap) * 100));
    } else {
        $next_level_points = $current_points; $points_needed = 0; $progress_percent = 100;
    }
} catch (Exception $e) {
    die("数据库错误: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>个人中心 | <?= htmlspecialchars(conf('site_name', 'BLOG.')) ?></title>
    <link href="https://cdn.bootcdn.net/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --page-bg: #f1f5f9;      /* 整个网页的底色 */
            --wrapper-bg: #ffffff;   /* 中间卡片的底色 */
            --sidebar-bg: #f8fafc;   /* 侧边栏底色 */
            --text-main: #0f172a;
            --text-sub: #64748b;
            --primary: #4f46e5;
            --border-color: #e2e8f0;
            --radius-lg: 24px;
            --radius-md: 16px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; outline: none; -webkit-tap-highlight-color: transparent; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", sans-serif;
            background: var(--page-bg);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        /* ====== 核心容器：居中悬浮卡片 ====== */
        .dashboard-wrapper {
            width: 100%;
            max-width: 1050px;
            height: 85vh;
            min-height: 650px;
            background: var(--wrapper-bg);
            border-radius: var(--radius-lg);
            box-shadow: 0 20px 40px rgba(0,0,0,0.06), 0 1px 3px rgba(0,0,0,0.03);
            display: flex;
            overflow: hidden;
            animation: scaleIn 0.5s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes scaleIn { 
            from { opacity: 0; transform: scale(0.97) translateY(20px); } 
            to { opacity: 1; transform: scale(1) translateY(0); } 
        }

        /* ====== 侧边栏 ====== */
        .sidebar {
            width: 260px;
            background: var(--sidebar-bg);
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            padding: 35px 20px;
            z-index: 10;
        }

        .user-profile-mini {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            margin-bottom: 35px;
            padding-bottom: 25px;
            border-bottom: 1px dashed #cbd5e1;
        }

        .avatar-wrap {
            width: 86px; height: 86px; border-radius: 50%; padding: 3px;
            background: linear-gradient(135deg, #e0c3fc 0%, #8ec5fc 100%);
            margin-bottom: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .avatar-wrap img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; border: 3px solid #fff; }
        .user-name { font-size: 19px; font-weight: 800; color: var(--text-main); margin-bottom: 4px; }
        .user-id { font-size: 13px; color: var(--text-sub); }

        .nav-menu { display: flex; flex-direction: column; gap: 8px; flex: 1; }
        .nav-item {
            display: flex; align-items: center; gap: 12px; padding: 14px 16px;
            border-radius: 12px; color: var(--text-sub); font-size: 15px; font-weight: 600;
            cursor: pointer; transition: all 0.2s; border: 1px solid transparent;
        }
        .nav-item i { width: 22px; text-align: center; font-size: 16px; }
        .nav-item:hover { background: #f1f5f9; color: var(--text-main); }
        .nav-item.active { background: #e0e7ff; color: var(--primary); }

        .bottom-actions { margin-top: auto; display: flex; flex-direction: column; gap: 8px; }
        .action-link {
            display: flex; align-items: center; gap: 12px; padding: 12px 16px;
            border-radius: 12px; color: var(--text-sub); font-size: 14px; font-weight: 600;
            text-decoration: none; transition: 0.2s;
        }
        .action-link:hover { background: #f1f5f9; color: var(--text-main); }
        .action-link.logout { color: #ef4444; }
        .action-link.logout:hover { background: #fef2f2; }

        /* ====== 主内容区 ====== */
        .main-content {
            flex: 1;
            padding: 40px 50px;
            overflow-y: auto;
            position: relative;
            background: #ffffff;
            scrollbar-width: thin;
        }
        .main-content::-webkit-scrollbar { width: 6px; }
        .main-content::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }

        .content-wrapper { max-width: 700px; margin: 0 auto; }

        .tab-pane { display: none; animation: fadeIn 0.4s ease; }
        .tab-pane.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .page-title { font-size: 24px; font-weight: 800; margin-bottom: 30px; display: flex; align-items: center; gap: 10px; color: #1e293b; }

        /* 状态总览卡片 */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 30px;}
        .stat-box { padding: 24px; background: #f8fafc; border-radius: var(--radius-md); border: 1px solid var(--border-color); display: flex; flex-direction: column; justify-content: center;}
        .stat-box-title { font-size: 14px; color: var(--text-sub); font-weight: 600; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
        .stat-box-value { font-size: 32px; font-weight: 800; color: var(--text-main); display: flex; align-items: center; }
        .level-tag { font-size: 12px; background: linear-gradient(135deg, #f6d365 0%, #fda085 100%); color: #fff; padding: 4px 10px; border-radius: 12px; margin-left: 12px; vertical-align: middle; }

        /* 进度条 */
        .progress-box { background: #f8fafc; padding: 24px; border-radius: var(--radius-md); border: 1px solid var(--border-color); }
        .progress-info { display: flex; justify-content: space-between; font-size: 13px; color: var(--text-sub); margin-bottom: 12px; font-weight: 600; }
        .progress-track { height: 10px; background: #e2e8f0; border-radius: 5px; overflow: hidden; }
        .progress-fill { height: 100%; background: linear-gradient(90deg, var(--primary), #818cf8); border-radius: 5px; transition: width 1s ease; }

        /* 表单与充值样式 */
        .card-panel { background: #f8fafc; padding: 30px; border-radius: var(--radius-md); border: 1px solid var(--border-color); margin-bottom: 30px; }
        .form-group { margin-bottom: 24px; }
        .form-label { display: block; font-size: 14px; font-weight: 600; color: var(--text-main); margin-bottom: 10px; }
        .form-input { width: 100%; padding: 14px 16px; background: #ffffff; border: 1px solid var(--border-color); border-radius: 12px; font-size: 15px; color: var(--text-main); transition: 0.2s; outline: none; }
        .form-input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1); }

        .btn-primary { background: var(--text-main); color: #fff; border: none; padding: 14px 24px; border-radius: 12px; font-size: 15px; font-weight: 600; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; gap: 8px; width: 100%; }
        .btn-primary:hover { background: #334155; transform: translateY(-2px); box-shadow: 0 6px 15px rgba(0,0,0,0.1); }

        /* 支付选项美化 (块状选择) */
        .pay-options { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .pay-radio { display: none; }
        .pay-card {
            border: 2px solid var(--border-color); border-radius: 12px; padding: 18px;
            display: flex; align-items: center; justify-content: center; gap: 10px;
            cursor: pointer; font-weight: 600; font-size: 16px; transition: 0.2s; background: #fff;
        }
        .pay-radio:checked + .pay-card { border-color: var(--primary); background: #eef2ff; color: var(--primary); }
        .pay-card:hover { border-color: #cbd5e1; }

        /* 消息提示 */
        .msg-box { padding: 16px 20px; border-radius: 12px; font-weight: 600; font-size: 14px; margin-bottom: 24px; display: flex; align-items: center; gap: 10px; }
        .msg-success { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
        .msg-error { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }

        .avatar-presets { display: flex; gap: 10px; margin-top: 10px; flex-wrap: wrap; }
        .preset-img { width: 44px; height: 44px; border-radius: 50%; cursor: pointer; border: 2px solid transparent; transition: 0.2s; opacity: 0.7; background: #fff; }
        .preset-img:hover, .preset-img.selected { opacity: 1; transform: scale(1.1); border-color: var(--primary); }

        /* ====== 响应式适配 ====== */
        @media (max-width: 850px) {
            body { padding: 0; }
            .dashboard-wrapper { height: 100vh; min-height: 100vh; border-radius: 0; flex-direction: column; box-shadow: none; }
            
            /* 手机端顶部滑动导航 */
            .sidebar { 
                width: 100%; border-right: none; border-bottom: 1px solid var(--border-color); 
                padding: 15px 20px; flex-direction: row; align-items: center; 
                position: sticky; top: 0; z-index: 100;
            }
            .user-profile-mini { flex-direction: row; margin: 0; padding: 0; border: none; text-align: left; gap: 12px; flex-shrink: 0; margin-right: 20px; }
            .avatar-wrap { width: 44px; height: 44px; margin: 0; padding: 2px; }
            .user-name { font-size: 16px; margin: 0; }
            .user-id { display: none; }
            
            .nav-menu { flex-direction: row; overflow-x: auto; white-space: nowrap; padding-bottom: 2px; scrollbar-width: none; }
            .nav-menu::-webkit-scrollbar { display: none; }
            .nav-item { padding: 8px 12px; font-size: 14px; }
            
            .bottom-actions { display: none; } 
            
            .main-content { padding: 25px 20px; overflow-y: auto; }
            .stats-grid { grid-template-columns: 1fr; }
            .pay-options { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <div class="dashboard-wrapper">
        <aside class="sidebar">
            <div class="user-profile-mini">
                <div class="avatar-wrap"><img src="<?= htmlspecialchars($user['avatar']) ?>" id="sideAvatar"></div>
                <div>
                    <div class="user-name"><?= htmlspecialchars($user['nickname'] ?: $user['username']) ?></div>
                    <div class="user-id">@<?= htmlspecialchars($user['username']) ?></div>
                </div>
            </div>

            <nav class="nav-menu">
                <div class="nav-item active" onclick="switchTab('overview', this)"><i class="fa-solid fa-chart-pie"></i> 状态总览</div>
                <div class="nav-item" onclick="switchTab('recharge', this)"><i class="fa-solid fa-wallet"></i> 积分充值</div>
                <div class="nav-item" onclick="switchTab('profile', this)"><i class="fa-solid fa-user-pen"></i> 个人资料</div>
            </nav>

            <div class="bottom-actions">
                <a href="../index.php" class="action-link"><i class="fa-solid fa-house"></i> 返回首页</a>
                <a href="logout.php" class="action-link logout"><i class="fa-solid fa-right-from-bracket"></i> 退出登录</a>
            </div>
        </aside>

        <main class="main-content">
            <div class="content-wrapper">
                
                <?php if($msg): ?>
                    <div class="msg-box <?= $msg_type == 'success' ? 'msg-success' : 'msg-error' ?>">
                        <i class="<?= $msg_type == 'success' ? 'fa-solid fa-check-circle' : 'fa-solid fa-circle-exclamation' ?>"></i>
                        <?= $msg ?>
                    </div>
                <?php endif; ?>

                <div id="tab-overview" class="tab-pane active">
                    <h2 class="page-title">👋 欢迎回来，<?= htmlspecialchars($user['nickname'] ?: $user['username']) ?></h2>
                    
                    <div class="stats-grid">
                        <div class="stat-box">
                            <div class="stat-box-title"><i class="fa-solid fa-coins" style="color:#f59e0b;"></i> 当前积分</div>
                            <div class="stat-box-value"><?= $current_points ?></div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-box-title"><i class="fa-solid fa-crown" style="color:#8b5cf6;"></i> 会员等级</div>
                            <div class="stat-box-value">
                                Lv.<?= $current_level_num ?>
                                <span class="level-tag"><?= htmlspecialchars($current_level_name) ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="progress-box">
                        <div class="progress-info">
                            <?php if ($next_level_data): ?>
                                <span>距 [<?= htmlspecialchars($next_level_data['name']) ?>] 还需要 <?= $points_needed ?> 积分</span>
                                <span><?= $current_points ?> / <?= $next_level_points ?></span>
                            <?php else: ?>
                                <span>已达到最高等级</span>
                                <span><?= $current_points ?> / MAX</span>
                            <?php endif; ?>
                        </div>
                        <div class="progress-track">
                            <div class="progress-fill" style="width: <?= $progress_percent ?>%;"></div>
                        </div>
                    </div>

                    <div style="margin-top: 30px; text-align: center; padding: 40px; color: var(--text-sub); border: 2px dashed var(--border-color); border-radius: var(--radius-md);">
                        <i class="fa-solid fa-seedling" style="font-size: 30px; margin-bottom: 10px; color:#cbd5e1;"></i>
                        <p>更多功能模块开发中...</p>
                    </div>
                </div>

                <div id="tab-recharge" class="tab-pane">
                    <h2 class="page-title">积分充值</h2>
                    <div class="card-panel">
                        <form action="../api/pay.php?action=submit" method="POST" target="_blank">
                            <div class="form-group">
                                <label class="form-label">充值金额 (人民币)</label>
                                <div style="position:relative;">
                                    <span style="position:absolute; left:16px; top:50%; transform:translateY(-50%); font-weight:bold; color:var(--text-sub);">¥</span>
                                    <input type="number" id="rechargeAmount" name="amount" class="form-input" min="1" step="1" value="10" style="padding-left:35px; font-size:20px; font-weight:bold;" required>
                                </div>
                            </div>

                            <div style="background: #fffbeb; color: #b45309; padding: 16px; border-radius: 12px; margin-bottom: 24px; font-size: 14px; border: 1px solid #fef3c7;">
                                <i class="fa-solid fa-circle-info"></i> 当前兑换比例：1元 = <?= $exchange_rate ?> 积分。本次将获得 <strong id="calcPoints" style="font-size:18px;"><?= 10 * $exchange_rate ?></strong> 积分。
                            </div>

                            <div class="form-group">
                                <label class="form-label">选择支付方式</label>
                                <div class="pay-options" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
                                    <label>
                                        <input type="radio" name="pay_type" value="alipay" class="pay-radio" checked>
                                        <div class="pay-card"><i class="fab fa-alipay" style="color:#00a1d6; font-size:24px;"></i> 网页/手机支付</div>
                                    </label>
                                    <label>
                                        <input type="radio" name="pay_type" value="alipay_f2f" class="pay-radio">
                                        <div class="pay-card"><i class="fa-solid fa-qrcode" style="color:#00a1d6; font-size:24px;"></i> 支付宝当面付</div>
                                    </label>
                                    <label>
                                        <input type="radio" name="pay_type" value="wxpay" class="pay-radio">
                                        <div class="pay-card"><i class="fab fa-weixin" style="color:#07c160; font-size:24px;"></i> 微信支付</div>
                                    </label>
                                </div>
                            </div>

                            <button type="submit" class="btn-primary">
                                <i class="fa-solid fa-bolt"></i> 前往收银台
                            </button>
                        </form>
                    </div>
                </div>

                <div id="tab-profile" class="tab-pane">
                    <h2 class="page-title">资料设置</h2>
                    <div class="card-panel">
                        <form method="POST">
                            <div class="form-group">
                                <label class="form-label">当前头像</label>
                                <div style="display:flex; align-items:flex-end; gap:20px;">
                                    <img src="<?= htmlspecialchars($user['avatar']) ?>" id="previewAvatar" style="width:80px; height:80px; border-radius:50%; object-fit:cover; border:2px solid #fff; box-shadow:0 2px 10px rgba(0,0,0,0.1);">
                                    <div style="flex:1;">
                                        <input type="text" name="avatar" id="avatarInput" class="form-input" value="<?= htmlspecialchars($user['avatar']) ?>" placeholder="输入图片 URL" required>
                                    </div>
                                </div>
                                <div style="margin-top:16px; font-size:13px; color:var(--text-sub);">快捷选择随机头像：</div>
                                <div class="avatar-presets">
                                    <?php 
                                    $seeds = ['Felix', 'Aneka', 'Zoe', 'Jack', 'Sam', 'Milo', 'Luna', 'Leo'];
                                    foreach($seeds as $seed): 
                                        $url = "https://api.dicebear.com/7.x/avataaars/svg?seed=$seed";
                                    ?>
                                        <img src="<?= $url ?>" class="preset-img" onclick="selectAvatar('<?= $url ?>', this)">
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">昵称</label>
                                <input type="text" name="nickname" class="form-input" value="<?= htmlspecialchars($user['nickname']) ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">登录邮箱 <span style="font-size:12px; color:#94a3b8; font-weight:normal;">(不可修改)</span></label>
                                <input type="text" class="form-input" value="<?= htmlspecialchars($user['email']) ?>" disabled style="background:#f1f5f9; color:#94a3b8; cursor:not-allowed;">
                            </div>

                            <div class="form-group" style="padding-top:20px; border-top:1px dashed var(--border-color);">
                                <label class="form-label">修改密码 <span style="font-size:12px; color:#94a3b8; font-weight:normal;">(不修改请留空)</span></label>
                                <input type="password" name="password" class="form-input" placeholder="输入新密码">
                            </div>

                            <button type="submit" class="btn-primary"><i class="fa-solid fa-floppy-disk"></i> 保存更改</button>
                        </form>
                    </div>
                    
                    <div style="display: none;" id="mobileLogoutBtn">
                        <a href="logout.php" class="btn-primary" style="background:#fef2f2; color:#ef4444; margin-top: 10px;"><i class="fa-solid fa-right-from-bracket"></i> 退出当前账号</a>
                    </div>
                </div>

            </div>
        </main>
    </div>

    <script>
        // Tab 切换逻辑
        function switchTab(tabId, el) {
            document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.remove('active'));
            document.querySelectorAll('.nav-item').forEach(nav => nav.classList.remove('active'));
            
            document.getElementById('tab-' + tabId).classList.add('active');
            if(el) el.classList.add('active');
        }

        // 头像选择逻辑
        function selectAvatar(url, el) {
            document.getElementById('avatarInput').value = url;
            document.getElementById('previewAvatar').src = url;
            document.getElementById('sideAvatar').src = url;
            
            document.querySelectorAll('.preset-img').forEach(img => img.classList.remove('selected'));
            if(el) el.classList.add('selected');
        }

        document.getElementById('avatarInput').addEventListener('input', function(e) {
            if(e.target.value.length > 10) {
                document.getElementById('previewAvatar').src = e.target.value;
                document.getElementById('sideAvatar').src = e.target.value;
            }
        });
        
        // 动态计算积分逻辑
        const rate = <?= $exchange_rate ?>;
        const inputAmount = document.getElementById('rechargeAmount');
        const calcPoints = document.getElementById('calcPoints');
        if(inputAmount && calcPoints) {
            inputAmount.addEventListener('input', (e) => {
                const val = parseFloat(e.target.value) || 0;
                calcPoints.innerText = Math.floor(val * rate);
            });
        }

        // 移动端处理
        if(window.innerWidth <= 850) {
            document.getElementById('mobileLogoutBtn').style.display = 'flex';
        }

        // 清理 URL 参数，防止刷新重复弹窗
        const urlParams = new URLSearchParams(window.location.search);
        if(urlParams.has('recharge_success')) {
            switchTab('overview', document.querySelector('.nav-item')); // 默认回到总览
            urlParams.delete('recharge_success');
            const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
            window.history.replaceState({}, document.title, newUrl);
            
            setTimeout(() => {
                const msgBox = document.querySelector('.msg-box');
                if(msgBox) msgBox.style.display = 'none';
            }, 4000);
        } else {
            setTimeout(() => {
                const msgBox = document.querySelector('.msg-box');
                if(msgBox) msgBox.style.display = 'none';
            }, 4000);
        }
    </script>
</body>
</html>