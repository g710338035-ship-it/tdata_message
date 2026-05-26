<?php
//已优化
namespace app\job;

use think\queue\Job;
use app\common\RedisConnectionPool;
use think\facade\Log;

class GroupMessageDelJob
{
    // 设定最大重试次数
    const MAX_ATTEMPTS = 3;

    /**
     * 任务执行入口
     * @param Job $job
     * @param array $data 包含群组ID和消息ID的数组
     */
    public function fire(Job $job, $data)
    {
        $groupId = $data['group_id'];
        $botToken = $data['token'];

        // 尝试删除消息
        if ($this->deleteMessages($groupId, $botToken)) {
            $job->delete(); // 删除成功
        } else {
            if ($job->attempts() > self::MAX_ATTEMPTS) {
                $job->delete(); // 达到重试上限，删除任务
            } else {
                $job->release(10); // 10秒后重试
            }
        }
    }

    /**
     * 从 Redis 获取群组的消息ID
     * @param int $groupId
     * @return array 消息ID数组
     */
    protected function getMessagesFromRedis($groupId)
    {
        $redis = RedisConnectionPool::getConnection();
        return $redis->lRange("group_messages:$groupId", 0, -1); // 获取所有消息ID
    }

    /**
     * 删除群组中的消息
     * @param int $groupId
     * @param string $botToken
     * @return bool 是否成功删除所有消息
     */
    protected function deleteMessages($groupId, $botToken)
    {
        $redis = RedisConnectionPool::getConnection();
        $messageDataList = $this->getMessagesFromRedis($groupId);

        if (empty($messageDataList)) {
            return true; // 无消息时直接返回
        }

        try {
            foreach ($messageDataList as $messageData) {
                $message = json_decode($messageData, true);

                if (isset($message['message_id'])) {
                    $messageId = $message['message_id'];
                    //Log::info("正在删除消息ID: " . $messageId.'****'.$groupId);

                    $response = $this->sendDeleteMessageRequest($groupId, $messageId, $botToken);
                    $responseArray = json_decode($response, true);
                    $redis->lRem("group_messages:$groupId", $messageData, 0); 
                    if (isset($responseArray['ok']) && $responseArray['ok'] === true) {
                        $redis->lRem("group_messages:$groupId", $messageData, 0); // 成功后从Redis中移除
                    } else {
                        //Log::warning("删除消息失败，消息ID: $messageId, 响应: " . json_encode($responseArray));
                    }
                } else {
                  
                }
            }
            return true;
        } catch (\Exception $e) {
           
            return false;
        }
    }

    /**
     * 发送 HTTP 请求删除指定消息
     * @param int $chatId
     * @param int $messageId
     * @param string $botToken
     * @return string HTTP 响应
     */
    protected function sendDeleteMessageRequest($chatId, $messageId, $botToken)
    {
        $postData = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ];

        return send($botToken, 'deleteMessage', $postData);
    }

    /**
     * 任务失败执行
     * @param array $data
     */
    public function failed($data)
    {
        
    }
}
