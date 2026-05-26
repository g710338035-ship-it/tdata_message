<?php
// 已优化
namespace app\robot\controller;

use think\facade\Db;
use think\facade\Log;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Request;
use app\BaseController;
use danog\MadelineProto\API;
use danog\MadelineProto\Exception;

/**
 * Telegram机器人API
**/
class Mpapi extends BaseController
{
    private $madelineInstances = [];

    public function __construct()
    {
        $config = Config::get('madelineproto');
        $sessionPath = $config['session_path'] . md5('+917015142278') . '.madeline';
        $accountName = 'account1';
        $this->madelineInstances[$accountName] = new API($sessionPath);
    }

    public function startWebhook()
    {
        $config = Config::get('madelineproto');
        $sessionPath = $config['session_path'] . md5('+917015142278') . '.madeline';
        $accountName = 'account1';
        $this->madelineInstances[$accountName] = new API($sessionPath);
        $webhookUrl = "https://xieyihao.appleshop.life/robot/Mpapi/webhook";

        try {
            // 使用 MadelineProto 设置 Webhook URL
            $this->madelineInstances[$accountName]->setWebhook($webhookUrl);
            echo "Webhook URL for $accountName has been set to: $webhookUrl\n";
        } catch (Exception $e) {
            echo "Error setting webhook for $accountName: " . $e->getMessage() . "\n";
        }
    }

    // Webhook 用于接收 Telegram 消息
    public function webhook(Request $request)
    {
        // 获取 Webhook 传入的 JSON 数据
        $data = Request::instance()->getContent();
        $update = json_decode($data, true);

        // 记录接收到的原始数据，转换为 JSON 字符串方便查看
        Log::info(json_encode($update, JSON_PRETTY_PRINT));

        /*if ($update) {
            switch ($update['_']) {
                case 'updateNewMessage':
                    // 处理普通用户的新消息
                    $this->handleNewMessage($update);
                    break;
                case 'updateNewChannelMessage':
                    // 处理频道的新消息
                    $this->handleNewChannelMessage($update);
                    break;
                case 'updateEditMessage':
                    // 处理普通用户编辑的消息
                    $this->handleEditMessage($update);
                    break;
                case 'updateEditChannelMessage':
                    // 处理频道编辑的消息
                    $this->handleEditChannelMessage($update);
                    break;
                case 'updateDeleteMessages':
                    // 处理普通用户删除的消息
                    $this->handleDeleteMessages($update);
                    break;
                case 'updateDeleteChannelMessages':
                    // 处理频道删除的消息
                    $this->handleDeleteChannelMessages($update);
                    break;
                case 'updateUserStatus':
                    // 处理用户状态更新
                    $this->handleUserStatusUpdate($update);
                    break;
                case 'updateChatParticipants':
                    // 处理普通聊天成员更新
                    $this->handleChatParticipantsUpdate($update);
                    break;
                case 'updateChannelParticipants':
                    // 处理频道成员更新
                    $this->handleChannelParticipantsUpdate($update);
                    break;
                default:
                    Log::info("Unhandled update type: " . $update['_']);
            }
        }*/

        return json(['status' => 'success']);
    }

    // 处理普通用户的新消息
    private function handleNewMessage($update)
    {
        $message = $update['message'];
        if (isset($message['chat']) && isset($message['text'])) {
            $chat_id = $message['chat']['id'];
            $text = $message['text'];

            // 打印接收到的消息
            Log::info("Received new message: $text from chat_id: $chat_id");

            // 选择要使用的账户
            $accountToUse = 'account1';
            // 回复消息
            $this->sendMessage($accountToUse, $chat_id, "Received your new message: " . $text);
        }
    }

    // 处理频道的新消息
    private function handleNewChannelMessage($update)
    {
        $message = $update['message'];
        if (isset($message['peer_id']) && isset($message['message'])) {
            $chat_id = $message['peer_id'];
            $text = $message['message'];

            // 打印接收到的频道消息
            Log::info("Received new channel message: $text from channel_id: $chat_id");

            // 选择要使用的账户
            $accountToUse = 'account1';
            // 回复频道消息
            $this->sendMessage($accountToUse, $chat_id, "Received your new channel message: " . $text);
        }
    }

