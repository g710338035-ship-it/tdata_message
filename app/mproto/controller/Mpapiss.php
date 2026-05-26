<?php
namespace app\mproto\controller;

use think\facade\Db;
use think\facade\Log;
use think\facade\Config;
use think\facade\Request;
use app\BaseController;
use danog\MadelineProto\API;
use danog\MadelineProto\Exception;
use think\facade\Cache;
use think\facade\Queue;
/**
 * Telegram机器人API控制器，负责处理与MadelineProto相关的消息接收、处理和发送等操作
 */
class Mpapi extends BaseController
{
    // 存储MadelineProto实例的数组，每个实例对应一个账号
    public $madelineInstances = [];

    /**
     * 构造函数，初始化一个MadelineProto实例
     */
    public function __construct()
    {
        
    }

     /**
     * 获取或创建指定账号的MadelineProto实例
     * @param string $accountName 账号标识（例如电话号码）
     * @return API
     */
    public function getMadelineInstance(string $accountName): API
    {
        if (!isset($this->madelineInstances[$accountName])) {
            $config = Config::get('madelineproto');
            // 使用账号标识的MD5值生成会话文件名
            $sessionPath = $config['session_path'] . md5($accountName) . '.madeline';
            $this->madelineInstances[$accountName] = new API($sessionPath);
        }
        return $this->madelineInstances[$accountName];
    }

    /**
     * 启动Webhook，将指定账号的Webhook URL设置为当前应用的Webhook处理地址
     * @param string $phone 电话号码
     * @return bool 操作是否成功
     */
    public function startWebhook($phone)
    {
        // 推送异步任务到队列
       // Queue::push('app\job\RecordGroupUserIds', ['accountName' => $phone]);
        // 获取MadelineProto的配置信息
        $instance = $this->getMadelineInstance($phone);
        // 获取应用的域名配置
        $domain = config('app.domainurl');
        // 构建Webhook URL
        $webhookUrl = $domain . "/mproto/Mpapi/webhook/$phone";
        
        try {
            // 使用MadelineProto设置Webhook URL
            $instance->setWebhook($webhookUrl);
        } catch (Exception $e) {
            // 记录设置Webhook时出现的错误信息
            Log::info("Error setting webhook for $accountName: " . $e->getMessage());
        }
        return true;
    }
          /**
     * 停止 Webhook，将指定账号的 Webhook URL 设置为空
     * @param string $accountName 账号标识（例如电话号码或其它唯一标识）
     * @return bool 操作是否成功
     */
    public function stopWebhook($accountName)
    {
        // 通过统一的实例管理获取当前账号对应的 MadelineProto 实例
        $instance = $this->getMadelineInstance($accountName);
        
        try {
            // 调用 MadelineProto 实例的 setWebhook 方法，将 Webhook URL 设置为空以停止 Webhook
            $instance->setWebhook('');
            Log::info("Webhook for account '$accountName' has been stopped.");
        } catch (Exception $e) {
            // 若停止 Webhook 时出现异常，记录具体的错误信息到日志
            Log::error("Error stopping webhook for account '$accountName': " . $e->getMessage());
        }
        
        return true;
    }
    /**
     * Webhook处理方法，接收Telegram发送的更新数据并根据更新类型进行相应处理
     * @param Request $request 请求对象
     * @return \think\response\Json 响应结果
     */
    public function webhook(Request $request)
    {
        // 使用 PHP 内置变量获取当前请求的 URI
        $requestUri = $_SERVER['REQUEST_URI'];
        // 解析URL路径（去除查询字符串部分）
        $parsedPath = parse_url($requestUri, PHP_URL_PATH);
        // 假设路径格式为：/mproto/Mpapi/webhook/{accountName}
        $segments = explode('/', trim($parsedPath, '/'));
        // 根据路径中的第三个段获取账号标识（例如：Mpapi/webhook/account1 => account1）
        $accountName = isset($segments[3]) ? $segments[3] : 'default';
         //Log::info($accountName);
        // 根据账号名称获取对应的 MadelineProto 实例
        $instance = $this->getMadelineInstance($accountName);
        
        // 获取Webhook传入的JSON数据
        $data = Request::instance()->getContent();
        // 将JSON数据解码为关联数组
        $update = json_decode($data, true);
        // 记录接收到的原始数据，方便调试
        //Log::info(json_encode($update, JSON_PRETTY_PRINT));

        if ($update) {
            switch ($update['_']) {
                case 'updateNewMessage':
                    Log::info(json_encode($update, JSON_PRETTY_PRINT));
                    // 处理普通用户的新消息
                    $this->handleNewMessage($update, $accountName, $instance);
                    break;
                case 'updateNewChannelMessage':
                    Log::info(json_encode($update, JSON_PRETTY_PRINT));
                    // 处理频道的新消息
                    $this->handleNewChannelMessage($update, $accountName, $instance);
                    break;
                    
                case 'updateChatParticipants':
                    // 处理普通聊天成员更新
                    $this->handleChatParticipantsUpdate($update, $accountName, $instance);
                    break;    
                /*case 'updateEditMessage':
                    // 处理普通用户编辑的消息
                    $this->handleEditMessage($update);
                    break;
                case 'updateEditChannelMessage':
                    // 处理频道编辑的消息
                    $this->handleEditChannelMessage($update);
                    break;
                case 'updateDeleteMessages':
                    // 处理普通用户删除的消息
                    $this->handleDeleteMessages($update);
                    break;
                case 'updateDeleteChannelMessages':
                    // 处理频道删除的消息
                    $this->handleDeleteChannelMessages($update);
                    break;
                case 'updateUserStatus':
                    // 处理用户状态更新
                    $this->handleUserStatusUpdate($update);
                    break;
                
                case 'updateChannelParticipants':
                    // 处理频道成员更新
                    $this->handleChannelParticipantsUpdate($update, $accountName, $instance);
                    break;*/
                default:
                    // 记录未处理的更新类型
                    //Log::info("Unhandled update type: " . $update['_']);
                    break;
            }
        }

        return json(['status' => 'success']);
    }

