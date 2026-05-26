<?php
namespace app\robot\controller;

use think\facade\Db;
use think\facade\Log;
use think\facade\Cache;

class Custommessage extends Apibot
{
    protected $cacheBot;
    
    public function __construct()
    {
        parent::__construct();       
        $this->cacheBot = $this->cacheBot;
    }

    public function handle($data)
    {
     
        $token=$data['token'];
        if (isset($data)&&$data['messagetype']==1) {
            $chat_id = $data['chat']['id'];
            $chatType = $data['chat']['type'];
            $text = $data['text']??'未知';
            $messageId = $data['message_id'] ?? null;
            $userId = $data['from']['id'];
        }
        if (isset($data)&&$data['messagetype']==2) {
            $last_name=isset($data['from']['last_name']);
            $name = $data['from']['first_name'].$last_name;
            
            $chat_id = $data['message']['chat']['id'];
            $chatType = $data['message']['chat']['type'];
            $text=$data['data'];
            $messageId = $data['message']['message_id']; 
    		$userId =$data['from']['id'];
    		$username ='';
    		$callbackQueryId = $data['id'];
            
        }
    
        // 处理接收到的数据
        $botinfo=Cache::store('redis')->get($this->cacheBot);
		$bot_id=$botinfo['bot_id'];
		
		if (preg_match('/^(.*):(\d+)$/', $text, $matches)) {
            $commeds = $matches[1]; // 提取到的文本
            $bgid = $matches[2];    // 提取到的数字
           // $text = $commeds;
        }else{
            $commeds=$text;
        }
		
        switch ($commeds) {
            ////消息开始设置
            case '/custom_message_start':
                // 处理 消息设置
                //$bgid='';
                $this->custom_message_start($chat_id, $chatType,$text, $userId, $token,$messageId,$bgid);
                break;
            ////消息状态
            case '/custom_message_start_status':
                 $isxx = Db::name('xiaoxi')->where('bot_id', $botinfo['bot_id'])->where('bgid', $bgid)->find();
                 if(!$isxx){
                $content = [
                        'callback_query_id' => $callbackQueryId,
                        'text' => '没有自定义消息 可以激活！',
                        'show_alert' => true,
                        'cache_time' => 5
                    ];
                    send($token,'answerCallbackQuery', $content);
                 }
                $latestRecord = Db::name('xiaoxi')->where('bot_id', $botinfo['bot_id'])->where('bgid', $bgid)->where('status', 0)->find();
                if($latestRecord){
                    Db::name('xiaoxi')->where('bot_id', $botinfo['bot_id'])->where('bgid', $bgid)->update(['status'=>1]);
                }else{
                  Db::name('xiaoxi')->where('bot_id', $botinfo['bot_id'])->where('bgid', $bgid)->update(['status'=>0]);  
                }
                // 处理 消息设置
                $this->custom_message_start($chat_id, $chatType,$text, $userId, $token,$messageId,$bgid);
                break;
            ////按钮设置    
            case '/custom_message_buttonset':
                // 处理 消息设置
                 $this->custom_message_buttonset($chat_id, $chatType,$text, $userId, $token,$messageId,$bgid);
                break;    
            ////消息是否删除所有    
            case '/custom_message_isdelall':
                // 处理 消息设置
                 $this->custom_message_isdelall($chat_id, $chatType,$text, $userId, $token,$messageId,$bgid);
                break;
            ////消息确定删除所有    
            case '/custom_message_delall':              
            
                Db::name('xiaoxi')->where('bot_id', $botinfo['bot_id'])->delete();
                $content = [
                        'callback_query_id' => $callbackQueryId,
                        'text' => '消息删除成功！',
                        'show_alert' => true,
                        'cache_time' => 5
                    ];
                    send($token,'answerCallbackQuery', $content);
                // 处理 消息设置
                 $this->custom_message_start($chat_id, $chatType,$text, $userId, $token,$messageId,$bgid);
                break;    
            ////消息添加消息    
            case '/custom_message_setting':
                // 处理 消息设置
                //$bgid='';
                $this->custom_message_setting($chat_id, $chatType,$text, $userId, $token,$messageId,$bgid);
                break;
            case '/custom_message_setting_back':
                $waiting='waiting_for_message';
                $redisKey = "customadd:$bot_id.txt:add_status";
                $redisKeyt = "customadd:$bot_id.photo:add_status";
                
                $redisHashKeycustomadd = "customadd:$bot_id.txt:addmessage";
                $redisHashKeycustomaddt = "customadd:$bot_id.photo:addmessage";
                 // 从集合中删除指定的 $data
                $this->redis->srem($redisKey,  $waiting);
                $this->redis->srem($redisKeyt,  $waiting);
                $this->redis->del($redisHashKeycustomadd, $waiting, $messageId);
                $this->redis->del($redisHashKeycustomaddt, $waiting, $messageId);
                
                // 处理 消息设置
                $this->custom_message_content_type($chat_id, $chatType,$text, $userId, $token,$messageId,$bgid);
                break;
 
///////////联系
            case '/custom_message_content_type':
                
                $this->custom_message_content_type($chat_id, $chatType,$text, $userId, $token,$messageId,$bgid);
                break;
////////////////////////////////////////////                
            case '/custom_message_content':
                $type=1;
                $this->custom_message_contentinfo($chat_id, $chatType,$text, $userId, $token,$messageId,$type,$bgid);
		        break;
            case '/custom_message_contentphoto':
                $type=2;
                $this->custom_message_contentinfo($chat_id, $chatType,$text, $userId, $token,$messageId,$type,$bgid);
		        break;

////////////////////////////////////////////                
            case '/custom_message_time':
                // 处理 消息设置
                $this->Custom_message_time($chat_id,$messageId,$token,$bgid);
                break;
////////////////////////////////////////////                
            case '/custom_message_timerepeat':
                $text='/custom_message_timerepeat';
                $this->checkForTelemessage($chat_id, $chatType,$text, $userId, $token,$messageId,$bgid);
                //$this->Custom_message_timerepeat($chat_id, $chatType,$text, $userId, $token,$messageId);
                break;
                
            case '/custom_message_timerepeat_back':
                // 处理 消息设置
                $this->processComUserMessage($data);
                break;
////////////////////////////////////////////                
            case '/custom_message_top':
                // 获取当前 is_top 值
                $current_is_top = Db::name('xxsetting')->where('user_id', $botinfo['bot_id'])->where('bgid', $bgid)->value('is_top');
                // 判断并设置 is_top 值
                $is_top = ($current_is_top == 0) ? 1 : 0;
                // 更新 is_top 值
                Db::name('xxsetting')->where('user_id', $botinfo['bot_id'])->where('bgid', $bgid)->update(['is_top' => $is_top]);
                
                $text='/custom_message_setting:'.$bgid;
                // 处理 消息设置
                $this->custom_message_setting($chat_id, $chatType,$text, $userId, $token,$messageId,$bgid);
                break;
////////////////////////////////////////////                 
            case '/custom_message_content_del':
                
                $current_is_del = Db::name('xxsetting')->where('user_id', $botinfo['bot_id'])->where('bgid', $bgid)->value('is_del');
                $is_del = ($current_is_del == 0) ? 1 : 0;
                Db::name('xxsetting')->where('user_id', $botinfo['bot_id'])->where('bgid', $bgid)->update(['is_del' => $is_del]);
                // 处理 banwords_button 逻辑
                $text='/custom_message_setting:'.$bgid;
                $this->custom_message_setting($chat_id, $chatType,$text, $userId, $token,$messageId,$bgid);
                break;
///////////////////////button_add                
            case '/custom_message_buttonset_add':
                
                $this->custom_message_buttonset_add($chat_id, $chatType,$text, $userId, $token,$messageId,$bgid);
                break;     
            case '/custom_message_buttonset_add_back':
                // 处理 消息设置
                $waiting='waiting_for_message';
                $redisKey = "xxButtonadd:$bot_id:add_status";
                $redisHashKey = "xxButtonadd:$bot_id:addmessage";
                 // 从集合中删除指定的 $data
                $this->redis->srem($redisKey,  $waiting);
                $this->redis->del($redisHashKey);
                
                // 处理 消息设置
                $this->custom_message_setting($chat_id, $chatType,$text, $userId, $token,$messageId,$bgid);
                break; 
 ///////////////////////button_del                  
            case '/custom_message_buttonset_del':
                Db::name('xxsetting')->where('user_id', $botinfo['bot_id'])->update(['buttonset'=>null]);
                $content = [
                        'callback_query_id' => $callbackQueryId,
                        'text' => '删除成功！',
                        'show_alert' => true,
                        'cache_time' => 5
                    ];
                    send($token,'answerCallbackQuery', $content);
                    $this->custom_message_buttonset($chat_id, $chatType,$text, $userId, $token,$messageId,$bgid);
                break;   
            default:
                if (strpos($text, '/custom_message_info_id:') === 0) {
                    $string = str_replace('/custom_message_info_id:', '', $text);
                    $parts = explode('_', $string);
                    //return;
                    $xx_id= $parts[0]; 
                    $bgid =$parts[1];
                    $this->custom_message_info_edit($chat_id,$messageId,$token,$xx_id, $bgid);
                }elseif (strpos($text, '/custom_message_time_setting_hour:') === 0) {
                    $string = str_replace('/custom_message_time_setting_hour:', '', $text);
                    $parts = explode('_', $string);
                    $hour= $parts[0]; 
                    $bgid =$parts[1];
                    $this->Custom_message_time_setting_hour($chat_id,$messageId,$token,$hour, $bgid);
                }elseif(strpos($text, '/custom_message_time_setting_min:') === 0) {
                    $string = str_replace('/custom_message_time_setting_min:', '', $text);
                    $parts = explode('_', $string);
                    $send_time= $parts[0]; 
                    $bgid =$parts[1];
                    Db::name('xxsetting')->where('user_id', $botinfo['bot_id'])->update(['send_time'=>$send_time]);
                    
                    $content = [
                        'callback_query_id' => $callbackQueryId,
                        'text' => '推送时间设置成功！',
                        'show_alert' => true,
                        'cache_time' => 5
                    ];
                    send($token,'answerCallbackQuery', $content);
                    
                    
                    $text='/custom_message_setting';
                    $this->custom_message_setting($chat_id, $chatType,$text, $userId, $token,$messageId,$bgid);
                }elseif(strpos($text, '/custom_message_timerepeat_hour:') === 0) {
                    $string = str_replace('/custom_message_timerepeat_hour:', '', $text);
                    $parts = explode('_', $string);
                    $mintime= $parts[0]; 
                    $bgid =$parts[1];
                    Db::name('xxsetting')->where('user_id', $botinfo['bot_id'])->where('bgid', $bgid)->update(['repeat_interval'=>$mintime]);
                    $text='/custom_message_setting';
                    $this->custom_message_setting($chat_id, $chatType,$text, $userId, $token,$messageId,$bgid);
                }elseif(strpos($text, '/custom_message_nowpush:') === 0) {
                    $content = [
                        'callback_query_id' => $callbackQueryId,
                        'text' => '消息推送成功！',
                        'show_alert' => true,
                        'cache_time' => 5
                    ];
                    send($token,'answerCallbackQuery', $content);
                    $string = str_replace('/custom_message_nowpush:', '', $text);
                    $parts = explode('_', $string);
                    $xxid= $parts[0]; 
                    $bgid =$parts[1];
                    $this->custom_message_nowpush($chat_id, $chatType,$text, $userId, $token,$messageId,$xxid,$bgid);
                }
                
                 
                // 处理其他情况
                break;
        }
    }
   
    
   
