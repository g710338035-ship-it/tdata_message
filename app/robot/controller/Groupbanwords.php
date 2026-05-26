<?php
namespace app\robot\controller;

use think\facade\Db;
use think\facade\Log;
use think\facade\Queue;

use think\Request;
use think\facade\Cache;
class Groupbanwords extends  Apibot
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
        if (isset($data) && $data['messagetype'] == 1) {
            $chat_id = $data['chat']['id'];
            $chatType = $data['chat']['type'];
            $text = $data['text'] ?? '未知';
            $messageId = $data['message_id'] ?? null;
            $userId = $data['from']['id'];
        } elseif (isset($data) && $data['messagetype'] == 2) {
            $last_name = $data['from']['last_name'] ?? '';
            $name = $data['from']['first_name'] . $last_name;
            $chat_id = $data['message']['chat']['id'];
            $chatType = $data['message']['chat']['type'];
            $text = $data['data'];
            $messageId = $data['message']['message_id'];
            $userId = $data['from']['id'];
            $username = $data['from']['username'] ?? '';
        }
      
       // $command = strpos($text, '/banWords_') !== false ? str_replace('/banWords_', '', $text) : $text;
       
        $botinfo=Cache::store('redis')->get($this->cacheBot);
		$bot_id=$botinfo['bot_id'];
        switch ($text) {
            case '/banWords':
                
                $this->banwords_setting($chat_id, $chatType, $text, $userId, $token, $messageId);
                break;
            case '/banWords_set_status':
                $this->banWords_set_status($chat_id, $chatType, $text, $userId, $token, $messageId);
                break;
            case '/banWords_set_editstatus':
                $this->banWords_set_editstatus($chat_id, $chatType, $text, $userId, $token, $messageId);
                break;    
                
            case '/banWords_time':
                $this->gbanwords_setting_time($chat_id, $chatType, $text, $userId, $token, $messageId);
                break;

            case '/banWords_notspeek_add':
                $this->manageWordAddition($chat_id, $userId, $token, $messageId, '禁言','notsay');
                break;

            case '/banWords_notspeek_del':
                $this->manageWordDeletion($chat_id, $userId, $token, $messageId, '禁言','notsay');
                break;

            case '/banWords_notspeak_list':
                //Log::info($chatType);
                $this->listBannedWords($token,$chat_id,$chatType, 1, '禁言',$messageId);
                break;

            case '/banWords_goout_add':
                $this->manageWordAddition($chat_id, $userId, $token, $messageId, '踢出','goout');
                break;

            case '/banWords_goout_del':
                $this->manageWordDeletion($chat_id, $userId, $token, $messageId, '踢出','goout');
                break;

            case '/banWords_goout_list':
                $this->listBannedWords($token,$chat_id, $chatType,2, '踢出',$messageId);
                break;
            case '/banWords_info_back':
                $waiting = 'waiting_for_message';
                
                $redisKeybanwordadd = "banwordadd:notsay.$bot_id:add_status";
                $redisHashKeybanwordadd = "banwordadd:notsay.$bot_id:addmessage";
                $this->redis->srem($redisKeybanwordadd, $waiting);
                $this->redis->del($redisHashKeybanwordadd, $waiting, $messageId);
                
                $redisKeybanwordaddgoout = "banwordadd:goout.$bot_id:add_status";
                $redisHashKeybanwordaddgoout = "banwordadd:goout.$$bot_id:addmessage";
                $this->redis->srem($redisKeybanwordaddgoout, $waiting);
                $this->redis->del($redisHashKeybanwordaddgoout, $waiting, $messageId);
                
                $redisKey = "banwordadd:$bot_id:del_status";
                $redisHashKey = "banwordadd:$bot_id:delmessage";
                $this->redis->srem($redisKey, $waiting);
                $this->redis->del($redisHashKey, $waiting, $messageId);
        
                
                $this->banwords_setting($chat_id, $chatType, $text, $userId, $token, $messageId);
                break;
            default:
                if (strpos($text, '/banWords_time_duration') === 0) {
                    $duration = str_replace('/banWords_time_duration:', '', $text);
                    $this->setDuration($chat_id,$chatType, $token, $messageId, $duration);
                }
                break;
        }
    }
    protected function banWords_set_editstatus($chat_id, $chatType, $text, $userId, $token, $messageId)
    {
        if($chatType=='private'){
            $bot=Cache::store('redis')->get($this->cacheBot);
            $isbanword = ($bot['isbanword'] == 0) ? 1 : 0;
            Db::name('telegrambot')->where('bot_token', $token)->update(['isbanword' => $isbanword]);
        }else{
            $group = Db::name('telegraggroup')->where('group_id', $chat_id)->find();
            $isbanword = ($group['isbanword'] == 0) ? 1 : 0;
            Db::name('telegraggroup')->where('group_id', $chat_id)->update(['isbanword' => $isbanword]);
        }
               
		        
                // 更新 is_top 值
                
		   
                    if ($isbanword== 1) {
                        $str = '已*【开启】*违禁词处罚，如果需要 * 取消 * 请点击下方按钮'; // 使用 Markdown 格式
                        $bs = '🚫取消';
                    } else {
                        $str = '已*【关闭】*违禁词处罚，如果需要 * 开启 * 请点击下方按钮'; // 使用 Markdown 格式
                        $bs = '✅开启';
                    }
                    
                    echo $str;
                    
                    // 确保 $bwButtons 被初始化为数组
                    $bwButtons = [];
                    
                    $bwButtons[] = [[
                        'text' => "$bs",
                        'callback_data' => '/banWords_set_editstatus'
                    ],  [
                        'text' => '🔙 返回',
                        'callback_data' => '/banWords'
                    ]];
                    
                    $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                    
                    $content = [
                        'chat_id' => $chat_id,
                        'reply_markup' => $replyMarkup,
                        'message_id' => $messageId,
                        'text' => "🔤修改违禁词状态\n\n此机器人$str",
                        'parse_mode' => 'Markdown' // 改为 Markdown 格式
                    ];
                    
                    // 发送请求以编辑消息
                    $response = send($token, 'editMessageText', $content);
                    if($chatType=='private'){
                        $bot['isbanword']=$isbanword;
                        Cache::store('redis')->set($this->cacheBot, $bot);
                    }else{
                        $cacheGroupkey="telegram_group_".$chat_id;
                        $rs = Db::name('telegraggroup')->where('group_id', $chat_id)-> find();
                        Cache::store('redis')->set($cacheGroupkey, $rs, 3600);
                    }
    }
    protected function banWords_set_status($chat_id, $chatType, $text, $userId, $token, $messageId)
    {
        if($chatType=='private'){
            $bot=Cache::store('redis')->get($this->cacheBot);
            $isbanword=$bot['isbanword'];   
        }else{
            $group = Db::name('telegraggroup')->where('group_id', $chat_id)->find();
            $isbanword=$group['isbanword']; 
        }
		       
                    if ($isbanword == 1) {
                        $str = '已*【开启】*违禁词处罚，如果需要 * 取消 * 请点击下方按钮'; // 使用 Markdown 格式
                        $bs = '🚫取消';
                    } else {
                        $str = '已*【关闭】*违禁词处罚，如果需要 * 开启 * 请点击下方按钮'; // 使用 Markdown 格式
                        $bs = '✅开启';
                    }
                    
                   
                    
                    // 确保 $bwButtons 被初始化为数组
                    $bwButtons = [];
                    
                    $bwButtons[] = [[
                        'text' => "$bs",
                        'callback_data' => '/banWords_set_editstatus'
                    ],  [
                        'text' => '🔙 返回',
                        'callback_data' => '/banWords'
                    ]];
                    
                    $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                    
                    $content = [
                        'chat_id' => $chat_id,
                        'reply_markup' => $replyMarkup,
                        'message_id' => $messageId,
                        'text' => "🔤修改违禁词状态\n\n$str",
                        'parse_mode' => 'Markdown' // 改为 Markdown 格式
                    ];
                    
                    // 发送请求以编辑消息
                    $response = send($token, 'editMessageText', $content);
    }

    // 添加屏蔽或踢出词组
    protected function manageWordAddition($chat_id, $userId, $token, $messageId, $typeinfo,$type)
    {
		$botinfo=Cache::store('redis')->get($this->cacheBot);
		$bot_id=$botinfo['bot_id'];
        $buttons = [
            [
                ['text' => '🔙 返回', 'callback_data' => '/banWords_info_back'],
            ]
        ];
        $content = [
            'chat_id' => $chat_id,
            'text' => "🔤违禁词\n\n现在请发送您想要从群组中 $typeinfo 的词组，每行一个。\n\n示例：\n我要杀了你\n你好烂\n\n",
            'reply_markup' => json_encode(['inline_keyboard' => $buttons]),
            'message_id' => $messageId,
            'parse_mode' => 'MarkdownV2'
        ];
        send($token, 'editMessageText', $content);
        $redisKey = "banwordadd:$type.$bot_id:add_status";
        $redisHashKey = "banwordadd:$type.$bot_id:addmessage";
        $waiting = 'waiting_for_message';
        $this->redis->sadd($redisKey, $waiting);
        $this->redis->hset($redisHashKey, $waiting, $messageId);
        $this->redis->expire($redisKey, 25 * 60);
        $this->redis->expire($redisHashKey, 25 * 60);
    }

    // 删除屏蔽或踢出词组
    protected function manageWordDeletion($chat_id, $userId, $token, $messageId, $typeinfo,$type)
    {
		$botinfo=Cache::store('redis')->get($this->cacheBot);
		$bot_id=$botinfo['bot_id'];
        $buttons = [
            [
                ['text' => '🔙 返回', 'callback_data' => '/banWords_info_back'],
               
            ]
        ];
        $content = [
            'chat_id' => $chat_id,
            'text' => "🔤违禁词\n\n现在请发送您想要从群组中删除的 $typeinfo 词组，每行一个。",
            'reply_markup' => json_encode(['inline_keyboard' => $buttons]),
            'message_id' => $messageId,
            'parse_mode' => 'MarkdownV2'
        ];
        send($token, 'editMessageText', $content);
        $redisKey = "banwordadd:$type.$bot_id:del_status";
        $redisHashKey = "banwordadd:$type.$bot_id:delmessage";
        $waiting = 'waiting_for_message';
        $this->redis->sadd($redisKey, $waiting);
        $this->redis->hset($redisHashKey, $waiting, $messageId);
        $this->redis->expire($redisKey, 25 * 60);
        $this->redis->expire($redisHashKey, 25 * 60);
    }

    // 列出违禁词
    protected function listBannedWords($token,$chat_id, $chatType,$psid, $type,$messageId)
    {   
        
        if($chatType=='private'){
            $bot=Cache::store('redis')->get($this->cacheBot);
                $bot_id=$bot['bot_id'];
                $bot_name=$bot['bot_name'];
                $where = [
                    ['bot_id', '=', $bot_id],
                    ['psid', '=', $psid],
                    ['status', '=', 1],
                ];
              
                $banwordslist = Db::name('banwords')->where($where)->select();  
        }else{
            $banwordslist = Db::name('banwords')->where([
                ['group_id', '=', $chat_id],
                ['psid', '=', $psid],
                ['status', '=', 1],
            ])->select();
        }
        
            
        $str = "🔤违禁词\n\n$type 词列表\n";
        foreach ($banwordslist as $item) {
            $str .= $item['word'] . "\n";
        }
        $str = rtrim($str, "\n");
        $buttons = [
            [
                ['text' => '🔙 返回', 'callback_data' => '/banWords'],
                ['text' => '❌关闭', 'callback_data' => '/closeMessage']
            ]
        ];
        $content = [
            'chat_id' => $chat_id,
            'text' => $str,
            'reply_markup' => json_encode(['inline_keyboard' => $buttons]),
            'message_id' => $messageId,
            'parse_mode' => 'MarkdownV2'
        ];
        send($token, 'editMessageText', $content);
    }

    protected function setDuration($chat_id,$chatType, $token, $messageId, $duration)
    {   
        $interval_display = $duration < 60 ? "{$duration} 分钟" : floor($duration / 60) . ' 小时'; 
        $buttons = [
            [
                ['text' => '🔙 返回', 'callback_data' => '/banWords'],
                ['text' => '❌关闭', 'callback_data' => '/closeMessage']
            ]
        ];
        $content = [
            'chat_id' => $chat_id,
            'text' => "🔤违禁词\n\n用户使用违禁词处罚，禁言时长已设为 $interval_display 。",
            'reply_markup' => json_encode(['inline_keyboard' => $buttons]),
            'message_id' => $messageId,
            'parse_mode' => 'MarkdownV2'
        ];
        send($token, 'editMessageText', $content);
        
        if($chatType=='private'){
            $bot=Cache::store('redis')->get($this->cacheBot);
            Db::name('telegrambot')->where('bot_token', $token)->update(['duration' => $duration]);
            $bot['duration']=$duration;
            Cache::store('redis')->set($this->cacheBot, $bot);
            Db::name('banwords')->where('bot_id', $bot['bot_id'])->update(['duration' => $duration]);
            $cacheBanwordsKey = "kl_tg_banwords";
            $cacheBanwordsData = Db::name('banwords')->where('status', 1)->select()->toArray();
            Cache::store('redis')->set($cacheBanwordsKey, $cacheBanwordsData, 3600); // 缓存1小时
        }else{
            Db::name('telegraggroup')->where('group_id', $chat_id)->update(['duration' => $duration]);
        }
        
        
    }


    protected function generateKeyboard($buttons,$chatType) {
    $keyboard = [];
    $currentRow = [];
    $currentRowNumber = null;
    
    foreach ($buttons as $button) {
        // 检查是否进入新的行
        if ($button['row_number'] !== $currentRowNumber) {
            // 如果当前行有按钮，将当前行添加到键盘布局中
            if (!empty($currentRow)) {
                $keyboard[] = $currentRow;
            }
            // 重置当前行
            $currentRow = [];
            $currentRowNumber = $button['row_number'];
            $maxColumns = $button['column_number'];
        }
        if($chatType!='private'&&$button['content'] === '/start'){
            $button['content'] ='/gsetting';
        }
        //$callbackData = ($button['content'] !== '/start') ? $callbackPrefix . $button['content'] : $defaultCallback;
        $currentRow[] = ['text' => $button['title'], 'callback_data' => $button['content']];

        // 如果当前行达到最大列数，加入键盘布局并重置当前行
        if (count($currentRow) >= $maxColumns) {
            $keyboard[] = $currentRow;
            $currentRow = [];
        }
    }

    // 加入未满列数的最后一行
    if (!empty($currentRow)) {
        $keyboard[] = $currentRow;
    }

    return ['inline_keyboard' => $keyboard];
    }
    
    protected function banwords_setting($chat_id, $chatType, $text, $userId, $token, $messageId) 
    {
        $str='';
        
        if($chatType=='private'){
            $bot = Cache::store('redis')->get($this->cacheBot);
            if($bot['isbanword']==1){
                $str.="违禁词状态  激活✅\n\n";
            }else{
                $str.="违禁词状态  关闭❌\n\n";
            }
            $duration=$bot['duration'];
            $interval_display = $duration < 60 ? "{$duration} 分钟" : floor($duration / 60) . ' 小时'; 
        }else{
           $group = Db::name('telegraggroup')->where('group_id', $chat_id)->find();
           $duration =$group['duration'];
           if($group['isbanword']==1){
                $str.="🔤 违禁词  激活✅\n\n";
            }else{
                $str.="🔤 违禁词  关闭❌\n\n";
            } 
           $interval_display = $duration < 60 ? "{$duration} 分钟" : floor($duration / 60) . ' 小时'; 
        }
        
        
        
        
        $buttons = getTelebuttonByTmgId(7);
        $keyboard = $this->generateKeyboard($buttons,$chatType);
        
        $buttonData = [
            'chat_id' => $chat_id,
            'text' => "🔤 违禁词\n\n此菜单你可以设置对使用违禁词的用户的处罚\n\n  $str 🕙已设置禁言时间：$interval_display\n\n",
            'message_id' => $messageId,
            'reply_markup' => json_encode($keyboard),
        ];
    
        send($token, 'editMessageText', $buttonData);
    }
    
    protected function gbanwords_setting_time($chat_id, $chatType, $text, $userId, $token, $messageId) {
        $buttons = getTelebuttonByTmgId(14);
        $keyboard = $this->generateKeyboard($buttons,$chatType);
    
        $buttonData = [
            'chat_id' => $chat_id,
            'text' => "🔤 违禁词\n\n您可以设置对使用违禁词的用户处罚禁言时间。\n",
            'message_id' => $messageId,
            'reply_markup' => json_encode($keyboard),
        ];
    
        send($token, 'editMessageText', $buttonData);
    }

}