    /**
     * 处理普通用户的新消息，包括存储用户信息和检查是否需要推送消息
     * @param array $update 更新数据
     */
    private function handleNewMessage($update, $accountName, $instance)
    {
        $message = $update['message']?? [];
        $text = $message['message'] ?? '';
        $text = json_decode('"' . $text . '"');
        $user_id = $message['from_id'] ?? null;
        $chat_id = $message['peer_id'] ?? null;
        $user_nickname = null;
       // log::info(json_encode($message));
        //log::info($text);
        if($text=='oks'){
            Log::info("Text equals 'oks', pushing job to queue with account name: ".$accountName );
           // Queue::push('app\job\RecordGroupUserIds', ['accountName' => $accountName]);
            Log::info("Job pushed to queue successfully.");
        }
        if ($text && $user_id&&$chat_id<0) {
           // log::info($text);
            $userInfo = $this->getUserInfo($user_id, $accountName, $instance);
            // 生成消息链接
            $message_link = $this->getMessageLink($message['peer_id'], $message['id'], $accountName, $instance,2);

            // 存储用户信息到数据库
            $this->saveUserInfo($user_id, $userInfo['username'], $userInfo['nickname'], $text, null, $message_link, $accountName);
            // 保存群组信息到数据库
            $this->saveGroupInfo($chat_id,  $accountName, $instance);
         
    //推送规则
            // 判断群组是否为英文群组
            if ($this->isEnglishGroup($chat_id, $accountName, $instance)) {
                  // 任务生成缓存键
                    $cacheKey = "monitor_tasks_{$accountName}";
                    // 尝试从缓存中获取监控任务
                    $tasks = Cache::store('redis')->get($cacheKey);

                    if (!$tasks) {
                        // 如果缓存中不存在，则进行多表关联查询
                        $tasks = Db::name('mprenwu')
                            ->alias('t')
                            ->join('cd_mpgrouptag mgt', 't.mpgt_id = mgt.id')
                            ->join('cd_monitorphone mp', 'mgt.mp_id = mp.id')
                            ->where('t.status', 1)
                            ->where('mp.phone', $accountName)
                            ->where(function ($query) use ($chat_id) {
                                $query->whereRaw("FIND_IN_SET(?, mgt.access)", [$chat_id]);
                            })
                            ->select();

                        // 将查询结果存入缓存，设置缓存时间（例如 3600 秒，即 1 小时）
                        Cache::store('redis')->set($cacheKey, $tasks, 3600);
                    }
                     // 判断昵称是否为中文
                $isNicknameChinese = $this->isChinese($userInfo['nickname']);
                // 判断发言是否为中文
                $isMessageChinese = $this->isChinese($text);
                $ispushm=false;
                $ispushm_chatid='';
                    foreach ($tasks as $task) {
                        // 获取当前任务的过滤规则
                        $filterType = $task['filterType'];
                        $filterValue = $task['filterValue'];
                        if($filterType=='user_id'){
                            if ($isNicknameChinese || $isMessageChinese){
                                $ispushm=true;
                                $ispushm_chatid=$task['push_chatid'];
                            }
                        }elseif($filterType=='keyword'){
                            if ($isNicknameChinese || $isMessageChinese || $this->containsKeywords($text, $filterValue)) {
                                $ispushm=true;
                                $ispushm_chatid=$task['push_chatid'];
                            }
                        }
                        
                    }
               
                
                // 检查发言是否包含关键词
            if ($ispushm&&$ispushm_chatid) {
                // 生成 Redis 缓存键
                $redisKey = "user_recorded_{$accountName}_{$user_id}";
                $userRecordCache = Cache::store('redis')->get($redisKey);

                if ($userRecordCache && $userRecordCache['has_message_text'] &&!$userRecordCache['is_pushed']) {
                    // 获取用户第一次发送的消息
                    $firstMessageInfo = Db::name('mpuser')
                        ->where('user_id', $user_id)
                        ->where('phone', $accountName)
                        ->find();

                    if ($firstMessageInfo) {
                        // 构造推送内容
                        $pushContent = "用户信息：\n";
                        $pushContent.= "用户ID：{$firstMessageInfo['user_id']}\n";
                        $pushContent.= "用户名：{$firstMessageInfo['username']}\n";
                        $pushContent.= "昵称：{$firstMessageInfo['user_nickname']}\n";
                        $pushContent.= "第一次发言内容：{$firstMessageInfo['message_text']}\n";
                        $pushContent.= "消息链接：{$firstMessageInfo['message_link']}\n";
                   
                        // 推送消息到指定群组
                        $this->pushMessage($ispushm_chatid, $text, $accountName, $message_link);
                       $firstMessageInfo = Db::name('mpuser')
                        ->where('user_id', $user_id)
                        ->where('phone', $accountName)
                        ->update(['is_pushed'=>true]);
                        // 标记消息为已推送
                        $userRecordCache['is_pushed'] = true;
                        Cache::store('redis')->set($redisKey, $userRecordCache);
                    }
                }
            }
        
            }
            
           
           
        }
    }

