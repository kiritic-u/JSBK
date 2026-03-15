<?php
// includes/redis_helper.php
/**
 * 追求极致的美学 - Redis 缓存核心组件 (企业级优化版)
 */
require_once __DIR__ . '/config.php';

class Cache {
    
    // 1. 零开销状态检查 (请在 config.php 中定义 define('REDIS_ENABLED', true);)
    private static function isEnabled() {
        return defined('REDIS_ENABLED') && REDIS_ENABLED === true;
    }

    public static function get($key) {
        if (!self::isEnabled()) return false;

        $redis = getRedis();
        if (!$redis) return false;
        
        $val = $redis->get(CACHE_PREFIX . $key);
        // 增加容错：确保解析的确实是 JSON，防止脏数据导致报错
        if ($val) {
            $decoded = json_decode($val, true);
            return (json_last_error() === JSON_ERROR_NONE) ? $decoded : false;
        }
        return false;
    }

    public static function set($key, $data, $ttl = 3600) {
        if (!self::isEnabled()) return false;

        $redis = getRedis();
        if (!$redis) return false;

        return $redis->setex(CACHE_PREFIX . $key, $ttl, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    public static function del($key) {
        if (!self::isEnabled()) return false;

        $redis = getRedis();
        if (!$redis) return false;
        return $redis->del(CACHE_PREFIX . $key);
    }

    // 2. 核心性能优化：使用 SCAN 替代危险的 KEYS
    public static function clear($pattern) {
        if (!self::isEnabled()) return false;

        $redis = getRedis();
        if (!$redis) return false;
        
        $iterator = null;
        $fullPattern = CACHE_PREFIX . $pattern;
        
        // 每次迭代扫描 100 个 key，防止阻塞 Redis 主线程
        while ($keys = $redis->scan($iterator, $fullPattern, 100)) {
            if (!empty($keys)) {
                // 批量删除，极大提升性能
                call_user_func_array([$redis, 'del'], $keys); 
            }
        }
    }
}
?>