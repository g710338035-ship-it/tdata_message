<?php
namespace app\robot\controller;

use think\facade\Db;
use think\facade\Log;
use think\Request;
use think\facade\Cache;

class Welcomeinfo extends Apibot
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
        $cacheGroupkey="telegram_group_".$chat_id;
        $groupInfo = Cache::store('redis')->get($cacheGroupkey);
        echo "message welcome info: $text";
        
        // 处理不同命令
        switch ($text) {
            case '/welcome_set':
                $this->welcome_set($chat_id, $chatType, $text, $userId, $token, $messageId);
                break;
            case '/welcome_del':  
		      
                Db::name('telegraggroup')->where('group_id', $chat_id)->update(['content' => '']);
                $groupInfo['content']='';
                
                $content = [
                        'callback_query_id' => $callbackQueryId,
                        'text' => '欢迎语删除操作成功！可以重新添加',
                        'show_alert' => true,
                        'cache_time' => 5
                    ];
                send($token,'answerCallbackQuery', $content);
                    
                Cache::store('redis')->set($cacheGroupkey, $groupInfo);
                $this->welcome_set($chat_id, $chatType, $text, $userId, $token, $messageId);
                    
                break;
		    case '/welcome_status':   
		        //$groupInfo=Db::name('telegraggroup')->where('group_id',$chat_id)->find();
		        $iswel = ($groupInfo['iswel'] == 0) ? 1 : 0;
                // 更新 is_top 值
                Db::name('telegraggroup')->where('group_id', $chat_id)->update(['iswel' => $iswel]);
                
                    $groupInfo['iswel']=$iswel;
                  
                    Cache::store('redis')->set($cacheGroupkey, $groupInfo);
                    $this->welcome_set($chat_id, $chatType, $text, $userId, $token, $messageId);
                break;
            case '/welcome_add_setting':
                $this->welcome_add_setting($chat_id, $chatType, $text, $userId, $token, $messageId);
                break;
                
            case '/welcome_add_see':
                $this->welcome_add_see($chat_id, $chatType, $text, $userId, $token, $messageId);
                break;
                
            case '/welcome_add_info':
                
                    $bwButtons[] = [[
                        'text' => '❌撤销',
                        'callback_data' => '/welcome_add_info_back'
                    ]];
                    $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                    $welcome = isset($groupInfo['content'])?$groupInfo['content'] : '无';
                    
                    $content = [
                    'chat_id' => $chat_id,
                    'text' => "\n👥设置。👏欢迎语，\n\n {$username},现在发送欢迎消息！你可以使用：\n• `{truename}` = 姓名\n• `{date}` = 进群日期\n• `{username}` = 用户名 \n• `{groupname}` = 群组名称。\n\n现在欢迎语为：\n$welcome",
                    'reply_markup' => $replyMarkup,
                    'message_id' => $messageId,
                    'parse_mode' => 'Markdown'
                ];
                    
                    $ttres = send($token, 'editMessageText', $content);
                    $redisKey = "welcomeadd:$userId:add_status";
                    $redisHashKey = "welcomeadd:$userId:addmessage";
                    $waiting = 'waiting_for_message';
                    
                    $this->redis->sadd($redisKey, $waiting);
                    $this->redis->hset($redisHashKey, $waiting, $messageId);
                    
                    // 设置过期时间（25分钟）
                    $this->redis->expire($redisKey, 25 * 60);
                    $this->redis->expire($redisHashKey, 25 * 60);
               
                break;
                
            case '/welcome_add_info_back':
                $waiting = 'waiting_for_message';
                $redisKeywelcomeadd = "welcomeadd:$userId:add_status";
                $redisHashKeywelcomeadd = "welcomeadd:$userId:addmessage";
                
                $this->redis->srem($redisKeywelcomeadd, $waiting);
                $this->redis->del($redisHashKeywelcomeadd, $waiting, $messageId);
                $this->welcome_add_setting($chat_id, $chatType, $text, $userId, $token, $messageId);
                break;
        }
    }
    protected function welcome_set($chat_id, $chatType, $text, $userId, $token, $messageId){
        $cacheGroupkey="telegram_group_".$chat_id;
        
            $groupInfo = Cache::store('redis')->get($cacheGroupkey);
                    $bwButtons = [];
                    if ($groupInfo['iswel']==1) {
                        $welStatus = ' 已开启✅️';
                    } else {
                        $welStatus =  ' 已关闭✖️';
                    } 
                  
                    $customMessage="👏欢迎语设置\n\n状态：".$welStatus."\n\n模式: 仅在用户首次进群时发送欢迎消息\n\n欢迎语内容：".$groupInfo['content'];
                    $buttons = getTelebuttonByTmgId(21);
                    
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
                        if($button['content']=='/welcome_status'){
                            $button['title']=$welStatus;
                        }
                        // 添加按钮到当前行
                        $currentRow[] = ['text' => $button['title'], 'callback_data' => $button['content']];
                    
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
                    $content = [
                        'chat_id' => $chat_id,
                        'reply_markup' => json_encode($keyboard),
                        'message_id' => $messageId,
                        'text' => $customMessage,
                        'parse_mode' => 'Markdown' // 改为 Markdown 格式
                    ];
                    
                    // 发送请求以编辑消息
                    $response = send($token, 'editMessageText', $content);
    }
    
    protected function welcome_add_setting($chat_id, $chatType, $text, $userId, $token, $messageId)
    {
        $cacheGroupkey="telegram_group_".$chat_id;
        $groupInfo = Cache::store('redis')->get($cacheGroupkey);
        if ($groupInfo['iswel']==1) {
            $welStatus = ' 已开启✅️';
            } else {
            $welStatus =  ' 已关闭✖️';
        } 
        $messageText = "\n👏欢迎语设置\n\n$welStatus \n\n请点击下方按钮进行操作。";
        $bwButtons = [
            [[
                'text' => '➕ 添加/修改',
                'callback_data' => '/welcome_add_info'
            ], [
                'text' => '👀 查看',
                'callback_data' => '/welcome_add_see'
            ]], [
                [
                    'text' => '🔙 返回',
                    'callback_data' => '/welcome_set'
                ]
            ]
        ];
        
        $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
        
        $content = [
            'chat_id' => $chat_id,
            'text' => $messageText,
            'message_id' => $messageId,
            'reply_markup' => $replyMarkup,
            'parse_mode' => 'HTML'
        ];
        send($token, 'editMessageText', $content);
    }
    
    protected function welcome_add_see($chat_id, $chatType, $text, $userId, $token, $messageId)
    {
       
        $cacheGroupkey="telegram_group_".$chat_id;
        $groupInfo = Cache::store('redis')->get($cacheGroupkey);
        
        $messageText = $groupInfo['content'] ?: '暂无欢迎语';
     
        $bwButtons = [
            [[
                'text' => '🔙 返回',
                'callback_data' => '/welcome_add_setting'
            ]]
        ];
        
        $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
        
        $content = [
            'chat_id' => $chat_id,
            'text' => $messageText,
            'message_id' => $messageId,
            'reply_markup' => $replyMarkup,
        ];
        send($token, 'editMessageText', $content);
    }
}
