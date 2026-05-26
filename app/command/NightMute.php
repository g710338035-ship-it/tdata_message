<?php
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
use think\facade\Log;

class NightMute extends Command
{
    protected function configure()
    {
        $this->setName('night:mute')
             ->setDescription('Mute group members during night mode');
    }

    protected function execute(Input $input, Output $output)
    {
        $currentTime = date('H:i');

        // 获取所有群组信息
        $groups = Db::name('telegraggroup')->alias('g')
            ->field('g.group_id as group_id, b.starttime as starttime, b.endtime as endtime, b.bot_token as bot_token')
            ->join('telegrambot b', 'g.bot_id = b.bot_id')
            ->select();
        
        if (!$groups->isEmpty()) {
            foreach ($groups as $group) {
                Log::info("处理群组 ID: {$group['group_id']}");

                if ($this->isInNightMode($currentTime, $group['starttime'], $group['endtime'])) {
                    Log::info("禁言群组 {$group['group_id']}，Bot Token: {$group['bot_token']}");
                    $this->muteChatMembers($group['bot_token'], $group['group_id'], $group['endtime'], $output);
                } else {
                    Log::info("解除禁言群组 {$group['group_id']}");
                    $this->unmuteGroup($group['bot_token'], $group['group_id'], $output);
                }
            }
        } else {
            Log::info("未找到群组信息。");
        }
    }

    private function isInNightMode($currentTime, $startTime, $endTime)
    {
        if ($startTime < $endTime) {
            return ($currentTime >= $startTime && $currentTime < $endTime);
        }
        // 跨天禁言时间（例如 22:00 - 06:00）
        return ($currentTime >= $startTime || $currentTime < $endTime);
    }

    private function muteChatMembers($botToken, $chatId, $endTime, Output $output)
    {
        $content = [
            'chat_id' => $chatId,
            'permissions' => json_encode([
                'can_send_messages' => false,
                'can_send_media_messages' => false,
            ])
        ];
        
        try {
            $response = send($botToken, 'setChatPermissions', $content);
            Log::info("禁言响应：群组ID $chatId - " . json_encode($response));
        } catch (\Exception $e) {
            Log::error("禁言群组失败：群组ID $chatId - 错误信息：" . $e->getMessage());
        }
    }
    
    private function unmuteGroup($botToken, $chatId, Output $output)
    {
        $content = [
            'chat_id' => $chatId,
            'permissions' => json_encode([
                'can_send_messages' => true,
                'can_send_media_messages' => true,
            ])
        ];
        
        try {
            $response = send($botToken, 'setChatPermissions', $content);
            Log::info("解除禁言响应：群组ID $chatId - " . json_encode($response));
        } catch (\Exception $e) {
            Log::error("解除禁言失败：群组ID $chatId - 错误信息：" . $e->getMessage());
        }
    }
    
 
}