    /**
     * 处理频道的新消息，逻辑与处理普通用户新消息类似
     * @param array $update 更新数据
     */
    private function handleNewChannelMessage($update, $accountName, $instance)
    {
        $message = $update['message'];
        
        if (isset($message['peer_id']) && isset($message['message'])) {
            // 获取频道ID
            $chat_id = $message['peer_id'];
           // Log::info(json_encode($userInfo, JSON_PRETTY_PRINT));
            // 获取消息文本
            $text = $message['message'];
            // 获取用户ID
            $user_id = $message['from_id'] ?? null;
            // 获取用户昵称
            $userInfo = $this->getUserInfo($user_id, $accountName, $instance);
            // 生成消息链接
            $message_link = $this->getMessageLink($chat_id, $message['id'], $accountName, $instance,2);

            // 存储用户信息到数据库
            $this->saveUserInfo($user_id,  $userInfo['username'], $userInfo['nickname'], $text, null, $message_link, $accountName);
            // 保存群组信息到数据库
            $this->saveGroupInfo($chat_id,  $accountName, $instance);
            //推送规则
            // 判断群组是否为英文群组
            if ($this->isEnglishGroup($chat_id, $accountName, $instance)) {
                  // 任务生成缓存键
                    $cacheKey = "monitor_tasks_{$accountName}";
                    // 尝试从缓存中获取监控任务
                    $tasks = Cache::store('redis')->get($cacheKey);

                    if (!$tasks) {
                        // 如果缓存中不存在，则进行多表关联查询
                        $tasks = Db::name('mprenwu')
                            ->alias('t')
                            ->join('cd_mpgrouptag mgt', 't.mpgt_id = mgt.id')
                            ->join('cd_monitorphone mp', 'mgt.mp_id = mp.id')
                            ->where('t.status', 1)
                            ->where('mp.phone', $accountName)
                            ->where(function ($query) use ($chat_id) {
                                $query->whereRaw("FIND_IN_SET(?, mgt.access)", [$chat_id]);
                            })
                            ->select();

                        // 将查询结果存入缓存，设置缓存时间（例如 3600 秒，即 1 小时）
                        Cache::store('redis')->set($cacheKey, $tasks, 3600);
                    }
                     // 判断昵称是否为中文
                $isNicknameChinese = $this->isChinese($userInfo['nickname']);
                // 判断发言是否为中文
                $isMessageChinese = $this->isChinese($text);
                $ispushm=false;
                $ispushm_chatid='';
                    foreach ($tasks as $task) {
                        // 获取当前任务的过滤规则
                        $filterType = $task['filterType'];
                        $filterValue = $task['filterValue'];
                        if($filterType=='user_id'){
                            if ($isNicknameChinese || $isMessageChinese){
                                $ispushm=true;
                                $ispushm_chatid=$task['push_chatid'];
                            }
                        }elseif($filterType=='keyword'){
                            if ($isNicknameChinese || $isMessageChinese || $this->containsKeywords($text, $filterValue)) {
                                $ispushm=true;
                                $ispushm_chatid=$task['push_chatid'];
                            }
                        }
                        
                    }
               
                
                // 检查发言是否包含关键词
            if ($ispushm&&$ispushm_chatid) {
                // 生成 Redis 缓存键
                $redisKey = "user_recorded_{$accountName}_{$user_id}";
                $userRecordCache = Cache::store('redis')->get($redisKey);
                log::info('推送id'.$ispushm_chatid);
                if ($userRecordCache && $userRecordCache['has_message_text'] &&!$userRecordCache['is_pushed']) {
                    // 获取用户第一次发送的消息
                    $firstMessageInfo = Db::name('mpuser')
                        ->where('user_id', $user_id)
                        ->where('phone', $accountName)
                        ->find();

                    if ($firstMessageInfo) {
                        // 构造推送内容
                        $pushContent = "用户信息：\n";
                        $pushContent.= "用户ID：{$firstMessageInfo['user_id']}\n";
                        $pushContent.= "用户名：{$firstMessageInfo['username']}\n";
                        $pushContent.= "昵称：{$firstMessageInfo['user_nickname']}\n";
                        $pushContent.= "第一次发言内容：{$firstMessageInfo['message_text']}\n";
                        $pushContent.= "消息链接：{$firstMessageInfo['message_link']}\n";
                   
                        // 推送消息到指定群组
               
                       $this->pushMessage($ispushm_chatid, $pushContent, $message_link, $accountName);
                       $firstMessageInfo = Db::name('mpuser')
                        ->where('user_id', $user_id)
                        ->where('phone', $accountName)
                        ->update(['is_pushed'=>true]);
                        // 标记消息为已推送
                        $userRecordCache['is_pushed'] = true;
                        Cache::store('redis')->set($redisKey, $userRecordCache);
                    }
                }
            }
        
            }
             
        }
    }