    protected function custom_message_contentinfo($chat_id, $chatType,$text, $userId, $token,$messageId,$type,$bgid){
      $botinfo=Cache::store('redis')->get($this->cacheBot); 	
	  $bot_id=$botinfo['bot_id'];
        $xxsetting=Db::name('xxsetting')->where('user_id', $botinfo['bot_id'])->where('bgid', $bgid)->find();
        if($xxsetting){
        	$xxstr="";
        	if($xxsetting['send_time']){
        		$xxstr.="🕑 时间: ".$xxsetting['send_time']."\n\n";
        	}else{
        		$xxstr.="🕑 时间: ❌"."\n\n";
        	}
        	if($xxsetting['repeat_interval']){
        		$repeat_interval = $xxsetting['repeat_interval'];
                    if ($repeat_interval < 60) {
                        $interval_display = $repeat_interval . ' 分钟';
                    } else {
                        $hours = floor($repeat_interval / 60);
                        $interval_display = $hours . ' 小时';
                    }
        		$xxstr.="🔁 重复: ".$interval_display."\n\n";
        	}else{
        		$xxstr.="🔁 重复: ❌"."\n\n";
        	}
           
       
        		$bwButtons[] = [[
        			'text' => '🔙 返回',
        			'callback_data' => '/custom_message_setting_back:'.$bgid
        		]];
        		$replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
        	    if($type==1){
        	        $sendtext="👉🏻 现在发送消息,你想要设置: \n\n $xxstr 你可以发送已经格式化的消息或者 HTML 代码。";
        	        $mtype='txt';
        	    }else{
        	         $sendtext="👉🏻 现在发送消息,你想要设置:\n\n $xxstr 现在发送媒体（图片）。";
        	         $mtype='photo';
        	    }    
        		$content = [
        			'chat_id' => $chat_id,
        			'text' => $sendtext,
        			'reply_markup' => $replyMarkup,
        			'message_id' => $messageId,
        			'parse_mode' => 'MarkdownV2' 
        		];
        		$ttres=send($token,'editMessageText', $content);
        	  
        		$redisKey = "customadd:$bot_id.$mtype:add_status";
				$redisHashKey = "customadd:$bot_id.$mtype:addmessage";
        		$waiting='waiting_for_message';
        		// 将消息 ID 推入 Redis 列表
        		$this->redis->sadd($redisKey, $waiting);
				$this->redis->hset($redisHashKey, $waiting, $messageId."_".$bgid);
        				// 设置列表的过期时间（例如一周后自动清理）
        		$this->redis->expire($redisKey, 25 * 60); // 7天
				$this->redis->expire($redisHashKey, 25 * 60);
        	
        	
        }
    }
    protected function custom_message_content_type($chat_id, $chatType,$text, $userId, $token,$messageId,$bgid){
        $botinfo=Cache::store('redis')->get($this->cacheBot); 
        $xxsetting=Db::name('xxsetting')->where('user_id', $botinfo['bot_id'])->where('bgid', $bgid)->find();
        if($xxsetting){
        	$xxstr="";
        	if($xxsetting['send_time']){
        		$xxstr.="🕑 时间: ".$xxsetting['send_time']."\n\n";
        	}else{
        		$xxstr.="🕑 时间: ❌"."\n\n";
        	}
        	if($xxsetting['repeat_interval']){
        	    $repeat_interval = $xxsetting['repeat_interval'];
                    if ($repeat_interval < 60) {
                        $interval_display = $repeat_interval . ' 分钟';
                    } else {
                        $hours = floor($repeat_interval / 60);
                        $interval_display = $hours . ' 小时';
                    }
        		$xxstr.="🔁 重复: ".$interval_display."\n\n";
        	}else{
        		$xxstr.="🔁 重复: ❌"."\n\n";
        	}
        	
        	if(!$xxsetting['send_time']||!$xxsetting['repeat_interval']){
        		$bwButtons[] = [[
        		'text' => '🔙 返回',
        		'callback_data' => '/custom_message_setting:'.$bgid
        	]];
        	
        	$replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
        	
        	$content = [
        		'chat_id' => $chat_id,
        		'text' => "👉🏻 发送消息的设置参数没有设定：\n\n".$xxstr,
        		'reply_markup' => $replyMarkup,
        		'message_id' => $messageId,
        		'parse_mode' => 'MarkdownV2' 
        	];
        	$ttres=send($token,'editMessageText', $content);
        	}else{
        
        	    $bwButtons[] = [[
        			'text' => '🗓 文本',
        			'callback_data' => '/custom_message_content:'.$bgid
        		]];
        		$bwButtons[] = [[
        			'text' => '🖼 图片',
        			'callback_data' => '/custom_message_contentphoto:'.$bgid
        		]];
        		$bwButtons[] = [[
        			'text' => '🔙 返回',
        			'callback_data' => '/custom_message_setting:'.$bgid
        		]];
        		$replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
        	 
        		$content = [
        			'chat_id' => $chat_id,
        			'text' => "👉🏻 现在发送消息你想要设置的。\n\n $xxstr 你可以选择文本或者图片操作。",
        			'reply_markup' => $replyMarkup,
        			'message_id' => $messageId,
        			'parse_mode' => 'MarkdownV2' 
        		];
        		$ttres=send($token,'editMessageText', $content);
        		
        	
        	}
        }
    }
    protected function custom_message_isdelall($chat_id, $chatType,$text, $userId, $token,$messageId,$bgid){
        $hoursButtons = [
        	[
        	    	['text' => '✅ 确定删除所有消息', 'callback_data' => '/custom_message_delall:'.$bgid],
        		],[['text' => '🔙 取消', 'callback_data' => '/custom_message_start:'.$bgid] // 确保按钮是对象
        	]
        ];
        $replyMarkup = json_encode(['inline_keyboard' => $hoursButtons]);
        $content = [
        	'chat_id' => $chat_id,
        	'text' => "🕑 重发消息\n\n⚠️ 你确定要删除所有自定义消息吗？",
        	'reply_markup' => $replyMarkup,
        	'message_id' => $messageId,
        	'parse_mode' => 'MarkdownV2' 
        ];
        
        $ttres=send($token,'editMessageText', $content);
    }
    //编辑自定义
    protected function custom_message_info_edit($chat_id,$messageId,$token,$xx_id,$bgid){
          $buttons = getTelebuttonByTmgId(12);
        
            // 如果存在自定义按钮，生成键盘按钮并发送
           
            $keyboard = [];
            $currentRow = [];
            $currentRowNumber = null;
            $maxColumns = 3; // 默认列数
            $keyboard[] = [[
                'text' => '🗑删除该消息',
                'callback_data' => '/custom_infoedit_/custom_message_isdel:'.$xx_id
            ]];
            $keyboard[] = [[
                'text' => '👀 查看该消息',
                'callback_data' => '/custom_infoedit_/custom_message_see:'.$xx_id
            ]];
            foreach ($buttons as $button) {
                // 如果进入了新的行，或者行号不同，则重置当前行
                if ($button['row_number'] !== $currentRowNumber) {
                    // 如果当前行有按钮，则先将这一行添加到键盘布局中
                    if (!empty($currentRow)) {
                        $keyboard[] = $currentRow;
                    }
                    // 重置当前行
                    $currentRow = [];
                    $currentRowNumber = $button['row_number'];
                    // 设置每行按钮的列数
                    $maxColumns = $button['column_number'];
                }
                $buttontitle=$button['title'];
                // 添加按钮到当前行
                if($button['content']!='/custom_message_start'){
                    $currentRow[] = ['text' => $button['title'], 'callback_data' => "/custom_infoedit_".$button['content'].":".$xx_id];
                }else{
                    $currentRow[] = ['text' => $button['title'], 'callback_data' => $button['content'].":".$bgid];
                }
                // 如果当前行已达到最大列数，则将该行推入键盘布局，并重置当前行
                if (count($currentRow) >= $maxColumns) {
                    $keyboard[] = $currentRow;
                    $currentRow = [];
                }
            }
            
            // 如果最后一行的按钮没有达到最大列数，依然需要加入键盘
            if (!empty($currentRow)) {
                $keyboard[] = $currentRow;
            }
            $keyboard[] = [[
                'text' => '立即推送',
                'callback_data' => '/custom_message_nowpush:'.$xx_id.'_'.$bgid
            ]];
            $keyboard[] = [[
                'text' => '❌关闭',
                'callback_data' => '/closeMessage'
            ]];
            $keyboard = [
                'inline_keyboard' => $keyboard,
            ];
           
            $xxinfo = Db::name('xiaoxi')->where('id',$xx_id)->find();
            
            $str='';
            if($xxinfo){
                $repeat_interval = $xxinfo['repeat_interval'];
                    if ($repeat_interval < 60) {
                        $interval_display = $repeat_interval . ' 分钟';
                    } else {
                        $hours = floor($repeat_interval / 60);
                        $interval_display = $hours . ' 小时';
                    }
                if($xxinfo['photo']){
                    $str .= "这条消息是: 🖼\n";
                }else{
                    $str .= "这条消息是: 📝\n";
                }    
                $str .= "🕑 时间: " . $xxinfo['send_time'] . "\n";
                $str .= "📌 置顶: " . ($xxinfo['is_top'] ? '是✔️' : '否✖️') . "\n";
                $str .= "🔁 重复:每 " . $interval_display . "\n"; 
                $str .= "♻️ 删除上一条: " . ($xxinfo['is_del'] ? '是✔️' : '否✖️') . "\n";
                $str .= "❇️ 按钮: " . ($xxinfo['buttonset'] ? '是' : '否') . "\n";
                
            }
            
            
            $buttonData = [
                'chat_id' => $chat_id,
                'text' => "📖编辑自定义消息设置。下方按钮操作为管理该消息的参数设置。\n\n$str",
                'message_id' => $messageId,
                'reply_markup' => json_encode($keyboard), // 将键盘数据进行 JSON 序列化
            ];
    
            send($token, 'editMessageText', $buttonData);
       
    }
    
    
    protected function custom_message_start($chat_id, $chatType,$text, $userId, $token,$messageId,$bgid){
      
            // 如果存在自定义按钮，生成键盘按钮并发送
            $keyboard = [];
            $botinfo=Cache::store('redis')->get($this->cacheBot);        
            $xiaoxi = Db::name('xiaoxi')->where('bot_id',$botinfo['bot_id'])->where('bgid',$bgid)->order('id', 'desc')->select();
            $str='';
            if($xiaoxi){
                $str.="已设置消息：\n";
              
                $buttonRow = []; // 用于存储当前行的按钮
                $buttonCount = 0; // 当前行按钮计数器
                foreach ($xiaoxi as $k => $xx) {
                    // 处理重复间隔
                    $repeat_interval = $xx['repeat_interval'];
                    if ($repeat_interval < 60) {
                        $interval_display = $repeat_interval . ' 分钟';
                    } else {
                        $hours = floor($repeat_interval / 60);
                        $interval_display = $hours . ' 小时';
                    }
            
                    // 截取 content 的前 20 个字符
                    $content_display = mb_substr($xx['content'], 0, 60) . '...'; // 如果内容超过 20 字符，加上省略号
                    // 拼接字符串
                    $k=$k+1;
                    $str .= "🗯".$k. "\n";
                    $str .= "状态: " . ($xx['status'] ? '激活✅ ' : '关闭 ❌') . "\n";
                    $str .= "时间: " . $xx['send_time'] . "\n";
                    $str .= "置顶: " . ($xx['is_top'] ? '是' : '否') . "\n";
                    $str .= "重复: 每" . $interval_display . "\n";
                    $str .= "按钮: " . ($xx['buttonset'] ? '是' : '否') . "\n";
                    $str .= "-----------------------\n";  // 添加分隔线
                    $buttonRow[]=[
                        'text' => "🗯".$k,
                        'callback_data' => '/custom_message_info_id:'.$xx['id']."_".$bgid
                        ];
                   $buttonCount++;
                    // 每五个按钮换一行
                    if ($buttonCount == 5) {
                        $keyboard[] = $buttonRow; // 将当前行的按钮添加到键盘
                        $buttonRow = []; // 清空当前行
                        $buttonCount = 0; // 重置计数器
                    }     
                }
               // 检查是否还有剩余按钮需要添加
                if (!empty($buttonRow)) {
                    $keyboard[] = $buttonRow; // 添加剩余的按钮
                } 
               
            }else{
                $str.="\n无自定义消息";
            }
            $xxst= Db::name('xiaoxi')->where('bot_id',$botinfo['bot_id'])->where('bgid',$bgid)->where('status',0)->find();
            if($xxst){
                $xxstatus="❌关闭";
            }else{
               $xxstatus='✅ 激活'; 
            }
            $keyboard[] = [[
                'text' =>$xxstatus,
                'callback_data' => '/custom_message_start_status:'.$bgid
            ],[
                'text' => '🗑 删除',
                'callback_data' => '/custom_message_isdelall:'.$bgid
            ]];
            
            $keyboard[] = [[
                'text' => '📝 添加消息',
                'callback_data' => '/custom_message_setting:'.$bgid
            ]];
           /* $keyboard[] = [[
                'text' => '‼️ 立即推送消息',
                'callback_data' => '/custom_message_nowpush'
            ]];*/
            $keyboard[] = [[
                'text' => '🔙 返回',
                'callback_data' => '/group_setting_botquninfo:'.$bgid
            ]];
            
            $keyboard = [
                'inline_keyboard' => $keyboard,
            ];
            $date=date('Y-m-d H:i',time());
            $buttonData = [
                'chat_id' => $chat_id,
                'text' => "📖自定义消息设置。\n\n从此菜单你可以设置在群组中每隔几分钟/小时重复发送的消息\n\n * 当前时间：* $date  \n\n$str",
                'message_id' => $messageId,
                'reply_markup' => json_encode($keyboard), // 将键盘数据进行 JSON 序列化
            ];
    
            send($token, 'editMessageText', $buttonData);
        
    }
////////////添加消息设置   
    protected function custom_message_setting($chat_id, $chatType,$text, $userId, $token,$messageId,$bgid){
        $botinfo=Cache::store('redis')->get($this->cacheBot);
        $buttons =getTelebuttonByTmgId(12);
        
            // 如果存在自定义按钮，生成键盘按钮并发送
           
            $keyboard = [];
            $currentRow = [];
            $currentRowNumber = null;
            $maxColumns = 3; // 默认列数
            foreach ($buttons as $button) {
                // 如果进入了新的行，或者行号不同，则重置当前行
                if ($button['row_number'] !== $currentRowNumber) {
                    // 如果当前行有按钮，则先将这一行添加到键盘布局中
                    if (!empty($currentRow)) {
                        $keyboard[] = $currentRow;
                    }
                    // 重置当前行
                    $currentRow = [];
                    $currentRowNumber = $button['row_number'];
                    // 设置每行按钮的列数
                    $maxColumns = $button['column_number'];
                }
                $buttontitle=$button['title'];
                // 添加按钮到当前行
                $currentRow[] = ['text' => $button['title'], 'callback_data' => $button['content'].":".$bgid];
            
                // 如果当前行已达到最大列数，则将该行推入键盘布局，并重置当前行
                if (count($currentRow) >= $maxColumns) {
                    $keyboard[] = $currentRow;
                    $currentRow = [];
                }
            }
            
            // 如果最后一行的按钮没有达到最大列数，依然需要加入键盘
            if (!empty($currentRow)) {
                $keyboard[] = $currentRow;
            }
            $keyboard[] = [[
                'text' => '❌关闭',
                'callback_data' => '/closeMessage'
            ]];
            $keyboard = [
                'inline_keyboard' => $keyboard,
            ];
            $xxsetting=Db::name('xxsetting')-> where('user_id', $botinfo['bot_id'])->where('bgid', $bgid)->find();          
          
           // $xiaoxi = Db::name('xiaoxi')->where('group_id',$chat_id)->where('username',$bot['chat_id'])->where('status',1)->order('id', 'desc')->select();
            
            $str='';
            if($xxsetting){
                $repeat_interval = $xxsetting['repeat_interval'];
                    if ($repeat_interval < 60) {
                        $interval_display = $repeat_interval . ' 分钟';
                    } else {
                        $hours = floor($repeat_interval / 60);
                        $interval_display = $hours . ' 小时';
                    }    
                $str .= "🕑 时间: " . substr($xxsetting['send_time'], 0, 5) . "\n";
                $str .= "📌 置顶: " . ($xxsetting['is_top'] ? '是✔️' : '否✖️') . "\n";
                $str .= "🔁 重复:每 " . $interval_display . "\n"; 
                $str .= "♻️ 删除上一条: " . ($xxsetting['is_del'] ? '是✔️' : '否✖️') . "\n";
                $str .= "❇️ 按钮链接: " . ($xxsetting['buttonset'] ? '是✔️' : '否✖️') . "\n";
            }
            
            
            $buttonData = [
                'chat_id' => $chat_id,
                'text' => "📖添加自定义消息设置。下方按钮操作为添加该消息的参数设置。\n\n$str",
                'message_id' => $messageId,
                'reply_markup' => json_encode($keyboard), // 将键盘数据进行 JSON 序列化
            ];
    
            send($token, 'editMessageText', $buttonData);
                        
    }
    
