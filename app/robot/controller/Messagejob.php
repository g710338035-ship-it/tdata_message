<?php
//已优化
namespace app\robot\controller;

use think\facade\Db;
use think\facade\Log;
use think\facade\Cache;

class Messagejob extends Apibot
{
    protected $cacheBot;
    
    public function __construct()
    {
        parent::__construct();       
        $this->cacheBot = $this->cacheBot;
    }

    public function handle($data)
    {
        $token = $data['token'];
        $userId = $data['from']['id'];
        $chatId = $data['chat']['id'];
        $messageId = $data['message_id'] ?? null;
        $text = $data['text'] ?? '未知';
        $last_name = isset($data['from']['last_name']) ? $data['from']['last_name'] : '';
        $name = $data['from']['first_name'] . $last_name;

        // 将消息存储到 Redis，方便后续清理
        $this->storeMessageToRedis($chatId, $messageId, $data);
        // 检查消息中是否包含加密货币代码
        $bot=Cache::store('redis')->get($this->cacheBot);
        if($bot['isbi']==1){
        if (preg_match('/^[a-zA-Z]+$/', $text)) {           
            if ($this->checkforcryptoquery->checkForCryptoQuery($chatId, $text, $userId, $token, $messageId)) {
                return;
            }
        }
        }
        if ($this->checkforkeyword->checkForKeyword($chatId, $text, $userId, $token, $messageId)) {
            
                return;
            }
        // 判断用户是否为管理员
        $isAdmin = $this->isUserAdmin($token, $userId, $chatId);
        
        if ($isAdmin) {
            //Log::info("管理员消息处理");
            $data['messagetype'] = 1;
            $this->handleAdminMessage($data);
        } else {
            //Log::info("会员消息处理");
            $this->handleMemberMessage($data);
        }

    }

    /**
     * 检查用户是否为管理员
     */
    private function isUserAdmin($token, $userId, $chatId)
    {
        $botinfo=Cache::store('redis')->get($this->cacheBot);
        return $botinfo && $botinfo['chat_id'] == $userId;
    }

    /**
     * 处理管理员消息
     */
    private function handleAdminMessage($data)
    {
        if (isset($data['text'])) {
           /* if (strpos($data['text'], '/custom_message') !== false) {
                $CustomMessage = new \app\robot\controller\Custommessage();
                $CustomMessage->handle($data);
            } elseif (strpos($data['text'], '/custom_infoedit_') !== false) {
                $CustomMessageEdit = new \app\robot\controller\Custommessageedit();
                $CustomMessageEdit->handle($data);
            } else {*/
                /*if (strpos($data['text'], '/gsetting@') !== false) {
                    $parts = explode('@', $data['text']);
                    $data['text'] = $parts[0] ?? $data['text'];
                }*/
                $AdminMessage = new \app\robot\controller\Adminmessage();
                $AdminMessage->handleMessage($data);
           // }
        }elseif(isset($data['migrate_to_chat_id'])) {
                $oldChatId = $data['chat']['id'];
                $newSupergroupId = $data['migrate_to_chat_id'];
                // 处理群组 ID 迁移的逻辑
                $this->handleChatMigration($oldChatId, $newSupergroupId);
               // log::write('Chat migrated from group to supergroup');
                return 'Chat migrated from group to supergroup';
        }
    }

    /**
     * 处理会员消息
     */
    private function handleMemberMessage($data)
    {
        $MemberMessage = new \app\robot\controller\Membermessage();
        $MemberMessage->handleMessage($data);
    }

    /**
     * 将消息存储到 Redis，方便后续清理
     */
    private function storeMessageToRedis($chatId, $messageId, $message)
    {
        if ($messageId) {
            $redisKey = $this->generateRedisKey($chatId);
            $messageData = [
                'message_id' => $messageId,
                'message' => $message,
            ];
            
            $this->redis->rpush($redisKey, json_encode($messageData));
            $this->redis->expire($redisKey, 7 * 24 * 60 * 60); // 设置过期时间为7天
        }
    }

    /**
     * 生成 Redis 键名
     */
    private function generateRedisKey($chatId)
    {
        return "group_messages:$chatId";
    }
    
     // 处理群组迁移
    private function handleChatMigration($oldChatId, $newSupergroupId)
    {
        // 你可以在这里更新数据库中存储的群组 ID
        // 比如将旧的群组 ID 更新为新的超级群组 ID

        Db::name('telegraggroup')->where('group_id', $oldChatId)->delete();
        
        $redisKey = "group_messages:$oldChatId";
        Cache::store('redis')->delete($redisKey);  
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
       // Db::name('xxsetting')->where('group_id', $oldChatId)->update(['group_id' => $newSupergroupId]);
       // Db::name('xiaoxi')->where('group_id', $oldChatId)->update(['group_id' => $newSupergroupId]);    
       // Db::name('banwords')->where('group_id', $oldChatId)->update(['group_id' => $newSupergroupId]);  
        // 其他处理逻辑...
    }
}