    /**
     * 处理普通用户编辑的消息，主要是存储编辑后的用户信息
     * @param array $update 更新数据
     */
    private function handleEditMessage($update)
    {
        $message = $update['message'];
        if (isset($message['chat']) && isset($message['text'])) {
            // 获取聊天ID
            $chat_id = $message['chat']['id'];
            // 获取消息文本
            $text = $message['text'];
            // 获取用户ID
            $user_id = $message['from']['id'];
            // 获取用户昵称，如果没有用户名则使用名字
            $user_nickname = $message['from']['username'] ?? $message['from']['first_name'];
            // 生成消息链接
            $message_link = $this->getMessageLink($chat_id, $message['id']);

            // 存储用户信息到数据库
            $this->saveUserInfo($user_id, $user_nickname, $text, null, $message_link);
            // 保存群组信息到数据库
            $this->saveGroupInfo($chat_id,  $accountName, $instance);
        }
    }

    /**
     * 处理频道编辑的消息，逻辑与处理普通用户编辑消息类似
     * @param array $update 更新数据
     */
    private function handleEditChannelMessage($update)
    {
        $message = $update['message'];
        if (isset($message['peer_id']) && isset($message['message'])) {
            // 获取频道ID
            $chat_id = $message['peer_id'];
            // 获取消息文本
            $text = $message['message'];
            // 获取用户ID
            $user_id = $message['from_id']['user_id'] ?? null;
            // 获取用户昵称
            $user_nickname = $this->getUserName($user_id);
            // 生成消息链接
            $message_link = $this->getMessageLink($chat_id, $message['id']);

            // 存储用户信息到数据库
            $this->saveUserInfo($user_id, $user_nickname, $text, null, $message_link);
            // 保存群组信息到数据库
            $this->saveGroupInfo($chat_id,  $accountName, $instance);
        }
    }

    /**
     * 处理普通用户删除的消息，记录删除信息
     * @param array $update 更新数据
     */
    private function handleDeleteMessages($update)
    {
        // 获取被删除的消息ID数组
        $messageIds = $update['messages'];
        // 获取聊天ID
        $chatId = isset($update['peer_id']) ? $update['peer_id'] : null;
        // 记录被删除的消息信息
        Log::info("Messages deleted: " . implode(', ', $messageIds) . " in chat_id: $chatId");
    }

    /**
     * 处理频道删除的消息，记录删除信息
     * @param array $update 更新数据
     */
    private function handleDeleteChannelMessages($update)
    {
        // 获取被删除的消息ID数组
        $messageIds = $update['messages'];
        // 获取频道ID
        $chatId = isset($update['channel_id']) ? $update['channel_id'] : null;
        // 记录被删除的频道消息信息
        Log::info("Channel messages deleted: " . implode(', ', $messageIds) . " in channel_id: $chatId");
    }

    /**
     * 处理用户状态更新，记录用户状态变化信息
     * @param array $update 更新数据
     */
    private function handleUserStatusUpdate($update)
    {
        // 获取用户ID
        $userId = $update['user_id'];
        // 获取用户状态
        $status = $update['status']['_'];
        // 记录用户状态更新信息
        Log::info("User $userId status updated to: $status");
    }

