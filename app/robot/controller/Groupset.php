<?php
namespace app\robot\controller;

use think\facade\Db;
use think\facade\Log;
use think\Request;
use think\facade\Cache;

class Groupset extends Apibot
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
            
            $last_name = isset($data['from']['last_name']) ? $data['from']['last_name'] : '';
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
        log::write($text);
        // 处理不同命令
        switch ($commeds) {
            case '/group_setting':
                
                $waiting = 'waiting_for_message';
                $redisKey = "botgroupadd:$bot_id:add_status";
                $redisHashKey = "botgroupadd:$bot_id:addmessage";
                
                $this->redis->srem($redisKey, $waiting);
                $this->redis->del($redisHashKey, $waiting, $messageId);
                
                $redisKeyedit = "botgroupedit:$bot_id:add_status";
                $redisHashKeyedit = "botgroupedit:$bot_id:addmessage";
                
                $this->redis->srem($redisKeyedit, $waiting);
                $this->redis->del($redisHashKeyedit);
                
                $selectedGroupsKey = 'selected_groups_' . $chat_id;
                $cachedData = Cache::store('redis')->get($selectedGroupsKey);
                if($cachedData){
                    Cache::store('redis')->delete($selectedGroupsKey);
                }
                $this->group_setting($chat_id, $chatType, $text, $userId, $token, $messageId);
                break;
            case '/group_setting_refresh': 
                 $this->group_setting_refresh($chat_id, $chatType, $text, $userId, $token, $messageId,$callbackQueryId);
                break;
            case '/group_setting_batch':
                $this->group_setting_batch($chat_id, $chatType, $text, $userId, $token, $messageId);
                break;
            case '/group_setting_botgroup_ok':
                $selectedGroupsKey = 'selected_groups_' . $chat_id;
                $cachedData = Cache::store('redis')->get($selectedGroupsKey);
                if($cachedData){
                    /*$count = count($cachedData);
                    if ($count === 1) {
                        $content = [
                        'callback_query_id' => $callbackQueryId,
                        'text' => '请至少选择两个群组',
                        'show_alert' => true,
                        'cache_time' => 5
                    ];
                    send($token,'answerCallbackQuery', $content);
                    } else {
                    $this->group_setting_botgroup_ok($chat_id, $chatType, $text, $userId, $token, $messageId);
                        
                    }*/
                    $this->group_setting_botgroup_ok($chat_id, $chatType, $text, $userId, $token, $messageId);
                    
                }else{
                    $content = [
                        'callback_query_id' => $callbackQueryId,
                        'text' => '请选择群组',
                        'show_alert' => true,
                        'cache_time' => 5
                    ];
                    send($token,'answerCallbackQuery', $content);
                }
                
                break;
            case '/group_setting_botgroup_editok':
                $selectedGroupsKey = 'selected_groups_' . $chat_id;
                $cachedData = Cache::store('redis')->get($selectedGroupsKey);
                if($cachedData){
                    /*$count = count($cachedData);
                    if ($count === 1) {
                        $content = [
                        'callback_query_id' => $callbackQueryId,
                        'text' => '请至少选择两个群组',
                        'show_alert' => true,
                        'cache_time' => 5
                    ];
                    send($token,'answerCallbackQuery', $content);
                    } else {
                    $this->group_setting_botgroup_editok($chat_id,  $text, $token, $messageId,$bgid);
                        
                    }*/
                     $this->group_setting_botgroup_editok($chat_id,  $text, $token, $messageId,$bgid);
                    
                }else{
                    $content = [
                        'callback_query_id' => $callbackQueryId,
                        'text' => '请选择群组',
                        'show_alert' => true,
                        'cache_time' => 5
                    ];
                    send($token,'answerCallbackQuery', $content);
                }
                
                break;      
            case '/group_setting_botgroup':
                $this->group_setting_botgroup($chat_id, $chatType, $text, $userId, $token,$messageId);
                break;    
                
           case '/group_setting_adminior':
                $this->group_setting_adminior($chat_id, $chatType, $text, $userId, $token, $messageId,$callbackQueryId);
                break;     
            case '/group_setting_gphoto':
                
                    $bwButtons[] = [[
                        'text' => '❌撤销',
                        'callback_data' => '/group_setting_gphoto_back:'.$bgid
                    ]];
                    $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                  
                    
                    $content = [
                    'chat_id' => $chat_id,
                    'text' => "🖼更新群组头像。\n请选择一张800x800像素以上图片进行上传。",
                    'reply_markup' => $replyMarkup,
                    'message_id' => $messageId,
                    'parse_mode' => 'Markdown'
                ];
                    
                    $ttres = send($token, 'editMessageText', $content);
                    $redisKey = "gphoto:$bot_id:add_status";
                    $redisHashKey = "gphoto:$bot_id:addmessage";
                    $waiting = 'waiting_for_message';
                    
                    $this->redis->sadd($redisKey, $waiting);
                    $this->redis->hset($redisHashKey, $waiting, $messageId."_".$bgid);
                    
                    // 设置过期时间（25分钟）
                    $this->redis->expire($redisKey, 25 * 60);
                    $this->redis->expire($redisHashKey, 25 * 60);
               
                break;
                
            case '/group_setting_gphoto_back':
                $waiting = 'waiting_for_message';
                $redisKeygphoto = "gphoto:$bot_id:add_status";
                $redisHashKeygphoto = "gphoto:$bot_id:addmessage";
                
                $this->redis->srem($redisKeygphoto, $waiting);
                $this->redis->del($redisHashKeygphoto);
                $this->group_setting_botquninfo($chat_id,  $text,  $token, $messageId,$bgid);
                break;
            case '/group_setting_gdes':
                
                    $bwButtons[] = [[
                        'text' => '❌撤销',
                        'callback_data' => '/group_setting_gdes_back:'.$bgid
                    ]];
                    $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                  
                    
                    $content = [
                    'chat_id' => $chat_id,
                    'text' => "🖼更新群组介绍。\n请可以发送已经格式化的文本。",
                    'reply_markup' => $replyMarkup,
                    'message_id' => $messageId,
                    'parse_mode' => 'Markdown'
                ];
                    
                    $ttres = send($token, 'editMessageText', $content);
                    $redisKey = "gdes:$bot_id:add_status";
                    $redisHashKey = "gdes:$bot_id:addmessage";
                    $waiting = 'waiting_for_message';
                    
                    $this->redis->sadd($redisKey, $waiting);
                    $this->redis->hset($redisHashKey, $waiting, $messageId."_".$bgid);
                    
                    // 设置过期时间（25分钟）
                    $this->redis->expire($redisKey, 25 * 60);
                    $this->redis->expire($redisHashKey, 25 * 60);
               
                break;
                
            case '/group_setting_gdes_back':
                $waiting = 'waiting_for_message';
                $redisKeygphoto = "gdes:$bot_id:add_status";
                $redisHashKeygphoto = "gdes:$bot_id:addmessage";
                
                $this->redis->srem($redisKeygphoto, $waiting);
                $this->redis->del($redisHashKeygphoto);
                $this->group_setting_botquninfo($chat_id,  $text,  $token, $messageId,$bgid);
                break;
            case '/group_setting_botgroup_edit':
                $this->group_setting_botgroup_edit($chat_id,  $text,  $token, $messageId,$bgid);
                break;
            case '/group_setting_botquninfo':
                $this->group_setting_botquninfo($chat_id,  $text,  $token, $messageId,$bgid);
                break;
            case '/group_setting_botgroup_del':
                $this->group_setting_botgroup_del($chat_id,  $text,  $token, $messageId,$bgid);
                break;
            
            case '/group_setting_botgroup_delall': 
                Db::name('kwfilter')->where('bgid', $bgid)->delete();
                Db::name('keyword')->where('bgid', $bgid)->delete();
                Db::name('xxsetting')->where('bgid', $bgid)->delete();
                Db::name('xiaoxi')->where('bgid', $bgid)->delete();
                Db::name('botgroup')->where('id', $bgid)->delete();
                $content = [
                        'callback_query_id' => $callbackQueryId,
                        'text' => '删除成功！',
                        'show_alert' => true,
                        'cache_time' => 5
                    ];
                send($token,'answerCallbackQuery', $content);
                
                gxcache();
                
                // 处理 消息设置
                $this->group_setting($chat_id, $chatType, $text, $userId, $token, $messageId);
                break; 
            default:
                if (strpos($text, '/group_setting_quninfo') === 0) {
                    $groupOneId = str_replace('/group_setting_quninfo:', '', $text);
                    $this->group_setting_quninfo($chat_id,$chatType, $token, $messageId, $groupOneId);
                }
                if (strpos($text, '/group_setting_toggle:') === 0) {
                    $selectedGroupsKey = 'selected_groups_' . $chat_id;
                    $cacheDuration = 1200;
                    
                    $selectedGroups = Cache::store('redis')->get($selectedGroupsKey) ?? [];  
                    $groupId = str_replace('/group_setting_toggle:', '', $text);
                    if (in_array($groupId, $selectedGroups)) {
                        // 如果已经选择则取消选择
                        $selectedGroups = array_diff($selectedGroups, [$groupId]);
                    } else {
                        // 否则加入选择
                        $selectedGroups[] = $groupId;
                    }
                   Cache::store('redis')->set($selectedGroupsKey, $selectedGroups, $cacheDuration);
                    // 刷新消息以更新勾选状态
                    $this->group_setting_botgroup($chat_id, $chatType, $text, $userId, $token,$messageId);
                }
                if (strpos($text, '/group_setting_edittoggle:') === 0) {
                    $selectedGroupsKey = 'selected_groups_' . $chat_id;
                    $cacheDuration = 1200;
                    
                    $selectedGroups = Cache::store('redis')->get($selectedGroupsKey) ?? [];  
                    $editId= str_replace('/group_setting_edittoggle:', '', $text);
                    $parts = explode('_', $editId);
                    $groupId= $parts[0]; // 固定为 00:00
                    $bgid =$parts[1];
                    if (in_array($groupId, $selectedGroups)) {
                        // 如果已经选择则取消选择
                        $selectedGroups = array_diff($selectedGroups, [$groupId]);
                    } else {
                        // 否则加入选择
                        $selectedGroups[] = $groupId;
                    }
                   Cache::store('redis')->set($selectedGroupsKey, $selectedGroups, $cacheDuration);
                    // 刷新消息以更新勾选状态
                    $this->group_setting_botgroup_edit($chat_id,  $text,  $token, $messageId,$bgid);
                }
                /*if (strpos($text, '/group_setting_botquninfo') === 0) {
                    $bgid = str_replace('/group_setting_botquninfo:', '', $text);
                    $this->group_setting_botquninfo($chat_id,$chatType, $token, $messageId, $bgid);
                }*/
                
                break;    
        }
    }
    protected function group_setting($chat_id, $chatType, $text, $userId, $token, $messageId){
           $bot=Cache::store('redis')->get($this->cacheBot);
           $groups = Db::name('telegraggroup')->where('bot_id',$bot['bot_id'])->select();
           $botgroups = Db::name('botgroup')->where('bot_id',$bot['bot_id'])->select(); 
                // 将群组信息转换为按钮格式，每2个为一行
                $keyboard = [];
                $tempRow = [];
                $num=0;
                foreach ($groups as $index => $group) {
                    $tempRow[] = [
                        'text' => $group['title'].'('.$group['group_id'].')',
                        'callback_data' => '/group_setting_quninfo:' . $group['group_id'],
                    ];
        
                    // 每2个按钮分为一行
                    if (($index + 1) % 2 === 0) {
                        $keyboard[] = $tempRow;
                        $tempRow = [];
                    }
                    $num++;
                }
                
                foreach ($botgroups as $index => $group) {
                    $tempRow[] = [
                        'text' => "👥 ".$group['title'],
                        'callback_data' => '/group_setting_botquninfo:' . $group['id'],
                    ];
        
                    // 每2个按钮分为一行
                    if (($index + 1) % 2 === 0) {
                        $keyboard[] = $tempRow;
                        $tempRow = [];
                    }
                  
                }
                // 如果最后一行不足2个按钮，直接加入
                if (!empty($tempRow)) {
                    $keyboard[] = $tempRow;
                }
                    $keyboard[]=[[
                        'text' => '批量设置',
                        'callback_data' => '/group_setting_botgroup'
                        ],[
                        'text' => '刷新群组列表',
                        'callback_data' => '/group_setting_refresh'
                        ],[
                        'text' => '🔙 返回',
                        'callback_data' => '/start'
                        ]];
                    $keyboard = [
                        'inline_keyboard' => $keyboard,
                    ]; 
                    $content = [
                        'chat_id' => $chat_id,
                        'reply_markup' => json_encode($keyboard),
                        'message_id' => $messageId,
                        'text' => "👥群组列表设置\n\n一共管理：*$num 个群*\n\n没有找到您的群组信息，先邀请机器人进群，设置为管理员，允许踢人和删除消息，机器人监视到群组发送消息会自动将群组收录进来。",
                        'parse_mode' => 'Markdown' // 改为 Markdown 格式
                    ];
                    
                    // 发送请求以编辑消息
                    $response = send($token, 'editMessageText', $content);
    }
    
    protected function group_setting_botgroup($chat_id, $chatType, $text, $userId, $token,$messageId){
              $bot=Cache::store('redis')->get($this->cacheBot);
              $groups = Db::name('telegraggroup')->where('bot_id',$bot['bot_id'])->select();
              // 用户选择的群组存储在 Redis 中
                $selectedGroupsKey = 'selected_groups_' . $chat_id;
                
                // 获取用户已选择的群组
                $selectedGroups = Cache::store('redis')->get($selectedGroupsKey) ?? [];  
                // 将群组信息转换为按钮格式，每2个为一行
                $keyboard = [];
                $tempRow = [];
                $num=0;
                foreach ($groups as $index => $group) {
                    $isSelected = in_array($group['group_id'], $selectedGroups);
                    $checkbox = $isSelected ? '✅ ' : '⬜️ ';
                    $tempRow[] = [
                        'text' => $checkbox . $group['title'].'('.$group['group_id'].')',
                        'callback_data' => '/group_setting_toggle:' . $group['group_id'],
                    ];
        
                    // 每2个按钮分为一行
                    if (($index + 1) % 2 === 0) {
                        $keyboard[] = $tempRow;
                        $tempRow = [];
                    }
                    $num++;
                }
        
                // 如果最后一行不足2个按钮，直接加入
                if (!empty($tempRow)) {
                    $keyboard[] = $tempRow;
                }
                    $keyboard[]=[[
                        'text' => '完成',
                        'callback_data' => '/group_setting_botgroup_ok'
                        ],[
                        'text' => '🔙 取消',
                        'callback_data' => '/group_setting'
                        ]];
                    $keyboard = [
                        'inline_keyboard' => $keyboard,
                    ]; 
                    $content = [
                        'chat_id' => $chat_id,
                        'reply_markup' => json_encode($keyboard),
                        'message_id' => $messageId,
                        'text' => "👥群组列表设置\n\n没有找到您的群组信息，先邀请机器人进群，设置为管理员，允许踢人和删除消息，机器人监视到群组发送消息会自动将群组收录进来。",
                        'parse_mode' => 'Markdown' // 改为 Markdown 格式
                    ];
                    
                    // 发送请求以编辑消息
                    $response = send($token, 'editMessageText', $content);
    }
    
    protected function group_setting_botgroup_ok($chat_id, $chatType, $text, $userId, $token, $messageId){
              $bot=Cache::store('redis')->get($this->cacheBot);
              $bot_id=$bot['bot_id'];
              $groups = Db::name('telegraggroup')->where('bot_id',$bot['bot_id'])->select();
              // 用户选择的群组存储在 Redis 中
                $selectedGroupsKey = 'selected_groups_' . $chat_id;
                
                // 获取用户已选择的群组
                $selectedGroups = Cache::store('redis')->get($selectedGroupsKey) ?? [];  
                // 将群组信息转换为按钮格式，每2个为一行
                $keyboard = [];
                $tempRow = [];
                $num=0;
                $str='';
                foreach ($groups as $index => $group) {
                    $isSelected = in_array($group['group_id'], $selectedGroups);
                    if($isSelected){
                        $str.=$group['title'].'&';
                    }
                  
                }
                $str=trim($str, '&');
                    $keyboard[]=[[
                        'text' => '🔙 取消',
                        'callback_data' => '/group_setting'
                        ]];
                    $keyboard = [
                        'inline_keyboard' => $keyboard,
                    ]; 
                    $content = [
                        'chat_id' => $chat_id,
                        'reply_markup' => json_encode($keyboard),
                        'message_id' => $messageId,
                        'text' => "👥群组列表设置\n $str \n请给该组取一个名字，方便管理:",
                        'parse_mode' => 'MarkdownV2' // 改为 Markdown 格式
                    ];
                    
                    $redisKey = "botgroupadd:$bot_id:add_status";
                    $redisHashKey = "botgroupadd:$bot_id:addmessage";
                    $waiting = 'waiting_for_message';
                    
                    $this->redis->sadd($redisKey, $waiting);
                    $this->redis->hset($redisHashKey, $waiting, $messageId);
                    
                    // 设置过期时间（25分钟）
                    $this->redis->expire($redisKey, 25 * 60);
                    $this->redis->expire($redisHashKey, 25 * 60);
                    
                    // 发送请求以编辑消息
                    $response = send($token, 'editMessageText', $content);
    }
    
    protected function group_setting_botgroup_editok($chat_id,  $text,  $token, $messageId,$bgid){
              $bot=Cache::store('redis')->get($this->cacheBot);
              $bot_id=$bot['bot_id'];
              $groups = Db::name('telegraggroup')->where('bot_id',$bot['bot_id'])->select();
              // 用户选择的群组存储在 Redis 中
                $selectedGroupsKey = 'selected_groups_' . $chat_id;
                
                // 获取用户已选择的群组
                $selectedGroups = Cache::store('redis')->get($selectedGroupsKey) ?? [];  
                // 将群组信息转换为按钮格式，每2个为一行
                $keyboard = [];
                $tempRow = [];
                $num=0;
                $str='';
                foreach ($groups as $index => $group) {
                    $isSelected = in_array($group['group_id'], $selectedGroups);
                    if($isSelected){
                        $str.=$group['title'].'&';
                    }
                  
                }
                $str=trim($str, '&');
                    $keyboard[]=[[
                        'text' => '🔙 取消',
                        'callback_data' => '/group_setting'
                        ]];
                    $keyboard = [
                        'inline_keyboard' => $keyboard,
                    ]; 
                    $content = [
                        'chat_id' => $chat_id,
                        'reply_markup' => json_encode($keyboard),
                        'message_id' => $messageId,
                        'text' => "👥群组列表设置\n $str \n请给该组取一个名字，方便管理:",
                        'parse_mode' => 'MarkdownV2' // 改为 Markdown 格式
                    ];
                    
                    $redisKey = "botgroupedit:$bot_id:add_status";
                    $redisHashKey = "botgroupedit:$bot_id:addmessage";
                    $waiting = 'waiting_for_message';
                    
                    $this->redis->sadd($redisKey, $waiting);
                    $this->redis->hset($redisHashKey, $waiting, $messageId.'_'.$bgid);
                    
                    // 设置过期时间（25分钟）
                    $this->redis->expire($redisKey, 25 * 60);
                    $this->redis->expire($redisHashKey, 25 * 60);
                    
                    // 发送请求以编辑消息
                    $response = send($token, 'editMessageText', $content);
    }
    private function checkGroupExists($token, $groupId)
    {
        try {
            $postData = [
                    'chat_id' => $groupId
                ];
            $resp = send($token, 'getChat', $postData);
            $result = json_decode($resp, true);
           // log::info($groupId);
           // log::info($result);
            // 如果返回 `ok: true`，群组存在
            if (isset($result['ok']) && $result['ok'] === true) {
                return true;
            }
        } catch (\Exception $e) {
            // 捕获 API 调用错误或群组不存在情况
            return false;
        }

        return false;
    }
        /**
     * 从 botgroup 表的 node 字段中删除指定的群组 ID
     */
    private function removeGroupIdFromBotgroup($groupId)
    {
        // 查询所有包含该群组 ID 的记录
        $botgroups = Db::name('botgroup')->field('id, node')->select();

        foreach ($botgroups as $botgroup) {
            $node = $botgroup['node'];
            $nodeArray = explode(',', $node);

            // 如果群组 ID 存在于 node 中，删除它
            if (in_array($groupId, $nodeArray)) {
                $updatedNode = implode(',', array_diff($nodeArray, [$groupId]));
                if (empty($updatedNode)) {
                // 如果没有值，删除整个记录
                    Db::name('botgroup')->where('id', $botgroup['id'])->delete();
                } else {
                    // 如果还有值，更新 node 字段
                    Db::name('botgroup')->where('id', $botgroup['id'])->update(['node' => $updatedNode]);
                }
                // 更新 botgroup 表的 node 字段
                //Db::name('botgroup')->where('id', $botgroup['id'])->update(['node' => $updatedNode]);
            }
        }
    }
    protected function group_setting_refresh($chat_id, $chatType, $text, $userId, $token, $messageId,$callbackQueryId)
    {
          $content = [
                        'callback_query_id' => $callbackQueryId,
                        'text' => '成功刷新群组列表！',
                        'show_alert' => true,
                        'cache_time' => 5
        ];
        send($token,'answerCallbackQuery', $content);
        $bot=Cache::store('redis')->get($this->cacheBot);
        
        $gxgroups = Db::name('telegraggroup')->where('bot_id',$bot['bot_id'])->select(); 
        
       foreach ($gxgroups as $index => $item) { 
           $groupId = $item['group_id'];
           $response = $this->checkGroupExists($token, $groupId);
           if (!$response) {
                // 群组不存在，从数据库中删除
                Db::name('telegraggroup')->where('id', $item['id'])->delete();
                //在 `botgroup` 表的 `node` 字段中删除群组 ID
                $this->removeGroupIdFromBotgroup($groupId);
            }
       }
        
         
                    
        $groups = Db::name('telegraggroup')->where('bot_id',$bot['bot_id'])->select(); 
          $botgroups = Db::name('botgroup')->where('bot_id',$bot['bot_id'])->select();  
                // 将群组信息转换为按钮格式，每2个为一行
                $keyboard = [];
                $tempRow = [];
                $num=0;
                foreach ($groups as $index => $group) {
                    $tempRow[] = [
                        'text' => $group['title'].'('.$group['group_id'].')',
                        'callback_data' => '/group_setting_quninfo:' . $group['group_id'],
                    ];
        
                    // 每2个按钮分为一行
                    if (($index + 1) % 2 === 0) {
                        $keyboard[] = $tempRow;
                        $tempRow = [];
                    }
                    $num++;
                }
                foreach ($botgroups as $index => $group) {
                    $tempRow[] = [
                        'text' => "👥 ".$group['title'],
                        'callback_data' => '/group_setting_botquninfo:' . $group['id'],
                    ];
        
                    // 每2个按钮分为一行
                    if (($index + 1) % 2 === 0) {
                        $keyboard[] = $tempRow;
                        $tempRow = [];
                    }
                  
                }
                // 如果最后一行不足2个按钮，直接加入
                if (!empty($tempRow)) {
                    $keyboard[] = $tempRow;
                }
                    $keyboard[]=[[
                        'text' => '批量设置',
                        'callback_data' => '/group_setting_botgroup'
                        ],[
                        'text' => '刷新群组列表',
                        'callback_data' => '/group_setting_refresh'
                        ],[
                        'text' => '🔙 返回',
                        'callback_data' => '/start'
                        ]];
                    $keyboard = [
                        'inline_keyboard' => $keyboard,
                    ]; 
                    $content = [
                        'chat_id' => $chat_id,
                        'reply_markup' => json_encode($keyboard),
                        'message_id' => $messageId,
                        'text' => "👥群组列表设置\n一共管理：*$num 个群*\n\n没有找到您的群组信息，返回上一层，先邀请机器人进群，设置为管理员，允许踢人和删除消息，机器人监视到群组发送消息会自动将群组收录进来。",
                        'parse_mode' => 'MarkdownV2' // 改为 Markdown 格式
                    ];
                    
                    // 发送请求以编辑消息
                    $response = send($token, 'editMessageText', $content);
    }
    
    
    protected function group_setting_adminior($chat_id, $chatType, $text, $userId, $token, $messageId,$callbackQueryId) 
    {
         $content = [
        		'callback_query_id' => $callbackQueryId,
        		'text' => '更新成功！',
        		'show_alert' => true,
        		'cache_time' => 5
        ];
        send($token,'answerCallbackQuery', $content);
        	
        $bot=Cache::store('redis')->get($this->cacheBot);
        $groups = Db::name('telegraggroup')->where('bot_id',$bot['bot_id'])->select();
        
        // 将群组信息转换为按钮格式，每2个为一行
        $keyboard = [];
        $tempRow = [];
        $num=0;
        $str="";
        foreach ($groups as $index => $group) {
            $data = [
                'chat_id' => $group['group_id']
            ];
            $str.= "_".$group['title']."管理员列表_\n";
        	$rs=send($token, 'getChatAdministrators', $data);
        
        	$datars = json_decode($rs, true);
        	if ($datars['ok']) {
        	    foreach ($datars['result'] as $admin) {
        	        if($admin['user']['is_bot']){$icon="🤖";}else{$icon='';}
        	         $truename = $admin['user']['first_name'] . (isset($admin['user']['last_name']) ? $admin['user']['last_name'] : '');
                    $str.= $truename.$icon.($admin['status'] === 'creator' ? '*【群主】*' : '') . "\n";
                }
        	}
        	$str.= "\n";
        	$num++;
        }
                $keyboard[]=[[
                        'text' => '🔙 返回',
                        'callback_data' => '/group_setting'
                        ]];
                    $keyboard = [
                        'inline_keyboard' => $keyboard,
                    ]; 
        	$content = [
        		'chat_id' => $chat_id,
        		'reply_markup' => json_encode($keyboard),
        		'text' => "👥群组列表\n一共管理：*$num 个群*\n\n".$str,
        		'message_id' => $messageId,
        		'parse_mode' => 'MarkdownV2' // 改为 Markdown 格式
        	];
        	
        	// 发送请求以编辑消息
        $response = send($token, 'editMessageText', $content);
      
    }
    
    
    protected function group_setting_batch($chat_id, $chatType, $text, $userId, $token, $messageId){
        $bot=Cache::store('redis')->get($this->cacheBot);
        $count = Db::name('telegraggroup')->where('bot_id',$bot['bot_id'])->count();
       $keyboard = [[
            ['text' => '🖼更新群头像',
                'callback_data' => '/group_setting_gphoto'
            ],[
                'text' => '📋更新群介绍',
                'callback_data' => '/group_setting_gdes'
            ]],[
            ['text' => '🈲违禁词',
                'callback_data' => '/banWords'
            ],[
                'text' => '📝自定义消息',
                'callback_data' => '/custom_message_start'
            ]],[
            ['text' => '🗣反刷屏',
                'callback_data' => '/backwash'
            ],[
                'text' => '🔡自动回复',
                'callback_data' => '/keyworkauto_Reply'
            ]],[
                [
                'text' => '👤 更新群管理员',
                'callback_data' => '/group_setting_adminior'
            ]
            ],[
                [
                'text' => '🔙 返回',
                'callback_data' => '/group_setting'
            ]
            ]];

        $keyboard = [
        	'inline_keyboard' => $keyboard,
        ]; 
        $content = [
        	'chat_id' => $chat_id,
        	'reply_markup' => json_encode($keyboard),
        	'message_id' => $messageId,
        	'text' => "👥群组批量设置\n\n一共管理：*$count 个群*\n\n 请选择一个类型进行设置：",
        	'parse_mode' => 'Markdown' // 改为 Markdown 格式
        ];
        
        // 发送请求以编辑消息
        $response = send($token, 'editMessageText', $content); 
    }
    
    
    protected function group_setting_botgroup_edit($chat_id,  $text,  $token, $messageId,$bgid){
        $bot=Cache::store('redis')->get($this->cacheBot);
        $selectedGroupsKey = 'selected_groups_' . $chat_id;
        if (!Cache::store('redis')->has($selectedGroupsKey)) {
        $rs=Db::name('botgroup')->where('id',$bgid)->find();
        $node=$rs['node'];
        $groupIds = explode(',', trim($node, ','));
        
        $allGroups = Db::name('telegraggroup')->where('bot_id', $bot['bot_id'])->column('group_id');
        
        $validGroupIds = array_intersect($groupIds, $allGroups);
        $cacheDuration=1200;
     
        Cache::store('redis')->set($selectedGroupsKey, $validGroupIds, $cacheDuration);
        }
              
              $groups = Db::name('telegraggroup')->where('bot_id',$bot['bot_id'])->select();
              // 用户选择的群组存储在 Redis 中
                
                
                // 获取用户已选择的群组
                $selectedGroups = Cache::store('redis')->get($selectedGroupsKey) ?? [];  
                // 将群组信息转换为按钮格式，每2个为一行
                $keyboard = [];
                $tempRow = [];
                $num=0;
                foreach ($groups as $index => $group) {
                    $isSelected = in_array($group['group_id'], $selectedGroups);
                    $checkbox = $isSelected ? '✅ ' : '⬜️ ';
                    $tempRow[] = [
                        'text' => $checkbox . $group['title'].'('.$group['group_id'].')',
                        'callback_data' => '/group_setting_edittoggle:' . $group['group_id'].'_'.$bgid,
                    ];
        
                    // 每2个按钮分为一行
                    if (($index + 1) % 2 === 0) {
                        $keyboard[] = $tempRow;
                        $tempRow = [];
                    }
                    $num++;
                }
        
                // 如果最后一行不足2个按钮，直接加入
                if (!empty($tempRow)) {
                    $keyboard[] = $tempRow;
                }
                    $keyboard[]=[[
                        'text' => '完成',
                        'callback_data' => '/group_setting_botgroup_editok:'.$bgid
                        ],[
                        'text' => '🔙 取消',
                        'callback_data' => '/group_setting'
                        ]];
                    $keyboard = [
                        'inline_keyboard' => $keyboard,
                    ]; 
                    $content = [
                        'chat_id' => $chat_id,
                        'reply_markup' => json_encode($keyboard),
                        'message_id' => $messageId,
                        'text' => "👥群组列表设置\n\n修改群标签信息",
                        'parse_mode' => 'Markdown' // 改为 Markdown 格式
                    ];
                    
                    // 发送请求以编辑消息
                    $response = send($token, 'editMessageText', $content);
    
    }
    protected function group_setting_botquninfo($chat_id,$chatType, $token, $messageId, $bgid){
        $bot=Cache::store('redis')->get($this->cacheBot);
        $rs=Db::name('botgroup')->where('id',$bgid)->find();
        $node=$rs['node'];
        $groupIds = explode(',', trim($node, ','));
        $results = Db::name('telegraggroup')
        ->whereIn('group_id', $groupIds)
        ->where('bot_id', $bot['bot_id'])
        ->field('id,group_id, title')
        ->select()
        ->toArray();
        $str = '';
        foreach ($results as $row) {
            $str .= $row['title']."&";
        }
        $str = rtrim($str, '&'); 
       $keyboard = [[
            ['text' => '🖼更新群头像',
                'callback_data' => '/group_setting_gphoto:'.$bgid
            ],[
                'text' => '📋更新群介绍',
                'callback_data' => '/group_setting_gdes:'.$bgid
            ]],[
            ['text' => '🈲文本过滤',
                'callback_data' => '/kwfilter:'.$bgid
            ],[
                'text' => '📝自定义消息',
                'callback_data' => '/custom_message_start:'.$bgid
            ]],[
            ['text' => '🗣反刷屏',
                'callback_data' => '/backwash:'.$bgid
            ],[
                'text' => '🔡自动回复',
                'callback_data' => '/keyworkauto_Reply:'.$bgid
            ]],[
            ['text' => '👥修改当前组',
                'callback_data' => '/group_setting_botgroup_edit:'.$bgid
            ],[
                'text' => '👥删除当前组',
                'callback_data' => '/group_setting_botgroup_del:'.$bgid
            ]],[
                [
                'text' => '🔙 返回',
                'callback_data' => '/group_setting'
            ]
            ]];

        $keyboard = [
        	'inline_keyboard' => $keyboard,
        ]; 
        $content = [
        	'chat_id' => $chat_id,
        	'reply_markup' => json_encode($keyboard),
        	'message_id' => $messageId,
        	'text' => "👥群组批量设置【".$rs['title']."】\n\n$str\n\n 请选择一个类型进行设置：",
        	'parse_mode' => 'Markdown' // 改为 Markdown 格式
        ];
        
        // 发送请求以编辑消息
        $response = send($token, 'editMessageText', $content); 
    }
    
    //群信息
    protected function group_setting_quninfo($chat_id,$chatType, $token, $messageId, $groupOneId){
                $data = [
                    'chat_id' => $groupOneId
                ];
                $str="";
                $str.= "_群组信息_\n\n";
            	$rsg=send($token, 'getChat', $data);
            	$datarsg = json_decode($rsg, true);
            	log::write($datarsg);
                if ($datarsg['ok']) {
            	   $chatInfo =$datarsg['result'];
            	    $str.= "*群名称: *" . $chatInfo['title'] . "\n";
                    $str.= "*群描述: *" . ($chatInfo['description'] ?? "无描述") . "\n";
                    $str.= "*群类型: *" . $chatInfo['type'] . "\n";
            	}
            	
                $data = [
                    'chat_id' => $groupOneId
                ];
               
                $str.= "\n_管理员列表_\n\n";
            	$rs=send($token, 'getChatAdministrators', $data);
                $datars = json_decode($rs, true);
            	
            	if ($datars['ok']) {
            	    foreach ($datars['result'] as $admin) {
            	        if($admin['user']['is_bot']){$icon="🤖";}else{$icon='';}
            	         $truename = $admin['user']['first_name'] . (isset($admin['user']['last_name']) ? $admin['user']['last_name'] : '');
                        $str.= $truename.$icon.($admin['status'] === 'creator' ? '*【群主】*' : '') . "\n";
                    }
            	}
            	$str.= "\n\n";
            	 //log::write($str);
                 $keyboard[]=[[
                        'text' => '🔙 返回',
                        'callback_data' => '/group_setting'
                        ]];
                    $keyboard = [
                        'inline_keyboard' => $keyboard,
                    ]; 
                    
                    $content = [
                        'chat_id' => $chat_id,
                        'reply_markup' => json_encode($keyboard),
                        'message_id' => $messageId,
                        'text' => "👥群组信息\n\n".$str,
                        'parse_mode' => 'MarkdownV2' // 改为 Markdown 格式
                    ];
                    
                    // 发送请求以编辑消息
                    $response = send($token, 'editMessageText', $content);
                   // log::write($response);
    }
    
    
    protected function group_setting_botgroup_del($chat_id,  $text,  $token, $messageId,$bgid){
         $rs=Db::name('botgroup')->where('id',$bgid)->find();
        $Buttons = [
        	[
        	    	['text' => '✅ 确定删除该群组标签', 'callback_data' => '/group_setting_botgroup_delall:'.$bgid],
        		],[['text' => '🔙 取消', 'callback_data' => '/group_setting_botquninfo:'.$bgid] // 确保按钮是对象
        	]
        ];
        $replyMarkup = json_encode(['inline_keyboard' => $Buttons]);
        $content = [
        	'chat_id' => $chat_id,
        	'text' => "删除* ".$rs['title']." *群组标签\n\n你将一键删除该群组制定的规则。\n\n⚠️ 你确定要删除吗？",
        	'reply_markup' => $replyMarkup,
        	'message_id' => $messageId,
        	'parse_mode' => 'MarkdownV2' 
        ];
        
        $ttres=send($token,'editMessageText', $content);
    }
}
