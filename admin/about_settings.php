<?php
// admin/about_settings.php
require_once '../includes/config.php';
requireLogin();

$pdo = getDB();

// --- 1. 处理 AJAX 表单保存逻辑 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        // 常规文本字段
        $text_keys = [
            'about_motto_title', 'about_motto_tag', 'about_mbti_name', 'about_mbti_type',
            'about_mbti_icon', 'about_belief_title', 'about_specialty_title',
            'about_game_title', 'about_game_bg', 'about_tech_title', 'about_tech_bg',
            'about_music_title', 'about_music_bg', 'about_location_city', 'about_loc_birth',
            'about_loc_major', 'about_loc_job', 'about_journey_content', 'about_photo_bg'
        ];
        
        $stmt = $pdo->prepare("INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
        
        foreach ($text_keys as $key) {
            if (isset($_POST[$key])) {
                $stmt->execute([$key, $_POST[$key]]);
            }
        }

        // 简单数组字段
        if (isset($_POST['about_avatar_tags']) && is_array($_POST['about_avatar_tags'])) {
            $tags = array_map('trim', $_POST['about_avatar_tags']);
            $stmt->execute(['about_avatar_tags', json_encode($tags, JSON_UNESCAPED_UNICODE)]);
        }
        if (isset($_POST['about_anime_covers']) && is_array($_POST['about_anime_covers'])) {
            $covers = array_values(array_filter(array_map('trim', $_POST['about_anime_covers'])));
            $stmt->execute(['about_anime_covers', json_encode($covers, JSON_UNESCAPED_UNICODE)]);
        } else if (!isset($_POST['about_anime_covers'])) {
            $stmt->execute(['about_anime_covers', json_encode([], JSON_UNESCAPED_UNICODE)]);
        }

        // 复杂数组字段：生涯经历节点
        if (isset($_POST['career_event_title'])) {
            $events = [];
            for($i=0; $i<count($_POST['career_event_title']); $i++) {
                if(trim($_POST['career_event_title'][$i]) === '') continue;
                $events[] = [
                    'title' => trim($_POST['career_event_title'][$i]),
                    'icon' => trim($_POST['career_event_icon'][$i]),
                    'color' => trim($_POST['career_event_color'][$i]),
                    'left' => trim($_POST['career_event_left'][$i]),
                    'width' => trim($_POST['career_event_width'][$i]),
                    'top' => trim($_POST['career_event_top'][$i]),
                    'pos' => trim($_POST['career_event_pos'][$i])
                ];
            }
            $stmt->execute(['about_career_events', json_encode($events, JSON_UNESCAPED_UNICODE)]);
        } else {
            $stmt->execute(['about_career_events', json_encode([], JSON_UNESCAPED_UNICODE)]);
        }

        // 复杂数组字段：生涯底部时间轴
        if (isset($_POST['career_axis_text'])) {
            $axis = [];
            for($i=0; $i<count($_POST['career_axis_text']); $i++) {
                if(trim($_POST['career_axis_text'][$i]) === '') continue;
                $axis[] = [
                    'text' => trim($_POST['career_axis_text'][$i]),
                    'left' => trim($_POST['career_axis_left'][$i])
                ];
            }
            $stmt->execute(['about_career_axis', json_encode($axis, JSON_UNESCAPED_UNICODE)]);
        } else {
            $stmt->execute(['about_career_axis', json_encode([], JSON_UNESCAPED_UNICODE)]);
        }
        
        // 清空 Redis 缓存
        if (function_exists('getRedis') && $redis = getRedis()) {
            $redis->flushDB();
        }

        echo json_encode(['success' => true, 'message' => '关于页面配置已保存']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '保存失败: ' . $e->getMessage()]);
    }
    exit;
}

// --- 2. 获取当前设置 ---
$stmt = $pdo->query("SELECT key_name, value FROM settings WHERE key_name LIKE 'about_%'");
$about_conf = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $about_conf[$row['key_name']] = $row['value'];
}

function val($key, $default = '') {
    global $about_conf;
    return htmlspecialchars($about_conf[$key] ?? $default);
}

// 数组解析
$avatar_tags = json_decode($about_conf['about_avatar_tags'] ?? '[]', true) ?: [];
$avatar_tags = array_pad($avatar_tags, 8, '');

$anime_covers = json_decode($about_conf['about_anime_covers'] ?? '[]', true) ?: [];
$career_events = json_decode($about_conf['about_career_events'] ?? '[]', true) ?: [];
$career_axis = json_decode($about_conf['about_career_axis'] ?? '[]', true) ?: [];