    /**
     * 处理普通聊天成员更新，记录成员更新信息并存储新加入成员信息
     * @param array $update 更新数据
     */
    private function handleChatParticipantsUpdate($update, $accountName, $instance)
    {
        // 获取聊天ID
        
        // 获取成员信息数组
        $participants = $update['participants'];
        // 记录聊天成员更新信息
        Log::info("Chat  participants updated: " . json_encode($participants));
        $chatId = isset($participants['chat_id']) ? $participants['chat_id'] : null;
        $participantlist=$participants['participants'];
        foreach ($participantlist as $participant) {
            // 获取用户ID
            $user_id = $participant['user_id'];
            // 获取用户昵称
           
            $userInfo = $this->getUserInfo($user_id, $accountName, $instance);
            // 存储新加入成员信息到数据库
            $this->saveUserInfo($user_id, $userInfo['username'], $userInfo['nickname'],  null, 'New member joined', null, $accountName);
        }
        if ($chatId) {
            // 保存群组信息到数据库
           // $this->saveGroupInfo($chatId, null, $this->getGroupTypeFromParticipants($update), $accountName, $instance);
        }
    }
   private function getGroupTypeFromParticipants(){
       return ;
   }   
    /**
     * 处理频道成员更新，逻辑与处理普通聊天成员更新类似
     * @param array $update 更新数据
     */
    private function handleChannelParticipantsUpdate($update)
    {
        // 获取频道ID
        $chatId = isset($update['channel_id']) ? $update['channel_id'] : null;
        // 获取成员信息数组
        $participants = $update['participants'];
        // 记录频道成员更新信息
        Log::info("Channel $chatId participants updated: " . json_encode($participants));

        foreach ($participants as $participant) {
            // 获取用户ID
            $user_id = $participant['user_id'];
            // 获取用户昵称
            $user_nickname = $this->getUserName($user_id);
            // 存储新加入成员信息到数据库
            $this->saveUserInfo($user_id, $user_nickname, null, 'New member joined', null);
        }
        if ($chatId) {
            // 保存群组信息到数据库
            $this->saveGroupInfo($chatId, $accountName, $instance);
        }
    }

