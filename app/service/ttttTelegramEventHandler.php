<?php
namespace app\service;

use danog\MadelineProto\EventHandler;
use think\facade\Log;
use think\facade\Db;

/**
 * TelegramEventHandler 类
 *
 * 用于处理接收到的更新消息。
 * 此类由 TelegramService 通过 Settings 对象的 update_callback 调用 onUpdate() 方法处理更新。
 */
class TelegramEventHandler extends EventHandler
{
    // 可选的任务数据（用于关键词匹配等）
    protected $task;

    /**
     * 设置任务数据
     *
     * @param array $task 任务数据
     */
    public function setTask(array $task)
    {
        $this->task = $task;
    }

    /**
     * 当收到更新时调用此方法
     *
     * @param array $update 更新数据
     */
    public function onUpdate($update)
    {
        Log::info('Telegram 消息');
        // 判断更新中是否包含消息
        if (isset($update['message'])) {
            $message = $update['message'];

            // 记录消息日志
            Log::info('Telegram 消息', [
                'message_id' => $message['id'],
                'chat_id'    => $message['chat_id'],
                'from_id'    => $message['from_id'],
                'message'    => $message['message'],
                'created_at' => date('Y-m-d H:i:s')
            ]);

            // 如果存在任务数据，则进行关键词匹配处理
            /*if ($this->task && $this->matchKeywords($message['message'])) {
                // 将匹配到的消息保存到数据库
                Db::name('monitor_messages')->insert([
                    'task_id'    => $this->task['id'],
                    'message_id' => $message['id'],
                    'chat_id'    => $message['chat_id'],
                    'from_id'    => $message['from_id'],
                    'message'    => $message['message'],
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            }*/
        }
    }
    /**
     * 处理新消息事件
     *
     * @param array $update 更新数据
     */
    public function onUpdateNewMessage(array $update): void
    {
        if (isset($update['message'])) {
            $message = $update['message'];
            $messageInfo = [
                'message_id' => $message['id']?? null,
                'chat_id'    => $message['chat']['id']?? null,
                'from_id'    => $message['from']['id']?? null,
                'text'       => $message['message']?? null,
                'date'       => date('Y-m-d H:i:s', $message['date']?? time())
            ];
            // 记录日志
            Log::info('Received Telegram message', $messageInfo);
        }
    }

    /**
     * 检查消息是否包含任务中定义的关键词
     *
     * @param string $text 消息内容
     * @return bool 如果匹配返回 true，否则返回 false
     */
    protected function matchKeywords($text)
    {
        $keywords = explode(',', $this->task['keywords']);
        foreach ($keywords as $keyword) {
            if (strpos($text, trim($keyword)) !== false) {
                return true;
            }
        }
        return false;
    }
}