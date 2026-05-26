<?php
namespace app\robot\controller;

use think\facade\Db;
use think\facade\Log;
use think\facade\Queue;
use think\facade\Cache;
class Membermessage extends Apibot
{
    protected $cacheBot;
    
    public function __construct()
    {
        parent::__construct();
        //$this->index();
        $this->cacheBot = $this->cacheBot;
    }

    public function handleMessage($message)
    {
        // 使用消息中的 token，如果没有则使用默认的
        $token = isset($message['token']) ? $message['token'] : $this->token;

        // 检查是否为群组迁移事件
        /*if (isset($message['migrate_to_chat_id'])) {
            $oldChatId = $message['chat']['id'];
            $newSupergroupId = $message['migrate_to_chat_id'];
            $this->handleChatMigration($oldChatId, $newSupergroupId);
            log::write('Chat migrated from group to supergroup');
            return 'Chat migrated from group to supergroup';
        }elseif(isset($data['migrate_from_chat_id'])){
                $newSupergroupId = $data['chat']['id'];
                $oldChatId = $data['migrate_from_chat_id'];
                // 处理群组 ID 迁移的逻辑
                $this->handleChatMigration($oldChatId, $newSupergroupId);
             log::write('Chat supergroup');    
               return 'Chat migrated from group to supergroup';
        }*/
    
        $chatId = $message['chat']['id'];
        $text = $message['text'] ?? '未知';
        $messageId = $message['message_id'] ?? null;
        $chatType = $message['chat']['type'];
        $chatTitle = $message['chat']['title'];
        $fromUserId = $message['from']['id'];
        $last_name = isset($message['from']['last_name']) ? $message['from']['last_name'] : '';
        $name = $message['from']['first_name'] . $last_name;
      
           // Log::info("处理消息，来自用户: $fromUserId, 消息内容: $text");
 
            $bot = Cache::store('redis')->get($this->cacheBot);
            if($bot['qunzf']==1){
            $isMembe = $this->isUserMembe($token, $fromUserId, $chatId);
        
            if (!$isMembe) {
                if($text!='未知'){
                // 处理反刷屏
                if ($this->checkFloodmessage($chatId, $messageId, $fromUserId, $token)) {
                    //Log::info("检测到刷屏行为，用户ID: $fromUserId");
                    return;
                }
                // 检查消息中是否包含违禁词      
                if ($this->checkforbannedwords->checkForBannedWords($chatId, $text, $name,$fromUserId, $token, $messageId)) {
                   // Log::info("检测到违禁词，执行惩罚: $text");
                    return;
                }
                
                $chatUsername = $message['chat']['username'] ?? '';
                if (!empty($chatUsername)) {
                    $messageLink = "https://t.me/{$chatUsername}/{$messageId}";
                } else {
                    $messageLink = '无法生成链接（群组未设置用户名）';
                }
               // Log::info("检测到用户ID: ");
                $botinfo=Cache::store('redis')->get($this->cacheBot);
                
                $entities = isset($message['entities']) ? $message['entities'] : '';
                if($entities){
                  $text=$this->sendTextentities($text,$entities);  
                }
                
                $postData = [
                    'chat_id' => $botinfo['chat_id'],
                    'text' => "来自群组 {$chatTitle} {$chatId} 的消息:\n\n{$text}\n\n消息链接: {$messageLink}",
                    'parse_mode' => 'HTML'
                ];
                $rss=send($token, 'sendMessage', $postData);
                //log::info($rss);
              }
            }
        }
    }
    
    
    
    
    
    
    private function isUserMembe($token, $userId, $chatId)
    {
        $cacheMembe="telegram_membe";
        if (!Cache::store('redis')->has($cacheMembe)) {
       
           $rs = Db::name('membe')->where('status', 1)
            ->select()->toArray();
           Cache::store('redis')->set($cacheMembe, $rs, 3600);
        }
        
        $members =Cache::store('redis')->get($cacheMembe);
        
        // 判断 userId 是否在成员列表中
        foreach ($members as $member) {
            if ($member['user_id'] == $userId) {
                return true;
            }
        }
    
        return false;
       
    }
    // 处理群组迁移
    /*private function handleChatMigration($oldChatId, $newSupergroupId)
    {
        try {
        
            Db::name('telegraggroup')->where('group_id', $oldChatId)->update(['group_id' => $newSupergroupId,'type' => 'supergroup']);
            $cacheGroupkey="telegram_group_".$newSupergroupId;
            $rs = Db::name('telegraggroup')->where('group_id', $newSupergroupId)-> find();
            Cache::store('redis')->set($cacheGroupkey, $rs, 3600);
            
            $cacheKey = "botgroup_cache";
            $records = Db::name('botgroup')->field('id, node')->select();
    
            foreach ($records as $record) {
                $node = $record['node']; // 获取原始的 node 字段值
                $node = str_replace($oldChatId, $newSupergroupId, $node); // 替换旧ID为新ID
                // 更新数据库记录
                Db::name('botgroup')->where('id', $record['id'])->update(['node' => $node]);
            }
            
            $botGroups = Db::name('botgroup')->order("id desc")->select()->toArray(); // 查询所有 botgroup 数据
            Cache::store('redis')->set($cacheKey, $botGroups, 3600); // 缓存查询结果 
            
        } catch (\Exception $e) {
            Log::error("群组迁移失败: " . $e->getMessage());
        }
    }*/

