<?php
//已优化
namespace app\robot\controller;

use think\facade\Log;
use think\facade\Cache;

class Floodcontroljob extends Apibot
{
    // 消息频率限制的阈值（单位：秒）
   /* const FLOOD_TIME_LIMIT = 3;
    const FLOOD_MESSAGE_COUNT = 5;
    const MUTE_DURATION = 3600; // 禁言时长（单位：秒）*/
    const CACHE_EXPIRY = 60; // 缓存过期时间（单位：秒）
    protected $cacheBot;
    
    public function __construct()
    {
        parent::__construct();
        //$this->index();
        $this->cacheBot = $this->cacheBot;
    }
    
    public function handleMessage($data)
    {
        try {
            // 处理消息
            $this->processMessage($data);
            // 任务完成后删除
        } catch (\Exception $e) {
            Log::error("FloodControlJob 处理失败: " . $e->getMessage());
            // 可以选择重试任务或记录失败
           // $job->release(30); // 30秒后重试
        }
    }

    protected function processMessage($data)
    {
        
        $chatId = $data['chat_id'];
        $userId = $data['user_id'];
        $messageId = $data['message_id'];
        $currentTime = $data['time'];
        $token = $data['token'];
        
        $cacheKey = "botgroup_cache";
                // 从缓存获取数据
        $botGroups = Cache::store('redis')->get($cacheKey);
        
                // 如果缓存不存在，从数据库查询并写入缓存
        if ($botGroups === null) {
            $botGroups = Db::name('botgroup')->order("id desc")->select()->toArray(); // 查询所有 botgroup 数据
            Cache::store('redis')->set($cacheKey, $botGroups, 3600); // 缓存查询结果
        }
        $result='';
        // 在缓存的结果中查找符合条件的记录
        $groupInfo = null;
        foreach ($botGroups as $group) {
            if (strpos($group['node'], "$chatId,") !== false) {
                //$result= $group['id']; // 返回符合条件的 ID
                $groupInfo =$group;
                break;
            }
        }
       // Log::info("检测到刷屏行为，用户ID: $userId");
        if($groupInfo&&$groupInfo['isbw']==1){
         
        
        //$groupInfo = Cache::store('redis')->get($this->cacheBot);
        
        // 获取该用户的消息时间记录
        $key = $this->getUserCacheKey($userId);
        $messageRecords = Cache::store('redis')->get($key, []);
        $keyall = $this->getUserCacheKeyall($userId);
        $messageRecordsall = Cache::store('redis')->get($keyall, []);
        // 记录当前消息时间和消息 ID
        $messageRecords[] = [
            'time' => $currentTime,
            'message_id' => $messageId,
        ];
        
        $messageRecordsall[] = [
            'time' => $currentTime,
            'message_id' => $messageId,
        ];
        
        // 只保留最近 5 条记录
        if (count($messageRecords) > $groupInfo['bwnum']) {
            array_shift($messageRecords);
        }

        // 更新缓存
        Cache::store('redis')->set($key, $messageRecords, $groupInfo['bwtime']+2);
        
        Cache::store('redis')->set($keyall, $messageRecordsall, self::CACHE_EXPIRY);
        
        
       // Log::info("处理消息，来自: $chatId,-----". count($messageRecords).": ".$messageRecords[0]['time']);
        
        // 检查是否满足反刷屏条件
        if ($this->isFloodDetected($messageRecords, $currentTime,$groupInfo)) {
            // Log::info("处理消息: $chatId,-----". count($messageRecords).": ".$messageRecords[0]['time']);
            // 执行删除消息和禁言操作
            $this->handleFloodControl($chatId, $userId, $messageRecords, $token,$groupInfo,$messageRecordsall);
        }
        }
    }

    protected function isFloodDetected($messageRecords, $currentTime,$groupInfo)
    {
        $cc=$currentTime - $messageRecords[0]['time'];
       // Log::info("处理消息: -----". count($messageRecords).": ".$groupInfo['bwnum'].'---'.$cc.'**'.$groupInfo['bwtime']);
        // 检查 5 条消息是否在设定时间内发送
        return count($messageRecords) >= $groupInfo['bwnum'] &&
               isset($messageRecords[0]['time']) &&
               ($currentTime - $messageRecords[0]['time'] <= $groupInfo['bwtime']);
    }

    protected function handleFloodControl($chatId, $userId, $messageRecords, $token,$groupInfo,$messageRecordsall)
    {
        if($groupInfo['bwpin']==1){
            $this->tichuUser($chatId, $userId,$token);
            foreach ($messageRecordsall as $record) {
                if (isset($record['message_id'])) {
                    $this->deleteMessage($chatId, $record['message_id'], $token);
                }
            }
        }else{
            $this->restrictUser($chatId, $userId, $groupInfo['bwwarntime'], $token);
        }
        // 删除用户发送的所有消息
        if($groupInfo['bwisdel']==1){
            foreach ($messageRecordsall as $record) {
                if (isset($record['message_id'])) {
                    $this->deleteMessage($chatId, $record['message_id'], $token);
                }
            }
        }
        // 禁言用户
        
    }

    // 删除消息
    protected function deleteMessage($chatId, $messageId, $token)
    {
        $content = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ];

        $this->sendRequest($token, 'deleteMessage', $content);
    }
   // 踢出
    protected function tichuUser($chatId, $userId,  $token)
    {
     

        $content = [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ];

        $this->sendRequest($token, 'kickChatMember', $content);
        $content = array(
            'chat_id' => $chatId,
            'user_id' => $userId
        );
        $this->sendRequest($token, 'unbanChatMember', $content);
        
        
    }
    // 禁言用户
    protected function restrictUser($chatId, $userId, $duration, $token)
    {   if($duration==0){$duration=99999999;}
        $untilDate = time() + $duration*60;

        $content = [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'permissions' => ['can_send_messages' => false],
            'until_date' => $untilDate,
        ];

        $this->sendRequest($token, 'restrictChatMember', $content);
    }

    // 获取用户缓存键
    protected function getUserCacheKey($userId)
    {
        return "user_send_message_times_$userId";
    }
    protected function getUserCacheKeyall($userId)
    {
        return "user_send_message_timesall_$userId";
    }
    // 通用的发送请求方法
    protected function sendRequest($token, $method, $content)
    {
        try {
            send($token, $method, $content);
        } catch (\Exception $e) {
            Log::error("请求失败: " . $e->getMessage());
        }
    }
}
