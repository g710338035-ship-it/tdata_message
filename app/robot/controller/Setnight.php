<?php
namespace app\robot\controller;

use think\facade\Db;
use think\facade\Log;
use think\facade\Cache;
class Setnight extends Apibot
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
            case '/set_night':		    

                $bot=Cache::store('redis')->get($this->cacheBot);
                // 更新 is_top 值
               // Db::name('telegrambot')->where('bot_token', $token)->update(['ismoon' => $ismoon]);
                $starttime=$bot['starttime'];
                $endtime=$bot['endtime']; 
		            $bwButtons = [];
                    if ($bot['ismoon'] == 1) {
                        
                        $str = "已*【开启】*🌒 夜间模式,全局禁言。\n\n开启时间：$starttime 到$endtime \n\n，如果需要 * 取消 * 请点击下方按钮"; // 使用 Markdown 格式
                        
                        $bwButtons[] = [ [
                            'text' => '🚫取消',
                            'callback_data' => '/set_night_status'
                        ], [
                            'text' => '🕙 设置时段',
                            'callback_data' => '/set_night_mode'
                        ],[
                            'text' => '🔙 返回',
                            'callback_data' => '/start'
                        ]
                        ];
                        
                    } else {
                        $str = "已*【关闭】*夜间模式\n\n夜间时间为：{$starttime} 到 {$endtime} 点，\n\n如果需要 * 激活 * 请点击下方按钮"; // 使用 Markdown 格式
                        $bwButtons[] = [ [
                            'text' => '✅激活',
                            'callback_data' => '/set_night_status'
                        ], [
                            'text' => '🔙 返回',
                            'callback_data' => '/start'
                        ]];
                    }
                 
                    
                    $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                    
                    $content = [
                        'chat_id' => $chat_id,
                        'reply_markup' => $replyMarkup,
                        'message_id' => $messageId,
                        'text' => "🌒 夜间模式\n\n $str",
                        'parse_mode' => 'Markdown' // 改为 Markdown 格式
                    ];
                    
                    // 发送请求以编辑消息
                    $response = send($token, 'editMessageText', $content);
		        break;
		    case '/set_night_status':   
		        $bot=Cache::store('redis')->get($this->cacheBot);
		        $ismoon = ($bot['ismoon'] == 0) ? 1 : 0;
                // 更新 is_top 值
                Db::name('telegrambot')->where('bot_token', $token)->update(['ismoon' => $ismoon]);
                $starttime=$bot['starttime'];
                $endtime=$bot['endtime']; 
                $bwButtons = [];
                    if ($bot['ismoon'] == 0) {
                        
                        $str = "已*【开启】*夜间模式,全局禁言。\n\n*开启时间：{$starttime} 到 {$endtime} *\n\n，如果需要 * 取消 * 请点击下方按钮"; // 使用 Markdown 格式
                        
                        $bwButtons[] = [ [
                            'text' => '🚫取消',
                            'callback_data' => '/set_night_status'
                        ], [
                            'text' => '🕙 设置时段',
                            'callback_data' => '/set_night_mode'
                        ],[
                            'text' => '🔙 返回',
                            'callback_data' => '/start'
                        ]
                        ];
                        
                    } else {
                        $str = "已*【关闭】*夜间模式\n\n夜间时间为：{$starttime} 到 {$endtime} 点，如果需要 * 激活 * 请点击下方按钮"; // 使用 Markdown 格式
                        $bwButtons[] = [ [
                            'text' => '✅激活',
                            'callback_data' => '/set_night_status'
                        ], [
                            'text' => '🔙 返回',
                            'callback_data' => '/start'
                        ]];
                    }
                 
                    
                    $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                    
                    $content = [
                        'chat_id' => $chat_id,
                        'reply_markup' => $replyMarkup,
                        'message_id' => $messageId,
                        'text' => "🌒 夜间模式\n\n $str",
                        'parse_mode' => 'Markdownv2' // 改为 Markdown 格式
                    ];
                    
                    // 发送请求以编辑消息
                    $response = send($token, 'editMessageText', $content);
                    
                    $bot['ismoon']=$ismoon;
                    Cache::store('redis')->set($this->cacheBot, $bot);
                break;
            case '/set_night_mode':
        		    
                    $hoursButtons = [];
                    $row = []; // 用于存储一行的按钮
                
                    // 创建小时按钮，分为五行
                    for ($i = 0; $i <= 23; $i++) {
                        // 添加按钮到当前行
                        $row[] = ['text' => (string)$i, 'callback_data' => '/set_night_start_time:'.(string)$i]; // 确保按钮是对象
                        
                
                        // 每五个按钮后添加一行
                        if (count($row) == 5) {
                            $hoursButtons[] = $row; // 将当前行添加到按钮组
                            $row = []; // 清空当前行
                        }
                    }
                
                    // 如果还有剩余按钮，添加到按钮组
                    if (!empty($row)) {
                        $hoursButtons[] = $row; // 添加剩余的按钮
                    }
                
                    // 添加关闭按钮
                    $hoursButtons[] = [[
                            'text' => '🔙 返回',
                            'callback_data' => '/set_night'
                        ]];
                    $replyMarkup = json_encode(['inline_keyboard' => $hoursButtons]);
                    $content = [
                        'chat_id' => $chat_id,
                        'text' => "🌒 夜间模式\n从这个菜单你可以设置每天数小时的时间段内的夜间模式。\n\n👉🏻 选择开始时间：\n",
                        'reply_markup' => $replyMarkup,
                        'message_id' => $messageId,
                        'parse_mode' => 'MarkdownV2' 
                    ];
                    
                    send($token,'editMessageText', $content);
        		        	    
        	    break;
        	default:    
        	  if (strpos($text, '/set_night_start_time:') === 0) {
                    $hour = str_replace('/set_night_start_time:', '', $text);
                    $hoursButtons = [];
                    $row = []; // 用于存储一行的按钮
                
                    // 创建小时按钮，分为五行
                    for ($i = 0; $i <= 23; $i++) {
                        // 添加按钮到当前行
                        $row[] = ['text' => (string)$i, 'callback_data' => '/set_night_end_time:'.$hour.'到'.(string)$i]; // 确保按钮是对象
                        
                
                        // 每五个按钮后添加一行
                        if (count($row) == 5) {
                            $hoursButtons[] = $row; // 将当前行添加到按钮组
                            $row = []; // 清空当前行
                        }
                    }
                
                    // 如果还有剩余按钮，添加到按钮组
                    if (!empty($row)) {
                        $hoursButtons[] = $row; // 添加剩余的按钮
                    }
                
                    // 添加关闭按钮
                    $hoursButtons[] = [[
                        'text' => '🔙 返回',
                        'callback_data' => '/set_night'
                    ]];
                    $replyMarkup = json_encode(['inline_keyboard' => $hoursButtons]);
                    $content = [
                        'chat_id' => $chat_id,
                        'text' => "🌒 夜间模式\n\n从这个菜单你可以设置每天数小时的时间段内的夜间模式。\n\n⏱️ *开始时间：{$hour} 点*\n\n👉🏻 选择结束时间：\n",
                        'reply_markup' => $replyMarkup,
                        'message_id' => $messageId,
                        'parse_mode' => 'MarkdownV2' 
                    ];
                    
                    send($token,'editMessageText', $content);
                } 
                if (strpos($text, '/set_night_end_time:') === 0) {
                    $hour = str_replace('/set_night_end_time:', '', $text);
                    $hoursButtons = [
                        [
                            ['text' => '🔙 返回', 'callback_data' => '/set_night'] // 确保按钮是对象
                        ]
                    ];
                    $replyMarkup = json_encode(['inline_keyboard' => $hoursButtons]);
                    $content = [
                        'chat_id' => $chat_id,
                        'text' => "🌒 夜间模式，设置成功。\n\n⏱️ 夜间模式时段已设置：*{$hour}* 点\n",
                        'reply_markup' => $replyMarkup,
                        'message_id' => $messageId,
                        'parse_mode' => 'MarkdownV2' 
                    ];
                    
                    $ttres=send($token,'editMessageText', $content);
                   
                    $ttre = json_decode($ttres, true);
                     // 输出为 JSON 字符串
                
                    // 使用 === 比较
                    if (isset($ttre['ok']) && $ttre['ok'] === true) {
                        $parts = explode('到', $hour);
                        if (count($parts) === 2) {
                        $startTime = sprintf('%02d:00', $parts[0]); // 固定为 00:00
                        $endTime = sprintf('%02d:00',  $parts[1]);
                        echo $endTime;
                        Db::name('telegrambot')->where('bot_token', $token)->update(['starttime'=>$startTime,'endtime'=>$endTime]);
                        $bot = Cache::store('redis')->get($this->cacheBot);
                        $bot['starttime']=$startTime;
                        $bot['endtime']=$endTime;
                        Cache::store('redis')->set($this->cacheBot, $bot);      
                        }
                    }
                }  
        	break;    
        }
    }
    
    
}