require_once 'header.php';
?>

<link rel="stylesheet" href="assets/css/settings.css?v=<?= time() ?>">
<style>
    .page-scroll-container { width: 100%; height: calc(100vh - 80px); overflow-y: auto; padding: 20px 20px 80px 20px; box-sizing: border-box; scrollbar-width: thin; }
    .page-scroll-container::-webkit-scrollbar { width: 6px; }
    .page-scroll-container::-webkit-scrollbar-thumb { background: #ccc; border-radius: 3px; }
    
    .input-group { display: flex; align-items: stretch; }
    .input-group .form-control { border-top-right-radius: 0; border-bottom-right-radius: 0; border-right: none; }
    .btn-upload { background: #f8fafc; border: 1px solid var(--border-color); padding: 0 16px; border-top-right-radius: 8px; border-bottom-right-radius: 8px; cursor: pointer; color: var(--text-sub); transition: 0.2s; font-size: 13px; font-weight: 500; white-space: nowrap;}
    .btn-upload:hover { background: #e2e8f0; color: var(--primary); }
    .btn-upload-text { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: var(--primary); cursor: pointer; margin-bottom: 8px; border: 1px solid #bfdbfe; padding: 4px 10px; border-radius: 6px; background: #eff6ff; transition: 0.2s;}
    .btn-upload-text:hover { background: #dbeafe; }

    .mb-2 { margin-bottom: 12px; }
    .tag-group-title { font-size: 13px; font-weight: 600; color: var(--text-sub); margin-bottom: 10px; padding-bottom: 5px; border-bottom: 1px dashed #e2e8f0;}
    .input-prefix { background: #f1f5f9; border: 1px solid #d1d5db; border-right: none; padding: 0 12px; display: flex; align-items: center; justify-content: center; font-size: 12px; color: #64748b; font-weight: bold; border-top-left-radius: 8px; border-bottom-left-radius: 8px;}
    .input-prefix i { color: #3b82f6; font-size: 14px; }
    .prefixed-input { border-top-left-radius: 0; border-bottom-left-radius: 0; }

    .dynamic-list { display: flex; flex-direction: column; gap: 12px; }
    .dynamic-item { display: flex; align-items: center; gap: 15px; background: #f8fafc; padding: 12px; border-radius: 12px; border: 1px solid #e2e8f0; transition: 0.3s; }
    .dynamic-item:hover { background: #fff; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border-color: #cbd5e1; }
    .item-preview { width: 50px; height: 75px; object-fit: cover; border-radius: 6px; background: #e2e8f0; border: 1px solid #cbd5e1; flex-shrink: 0; }
    .item-input { flex: 1; }
    .btn-icon { width: 36px; height: 36px; border-radius: 8px; border: none; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.2s; font-size: 14px; }
    .btn-icon.upload { background: #eff6ff; color: #3b82f6; border: 1px solid #bfdbfe; }
    .btn-icon.upload:hover { background: #3b82f6; color: #fff; }
    .btn-icon.delete { background: #fef2f2; color: #ef4444; border: 1px solid #fecaca; }
    .btn-icon.delete:hover { background: #ef4444; color: #fff; }

    .career-item { background: #fff; padding: 15px; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 15px; box-shadow: 0 2px 5px rgba(0,0,0,0.02); }
    .career-item-header { display: flex; justify-content: space-between; font-weight: bold; margin-bottom: 10px; color: #1e293b; font-size: 14px;}
    .c-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
    .c-grid .form-group { margin-bottom: 0; }

    /* --- 图标选择器弹窗样式 (已修复居中问题) --- */
    .icon-modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 99999; display: none; align-items: center; justify-content: center; backdrop-filter: blur(4px); opacity: 0; transition: opacity 0.3s; }
    .icon-modal-overlay.active { display: flex !important; opacity: 1; }
    .icon-modal-box { background: #fff; width: 500px; max-width: 90%; max-height: 90vh; margin: auto; border-radius: 16px; box-shadow: 0 20px 40px rgba(0,0,0,0.2); display: flex; flex-direction: column; overflow: hidden; transform: scale(0.9); transition: transform 0.3s; }
    .icon-modal-overlay.active .icon-modal-box { transform: scale(1); }
    .icon-modal-header { padding: 15px 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; }
    .icon-modal-title { font-weight: bold; font-size: 16px; color: #333; }
    .icon-modal-close { cursor: pointer; color: #999; font-size: 20px; transition: 0.2s; }
    .icon-modal-close:hover { color: #ef4444; transform: rotate(90deg); }
    .icon-search-bar { padding: 15px 20px; border-bottom: 1px solid #f5f5f5; }
    .icon-search-bar input { width: 100%; padding: 10px 15px; border-radius: 20px; border: 1px solid #ddd; outline: none; background: #f9f9f9; transition: 0.2s; }
    .icon-search-bar input:focus { border-color: #3b82f6; background: #fff; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
    .icon-grid { padding: 15px 20px; display: grid; grid-template-columns: repeat(auto-fill, minmax(45px, 1fr)); gap: 10px; max-height: 350px; overflow-y: auto; scrollbar-width: thin; }
    .icon-item-btn { display: flex; align-items: center; justify-content: center; font-size: 20px; color: #475569; width: 45px; height: 45px; border-radius: 8px; border: 1px solid #e2e8f0; cursor: pointer; transition: 0.2s; }
    .icon-item-btn:hover { background: #eff6ff; color: #3b82f6; border-color: #bfdbfe; transform: translateY(-2px); }
</style>

<div class="page-scroll-container">
    <div class="settings-wrapper">
        
        <div class="settings-header">
            <div class="page-title">
                <i class="fas fa-id-card" style="color: var(--primary);"></i> 关于本站配置
            </div>
            <div class="header-actions">
                <button type="button" class="btn btn-primary btn-save-desktop" onclick="saveAboutSettings(this)">
                    <i class="fas fa-save"></i> 保存修改
                </button>
            </div>
        </div>

        <div class="tabs-wrapper">
            <div class="tabs-header">
                <div class="tab-btn active" onclick="switchTab('tab-profile')"><i class="fas fa-user"></i> 个人档案</div>
                <div class="tab-btn" onclick="switchTab('tab-resume')"><i class="fas fa-map-marker-alt"></i> 履历生涯</div>
                <div class="tab-btn" onclick="switchTab('tab-hobbies')"><i class="fas fa-gamepad"></i> 兴趣爱好</div>
            </div>
        </div>

        <form id="aboutSettingsForm">
            
            <div id="tab-profile" class="tab-content active">
                <div class="section-card">
                    <h3 class="section-title"><i class="fas fa-tags" style="color:#8b5cf6;"></i> 头像与标签</h3>
                    <div class="grid-2">
                        <div class="form-group" style="grid-column: span 2;">
                            <label class="form-label">8个环绕标签 (精确控制左右位置)</label>
                            <div class="grid-2" style="background: #fafafa; padding: 20px; border-radius: 12px; border: 1px solid #eee;">
                                <div>
                                    <div class="tag-group-title">左侧标签组 (L1 - L4)</div>
                                    <?php for($i=0; $i<4; $i++): ?>
                                    <div class="input-group mb-2">
                                        <div class="input-prefix" style="width: 40px;">L<?= $i+1 ?></div>
                                        <input type="text" name="about_avatar_tags[]" class="form-control prefixed-input" value="<?= htmlspecialchars($avatar_tags[$i]) ?>">
                                    </div>
                                    <?php endfor; ?>
                                </div>
                                <div>
                                    <div class="tag-group-title">右侧标签组 (R1 - R4)</div>
                                    <?php for($i=4; $i<8; $i++): ?>
                                    <div class="input-group mb-2">
                                        <div class="input-prefix" style="width: 40px;">R<?= $i-3 ?></div>
                                        <input type="text" name="about_avatar_tags[]" class="form-control prefixed-input" value="<?= htmlspecialchars($avatar_tags[$i]) ?>">
                                    </div>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label class="form-label">纯图卡片 (个人照片/工作台)</label>
                            <div class="input-group">
                                <input type="text" id="ipt_photo_bg" name="about_photo_bg" class="form-control" value="<?= val('about_photo_bg') ?>">
                                <button type="button" class="btn-upload" onclick="triggerUpload('ipt_photo_bg', false)"><i class="fas fa-cloud-upload-alt"></i> 上传</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="section-card">
                    <h3 class="section-title"><i class="fas fa-bullseye" style="color:#ef4444;"></i> 追求与个性</h3>
                    <div class="grid-2">
                        <div class="form-group">
                            <label class="form-label">追求标签</label>
                            <input type="text" name="about_motto_tag" class="form-control" value="<?= val('about_motto_tag') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">核心标题 (支持 &lt;br&gt; 换行)</label>
                            <input type="text" name="about_motto_title" class="form-control" value="<?= val('about_motto_title') ?>">
                        </div>
                    </div>
                    <div class="grid-2" style="grid-template-columns: 1fr 1fr 1fr;">
                        <div class="form-group">
                            <label class="form-label">MBTI 称号</label>
                            <input type="text" name="about_mbti_name" class="form-control" value="<?= val('about_mbti_name') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">MBTI 英文</label>
                            <input type="text" name="about_mbti_type" class="form-control" value="<?= val('about_mbti_type') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">MBTI 图标</label>
                            <div class="input-group">
                                <div class="input-prefix" style="width: 40px;"><i id="preview_mbti_icon" class="fa-solid <?= val('about_mbti_icon', 'fa-leaf') ?>"></i></div>
                                <input type="text" id="ipt_mbti_icon" name="about_mbti_icon" class="form-control prefixed-input" value="<?= val('about_mbti_icon', 'fa-leaf') ?>" oninput="document.getElementById('preview_mbti_icon').className='fa-solid ' + this.value">
                                <button type="button" class="btn-upload" onclick="openIconPicker('ipt_mbti_icon', 'preview_mbti_icon')"><i class="fas fa-search"></i> 选择</button>
                            </div>
                        </div>
                    </div>
                    <div class="grid-2">
                        <div class="form-group">
                            <label class="form-label">信仰文案 (支持 &lt;br&gt; 换行)</label>
                            <textarea name="about_belief_title" class="form-control" rows="3"><?= val('about_belief_title') ?></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label">特长文案 (支持 &lt;br&gt; 换行)</label>
                            <textarea name="about_specialty_title" class="form-control" rows="3"><?= val('about_specialty_title') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div id="tab-resume" class="tab-content">
                
                <div class="section-card">
                    <h3 class="section-title"><i class="fas fa-stream" style="color:#0ea5e9;"></i> 生涯时间线节点 (进度条)</h3>
                    <div style="margin-bottom: 10px;">
                        <span class="btn-upload-text" onclick="addCareerEvent()"><i class="fas fa-plus"></i> 新增一个生涯阶段</span>
                        <div class="help-text">说明：左侧起点(0-100)，宽度(0-100)，垂直偏移量用来做上下交错层叠效果。</div>
                    </div>
                    <div id="careerEventsContainer" style="background:#f8fafc; padding:15px; border-radius:10px;">
                        <?php foreach($career_events as $idx => $ev): ?>
                        <div class="career-item">
                            <div class="career-item-header">阶段 <?= $idx+1 ?> <button type="button" class="btn-icon delete" style="width:24px;height:24px;font-size:12px;" onclick="this.closest('.career-item').remove()"><i class="fas fa-times"></i></button></div>
                            <div class="c-grid">
                                <div class="form-group"><label>标题文案</label><input type="text" name="career_event_title[]" class="form-control" value="<?= htmlspecialchars($ev['title']) ?>"></div>
                                <div class="form-group">
                                    <label>图标选择</label>
                                    <div class="input-group">
                                        <div class="input-prefix" style="width: 36px;"><i id="preview_cev_<?= $idx ?>" class="fa-solid <?= htmlspecialchars($ev['icon']) ?>"></i></div>
                                        <input type="text" id="ipt_cev_<?= $idx ?>" name="career_event_icon[]" class="form-control prefixed-input" value="<?= htmlspecialchars($ev['icon']) ?>" oninput="document.getElementById('preview_cev_<?= $idx ?>').className='fa-solid ' + this.value">
                                        <button type="button" class="btn-upload" onclick="openIconPicker('ipt_cev_<?= $idx ?>', 'preview_cev_<?= $idx ?>')" style="padding: 0 10px;">选</button>
                                    </div>
                                </div>
                                <div class="form-group"><label>颜色</label><select name="career_event_color[]" class="form-control"><option value="bg-blue" <?= $ev['color']=='bg-blue'?'selected':'' ?>>科技蓝</option><option value="bg-red" <?= $ev['color']=='bg-red'?'selected':'' ?>>活力红</option></select></div>
                                <div class="form-group"><label>文字位置</label><select name="career_event_pos[]" class="form-control"><option value="t-top" <?= $ev['pos']=='t-top'?'selected':'' ?>>上方</option><option value="t-bottom" <?= $ev['pos']=='t-bottom'?'selected':'' ?>>下方</option></select></div>
                                <div class="form-group"><label>左侧起点(%)</label><input type="number" name="career_event_left[]" class="form-control" value="<?= htmlspecialchars($ev['left']) ?>"></div>
                                <div class="form-group"><label>总宽度(%)</label><input type="number" name="career_event_width[]" class="form-control" value="<?= htmlspecialchars($ev['width']) ?>"></div>
                                <div class="form-group"><label>垂直下沉量(px)</label><input type="number" name="career_event_top[]" class="form-control" value="<?= htmlspecialchars($ev['top']) ?>"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="section-card">
                    <h3 class="section-title"><i class="fas fa-ruler-horizontal" style="color:#64748b;"></i> 底部时间轴坐标</h3>
                    <div style="margin-bottom: 10px;">
                        <span class="btn-upload-text" onclick="addCareerAxis()"><i class="fas fa-plus"></i> 新增时间锚点</span>
                    </div>
                    <div id="careerAxisContainer" class="dynamic-list">
                        <?php foreach($career_axis as $ax): ?>
                        <div class="dynamic-item" style="padding: 10px;">
                            <div class="item-input" style="display:flex; gap:10px;">
                                <input type="text" name="career_axis_text[]" class="form-control" value="<?= htmlspecialchars($ax['text']) ?>" placeholder="年份文本 (如 2018)">
                                <input type="number" name="career_axis_left[]" class="form-control" value="<?= htmlspecialchars($ax['left']) ?>" placeholder="左侧位置 % (0-100)">
                            </div>
                            <button type="button" class="btn-icon delete" onclick="this.closest('.dynamic-item').remove()"><i class="fas fa-trash"></i></button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="section-card">
                    <h3 class="section-title"><i class="fas fa-map-marker-alt" style="color:#3b82f6;"></i> 坐标定位与履历简介</h3>
                    <div class="grid-2">
                        <div class="form-group"><label class="form-label">当前城市</label><input type="text" name="about_location_city" class="form-control" value="<?= val('about_location_city') ?>"></div>
                        <div class="form-group"><label class="form-label">出生标签</label><input type="text" name="about_loc_birth" class="form-control" value="<?= val('about_loc_birth') ?>"></div>
                        <div class="form-group"><label class="form-label">专业标签</label><input type="text" name="about_loc_major" class="form-control" value="<?= val('about_loc_major') ?>"></div>
                        <div class="form-group"><label class="form-label">职业标签</label><input type="text" name="about_loc_job" class="form-control" value="<?= val('about_loc_job') ?>"></div>
                    </div>
                </div>

                <div class="section-card">
                    <h3 class="section-title"><i class="fas fa-pen-nib" style="color:#6366f1;"></i> 心路历程</h3>
                    <div class="form-group">
                        <label class="form-label">建站历程描述 (支持 HTML 标签，如 &lt;p&gt;, &lt;strong&gt;)</label>
                        <textarea name="about_journey_content" class="form-control" rows="8"><?= val('about_journey_content') ?></textarea>
                    </div>
                </div>
            </div>

            <div id="tab-hobbies" class="tab-content">
                <div class="section-card">
                    <h3 class="section-title"><i class="fas fa-gamepad" style="color:#f59e0b;"></i> 游戏与数码</h3>
                    <div class="grid-2">
                        <div class="form-group"><label class="form-label">游戏热爱 - 标题</label><input type="text" name="about_game_title" class="form-control" value="<?= val('about_game_title') ?>"></div>
                        <div class="form-group"><label class="form-label">游戏热爱 - 背景图片</label><div class="input-group"><input type="text" id="ipt_game_bg" name="about_game_bg" class="form-control" value="<?= val('about_game_bg') ?>"><button type="button" class="btn-upload" onclick="triggerUpload('ipt_game_bg', false)"><i class="fas fa-upload"></i></button></div></div>
                        <div class="form-group"><label class="form-label">数码科技 - 标题</label><input type="text" name="about_tech_title" class="form-control" value="<?= val('about_tech_title') ?>"></div>
                        <div class="form-group"><label class="form-label">数码科技 - 背景图片</label><div class="input-group"><input type="text" id="ipt_tech_bg" name="about_tech_bg" class="form-control" value="<?= val('about_tech_bg') ?>"><button type="button" class="btn-upload" onclick="triggerUpload('ipt_tech_bg', false)"><i class="fas fa-upload"></i></button></div></div>
                    </div>
                </div>

                <div class="section-card">
                    <h3 class="section-title"><i class="fas fa-film" style="color:#10b981;"></i> 影视与音乐</h3>
                    <div class="grid-2">
                        <div class="form-group"><label class="form-label">音乐偏好 - 概括标题</label><input type="text" name="about_music_title" class="form-control" value="<?= val('about_music_title') ?>"></div>
                        <div class="form-group"><label class="form-label">音乐偏好 - 沉浸背景图</label><div class="input-group"><input type="text" id="ipt_music_bg" name="about_music_bg" class="form-control" value="<?= val('about_music_bg') ?>"><button type="button" class="btn-upload" onclick="triggerUpload('ipt_music_bg', false)"><i class="fas fa-upload"></i></button></div></div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" style="display: flex; justify-content: space-between; align-items: center;">
                            <span>近期追番/影视海报 (支持动态管理)</span>
                            <span class="btn-upload-text" onclick="addAnimeCover()"><i class="fas fa-plus"></i> 新增一张海报</span>
                        </label>
                        <div id="animeListContainer" class="dynamic-list">
                            <?php foreach($anime_covers as $index => $cover): ?>
                            <div class="dynamic-item">
                                <img src="<?= htmlspecialchars($cover) ?>" class="item-preview" onerror="this.src='https://placehold.co/100x150?text=No+Img'">
                                <div class="item-input">
                                    <input type="text" name="about_anime_covers[]" class="form-control" value="<?= htmlspecialchars($cover) ?>" oninput="updatePreview(this)" id="anime_cover_<?= $index ?>">
                                </div>
                                <div style="display:flex; gap: 8px;">
                                    <button type="button" class="btn-icon upload" onclick="triggerUpload('anime_cover_<?= $index ?>', false, true)"><i class="fas fa-upload"></i></button>
                                    <button type="button" class="btn-icon delete" onclick="this.closest('.dynamic-item').remove()"><i class="fas fa-trash"></i></button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

        </form>
    </div>

    <div class="mobile-save-bar">
        <button type="button" class="btn btn-primary" onclick="saveAboutSettings(this)"><i class="fas fa-save"></i> 保存</button>
    </div>
</div>

<div class="icon-modal-overlay" id="iconPickerModal">
    <div class="icon-modal-box">
        <div class="icon-modal-header">
            <div class="icon-modal-title"><i class="fas fa-icons"></i> 选择一个图标</div>
            <i class="fas fa-times icon-modal-close" onclick="closeIconPicker()"></i>
        </div>
        <div class="icon-search-bar">
            <input type="text" id="iconSearchInput" placeholder="输入关键字搜索 (如: star, user, code...)" onkeyup="filterIcons()">
        </div>
        <div class="icon-grid" id="iconGridContainer">
            </div>
    </div>
</div>

<div id="toast" class="toast"><i class="fas fa-check-circle"></i><span></span></div>
<input type="file" id="hiddenImageUploader" accept="image/*" style="display: none;">

<script src="assets/js/settings.js?v=<?= time() ?>"></script>
<script>
    // ==========================================
    // [新增] 可视化图标库库 (精选了80多个常用实用图标)
    // ==========================================
    const faIcons = [
        'fa-user', 'fa-users', 'fa-user-tie', 'fa-code', 'fa-laptop-code', 'fa-server', 'fa-database', 'fa-cloud',
        'fa-bug', 'fa-shield-halved', 'fa-lock', 'fa-key', 'fa-graduation-cap', 'fa-building', 'fa-briefcase', 'fa-rocket',
        'fa-star', 'fa-heart', 'fa-leaf', 'fa-fire', 'fa-bolt', 'fa-lightbulb', 'fa-pen-nib', 'fa-palette',
        'fa-compass', 'fa-map-marker-alt', 'fa-globe', 'fa-camera', 'fa-image', 'fa-film', 'fa-music', 'fa-gamepad',
        'fa-mobile-screen', 'fa-desktop', 'fa-microchip', 'fa-terminal', 'fa-robot', 'fa-brain', 'fa-cogs', 'fa-network-wired',
        'fa-link', 'fa-calendar-check', 'fa-check-circle', 'fa-chart-line', 'fa-chart-pie', 'fa-award', 'fa-trophy',
        'fa-medal', 'fa-crown', 'fa-flag', 'fa-paper-plane', 'fa-paperclip', 'fa-envelope', 'fa-comments', 'fa-bell',
        'fa-magnifying-glass', 'fa-share-nodes', 'fa-feather', 'fa-book', 'fa-bookmark', 'fa-folder-open', 'fa-box-open',
        'fa-wrench', 'fa-hammer', 'fa-screwdriver-wrench', 'fa-gears', 'fa-wand-magic-sparkles', 'fa-flask', 'fa-flask-vial',
        'fa-virus', 'fa-circle-nodes', 'fa-sitemap', 'fa-memory', 'fa-satellite-dish', 'fa-wifi', 'fa-signal'
    ];

    let currentIconTargetInput = '';
    let currentIconTargetPreview = '';
    const iconModal = document.getElementById('iconPickerModal');
    const iconGrid = document.getElementById('iconGridContainer');

    function openIconPicker(inputId, previewId) {
        currentIconTargetInput = inputId;
        currentIconTargetPreview = previewId;
        
        // 渲染图标网格
        renderIcons(faIcons);
        
        iconModal.classList.add('active');
        document.getElementById('iconSearchInput').value = '';
        document.getElementById('iconSearchInput').focus();
    }

    function closeIconPicker() {
        iconModal.classList.remove('active');
    }

    function selectIcon(iconClass) {
        // 填入输入框
        document.getElementById(currentIconTargetInput).value = iconClass;
        // 更新左侧的小预览图标
        document.getElementById(currentIconTargetPreview).className = 'fa-solid ' + iconClass;
        closeIconPicker();
    }

    function renderIcons(iconsArray) {
        let html = '';
        iconsArray.forEach(icon => {
            // 点击时调用 selectIcon
            html += `<div class="icon-item-btn" title="${icon}" onclick="selectIcon('${icon}')"><i class="fa-solid ${icon}"></i></div>`;
        });
        iconGrid.innerHTML = html;
    }

    function filterIcons() {
        const keyword = document.getElementById('iconSearchInput').value.toLowerCase();
        const filtered = faIcons.filter(icon => icon.toLowerCase().includes(keyword));
        renderIcons(filtered);
    }
    
    // 点击弹窗外部也可关闭
    iconModal.addEventListener('click', function(e) {
        if(e.target === iconModal) closeIconPicker();
    });


    // ==========================================
    // 生涯时间线 动态交互逻辑 (已关联图标选择器)
    // ==========================================
    let careerIdx = <?= count($career_events) + 10 ?>; // 保证ID唯一
    function addCareerEvent() {
        careerIdx++;
        const container = document.getElementById('careerEventsContainer');
        if (!container) return;
        const html = `
        <div class="career-item">
            <div class="career-item-header">新阶段 <button type="button" class="btn-icon delete" style="width:24px;height:24px;font-size:12px;" onclick="this.closest('.career-item').remove()"><i class="fas fa-times"></i></button></div>
            <div class="c-grid">
                <div class="form-group"><label>标题</label><input type="text" name="career_event_title[]" class="form-control" value="新阶段"></div>
                <div class="form-group">
                    <label>图标选择</label>
                    <div class="input-group">
                        <div class="input-prefix" style="width: 36px;"><i id="preview_cev_${careerIdx}" class="fa-solid fa-circle"></i></div>
                        <input type="text" id="ipt_cev_${careerIdx}" name="career_event_icon[]" class="form-control prefixed-input" value="fa-circle" oninput="document.getElementById('preview_cev_${careerIdx}').className='fa-solid ' + this.value">
                        <button type="button" class="btn-upload" onclick="openIconPicker('ipt_cev_${careerIdx}', 'preview_cev_${careerIdx}')" style="padding: 0 10px;">选</button>
                    </div>
                </div>
                <div class="form-group"><label>颜色</label><select name="career_event_color[]" class="form-control"><option value="bg-blue">科技蓝</option><option value="bg-red">活力红</option></select></div>
                <div class="form-group"><label>文字位置</label><select name="career_event_pos[]" class="form-control"><option value="t-top">上方</option><option value="t-bottom">下方</option></select></div>
                <div class="form-group"><label>左侧起点(%)</label><input type="number" name="career_event_left[]" class="form-control" value="0"></div>
                <div class="form-group"><label>总宽度(%)</label><input type="number" name="career_event_width[]" class="form-control" value="30"></div>
                <div class="form-group"><label>下沉量(px)</label><input type="number" name="career_event_top[]" class="form-control" value="15"></div>
            </div>
        </div>`;
        container.insertAdjacentHTML('beforeend', html);
    }

    function addCareerAxis() {
        const container = document.getElementById('careerAxisContainer');
        if (!container) return;
        const html = `
        <div class="dynamic-item" style="padding: 10px;">
            <div class="item-input" style="display:flex; gap:10px;">
                <input type="text" name="career_axis_text[]" class="form-control" placeholder="年份文本">
                <input type="number" name="career_axis_left[]" class="form-control" placeholder="左侧位置 % (0-100)">
            </div>
            <button type="button" class="btn-icon delete" onclick="this.closest('.dynamic-item').remove()"><i class="fas fa-trash"></i></button>
        </div>`;
        container.insertAdjacentHTML('beforeend', html);
    }

    // ==========================================
    // 动态列表交互 (影视封面)
    // ==========================================
    let coverIndex = <?= count($anime_covers) + 10 ?>; 

    function updatePreview(inputEl) {
        const previewImg = inputEl.closest('.dynamic-item').querySelector('.item-preview');
        if(previewImg) previewImg.src = inputEl.value || 'https://placehold.co/100x150?text=No+Img';
    }

    function addAnimeCover() {
        coverIndex++;
        const container = document.getElementById('animeListContainer');
        const html = `
            <div class="dynamic-item">
                <img src="https://placehold.co/100x150?text=Upload" class="item-preview" onerror="this.src='https://placehold.co/100x150?text=Error'">
                <div class="item-input">
                    <input type="text" name="about_anime_covers[]" class="form-control" value="" oninput="updatePreview(this)" id="anime_cover_${coverIndex}" placeholder="输入图片URL或右侧上传">
                </div>
                <div style="display:flex; gap: 8px;">
                    <button type="button" class="btn-icon upload" onclick="triggerUpload('anime_cover_${coverIndex}', false, true)" title="上传"><i class="fas fa-upload"></i></button>
                    <button type="button" class="btn-icon delete" onclick="this.closest('.dynamic-item').remove()"><i class="fas fa-trash"></i></button>
                </div>
            </div>
        `;
        container.insertAdjacentHTML('beforeend', html);
    }

    // ==========================================
    // AJAX 提交关于页面设置
    // ==========================================
    function saveAboutSettings(btn) {
        const allBtns = document.querySelectorAll('.btn-save-desktop, .mobile-save-bar .btn');
        allBtns.forEach(b => {
            b.dataset.original = b.innerHTML;
            b.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 保存中...';
            b.disabled = true;
        });

        const form = document.getElementById('aboutSettingsForm');
        const formData = new FormData(form);

        fetch('about_settings.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(res => {
            if(res.success) {
                showToast(res.message, 'success');
            } else {
                showToast(res.message, 'error');
            }
        })
        .catch(err => {
            console.error(err);
            showToast('网络错误或服务器异常', 'error');
        })
        .finally(() => {
            allBtns.forEach(b => {
                b.innerHTML = b.dataset.original;
                b.disabled = false;
            });
        });
    }

    // ==========================================
    // 本地图片异步直传逻辑
    // ==========================================
    let currentUploadTarget = '';
    let isNeedPreviewUpdate = false; 
    let isAppendMode = false; 
    const uploader = document.getElementById('hiddenImageUploader');

    function triggerUpload(targetId, append = false, needPreview = false) {
        currentUploadTarget = targetId;
        isAppendMode = append;
        isNeedPreviewUpdate = needPreview;
        uploader.click();
    }

    uploader.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (!file) return;

        const formData = new FormData();
        formData.append('wangeditor-uploaded-image', file); 

        const targetElement = document.getElementById(currentUploadTarget);
        if(!targetElement) return;
        
        const originalVal = targetElement.value;
        
        if (!isAppendMode) {
            targetElement.value = '上传中，请稍候...';
        }

        fetch('upload.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.errno === 0 && data.data && data.data.url) {
                const url = data.data.url;
                
                if (isAppendMode) {
                    const currentText = targetElement.value.trim();
                    targetElement.value = currentText ? currentText + '\n' + url : url;
                    showToast('图片已追加到列表', 'success');
                } else {
                    targetElement.value = url;
                    showToast('图片上传成功', 'success');
                }
                
                if (isNeedPreviewUpdate) {
                    updatePreview(targetElement);
                }
            } else {
                targetElement.value = originalVal;
                showToast('上传失败: ' + (data.message || '未知错误'), 'error');
            }
        })
        .catch(err => {
            console.error('Upload error:', err);
            targetElement.value = originalVal;
            showToast('请求上传接口失败，请检查网络', 'error');
        })
        .finally(() => {
            uploader.value = ''; 
        });
    });
</script>

<?php require_once 'footer.php'; ?>