<?php
//已优化
namespace app\robot\controller;

use think\facade\Db;
use think\facade\Log;
use think\facade\Cache;
class Checkforward extends Apibot
{
    protected $cacheBot;
    
    public function __construct()
    {
        parent::__construct();
        //$this->index();
        $this->cacheBot = $this->cacheBot;
    }
    public function handle($data)
    {
        $userId = $data['fromid'];
        $chatId = $data['chat_id'];
        $messageId = $data['message_id'] ?? null;
       // log::write('23424324');
        $this->Checkforward($chatId,  $userId,  $messageId);
    }
    // 检查消息中的违禁词，并执行相应的惩罚
    public function Checkforward($chatId,  $userId,  $messageId)
    {
     
        $botinfo=Cache::store('redis')->get($this->cacheBot);
       if($botinfo){
           
            $bot_id=$botinfo['bot_id'];
            $token=$botinfo['bot_token'];
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
                        $result= $group['iszhuanfa']; // 返回符合条件的 ID
                        break;
                    }
                }
           // log::write('nnnnnn'.$result);
             if($result!=1){
                        $content = [
                                'chat_id' => $chatId,
                             'message_id' => $messageId
                            ];
                            send($token, 'deleteMessage', $content);
                        if($result==2){
                            log::write('aaaaaa');
                            $this->muteUser($chatId, $userId,48, $token);
                        } elseif($result==3){
                            $this->kickUser($chatId, $userId, $token);
                        }elseif($result==4){
                        $this->fjUser($chatId, $userId,  $token);
                    }
                      
                return true; // 检测到违禁词后直接返回  
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
            ]
        ];

        // 发送禁言请求
        $response = $this->sendRequest($token, 'restrictChatMember', $content);
      
    }
    // 禁封用户
    private function fjUser($chatId, $userId, $token)
    {
        $params = [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ];
        $this->sendRequest($token, 'banChatMember', $params);
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
