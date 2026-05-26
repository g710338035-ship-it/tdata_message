<?php
namespace app\robot\controller;

use think\facade\Db;
use think\facade\Log;
use think\facade\Cache;

class Custommessageedit extends Apibot
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
        if(strpos($text, '/custom_infoedit_/custom_message_timerepeat_hour') !== false){
            $hourfg = str_replace('/custom_infoedit_/custom_message_timerepeat_hour:', '', $text);
            $commeds ='/custom_infoedit_/custom_message_timerepeat_hour:';
            //$id=$hourfg;
        }else{
            // 使用正则表达式提取 $text 和 $id
             preg_match('/\/custom_infoedit_(\/[^:]+):(\d+)/', $text, $matches);
            
            if (count($matches) === 3) {
                $commeds = $matches[1]; // 提取到的 text
                $id = $matches[2];   // 提取到的 id
               
                // 输出结果
               // log::write(  "text = $commeds\n"); // 输出: $text = /custom_message_content_type
               // log::write( "id = $id\n");     // 输出: $id = 21
            } else {
                echo "未能提取到值。";
            }
        }
		$botinfo=Cache::store('redis')->get($this->cacheBot);
		$bot_id=$botinfo['bot_id'];

        switch ($commeds) {
            ////消息是否删除所有    
            case '/custom_message_isdel':
                // 处理 消息设置
                 $this->custom_message_isdel($chat_id, $chatType,$text, $userId, $token,$messageId,$id);
                break;
            ////消息确定删除所有    
            case '/custom_message_del':                
                $botinfo=Cache::store('redis')->get($this->cacheBot);
                $xxinfo=Db::name('xiaoxi')->where('id', $id)->find();
                Db::name('xiaoxi')->where('id',$id)->delete();  
                // 处理 消息设置
                $this->custom_message_start($chat_id, $chatType,$text, $userId, $token,$messageId,$xxinfo['bgid']);
                break;    
            ////消息添加消息    
           /* case '/custom_message_setting':
                // 处理 消息设置
                $this->custom_message_setting($chat_id, $chatType,$text, $userId, $token,$messageId,$id);
                break;*/
            case '/custom_message_setting_back':
                $waiting='waiting_for_message';
                $redisKey = "customedit:$bot_id.txt:add_status";
                $redisHashKey = "customedit:$bot_id.txt:addmessage";
               
                 // 从集合中删除指定的 $data
                $this->redis->srem($redisKey,  $waiting);
                $this->redis->del($redisHashKey);
                
                $redisKeyt = "customedit:$bot_id.photo:add_status";
                $redisHashKeyt = "customedit:$bot_id.photo:addmessage";
               
                 // 从集合中删除指定的 $data
                $this->redis->srem($redisKeyt,  $waiting);
                $this->redis->del($redisHashKeyt);
                // 处理 消息设置
                $this->custom_message_content_type($chat_id, $chatType,$text, $userId, $token,$messageId,$id);
                break;
///////////联系
            case '/custom_message_content_type':
                
                $this->custom_message_content_type($chat_id, $chatType,$text, $userId, $token,$messageId,$id);
                break;
////////////////////////////////////////////                
            case '/custom_message_content':
                $type=1;
                $this->custom_message_contentinfo($chat_id, $chatType,$text, $userId, $token,$messageId,$type,$id);
		        break;
            case '/custom_message_contentphoto':
                $type=2;
                $this->custom_message_contentinfo($chat_id, $chatType,$text, $userId, $token,$messageId,$type,$id);
                
		        break;
            case '/custom_message_see':
                // 处理 消息内容查看 逻辑
                $this->custom_message_see($chat_id, $chatType,$text, $userId, $token,$messageId,$id);
                break;
                
            case '/custom_message_content_back':
               $this->custom_message_info_edit($chat_id,$messageId,$token,$id);
                // 处理 消息内容查看 逻辑
                break;
////////////////////////////////////////////                
            case '/custom_message_time':
                // 处理 消息设置
                $this->Custom_message_time($chat_id,$messageId,$token,$id);
                break;
////////////////////////////////////////////                
            case '/custom_message_timerepeat':
                $text='/custom_message_timerepeat';
                $this->checkForTelemessage($chat_id, $chatType,$text, $userId, $token,$messageId,$id);
                //$this->Custom_message_timerepeat($chat_id, $chatType,$text, $userId, $token,$messageId);
                break;

////////////////////////////////////////////                
            case '/custom_message_top':
                // 获取当前 is_top 值
                $current_is_top = Db::name('xiaoxi')->where('id', $id)->value('is_top');
                // 判断并设置 is_top 值
                $is_top = ($current_is_top == 0) ? 1 : 0;
                // 更新 is_top 值
                Db::name('xiaoxi')->where('id', $id)->update(['is_top' => $is_top]);
                
                $this->custom_message_info_edit($chat_id,$messageId,$token,$id);
                break;
////////////////////////////////////////////                 
            case '/custom_message_content_del':
                
                $current_is_del = Db::name('xiaoxi')->where('id', $id)->value('is_del');
                $is_del = ($current_is_del == 0) ? 1 : 0;
                Db::name('xiaoxi')->where('id', $id)->update(['is_del' => $is_del]);
                // 处理 banwords_button 逻辑
                $this->custom_message_info_edit($chat_id,$messageId,$token,$id);
                break;
 ////按钮设置    
            case '/custom_message_buttonset':
                // 处理 消息设置
                 $this->custom_message_buttonset($chat_id, $chatType,$text, $userId, $token,$messageId,$id);
                break;                
            ///////////////////////button_add                
            case '/custom_message_buttonset_add':
                
                $this->custom_message_buttonset_add($chat_id, $chatType,$text, $userId, $token,$messageId,$id);
                break;     
            case '/custom_message_buttonset_add_back':
                // 处理 消息设置
                $waiting='waiting_for_message';
                $redisKey = "xxButtonedit:$bot_id:add_status";
                $redisHashKey = "xxButtonedit:$bot_id:addmessage";
                 // 从集合中删除指定的 $data
                $this->redis->srem($redisKey,  $waiting);
                $this->redis->del($redisHashKey, $waiting, $messageId);
                
                // 处理 消息设置
                $this->custom_message_buttonset($chat_id, $chatType,$text, $userId, $token,$messageId,$id);
                break; 
 ///////////////////////button_del                  
            case '/custom_message_buttonset_del':
                Db::name('xiaoxi')->where('id', $id)->update(['buttonset'=>null]);
                 $content = [
                        'callback_query_id' => $callbackQueryId,
                        'text' => '删除成功！',
                        'show_alert' => true,
                        'cache_time' => 5
                    ];
                    send($token,'answerCallbackQuery', $content);
                break;    
                
            default:
               
                if (strpos($text, '/custom_infoedit_/custom_message_time_setting_hour:') === 0) {
                    $hourfg = str_replace('/custom_infoedit_/custom_message_time_setting_hour:', '', $text);
                    
                    $parts = explode('_', $hourfg);
                    if (count($parts) === 2) {
                    $id = $parts[0]; // 固定为 00:00
                    $hour = sprintf('%02d:00',  $parts[1]);
                        
                    $this->Custom_message_time_setting_hour($chat_id,$messageId,$token,$hour,$id);
                        
                    }
                }
                if (strpos($text, '/custom_infoedit_/custom_message_time_setting_min:') === 0) {
                    
                    $hourfg = str_replace('/custom_infoedit_/custom_message_time_setting_min:', '', $text);
                    $parts = explode('_', $hourfg);
                    if (count($parts) === 2) {
                        $id = $parts[0]; // 固定为 00:00
                        $send_time =$parts[1];
                    
                        Db::name('xiaoxi')->where('id', $id)->update(['send_time'=>$send_time]);
                      
                        $this->custom_message_info_edit($chat_id,$messageId,$token,$id);
                    }
                }
                
                if (strpos($text, '/custom_infoedit_/custom_message_timerepeat_hour:') === 0) {
                    $mintime = str_replace('/custom_infoedit_/custom_message_timerepeat_hour:', '', $text);
                    $parts = explode(':', $mintime);
                    if (count($parts) === 2) {
                         $repeat_interval= $parts[0]; // 固定为 00:00
                         $id =$parts[1];
                    
                        Db::name('xiaoxi')->where('id', $id)->update(['repeat_interval'=>$repeat_interval]);
                      
                        $this->custom_message_info_edit($chat_id,$messageId,$token,$id);
                    }
                }
                if (strpos($text, '/custom_message_timerepeat_min:') === 0) {
                    
                }
                // 处理其他情况
                break;
        }
    }
    protected function custom_message_see($chat_id, $chatType,$text, $userId, $token,$messageId,$id){
        $xxinfo=Db::name('xiaoxi')->where('id', $id)->find();
        if($xxinfo){
        	$xxstr="";
        	if($xxinfo['send_time']){
        		$xxstr.="🕑 时间: ".$xxinfo['send_time']."\n\n";
        	}else{
        		$xxstr.="🕑 时间: ❌"."\n\n";
        	}
        	if($xxinfo['repeat_interval']){
        	    $repeat_interval = $xxinfo['repeat_interval'];
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
        	
        	$buttons = explode("\n", trim($xxinfo['buttonset']));
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
        	
            	$bwButtons[] = [[
                    'text' => '🗑删除该消息',
                    'callback_data' => '/custom_infoedit_/custom_message_isdel:'.$id
                ]];
        	    $bwButtons[] = [[
        			'text' => '🔙 返回',
        			'callback_data' => '/custom_message_info_id:'.$id.'_'.$xxinfo['bgid']
        		]];
        		$replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
        	    if(!$xxinfo['photo']){
        	        $sendtext="👉🏻 内容: \n\n $xxstr ".$xxinfo['content'];
        	        $mtype='txt';
        	    }else{
        	         $sendtext="👉🏻 内容:\n\n $xxstr ".$xxinfo['content'];
        	         $mtype='photo';
        	    }  
        	    if (preg_match('/<\s*a[^>]*>(.*?)<\s*\/\s*a>/', $xxinfo['content'])) {
                   $parse_mode='HTML';
                }else {
                    $parse_mode='Markdown'; 
                }
        		$content = [
        			'chat_id' => $chat_id,
        			'text' => $sendtext,
        			'reply_markup' => $replyMarkup,
        			'message_id' => $messageId,
        			'parse_mode' => $parse_mode 
        		];
        		$ttres=send($token,'editMessageText', $content);
        	    log::write($ttres);
        		
        	}
        
        
    }
    protected function custom_message_info_edit($chat_id,$messageId,$token,$xx_id){
          $buttons = getTelebuttonByTmgId(12);
             $xxinfo = Db::name('xiaoxi')->where('id',$xx_id)->find(); 
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
                    $currentRow[] = ['text' => $button['title'], 'callback_data' => '/custom_message_info_id:'.$xx_id.'_'.$xxinfo['bgid']];
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
                'callback_data' => '/custom_message_nowpush:'.$xx_id.'_'.$xxinfo['bgid']
            ]];
            $keyboard[] = [[
                'text' => '❌关闭',
                'callback_data' => '/closeMessage'
            ]];
            $keyboard = [
                'inline_keyboard' => $keyboard,
            ];
           
          
            
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
                $str .= "❇️ 按钮链接: " . ($xxinfo['buttonset'] ? '是✔️' : '否✖️') . "\n";
            }
            
            
            $buttonData = [
                'chat_id' => $chat_id,
                'text' => "📖编辑自定义消息设置。下方按钮操作为管理该消息的参数设置。\n\n$str",
                'message_id' => $messageId,
                'reply_markup' => json_encode($keyboard), // 将键盘数据进行 JSON 序列化
            ];
    
            send($token, 'editMessageText', $buttonData);
       
    }
    
    protected function custom_message_contentinfo($chat_id, $chatType,$text, $userId, $token,$messageId,$type,$id){
        
       $botinfo=Cache::store('redis')->get($this->cacheBot);
       $bot_id=$botinfo['bot_id'];
        $xxinfo=Db::name('xiaoxi')->where('id', $id)->find();
        if($xxinfo){
        	$xxstr="";
        	if($xxinfo['send_time']){
        		$xxstr.="🕑 时间: ".$xxinfo['send_time']."\n\n";
        	}else{
        		$xxstr.="🕑 时间: ❌"."\n\n";
        	}
        	if($xxinfo['repeat_interval']){
        	    $repeat_interval = $xxinfo['repeat_interval'];
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
        			'callback_data' => '/custom_infoedit_/custom_message_setting_back:'.$id
        		]];
        		$replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
        	    if($type==1){
        	        $sendtext="👉🏻 现在发送消息,你想要修改的内容: \n\n $xxstr 你可以发送已经格式化的消息或者 HTML 代码。";
        	        $mtype='txt';
        	    }else{
        	         $sendtext="👉🏻 现在发送消息,你想要修改的内容:\n\n $xxstr 现在发送媒体（图片）。";
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
        	  
        		$redisKey = "customedit:$bot_id.$mtype:add_status";
        		$redisHashKey = "customedit:$bot_id.$mtype:addmessage";
        		$waiting='waiting_for_message';
        		// 将消息 ID 推入 Redis 列表
        		$this->redis->sadd($redisKey, $waiting);
        		$this->redis->hset($redisHashKey, $waiting,  $messageId.'_'.$id);
        				// 设置列表的过期时间（例如一周后自动清理）
        		$this->redis->expire($redisKey, 25 * 60); // 7天
        		$this->redis->expire($redisHashKey, 25 * 60);
        		
        	} 
        	    
        
    }
    protected function custom_message_content_type($chat_id, $chatType,$text, $userId, $token,$messageId,$id){
        $xxinfo=Db::name('xiaoxi')->where('id', $id)->find();
        if($xxinfo){
        	$xxstr="";
        	if($xxinfo['send_time']){
        		$xxstr.="🕑 时间: ".$xxinfo['send_time']."\n\n";
        	}else{
        		$xxstr.="🕑 时间: ❌"."\n\n";
        	}
        	if($xxinfo['repeat_interval']){
        	    $repeat_interval = $xxinfo['repeat_interval'];
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
        	    if($xxinfo['photo']){
        	       $bwButtons[] = [[
        			'text' => '🖼 图片',
        			'callback_data' => '/custom_infoedit_/custom_message_contentphoto:'.$id
        		]]; 
        	    }else{
            	     $bwButtons[] = [[
            			'text' => '🗓 文本',
            			'callback_data' => '/custom_infoedit_/custom_message_content:'.$id
            		]];  
        	    }
        	    
        		
        		$bwButtons[] = [[
        			'text' => '🔙 返回',
        			'callback_data' => '/custom_message_info_id:'.$id.'_'.$xxinfo['bgid']
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
    protected function custom_message_isdel($chat_id, $chatType,$text, $userId, $token,$messageId,$id){
         $xx=Db::name('xiaoxi')->where('id', $id)->find();
        $hoursButtons = [
        	[
        	    	['text' => '✅ 确定删除消息', 'callback_data' => '/custom_infoedit_/custom_message_del:'.$id],
        		],[['text' => '🔙 取消', 'callback_data' => '/custom_message_info_id:'.$id.'_'.$xx['bgid']] // 确保按钮是对象
        	]
        ];
        
        $replyMarkup = json_encode(['inline_keyboard' => $hoursButtons]);
        $content = [
        	'chat_id' => $chat_id,
        	'text' => "🕑 重发消息\n\n⚠️ 你确定要删除该自定义消息吗？",
        	'reply_markup' => $replyMarkup,
        	'message_id' => $messageId,
        	'parse_mode' => 'MarkdownV2' 
        ];
        
        $ttres=send($token,'editMessageText', $content);
    }
    protected function custom_message_start($chat_id, $chatType,$text, $userId, $token,$messageId,$bgid){
      
            // 如果存在自定义按钮，生成键盘按钮并发送
            $keyboard = [];
            $botinfo=Cache::store('redis')->get($this->cacheBot);
            $keyboard[] = [[
                'text' => '🔙 返回',
                'callback_data' => '/custom_message_start:'.$bgid
            ]];
            
            $keyboard = [
                'inline_keyboard' => $keyboard,
            ];
            $date=date('Y-m-d H:m',time());
            $buttonData = [
                'chat_id' => $chat_id,
                'text' => "📖自定义消息删除成功",
                'message_id' => $messageId,
                'reply_markup' => json_encode($keyboard), // 将键盘数据进行 JSON 序列化
            ];
    
            send($token, 'editMessageText', $buttonData);
        
    }
////////////添加消息设置   
   
    protected function Custom_message_time_setting_hour($chat_id,$messageId,$token,$hour,$id){
        $xx=Db::name('xiaoxi')->where('id', $id)->find();
        $ysend_time=$xx['send_time'];
        $minButtons = [];
        $row = []; // 用于存储一行的按钮
        $hour = sprintf('%02d', $hour);
        // 创建小时按钮，分为五行
        for ($i = 0; $i <= 59; $i++) {
        	// 添加按钮到当前行
        	$i=sprintf('%02d', $i);
        	$row[] = ['text' => (string)$i, 'callback_data' => '/custom_infoedit_/custom_message_time_setting_min:'.$id."_".$hour.':'.(string)$i]; // 确保按钮是对象
        	
        
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
    		'callback_data' => '/custom_message_info_id:'.$id.'_'.$xx['bgid']
    	]];
     
        $replyMarkup = json_encode(['inline_keyboard' => $minButtons]);
        $content = [
        	'chat_id' => $chat_id,
        	'text' => "🕑 重发消息 \n原时间：$ysend_time\n\n新时间：$hour ：00\n\n👉🏻 编辑开始分钟：\n",
        	'reply_markup' => $replyMarkup,
        	'message_id' => $messageId,
        	'parse_mode' => 'MarkdownV2' 
        ];
        
        send($token,'editMessageText', $content);
    }
    protected function Custom_message_time($chat_id,$messageId,$token,$id){
        $hoursButtons = [];
    	$row = []; // 用于存储一行的按钮
        $xx=Db::name('xiaoxi')->where('id', $id)->find();
        $ysend_time=$xx['send_time'];
    	// 创建小时按钮，分为五行
    	for ($i = 0; $i <= 23; $i++) {
    		// 添加按钮到当前行
    		$row[] = ['text' => (string)$i, 'callback_data' => '/custom_infoedit_/custom_message_time_setting_hour:'.$id.'_'.(string)$i]; // 确保按钮是对象
    		
    
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
    		'callback_data' => '/custom_message_info_id:'.$id.'_'.$xx['bgid']
    	]];
    	
    	$replyMarkup = json_encode(['inline_keyboard' => $hoursButtons]);
    	$content = [
    		'chat_id' => $chat_id,
    		'text' => "🕑 重发消息 \n原时间：$ysend_time\n👉🏻 编辑开始时间：\n",
    		'reply_markup' => $replyMarkup,
    		'message_id' => $messageId,
    		'parse_mode' => 'MarkdownV2' 
    	];
    	
    	send($token,'editMessageText', $content);
    }
    
    
    private function checkForTelemessage($chat_id,$chatType, $text, $fromUserId, $token,$messageId,$id)
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
        $buttons =getTelebuttonByTmgId($telemessage['id']);
    
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
                $currentRow[] = ['text' => $button['title'], 'callback_data' => "/custom_infoedit_".$button['content'].":".$id];
            
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
    protected function custom_message_buttonset($chat_id, $chatType,$text, $userId, $token,$messageId,$id){
      $botinfo=Cache::store('redis')->get($this->cacheBot); 
        $xxinfo=Db::name('xiaoxi')->where('id', $id)->find();
        if($xxinfo){
        	$xxstr="";
        	if($xxinfo['buttonset']){
        		$xxstr.=" ❇️按钮效果如下: \n\n";
        		$buttons = explode("\n", trim($xxinfo['buttonset']));
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
        			'text' => '🖌编辑按钮',
        			'callback_data' => '/custom_infoedit_/custom_message_buttonset_add:'.$id
        		],[
        			'text' => '➖删除按钮',
        			'callback_data' => '/custom_infoedit_/custom_message_buttonset_del:'.$id
        		]];
        		$bwButtons[] = [[
        			'text' => '🔙 返回',
        			'callback_data' => '/custom_message_info_id:'.$id.'_'.$xxinfo['bgid']
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
    
    protected function custom_message_buttonset_add($chat_id, $chatType,$text, $userId, $token,$messageId,$id){
        $botinfo=Cache::store('redis')->get($this->cacheBot);
		$bot_id=$botinfo['bot_id'];
		
        $bwButtons[] = [[
        	'text' => '❌撤销',
        	'callback_data' => '/custom_infoedit_/custom_message_buttonset_add_back:'.$id,
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
        $redisKey = "xxButtonedit:$bot_id:add_status";
        $redisHashKey = "xxButtonedit:$bot_id:addmessage";
        $waiting = 'waiting_for_message';
        
        $this->redis->sadd($redisKey, $waiting);
        $this->redis->hset($redisHashKey, $waiting,  $messageId.'_'.$id);
        
        // 设置过期时间（25分钟）
        $this->redis->expire($redisKey, 25 * 60);
        $this->redis->expire($redisHashKey, 25 * 60);
                        
    }
    
}