    // 反刷屏检测
    private function checkFloodmessage($chatId, $messageId, $fromUserId, $token)
    {
       // Log::info("检测到执行惩罚: $messageId");
        
        $currentTime = time();

        // 将消息数据推入队列
        $jobData = [
            'chat_id'    => $chatId,
            'user_id'    => $fromUserId,
            'message_id' => $messageId,
            'time'       => $currentTime,
            'token'      => $token
        ];

        try {
            //Log::info("处理消息，来自用户: $chatId, 消息内容: $messageId");
            $Floodcontroljob = new \app\robot\controller\Floodcontroljob();
            if ($Floodcontroljob->handleMessage($jobData)) {
               // Log::info("检测到执行惩罚: $messageId");
                return;
            }
           /* Queue::push('app\job\FloodControlJob', $jobData);
            Log::info("反刷屏消息已推入队列，用户ID: $fromUserId, 消息ID: $messageId");*/
            return false;
        } catch (\Exception $e) {
            Log::error("反刷屏消息推送队列失败: " . $e->getMessage());
            return true;
        }
    }
    
    private function sendTextentities($message,$entities){
      
        $parsedMessage = '';
        $lastOffset = 0;
        
        // 遍历 entities 进行处理
        foreach ($entities as $entity) {
            // 提取 entity 的信息
            $offset = $entity['offset'];
            $length = $entity['length'];
            $type = $entity['type'];
        
            // 添加上一次 entity 到这次 entity 之间的普通文本
            $parsedMessage .= mb_substr($message, $lastOffset, $offset - $lastOffset);
        
            // 根据 entity 类型处理链接等特殊格式
            if ($type === 'text_link') {
                $url = $entity['url']; // 获取链接
                $text = mb_substr($message, $offset, $length); // 获取链接文字
                $parsedMessage .= "<a href=\"{$url}\">{$text}</a>"; // 拼接超链接
            } elseif ($type === 'url') {
                // 直接是 URL 的情况
                $url = mb_substr($message, $offset, $length);
                $parsedMessage .= "<a href=\"{$url}\">{$url}</a>";
            } else {
                // 其他类型（如粗体、斜体等）按普通文本处理
                $parsedMessage .= mb_substr($message, $offset, $length);
            }
        
            // 更新偏移量
            $lastOffset = $offset + $length;
        }
        
        // 添加最后一部分普通文本
        $parsedMessage .= mb_substr($message, $lastOffset);
        
        // 输出解析后的内容
        return $parsedMessage;

    }
}
