<?php
//已优化
// 已优化
namespace app\robot\controller;

use think\facade\Db;
use think\facade\Log;
use think\facade\Cache;
use app\BaseController;
//use app\admin\model\Telegrambot as TelegrambotModel;
use app\robot\controller\Checkfortelemessage;//自定义回复
use app\robot\controller\Checkforbannedwords;//违禁词
use app\robot\controller\Checkforcryptoquery;//币查询
use app\robot\controller\Checkforkeyword;//币查询
use app\common\RedisConnectionPool;//缓存
/**
 * Telegram机器人API
**/
class Apibot extends BaseController
{
    protected $checkfortelemessage;
    protected $checkforbannedwords;
    protected $checkforcryptoquery;
    protected $checkforkeyword;
    protected $redis;
    protected $cacheBot;
    public function __construct()
    {
         
         $this->redis = RedisConnectionPool::getConnection();
         if (!$this->redis || !$this->redis->ping()) {
            throw new \Exception("Redis 实例化或连接失败");
        }
         $id = $_GET['id'] ?? null;
        if ($id) {
            $this->cacheBot = 'telegram_bot_' . $id;
        }
        $this->checkfortelemessage = new Checkfortelemessage($id);
         $this->checkforbannedwords = new Checkforbannedwords($id);
         $this->checkforcryptoquery = new Checkforcryptoquery($id);
         $this->checkforkeyword = new Checkforkeyword($id);
         
    }
    public function testttt()
    {
        echo(2);
    }
    public function index()
    {
        
         
        $rawData = file_get_contents('php://input');
        $update = json_decode($rawData, true);
        
        if (!$update) {
            Log::error("Empty update received.");
            return;
        }
        $id = $_GET['id'] ?? null;  // 如果 'id' 不存在，则避免报错
        if ($id) {
            $cacheKey = 'telegram_bot_' . $id;
            $this->cacheBot = $cacheKey;  // 设置 cacheBot
        
        }else{
            Log::error("Empty update received.");
            return;
        }
     
        
        if (Cache::store('redis')->has($cacheKey)) {
            $cachedBot = Cache::store('redis')->get($cacheKey);
            $bot_token =$cachedBot['bot_token'];
        }else{
           $rs = Db::name('telegrambot')->where('id', $id)->find();
           Cache::store('redis')->set($cacheKey, $rs, 3600);
           $bot_token =$rs['bot_token'];
        }
        $cacheButtonKey="telegram_button";
        if (!Cache::store('redis')->has($cacheButtonKey)) {
           $Buttondata = Db::name('telebutton')->where('status', 1)
            ->order('row_number', 'asc')
            ->order('sortid', 'asc')->select()->toArray();
           Cache::store('redis')->set($cacheButtonKey, $Buttondata, 3600);
          
        }
      
        $update['token'] = $bot_token;
      
        // 记录更新日志
        //Log::write($update);

        // 判断并处理不同的事件
        if (isset($update['message'])) {
            if (isset($update['message']['new_chat_member'])) {
                
                $this->handleMemberChange($update['message']['message_id'],$update['message']['chat'], $update['message']['new_chat_member'], 'join',$bot_token);
            }elseif (isset($update['message']['left_chat_member'])) {
                
                $this->handleMemberChange($update['message']['message_id'],$update['message']['chat'], $update['message']['left_chat_member'], 'leave',$bot_token);
            }elseif (isset($update['message']['forward_from']) || isset($update['message']['forward_from_chat'])) {
                $chatId = $update['message']['chat']['id'];
                $message['chat_id']=$update['message']['chat']['id'];
                $message['fromid']=$update['message']['from']['id'];
                $message['message_id']=$update['message']['message_id'];
                // 获取原始消息的来源
                if (isset($update['message']['forward_from'])) {
                    $this->handleAdminMessage($message, 'checkfoward');
                } elseif (isset($update['message']['forward_from_chat'])) {
                    $forwardChatId = $update['message']['forward_from_chat']['id'];
                    if($forwardChatId!=$chatId){
                        $this->handleAdminMessage($message, 'checkfoward');
                    }
                }
            }else{
               $this->processMessage($update['message'], $bot_token,$cacheKey);  
            }
           
        }

        // 回调函数
        if (isset($update['callback_query'])) {
            $update['callback_query']['token'] = $bot_token;
            
            $this->handleAdminMessage($update['callback_query'], 'callback_query');
        }

        // 处理机器人加入或移出
        if (isset($update['my_chat_member'])) {
            $this->processChatMemberChange($update['my_chat_member'], $bot_token);
        }
    }
    // 回调函数
    private function processMessage($message, $token,$cacheKey)
    {
        $message['token'] = $token;
        $chatType = $message['chat']['type'];

        // 私聊消息处理
        if ($chatType == 'private') {
            $this->handleAdminMessage($message, 'botMessagejob');
        } 
        // 群组消息处理
        else {
              // 更新该群组的最后一次发言时间
            Cache::store('redis')->set('last_group_activity_' . $message['chat']['id'], time());
            Cache::store('redis')->set('group_notification_status_' . $message['chat']['id'], 0);
            $this->cachegroup($message['chat']['id'],$message['chat']['title'],$message['chat']['type'],$cacheKey);
            
            $this->handleAdminMessage($message, 'Messagejob');
            
            // 图片消息处理
            if (isset($message['photo'])) {
                $this->handleAdminMessage($message, 'Messagephotojob');
            }
        }
    }
     //用户信息加入或者离开
    private function processChatMemberChange($memberChange, $token)
    {
        $chat = $memberChange['chat'];
        $newMember = $memberChange['new_chat_member']['user'] ?? null;
        $leftMember = $memberChange['left_chat_member']['user'] ?? null;

        if ($newMember) {
            $this->handleMemberChange('', $chat, $newMember, 'join', $token);
        } elseif ($leftMember) {
            $this->handleMemberChange('', $chat, $leftMember, 'leave', $token);
        }
    }

