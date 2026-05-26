<?php
//已优化
namespace app\robot\controller;

use think\facade\Db;
use think\facade\Log;
use app\BaseController;
use think\facade\Cache;
class Checkforbannedwords extends BaseController
{
    protected $cacheBot;
    public function __construct($id = null)
    {
       $this->cacheBot = 'telegram_bot_' .$id;
    }
    // 检查消息中的违禁词，并执行相应的惩罚
    public function checkForBannedWords($chatId, $text,$name, $userId, $token, $messageId)
    {
      // Log::info("检测到违禁词，执行惩罚: $text");
        $botinfo=Cache::store('redis')->get($this->cacheBot);
       if($botinfo){
           
            $bot_id=$botinfo['bot_id'];
            
            $cacheBanwordsKey = "kl_bg_kwfilter";
            // 获取缓存中的违禁词数据
            $cacheBanwordsData = Cache::store('redis')->get($cacheBanwordsKey);
        
                // 如果缓存中没有违禁词数据，从数据库查询并缓存
            if (!$cacheBanwordsData) {
                $cacheBanwordsData = Db::name('kwfilter')->select()->toArray();
                Cache::store('redis')->set($cacheBanwordsKey, $cacheBanwordsData, 3600); // 缓存1小时
            }
            
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
                foreach ($botGroups as $group) {
                    if (strpos($group['node'], "$chatId,") !== false) {
                        $result= $group['id']; // 返回符合条件的 ID
                        break;
                    }
                }
        
             if($result){
                // 遍历缓存中的违禁词数据，检查是否存在匹配的词
                foreach ($cacheBanwordsData as $banword) {
                  
                    // 检查是否符合 bot_id 或 group_id 与输入参数匹配
                    if ($banword['bot_id'] == $bot_id && strpos($text, $banword['keyword']) !== false&& $banword['type'] == 1&& $banword['bgid'] == $result ) {
                        $content = [
                                'chat_id' => $chatId,
                             'message_id' => $messageId
                            ];
                            send($token, 'deleteMessage', $content);
                        if($banword['psid']==1){
                             $this->muteUser($chatId, $userId, 48, $token);
                        }elseif($banword['psid']==2){
                            $this->kickUser($chatId, $userId, $token);
                        }elseif($banword['psid']==3){
                            $this->fjUser($chatId, $userId, $token);
                        }
                      
                        return true; // 检测到违禁词后直接返回
                    }
                    if ($banword['bot_id'] == $bot_id && strpos($name, $banword['keyword']) !== false&& $banword['type'] == 2&& $banword['bgid'] == $result ) {
                        $content = [
                             'chat_id' => $chatId,
                             'message_id' => $messageId
                            ];
                            send($token, 'deleteMessage', $content);
                        if($banword['psid']==1){
                             $this->muteUser($chatId, $userId, 48, $token);
                        }elseif($banword['psid']==2){
                            $this->kickUser($chatId, $userId, $token);
                        }elseif($banword['psid']==3){
                            $this->fjUser($chatId, $userId, $token);
                        }
                      
                        return true; // 检测到违禁词后直接返回
                    }
                }
                }
                return false; // 没有检测到违禁词
          
       }else{
          return false;  
       }
    }

    // 禁言用户
    private function muteUser($chatId, $userId, $punishTime, $token)
    {
        $untilDate = time() + $punishTime * 60; // 计算禁言时间，单位是分钟
        $content = [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'until_date' => $untilDate, // 禁言截止时间
            'permissions' => [
                'can_send_messages' => false, // 禁止发送文本消息
                'can_send_media_messages' => false, // 禁止发送多媒体消息
                //'can_send_polls' => false, // 禁止发送投票
                //'can_send_other_messages' => false, // 禁止发送其他类型的消息
                //'can_add_web_page_previews' => false, // 禁止添加网页预览
                //'can_change_info' => false, // 禁止更改群组信息
                //'can_invite_users' => false, // 禁止邀请用户
                //'can_pin_messages' => false // 禁止置顶消息
            ]
        ];

        // 发送禁言请求
        $response = $this->sendRequest($token, 'restrictChatMember', $content);
        
        
        
        // 可选：根据禁言结果发送反馈消息
       /* if ($response['ok']) {
            $this->sendFeedbackMessage($chatId, "用户已被禁言 {$punishTime} 分钟。", $token);
        } else {
            // 记录错误日志
            Log::error("禁言失败: " . json_encode($response));
            $this->sendFeedbackMessage($chatId, "禁言操作失败，错误信息: " . $response['description'], $token);
        }*/
    }
    // 禁言用户
    private function fjUser($chatId, $userId, $token)
    {
        $params = [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ];
        $this->sendRequest($token, 'banChatMember', $params);
        /*$untilDate = time() + $punishTime * 60; // 计算禁言时间，单位是分钟
        $content = [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'until_date' => $untilDate, // 禁言截止时间
            'permissions' => [
                'can_send_messages' => false, // 禁止发送文本消息
                'can_send_media_messages' => false, // 禁止发送多媒体消息
                //'can_send_polls' => false, // 禁止发送投票
                //'can_send_other_messages' => false, // 禁止发送其他类型的消息
                //'can_add_web_page_previews' => false, // 禁止添加网页预览
                //'can_change_info' => false, // 禁止更改群组信息
                //'can_invite_users' => false, // 禁止邀请用户
                //'can_pin_messages' => false // 禁止置顶消息
            ]
        ];

        // 发送禁言请求
        $response = $this->sendRequest($token, 'restrictChatMember', $content);*/
        
        
        
        // 可选：根据禁言结果发送反馈消息
       /* if ($response['ok']) {
            $this->sendFeedbackMessage($chatId, "用户已被禁言 {$punishTime} 分钟。", $token);
        } else {
            // 记录错误日志
            Log::error("禁言失败: " . json_encode($response));
            $this->sendFeedbackMessage($chatId, "禁言操作失败，错误信息: " . $response['description'], $token);
        }*/
    }
    // 踢出群组
    private function kickUser($chatId, $userId, $token)
    {
        $content = [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ];
        
        // 发送踢出请求
        $response = $this->sendRequest($token, 'kickChatMember', $content);
        
        $content = array(
            'chat_id' => $chatId,
            'user_id' => $userId
        );
        $response = send($token, 'unbanChatMember', $content);
        // 可选：根据踢出结果发送反馈消息
       /* if ($response['ok']) {
            $this->sendFeedbackMessage($chatId, "用户已被踢出群组。", $token);
        } else {
            // 记录错误日志
            Log::error("踢出失败: " . json_encode($response));
            $this->sendFeedbackMessage($chatId, "踢出操作失败，错误信息: " . $response['description'], $token);
        }*/
    }

    // 发送请求到 Telegram API
    private function sendRequest($token, $method, $data)
    {
        // 实现发送请求的逻辑
        return send($token, $method, $data); // 假设 send 是可用的函数
    }

    // 发送反馈消息
    private function sendFeedbackMessage($chatId, $text, $token)
    {
        $messageContent = [
            'chat_id' => $chatId,
            'text' => $text,
        ];
        
        $this->sendRequest($token, 'sendMessage', $messageContent);
    }
}
