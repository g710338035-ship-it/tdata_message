<?php
//已优化
namespace app\robot\controller;

use think\facade\Db;
use think\facade\Log;
use think\facade\Cache;
class MessagePhotoJob extends Apibot
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

        $this->storeMessageToRedis($chatId, $messageId, $data); // 保存消息到 Redis

        if ($this->isUserAdmin($token, $userId, $chatId)) {
            $this->processPhotoMessage($chatId, $userId, $token, $messageId, $data);
        }

    }

    /**
     * 处理图片消息的逻辑
     * @param Redis $redis
     * @param int $chatId
     * @param int $userId
     * @param string $token
     * @param int|null $messageId
     * @param array $data 消息数据
     */
    private function processPhotoMessage($chatId, $userId, $token, $messageId, $data)
    {
        $waiting = 'waiting_for_message';
        $redisKeyStatus = "customadd:$chatId.$userId.photo:add_status";
        $redisHashKeyMessage = "customadd:$chatId.$userId.photo:addmessage";

        if ($this->redis->sismember($redisKeyStatus, $waiting) && isset($data['photo'])) {
            $photo = json_encode($data['photo']);
            $caption = $data['caption'] ?? '设置了图片';

            if ($this->addPhotoToCustomMessages($photo, $chatId, $userId, $token, $messageId, $caption)) {
                $this->sendConfirmationMessage($token, $chatId, $messageId, $caption);
                $this->redis->srem($redisKeyStatus, $waiting);
                $this->redis->del($redisHashKeyMessage);
            }
        }
    }

    /**
     * 向数据库添加自定义图片消息
     * @param string $photo
     * @param int $groupId
     * @param int $userId
     * @param string $token
     * @param int|null $messageId
     * @param string $caption
     * @return bool
     */
    private function addPhotoToCustomMessages($photo, $groupId, $userId, $token, $messageId, $caption)
    {
        $settings = Db::name('xxsetting')->where('group_id', $groupId)->find();
        
        if ($settings && $settings['send_time']) {
            $data = [
                'send_time'       => $settings['send_time'],
                'nexttime'        => $settings['send_time'],
                'content'         => $caption,
                'photo'           => $photo,
                'is_top'          => $settings['is_top'],
                'repeat_interval' => $settings['repeat_interval'] > 0 ? $settings['repeat_interval'] : 1440,
                'status'          => 0,
                'create_time'     => time(),
                'username'        => $userId,
                'group_id'        => $groupId,
                'token'           => $token,
                'message_id'      => $messageId,
            ];

            if (Db::name('xiaoxi')->insert($data)) {
                Db::name('xxsetting')->where('group_id', $groupId)
                    ->update(['send_time' => null, 'repeat_interval' => null, 'is_top' => 0, 'is_del' => 0]);
                return true;
            }
        }
        
        return false;
    }

    /**
     * 检查用户是否为群组管理员
     * @param string $token
     * @param int $userId
     * @param int $chatId
     * @return bool
     */
    private function isUserAdmin($token, $userId, $chatId)
    {
        $botinfo=Cache::store('redis')->get($this->cacheBot);
        return $botinfo && $botinfo['chat_id'] == $userId;
    }

    /**
     * 存储消息到 Redis 中以便后续清理
     * @param int $chatId
     * @param int|null $messageId
     * @param array $message 消息数据
     */
    private function storeMessageToRedis($chatId, $messageId, $message)
    {
        
        if ($messageId) {
            $redisKey = "group_messages:$chatId";
            $this->redis->rpush($redisKey, json_encode(['message_id' => $messageId, 'message' => $message]));
        }
    }

    /**
     * 发送确认消息
     * @param Redis $redis
     * @param string $token
     * @param int $chatId
     * @param int|null $messageId
     * @param string $caption
     */
    private function sendConfirmationMessage($token, $chatId, $messageId, $caption)
    {
        $replyMarkup = json_encode(['inline_keyboard' => [[
            ['text' => '🔙 返回', 'callback_data' => '/custom_message_start'],
            ['text' => '❌关闭', 'callback_data' => '/closeMessage']
        ]]]);
        
        $content = [
            'chat_id'      => $chatId,
            'text'         => "🕑 重发消息\n\n👉🏻 设置图片成功。\n\n $caption",
            'reply_markup' => $replyMarkup,
            'message_id'   => $messageId,
            'parse_mode'   => 'MarkdownV2'
        ];

        send($token, 'sendMessage', $content);
    }
}
