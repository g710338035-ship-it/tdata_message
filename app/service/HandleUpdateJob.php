<?php
namespace app\service;

use think\queue\Job;
use think\facade\Db;

/**
 * HandleUpdateJob 类
 *
 * 用于处理队列中的更新任务，对收到的消息进行关键词匹配，
 * 并将符合条件的消息保存到数据库中。
 */
class HandleUpdateJob
{
    // 更新数据
    protected $update;
    // 任务数据
    protected $task;

    /**
     * 构造函数
     *
     * @param array $update 更新数据
     * @param array $task   任务数据
     */
    public function __construct($update, $task)
    {
        $this->update = $update;
        $this->task   = $task;
    }

    /**
     * 处理队列任务的方法
     *
     * @param Job $job 队列任务对象
     */
    public function handle(Job $job)
    {
        // 判断更新中是否包含消息
        if (isset($this->update['message'])) {
            $message = $this->update['message'];

            // 如果消息内容包含任务中定义的关键词，则保存消息
            if ($this->matchKeywords($message['message'])) {
                Db::name('monitor_messages')->insert([
                    'task_id'    => $this->task['id'],
                    'message_id' => $message['id'],
                    'chat_id'    => $message['chat_id'],
                    'from_id'    => $message['from_id'],
                    'message'    => $message['message'],
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            }
        }
        // 任务处理完成后，将队列任务删除
        $job->delete();
    }

    /**
     * 检查消息内容是否包含任务中定义的关键词
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
