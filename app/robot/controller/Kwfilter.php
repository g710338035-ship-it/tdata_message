<?php
namespace app\robot\controller;

use think\facade\Db;
use think\facade\Log;
use think\Request;
use think\facade\Cache;

class Kwfilter extends Apibot
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
      
            
            $last_name = isset($data['from']['last_name']);
            $name = $data['from']['first_name'] . $last_name;
            
            $chat_id = $data['message']['chat']['id'];
            $chatType = $data['message']['chat']['type'];
            $text = $data['data'];
            $messageId = $data['message']['message_id'];
            $userId = $data['from']['id'];
            $username = isset($data['from']['username']) ? $data['from']['username'] : '';
      
        $botinfo=Cache::store('redis')->get($this->cacheBot);
        $bot_id=$botinfo['bot_id'];
        
        if (preg_match('/^(.*):(\d+)$/', $text, $matches)) {
            $commeds = $matches[1]; // 提取到的文本
            $bgid = $matches[2];    // 提取到的数字
           // $text = $commeds;
           log::write($bgid);
        }else{
            $commeds=$text;
        }
        log::write($commeds);
        // 处理不同命令
        switch ($commeds) {
            case '/kwfilter':
                $this->kwfilter($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid);
                break;
            case '/kwfilter_xiaoxi_word':
                $this->kwfilter_xiaoxi_word($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid);
                break;
            case '/kwfilter_mingzi_word':
                $this->kwfilter_mingzi_word($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid);
                break;
            case '/kwfilter_zhuanfa_set':
                $this->kwfilter_zhuanfa_set($chat_id, $token, $messageId,$bgid);
                break;
            case '/kwfilter_xiaoxi_word_back':
                $waiting = 'waiting_for_message';
                $redisKeykeywordadd = "kwfilter:$bot_id:add_status";
                $redisHashKeykeywordadd = "kwfilter:$bot_id:addmessage";
                
                $this->redis->srem($redisKeykeywordadd, $waiting);
                $this->redis->del($redisHashKeykeywordadd);
                $this->kwfilter($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid);
                break;
            case '/kwfilter_xiaoxi_word_adddel':
                $redisKey = "kwfilter:$bot_id:" . $bgid . ":add_status";
                if (Cache::store('redis')->has($redisKey)) {
                    $retrievedData = Cache::store('redis')->get($redisKey);
                    log::write($retrievedData);
                    $keyword=$retrievedData['message'];
                    $psid=1;
                    $num=$this->kwfilter_xiaoxi_word_add($chat_id, $psid, $keyword, $token, $messageId,$bgid,$bot_id);
                    
                    $this->kwfilterreturn($chat_id,  $token, $messageId,$bgid,$num);
                    $this->redis->del($redisKey);
                } else {
                    $content = [
                        'callback_query_id' => $callbackQueryId,
                        'text' => '操作时间已失效',
                        'show_alert' => true,
                        'cache_time' => 5
                    ];
                    send($token,'answerCallbackQuery', $content);
                }
               
                break;
            case '/kwfilter_xiaoxi_word_addgouout':
                $redisKey = "kwfilter:$bot_id:" . $bgid . ":add_status";
                if (Cache::store('redis')->has($redisKey)) {
                    $retrievedData = Cache::store('redis')->get($redisKey);
                    log::write($retrievedData);
                    $keyword=$retrievedData['message'];
                    $psid=2;
                   $num=$this->kwfilter_xiaoxi_word_add($chat_id, $psid, $keyword, $token, $messageId,$bgid,$bot_id);
                    
                    $this->kwfilterreturn($chat_id,  $token, $messageId,$bgid,$num);
                    $this->redis->del($redisKey);
                } else {
                    $content = [
                        'callback_query_id' => $callbackQueryId,
                        'text' => '操作时间已失效',
                        'show_alert' => true,
                        'cache_time' => 5
                    ];
                    send($token,'answerCallbackQuery', $content);
                }
               
                break;
            case '/kwfilter_xiaoxi_word_addnosay':
                $redisKey = "kwfilter:$bot_id:" . $bgid . ":add_status";
                if (Cache::store('redis')->has($redisKey)) {
                    $retrievedData = Cache::store('redis')->get($redisKey);
                    log::write($retrievedData);
                    $keyword=$retrievedData['message'];
                    $psid=3;
                    $num=$this->kwfilter_xiaoxi_word_add($chat_id, $psid, $keyword, $token, $messageId,$bgid,$bot_id);
                    $this->kwfilterreturn($chat_id,  $token, $messageId,$bgid,$num);
                    $this->redis->del($redisKey);
                } else {
                    $content = [
                        'callback_query_id' => $callbackQueryId,
                        'text' => '操作时间已失效',
                        'show_alert' => true,
                        'cache_time' => 5
                    ];
                    send($token,'answerCallbackQuery', $content);
                }
               
                break;
                
            case '/kwfilter_mingzi_word_adddel':
                $redisKey = "kwfiltermz:$bot_id:" . $bgid . ":add_status";
                if (Cache::store('redis')->has($redisKey)) {
                    $retrievedData = Cache::store('redis')->get($redisKey);
                    log::write($retrievedData);
                    $keyword=$retrievedData['message'];
                    $psid=1;
                    $num=$this->kwfilter_mingzi_word_add($chat_id, $psid, $keyword, $token, $messageId,$bgid,$bot_id);
                    
                    $this->kwfilterreturn($chat_id,  $token, $messageId,$bgid,$num);
                    $this->redis->del($redisKey);
                } else {
                    $content = [
                        'callback_query_id' => $callbackQueryId,
                        'text' => '操作时间已失效',
                        'show_alert' => true,
                        'cache_time' => 5
                    ];
                    send($token,'answerCallbackQuery', $content);
                }
               
                break;
            case '/kwfilter_mingzi_word_addgouout':
                $redisKey = "kwfiltermz:$bot_id:" . $bgid . ":add_status";
                if (Cache::store('redis')->has($redisKey)) {
                    $retrievedData = Cache::store('redis')->get($redisKey);
                    log::write($retrievedData);
                    $keyword=$retrievedData['message'];
                    $psid=2;
                   $num=$this->kwfilter_mingzi_word_add($chat_id, $psid, $keyword, $token, $messageId,$bgid,$bot_id);
                    
                    $this->kwfilterreturn($chat_id,  $token, $messageId,$bgid,$num);
                    $this->redis->del($redisKey);
                } else {
                    $content = [
                        'callback_query_id' => $callbackQueryId,
                        'text' => '操作时间已失效',
                        'show_alert' => true,
                        'cache_time' => 5
                    ];
                    send($token,'answerCallbackQuery', $content);
                }
               
                break;
            case '/kwfilter_mingzi_word_addnosay':
                $redisKey = "kwfiltermz:$bot_id:" . $bgid . ":add_status";
                if (Cache::store('redis')->has($redisKey)) {
                    $retrievedData = Cache::store('redis')->get($redisKey);
                    log::write($retrievedData);
                    $keyword=$retrievedData['message'];
                    $psid=3;
                    $num=$this->kwfilter_mingzi_word_add($chat_id, $psid, $keyword, $token, $messageId,$bgid,$bot_id);
                    $this->kwfilterreturn($chat_id,  $token, $messageId,$bgid,$num);
                    $this->redis->del($redisKey);
                } else {
                    $content = [
                        'callback_query_id' => $callbackQueryId,
                        'text' => '操作时间已失效',
                        'show_alert' => true,
                        'cache_time' => 5
                    ];
                    send($token,'answerCallbackQuery', $content);
                }
               
                break;     
                
                
            case '/kwfilter_xiaoxi_del':
                $this->kwfilter_xiaoxi_del($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid);
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
            default:
                if (strpos($text, '/kwfilter_zhuanfa_set_type:') === 0) {
        	    $string = str_replace('/kwfilter_zhuanfa_set_type:', '', $text);
                $parts = explode('_', $string);
                $iszhuanfa= $parts[0]; // 固定为 00:00
                $bgid =$parts[1];
                Db::name('botgroup')->where('id',$bgid)->update(['iszhuanfa'=>$iszhuanfa]);    
                $cacheKey = "botgroup_cache";
                $botGroups = Db::name('botgroup')->order("id desc")->select()->toArray(); // 查询所有 botgroup 数据
                Cache::store('redis')->set($cacheKey, $botGroups, 3600); // 缓存查询结果
                $this->kwfilter_zhuanfa_set($chat_id, $token, $messageId,$bgid);
                return;
                }
        	  break;      
        }
    }
    protected function kwfilter($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid){
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '消息过滤关键字', 'callback_data' => '/kwfilter_xiaoxi_word:' . $bgid]],
                [['text' => '名字过滤关键字', 'callback_data' => '/kwfilter_mingzi_word:' . $bgid]],
                [['text' => '文本过滤删除规则', 'callback_data' => '/kwfilter_xiaoxi_del:' . $bgid]],
                [['text' => '文本转发设置', 'callback_data' => '/kwfilter_zhuanfa_set:' . $bgid]],
                [['text' => '🔙 返回', 'callback_data' => '/group_setting_botquninfo:' . $bgid]]
            ]
        ];
               $rs=Db::name('botgroup')->where('id',$bgid)->find();     
               $sendtext="👥群组批量设置【".$rs['title']."】 \n\n  文本过滤设置:"; 
                    $content = [
                        'chat_id' => $chat_id,
                        'reply_markup' => json_encode($keyboard),
                        'message_id' => $messageId,
                        'text' => $sendtext,
                        'parse_mode' => 'Markdown' // 改为 Markdown 格式
                    ];
                    
                    // 发送请求以编辑消息
        $response = send($token, 'editMessageText', $content);
       
    }
    
    protected function kwfilterreturn($chat_id,  $token, $messageId,$bgid,$num){
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '消息过滤关键字', 'callback_data' => '/kwfilter_xiaoxi_word:' . $bgid]],
                [['text' => '名字过滤关键字', 'callback_data' => '/kwfilter_mingzi_word:' . $bgid]],
                [['text' => '文本过滤删除规则', 'callback_data' => '/kwfilter_xiaoxi_del:' . $bgid]],
                [['text' => '🔙 返回', 'callback_data' => '/group_setting_botquninfo:' . $bgid]]
            ]
        ];
               $rs=Db::name('botgroup')->where('id',$bgid)->find();     
               $sendtext="👥群组批量设置【".$rs['title']."】 \n\n 添加成功 $num 条规则\n\n   继续添加:"; 
                    $content = [
                        'chat_id' => $chat_id,
                        'reply_markup' => json_encode($keyboard),
                        'message_id' => $messageId,
                        'text' => $sendtext,
                        'parse_mode' => 'MarkdownV2' // 改为 Markdown 格式
                    ];
                                        // 发送请求以编辑消息
        $response = send($token, 'editMessageText', $content);
        $node=$rs['node'];
        $groupIds = explode(',', trim($node, ','));
        $groupChatIds = Db::name('telegraggroup')
        ->whereIn('group_id', $groupIds)
        ->field('id,group_id, title')
        ->select()
        ->toArray();
        $sendtextq='';
        $qnum=0;
        foreach ($groupChatIds as $chatId) {
            $sendtextq.=$chatId['title']."\n";
             $qnum++; 
            } 
         $content = [
                        'chat_id' => $chat_id,
                        'text' => "一共 *$qnum 群*\n【".$sendtextq."】\n\n增加了*$num  条*规则",
                        'parse_mode' => 'MarkdownV2' // 改为 Markdown 格式
                    ];
                    
        send($token, 'sendMessage', $content);
    }
    
    protected function kwfilter_zhuanfa_set($chat_id, $token, $messageId,$bgid){
         $rs=Db::name('botgroup')->where('id',$bgid)->find(); 
         $currentType = $rs['iszhuanfa'] ?? 0;
         $options = [
            ['type' => 1, 'text' => '允许转发', 'callback_data' => '/kwfilter_zhuanfa_set_type:1_' . $bgid],
            ['type' => 2, 'text' => '删除消息', 'callback_data' => '/kwfilter_zhuanfa_set_type:2_' . $bgid],
            ['type' => 3, 'text' => '踢出群组', 'callback_data' => '/kwfilter_zhuanfa_set_type:3_' . $bgid],
            ['type' => 4, 'text' => '封禁用户', 'callback_data' => '/kwfilter_zhuanfa_set_type:4_' . $bgid],
            ['type' => 0, 'text' => '🔙 返回', 'callback_data' => '/kwfilter:' . $bgid] // 返回按钮
        ];
        $inlineKeyboard = [];
        foreach ($options as $option) {
            // 对于返回按钮，不加图标
            if ($option['type'] == 0) {
                $inlineKeyboard[] = [['text' => $option['text'], 'callback_data' => $option['callback_data']]];
            } else {
                // 根据选中状态添加选中图标或未选中图标
                $icon = ($option['type'] == $currentType) ? '✅' : '☑️';
                $inlineKeyboard[] = [['text' => $icon . ' ' . $option['text'], 'callback_data' => $option['callback_data']]];
            }
        }
        
        // 构造键盘数组
        $keyboard = ['inline_keyboard' => $inlineKeyboard];

               
               $sendtext="👥群组批量设置【".$rs['title']."】 \n\n  请选当用户转发文本消息后的操作:"; 
                    $content = [
                        'chat_id' => $chat_id,
                        'reply_markup' => json_encode($keyboard),
                        'message_id' => $messageId,
                        'text' => $sendtext,
                        'parse_mode' => 'Markdown' // 改为 Markdown 格式
                    ];
                    
                    // 发送请求以编辑消息
        $response = send($token, 'editMessageText', $content);
       
    }
    
    protected function kwfilter_xiaoxi_del($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid){
        $botinfo=Cache::store('redis')->get($this->cacheBot);
        $bot_id=$botinfo['bot_id'];
        $kwlist=Db::name('kwfilter')->where('bgid',$bgid)->select();
        $string='';
        foreach ($kwlist as $item){
            if($item['psid']==1){$pstype='【删除消息】';}
            if($item['psid']==2){$pstype='【踢出群组】';}
            if($item['psid']==3){$pstype='【封禁用户】';}
            if($item['type']==1){$type='【消息关键词】';}else{$type='【名字关键词】';}
            $string.="ID ".$item['id']." ".$pstype.$type.$item['keyword']."\n";
        }
                $bwButtons[] = [[
                        'text' => '❌撤销',
                        'callback_data' => '/kwfilter_xiaoxi_word_back:'.$bgid
                    ]];
                    $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                    $content = [
                    'chat_id' => $chat_id,
                    'text' => "删除规则,规则列表：\n\n $string \n请输入要删除的ID（ID后面那个数字，一行一条）：",
                    'reply_markup' => $replyMarkup,
                    'message_id' => $messageId,
                    'parse_mode' => 'MarkdownV2'
                ];
                    
                    $ttres = send($token, 'editMessageText', $content);
                    $redisKey = "kwfilterdel:$bot_id:add_status";
                    $redisHashKey = "kwfilterdel:$bot_id:addmessage";
                    $waiting = 'waiting_for_message';
                    
                    $this->redis->sadd($redisKey, $waiting);
                    $this->redis->hset($redisHashKey, $waiting, $messageId."_".$bgid);
                    // 设置过期时间（25分钟）
                    $this->redis->expire($redisKey, 25 * 60);
                    $this->redis->expire($redisHashKey, 25 * 60);
    }
    
    
    
    protected function kwfilter_xiaoxi_word($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid){
        $botinfo=Cache::store('redis')->get($this->cacheBot);
        $bot_id=$botinfo['bot_id'];
                $bwButtons[] = [[
                        'text' => '❌撤销',
                        'callback_data' => '/kwfilter_xiaoxi_word_back:'.$bgid
                    ]];
                    $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                    $content = [
                    'chat_id' => $chat_id,
                    'text' => "🔡请输入要屏蔽的消息关键字，同一个消息中的多个关键字请用空格隔开，如想屏蔽“点击头像，进群聊天”，您可以发送“点击 进群”，所有既包含“点击”且包含“进群”的消息都会被过滤。可以批量添加，每行表示一条规则（即空格之间表示且的关系，行与行表示或的关系）：",
                    'reply_markup' => $replyMarkup,
                    'message_id' => $messageId,
                    'parse_mode' => 'Markdown'
                ];
                    
                    $ttres = send($token, 'editMessageText', $content);
                    $redisKey = "kwfilter:$bot_id:add_status";
                    $redisHashKey = "kwfilter:$bot_id:addmessage";
                    $waiting = 'waiting_for_message';
                    
                    $this->redis->sadd($redisKey, $waiting);
                    $this->redis->hset($redisHashKey, $waiting, $messageId."_".$bgid);
                    // 设置过期时间（25分钟）
                    $this->redis->expire($redisKey, 25 * 60);
                    $this->redis->expire($redisHashKey, 25 * 60);
    }
    
    protected function kwfilter_mingzi_word($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid){
        $botinfo=Cache::store('redis')->get($this->cacheBot);
        $bot_id=$botinfo['bot_id'];
                $bwButtons[] = [[
                        'text' => '❌撤销',
                        'callback_data' => '/kwfilter_xiaoxi_word_back:'.$bgid
                    ]];
                    $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                    $content = [
                    'chat_id' => $chat_id,
                    'text' => "🔡请输入要屏蔽的 *名字* 关键字，同一个消息中的多个关键字请用空格隔开，如想屏蔽“点击头像，进群聊天”，您可以发送“点击 进群”，所有既包含“点击”且包含“进群”的消息都会被过滤。可以批量添加，每行表示一条规则（即空格之间表示且的关系，行与行表示或的关系）：",
                    'reply_markup' => $replyMarkup,
                    'message_id' => $messageId,
                    'parse_mode' => 'MarkdownV2'
                ];
                    
                    $ttres = send($token, 'editMessageText', $content);
                    $redisKey = "kwfiltermz:$bot_id:add_status";
                    $redisHashKey = "kwfiltermz:$bot_id:addmessage";
                    $waiting = 'waiting_for_message';
                    
                    $this->redis->sadd($redisKey, $waiting);
                    $this->redis->hset($redisHashKey, $waiting, $messageId."_".$bgid);
                    // 设置过期时间（25分钟）
                    $this->redis->expire($redisKey, 25 * 60);
                    $this->redis->expire($redisHashKey, 25 * 60);
    }
    
    protected function kwfilter_xiaoxi_word_add($chat_id, $psid, $keyword, $token, $messageId,$bgid,$bot_id){
        // 将文本按换行符分割成数组
	    $words = explode("\n", $keyword);
	    // 去除前后空格并过滤空行
	    $words = array_filter(array_map('trim', $words));
	    // 如果数组为空，直接返回
	    if (empty($words)) {
	        echo "没有需要处理的词条。";
	        return;
	    }
	    $insertData = [];
	    $num=0;
	    foreach ($words as $item) {
	        log::write($item);
            // 检查关键词是否已经存在
            $existing = Db::name('kwfilter')->where('bot_id', $bot_id)->where('bgid', $bgid)->where('keyword', $item)->where('type', 1)->find();

            if (!$existing) {
                 $insertData[] =[
	                'keyword' => $item,
	                'bot_id' => $bot_id,
	                'bgid' => $bgid,
	                'psid' => $psid,
	                'type' => 1
	            ];
                // 如果存在，更新对应的回复内容
                $num++;
            } 
            
        }

        // 批量插入不重复的记录
        if (!empty($insertData)) {
                Db::name('kwfilter')->insertAll($insertData);
        }
        
		$cachekeywordKey = "kl_bg_kwfilter";
        $cachekeywordData = Db::name('kwfilter')->select()->toArray();
        Cache::store('redis')->set($cachekeywordKey, $cachekeywordData, 3600);
        return $num;
    }
    protected function kwfilter_mingzi_word_add($chat_id, $psid, $keyword, $token, $messageId,$bgid,$bot_id){
        // 将文本按换行符分割成数组
	    $words = explode("\n", $keyword);
	    // 去除前后空格并过滤空行
	    $words = array_filter(array_map('trim', $words));
	    // 如果数组为空，直接返回
	    if (empty($words)) {
	        echo "没有需要处理的词条。";
	        return;
	    }
	    $insertData = [];
	    $num=0;
	    foreach ($words as $item) {
	        log::write($item);
            // 检查关键词是否已经存在
            $existing = Db::name('kwfilter')->where('bot_id', $bot_id)->where('bgid', $bgid)->where('keyword', $item)->where('type', 2)->find();

            if (!$existing) {
                 $insertData[] =[
	                'keyword' => $item,
	                'bot_id' => $bot_id,
	                'bgid' => $bgid,
	                'psid' => $psid,
	                'type' => 2
	            ];
                // 如果存在，更新对应的回复内容
                $num++;
            } 
            
        }

        // 批量插入不重复的记录
        if (!empty($insertData)) {
                Db::name('kwfilter')->insertAll($insertData);
        }
        
		$cachekeywordKey = "kl_bg_kwfilter";
        $cachekeywordData = Db::name('kwfilter')->select()->toArray();
        Cache::store('redis')->set($cachekeywordKey, $cachekeywordData, 3600);
        return $num;
    }
}
