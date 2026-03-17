<?php
// 引入公共头部 (里面包含了 config.php 和必要的依赖)
/**              _ _                      ____  _                             
                | (_) __ _ _ __   __ _   / ___|| |__  _   _  ___              
             _  | | |/ _` | '_ \ / _` | \___ \| '_ \| | | |/ _ \             
            | |_| | | (_| | | | | (_| |  ___) | | | | |_| | (_) |            
             \___/|_|\__,_|_| |_|\__, | |____/|_| |_|\__,_|\___/             
    ____  _____          _  __  |___/  _____   _  _  _          ____ ____ 
  / ___| |__  /         | | \ \/ / / | |___ /  / | | || |        / ___/ ___|
 | |  _    / /       _  | |  \  /  | |   |_ \  | | | || |_      | |  | |   
 | |_| |  / /_   _  | |_| |  /  \  | |  ___) | | | |__   _|  _  | |__| |___ 
  \____| /____| (_)  \___/  /_/\_\ |_| |____/  |_|    |_|   (_)  \____\____|
                                                                            
                                追求极致的美学                               
**/
require_once __DIR__ . '/../includes/header.php';

// ======== 1. 获取基础配置 ========
$author_name = conf('author_name', '江硕');
$author_bio = conf('author_bio', '全栈开发者 / UI设计爱好者');
$author_avatar = conf('author_avatar', 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?ixlib=rb-4.0.3&auto=format&fit=crop&w=200&q=80');

// ======== 2. 获取实时统计数据 ========
$pdo = getDB();
$stats_total = $pdo->query("SELECT SUM(views) FROM articles")->fetchColumn() ?: 0;
$stats_month = $pdo->query("SELECT COUNT(*) FROM comments")->fetchColumn() ?: 0;
$stats_today = $pdo->query("SELECT COUNT(*) FROM articles")->fetchColumn() ?: 0;

// ======== 3. 获取并解析后台配置 ========
// 头像标签解析
$avatar_tags = json_decode(htmlspecialchars_decode(conf('about_avatar_tags', '[]')), true) ?: [];
$avatar_tags = array_pad($avatar_tags, 8, "未设置");

// 追剧动漫封面解析
$anime_covers = json_decode(htmlspecialchars_decode(conf('about_anime_covers', '[]')), true) ?: [
    'https://placehold.co/200x400/8a2be2/ffffff?text=Anime+1',
    'https://placehold.co/200x400/ff6b6b/ffffff?text=Anime+2',
    'https://placehold.co/200x400/1dd1a1/ffffff?text=Anime+3',
    'https://placehold.co/200x400/feca57/ffffff?text=Anime+4',
    'https://placehold.co/200x400/5f27cd/ffffff?text=Anime+5'
];

// 生涯模块数据解析
$career_events = json_decode(htmlspecialchars_decode(conf('about_career_events', '[]')), true) ?: [
    ['title'=>'某某理工大学', 'icon'=>'fa-graduation-cap', 'color'=>'bg-blue', 'left'=>'0', 'width'=>'42', 'top'=>'15', 'pos'=>'t-top'],
    ['title'=>'某互联网科技公司', 'icon'=>'fa-building', 'color'=>'bg-red', 'left'=>'38', 'width'=>'32', 'top'=>'45', 'pos'=>'t-bottom'],
    ['title'=>'独立开发 / BKCS 系统', 'icon'=>'fa-rocket', 'color'=>'bg-red', 'left'=>'65', 'width'=>'35', 'top'=>'15', 'pos'=>'t-top']
];
$career_axis = json_decode(htmlspecialchars_decode(conf('about_career_axis', '[]')), true) ?: [
    ['text'=>'2018', 'left'=>'0'], ['text'=>'2022', 'left'=>'38'], 
    ['text'=>'2024', 'left'=>'65'], ['text'=>'现在', 'left'=>'100']
];
?>

<link rel="stylesheet" href="https://cdn.staticfile.net/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="../pages/assets/css/about.css?v=<?= time() ?>">

<style>
    /* 【核心修复】：解决内容与头部导航栏两边宽度不对齐的问题 */
    .about-page-container {
        max-width: 100% !important; /* 强制填满 header.php 提供的 container 宽度 */
        padding-left: 0 !important; /* 抵消双重 padding，防止内容往中间挤压 */
        padding-right: 0 !important;
    }
</style>

<div class="apple-ambient-bg">
    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>
    <div class="blob blob-3"></div>
</div>

<div class="about-page-container">
    
    <div class="about-header-section">
        <div class="avatar-interactive-zone">
            <div class="about-avatar-wrapper">
                <img src="<?= htmlspecialchars($author_avatar) ?>" alt="Avatar">
                <div class="status-dot"></div>
                
                <div class="f-tag tag-l1"><i class="fa-solid fa-code"></i> <?= htmlspecialchars($avatar_tags[0]) ?></div>
                <div class="f-tag tag-l2"><i class="fa-solid fa-server"></i> <?= htmlspecialchars($avatar_tags[1]) ?></div>
                <div class="f-tag tag-l3"><i class="fa-solid fa-shield-halved"></i> <?= htmlspecialchars($avatar_tags[2]) ?></div>
                <div class="f-tag tag-l4"><i class="fa-solid fa-bug"></i> <?= htmlspecialchars($avatar_tags[3]) ?></div>

                <div class="f-tag tag-r1"><?= htmlspecialchars($avatar_tags[4]) ?> <i class="fa-solid fa-magnifying-glass"></i></div>
                <div class="f-tag tag-r2"><?= htmlspecialchars($avatar_tags[5]) ?> <i class="fa-solid fa-share-nodes"></i></div>
                <div class="f-tag tag-r3"><?= htmlspecialchars($avatar_tags[6]) ?> <i class="fa-solid fa-feather"></i></div>
                <div class="f-tag tag-r4"><?= htmlspecialchars($avatar_tags[7]) ?> <i class="fa-solid fa-book"></i></div>
            </div>
        </div>
        <h1 class="about-page-title">关于本站</h1>
    </div>

    <div class="bento-grid">
        
        <div class="bento-card bento-intro col-span-2">
            <div class="intro-badge">你好，很高兴认识你 👋</div>
            <h2>我叫 <?= htmlspecialchars($author_name) ?></h2>
            <p><?= htmlspecialchars($author_bio) ?></p>
        </div>

        <div class="bento-card bento-motto col-span-2">
            <span class="card-sm-title"><?= htmlspecialchars(conf('about_motto_tag', '追求')) ?></span>
            <h2><?= htmlspecialchars_decode(conf('about_motto_title', '源于<br>热爱而去创造')) ?></h2>
            <div class="motto-tag">代码与设计</div>
        </div>

        <div class="bento-card bento-skills col-span-2">
            <div class="skills-header">
                <span class="card-sm-title">技能栈</span>
                <h3>开启创造力</h3>
            </div>
            <div class="skills-scroll-area">
                <div class="bento-icon-scroll row-1">
                    <div class="bento-icon-item" style="background: #ff7675;"><i class="fa-brands fa-php"></i></div>
                    <div class="bento-icon-item" style="background: #74b9ff;"><i class="fa-brands fa-vuejs"></i></div>
                    <div class="bento-icon-item" style="background: #55efc4; color:#000;"><i class="fa-brands fa-js"></i></div>
                    <div class="bento-icon-item" style="background: #a29bfe;"><i class="fa-solid fa-database"></i></div>
                    <div class="bento-icon-item" style="background: #fdcb6e; color:#000;"><i class="fa-brands fa-html5"></i></div>
                    <div class="bento-icon-item" style="background: #6c5ce7;"><i class="fa-brands fa-css3-alt"></i></div>
                    <div class="bento-icon-item" style="background: #e17055;"><i class="fa-brands fa-figma"></i></div>
                    <div class="bento-icon-item" style="background: #ff7675;"><i class="fa-brands fa-php"></i></div>
                    <div class="bento-icon-item" style="background: #74b9ff;"><i class="fa-brands fa-vuejs"></i></div>
                    <div class="bento-icon-item" style="background: #55efc4; color:#000;"><i class="fa-brands fa-js"></i></div>
                    <div class="bento-icon-item" style="background: #a29bfe;"><i class="fa-solid fa-database"></i></div>
                    <div class="bento-icon-item" style="background: #fdcb6e; color:#000;"><i class="fa-brands fa-html5"></i></div>
                    <div class="bento-icon-item" style="background: #6c5ce7;"><i class="fa-brands fa-css3-alt"></i></div>
                    <div class="bento-icon-item" style="background: #e17055;"><i class="fa-brands fa-figma"></i></div>
                </div>
                <div class="bento-icon-scroll row-2">
                    <div class="bento-icon-item" style="background: #e84393;"><i class="fa-solid fa-server"></i></div>
                    <div class="bento-icon-item" style="background: #d63031;"><i class="fa-brands fa-git-alt"></i></div>
                    <div class="bento-icon-item" style="background: #f1c40f; color:#000;"><i class="fa-brands fa-linux"></i></div>
                    <div class="bento-icon-item" style="background: #00cec9;"><i class="fa-solid fa-shield-halved"></i></div>
                    <div class="bento-icon-item" style="background: #2d3436;"><i class="fa-solid fa-terminal"></i></div>
                    <div class="bento-icon-item" style="background: #0984e3;"><i class="fa-brands fa-bootstrap"></i></div>
                    <div class="bento-icon-item" style="background: #00a8ff;"><i class="fa-brands fa-docker"></i></div>
                    <div class="bento-icon-item" style="background: #e84393;"><i class="fa-solid fa-server"></i></div>
                    <div class="bento-icon-item" style="background: #d63031;"><i class="fa-brands fa-git-alt"></i></div>
                    <div class="bento-icon-item" style="background: #f1c40f; color:#000;"><i class="fa-brands fa-linux"></i></div>
                    <div class="bento-icon-item" style="background: #00cec9;"><i class="fa-solid fa-shield-halved"></i></div>
                    <div class="bento-icon-item" style="background: #2d3436;"><i class="fa-solid fa-terminal"></i></div>
                    <div class="bento-icon-item" style="background: #0984e3;"><i class="fa-brands fa-bootstrap"></i></div>
                    <div class="bento-icon-item" style="background: #00a8ff;"><i class="fa-brands fa-docker"></i></div>
                </div>
                <div class="bento-icon-scroll row-3">
                    <div class="bento-icon-item" style="background: #3776ab;"><i class="fa-brands fa-python"></i></div>
                    <div class="bento-icon-item" style="background: #61dafb; color:#000;"><i class="fa-brands fa-react"></i></div>
                    <div class="bento-icon-item" style="background: #333333;"><i class="fa-brands fa-github"></i></div>
                    <div class="bento-icon-item" style="background: #cb3837;"><i class="fa-brands fa-npm"></i></div>
                    <div class="bento-icon-item" style="background: #68a063;"><i class="fa-brands fa-node-js"></i></div>
                    <div class="bento-icon-item" style="background: #f29111;"><i class="fa-solid fa-database"></i></div>
                    <div class="bento-icon-item" style="background: #2c8ebb;"><i class="fa-solid fa-code-branch"></i></div>
                    <div class="bento-icon-item" style="background: #3776ab;"><i class="fa-brands fa-python"></i></div>
                    <div class="bento-icon-item" style="background: #61dafb; color:#000;"><i class="fa-brands fa-react"></i></div>
                    <div class="bento-icon-item" style="background: #333333;"><i class="fa-brands fa-github"></i></div>
                    <div class="bento-icon-item" style="background: #cb3837;"><i class="fa-brands fa-npm"></i></div>
                    <div class="bento-icon-item" style="background: #68a063;"><i class="fa-brands fa-node-js"></i></div>
                    <div class="bento-icon-item" style="background: #f29111;"><i class="fa-solid fa-database"></i></div>
                    <div class="bento-icon-item" style="background: #2c8ebb;"><i class="fa-solid fa-code-branch"></i></div>
                </div>
            </div>
            <div class="skills-footer">
                <span>前端开发</span><span class="dot"></span>
                <span>后端架构</span><span class="dot"></span>
                <span>UI设计</span><span class="dot"></span>
                <span>服务器运维</span>
            </div>
        </div>

        <div class="bento-card bento-experience col-span-2">
            <span class="card-sm-title">生涯</span>
            <h3>无限进步</h3>
            
            <div class="career-legend">
                <div class="legend-item"><span class="lg-dot bg-blue"></span> 计算机科学与技术专业</div>
                <div class="legend-item"><span class="lg-dot bg-red"></span> UI / 全栈 / 独立开发</div>
            </div>

            <div class="career-chart-area">
                <div class="career-chart-scroll">
                    <div class="career-timeline">
                        <?php foreach($career_events as $event): ?>
                        <div class="t-bar <?= htmlspecialchars($event['color']) ?>" style="left: <?= htmlspecialchars($event['left']) ?>%; width: <?= htmlspecialchars($event['width']) ?>%; top: <?= htmlspecialchars($event['top']) ?>px;">
                            <div class="t-label <?= htmlspecialchars($event['pos']) ?>"><i class="fa-solid <?= htmlspecialchars($event['icon']) ?>"></i> <?= htmlspecialchars($event['title']) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="career-axis">
                        <div class="axis-line"></div>
                        <?php foreach($career_axis as $point): ?>
                        <span class="t-year" style="left: <?= htmlspecialchars($point['left']) ?>%;"><?= htmlspecialchars($point['text']) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="bento-card bento-mbti col-span-1">
            <span class="card-sm-title">性格</span>
            <h3><?= htmlspecialchars(conf('about_mbti_name', '调停者')) ?><br>
                <?= htmlspecialchars(conf('about_mbti_type', 'INFP-T')) ?>
            </h3>
            <i class="fa-solid <?= htmlspecialchars(conf('about_mbti_icon', 'fa-leaf')) ?> mbti-icon"></i>
        </div>

        <div class="bento-card bento-photo col-span-1" style="background-image: url('<?= htmlspecialchars(conf('about_photo_bg', 'https://placehold.co/400x400/eeeeee/cccccc?text=400x400+Photo')) ?>');">
        </div>

        <div class="bento-card bento-tag col-span-1">
            <span class="card-sm-title">信仰</span>
            <h3><?= htmlspecialchars_decode(conf('about_belief_title', '披荆斩棘之路，<br>劈风斩浪。')) ?></h3>
        </div>
        
        <div class="bento-card bento-tag col-span-1">
            <span class="card-sm-title">特长</span>
            <h3><?= htmlspecialchars_decode(conf('about_specialty_title', '高质感 UI<br>全栈开发<br>折腾能力 大师级')) ?></h3>
        </div>

        <div class="bento-card bento-hobby col-span-2" style="background-image: url('<?= htmlspecialchars(conf('about_game_bg', 'https://placehold.co/600x300/a8c0ff/ffffff?text=Gaming')) ?>');">
            <div class="hobby-overlay">
                <span class="card-sm-title" style="color: rgba(255,255,255,0.8);">游戏热爱</span>
                <h3 style="color: #ffffff;"><?= htmlspecialchars(conf('about_game_title', '单机与主机游戏')) ?></h3>
            </div>
        </div>

        <div class="bento-card bento-hobby col-span-2" style="background-image: url('<?= htmlspecialchars(conf('about_tech_bg', 'https://placehold.co/600x300/ffecd2/ffffff?text=Tech')) ?>');">
            <div class="hobby-overlay">
                <span class="card-sm-title" style="color: rgba(255,255,255,0.8);">数码科技</span>
                <h3 style="color: #ffffff;"><?= htmlspecialchars(conf('about_tech_title', '极客外设控')) ?></h3>
            </div>
        </div>

        <div class="bento-card bento-anime col-span-2">
            <div class="anime-grid">
                <?php foreach($anime_covers as $cover): ?>
                    <div class="anime-item" style="background-image: url('<?= htmlspecialchars($cover) ?>');"></div>
                <?php endforeach; ?>
            </div>
            <div class="hobby-overlay pointer-pass">
                <span class="card-sm-title" style="color: rgba(255,255,255,0.8);">近期追番 / 剧集</span>
                <h3 style="color: #ffffff;">动漫与热播剧</h3>
            </div>
        </div>

        <div class="bento-card bento-music col-span-2" style="background-image: url('<?= htmlspecialchars(conf('about_music_bg', 'https://placehold.co/600x300/3b82f6/ffffff?text=Music+Cover')) ?>');">
            <div class="music-overlay">
                <div class="music-top-text">
                    <span class="card-sm-title" style="color: rgba(255,255,255,0.8);">音乐偏好</span>
                    <h3 class="music-title"><?= htmlspecialchars(conf('about_music_title', '许嵩、民谣、华语流行')) ?></h3>
                </div>
                <div class="music-bottom-bar">
                    <span class="music-desc">跟 <?= htmlspecialchars($author_name) ?> 一起欣赏更多音乐</span>
                    <a href="/music" class="music-btn">更多推荐 <i class="fa-solid fa-arrow-right"></i></a>
                </div>
            </div>
        </div>

        <div class="bento-card bento-stats col-span-1">
            <span class="card-sm-title">建站数据</span>
            <h3>访问统计</h3>
            <div class="stats-list">
                <div class="stat-box">
                    <span class="stat-num"><?= number_format($stats_today) ?></span>
                    <span class="stat-label">文章总数</span>
                </div>
                <div class="stat-box">
                    <span class="stat-num"><?= number_format($stats_month) ?></span>
                    <span class="stat-label">互动评论</span>
                </div>
                <div class="stat-box">
                    <span class="stat-num"><?= number_format($stats_total) ?></span>
                    <span class="stat-label">总阅读量</span>
                </div>
            </div>
        </div>

        <div class="bento-card bento-location col-span-3">
            <div class="loc-map-bg"></div>
            <div class="loc-content">
                <div class="loc-current">
                    <i class="fa-solid fa-location-dot"></i> 我现在住在 <strong><?= htmlspecialchars(conf('about_location_city', '中国')) ?></strong>
                </div>
                <div class="loc-details">
                    <div class="loc-item">
                        <i class="fa-solid fa-cake-candles"></i>
                        <span><?= htmlspecialchars(conf('about_loc_birth', '199X 出生')) ?></span>
                    </div>
                    <div class="loc-item">
                        <i class="fa-solid fa-graduation-cap"></i>
                        <span><?= htmlspecialchars(conf('about_loc_major', '产品设计 / 计算机')) ?></span>
                    </div>
                    <div class="loc-item">
                        <i class="fa-solid fa-laptop-code"></i>
                        <span><?= htmlspecialchars(conf('about_loc_job', 'UI设计 / 全栈开发')) ?></span>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <div class="bento-card about-footer-block">
        <span class="card-sm-title">心路历程</span>
        <h2>为什么建站？</h2>
        <div class="story-content">
            <?= htmlspecialchars_decode(conf('about_journey_content', '<p>建立这个站点的初衷，是希望有一个属于自己的<strong>数字后花园</strong>。在这里，我可以不受限于各大平台的规则，自由地分享技术、沉淀思考、记录生活。</p><p>从前端 UI 的打磨，到后端 PHP + MySQL 的底层架构，再到 Redis 缓存优化与 WAF 安全机制的引入，开发 <strong>BKCS 系统</strong> 的每一行代码都是一次创造的乐趣。网络世界瞬息万变，希望这里能成为记录我个人成长轨迹的最安稳的港湾。</p><p>这也是我与世界沟通的桥梁，感谢你能访问这里，愿我们共同留下美好的记忆。</p>')) ?>
        </div>
    </div>
</div>
<?php require_once 'includes/footer.php'; ?>