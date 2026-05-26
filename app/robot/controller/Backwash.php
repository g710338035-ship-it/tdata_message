<?php
namespace app\robot\controller;

use think\facade\Db;
use think\facade\Log;
use think\facade\Cache;

class Backwash extends Apibot
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
 
            $last_name = isset($data['from']['last_name']);
            $name = $data['from']['first_name'] . $last_name;
            
            $chat_id = $data['message']['chat']['id'];
            $chatType = $data['message']['chat']['type'];
            $text = $data['data'];
            $messageId = $data['message']['message_id'];
            $userId = $data['from']['id'];
            $username = isset($data['from']['username']) ? $data['from']['username'] : '';
     
        
        $groupInfo = Cache::store('redis')->get($this->cacheBot);
        
        if (preg_match('/^(.*):(\d+)$/', $text, $matches)) {
            $commeds = $matches[1]; // 提取到的文本
            $bgid = $matches[2];    // 提取到的数字
           // $text = $commeds;
        }else{
            $commeds=$text;
        }
        // 处理不同命令
        switch ($commeds) {
            case '/backwash':
                $this->backwash($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid);
               break;
            case '/backwash_message_num':
                $this->backwash_message_num($chat_id,$messageId,$token,$bgid);
               break;
            case '/backwash_message_time':
                 $this->backwash_message_time($chat_id,$messageId,$token,$bgid);
               break;
            case '/backwash_message_closecf':
                $this->backwash_message_closecf($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid);
               break;
            case '/backwash_message_warn':
                $this->backwash_message_warn($chat_id,$messageId,$token,$bgid);
               break;
            case '/backwash_message_cftype1':
                 $this->backwash_message_cftype1($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid);
               break;
            case '/backwash_message_cftype2':
                $this->backwash_message_cftype2($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid);
               break; 
            case '/backwash_message_isdel':
                $this->backwash_message_isdel($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid);
               break;
            case '/backwash_message_warntime':
                
               break; 
        	default:    
        	  if (strpos($text, '/backwash_message_num_set:') === 0) {
                    $string = str_replace('/backwash_message_num_set:', '', $text);
                    $parts = explode('_', $string);
                    $zhi= $parts[0]; 
                    $bgid =$parts[1];
                    $bwnum = $zhi;
                // 更新 is_top 值
                    Db::name('botgroup')->where('id',$bgid)->update(['bwnum' => $bwnum]);
                   
                    $this->backwash($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid);
                }elseif (strpos($text, '/backwash_message_time_set:') === 0) {
                    $string = str_replace('/backwash_message_time_set:', '', $text);
                    $parts = explode('_', $string);
                    $zhi= $parts[0]; 
                    $bgid =$parts[1];
                    $bwtime = $zhi;
                // 更新 is_top 值
                    Db::name('botgroup')->where('id',$bgid)->update(['bwtime' => $bwtime]);
                            
                    $groupInfo['bwtime']=$bwtime;
                    $this->backwash($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid);
                }elseif (strpos($text, '/backwash_message_warn_time:') === 0) {
                    $string = str_replace('/backwash_message_warn_time:', '', $text);
                    $parts = explode('_', $string);
                    $zhi= $parts[0]; 
                    $bgid =$parts[1];
                    $bwtime = $zhi;
                // 更新 is_top 值
                    Db::name('botgroup')->where('id',$bgid)->update(['bwwarntime' => $bwtime]);
                   
                    $this->backwash($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid);
                } 
        	break;    
        }
    }
    
    protected function backwash($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid){
        
            $groupInfo = Db::name('botgroup')->where('id',$bgid)->find();
                   
                    $customMessage="🗣反刷屏设置\n";
                    if ($groupInfo['isbw']==1) {
                        $isStatus = "*处罚：*";
                        if($groupInfo['bwpin']==1){
                            $isStatus.='踢出';
                        }else{
                           $isStatus.='禁言'; 
                           if($groupInfo['bwwarntime']){
                               if ($groupInfo['bwwarntime'] < 60) {
                                    $interval_display = $groupInfo['bwwarntime'] . ' 分钟';
                                } else {
                                    $hours = floor($groupInfo['bwwarntime'] / 60);
                                    $interval_display = $hours . ' 小时';
                                }
                               $isStatus.="\+".$interval_display;
                           }else{
                               $isStatus.="【永久】";
                           }
                        }
                        if($groupInfo['bwisdel']){
                            $isStatus.="\+删除";
                        }
                        
                    } else {
                        $isStatus =  '*处罚：* 关闭✖️';
                    } 
                    
                    $customMessage.=$isStatus;
                    
                    $customMessage.= "\n\n👉目前： *".$groupInfo['bwtime']."秒内*发送*". $groupInfo['bwnum']." 条消息*会触发反刷屏。";
                
                    
                    $buttons = getTelebuttonByTmgId(22);
                    
                    //Db::name('telebutton')->where('tmg_id',21)->where('status',1)->order('row_number', 'asc')->order('sortid', 'asc')->select();
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
                        /*if($button['content']=='/welcome_status'){
                            $button['title']=$welStatus;
                        }*/
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
                    	'text' => '🔙 返回',
                        'callback_data' => '/group_setting_botquninfo:'.$bgid]
                    ];
                    $keyboard = [
                        'inline_keyboard' => $keyboard,
                    ]; 
                    $content = [
                        'chat_id' => $chat_id,
                        'reply_markup' => json_encode($keyboard),
                        'message_id' => $messageId,
                        'text' => $customMessage,
                        'parse_mode' => 'MarkdownV2' // 改为 Markdown 格式
                    ];
                    
                    // 发送请求以编辑消息
                    $response = send($token, 'editMessageText', $content);
                    gxcache();
                     //log::info("message welcome info:".$response) ;
    }
    
    protected function backwash_message_num($chat_id,$messageId,$token,$bgid){
        
        $groupInfo = Db::name('botgroup')->where('id',$bgid)->find();
        $hoursButtons = [];
    	$row = []; // 用于存储一行的按钮
    
    	// 创建小时按钮，分为五行
    	for ($i = 2; $i <= 20; $i++) {
    		// 添加按钮到当前行
    		$row[] = ['text' => (string)$i, 'callback_data' => '/backwash_message_num_set:'.(string)$i."_".$bgid]; // 确保按钮是对象
    		
    
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
    		'callback_data' => '/backwash:'.$bgid
    	]];
    	$replyMarkup = json_encode(['inline_keyboard' => $hoursButtons]);
    	$content = [
    		'chat_id' => $chat_id,
    		'text' => "🗣 反刷屏 \n这里你可以选择每段时间内可发送消息的最大数量。\n\n👉目前： ".$groupInfo['bwtime']."秒内发送 *". $groupInfo['bwnum']." 条消息* 会触发反刷屏。\n",
    		'reply_markup' => $replyMarkup,
    		'message_id' => $messageId,
    		'parse_mode' => 'MarkdownV2' 
    	];
    	
    	send($token,'editMessageText', $content);
    }
    protected function backwash_message_time($chat_id,$messageId,$token,$bgid){
    
        
        $groupInfo =Db::name('botgroup')->where('id',$bgid)->find();
        $hoursButtons = [];
    	$row = []; // 用于存储一行的按钮
    
    	// 创建小时按钮，分为五行
    	for ($i = 3; $i <= 20; $i++) {
    		// 添加按钮到当前行
    		$row[] = ['text' => (string)$i, 'callback_data' => '/backwash_message_time_set:'.(string)$i."_".$bgid]; // 确保按钮是对象
    		
    
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
    		'callback_data' => '/backwash:'.$bgid
    	]];
    	$replyMarkup = json_encode(['inline_keyboard' => $hoursButtons]);
    	$content = [
    		'chat_id' => $chat_id,
    		'text' => "🗣 反刷屏 \n这里你可以选择每段时间内可发送消息的最大数量。\n\n👉目前： *".$groupInfo['bwtime']."秒内* 发送 ". $groupInfo['bwnum']." 条消息会触发反刷屏。\n",
    		'reply_markup' => $replyMarkup,
    		'message_id' => $messageId,
    		'parse_mode' => 'MarkdownV2' 
    	];
    	
    	send($token,'editMessageText', $content);
    }
    
    protected function backwash_message_warn($chat_id,$messageId,$token,$bgid){
       
        
        $groupInfo = Db::name('botgroup')->where('id',$bgid)->find();
         $buttons = getTelebuttonByTmgId(24);
                    
                    //Db::name('telebutton')->where('tmg_id',21)->where('status',1)->order('row_number', 'asc')->order('sortid', 'asc')->select();
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
                        /*if($button['content']=='/welcome_status'){
                            $button['title']=$welStatus;
                        }*/
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
                        'callback_data' => '/backwash:'.$bgid]
                    ];
                    $keyboard = [
                        'inline_keyboard' => $keyboard,
                    ];
                    $interval_display='';
                     if($groupInfo['bwwarntime']){
                               if ($groupInfo['bwwarntime'] < 60) {
                                    $interval_display = $groupInfo['bwwarntime'] . ' 分钟';
                                } else {
                                    $hours = floor($groupInfo['bwwarntime'] / 60);
                                    $interval_display = $hours . ' 小时';                                }
                             
                           }
                	$content = [
                		'chat_id' => $chat_id,
                		'text' => "🗣 反刷屏 \n这里你可以选择用于反刷屏的禁言间隔时长。\n\n👉目前：当前处罚时间为 ".$interval_display,
                		'reply_markup' => json_encode($keyboard),
                		'message_id' => $messageId,
                		'parse_mode' => 'MarkdownV2' 
                	];
    	
    	send($token,'editMessageText', $content);
    }
    
    protected function backwash_message_closecf($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid){
        
        $groupInfo = Db::name('botgroup')->where('id',$bgid)->find();
        $isbw = ($groupInfo['isbw'] == 0) ? 1 : 0;
                // 更新 is_top 值
        Db::name('botgroup')->where('id',$bgid)->update(['isbw' => $isbw]);
        
        $this->backwash($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid);
    }
    
    protected function backwash_message_cftype1($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid){
      
        $bwpin = 1;
                // 更新 is_top 值
        Db::name('botgroup')->where('id',$bgid)->update(['bwpin' => $bwpin]);
        
        $this->backwash($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid);
    }
    protected function backwash_message_cftype2($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid){
      
        $bwpin = 2;
                // 更新 is_top 值
        Db::name('botgroup')->where('id',$bgid)->update(['bwpin' => $bwpin]);
     
        $this->backwash($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid);
    }
    
    protected function backwash_message_isdel($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid){
        $groupInfo =Db::name('botgroup')->where('id',$bgid)->find();
        $bwisdel = ($groupInfo['bwisdel'] == 0) ? 1 : 0;
                // 更新 is_top 值
        Db::name('botgroup')->where('id',$bgid)->update(['bwisdel' => $bwisdel]);
      
        $this->backwash($chat_id, $chatType, $text, $userId, $token, $messageId,$bgid);
    }
}
