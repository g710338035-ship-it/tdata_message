<?php
//已优化
namespace app\job;

use think\queue\Job;
use think\facade\Db;
use think\facade\Log;

class BanwordsJob
{
    public function fire(Job $job, $data)
    {
        $message = $data['message'];
        $chatId = $data['group_id'];
        $token = $data['token'];
        $psid = $data['psid'];

        // 处理文本，调用对应的解析方法
        if ($psid == 1) {
            $this->processBanWords($message, $chatId, $token, $psid, '禁言词');
        } elseif ($psid == 2) {
            $this->processBanWords($message, $chatId, $token, $psid, '踢出词');
        }

        // 删除当前任务
        if ($job->isDeleted() === false) {
            $job->delete();
        }
    }

    /**
     * 处理禁言词或踢出词的添加
     */
    protected function processBanWords($message, $chatId, $token, $psid, $wordType)
    {
        $num = 0;
        $botId = $psid == 2 ? Db::name('telegrambot')->where('bot_token', $token)->value('bot_id') : null;

        // 按行分割消息
        $lines = explode("\n", trim($message));

        foreach ($lines as $line) {
            // 根据 psid 不同处理禁言词和踢出词的格式
            if ($psid == 1) {
                $parts = explode('+', $line);
                if (count($parts) !== 2) continue; // 格式不符则跳过
                list($word, $duration) = $parts;
                $duration = (int) trim($duration);
            } else {
                $word = trim($line);
                $duration = null;
            }

            $word = trim($word);
            if (!$this->wordExists($word, $chatId)) {
                $inserted = $this->insertBanWord($word, $psid, $chatId, $botId, $duration);
                if ($inserted) $num++;
            }
        }

        // 发送处理结果消息
        $this->sendMessage($token, $chatId, "添加成功{$num}个{$wordType}");
    }

    /**
     * 检查违禁词是否已存在
     */
    protected function wordExists($word, $chatId)
    {
        return Db::name('banwords')->where(['word' => $word, 'group_id' => $chatId])->find() !== null;
    }

    /**
     * 插入违禁词记录
     */
    protected function insertBanWord($word, $psid, $chatId, $botId = null, $duration = null)
    {
        $data = [
            'word' => $word,
            'psid' => $psid,
            'group_id' => $chatId,
            'create_time' => time()
        ];
        if ($duration !== null) $data['duration'] = $duration;
        if ($botId !== null) $data['bot_id'] = $botId;

        return Db::name('banwords')->insert($data) !== false;
    }

    /**
     * 发送消息
     */
    protected function sendMessage($token, $chatId, $text)
    {
        $messageData = [
            'chat_id' => $chatId,
            'text' => $text
        ];
        send($token, 'sendMessage', $messageData);
    }
}