       /**
     * 发送消息到指定聊天ID
     * @param string $accountName 账号名称
     * @param int $chat_id 聊天ID
     * @param string $text 消息文本
     */
    public function sendMessage($accountName, $chat_id, $text)
    {
        try {
            // 使用 MadelineProto 实例调用 sendMessage 方法发送消息
            $this->madelineInstances[$accountName]->messages->sendMessage([
                'peer' => $chat_id,
                'message' => $text
            ]);
        } catch (Exception $e) {
            // 若发送消息时捕获到异常，判断是否为 Flood 限制异常
            if (strpos($e->getMessage(), 'FLOOD_WAIT')!== false) {
                // 若为 Flood 限制异常，通过正则表达式提取需要等待的时间
                preg_match('/FLOOD_WAIT_(\d+)/', $e->getMessage(), $matches);
                if (isset($matches[1])) {
                    $waitTime = (int)$matches[1];
                    // 记录等待信息到日志
                    Log::info("Flood control triggered. Waiting for $waitTime seconds...");
                    // 程序暂停指定的等待时间
                    sleep($waitTime);
                    // 等待结束后，递归调用 sendMessage 方法重试发送消息
                    $this->sendMessage($accountName, $chat_id, $text);
                }
            } else {
                // 若不是 Flood 限制异常，记录具体的错误信息到日志
                Log::info("Error sending message: " . $e->getMessage());
            }
        }
    }
 

/**
 * 获取用户信息
 * 此处改用 getInfo 方法获取用户信息，并根据返回数据中的 "User" 或 "user" 字段提取数据
 *
 * @param int $userId 用户ID
 * @param string $accountName 当前账号名称
 * @param API $instance 当前的 MadelineProto 实例
 * @return array|null 用户信息
 */
private function getUserInfo($userId, $accountName, $instance)
{
    try {
        $userInfo = $instance->getInfo($userId);
        //Log::info("Fetched user info: " . json_encode($userInfo));
        
        // 检查返回的数组中是否存在 "User" 或 "user" 字段
        if (isset($userInfo['User'])) {
            $user = $userInfo['User'];
        } elseif (isset($userInfo['user'])) {
            $user = $userInfo['user'];
        } else {
            Log::error("No 'User' or 'user' field found in the response for user ID: $userId");
            return null;
        }
        // 合并 first_name 和 last_name 为 nickname，去除多余空格
        $firstName = $user['first_name'] ?? '';
        $lastName  = $user['last_name'] ?? '';
        $nickname  = trim($firstName . ' ' . $lastName);
        if (empty($nickname)) {
            $nickname = 'Unknown';
        }
        return [
            'user_id'  => $user['id'] ?? 'unknown',
            'username' => $user['username'] ?? (!empty($firstName) ? $firstName : 'Unknown'),
            'nickname' => $nickname
        ];
    } catch (Exception $e) {
        Log::error('Error fetching user info: ' . $e->getMessage());
        return null;
    }
}

/**
 * 获取群组的详细信息
 * @param int $peer_id 群组的 peer_id
 * @param string $accountName 当前账号的名称（例如手机号）
 * @param API $instance 当前 MadelineProto 实例
 * @return array|null 群组信息
 */
private function getGroupInfo($peer_id, $accountName, $instance)
{
    try {
        // 使用 getFullChat 方法获取群组的详细信息
        $groupInfo = $instance->getInfo($peer_id);
       // Log::info("Fetched group info: " . json_encode($groupInfo));
         if (isset($groupInfo['Chat'])) {
            $chat = $groupInfo['Chat'];
        } elseif (isset($groupInfo['chat'])) {
            $chat = $groupInfo['chat'];
        } else {
            Log::error("No 'User' or 'user' field found in the response for user ID: $peer_id");
            return null;
        }
        // 返回群组的基本信息，例如名称、类型等
        return [
            'group_id'   => $peer_id,
            'group_name' => $chat['title'] ?? 'Unknown Group',
            'group_username'=> $chat['username'] ?? 'Unknown Username',
            'group_type' => $groupInfo['type'] ?? 'Unknown Type',
            'group_description' => isset($chat['description']) ? $chat['description'] : 'No description available' // 检查 description 是否存在
        ];
    } catch (Exception $e) {
        Log::error('Error fetching group info: ' . $e->getMessage());
        return null;
    }
}


   
    /**
     * 从peer_id结构中提取聊天ID（适用于频道、群组或用户）
     * @param mixed $peer
     * @return int|string|null
     */
    private function extractChatIdFromPeer($peer)
    {
        if (is_array($peer)) {
            if (isset($peer['channel_id'])) {
                return $peer['channel_id'];
            }
            if (isset($peer['chat_id'])) {
                return $peer['chat_id'];
            }
            if (isset($peer['user_id'])) {
                return $peer['user_id'];
            }
        }
        return $peer;
    }
    /**
     * 保存用户信息到数据库
     * @param int $user_id 用户 ID
     * @param string $user_nickname 用户昵称
     * @param string $message_text 消息文本
     * @param string $new_member_info 新成员信息
     * @param string $message_link 消息链接
     */

private function saveUserInfo($user_id, $username, $user_nickname, $message_text, $new_member_info, $message_link, $accountName)
{
    Log::info("第一次 $user_id $message_text");

    // 生成 Redis 缓存键
    $redisKey = "user_recorded_{$accountName}_{$user_id}";

    // 从 Redis 缓存中获取用户记录信息
    $userRecordCache = Cache::store('redis')->get($redisKey);

    // 提前查询数据库，获取用户记录
    $existingUser = null;
    if (!$userRecordCache) {
        $existingUser = Db::name('mpuser')
            ->where('user_id', $user_id)
            ->where('phone', $accountName)
            ->find();
    }

    if (!$userRecordCache) {
        if (!$existingUser) {
            $adminId = $this->getMpadmininfo($accountName);
            $isPushed = false;
            // 使用 ThinkPHP 的数据库操作类将用户信息插入到 mpuser 表中
            Db::name('mpuser')->insert([
                'user_id' => $user_id,
                'username' => $username,
                'user_nickname' => $user_nickname,
                'message_text' => $message_text,
                'new_member_info' => $new_member_info,
                'message_link' => $message_link,
                'admin_id' => $adminId,
                'phone' => $accountName,
                'create_time' => time(),
                'is_pushed' => $isPushed
                
            ]);
            // 设置 Redis 缓存，表示用户已经被记录且是否有 message_text
            $this->setUserRecordCache($redisKey, true, !empty($message_text), $isPushed);
        } else {
            $isPushed = $existingUser['is_pushed'] ?? false;
            if (empty($existingUser['message_text']) &&!empty($message_text)) {
                $this->updateUserMessageText($user_id, $accountName, $message_text, $message_link);
                $this->setUserRecordCache($redisKey, true, true, $isPushed);
            } else {
                $this->setUserRecordCache($redisKey, true, !empty($existingUser['message_text']), $isPushed);
            }
        }
    } else {
         $isPushed = $userRecordCache['is_pushed'] ?? false;
        if (!$userRecordCache['has_message_text'] &&!empty($message_text)) {
            $this->updateUserMessageText($user_id, $accountName, $message_text, $message_link);
            $this->setUserRecordCache($redisKey, true, true, $isPushed);
        }
    }
}
//更新用户缓存
private function setUserRecordCache($redisKey, $isRecorded, $hasMessageText, $isPushed)
{
    Cache::store('redis')->set($redisKey, [
        'is_recorded' => $isRecorded,
        'has_message_text' => $hasMessageText,
        'is_pushed' => $isPushed
    ]);
}
//更新用户发言
private function updateUserMessageText($user_id, $accountName, $message_text, $message_link, $isPushed)
{
    Db::name('mpuser')
        ->where('user_id', $user_id)
        ->where('phone', $accountName)
        ->update([
            'message_link' => $message_link,
           'message_text' => $message_text,
           'is_pushed' => $isPushed
        ]);
}
    /**
     * 保存群组信息到数据库
     * @param int $group_id 群组 ID
     * @param string|null $group_name 群组名称
     * @param string $group_type 群组类型
     */
    private function saveGroupInfo($group_id, $accountName, $instance)
    {
        // 生成缓存键
         $cacheKey = "mpgroup_{$accountName}_{$group_id}";
        // 先从缓存中获取群组信息
         $groupInfo = Cache::store('redis')->get($cacheKey); 
        if (!$groupInfo) {
             // 获取群组信息
            $groupInfo = $this->getGroupInfo($group_id, $accountName, $instance);
            $group_name=$groupInfo['group_name'];
            $group_type=$groupInfo['group_type'];
            $group_username=$groupInfo['group_username'];
            $group_description=$groupInfo['group_description'];
            // 先查询数据库中是否已经存在该群组信息
            $existingGroup = Db::name('mpgroup')->where('group_id', $group_id)->find();
            if ($existingGroup) {
                // 若存在，则更新群组名称和类型
               /* Db::name('mpgroup')->where('group_id', $group_id)->update([
                    'group_name' => $group_name,
                    'group_username' => $group_username,
                    'group_type' => $group_type
                ]);*/
            } else {
               $adminId=$this->getMpadmininfo($accountName);
                // 若不存在，则插入新的群组信息
                Db::name('mpgroup')->insert([
                    'group_id' => $group_id,
                    'group_name' => $group_name,
                    'group_username' => $group_username,
                    'group_type' => $group_type,
                    'description' => $group_description,
                    'admin_id' => $adminId,
                    'phone' => $accountName,
                ]);
                 // 插入新记录后更新缓存
                $newGroupInfo = array_merge($groupInfo, [
                    'group_id' => $group_id,
                    'group_name' => $group_name,
                    'group_username' => $group_username,
                    'group_type' => $group_type,
                    'description' => $group_description,
                    'admin_id' => $adminId,
                    'phone' => $accountName,
                ]);
                   // 将查询结果存入缓存，设置缓存有效期，例如 3600 秒（1 小时）
                Cache::store('redis')->set($cacheKey, $groupInfo, 3600);
                log::info("添加群组");
            }
         
        }

       
    }

