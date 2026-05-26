<?php

namespace app\service;

use danog\MadelineProto\EventHandler;
use think\facade\Log;

class TelegramEventHandler extends EventHandler
{
    /**
     * 当收到新消息时调用
     *
     * @param array $update 更新数组
     * @return \Generator
     */
    public function onUpdateNewMessage(array $update): \Generator
    {
        if (isset($update['message'])) {
            Log::info("收到消息：" . json_encode($update['message'], JSON_UNESCAPED_UNICODE));
            // 如果需要调用 TelegramService 中的 logMessage()，可以在此处扩展代码
        }
        yield;
    }
}
