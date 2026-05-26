<?php

namespace app\admin\controller;
use think\facade\Cache;
use think\facade\Log;
use app\BaseController;
class Test extends BaseController
{

public function checkCache()
{
 
    try {
        // 写入缓存
        Cache::set('cache_test', 'Cache is working!', 3600); // 有效期3600秒
        
        Cache::hSet("group:32:users", '234', json_encode([
                'username' => '234',
                'added_at' => date('Y-m-d H:i:s'),
            ]));
        $exists = Cache::hExists("group:32:users", '234'); 
        if ($exists) {
            echo "字段存在";
        } else {
            echo "字段不存在";
        }
        // 读取缓存
        $result = Cache::get('cache_test');

        if ($result === 'Cache is working!') {
            Log::info('Cache 正常工作');
              echo 1;
        } else {
            Log::error('Cache 读取失败或数据不匹配');
            return false;
        }
    } catch (\Exception $e) {
        Log::error('Cache 错误: ' . $e->getMessage());
        return false;
    }
}
}