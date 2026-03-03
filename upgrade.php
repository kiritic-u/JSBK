<?php
/**
 * BKCS 阶梯式增量更新引擎
 * 运行环境：由 admin/updater.php 在解压后自动 require 执行
 */

// 获取 PDO 对象 (updater.php 已经引入了 config.php)
$pdo = getDB();

// 1. 获取用户当前的数据库版本
try {
    $stmt = $pdo->query("SELECT value FROM settings WHERE key_name = 'db_version'");
    $current_db_version = $stmt->fetchColumn();
} catch (Exception $e) {
    $current_db_version = false; // 如果连 settings 表或这个键都没有，返回 false
}

// 2. 如果没有记录版本，说明他是 1.0.6 之前的旧版老用户，我们强行标记他为 1.0.5
if (!$current_db_version) {
    $current_db_version = '1.0.5';
    $pdo->exec("INSERT IGNORE INTO settings (key_name, value) VALUES ('db_version', '1.0.5')");
}

// =============================================================================
// 流水线式升级区块：根据版本号，缺哪补哪！绝对不会重复执行报错！
// =============================================================================

// 【目标版本 1.0.6】：补齐密码字段、资源字段、以及关于页面的默认配置
if (version_compare($current_db_version, '1.0.6', '<')) {
    
    // 动作 1：安全添加 articles 表的新字段 (使用 PHP 探测，完美避开 MySQL 5.7 语法报错)
    $check_res = $pdo->query("SHOW COLUMNS FROM articles LIKE 'resource_data'");
    if ($check_res->rowCount() == 0) {
        $pdo->exec("ALTER TABLE articles ADD COLUMN resource_data TEXT DEFAULT NULL COMMENT '资源下载信息JSON'");
    }
    
    $check_pwd = $pdo->query("SHOW COLUMNS FROM articles LIKE 'password'");
    if ($check_pwd->rowCount() == 0) {
        $pdo->exec("ALTER TABLE articles ADD COLUMN password VARCHAR(255) DEFAULT NULL COMMENT '文章访问密码'");
    }

    // 动作 2：安全插入关于页面的数据 (INSERT IGNORE)
    $about_settings = [
        "about_avatar_tags" => '["全栈开发一条龙", "架构设计爱好者", "极客安全狂热粉", "疑难杂症清道夫", "细节强迫症晚期", "热爱开源与分享", "代码如诗行动派", "终身学习践行者"]',
        "about_motto_title" => '源于<br>热爱而去创造',
        "about_motto_tag" => '代码与设计',
        "about_mbti_name" => '调停者',
        "about_mbti_type" => 'INFP-T',
        "about_mbti_icon" => 'fa-leaf',
        "about_belief_title" => '披荆斩棘之路，<br>劈风斩浪。',
        "about_specialty_title" => '高质感 UI<br>全栈开发<br>折腾能力 大师级',
        "about_game_title" => '单机与主机游戏',
        "about_game_bg" => 'https://placehold.co/600x300/a8c0ff/ffffff?text=Gaming',
        "about_tech_title" => '极客外设控',
        "about_tech_bg" => 'https://placehold.co/600x300/ffecd2/ffffff?text=Tech',
        "about_music_title" => '许嵩、民谣、华语流行',
        "about_music_bg" => 'https://placehold.co/600x300/3b82f6/ffffff?text=Music',
        "about_anime_covers" => '["https://placehold.co/200x400/8a2be2/ffffff?text=Anime+1", "https://placehold.co/200x400/ff6b6b/ffffff?text=Anime+2", "https://placehold.co/200x400/1dd1a1/ffffff?text=Anime+3", "https://placehold.co/200x400/feca57/ffffff?text=Anime+4", "https://placehold.co/200x400/5f27cd/ffffff?text=Anime+5"]',
        "about_location_city" => '中国',
        "about_loc_birth" => '199X 出生',
        "about_loc_major" => '产品设计 / 计算机',
        "about_loc_job" => 'UI设计 / 全栈开发',
        "about_journey_content" => '<p>建立这个站点的初衷...</p>',
        "about_career_events" => '[{"title":"某某理工大学","icon":"fa-graduation-cap","color":"bg-blue","left":"0","width":"42","top":"15","pos":"t-top"},{"title":"某互联网科技公司","icon":"fa-building","color":"bg-red","left":"38","width":"32","top":"45","pos":"t-bottom"},{"title":"独立开发 \/ BKCS 系统","icon":"fa-rocket","color":"bg-red","left":"65","width":"35","top":"15","pos":"t-top"}]',
        "about_career_axis" => '[{"text":"2018","left":"0"},{"text":"2022","left":"38"},{"text":"2024","left":"65"},{"text":"现在","left":"100"}]'
    ];

    $stmt_insert = $pdo->prepare("INSERT IGNORE INTO settings (key_name, value) VALUES (?, ?)");
    foreach ($about_settings as $k => $v) {
        $stmt_insert->execute([$k, $v]);
    }

    // 动作 3：成功执行完毕后，更新用户的数据库版本号
    $pdo->exec("UPDATE settings SET value = '1.0.6' WHERE key_name = 'db_version'");
    $current_db_version = '1.0.6'; // 传递给下一个可能的 if 区块
}

// -----------------------------------------------------------------------------
// 未来如果你发布 1.0.7 版本，只需要在下面继续加一个 if 区块即可：
// if (version_compare($current_db_version, '1.0.7', '<')) { 
//     $pdo->exec("ALTER TABLE ..."); 
//     $pdo->exec("UPDATE settings SET value = '1.0.7' WHERE key_name = 'db_version'");
// }
// -----------------------------------------------------------------------------

?>