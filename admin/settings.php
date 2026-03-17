<?php
// admin/settings.php
/**
                _ _                    ____  _                              
               | (_) __ _ _ __   __ _  / ___|| |__  _   _  ___              
            _  | | |/ _` | '_ \ / _` | \___ \| '_ \| | | |/ _ \             
           | |_| | | (_| | | | | (_| |  ___) | | | | |_| | (_) |            
            \___/|_|\__,_|_| |_|\__, | |____/|_| |_|\__,_|\___/             
   ____  _____          _  __  |___/  _____  _   _  _          ____ ____  
  / ___| |__  /         | | \ \/ / / | |___ /  / | | || |        / ___/ ___|
 | |  _    / /       _  | |  \  /  | |   |_ \  | | | || |_      | |  | |   
 | |_| |  / /_   _  | |_| |  /  \  | |  ___) | | | |__   _|  _  | |__| |___ 
  \____| /____| (_)  \___/  /_/\_\ |_| |____/  |_|    |_|   (_)  \____\____|
                                                                            
                                追求极致的美学                               
**/
require_once '../includes/config.php';
requireLogin(); 
$pdo = getDB();
$redis = getRedis(); 
$msg = '';

// 定义缓存前缀 (防止清空整个 Redis 实例)
if (!defined('CACHE_PREFIX')) define('CACHE_PREFIX', 'bkcs:');

// --- 1. AJAX 请求处理 (保存 & 清理缓存) ---
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_FILES['import_file'])) {
    
    // 1.1 处理清空缓存 AJAX 请求
    if (isset($_POST['action']) && $_POST['action'] === 'clear_cache') {
        header('Content-Type: application/json');
        if ($redis) {
            try {
                // 查找所有匹配前缀的 Key
                $keys = $redis->keys(CACHE_PREFIX . '*');
                $count = 0;
                if (!empty($keys)) {
                    foreach ($keys as $key) {
                        $redis->del($key);
                        $count++;
                    }
                }
                echo json_encode(['success' => true, 'message' => "成功清理了 {$count} 个缓存文件"]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => '清理失败: ' . $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Redis 未连接，无法清理']);
        }
        exit; // 结束执行
    }

    // 1.2 保存设置逻辑
    try {
        // 处理背景设置逻辑
        $bg_type = $_POST['site_bg_type'] ?? 'color';
        $final_bg_value = '';
        
        if ($bg_type === 'color') { 
            $final_bg_value = !empty($_POST['bg_color_text']) ? $_POST['bg_color_text'] : ($_POST['bg_color_picker'] ?? '#f3f4f6');
        } elseif ($bg_type === 'image') { 
            $final_bg_value = $_POST['site_bg_value_image'] ?? ''; 
        }

        $pdo->prepare("INSERT INTO settings (key_name, value) VALUES ('site_bg_value', ?) ON DUPLICATE KEY UPDATE value = ?")->execute([$final_bg_value, $final_bg_value]);
        
        // 处理常规字段
        $fields = [
            'site_name','author_name','author_avatar','author_bio',
            'social_github','social_twitter','social_email','wechat_qrcode',
            'hot_tags','site_keywords','site_description','site_icp','baidu_verify','google_verify',
            'music_api_url','music_playlist_id',
            'site_bg_type','site_bg_gradient_start','site_bg_gradient_end','site_bg_overlay_opacity',
            'home_slogan_main','home_slogan_sub',
            'home_btn1_text','home_btn1_link','home_btn2_text','home_btn2_link','home_btn3_text','home_btn3_link',
            'smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from_name',
            'cos_secret_id','cos_secret_key','cos_bucket','cos_region','cos_domain',
            'ai_api_url','ai_api_key','ai_model_name','custom_css','custom_js',
            'cos_enabled',
            
            // 社交登录相关配置
            'social_login_url', 'social_appid', 'social_appkey',
            // 积分任务配置
            'points_register', 'points_login', 'points_comment', 'points_like', 'points_share',
            // 官方通道 vs 聚合通道选择
            'social_login_mode', 
            // 官方通道的独立密钥配置
            'official_wx_appid', 'official_wx_appsecret',
            'official_qq_appid', 'official_qq_appkey',
            'official_dy_clientkey', 'official_dy_clientsecret',
            // 新增：社交登录相关配置
            'social_login_url', 'social_appid', 'social_appkey',
            // 新增：积分任务配置
            'points_register', 'points_login', 'points_comment', 'points_like', 'points_share',
            // 新增：支付与充值配置
            'points_exchange_rate', 'pay_channel', 'epay_url', 'epay_pid', 'epay_key',
            'alipay_appid', 'alipay_private_key', 'alipay_public_key', 
            'wxpay_appid', 'wxpay_mchid', 'wxpay_serial_no', 'wxpay_private_key', 'wxpay_key',
            // 官方通道 vs 聚合通道选择
            'social_login_mode',
        ];

        // 处理复选框
        $checkboxes = ['enable_chatroom','enable_friend_links','enable_hot_tags','chatroom_muted','enable_loading_anim','redis_enabled', 'enable_login_wx', 'enable_login_qq', 'enable_login_dy'];
        
        // =========================================================================
        // [动态修改 config.php 里的 REDIS_ENABLED 常量]
        // =========================================================================
        $config_path = '../includes/config.php';
        if (file_exists($config_path)) {
            if (!is_writable($config_path)) {
                throw new Exception("文件无写入权限，请将 includes/config.php 的权限设置为 755 或 777");
            }
            
            $config_content = file_get_contents($config_path);
            $new_redis_status = isset($_POST['redis_enabled']) ? 'true' : 'false';
            
            $config_content = preg_replace(
                "/define\(\s*['\"]REDIS_ENABLED['\"]\s*,\s*(true|false)\s*\);/i", 
                "define('REDIS_ENABLED', {$new_redis_status});", 
                $config_content
            );
            
            file_put_contents($config_path, $config_content);
        }
        // =========================================================================
        
        foreach (array_merge($fields, $checkboxes) as $key) {
            if (in_array($key, $checkboxes)) {
                $val = isset($_POST[$key]) ? '1' : '0';
            } else {
                $val = isset($_POST[$key]) ? trim($_POST[$key]) : '';
            }
            $pdo->prepare("INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?")->execute([$key, $val, $val]);
        }
        
        // 处理友情链接 (JSON)
        $fl_names = $_POST['fl_name'] ?? []; 
        $fl_urls = $_POST['fl_url'] ?? []; 
        $friend_links_data = [];
        for($i=0; $i<count($fl_names); $i++) {
            if(!empty($fl_names[$i]) && !empty($fl_urls[$i])) { 
                $friend_links_data[] = ['name' => $fl_names[$i], 'url' => $fl_urls[$i]]; 
            }
        }
        $json_fl = json_encode($friend_links_data, JSON_UNESCAPED_UNICODE);
        $pdo->prepare("INSERT INTO settings (key_name, value) VALUES ('friend_links', ?) ON DUPLICATE KEY UPDATE value = ?")->execute([$json_fl, $json_fl]);
        
        // --- 处理用户等级设置 (JSON) ---
        $lvl_nums = $_POST['level_num'] ?? [];
        $lvl_points = $_POST['level_points'] ?? [];
        $lvl_names = $_POST['level_name'] ?? [];
        $levels_data = [];
        for($i=0; $i<count($lvl_nums); $i++) {
            if(trim($lvl_nums[$i]) !== '') {
                $levels_data[] = [
                    'level' => intval($lvl_nums[$i]),
                    'points' => intval($lvl_points[$i]),
                    'name' => trim($lvl_names[$i])
                ];
            }
        }
        // 按等级数字升序排序，保证前台逻辑的连贯性
        usort($levels_data, function($a, $b) { return $a['level'] <=> $b['level']; });
        
        $json_levels = json_encode($levels_data, JSON_UNESCAPED_UNICODE);
        $pdo->prepare("INSERT INTO settings (key_name, value) VALUES ('user_levels_config', ?) ON DUPLICATE KEY UPDATE value = ?")->execute([$json_levels, $json_levels]);
        // ------------------------------------

        // 保存后顺便清除一次配置缓存
        if ($redis) $redis->del(CACHE_PREFIX . 'site_settings'); 
        
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => '设置已保存成功']);
            exit;
        }
        $msg = '<div class="alert alert-success">设置已保存成功</div>';

    } catch (Exception $e) { 
        if ($is_ajax) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => '保存失败: ' . $e->getMessage()]);
            exit;
        }
        $msg = '<div class="alert alert-error">保存失败: ' . $e->getMessage() . '</div>';
    }
}