    // 处理成员加入或离开事件
    private function handleMemberChange($messageid, $chat, $member, $action, $token)
    {
        $data = [
            'token'      => $token,
            'message_id' => $messageid,
            'group_id'   => $chat['id'],
            'group_name' => $chat['title'] ?? 'unknown',
            'group_type' => $chat['type'],
            'user_id'    => $member['id'],
            'username'   => $member['username'] ?? 'unknown',
            'first_name' => $member['first_name'] ?? 'unknown',
            'action'     => $action,
        ];

        if (!empty($member['is_bot'])) {
            $data['event_type'] = $action === 'join' ? 'bot_added_to_group' : 'bot_removed_from_group';
        }else {
            $data['event_type'] = ($action === 'join') ? 'user_added_to_group' : 'user_removed_from_group';
        }

        $this->handleAdminMessage($data, 'Telegramupdatesjob');
    }
    //控制器处理
    private function handleAdminMessage($data, $jobType)
    {
        if (isset($jobType)) {
            
            switch ($jobType) {
                case 'callback_query':
                    $Callbackqueryjob = new \app\robot\controller\Callbackquery();
                    $Callbackqueryjob->handle($data);
                    break;
                case 'botMessagejob':
                    $botMessageJob = new \app\robot\controller\Botmessagejob();
                    $botMessageJob->handle($data);
                    break;
                case 'Messagejob':
                    $Messagejob = new \app\robot\controller\Messagejob();
                    $Messagejob->handle($data);
                    break;
                case 'checkfoward':
                    $checkfoward = new \app\robot\controller\Checkforward();
                    $checkfoward->handle($data);
                    break;    
                case 'Messagephotojob':
                    $Messagephotojob = new \app\robot\controller\Messagephotojob();
                    $Messagephotojob->handle($data);
                    break;
                case 'Telegramupdatesjob':
                    $Telegramupdatesjob = new \app\robot\controller\Telegramupdatesjob();
                    $Telegramupdatesjob->handle($data);
                    break;
            }
        }
    }
    private function cachegroup($chatId,$title,$type,$cacheKey){
       
        $data = Cache::store('redis')->get($cacheKey);
        $username = $data['bot_name'];
        $first_name = $data['first_name'];
        $botId = $data['bot_id'];
        $cacheGroupkey="telegram_group_".$chatId.'_'.$data['bot_id'];
        //log::info($chatId);
        if (!Cache::store('redis')->has($cacheGroupkey)) {
           $rs = Db::name('telegraggroup')->where('group_id', $chatId)->where('bot_id', $botId)-> find();
           if($rs){
                Cache::store('redis')->set($cacheGroupkey, $rs, 3600);
           }else{
              
               $gid=Db::name('telegraggroup')->insertGetId([
                    'group_id' => $chatId,
                    'title' => $title,
                    'type' => $type, 
                    'username' => $username,
                    'first_name' => $first_name,
                    'bot_id' => $botId,
                    'create_time' => time(),
                ]);
                $rs = Db::name('telegraggroup')->where('group_id', $chatId)->where('bot_id', $botId)-> find();
                Cache::store('redis')->set($cacheGroupkey, $rs, 3600);
               /* $rsxx = Db::name('xxsetting')->where('bot_id', $botId)->find();
                if (!$rsxx) {
                Db::name('xxsetting')->insert([
                        'user_id' => $botId,
                    ]);
                    
                } */
           }
        }
    }
}
