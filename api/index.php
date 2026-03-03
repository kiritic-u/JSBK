<?php
/**
 * api/index.php - BKCS 现代化核心业务接口 (高性能、防刷增强版)
 */
ob_start();
require_once __DIR__ . '/../includes/config.php';
if (ob_get_length()) ob_end_clean();

error_reporting(0); 
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDB();
    $redis = function_exists('getRedis') ? getRedis() : null; 
} catch (Exception $e) {
    die(json_encode(['success' => false, 'msg' => '数据库连接失败']));
}

$action = $_GET['action'] ?? '';

// =========================================================================
// [模块 1] Redis 缓存辅助工具
// =========================================================================
function getCache($key) {
    global $redis;
    if (!$redis) return false;
    $data = $redis->get('bkcs:' . $key);
    return $data ? json_decode($data, true) : false;
}
function setCache($key, $data, $ttl = 600) {
    global $redis;
    if ($redis) $redis->setex('bkcs:' . $key, $ttl, json_encode($data));
}
function delCache($key) {
    global $redis;
    if ($redis) $redis->del('bkcs:' . $key);
}
function clearListCache() {
    global $redis;
    if (!$redis) return;
    $keys = $redis->keys('bkcs:list:*');
    if (!empty($keys)) { foreach ($keys as $k) $redis->del($k); }
}

// =========================================================================
// [模块 2] 全局接口防刷器 (Rate Limiter)
// =========================================================================
function checkRateLimit($action_name, $limit_seconds = 3) {
    // 管理员不受限
    if (!empty($_SESSION['admin_logged_in'])) return true; 
    
    $session_key = 'last_req_' . $action_name;
    $now = time();
    if (isset($_SESSION[$session_key]) && ($now - $_SESSION[$session_key] < $limit_seconds)) {
        $remain = $limit_seconds - ($now - $_SESSION[$session_key]);
        echo json_encode(['success' => false, 'msg' => "操作太快了，请 {$remain} 秒后再试"]);
        exit;
    }
    $_SESSION[$session_key] = $now;
    return true;
}

// =========================================================================
// [模块 3] 业务控制器映射 (取代冗长的 if-else)
// =========================================================================

