<?php

namespace app\service;

use danog\MadelineProto\API;
use danog\MadelineProto\EventHandler;
use think\facade\Log;

class MyEventHandler extends EventHandler
{
    /**
     * 必须实现：指定上报的目标（可以返回空数组）
     */
    public function getReportPeers()
    {
        return [];
    }

    /**
     * 监听并处理 Telegram 的更新
     */
    public function onUpdate(array $update)
    {
        try {
            if (isset($update['_'])) {
                switch ($update['_']) {
                    case "updateNewMessage":
                        $message = $update['message'];
                        if (!empty($message['message'])) {
                            $text = $message['message'];
                            $fromId = $message['from_id'];
                            $chatId = $message['peer_id']['_'] === 'peerUser' ? $message['peer_id']['user_id'] : $message['peer_id']['channel_id'];

                            Log::info("收到新消息 [ChatID: $chatId] [From: $fromId]: " . $text);

                            // 发送自动回复（示例）
                            yield $this->messages->sendMessage([
                                'peer' => $chatId,
                                'message' => "收到你的消息：$text"
                            ]);
                        }
                        break;
                    
                    case "updateUserStatus":
                        $userId = $update['user_id'];
                        $status = $update['status']['_'];
                        Log::info("用户状态更新: 用户 $userId 状态变更为 $status");
                        break;
                    
                    default:
                        Log::info("收到其他更新类型: " . json_encode($update, JSON_UNESCAPED_UNICODE));
                        break;
                }
            }
        } catch (\Exception $e) {
            Log::error("处理消息失败：" . $e->getMessage());
        }
    }
}
