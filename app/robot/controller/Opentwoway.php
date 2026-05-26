<?php
namespace app\robot\controller;

use think\facade\Db;
use think\facade\Log;
use think\Request;
use think\facade\Cache;

class Opentwoway extends Apibot
{
    protected $cacheBot;
    
    public function __construct()
    {
        parent::__construct();
        //$this->index();
        $this->cacheBot = $this->cacheBot;
    }

    public function handle($data)
    { 
     
        $token = $data['token'];
        
        // 根据消息类型获取必要参数
        if (isset($data) && $data['messagetype'] == 1) {
            $chat_id = $data['chat']['id'];
            $chatType = $data['chat']['type'];
            $text = $data['text'] ?? '未知';
            $messageId = $data['message_id'] ?? null;
            $userId = $data['from']['id'];
        }
        
        if (isset($data) && $data['messagetype'] == 2) {
            $last_name = isset($data['from']['last_name']);
            $name = $data['from']['first_name'] . $last_name;
            
            $chat_id = $data['message']['chat']['id'];
            $chatType = $data['message']['chat']['type'];
            $text = $data['data'];
            $messageId = $data['message']['message_id'];
            $userId = $data['from']['id'];
            $username = isset($data['from']['username']) ? $data['from']['username'] : '';
        }
        
        echo "message welcome info: $text";
        
        // 处理不同命令
        switch ($text) {
            case '/openTwo-way':
		        $bot=Cache::store('redis')->get($this->cacheBot);
		       
                    if ($bot['opentwo'] == 1) {
                        $str = '已*【开启】*双向，如果需要 * 取消双向 * 请点击下方按钮'; // 使用 Markdown 格式
                        $bs = '取消双向❌';
                    } else {
                        $str = '已*【关闭】*双向，如果需要 * 开启双向 * 请点击下方按钮'; // 使用 Markdown 格式
                        $bs = '开启双向✅';
                    }
                    
                    echo $str;
                    
                    // 确保 $bwButtons 被初始化为数组
                    $bwButtons = [];
                    
                    $bwButtons[] = [[
                        'text' => "🔛$bs",
                        'callback_data' => '/openTwo-way-status'
                    ],  [
                        'text' => '🔙 返回',
                        'callback_data' => '/start'
                    ]];
                    
                    $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                    
                    $content = [
                        'chat_id' => $chat_id,
                        'reply_markup' => $replyMarkup,
                        'message_id' => $messageId,
                        'text' => "🔛开启双向\n\n此机器人$str",
                        'parse_mode' => 'Markdown' // 改为 Markdown 格式
                    ];
                    
                    // 发送请求以编辑消息
                    $response = send($token, 'editMessageText', $content);
		        break;
		    case '/openTwo-way-status':
		        $bot=Cache::store('redis')->get($this->cacheBot);
		        $opentwo = ($bot['opentwo'] == 0) ? 1 : 0;
                // 更新 is_top 值
                Db::name('telegrambot')->where('bot_token', $token)->update(['opentwo' => $opentwo]);
		        
		   
                    if ($bot['opentwo'] == 0) {
                        $str = '已*【开启】*双向，如果需要 * 取消双向 * 请点击下方按钮'; // 使用 Markdown 格式
                        $bs = '取消双向❌';
                    } else {
                        $str = '已*【关闭】*双向，如果需要 * 开启双向 * 请点击下方按钮'; // 使用 Markdown 格式
                        $bs = '开启双向✅';
                    }
                    
                    echo $str;
                    
                    // 确保 $bwButtons 被初始化为数组
                    $bwButtons = [];
                    
                    $bwButtons[] = [[
                        'text' => "🔛$bs",
                        'callback_data' => '/openTwo-way-status'
                    ],  [
                        'text' => '🔙 返回',
                        'callback_data' => '/start'
                    ]];
                    
                    $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                    
                    $content = [
                        'chat_id' => $chat_id,
                        'reply_markup' => $replyMarkup,
                        'message_id' => $messageId,
                        'text' => "🔛开启双向\n\n此机器人$str",
                        'parse_mode' => 'Markdown' // 改为 Markdown 格式
                    ];
                    
                    // 发送请求以编辑消息
                    $response = send($token, 'editMessageText', $content);
                    $bot['opentwo']=$opentwo;
                    Cache::store('redis')->set($this->cacheBot, $bot,3600);
		        break;
        }
    }
    
    
}