    protected function Custom_message_time_setting_hour($chat_id,$messageId,$token,$hour,$bgid){
        $minButtons = [];
        $row = []; // 用于存储一行的按钮
        $hour = sprintf('%02d', $hour);
        // 创建小时按钮，分为五行
        for ($i = 0; $i <= 59; $i++) {
        	// 添加按钮到当前行
        	$i=sprintf('%02d', $i);
        	$row[] = ['text' => (string)$i, 'callback_data' => '/custom_message_time_setting_min:'.$hour.':'.(string)$i.'_'.$bgid]; // 确保按钮是对象
        	
        
        	// 每五个按钮后添加一行
        	if (count($row) == 6) {
        		$minButtons[] = $row; // 将当前行添加到按钮组
        		$row = []; // 清空当前行
        	}
        }
        
        // 如果还有剩余按钮，添加到按钮组
        if (!empty($row)) {
        	$minButtons[] = $row; // 添加剩余的按钮
        }
        
        // 添加关闭按钮
        $minButtons[] = [[
        	'text' => '🔙 返回',
        	'callback_data' => '/custom_message_time:'.$bgid
        ]];
        $replyMarkup = json_encode(['inline_keyboard' => $minButtons]);
        $content = [
        	'chat_id' => $chat_id,
        	'text' => "🕑 自定义消息推送 \n\n推送小时为：*{$hour}点*\n\n👉🏻 选择开始推送分钟：\n",
        	'reply_markup' => $replyMarkup,
        	'message_id' => $messageId,
        	'parse_mode' => 'MarkdownV2' 
        ];
        
        send($token,'editMessageText', $content);
    }
    protected function Custom_message_time($chat_id,$messageId,$token,$bgid){
        $hoursButtons = [];
    	$row = []; // 用于存储一行的按钮
    
    	// 创建小时按钮，分为五行
    	for ($i = 0; $i <= 23; $i++) {
    		// 添加按钮到当前行
    		$row[] = ['text' => (string)$i, 'callback_data' => '/custom_message_time_setting_hour:'.(string)$i.'_'.$bgid]; // 确保按钮是对象
    		
    
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
    		'callback_data' => '/custom_message_setting:'.$bgid
    	]];
    	$replyMarkup = json_encode(['inline_keyboard' => $hoursButtons]);
    	$content = [
    		'chat_id' => $chat_id,
    		'text' => "🕑 自定义消息推送 \n\n👉🏻 选择开始推送小时：\n",
    		'reply_markup' => $replyMarkup,
    		'message_id' => $messageId,
    		'parse_mode' => 'MarkdownV2' 
    	];
    	
