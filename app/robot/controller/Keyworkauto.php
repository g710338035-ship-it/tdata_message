<?php
namespace app\robot\controller;

use think\facade\Db;
use think\facade\Log;
use think\Request;
use think\facade\Cache;

class Keyworkauto extends Apibot
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
        $callbackQueryId = $data['id'];
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
        $botinfo=Cache::store('redis')->get($this->cacheBot);
        $bot_id=$botinfo['bot_id'];
        
        if (preg_match('/^(.*):(\d+)$/', $text, $matches)) {
            $commeds = $matches[1]; // 提取到的文本
            $bgid = $matches[2];    // 提取到的数字
           // $text = $commeds;
        }else{
            $commeds=$text;
        }
        // 处理不同命令
        switch ($commeds) {
            case '/keyworkauto_Reply':
                $this->keyworkauto_Reply($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid);
                break;
            case '/keyworkauto_Reply_add':
                $this->keyworkauto_Reply_add($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid);
                break;
            case '/keyworkauto_Reply_add_back':
                $waiting = 'waiting_for_message';
                $redisKeykeywordadd = "keywordadd:$bot_id:add_status";
                $redisHashKeykeywordadd = "keywordadd:$bot_id:addmessage";
                
                $this->redis->srem($redisKeykeywordadd, $waiting);
                $this->redis->del($redisHashKeykeywordadd);
                $this->keyworkauto_Reply($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid);
                break;    
            case '/keyworkauto_Reply_del':
                $this->keyworkauto_Reply_del($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid);
                break;
            case '/keyworkauto_Reply_del_back':
                 $waiting = 'waiting_for_message';
                $redisKeykeyworddel = "keyworddel:$bot_id:add_status";
                $redisHashKeykeyworddel = "keyworddel:$bot_id:addmessage";
                
                $this->redis->srem($redisKeykeyworddel, $waiting);
                $this->redis->del($redisHashKeykeyworddel);
                $this->keyworkauto_Reply($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid);
                break;    
            case '/keyworkauto_Reply_see':
                $this->keyworkauto_Reply_see($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid);
                break;     
        }
    }
    protected function keyworkauto_Reply($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid){
        $keyboard = [[
                [
        			'text' => '➕添加关键词',
        			'callback_data' => '/keyworkauto_Reply_add:'.$bgid
        		],[
        			'text' => '➖删除关键词',
        			'callback_data' => '/keyworkauto_Reply_del:'.$bgid
        		]],[
                [
                'text' => '👀查看',
                'callback_data' => '/keyworkauto_Reply_see:'.$bgid
                ],[
                'text' => '🔙 返回',
                'callback_data' => '/group_setting_botquninfo:'.$bgid
                ]
                ]];

        $keyboard = [
        	'inline_keyboard' => $keyboard,
        ];
                    
               $sendtext="🔡自动回复\n\n👉🏻 在这个类别设置关键词和回复内容: \n\n  "; 
                    $content = [
                        'chat_id' => $chat_id,
                        'reply_markup' => json_encode($keyboard),
                        'message_id' => $messageId,
                        'text' => $sendtext,
                        'parse_mode' => 'Markdown' // 改为 Markdown 格式
                    ];
                    
                    // 发送请求以编辑消息
        $response = send($token, 'editMessageText', $content);
        log::write($response);
    }
    
    protected function keyworkauto_Reply_add($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid){
        $botinfo=Cache::store('redis')->get($this->cacheBot);
        $bot_id=$botinfo['bot_id'];
                $bwButtons[] = [[
                        'text' => '❌撤销',
                        'callback_data' => '/keyworkauto_Reply_add_back:'.$bgid
                    ]];
                    $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                    $content = [
                    'chat_id' => $chat_id,
                    'text' => "🔡自动回复\n\n批量添加自动回复消息。\n\n内容格式为：\n关键词#回复内容\n小鸟#在天上飞\n雨#从天上降\n鱼儿#水中游",
                    'reply_markup' => $replyMarkup,
                    'message_id' => $messageId,
                    'parse_mode' => 'Markdown'
                ];
                    
                    $ttres = send($token, 'editMessageText', $content);
                    $redisKey = "keywordadd:$bot_id:add_status";
                    $redisHashKey = "keywordadd:$bot_id:addmessage";
                    $waiting = 'waiting_for_message';
                    
                    $this->redis->sadd($redisKey, $waiting);
                    $this->redis->hset($redisHashKey, $waiting, $messageId."_".$bgid);
                    
                    // 设置过期时间（25分钟）
                    $this->redis->expire($redisKey, 25 * 60);
                    $this->redis->expire($redisHashKey, 25 * 60);
    }
    
    protected function keyworkauto_Reply_del($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid){
        $botinfo=Cache::store('redis')->get($this->cacheBot);
        $bot_id=$botinfo['bot_id'];
                $bwButtons[] = [[
                        'text' => '❌撤销',
                        'callback_data' => '/keyworkauto_Reply_del_back:'.$bgid
                    ]];
                    $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                   
                 $cacheBanwordsKey = "kl_tg_keyword";
        
                // 获取缓存中的违禁词数据
                $cacheBanwordsData = Cache::store('redis')->get($cacheBanwordsKey);
        
                // 如果缓存中没有违禁词数据，从数据库查询并缓存
                if (!$cacheBanwordsData) {
                    $cacheBanwordsData = Db::name('keyword')->select()->toArray();
                    Cache::store('redis')->set($cacheBanwordsKey, $cacheBanwordsData, 3600); // 缓存1小时
                }
                $messageText="";
                // 遍历缓存中的违禁词数据，检查是否存在匹配的词
                foreach ($cacheBanwordsData as $banword) {
                    // 检查是否符合 bot_id 或 group_id 与输入参数匹配
                    if ($banword['bgid'] == $bgid) {
                       $messageText.=$banword['keyword']."\n";
                    }
                }
                
                    $content = [
                    'chat_id' => $chat_id,
                    'text' => "🔡自动回复\n\n批量删除自动回复消息。\n\n现有关键词：\n$messageText\n删除关键词为一行一个。",
                    'reply_markup' => $replyMarkup,
                    'message_id' => $messageId,
                    'parse_mode' => 'Markdown'
                ];
                    
                    $ttres = send($token, 'editMessageText', $content);
                    $redisKey = "keyworddel:$bot_id:add_status";
                    $redisHashKey = "keyworddel:$bot_id:addmessage";
                    $waiting = 'waiting_for_message';
                    
                    $this->redis->sadd($redisKey, $waiting);
                    $this->redis->hset($redisHashKey, $waiting, $messageId."_".$bgid);
                    
                    // 设置过期时间（25分钟）
                    $this->redis->expire($redisKey, 25 * 60);
                    $this->redis->expire($redisHashKey, 25 * 60);
    }
    
    
    protected function keyworkauto_Reply_see($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid)
    {
       $botinfo=Cache::store('redis')->get($this->cacheBot);
        $messageText="🔡关键词列表内容A为关键词，Q为回复内容\n\n";    
                $bot_id=$botinfo['bot_id'];  
                // 定义 Redis 缓存键
                $cacheBanwordsKey = "kl_tg_keyword";
        
                // 获取缓存中的违禁词数据
                $cacheBanwordsData = Cache::store('redis')->get($cacheBanwordsKey);
        
                // 如果缓存中没有违禁词数据，从数据库查询并缓存
                if (!$cacheBanwordsData) {
                    $cacheBanwordsData = Db::name('keyword')->select()->toArray();
                    Cache::store('redis')->set($cacheBanwordsKey, $cacheBanwordsData, 3600); // 缓存1小时
                }
                
                // 遍历缓存中的违禁词数据，检查是否存在匹配的词
                foreach ($cacheBanwordsData as $banword) {
                    // 检查是否符合 bot_id 或 group_id 与输入参数匹配
                    if ($banword['bgid'] == $bgid) {
                       $messageText.="A:".$banword['keyword']."\n";
                       $messageText.="Q:".$banword['reply']."\n\n";
                    }
                }
           
        $bwButtons = [
            [[
                'text' => '🔙 返回',
                'callback_data' => '/keyworkauto_Reply:'.$bgid
            ]]
        ];
        
        $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
        
        $content = [
            'chat_id' => $chat_id,
            'text' => $messageText,
            'message_id' => $messageId,
            'reply_markup' => $replyMarkup,
            'parse_mode' => 'Markdown'
        ];
        send($token, 'editMessageText', $content);
        
        
        
    }
}
