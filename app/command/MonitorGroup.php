<?php
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;

class MonitorGroup extends Command
{
    protected function configure()
    {
        $this->setName('monitor:group')
             ->setDescription('Monitor multiple Telegram groups activity');
    }

    protected function execute(Input $input, Output $output)
    {
   
        // 从缓存中获取群组信息
        $groups = Cache::store('redis')->get('monitored_groups');

        // 如果缓存中没有群组信息，则从数据库中加载
        if (!$groups) {
            $groups = Db::name('telegraggroup')
                ->alias('tg') // telegraggroup 表别名
                ->join('telegrambot tb', 'tg.bot_id = tb.bot_id') // 关联 telegrambot 表
                ->field('tg.group_id as group_id, tg.title as group_name, tg.bot_id as bot_id, tb.bot_token as bot_token,tb.chat_id as botchat_id') // 选择需要的字段
                ->select()
                ->toArray();

            // 将群组信息存入缓存，缓存有效期为 1 小时
            Cache::store('redis')->set('monitored_groups', $groups, 3600);
        }

        foreach ($groups as $group) {
            $groupId = $group['group_id'];
            $groupName = $group['group_name'];
            $botToken = $group['bot_token'];
            $botchat_id = $group['botchat_id'];
            // 获取该群组的最后一次发言时间
            $lastActivity = Cache::store('redis')->get('last_group_activity_' . $groupId);
            // 获取该群组的通知状态
            $notificationStatus = Cache::store('redis')->get('group_notification_status_' . $groupId, 0);
            
            if ($lastActivity && (time() - $lastActivity) > 10) { // 1200秒 = 20分钟
                // 通知管理者
                if ($notificationStatus == 0) {
                    $content = [
                        'chat_id' => $botchat_id,
                        'text' => "群组 {$groupName} (ID: {$groupId}) 已经20分钟没有发言了！",
                    ];
                    send($botToken, 'sendMessage', $content);
                 // 更新通知状态为已发送
                    Cache::store('redis')->set('group_notification_status_' . $groupId, 1);
                   // log::info("群组 {$groupName} (ID: {$groupId}) 已经推送！");
                }else{
                    // log::info("群组 {$groupName} (ID: {$groupId}) 已经sfsf推送！");
                }
            }
        }

        $output->writeln('All groups activity monitored.');
    }
}