    	send($token,'editMessageText', $content);
    }
    

    
    private function checkForTelemessage($chat_id,$chatType, $text, $fromUserId, $token,$messageId,$bgid)
    {
        $botinfo=Cache::store('redis')->get($this->cacheBot);
        $bot_id=$botinfo['bot_id'];
        $bot_name=$botinfo['bot_name'];
        if($chatType=='private'){
            $chType=1;
        }
        if($chatType=='group'||$chatType=='supergroup'){
            $chType=2;
        }
       
        $where['bot_id']=$bot_id;
        $map1 = [
            ['bot_id', '=', $bot_id],
            ['title', '=', $text],
            ['status', '=', 1],
            ['chattype', 'in', [0,$chType]],
        ];
    
        $map2 = [
            ['bot_id', '=', NULL],
            ['title', '=', $text],
            ['status', '=', 1],
            ['chattype', 'in', [0,$chType]],
        ];
        // 查询 telemessage 表，查找与消息内容匹配的自定义消息
        $telemessage = Db::name('telemessage')
            ->whereOr([ $map1, $map2 ])
            ->find();
        
        if (!$telemessage) {
            // 如果没有找到匹配的自定义消息，则返回 false
            return false;
        }
        /*if($telemessage['title']=='/settings'){
         $telemessage['content']=$telemessage['content'];
        }*/
        // 获取自定义消息内容
        $customMessage = $telemessage['content'];
        
        // 查询 telebutton 表，查找与该自定义消息相关的按钮
        $buttons = getTelebuttonByTmgId($telemessage['id']); 
    
        if (!empty($buttons)) {
            // 如果存在自定义按钮，生成键盘按钮并发送
           
            $keyboard = [];
            $currentRow = [];
            $currentRowNumber = null;
            $maxColumns = 3; // 默认列数
            foreach ($buttons as $button) {
                // 如果进入了新的行，或者行号不同，则重置当前行
                if ($button['row_number'] !== $currentRowNumber) {
                    // 如果当前行有按钮，则先将这一行添加到键盘布局中
                    if (!empty($currentRow)) {
                        $keyboard[] = $currentRow;
                    }
                    // 重置当前行
                    $currentRow = [];
                    $currentRowNumber = $button['row_number'];
                    // 设置每行按钮的列数
                    $maxColumns = $button['column_number'];
                }
                $buttontitle=$button['title'];
                echo "button $buttontitle\n";
                if($buttontitle=='添加我到群组')    {
                    $button['content']='https://t.me/'.$bot_name.$button['content'];
                }
                // 添加按钮到当前行
                $currentRow[] = ['text' => $button['title'], 'callback_data' => $button['content']."_".$bgid];
            
                // 如果当前行已达到最大列数，则将该行推入键盘布局，并重置当前行
                if (count($currentRow) >= $maxColumns) {
                    $keyboard[] = $currentRow;
                    $currentRow = [];
                }
            }
            
            // 如果最后一行的按钮没有达到最大列数，依然需要加入键盘
            if (!empty($currentRow)) {
                $keyboard[] = $currentRow;
            }
            $keyboard[] = [[
        			'text' => '🔙 返回',
        			'callback_data' => '/custom_message_setting:'.$bgid
        		]];
            $keyboard = [
                'inline_keyboard' => $keyboard,
            ];
    
            $buttonData = [
                'chat_id' => $chat_id,
                'text' => $customMessage,
                'message_id' => $messageId,
                'reply_markup' => json_encode($keyboard), // 将键盘数据进行 JSON 序列化
            ];
    
            send($token, 'editMessageText', $buttonData);
            
        }else{
            // 发送自定义消息到群组
            $messageData = [
                'chat_id' => $chat_id,
                'text' => $customMessage,
            ];
            send($token, 'sendMessage', $messageData);
            
        }
    
        // 如果找到了自定义消息，并且可能发送了按钮，返回 true
        return true;
    }
    
    
    protected function custom_message_buttonset($chat_id, $chatType,$text, $userId, $token,$messageId,$bgid){
      $botinfo=Cache::store('redis')->get($this->cacheBot); 
        $xxsetting=Db::name('xxsetting')->where('user_id', $botinfo['bot_id'])->where('bgid', $bgid)->find();
        if($xxsetting){
        	$xxstr="";
        	if($xxsetting['buttonset']){
        		$xxstr.=" ❇️按钮效果如下: \n\n";
        		$buttons = explode("\n", trim($xxsetting['buttonset']));
                        $bwButtons = []; // 按钮格式化数组
                        foreach ($buttons as $button) {
                            $result = preg_replace('/<a href="([^"]+)">.*?<\/a>/', '$1', $button);
                            $parts = explode('#', $result);
                            if (count($parts) === 2) {
                                $bwButtons[] = [[
                                    'text' => $parts[0], // 按钮文本
                                    'url' => $parts[1]  // 按钮链接
                                ]];
                                
                            }
                        }
        	}else{
        		$xxstr.="❇️按钮: 无"."\n\n";
        	}
        	
           
                $bwButtons[]= [[
        			'text' => '➕按钮',
        			'callback_data' => '/custom_message_buttonset_add:'.$bgid
        		],[
        			'text' => '➖按钮',
        			'callback_data' => '/custom_message_buttonset_del:'.$bgid
        		]];
        		$bwButtons[] = [[
        			'text' => '🔙 返回',
        			'callback_data' => '/custom_message_setting:'.$bgid
        		]];
        		$replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                log::write($replyMarkup);
        	   $sendtext="发送自定义消息下方按钮链接设置: \n\n $xxstr ";
        	   $mtype='txt';
        		$content = [
        			'chat_id' => $chat_id,
        			'text' => $sendtext,
        			'reply_markup' => $replyMarkup,
        			'message_id' => $messageId,
        			'parse_mode' => 'MarkdownV2' 
        		];
        		$ttres=send($token,'editMessageText', $content);
        }
    }
    
    protected function custom_message_buttonset_add($chat_id, $chatType,$text, $userId, $token,$messageId,$bgid){
        $botinfo=Cache::store('redis')->get($this->cacheBot);
		$bot_id=$botinfo['bot_id'];
		
        $bwButtons[] = [[
        	'text' => '❌撤销',
        	'callback_data' => '/custom_message_buttonset_add_back:'.$bgid
        ]];
        $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
        
        $content = [
        'chat_id' => $chat_id,
        'text' => "\n设置按钮链接，\n\n 发送消息时下方的按钮\n存入格式为：\n百度#baidu.com\n百度#baidu.com\n百度#baidu.com",
        'reply_markup' => $replyMarkup,
        'message_id' => $messageId,
        'parse_mode' => 'Markdown'
        ];
        
        $ttres = send($token, 'editMessageText', $content);
        $redisKey = "xxButtonadd:$bot_id:add_status";
        $redisHashKey = "xxButtonadd:$bot_id:addmessage";
        $waiting = 'waiting_for_message';
        
        $this->redis->sadd($redisKey, $waiting);
        $this->redis->hset($redisHashKey, $waiting, $messageId.'_'.$bgid);
        
        // 设置过期时间（25分钟）
        $this->redis->expire($redisKey, 25 * 60);
        $this->redis->expire($redisHashKey, 25 * 60);
                        
    }
    protected function custom_message_nowpush($chat_id, $chatType,$text, $userId, $token,$messageId,$xxid,$bgid){
        $xxinfo = Db::name('xiaoxi')->where('id',$xxid)->find();
       // log::info($xxid);
        $bginfo = Db::name('botgroup')->where('id',$bgid)->find();
        
        $node=$bginfo['node'];
        $groupIds = explode(',', trim($node, ','));
        $results = Db::name('telegraggroup')
        ->whereIn('group_id', $groupIds)
        ->where('bot_id', $bginfo['bot_id'])
        ->field('id,group_id, title')
        ->select()
        ->toArray();
        foreach ($results as $row) {
            $buttonset=$xxinfo['buttonset'];
            $bwButtons = []; 
            if($buttonset){
            $buttons = explode("\n", trim($buttonset));
                        // 按钮格式化数组
                        foreach ($buttons as $button) {
                           // $text = '百度#<a href="https://t.me/ABCDE988888">https://t.me/ABCDE988888</a>';
                            $result = preg_replace('/<a href="([^"]+)">.*?<\/a>/', '$1', $button);
                            $parts = explode('#', $result);
                            if (count($parts) === 2) {
                                $bwButtons[] = [[
                                    'text' => $parts[0], // 按钮文本
                                    'url' => $parts[1]  // 按钮链接
                                ]];
                                
                            }
                        }
                    
            }
             $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]); 
            if (!empty($xxinfo['photo'])) {
               $photo=$xxinfo['photo'];
                $content = [
                            'chat_id' => $row['group_id'],
                            'photo' => $photo,
                            'caption' => $xxinfo['content'],
                            'parse_mode' => 'Markdown',
                            'reply_markup' => $replyMarkup,
                        ];
        
                        $response = send($token, 'sendPhoto', $content);
            } else {
               
           
                if (preg_match('/<\s*a[^>]*>(.*?)<\s*\/\s*a>/', $xxinfo['content'])) {
                   $parse_mode='HTML';
                }else{
                    $parse_mode='Markdown'; 
                }
                $content = [
                    'chat_id' => $row['group_id'],
                    'text' => $xxinfo['content'],
                    'parse_mode' => $parse_mode,
                    'disable_notification' => true,
                    'reply_markup' => $replyMarkup
                ];
                $response =send($token, 'sendMessage', $content);
                //log::write($response);
            }
            
            if ($xxinfo['is_top'] == 1) {
                     $currentPinnedMessage =$this-> getPinnedMessage($token, $row['group_id']);
            
            if ($currentPinnedMessage && $currentPinnedMessage['text'] == $xxinfo['content']) {
                 echo "群组 ID: {$row['group_id']} 当前置顶消息与发送的消息相同，跳过发送。\n";
            } else {      
                            $data = json_decode($response, true);
                          
                            if ($data['ok']) {
                                $messageId = $data['result']['message_id'];
                            }
                            
                            $this->pinMessage($row['group_id'], $token, $messageId);
                  
                }
            }
        }
    }
    
    private function pinMessage($groupId, $token, $messageId)
    {
        $content = [
            'chat_id' => $groupId,
            'message_id' => $messageId,
            'disable_notification' => true
        ];
        send($token, 'pinChatMessage', $content);
    }
    
    function getPinnedMessage($token, $chatId) {
        $content = ['chat_id' => $chatId];
        $chatInfo = json_decode(send($token, 'getChat', $content), true);
    
        if ($chatInfo['ok'] && isset($chatInfo['result']['pinned_message'])) {
            return $chatInfo['result']['pinned_message'];
        }
    
        return false;
    }
}