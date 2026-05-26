<?php

namespace app\common;

use Redis;
use think\Exception;

class RedisConnectionPool
{
    // 用于存储 Redis 实例的池
    protected static $instance;

    /**
     * 获取 Redis 连接
     * 如果已经有 Redis 实例，则复用现有连接
     * @return Redis
     * @throws Exception
     */
    public static function getConnection()
    {
        if (!isset(self::$instance)) {
            try {
                // 创建 Redis 连接
                $redis = new Redis();
                $redis->connect('127.0.0.1', 6379); // 根据实际 Redis 配置修改
                $redis->auth('6kE3zkytdzaKeGz7'); // 如果有密码需要认证
                $redis->select('3');
                self::$instance = $redis;
            } catch (\Exception $e) {
                throw new Exception('无法连接到 Redis: ' . $e->getMessage());
            }
        }

        return self::$instance;
    }

    /**
     * 关闭 Redis 连接
     */
    public static function closeConnection()
    {
        if (isset(self::$instance)) {
            self::$instance->close();
            self::$instance = null;
        }
    }
}