// --- 2. 其他 Action 处理 (GET) ---
if (isset($_GET['action']) && $_GET['action'] === 'export_settings') {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="settings_backup_' . date('Ymd_His') . '.json"');
    $stmt = $pdo->query("SELECT * FROM settings");
    $export_data = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $export_data[$row['key_name']] = $row['value']; }
    echo json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['import_file'])) {
    try {
        $file = $_FILES['import_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception('文件上传失败');
        $import_data = json_decode(file_get_contents($file['tmp_name']), true);
        if (!is_array($import_data)) throw new Exception('JSON 解析失败');
        
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?");
        foreach ($import_data as $key => $val) {
            $db_val = is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE) : $val;
            $stmt->execute([$key, $db_val, $db_val]);
        }
        $pdo->commit();
        if ($redis) $redis->del(CACHE_PREFIX . 'site_settings');
        $msg = '<div class="alert alert-success">成功导入配置！</div>';
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = '<div class="alert alert-error">导入失败: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// --- 3. 读取配置 ---
$stmt = $pdo->query("SELECT * FROM settings");
$settings = [];
while ($row = $stmt->fetch()) { $settings[$row['key_name']] = $row['value']; }
function getVal($key, $default = '') { global $settings; return isset($settings[$key]) ? htmlspecialchars($settings[$key]) : $default; }

$friend_links = json_decode($settings['friend_links'] ?? '[]', true);

// 读取用户等级配置，如为空则给一套默认值
$user_levels = json_decode($settings['user_levels_config'] ?? '[]', true);
if (empty($user_levels)) {
    $user_levels = [
        ['level' => 1, 'points' => 0, 'name' => '青铜会员'],
        ['level' => 2, 'points' => 100, 'name' => '白银会员'],
        ['level' => 3, 'points' => 500, 'name' => '黄金会员'],
        ['level' => 4, 'points' => 1500, 'name' => '钻石会员'],
        ['level' => 5, 'points' => 5000, 'name' => '星耀会员'],
    ];
}

require 'header.php';
?>

<link rel="stylesheet" href="assets/css/settings.css?v=<?= time() ?>">

<div class="settings-wrapper">
    <div class="settings-header">
        <h1 class="page-title"><i class="fas fa-sliders-h"></i> 网站设置</h1>
        
        <div class="header-actions">
            <div style="display:flex; gap:8px;">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('importInput').click()"><i class="fas fa-upload"></i> 导入</button>
                <a href="?action=export_settings" class="btn btn-ghost"><i class="fas fa-download"></i> 备份</a>
            </div>
            <button type="button" class="btn btn-primary btn-save-desktop" onclick="saveSettings(this)">
                <i class="fas fa-save"></i> 保存设置
            </button>
        </div>
    </div>

    <div class="tabs-wrapper">
        <div class="tabs-header">
            <div class="tab-btn active" onclick="switchTab('basic')"><i class="fas fa-cog"></i> 基本</div>
            <div class="tab-btn" onclick="switchTab('home')"><i class="fas fa-home"></i> 首页</div>
            <div class="tab-btn" onclick="switchTab('style')"><i class="fas fa-palette"></i> 外观</div>
            <div class="tab-btn" onclick="switchTab('users')"><i class="fas fa-users-cog"></i> 用户</div>
            <div class="tab-btn" onclick="switchTab('modules')"><i class="fas fa-cubes"></i> 模块</div>
            <div class="tab-btn" onclick="switchTab('services')"><i class="fas fa-server"></i> 服务</div>
            <div class="tab-btn" onclick="switchTab('payment')"><i class="fas fa-wallet"></i> 支付</div>
            <div class="tab-btn" onclick="switchTab('custom')"><i class="fas fa-code"></i> 代码</div>
        </div>
    </div>

    <form id="importForm" method="POST" enctype="multipart/form-data" style="display:none">
        <input type="file" name="import_file" id="importInput" accept=".json" onchange="if(confirm('覆盖当前配置？')) this.form.submit(); else this.value='';">
    </form>

    <form id="settingsForm" method="POST">
        <?= $msg ?>
        
        <div id="basic" class="tab-content active">
            <div class="section-card">
                <h3 class="section-title">站点信息</h3>
                <p class="section-desc">配置网站的基础 SEO 信息。</p>
                <div class="grid-2">
                    <div class="form-group"><label class="form-label">网站标题</label><input type="text" name="site_name" class="form-control" value="<?= getVal('site_name') ?>" required></div>
                    <div class="form-group"><label class="form-label">ICP 备案号</label><input type="text" name="site_icp" class="form-control" value="<?= getVal('site_icp') ?>"></div>
                    <div class="form-group" style="grid-column: 1/-1"><label class="form-label">关键词 (Keywords)</label><input type="text" name="site_keywords" class="form-control" value="<?= getVal('site_keywords') ?>" placeholder="逗号分隔"></div>
                    <div class="form-group" style="grid-column: 1/-1"><label class="form-label">站点描述 (Description)</label><textarea name="site_description" class="form-control" rows="3"><?= getVal('site_description') ?></textarea></div>
                    <div class="form-group"><label class="form-label">百度验证</label><input type="text" name="baidu_verify" class="form-control" value="<?= getVal('baidu_verify') ?>"></div>
                    <div class="form-group"><label class="form-label">Google 验证</label><input type="text" name="google_verify" class="form-control" value="<?= getVal('google_verify') ?>"></div>
                </div>
            </div>
            
            <div class="section-card">
                <h3 class="section-title">作者信息</h3>
                <div class="grid-2">
                    <div class="form-group"><label class="form-label">昵称</label><input type="text" name="author_name" class="form-control" value="<?= getVal('author_name') ?>"></div>
                    <div class="form-group"><label class="form-label">头像 URL</label><input type="text" name="author_avatar" class="form-control" value="<?= getVal('author_avatar') ?>"></div>
                    <div class="form-group" style="grid-column: 1/-1"><label class="form-label">个人简介</label><textarea name="author_bio" class="form-control" rows="2"><?= getVal('author_bio') ?></textarea></div>
                    <div class="form-group"><label class="form-label">GitHub</label><input type="text" name="social_github" class="form-control" value="<?= getVal('social_github') ?>"></div>
                    <div class="form-group"><label class="form-label">Email</label><input type="text" name="social_email" class="form-control" value="<?= getVal('social_email') ?>"></div>
                    <div class="form-group"><label class="form-label">Twitter</label><input type="text" name="social_twitter" class="form-control" value="<?= getVal('social_twitter') ?>"></div>
                    <div class="form-group"><label class="form-label">微信二维码</label><input type="text" name="wechat_qrcode" class="form-control" value="<?= getVal('wechat_qrcode') ?>"></div>
                </div>
            </div>
        </div>

        <div id="home" class="tab-content">
            <div class="section-card">
                <h3 class="section-title">标语配置</h3>
                <div class="form-group"><label class="form-label">主标题 (Slogan)</label><input type="text" name="home_slogan_main" class="form-control" value="<?= getVal('home_slogan_main') ?>"></div>
                <div class="form-group"><label class="form-label">副标题</label><input type="text" name="home_slogan_sub" class="form-control" value="<?= getVal('home_slogan_sub') ?>"></div>
            </div>
            <div class="section-card">
                <h3 class="section-title">首页按钮</h3>
                <p class="section-desc">留空则不显示对应的按钮。</p>
                <?php for($i=1; $i<=3; $i++): ?>
                <div class="grid-2" style="margin-bottom:15px; border-bottom:1px dashed #eee; padding-bottom:15px;">
                    <div class="form-group"><label class="form-label">按钮 <?= $i ?> 文字</label><input type="text" name="home_btn<?=$i?>_text" class="form-control" value="<?= getVal('home_btn'.$i.'_text') ?>"></div>
                    <div class="form-group"><label class="form-label">按钮 <?= $i ?> 链接</label><input type="text" name="home_btn<?=$i?>_link" class="form-control" value="<?= getVal('home_btn'.$i.'_link') ?>"></div>
                </div>
                <?php endfor; ?>
                <div class="form-group"><label class="form-label">热门标签</label><input type="text" name="hot_tags" class="form-control" value="<?= getVal('hot_tags') ?>" placeholder="英文逗号分隔"></div>
            </div>
        </div>

        <div id="style" class="tab-content">
            <div class="section-card">
                <h3 class="section-title">背景设置</h3>
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">背景类型</label>
                        <select name="site_bg_type" id="bgType" class="form-control" onchange="toggleBgInputs()">
                            <option value="color" <?= getVal('site_bg_type')=='color'?'selected':'' ?>>纯色背景</option>
                            <option value="gradient" <?= getVal('site_bg_type')=='gradient'?'selected':'' ?>>CSS 渐变</option>
                            <option value="image" <?= getVal('site_bg_type')=='image'?'selected':'' ?>>背景图片</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">遮罩透明度 (0-1)</label>
                        <input type="number" step="0.1" max="1" min="0" name="site_bg_overlay_opacity" class="form-control" value="<?= getVal('site_bg_overlay_opacity') ?: '0.8' ?>">
                    </div>
                </div>

                <div id="bg-color" class="form-group bg-option">
                    <label class="form-label">选择颜色</label>
                    <div style="display:flex; gap:10px;">
                        <input type="color" id="bgPicker" class="form-control" style="width:50px; padding:2px; height:42px;" value="<?= getVal('site_bg_type')=='color'?getVal('site_bg_value'):'#f3f4f6' ?>">
                        <input type="text" name="bg_color_text" id="bgText" class="form-control" value="<?= getVal('site_bg_type')=='color'?getVal('site_bg_value'):'#f3f4f6' ?>">
                    </div>
                </div>
                <div id="bg-gradient" class="grid-2 bg-option" style="display:none;">
                    <div class="form-group"><label class="form-label">起始颜色</label><input type="color" name="site_bg_gradient_start" class="form-control" style="height:42px" value="<?= getVal('site_bg_gradient_start')?:'#a18cd1' ?>"></div>
                    <div class="form-group"><label class="form-label">结束颜色</label><input type="color" name="site_bg_gradient_end" class="form-control" style="height:42px" value="<?= getVal('site_bg_gradient_end')?:'#fbc2eb' ?>"></div>
                </div>
                <div id="bg-image" class="form-group bg-option" style="display:none;">
                    <label class="form-label">图片 URL</label>
                    <input type="text" name="site_bg_value_image" class="form-control" value="<?= getVal('site_bg_type')=='image'?getVal('site_bg_value'):'' ?>" placeholder="https://...">
                </div>
            </div>
            
            <div class="section-card">
                <h3 class="section-title">加载动画</h3>
                 <label class="switch-label">
                    <span style="font-size:14px; font-weight:500;">开启页面加载 Loading 动画</span>
                    <div class="switch"><input type="checkbox" name="enable_loading_anim" value="1" <?= getVal('enable_loading_anim')=='1'?'checked':'' ?>><span class="slider"></span></div>
                </label>
            </div>
        </div>

        <div id="users" class="tab-content">
            <div class="section-card">
                <h3 class="section-title">积分任务奖励</h3>
                <p class="section-desc">设置用户完成特定操作时可获得的积分奖励（填 0 或留空表示无奖励）。</p>
                <div class="grid-2">
                    <div class="form-group"><label class="form-label">新用户注册奖励</label><input type="number" name="points_register" class="form-control" value="<?= getVal('points_register', '0') ?>" placeholder="例如: 50"></div>
                    <div class="form-group"><label class="form-label">每日首次登录奖励</label><input type="number" name="points_login" class="form-control" value="<?= getVal('points_login', '10') ?>" placeholder="例如: 10"></div>
                    <div class="form-group"><label class="form-label">发布评论奖励</label><input type="number" name="points_comment" class="form-control" value="<?= getVal('points_comment', '0') ?>" placeholder="例如: 2"></div>
                    <div class="form-group"><label class="form-label">点赞文章奖励</label><input type="number" name="points_like" class="form-control" value="<?= getVal('points_like', '0') ?>" placeholder="例如: 1"></div>
                    <div class="form-group"><label class="form-label">分享/转发奖励</label><input type="number" name="points_share" class="form-control" value="<?= getVal('points_share', '0') ?>" placeholder="例如: 5"></div>
                </div>
            </div>

            <div class="section-card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <h3 class="section-title" style="margin:0;">用户等级与积分配置</h3>
                    <button type="button" class="btn btn-ghost" onclick="addLevel()" style="font-size:12px; padding:4px 10px;"><i class="fas fa-plus"></i> 添加等级</button>
                </div>
                <p class="section-desc">设定前台用户中心的等级阶梯。你可以配置升到该等级所需的总积分，以及前台显示的称号。请务必保留等级 1 作为初始默认等级。</p>
                <div id="levels-container">
                    <?php foreach($user_levels as $lvl): ?>
                    <div class="level-item" style="display:flex; gap:10px; margin-bottom:10px; align-items: center;">
                        <div class="form-group" style="margin-bottom:0; flex:1">
                            <div style="font-size: 12px; color: #64748b; margin-bottom: 4px;">等级 (数字)</div>
                            <input type="number" name="level_num[]" class="form-control" value="<?= htmlspecialchars($lvl['level']) ?>" placeholder="例: 1" required>
                        </div>
                        <div class="form-group" style="margin-bottom:0; flex:1">
                            <div style="font-size: 12px; color: #64748b; margin-bottom: 4px;">所需总积分</div>
                            <input type="number" name="level_points[]" class="form-control" value="<?= htmlspecialchars($lvl['points']) ?>" placeholder="例: 100" required>
                        </div>
                        <div class="form-group" style="margin-bottom:0; flex:2">
                            <div style="font-size: 12px; color: #64748b; margin-bottom: 4px;">等级头衔/昵称</div>
                            <input type="text" name="level_name[]" class="form-control" value="<?= htmlspecialchars($lvl['name']) ?>" placeholder="例: 白银会员" required>
                        </div>
                        <div style="padding-top: 20px;">
                            <button type="button" class="btn btn-danger-ghost" onclick="removeLevel(this)" style="padding:0 10px; height: 38px;"><i class="fas fa-times"></i></button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div id="modules" class="tab-content">
            <div class="section-card">
                <h3 class="section-title">功能开关</h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 15px;">
                    <label class="switch-label">
                        <span style="font-size:14px; font-weight:500;">开启聊天室</span>
                        <div class="switch"><input type="checkbox" name="enable_chatroom" value="1" <?= getVal('enable_chatroom')=='1'?'checked':'' ?>><span class="slider"></span></div>
                    </label>
                    <label class="switch-label">
                        <span style="font-size:14px; font-weight:500;">聊天室全体禁言</span>
                        <div class="switch"><input type="checkbox" name="chatroom_muted" value="1" <?= getVal('chatroom_muted')=='1'?'checked':'' ?>><span class="slider"></span></div>
                    </label>
                    <label class="switch-label">
                        <span style="font-size:14px; font-weight:500;">显示友情链接</span>
                        <div class="switch"><input type="checkbox" name="enable_friend_links" value="1" <?= getVal('enable_friend_links')=='1'?'checked':'' ?>><span class="slider"></span></div>
                    </label>
                     <label class="switch-label">
                        <span style="font-size:14px; font-weight:500;">热门标签模块</span>
                        <div class="switch"><input type="checkbox" name="enable_hot_tags" value="1" <?= getVal('enable_hot_tags')=='1'?'checked':'' ?>><span class="slider"></span></div>
                    </label>
                    <label class="switch-label">
                        <span style="font-size:14px; font-weight:500;">Redis 缓存</span>
                        <div class="switch"><input type="checkbox" name="redis_enabled" value="1" <?= getVal('redis_enabled')=='1'?'checked':'' ?>><span class="slider"></span></div>
                    </label>
                </div>

                <?php if(getVal('redis_enabled') == '1'): ?>
                    <div style="margin-top:20px; border-top:1px dashed #eee; padding-top:15px; display:flex; justify-content:space-between; align-items:center;">
                        <span style="font-size:13px; color:#666;"><i class="fas fa-info-circle"></i> 如果前台内容未更新，请尝试清理缓存</span>
                        <button type="button" class="btn btn-danger-ghost" onclick="clearCache(this)" style="font-size:13px; padding:6px 15px;">
                            <i class="fas fa-trash"></i> 立即清空缓存
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <div class="section-card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <h3 class="section-title" style="margin:0;">友情链接列表</h3>
                    <button type="button" class="btn btn-ghost" onclick="addLink()" style="font-size:12px; padding:4px 10px;"><i class="fas fa-plus"></i> 添加</button>
                </div>
                <div id="fl-container">
                    <?php foreach($friend_links as $link): ?>
                    <div class="fl-item" style="display:flex; gap:10px; margin-bottom:10px;">
                        <input type="text" name="fl_name[]" class="form-control" value="<?= htmlspecialchars($link['name']) ?>" placeholder="网站名称" style="flex:1">
                        <input type="text" name="fl_url[]" class="form-control" value="<?= htmlspecialchars($link['url']) ?>" placeholder="URL" style="flex:2">
                        <button type="button" class="btn btn-danger-ghost" onclick="removeLink(this)" style="padding:0 10px;"><i class="fas fa-times"></i></button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

       <div id="services" class="tab-content">
            
            <div class="section-card">
                <h3 class="section-title">快捷登录模式与开关</h3>
                <p class="section-desc">请选择通道模式，并按需开启或关闭指定的快捷登录平台。</p>
                
                <div class="form-group" style="margin-bottom: 25px;">
                    <label class="form-label">API 接口通道模式</label>
                    <select name="social_login_mode" id="socialLoginMode" class="form-control" onchange="toggleSocialLoginInputs()" style="border-color: #4f46e5; background: #f8fafc;">
                        <option value="aggregated" <?= getVal('social_login_mode', 'aggregated')=='aggregated'?'selected':'' ?>>👉 第三方聚合登录 (如彩虹聚合，适合个人)</option>
                        <option value="official" <?= getVal('social_login_mode')=='official'?'selected':'' ?>>👉 官方开放平台直连 (需企业认证资质)</option>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px;">
                    <label class="switch-label">
                        <span style="font-size:14px; font-weight:500;"><i class="fab fa-weixin" style="color:#07c160; margin-right:5px; font-size:16px;"></i>微信登录</span>
                        <div class="switch"><input type="checkbox" name="enable_login_wx" value="1" <?= getVal('enable_login_wx', '1')=='1'?'checked':'' ?>><span class="slider"></span></div>
                    </label>
                    <label class="switch-label">
                        <span style="font-size:14px; font-weight:500;"><i class="fab fa-qq" style="color:#00a1d6; margin-right:5px; font-size:16px;"></i>QQ登录</span>
                        <div class="switch"><input type="checkbox" name="enable_login_qq" value="1" <?= getVal('enable_login_qq', '1')=='1'?'checked':'' ?>><span class="slider"></span></div>
                    </label>
                    <label class="switch-label">
                        <span style="font-size:14px; font-weight:500;"><i class="fab fa-tiktok" style="color:#1c1c1e; margin-right:5px; font-size:16px;"></i>抖音登录</span>
                        <div class="switch"><input type="checkbox" name="enable_login_dy" value="1" <?= getVal('enable_login_dy', '1')=='1'?'checked':'' ?>><span class="slider"></span></div>
                    </label>
                </div>
            </div>

            <div id="social-config-aggregated" class="section-card social-config-block">
                <h3 class="section-title">彩虹聚合登录配置</h3>
                <div class="grid-2">
                    <div class="form-group" style="grid-column: 1/-1"><label class="form-label">聚合登录接口地址</label><input type="text" name="social_login_url" class="form-control" value="<?= getVal('social_login_url', 'https://u.cccyun.cc') ?>"></div>
                    <div class="form-group"><label class="form-label">AppID</label><input type="text" name="social_appid" class="form-control" value="<?= getVal('social_appid') ?>"></div>
                    <div class="form-group"><label class="form-label">AppKey</label><input type="password" name="social_appkey" class="form-control" value="<?= getVal('social_appkey') ?>"></div>
                </div>
            </div>

            <div id="social-config-official" class="section-card social-config-block" style="display:none;">
                <h3 class="section-title">官方通道直连配置</h3>
                <p class="section-desc" style="color:#ef4444;"><i class="fas fa-exclamation-circle"></i> 需分别前往各大官方开放平台申请应用。</p>
                
                <div class="grid-2" style="border-bottom: 1px dashed #e2e8f0; padding-bottom: 15px; margin-bottom: 15px;">
                    <h4 style="grid-column: 1/-1; margin: 0; font-size: 14px; color: #07c160;"><i class="fab fa-weixin"></i> 微信开放平台</h4>
                    <div class="form-group"><label class="form-label">AppID</label><input type="text" name="official_wx_appid" class="form-control" value="<?= getVal('official_wx_appid') ?>"></div>
                    <div class="form-group"><label class="form-label">AppSecret</label><input type="password" name="official_wx_appsecret" class="form-control" value="<?= getVal('official_wx_appsecret') ?>"></div>
                </div>
                
                <div class="grid-2" style="border-bottom: 1px dashed #e2e8f0; padding-bottom: 15px; margin-bottom: 15px;">
                    <h4 style="grid-column: 1/-1; margin: 0; font-size: 14px; color: #00a1d6;"><i class="fab fa-qq"></i> QQ 互联</h4>
                    <div class="form-group"><label class="form-label">App ID</label><input type="text" name="official_qq_appid" class="form-control" value="<?= getVal('official_qq_appid') ?>"></div>
                    <div class="form-group"><label class="form-label">App Key</label><input type="password" name="official_qq_appkey" class="form-control" value="<?= getVal('official_qq_appkey') ?>"></div>
                </div>

                <div class="grid-2">
                    <h4 style="grid-column: 1/-1; margin: 0; font-size: 14px; color: #1c1c1e;"><i class="fab fa-tiktok"></i> 抖音开放平台</h4>
                    <div class="form-group"><label class="form-label">Client Key</label><input type="text" name="official_dy_clientkey" class="form-control" value="<?= getVal('official_dy_clientkey') ?>"></div>
                    <div class="form-group"><label class="form-label">Client Secret</label><input type="password" name="official_dy_clientsecret" class="form-control" value="<?= getVal('official_dy_clientsecret') ?>"></div>
                </div>
            </div>
            
            <div class="section-card">
                <h3 class="section-title">SMTP 邮件发送</h3>
                <div class="grid-2">
                    <div class="form-group"><label class="form-label">服务器 (Host)</label><input type="text" name="smtp_host" class="form-control" value="<?= getVal('smtp_host') ?>"></div>
                    <div class="form-group"><label class="form-label">端口 (Port)</label><input type="text" name="smtp_port" class="form-control" value="<?= getVal('smtp_port') ?>"></div>
                    <div class="form-group"><label class="form-label">邮箱账号</label><input type="text" name="smtp_user" class="form-control" value="<?= getVal('smtp_user') ?>"></div>
                    <div class="form-group"><label class="form-label">密码/授权码</label><input type="password" name="smtp_pass" class="form-control" value="<?= getVal('smtp_pass') ?>"></div>
                    <div class="form-group" style="grid-column: 1/-1"><label class="form-label">发件人昵称</label><input type="text" name="smtp_from_name" class="form-control" value="<?= getVal('smtp_from_name') ?>"></div>
                </div>
            </div>

            <div class="section-card">
                <h3 class="section-title">COS 对象存储</h3>
                <div class="form-group">
                    <label class="form-label">存储策略</label>
                    <select name="cos_enabled" class="form-control">
                        <option value="0" <?= getVal('cos_enabled')!='1'?'selected':'' ?>>本地存储</option>
                        <option value="1" <?= getVal('cos_enabled')=='1'?'selected':'' ?>>腾讯云 COS</option>
                    </select>
                </div>
                <div class="grid-2">
                    <div class="form-group"><label class="form-label">SecretId</label><input type="text" name="cos_secret_id" class="form-control" value="<?= getVal('cos_secret_id') ?>"></div>
                    <div class="form-group"><label class="form-label">SecretKey</label><input type="password" name="cos_secret_key" class="form-control" value="<?= getVal('cos_secret_key') ?>"></div>
                    <div class="form-group"><label class="form-label">存储桶 (Bucket)</label><input type="text" name="cos_bucket" class="form-control" value="<?= getVal('cos_bucket') ?>"></div>
                    <div class="form-group"><label class="form-label">地域 (Region)</label><input type="text" name="cos_region" class="form-control" value="<?= getVal('cos_region') ?>"></div>
                </div>
                <div class="form-group" style="margin-top:20px;"><label class="form-label">CDN 加速域名</label><input type="text" name="cos_domain" class="form-control" value="<?= getVal('cos_domain') ?>" placeholder="https://cdn.example.com"></div>
            </div>
            
            <div class="section-card">
                <h3 class="section-title">AI 与 音乐</h3>
                <div class="grid-2">
                    <div class="form-group"><label class="form-label">AI API 地址</label><input type="text" name="ai_api_url" class="form-control" value="<?= getVal('ai_api_url') ?>"></div>
                    <div class="form-group"><label class="form-label">AI Key</label><input type="password" name="ai_api_key" class="form-control" value="<?= getVal('ai_api_key') ?>"></div>
                    <div class="form-group" style="grid-column: 1/-1"><label class="form-label">AI 模型名</label><input type="text" name="ai_model_name" class="form-control" value="<?= getVal('ai_model_name') ?>"></div>
                    <div class="form-group"><label class="form-label">音乐 API 可使用作者的yy.jx1314.cc</label><input type="text" name="music_api_url" class="form-control" value="<?= getVal('music_api_url') ?>"></div>
                    <div class="form-group"><label class="form-label">歌单 ID-你的网易云歌单id</label><input type="text" name="music_playlist_id" class="form-control" value="<?= getVal('music_playlist_id') ?>"></div>
                </div>
            </div>
        </div>

        <div id="custom" class="tab-content">
            <div class="section-card">
                <h3 class="section-title">自定义 CSS</h3>
                <div class="form-group"><textarea name="custom_css" class="form-control code-editor" rows="8"><?= getVal('custom_css') ?></textarea></div>
            </div>
            <div class="section-card">
                <h3 class="section-title">自定义 JavaScript</h3>
                <div class="form-group"><textarea name="custom_js" class="form-control code-editor" rows="8"><?= getVal('custom_js') ?></textarea></div>
            </div>
        </div>
        
        
        <div id="payment" class="tab-content">
            
            <div class="section-card">
                <h3 class="section-title">基础支付设置</h3>
                <p class="section-desc">配置充值比例与全局默认支付通道。</p>
                <div class="grid-2">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">积分充值比例 (1元 = ? 积分)</label>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <input type="number" name="points_exchange_rate" class="form-control" value="<?= getVal('points_exchange_rate', '100') ?>" required style="font-weight: bold;">
                            <span style="font-weight:bold; color: #64748b; white-space: nowrap;">积分</span>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">默认支付通道选择</label>
                        <select name="pay_channel" class="form-control" style="border-color: #4f46e5; background: #f8fafc; font-weight: 500; cursor: pointer;" onchange="document.getElementById('epay_config').style.display = this.value === 'epay' ? 'block' : 'none'; document.getElementById('official_config').style.display = this.value === 'official' ? 'block' : 'none';">
                            <option value="epay" <?= getVal('pay_channel', 'epay')=='epay'?'selected':'' ?>>👉 彩虹易支付 (请预防平台跑路)</option>
                            <option value="official" <?= getVal('pay_channel')=='official'?'selected':'' ?>>👉 官方直连 (需企业资质申请)</option>
                        </select>
                    </div>
                </div>
            </div>

            <div id="epay_config" class="section-card" style="display: <?= getVal('pay_channel', 'epay')=='epay'?'block':'none' ?>;">
                <h3 class="section-title">彩虹易支付配置</h3>
                <div class="grid-2">
                    <div class="form-group" style="grid-column: 1/-1"><label class="form-label">易支付网关地址 (末尾需带 /)</label><input type="text" name="epay_url" class="form-control" value="<?= getVal('epay_url') ?>" placeholder="例如: https://pay.example.com/"></div>
                    <div class="form-group"><label class="form-label">商户 ID (PID)</label><input type="text" name="epay_pid" class="form-control" value="<?= getVal('epay_pid') ?>"></div>
                    <div class="form-group"><label class="form-label">商户密钥 (KEY)</label><input type="password" name="epay_key" class="form-control" value="<?= getVal('epay_key') ?>"></div>
                </div>
            </div>

            <div id="official_config" class="section-card" style="display: <?= getVal('pay_channel')=='official'?'block':'none' ?>;">
                <h3 class="section-title">官方原生支付配置</h3>
                
                <div class="grid-2" style="border-bottom:1px dashed #eee; padding-bottom:15px; margin-bottom:15px;">
                    <h4 style="grid-column: 1/-1; margin:0; color:#00a1d6;"><i class="fab fa-alipay"></i> 支付宝 (支持电脑/手机网站 + 当面付)</h4>
                    <div class="form-group"><label class="form-label">AppID</label><input type="text" name="alipay_appid" class="form-control" value="<?= getVal('alipay_appid') ?>"></div>
                    <div class="form-group"><label class="form-label">支付宝公钥 (非应用公钥)</label><textarea name="alipay_public_key" class="form-control" rows="2" placeholder="填入纯字符串，无需 -----BEGIN PUBLIC KEY-----"><?= getVal('alipay_public_key') ?></textarea></div>
                    <div class="form-group" style="grid-column: 1/-1"><label class="form-label">应用私钥 (RSA2)</label><textarea name="alipay_private_key" class="form-control" rows="3" placeholder="填入纯字符串，无需 -----BEGIN RSA PRIVATE KEY-----"><?= getVal('alipay_private_key') ?></textarea></div>
                </div>

                <div class="grid-2">
                    <h4 style="grid-column: 1/-1; margin:0; color:#07c160;"><i class="fab fa-weixin"></i> 微信支付 (API v3 原生扫码)</h4>
                    <div class="form-group"><label class="form-label">绑定的 AppID</label><input type="text" name="wxpay_appid" class="form-control" value="<?= getVal('wxpay_appid') ?>"></div>
                    <div class="form-group"><label class="form-label">商户号 (MCHID)</label><input type="text" name="wxpay_mchid" class="form-control" value="<?= getVal('wxpay_mchid') ?>"></div>
                    <div class="form-group"><label class="form-label">商户证书序列号 (Serial No)</label><input type="text" name="wxpay_serial_no" class="form-control" value="<?= getVal('wxpay_serial_no') ?>"></div>
                    <div class="form-group"><label class="form-label">API v3 密钥 (AES-256-GCM)</label><input type="password" name="wxpay_key" class="form-control" value="<?= getVal('wxpay_key') ?>" placeholder="32位字符串"></div>
                    <div class="form-group" style="grid-column: 1/-1"><label class="form-label">商户私钥 (apiclient_key.pem)</label><textarea name="wxpay_private_key" class="form-control" rows="3" placeholder="填入纯字符串，无需 -----BEGIN PRIVATE KEY-----"><?= getVal('wxpay_private_key') ?></textarea></div>
                </div>
            </div>
        </div>
    </form>
</div>

<div class="mobile-save-bar">
    <button type="button" class="btn btn-primary" onclick="saveSettings(this)">
        <i class="fas fa-save"></i> 保存设置
    </button>
</div>

<div id="toast" class="toast"><i class="fas fa-check-circle"></i> <span>保存成功</span></div>

<script src="assets/js/settings.js?v=<?= time() ?>"></script>

<?php require 'footer.php';  ?>