    // 处理普通用户编辑的消息
    private function handleEditMessage($update)
    {
        $message = $update['message'];
        if (isset($message['chat']) && isset($message['text'])) {
            $chat_id = $message['chat']['id'];
            $text = $message['text'];

            // 打印编辑后的消息
            Log::info("Message edited: $text in chat_id: $chat_id");

            // 选择要使用的账户
            $accountToUse = 'account1';
            // 回复编辑消息通知
            $this->sendMessage($accountToUse, $chat_id, "The message has been edited to: " . $text);
        }
    }

    // 处理频道编辑的消息
    private function handleEditChannelMessage($update)
    {
        $message = $update['message'];
        if (isset($message['peer_id']) && isset($message['message'])) {
            $chat_id = $message['peer_id'];
            $text = $message['message'];

            // 打印编辑后的频道消息
            Log::info("Channel message edited: $text in channel_id: $chat_id");

            // 选择要使用的账户
            $accountToUse = 'account1';
            // 回复频道编辑消息通知
            $this->sendMessage($accountToUse, $chat_id, "The channel message has been edited to: " . $text);
        }
    }

    // 处理普通用户删除的消息
    private function handleDeleteMessages($update)
    {
        $messageIds = $update['messages'];
        $chatId = isset($update['peer_id']) ? $update['peer_id'] : null;

        // 打印被删除的消息 ID
        Log::info("Messages deleted: " . implode(', ', $messageIds) . " in chat_id: $chatId");

        // 选择要使用的账户
        $accountToUse = 'account1';
        if ($chatId) {
            $this->sendMessage($accountToUse, $chatId, "Some messages have been deleted.");
        }
    }

    // 处理频道删除的消息
    private function handleDeleteChannelMessages($update)
    {
        $messageIds = $update['messages'];
        $chatId = isset($update['channel_id']) ? $update['channel_id'] : null;

        // 打印被删除的频道消息 ID
        Log::info("Channel messages deleted: " . implode(', ', $messageIds) . " in channel_id: $chatId");

        // 选择要使用的账户
        $accountToUse = 'account1';
        if ($chatId) {
            $this->sendMessage($accountToUse, $chatId, "Some channel messages have been deleted.");
        }
    }

    // 处理用户状态更新
    private function handleUserStatusUpdate($update)
    {
        $userId = $update['user_id'];
        $status = $update['status']['_'];

        // 打印用户状态更新信息
        Log::info("User $userId status updated to: $status");
    }

    // 处理普通聊天成员更新
    private function handleChatParticipantsUpdate($update)
    {
        $chatId = isset($update['chat_id']) ? $update['chat_id'] : null;
        $participants = $update['participants'];

        // 打印聊天成员更新信息
        Log::info("Chat $chatId participants updated: " . json_encode($participants));

        // 选择要使用的账户
        $accountToUse = 'account1';
        if ($chatId) {
            $this->sendMessage($accountToUse, $chatId, "Chat participants have been updated.");
        }
    }

    // 处理频道成员更新
    private function handleChannelParticipantsUpdate($update)
    {
        $chatId = isset($update['channel_id']) ? $update['channel_id'] : null;
        $participants = $update['participants'];

        // 打印频道成员更新信息
        Log::info("Channel $chatId participants updated: " . json_encode($participants));

        // 选择要使用的账户
        $accountToUse = 'account1';
        if ($chatId) {
            $this->sendMessage($accountToUse, $chatId, "Channel participants have been updated.");
        }
    }

    public function sendMessage($accountName, $chat_id, $text)
    {
        try {
            $this->madelineInstances[$accountName]->messages->sendMessage([
                'peer' => $chat_id,
                'message' => $text
            ]);
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'FLOOD_WAIT')!== false) {
                // 提取等待时间
                preg_match('/FLOOD_WAIT_(\d+)/', $e->getMessage(), $matches);
                if (isset($matches[1])) {
                    $waitTime = (int)$matches[1];
                    Log::info("Flood control triggered. Waiting for $waitTime seconds...");
                    sleep($waitTime);
                    // 等待结束后重试
                    $this->sendMessage($accountName, $chat_id, $text);
                }
            } else {
                echo "Error sending message: " . $e->getMessage() . "\n";
            }
        }
    }
     // 停止 Webhook
    public function stopWebhook()
    {
        $accountName = 'account1';
        try {
            // 将 Webhook URL 设置为空以停止 Webhook
            $this->madelineInstances[$accountName]->setWebhook('');
            echo "Webhook for $accountName has been stopped.\n";
        } catch (Exception $e) {
            echo "Error stopping webhook for $accountName: " . $e->getMessage() . "\n";
        }
    }
}