    /**
     * 获取消息链接
     * @param int $chat_id 聊天 ID
     * @param int $message_id 消息 ID
     * @return string 消息链接
     */
    private function getMessageLink($chat_id, $message_id, $accountName, $instance,$type)
    {
        if($type==2){
        $groupInfo = $this->getGroupInfo($chat_id, $accountName, $instance);
        $group_name=$groupInfo['group_name'];
        $group_type=$groupInfo['group_type'];
        $group_username=$groupInfo['group_username'];
        if ($group_username !== 'Unknown Username') {
            // 群组有用户名，使用用户名构建链接
            return "https://t.me/{$group_username}/{$message_id}";
        } else {
            // 群组没有用户名，使用群组 ID 构建链接
            return "https://t.me/c/" . $chat_id . "/{$message_id}";
        }
            
        }else{
            $group_username=$chat_id;
             // 根据聊天 ID 和消息 ID 生成 Telegram 消息链接
            return "https://t.me/$group_username/$message_id";
        }
       
    }
    
    // 检查发言是否包含关键词
    private function containsKeywords($text, $keywords)
    {
        // 简单分词，可根据实际情况优化
        $keywords = explode('\n', strtolower($keywords));
        // 将文本转换为小写，便于不区分大小写匹配
        $text = strtolower($text);
        foreach ($keywords as $keyword) {
            log::info('关键词'.$words);
            $words = trim(strtolower($keyword));
             if (strpos($text, $words) !== false) {
                 log::info('有关键词'.$words);
                return true;
            }
        }
        return false;
    }
    
 
    
    // 判断字符串是否为中文
    private function isChinese($str)
    {
        return preg_match('/^[\x{4e00}-\x{9fa5}]+$/u', $str) === 1;
    }
    // 判断群组是否为英文群组的方法，需要根据实际情况实现
    private function isEnglishGroup($chat_id, $accountName, $instance)
    {
        // 这里可以根据群组的描述、名称等信息来判断是否为英文群组
        $groupInfo = $this->getGroupInfo($chat_id, $accountName, $instance);
        if ($groupInfo) {
            // 简单示例：假设群组名称全为英文字母则认为是英文群组
            return preg_match('/^[a-zA-Z\s]+$/', $groupInfo['group_name']) === 1;
        }
        return false;
    }

   // 检查消息是否符合过滤规则
    private function isMessageValid($user_id, $text, $filterType, $filterValue)
    {
        if ($filterType === 'user_id') {
            $filterUserIds = explode(',', $filterValue);
            return in_array($user_id, $filterUserIds);
        } elseif ($filterType === 'keyword') {
            $filterKeywords = explode(',', $filterValue);
            foreach ($filterKeywords as $keyword) {
                if (stripos($text, $keyword)!== false) {
                    return true;
                }
            }
        }
        return false;
    }


