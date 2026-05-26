<?php
namespace app\kefu\controller;
use think\facade\Db;
use think\facade\Log;
use think\facade\Cache;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\Utils;

use GuzzleHttp\Exception\RequestException;
use app\admin\model\Mtuser as MtuserModel;

use think\facade\Queue;
use app\job\TelegramSendMessage;

class Teletdata extends Baseinfo {
    
	private $httpClient;
	private string $redisPrefix = 'telegram_task:user:';
    public function initialize(){
		parent::initialize();
		$this->httpClient = new Client([
            'timeout'         => 60.0,
            'connect_timeout' => 10.0,
            'read_timeout'    => 30.0,
            'pool'           => 5, // 连接池大小
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'User-Agent'   => 'TelegramApiClient/1.0'
            ]
        ]);
	}
	/**
     * 创建私聊
     * @return \think\response\Json
     */
    public function createPrivateChat()
    {
        try {
            // 获取请求参数
            $chat_id = $this->request->post('user_id', 0);
            $tdid = $this->request->post('tdid', 0);
            $senderName = $this->request->post('senderName');
            $senderUsername = $this->request->post('senderUsername');
            // 参数验证|| empty($senderUsername)
            if (empty($chat_id) || empty($tdid)) {
                return json(['code' => 400, 'msg' => '参数不完整']);
            }
            
            // 获取客服账号信息
            $mtUsers = Db::name('mtuser')->where('id', $tdid)->find();
            
            if (!$mtUsers) {
                return json(['code' => 400, 'msg' => '账号不存在或无权访问']);
            }
            
            // 检查账号状态
            if ($mtUsers['account_status'] !== '正常') {
                return json(['code' => 400, 'msg' => '账号状态异常: ' . $mtUsers['account_status']]);
            }
         
            $account_id="temp_".$mtUsers['uuid'].".session";
            // 保存私聊信息到数据库
            $chatData = [
                'chat_id' => $chat_id,
                'account_id' => $account_id,
                'chat_type' => 'private',
                'title' => $senderName ?? '私聊用户',
                'username' => $senderUsername,
                'avatar_path' =>  '',
                'last_message_id' => 0,
                'last_message_time' => date('Y-m-d H:i:s'),
                'unread_count' => 0,
                'participants_count' => 0,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            // 检查是否已存在该聊天
            $existingChat = Db::name('tdchats')->where('chat_id', $chat_id)->where('account_id', $account_id)->find();
            
            if ($existingChat) {
                // 更新现有聊天
                Db::name('tdchats')
                    ->where('id', $existingChat['id'])
                    ->update([
                        'updated_at' => date('Y-m-d H:i:s'),
                        'last_message_time' => date('Y-m-d H:i:s')
                    ]);
                $chatData['id'] = $existingChat['id'];
            } else {
                // 插入新聊天
                $chatsId = Db::name('tdchats')->insertGetId($chatData);
                $chatData['id'] = $chatsId;
            }
            
            // 格式化返回数据
            $formattedChat = $this->formatChatData($chatData);
            
            return json([
                'code' => 200,
                'msg' => '创建私聊成功',
                'data' => $formattedChat
            ]);
            
        } catch (\Exception $e) {
            Log::error('创建私聊失败: ' . $e->getMessage());
            return json([
                'code' => 500,
                'msg' => '创建私聊失败: ' . $e->getMessage()
            ]);
        }
    }
    private function formatChatData($chatData)
    {
        return [
            'id' => $chatData['id'] ?? 0,
            'chat_id' => $chatData['chat_id'] ?? 0,
            'account_id'=>$chatData['account_id'] ??'',
            'chat_type' => $chatData['chat_type'] ?? 'private',
            'title' => $chatData['title'] ?? '私聊用户',
            'username' => $chatData['username'] ?? '',
            'avatar_path' => $chatData['avatar_path'] ?? '',
            'icon' => $chatData['chat_type'] === 'private' ? 'el-icon-user' : 'el-icon-chat-dot-round',
            'color' => $chatData['chat_type'] === 'private' ? '#409EFF' : '#67C23A',
            'unread_count' => $chatData['unread_count'] ?? 0,
            'last_message_text' => $chatData['last_message_text'] ?? '',
            'last_message_time' => $chatData['last_message_time'] ?? '',
            'participants_count' => $chatData['participants_count'] ?? 2,
            'is_blocked' => $chatData['is_blocked'] ?? 0,
            'created_at' => $chatData['created_at'] ?? '',
            'updated_at' => $chatData['updated_at'] ?? ''
        ];
    }
	// 发送消息
	/*
    	chat_id:-1003467124235
        account_id:819073740079
        message:123
        reply_to_msg_id:2282
    */
    public function sendMessage()
    {
        $params = $this->request->post();
        $required = ['account_id', 'chat_id', 'message_type'];
        
        foreach($required as $field) {
            if(empty($params[$field])) {
                return json(['code' => 400, 'msg' => "缺少参数: {$field}"]);
            }
        }
        
        $images = '';
        if (in_array($params['message_type'], ['image', 'image_text'])) {
            // 校验前端是否传递了images参数（兼容JSON字符串/数组格式）
            $imagesRaw = $params['images'] ?? '';
            if (empty($imagesRaw)) {
                return json(['code' => 400, 'msg' => $params['message_type'] . '类型消息需传递图片列表']);
            }
            
            
            // 过滤无效图片（确保每张图都有完整URL）
            $validImages = [];
            $baseCdnUrl = rtrim(config('telegram.cdn_domain'), '/'); // 从配置获取CDN域名（如https://cdn.xxx.com）

            if (!str_starts_with($imagesRaw, 'http://') && !str_starts_with($imagesRaw, 'https://')) {
                $images = $baseCdnUrl . '/' . ltrim($imagesRaw, '/'); // 避免拼接出//的情况
            }
            
            $validImages[] = $images;
           
            // 校验有效图片数量（至少1张）
            if (empty($validImages)) {
                return json(['code' => 400, 'msg' => '无有效图片URL，请检查图片参数']);
            }
        }
        
        
        $kefuinfo = session('kefu');
        $kefuid =  $kefuinfo['id'];
        $first_msg_id = 0;
        $feedback_type='';
        if (isset($params['reply_to_msg_id']) && 
            $params['reply_to_msg_id'] !== '' && 
            $params['reply_to_msg_id'] !== null &&
            is_numeric($params['reply_to_msg_id'])) {
            $first_msg_id = (int)$params['reply_to_msg_id'];
            $feedback_type='forward';
        }
    
        $mtUsers = Db::name('mtuser')->where('account',$params['account_id'])->field('id,uuid, tdata_path,session_path, account,proxyip, nickName,last_api_address')->find();
        if (!$mtUsers) {
            return json(['code' => 400, 'msg' => '账号不存在']);
        }
          
        try {
            $scriptParams = [
                'action' => 'send_messages',
                'session_path' => $mtUsers['session_path'],
                'group_id' => $params['chat_id'],
                'message_type' => $params['message_type'],
                'message_text' => $params['message'],
                'proxyip'=>$mtUsers['proxyip'],
                'first_msg_id' =>$first_msg_id,
                'feedback_type'=>$feedback_type,
                'last_api_address'=>$mtUsers['last_api_address'],
            ];
            if (!empty($validImages)) {
                $scriptParams['media_paths'] = implode(',', $validImages); // 图片URL列表（Python端按逗号分割）
            }

            $result = $this->execPythonScript($scriptParams);
            if ($result['status']) {
                // 关键修复：正确提取 message_id
                $telegramMessageId = null;
                
                // 从返回的数据结构中提取 message_id
                if (isset($result['data']['success']) && is_array($result['data']['success'])) {
                    foreach ($result['data']['success'] as $successItem) {
                        if (isset($successItem['message_id'])) {
                            $telegramMessageId = $successItem['message_id'];
                            break;
                        }
                    }
                }
                if(!$telegramMessageId){
                    return json(['code' => 500, 'msg' => '发送失败无法获取到用户信息']);
                }
                $account_id="temp_".$mtUsers['uuid'].".session";
                // 保存到数据库
                $messageData = [
                    'account_id' => $account_id,
                    'chat_id' => $params['chat_id'],
                    'message_id' => $telegramMessageId,
                    'sender_id' => $mtUsers['uuid'], // 标记为客服发送
                    'sender_name' => $mtUsers['nickName'],
                    'message_type' => $params['message_type'],
                    'message_text' => $params['message'],
                    'timestamp' => date('Y-m-d H:i:s'),
                    'is_outgoing' => 1, // 1表示发送的消息
                    'is_read' => 0,
                    'media_path'=>$params['images']??'',
                    'reply_to_msg_id' => $params['reply_to_msg_id']??0,
                    
                ];
                
                Db::name('tdmessages')->insert($messageData);
                
                // 更新聊天最后消息时间
                Db::name('tdchats')
                    ->where('chat_id', $params['chat_id'])
                    ->where('account_id', $account_id)
                    ->update([
                        'last_message_time' => date('Y-m-d H:i:s'),
                        'last_message_text' => $message,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                
                return json(['code' => 200, 'msg' => '发送成功', 'data' => $messageData]);
            } else {
                return json(['code' => 500, 'msg' => '发送失败: ' . $result['error']]);
            }
    
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => $e->getMessage()]);
        }
    }
    /**
     * 加载历史消息
     * @return \think\response\Json
     */
    public function loadHistory()
    {
        // 获取请求参数
        $tdataId = $this->request->post('tdid'); // 关联的tdata标识
        $targetId = $this->request->post('target_id'); // 目标会话ID（群组/好友）
        $limit = $this->request->post('limit', 10); // 每页消息数，默认50
        $offset = $this->request->post('offset', 0); // 偏移量，用于分页
    
        // 参数验证
        if (empty($tdataId) || empty($targetId)) {
            return json(['code' => 400, 'msg' => '缺少参数：tdata_path 或 target_id']);
        }
    
        try {
            // 获取账号的tdata路径和代理信息
            $mtUser =Cache::store('redis')->get($this->redisPrefix . $tdataId);
            if (!$mtUser) {
                $mtUser = Db::name('mtuser')
                ->where('id', $tdataId)
                ->field('id,tdata_path,session_path, proxyip,uuid,last_api_address')
                ->find();
                if (!$mtUser) {
                    return json(['code' => 404, 'msg' => '未找到关联的账号信息']);
                }
                Cache::store('redis')->set($this->redisPrefix . $tdataId, $mtUser, 60);
            }
    
            // 调用Python脚本获取历史消息
            $historyResult = $this->execPythonScript([
                'action' => 'get_history', // 对应Python脚本中的动作
                'session_path' => $mtUser['session_path'],
                'proxyip' => $mtUser['proxyip'],
                'target_id' => $targetId,
                'limit' => $limit,
                'offset' => $offset,
                'apiaddress'=>$mtUser['last_api_address'],
            ]);
    
            if (!$historyResult['status']) {
                return json(['code' => 400, 'msg' => $historyResult['message']]);
            }
    
            // 整理消息数据（补充发送者信息）
            $messages = $historyResult['data']['messages'] ?? [];
            $formattedMessages = [];
    
            foreach ($messages as $msg) {
                // 区分发送者是否为当前账号（需提前获取当前账号user_id）
                $currentUserId = $mtUser['uuid']; // 自定义方法获取当前账号ID
                $isOutgoing = $msg['sender_id'] == $currentUserId;
    
                // 格式化消息时间
                $msgTime = date('Y-m-d H:i:s', strtotime($msg['date']));
    
                $formattedMessages[] = [
                    'id' => $msg['id'], // 消息ID
                    'text' => $msg['text'] ?? '[无文本内容]', // 消息内容
                    'date' => $msgTime, // 发送时间
                    'sender_id' => $msg['sender_id'], // 发送者ID
                    'sender_name'=>$msg['sender_name'],
                    'is_outgoing' => $isOutgoing, // 是否为当前账号发送
                    'media_type' => $msg['media_type'] ?? '', // 媒体类型（如image/voice）
                    'media_url' => $msg['media_url'] ?? '', // 媒体URL（如有）
                    "is_reply"=> $msg['is_reply'] ?? '',  # 新增：是否为回复消息
                    "reply_to_msg_id"=> $msg['reply_to_msg_id'] ?? '',  # 新增：被回复的消息ID
                    "reply_to_text"=> $msg['reply_to_text'] ?? '',  # 新增：被回复的消息内容
                    'reply_to_sender_id'=>$msg['reply_to_sender_id']?? ''
                ];
            }
    
            return json([
                'code' => 200,
                'msg' => '加载成功',
                'data' => [
                    'messages' => $formattedMessages,
                    'has_more' => count($messages) >= $limit, // 是否还有更多消息
                    'total' => $historyResult['data']['total'] ?? count($formattedMessages) // 总消息数
                ]
            ]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '加载历史消息失败：' . $e->getMessage()]);
        }
    }
    /*共同群组*/
    public function getCommonGroups()
    {
        try {
            $tdataId = $this->request->post('tdid');
            $targetId = $this->request->post('chat_id');
            
            if (empty($tdataId) || empty($targetId)) {
                return json(['code' => 400, 'msg' => '缺少参数']);
            }
            
            // 1. 获取当前账号信息
            //$mtUser =Cache::store('redis')->get($this->redisPrefix . $tdataId);
            //if (!$mtUser) {
                $mtUser = Db::name('mtuser')
                ->where('id', $tdataId)
                ->field('id, tdata_path,session_path,proxyip,last_api_address')
                ->find();
                if (!$mtUser) {
                    return json(['code' => 404, 'msg' => '账号不存在']);
                }
              //  Cache::store('redis')->set($this->redisPrefix . $tdataId, $mtUser, 60);
            //}
            
            // 2. 调用Python脚本获取共同群组
            $result = $this->execPythonScript([
                'action' => 'get_common_groups',
                'session_path' => $mtUser['session_path'],
                'target_id' => $targetId,
                'proxyip' => $mtUser['proxyip'] ?? '',
                'last_api_address'=>$mtUser['last_api_address'],
            ]);
            
            if ($result['status'] ?? false) {
                return json([
                    'code' => 200,
                    'data' => ['groups' => $result['data']['groups'] ?? []]
                ]);
            } else {
                return json([
                    'code' => 500,
                    'msg' => $result['message'] ?? '获取共同群组失败'
                ]);
            }
        } catch (\Exception $e) {
            Log::error('获取共同群组异常: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => '服务器错误']);
        }
    }
    
    
    /**
     * 拉黑用户
     * @return \think\response\Json
     */
    public function blockUser()
    {
        // 获取请求参数
        $tdataId = $this->request->post('tdid'); // 关联的tdata标识
        $targetId = $this->request->post('chat_id'); // 目标会话ID（群组/好友）
    
        // 参数验证
        if (empty($tdataId) || empty($targetId)) {
            return json(['code' => 400, 'msg' => '缺少参数：tdata_path 或 target_id']);
        }
    
        try {
           
            $mtUser = Db::name('mtuser')->where('id', $tdataId)->field('id,tdata_path,session_path, proxyip,uuid,last_api_address')->find();
            if (!$mtUser) {
                return json(['code' => 404, 'msg' => '未找到关联的账号信息']);
            }
            $scriptParams = [
                'action' => 'block_user',
                'session_path' => $mtUser['session_path'],
                'proxyip' => $mtUser['proxyip'],
                'target_id' => $targetId,
                'last_api_address'=>$mtUser['last_api_address'],
            ];
            // 创建任务数据
            $jobData = [
                'params' => $scriptParams,
            ];
            
            // 推送到队列
            $jobId = Queue::push(TelegramSendMessage::class, $jobData, 'telegram_send');
            
            $account_id = "temp_" . $mtUser['uuid'] . ".session"; // 注意：修复了变量名 mtUsers 应该是 mtUser
            
            // 删除数据库中对应的聊天记录
            $deleteResult = Db::name('tdmessages')->where('chat_id', $targetId)->where('account_id', $account_id)->delete();
            $deleteResult = Db::name('tdchats')->where('chat_id', $targetId)->where('account_id', $account_id)->delete();
    
            return json([
                'code' => 200,
                'msg' => '操作成功',
            ]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '加载历史消息失败：' . $e->getMessage()]);
        }
    }
    public function deleteUser()
    {
        // 获取请求参数
        $tdataId = $this->request->post('tdid'); // 关联的tdata标识
        $chat_id = $this->request->post('chat_id'); // 目标会话ID（群组/好友）
    
        // 参数验证
        if (empty($tdataId) || empty($chat_id)) {
            return json(['code' => 400, 'msg' => '缺少参数：tdata_path 或 target_id']);
        }
    
        try {
            $mtUser = Db::name('mtuser')
            ->where('id', $tdataId)
            ->field('id,tdata_path,session_path, proxyip,uuid,last_api_address')
            ->find();
            if (!$mtUser) {
                return json(['code' => 404, 'msg' => '未找到关联的账号信息']);
            }
             
            $scriptParams = [
                'action' => 'deleteUser',
                'session_path' => $mtUser['session_path'],
                'proxyip' => $mtUser['proxyip'],
                'target_id' => $chat_id,
                'last_api_address'=>$mtUser['last_api_address'],
            ];
            // 创建任务数据
            $jobData = [
                'params' => $scriptParams,
            ];
            
            // 推送到队列
            $jobId = Queue::push(TelegramSendMessage::class, $jobData, 'telegram_send');
            
            // 调用Python脚本获取历史消息
            /*$historyResult = $this->execPythonScript([
                'action' => 'deleteUser', // 对应Python脚本中的动作
                'session_path' => $mtUser['session_path'],  
                'proxyip' => $mtUser['proxyip'],
                'target_id' => $chat_id,
                'last_api_address'=>$mtUser['last_api_address'],
            ]);
    
            if (!$historyResult['status']) {
                return json(['code' => 400, 'msg' => $historyResult['message']]);
            }
    
            // 整理消息数据（补充发送者信息）
            $messages = $historyResult['data']['messages'] ?? [];*/
            
            $account_id = "temp_" . $mtUser['uuid'] . ".session"; // 注意：修复了变量名 mtUsers 应该是 mtUser
            
            // 删除数据库中对应的聊天记录
            $deleteResult = Db::name('tdmessages')->where('chat_id', $chat_id)->where('account_id', $account_id)->delete();
            $deleteResult = Db::name('tdchats')->where('chat_id', $chat_id)->where('account_id', $account_id)->delete();
            
            return json([
                'code' => 200,
                'msg' => '加载成功',
                /*'data' => [
                    'messages' => $messages,
                    'data' => $historyResult['data']['data'],
                   
                ]*/
            ]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '加载历史消息失败：' . $e->getMessage()]);
        }
    }
    /**
     * 删除聊天记录
     * @return \think\response\Json
     * message_id：3339
       account_id：819073740079
      chat_id：-1003467124235
     */
    public function deleteHistory()
    {
        // 获取请求参数
        $tdid = $this->request->post('tdid');
        $chat_id = $this->request->post('chat_id'); // 关联的tdata标识
        
        // 参数验证
        if (empty($chat_id) || empty($tdid)) {
            return json(['code' => 400, 'msg' => '缺少参数：tdata_path 或 target_id']);
        }
        
        try {
            // 获取账号的tdata路径和代理信息
            $mtUser = Db::name('mtuser')->where('id', $tdid)->field('id,tdata_path,session_path, proxyip,uuid,last_api_address')->find();
            if (!$mtUser) {
                return json(['code' => 404, 'msg' => '未找到关联的账号信息']);
            }
            $scriptParams = [
                'action' => 'delete_chat_history',
                'session_path' => $mtUser['session_path'],
                'proxyip' => $mtUser['proxyip'],
                'target_id' => $chat_id,
                'last_api_address'=>$mtUser['last_api_address'],
            ];
            // 创建任务数据
            $jobData = [
                'params' => $scriptParams,
            ];
            
            // 推送到队列
            $jobId = Queue::push(TelegramSendMessage::class, $jobData, 'telegram_send');
            
            // 调用Python脚本获取历史消息
            /*$historyResult = $this->execPythonScript([
                'action' => 'delete_chat_history', // 对应Python脚本中的动作
                'session_path' => $mtUser['session_path'],
                'proxyip' => $mtUser['proxyip'],
                'target_id' => $chat_id,
                'last_api_address' => $mtUser['last_api_address'],
            ]);
            
            if (!$historyResult['status']) {
                return json(['code' => 400, 'msg' => $historyResult['message']]);
            }
            
            // 整理消息数据（补充发送者信息）
            $messages = $historyResult['data']['messages'] ?? [];*/
            
            // 修复这里：正确删除数据库中的消息记录
            $account_id = "temp_" . $mtUser['uuid'] . ".session"; // 注意：修复了变量名 mtUsers 应该是 mtUser
            
            // 删除数据库中对应的聊天记录
            $deleteResult = Db::name('tdmessages')->where('chat_id', $chat_id)->where('account_id', $account_id)->delete();
            
            return json([
                'code' => 200,
                'msg' => '删除成功',
                /*'data' => [
                    'messages' => $messages,
                    'data' => $historyResult['data']['data'],
                    'deleted_count' => $deleteResult, // 返回删除的行数
                ]*/
            ]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '删除历史消息失败：' . $e->getMessage()]);
        }
    }
    
    
    // 标记消息为已读
    public function markAsRead()
    {
        $chat_id = input('chat_id');
        $tdid = input('tdid');
        
        if (!$chat_id || !$tdid) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        try {
            $mtUser = MtuserModel::where('id', $tdid)->field('id,tdata_path,session_path, proxyip, uuid,last_api_address')->find();
            
            if (!$mtUser) {
                return json(['code' => 400, 'msg' => '账户不存在']);
            }
            $account_id="temp_".$mtUser['uuid'].".session";
            
            // 更新聊天未读数
            Db::name('tdchats')
                ->where('chat_id', $chat_id)
                ->where('account_id', $account_id)
                ->update([
                    'unread_count' => 0,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            
            // 更新消息为已读
            Db::name('tdmessages')
                ->where('chat_id', $chat_id)
                ->where('account_id', $accountId)
                ->where('is_outgoing', 0) // 只更新收到的消息
                ->update(['unread' => 0]);
                
            $scriptParams = [
                'action' => 'mark_session_as_read',
                'session_path' => $mtUser['session_path'],
                'proxyip' => $mtUser['proxyip'],
                'group_id' => $chat_id,
                'last_api_address'=>$mtUser['last_api_address'],
            ];
            // 创建任务数据
            $jobData = [
                'params' => $scriptParams,
            ];
            
            // 推送到队列
            $jobId = Queue::push(TelegramSendMessage::class, $jobData, 'telegram_send');
            
            // 调用Python脚本获取历史消息
            /*$historyResult = $this->execPythonScript([
                'action' => 'mark_session_as_read', // 对应Python脚本中的动作
                'session_path' => $accountInfo['session_path'],
                'proxyip' => $accountInfo['proxyip'],
                'group_id' => $chat_id,
                'last_api_address'=>$accountInfo['last_api_address'],
            ]);
    
            if (!$historyResult['status']) {
                return json(['code' => 400, 'msg' => $historyResult['message']]);
            }
    
            // 整理消息数据（补充发送者信息）
            $messages = $historyResult['data']['messages'] ?? [];
            */
    
            return json([
                'code' => 200,
                'msg' => '加载成功',
                /*'data' => [
                    'messages' => $messages,
                    'data' => $historyResult['data']['data'],
                   
                ]*/
            ]);
            
            return json(['code' => 200, 'msg' => '已标记为已读']);
            
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '标记已读失败: ' . $e->getMessage()]);
        }
    }
    
    // 执行Python脚本
	private function execPythonScript($params)
    {
       /* $this->httpClient = $httpClient ?? new Client([
            'timeout'         => 60.0,      // 整体超时时间
            'connect_timeout' => 10.0,      // 连接超时时间
            'read_timeout'    => 30.0,      // 读取超时时间
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'User-Agent'   => 'TelegramApiClient/1.0'
            ]
        ]);*/
        // Flask 服务的基础 URL
        $baseUrl = $params['last_api_address']; // 替换为你的 Flask 服务地址
        $url = $baseUrl . '/telegram_action';
        $action=$params['action'];
        $proxy=$params['proxyip'];
        $sessionPath=$params['session_path'];
        // 基础请求数据
        $requestData = [
            'action'    => $action,
            'api_id'    => config('telegram.api_id'),
            'api_hash'  => config('telegram.api_hash')
        ];

        // 添加tdata路径
        if ($sessionPath) {
            $requestData['tdata_path'] = $sessionPath;
        }

        // 处理代理信息
        if ($proxy) {
            $proxyParts = explode('##', $proxy);
            if (count($proxyParts) >= 3) {
                list($ipPort, $username, $password) = $proxyParts;
                $requestData['proxy'] = "socks5://{$username}:{$password}@{$ipPort}";
            } else {
                $error = "代理格式错误，正确格式应为: ip:port##username##password";
                log::error($error, ['proxy' => $proxy]);
                throw new \Exception($error);
            }
        }
        foreach($params as $key => $value) {
            if($key != 'action'&&$key != 'proxyip'&&$key != 'tdata_path') {
                $requestData[$key]=$value;
            }
        }
        log::info(json_encode($requestData,JSON_UNESCAPED_UNICODE));
        // 合并额外参数
        try {
            
            // 发送POST请求
            $response = $this->httpClient->post($url, [
                'json' => $requestData
            ]);

            // 获取响应内容
            $responseBody = $response->getBody()->getContents();
            log::debug('接口响应原始数据', [
                'action' => $action,
                'body'   => $responseBody
            ]);

            // 解析JSON响应
            $result = json_decode($responseBody, true);

            // 检查JSON解析错误
            if (json_last_error() !== JSON_ERROR_NONE) {
                $error = "JSON解析失败: " . json_last_error_msg();
                throw new \Exception($error);
            }

            // 检查接口返回状态
            if (empty($result['status'])) {
                $message = $result['message'] ?? '接口返回未知错误';
                
                throw new \Exception("操作失败: {$message}");
            }

            log::info('接口调用成功action'. $action.',result'. json_encode($result,JSON_UNESCAPED_UNICODE));

            return $result;

        } catch (RequestException $e) {
            // 处理HTTP请求异常
            $errorDetails = [];
            $errorMessage = "请求接口失败: ";

            if ($e->hasResponse()) {
                $statusCode = $e->getResponse()->getStatusCode();
                $errorBody = $e->getResponse()->getBody()->getContents();
                $errorMessage .= "状态码: {$statusCode}, 错误内容: {$errorBody}";
                $errorDetails['status_code'] = $statusCode;
                $errorDetails['response_body'] = $errorBody;
            } else {
                $errorMessage .= $e->getMessage();
            }

            $errorDetails['action'] = $action;
            $errorDetails['exception'] = $e->getMessage();
            $errorDetails['trace'] = $e->getTraceAsString();
            
            log::error($errorMessage, $errorDetails);
            throw new \Exception($errorMessage);

        }  catch (\Exception $e) {
            // 处理其他异常
            log::error('接口调用发生异常', [
                'action'  => $action,
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}	