switch ($action) {

    // ---------------------------------------------------------
    // 1. 获取文章列表 (已修复 N+1 查询黑洞，使用 LEFT JOIN)
    // ---------------------------------------------------------
    case 'get_list':
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $cat = $_GET['category'] ?? 'all';
        $key = $_GET['keyword'] ?? '';
        $cacheKey = "list:p{$page}_c{$cat}_k" . md5($key);

        if ($data = getCache($cacheKey)) { echo json_encode($data); exit; }

        $limit = 6; $offset = ($page - 1) * $limit;
        $where = "WHERE a.is_hidden = 0"; $params = [];
        if ($cat !== 'all') { $where .= " AND a.category = ?"; $params[] = $cat; }
        if ($key) { $where .= " AND (a.title LIKE ? OR a.summary LIKE ?)"; $params[] = "%$key%"; $params[] = "%$key%"; }

        $stmt_c = $pdo->prepare("SELECT COUNT(*) FROM articles a $where");
        $stmt_c->execute($params);
        $total_pages = ceil($stmt_c->fetchColumn() / $limit);

        // 【核心优化】：使用 LEFT JOIN 和 GROUP BY，1次查询解决文章数据和评论数汇总
        $sql = "SELECT a.*, COUNT(c.id) as comments_count 
                FROM articles a 
                LEFT JOIN comments c ON a.id = c.article_id 
                $where 
                GROUP BY a.id 
                ORDER BY a.is_recommended DESC, a.created_at DESC 
                LIMIT $offset, $limit";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($list as &$a) {
            $a['date'] = date('m-d', strtotime($a['created_at']));
            $a['title'] = htmlspecialchars($a['title']);
            // 抹除敏感信息
            unset($a['password']); 
        }

        $res = ['articles' => $list, 'total_pages' => $total_pages, 'current_page' => $page];
        setCache($cacheKey, $res);
        echo json_encode($res);
        break;

    // ---------------------------------------------------------
    // 2. 获取文章详情 (附带评论及用户头像)
    // ---------------------------------------------------------
    case 'get_article':
        $id = intval($_GET['id']);
        // 防止刷阅读量
        if (!isset($_SESSION['viewed_art_'.$id])) {
            $pdo->prepare("UPDATE articles SET views = views + 1 WHERE id = ?")->execute([$id]);
            $_SESSION['viewed_art_'.$id] = true;
            delCache("article:$id"); // 清缓存
        }

        if (!($art = getCache("article:$id"))) {
            $stmt = $pdo->prepare("SELECT * FROM articles WHERE id = ?");
            $stmt->execute([$id]);
            $art = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($art) setCache("article:$id", $art, 3600);
        }

        if ($art) {
            // 获取评论及真实头像
            $stmt = $pdo->prepare("
                SELECT c.username, c.content, c.created_at, u.avatar 
                FROM comments c 
                LEFT JOIN users u ON c.user_id = u.id 
                WHERE c.article_id = ? 
                ORDER BY c.id DESC
            ");
            $stmt->execute([$id]);
            $art['comments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $art['is_liked'] = false;
            if (isset($_SESSION['user_id'])) {
                $stmt = $pdo->prepare("SELECT id FROM article_likes WHERE user_id=? AND article_id=?");
                $stmt->execute([$_SESSION['user_id'], $id]);
                if ($stmt->fetch()) $art['is_liked'] = true;
            }
            
            // 确保获取最新点赞和浏览量
            $st_rt = $pdo->prepare("SELECT views, likes FROM articles WHERE id = ?");
            $st_rt->execute([$id]);
            $rt = $st_rt->fetch();
            $art['views'] = $rt['views']; $art['likes'] = $rt['likes'];
            
            // 密码保护逻辑
            $pwd = $_GET['pwd'] ?? '';
            if (!empty($art['password']) && $pwd !== $art['password']) {
                $art['require_password'] = true;
                $art['content'] = '';
                $art['media_data'] = '[]';
                $art['resource_data'] = '';
                $art['comments'] = [];
                $art['cover_image'] = ''; 
            } else {
                $art['require_password'] = false;
            }
            unset($art['password']);

            echo json_encode($art);
        } else { 
            echo json_encode(['error' => 'Not Found']); 
        }
        break;

    // ---------------------------------------------------------
    // 3. 提交评论
    // ---------------------------------------------------------
    case 'comment':
        checkRateLimit('comment', 30); // 30秒防刷

        if (!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false, 'msg'=>'请先登录']); break; }
        if (($_POST['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) { echo json_encode(['success'=>false, 'msg'=>'非法请求']); break; }

        $user_id = $_SESSION['user_id'];

        $stmt_user = $pdo->prepare("SELECT is_banned FROM users WHERE id = ?");
        $stmt_user->execute([$user_id]);
        if ($stmt_user->fetchColumn() == 1) { echo json_encode(['success'=>false, 'msg'=>'您的账号已被封禁']); break; }

        $article_id = intval($_POST['article_id']);
        $content = trim($_POST['content']);
        
        if (mb_strlen($content, 'UTF-8') < 2 || mb_strlen($content, 'UTF-8') > 500) { 
            echo json_encode(['success'=>false, 'msg'=>'内容长度需在 2-500 字之间']); break; 
        }

        $stmt = $pdo->prepare("INSERT INTO comments (article_id, username, content, user_id) VALUES (?, ?, ?, ?)");
        if ($stmt->execute([$article_id, $_SESSION['nickname'], htmlspecialchars($content), $user_id])) {
            clearListCache(); 
            delCache("article:$article_id"); 
            echo json_encode(['success' => true]);
        } else { 
            echo json_encode(['success' => false, 'msg' => '数据库写入失败']); 
        }
        break;

    // ---------------------------------------------------------
    // 4. 点赞
    // ---------------------------------------------------------
    case 'like':
        checkRateLimit('like', 2); // 2秒防刷
        $id = intval($_GET['id']);
        if (!isset($_SESSION['user_id'])) { echo json_encode(['success'=>false, 'msg'=>'请登录']); break; }
        
        $uid = $_SESSION['user_id'];
        $check = $pdo->prepare("SELECT id FROM article_likes WHERE user_id=? AND article_id=?");
        $check->execute([$uid, $id]);
        
        if ($check->fetch()) {
            $pdo->prepare("DELETE FROM article_likes WHERE user_id=? AND article_id=?")->execute([$uid, $id]);
            $pdo->prepare("UPDATE articles SET likes = GREATEST(likes-1,0) WHERE id=?")->execute([$id]);
            $liked = false;
        } else {
            $pdo->prepare("INSERT INTO article_likes (user_id, article_id) VALUES (?,?)")->execute([$uid, $id]);
            $pdo->prepare("UPDATE articles SET likes = likes + 1 WHERE id=?")->execute([$id]);
            $liked = true;
        }
        delCache("article:$id"); clearListCache();
        $st = $pdo->prepare("SELECT likes FROM articles WHERE id=?"); $st->execute([$id]);
        echo json_encode(['success'=>true, 'new_likes'=>$st->fetchColumn(), 'liked'=>$liked]);
        break;

    // ---------------------------------------------------------
    // 5. 发送邮件验证码
    // ---------------------------------------------------------
    case 'send_email_code':
    case 'send_reset_code':
        checkRateLimit('email', 60); // 严格的 60秒发信防刷限制

        require_once __DIR__ . '/../includes/email_helper.php';
        $email = trim($_POST['email'] ?? '');
        $captcha = trim($_POST['captcha'] ?? '');
        
        if (empty($_SESSION['captcha_code']) || strtolower($captcha) !== strtolower($_SESSION['captcha_code'])) {
            echo json_encode(['success'=>false, 'msg'=>'图形码错误']); break;
        }

        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $exists = $stmt->fetch();

        if ($action == 'send_email_code' && $exists) { echo json_encode(['success'=>false, 'msg'=>'邮箱已注册']); break; }
        if ($action == 'send_reset_code' && !$exists) { echo json_encode(['success'=>false, 'msg'=>'邮箱未注册']); break; }

        $code = rand(100000, 999999);
        global $email_error_msg;
        if (sendEmailCode($email, $code)) {
            if ($action == 'send_email_code') { 
                $_SESSION['email_verify_code'] = $code; 
                $_SESSION['email_verify_addr'] = $email; 
            } else { 
                $_SESSION['reset_email_code'] = $code; 
                $_SESSION['reset_email_addr'] = $email; 
            }
            unset($_SESSION['captcha_code']);
            echo json_encode(['success' => true, 'msg' => '验证码已发送']);
        } else {
            echo json_encode(['success' => false, 'msg' => '发信失败: ' . $email_error_msg]);
        }
        break;

    // ---------------------------------------------------------
    // 6. 聊天室：获取消息
    // ---------------------------------------------------------
    case 'get_messages':
        $stmt = $pdo->query("
            SELECT c.id, c.user_id, c.message, c.created_at, u.nickname, u.avatar 
            FROM chat_messages c 
            LEFT JOIN users u ON c.user_id = u.id 
            ORDER BY c.id ASC 
            LIMIT 100
        ");
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $messages]);
        break;

    // ---------------------------------------------------------
    // 7. 聊天室：发送消息
    // ---------------------------------------------------------
    case 'send_message':
        checkRateLimit('chat', 5); // 聊天室发言间隔 5秒防刷屏

        if (!isset($_SESSION['user_id'])) { echo json_encode(['success' => false, 'msg' => '请先登录']); break; }
        if (($_POST['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) { echo json_encode(['success' => false, 'msg' => '非法请求']); break; }

        $user_id = $_SESSION['user_id'];
        $message = trim($_POST['message'] ?? '');

        if (empty($message)) { echo json_encode(['success' => false, 'msg' => '消息不能为空']); break; }
        if (mb_strlen($message, 'UTF-8') > 200) { echo json_encode(['success' => false, 'msg' => '消息太长了，精简一点吧']); break; }

        $stmt_user = $pdo->prepare("SELECT is_banned FROM users WHERE id = ?");
        $stmt_user->execute([$user_id]);
        if ($stmt_user->fetchColumn() == 1) { echo json_encode(['success' => false, 'msg' => '您的账号已被封禁']); break; }

        $stmt = $pdo->prepare("INSERT INTO chat_messages (user_id, message) VALUES (?, ?)");
        if ($stmt->execute([$user_id, htmlspecialchars($message)])) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'msg' => '发送失败，请重试']);
        }
        break;

    // 未知路由
    default:
        echo json_encode(['error' => 'Invalid action']);
        break;
}
?>