   /**
     * 推送消息到指定目标群组
     * @param int|string $targetGroupId 目标群组的ID（或 peer_id）
     * @param string $text 消息文本
     * @param string $message_link 消息链接（例如跳转链接）
     */
    private function pushMessage($targetGroupId, $text, $message_link, $accountName)
    {
        // 组合需要推送的消息内容，可以根据需求调整格式
        // 例如：取消息文本的第一行，并附上消息链接
        //$firstLine = trim(explode("\n", $text)[0]);
        $pushText = "{$text}\n查看详情: {$message_link}";
        log::info($pushText);
        $this->sendMessage($accountName, $targetGroupId, $pushText);
    
   
  
    }
   
    /**
     * 检查消息是否包含关键词，并推送到目标群组
     * @param string $text 消息文本
     * @param string $message_link 消息链接
     * @param string $accountName 当前账号的名称（例如手机号）
     */
    private function checkmpkeyword($text, $message_link, $accountName)
    {
        // 生成 $mp_id 的缓存键
        $mpIdCacheKey = "mp_id_{$accountName}";
    
        // 尝试从缓存中获取 $mp_id
        $mp_id = Cache::store('redis')->get($mpIdCacheKey);
    
        if ($mp_id === null) {
            // 如果缓存中不存在，则从数据库中查询 $mp_id
            $mp_id = Db::name('monitorphone')->where('phone', $accountName)->value('id');
    
            // 将 $mp_id 存入缓存，设置缓存时间（例如 3600 秒，即 1 小时）
            if ($mp_id!== null) {
                Cache::store('redis')->set($mpIdCacheKey, $mp_id, 3600);
            }
        }
    
        if ($mp_id === null) {
            // 如果仍然没有获取到 $mp_id，可能数据库中不存在对应记录，直接返回
            return;
        }
    
        // 生成关键词的缓存键
        $keywordsCacheKey = "mp_keywords_{$mp_id}";
    
        // 尝试从缓存中获取关键词
        $keywords = Cache::store('redis')->get($keywordsCacheKey);
    
        if ($keywords === null) {
            // 如果缓存中不存在，则从数据库中获取所有关键词和目标群组ID
            $keywords = Db::name('mpkeyword')->where('mp_id', $mp_id)->where('status', 1)->select();
    
            // 将关键词存入缓存，设置缓存时间（例如 3600 秒，即 1 小时）
            Cache::store('redis')->set($keywordsCacheKey, $keywords, 3600);
        }
    
        // 遍历关键词列表，检查消息是否包含任意一个关键词
        foreach ($keywords as $keyword) {
            // 获取关键词和目标群组ID
            $keywordText = $keyword['title'];
            $pushChatId = $keyword['push_chatid'];
            // 使用 stripos 检查消息中是否包含关键词（不区分大小写）
            if (stripos($text, $keywordText) !== false) {
                // 如果匹配到关键词，推送消息到目标群组
                $this->pushMessage($pushChatId, $text, $message_link, $accountName);
    
                // 记录日志
                Log::info("Message containing keyword '{$keywordText}' pushed to group: {$pushChatId}");
            }
        }
    }

    
    ///信息所属
     private function getMpadmininfo($accountName){
         $admin_id = Db::name('monitorphone')->where('phone', $accountName)->value('admin_id');
         return $admin_id;
     }
  //在获取群组和用户信息   
public function recordGroupUserIds($accountName,$instance)
{
    log::info("我来了".$accountName);
    
    try {
       // $instance = $this->getMadelineInstance($accountName);
        // 获取所有群组（公开和私密）
        //$chats = $instance->getAllChats();
        log::info("我来de");
        /*foreach ($chats as $chat) {
            
            if ($chat['_'] === 'chat' || $chat['_'] === 'channel') {
                // 记录群组信息
               // $this->saveGroupInfo($chat, $accountName);

                // 分页获取群组成员
                $offset = 0;
                $limit = 100; // 每次获取 100 个成员
                while (true) {
                    $participants = $instance->getParticipants($chat, [
                        'offset' => $offset,
                        'limit' => $limit
                    ]);

                    if (empty($participants)) {
                        break;
                    }

                    foreach ($participants as $participant) {
                        $userId = $participant['user']['id'];
                        $userInfo = $this->getUserInfo($userId, $accountName, $instance);
                        // 存储用户信息，第一次发言信息初始为空
                        $this->saveUserInfo($userId, $userInfo['username'], $userInfo['nickname'], '', null, null, $accountName);
                    }

                    $offset += $limit;
                }
            }
        }*/

        return true;
    } catch (Exception $e) {
        Log::error("Error recording group user IDs for account '$accountName': " . $e->getMessage());
        return false;
    }
}
}