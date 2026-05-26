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
class Index extends Baseinfo {
	
    public function initialize(){
		parent::initialize();
	}
    
	public function main(){
        $id = request()->get('id');
        $mtAccount='';
        if($id) $mtAccount = MtuserModel::where('id',$id)->field('id, main_dc_id,auth_key')->find();
        return view('main', ['account' => $mtAccount]);
    }

	
	public function index(){
	//	$accounts = $this->accountList()->getData()['data'];
        $kefuinfo = session('kefu');
        $kefuid = $kefuinfo['id'];
        $statusCounts = MtuserModel::field('id, account_status, COUNT(*) as count')
            ->where('customid', $kefuid)
            ->where('archive', 1)
            ->group('account_status')
            ->select();
        $statusMap = [];
        $totalCount = 0;
        foreach ($statusCounts as $status) {
           // $statusMap[$status['account_status']] = $status['count'];
            $totalCount += $status['count'];
        }
        
        log::info(json_encode($statusCounts,JSON_UNESCAPED_UNICODE));
        return view('index', ['kefuinfo' => $kefuinfo,'statusCounts'=>$statusCounts,'totalCount'=>$totalCount]);
	}
	public function checkKf(){
	    $kefuinfo = session('kefu');
        
        if(!$kefuinfo){
             return json([
                'code' => 400, 
                'msg' => '账户已退出' 
            ]);
        }else{
            return json([
                'code' => 200
            ]); 
        }
	}
	 // 获取账号列表
    // 修改 accountList() 方法，关联 mtuser 表
    public function accountList()
    {
        $kefuinfo = session('kefu');
        $kefuid = $kefuinfo['id'];
        if(!$kefuinfo){
             return json([
                'code' => 400, 
                'msg' => '账户已退出' 
            ]);
        }
        try {
            // 1. 接收前端传递的分页参数（默认值：第1页，每页10条）
            $page = request()->post('page', 1);         // 当前页码（前端传递）
            $pageSize = request()->post('pageSize', 200); // 每页条数（前端传递，可自定义）
            $keyword = request()->post('keyword');
            $status = request()->post('status');
            
         
            $query = MtuserModel::where('customid', $kefuid)
                ->where('status', 1)
                ->where('archive', 1);
            
            if ($keyword) {
                $query->where('account|nickName|username|uuid', 'like', "%{$keyword}%");
            }
            if($status) $query->where('account_status', $status);
            $res = $query->field('id, account, online, account_status,uuid, nickName, avatar_url, unread')
                ->order('nickName asc,id desc')
                ->paginate(['list_rows' => $pageSize, 'page' => $page])
                ->toArray();

            
            foreach ($res['data'] as $key => $value) {
                $account_id="temp_".$value['uuid'].".session";
                $res['data'][$key]['unread']=Db::name('tdchats')->where('account_id', $account_id)->where('chat_type','private')->sum('unread_count');
				$res['data'][$key]['tpid'] = $value['id'];
			}
			 
           
			$data['code'] = 200;
			$data['msg'] = 'success';
			$data['data'] = $res;
			return json($data);
			
        } catch (\Exception $e) {
            Log::error('获取账号列表失败: ' . $e->getMessage());
            return json([
                'code' => 500, 
                'msg' => '获取账号列表失败：' . $e->getMessage()
            ]);
        }
    }
    
    public function chechAccount(){
        $id = request()->post('id');
        $mtAccount = MtuserModel::where('id', $id)
                    ->field([
                        'id',
                        'nickName',
                        'session_path',
                        'proxyip',
                        'status',
                        'uuid',
                        'account_status',
                        'last_api_address',
                        'account_status_desc',
                    ])
                    ->find();
        /*if(!$mtAccount['auth_key']){
            $result = $this->execPythonScript([
                'action' => 'get_webhex',  // 对应Python脚本的动作标识
                'last_api_address'=>$mtAccount['last_api_address'],
                'session_path' => $mtAccount['session_path'],
                'proxyip' => $mtAccount['proxyip']
            ]);
            
            log::info(json_encode($result,));
        }  */         
        if($mtAccount['account_status']=='正常'){
            $status=200;
            $msg='正常';
        }else{
            $status=201;
            $msg=$mtAccount['account_status_desc'];
        }
  
        //unset($mtAccount['auth_key']);
      
        
        return json([
                'status' => $status, 
                'msg' => $msg,
                'data'=>$mtAccount
            ]);
        
    }
    

  
    // 获取指定账户的聊天列表
    public function getChats()
    {
        $accountId = input('account_id');
        $tpid = input('tpid');
        $keyword = input('keyword', '');

        if (!$accountId && !$tpid) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        try {

            // 获取账户信息
            $accountInfo = MtuserModel::where('id', $tpid)->field('id,uuid')->find();

            if (!$accountInfo) {
                return json(['code' => 400, 'msg' => '账户不存在']);
            }

            $account_id = "temp_" . $accountInfo['uuid'] . ".session";

            // 获取聊天列表
            $chatsQuery = Db::name('tdchats')->where('account_id', $account_id)->where('chat_id', '<>', $accountInfo['uuid']);

            if ($keyword) {
                $chatsQuery->whereLike('title|username', "%{$keyword}%");
            }

            $chats = $chatsQuery->select()->toArray();

            foreach ($chats as &$chat) {

                $chat['chat_data'] = json_decode($chat['chat_data'] ?? '{}', true);

                // 头像处理
                if ($chat['avatar_path'] == "图片") {
                    $chat['avatar_path'] = '/assets/images/pic.gif';
                } else {
                    $chat['avatar_path'] = preg_replace('#^\.\./public#', '', $chat['avatar_path']);
                }

                $lastMessage = null;

                // ========================
                // 1 先查 Redis
                // ========================

                if ($chat['chat_type'] != 'private') {

                    // 群消息
                    $redisKey = "tdata8:group_message_{$chat['chat_id']}:{$account_id}";

                } else {

                    // 私聊
                    $redisKey = "tdata8:messages:{$account_id}:{$chat['chat_id']}";
                }
                log::info($chat['chat_id'].':'.$redisKey);
                $messages = Cache::store('redis')->lRange($redisKey, 0, 0);

                if (!empty($messages)) {
                    $lastMessage = json_decode($messages[0], true);
                }
                log::info($chat['chat_id'].':'.json_encode($messages));
                // ========================
                // 2 Redis没有查数据库
                // ========================

                if (!$lastMessage) {

                    $lastMessage = Db::name('tdmessages')->where('account_id', $account_id)->where('chat_id', $chat['chat_id'])->order('created_at', 'desc')->find();
                }

                // ========================
                // 3 处理消息内容
                // ========================

                if ($lastMessage) {

                    if (isset($lastMessage['message_text'])) {

                        $chat['last_message_text'] = $lastMessage['message_text'];

                        $chat['last_message_time'] =
                            $lastMessage['timestamp'] ??
                            $lastMessage['created_at'] ??
                            date('Y-m-d H:i:s');

                    } else {

                        $chat['last_message_text'] = $this->formatMessageContent($lastMessage);

                        $chat['last_message_time'] =
                            $lastMessage['created_at'] ??
                            date('Y-m-d H:i:s');
                    }

                } else {

                    $chat['last_message_text'] = '暂无消息';

                    $chat['last_message_time'] =
                        $chat['updated_at'] ??
                        date('Y-m-d H:i:s');
                }

                // ========================
                // 4 聊天图标
                // ========================

                if ($chat['chat_type'] === 'private') {

                    $chat['icon'] = 'el-icon-user';
                    $chat['color'] = '#67C23A';

                } elseif ($chat['chat_type'] === 'group') {

                    $chat['icon'] = 'el-icon-chat-dot-square';
                    $chat['color'] = '#409EFF';

                } else {

                    $chat['icon'] = 'el-icon-s-promotion';
                    $chat['color'] = '#E6A23C';
                }
            }

            return json([
                'code' => 200,
                'data' => $chats,
                'total' => count($chats)
            ]);

        } catch (\Exception $e) {

            return json([
                'code' => 500,
                'msg' => '获取聊天列表失败: ' . $e->getMessage()
            ]);
        }
    }
    // 格式化消息内容（辅助方法）
    private function formatMessageContent($message)
    {
        if (!$message) return '';
        
        $content = '';
        
        switch ($message['message_type']) {
            case 'photo':
                $content = '[图片]';
                if (!empty($message['caption'])) {
                    $content .= ' ' . $message['caption'];
                }
                break;
            case 'image':
                $content = '[图片]';
                if (!empty($message['caption'])) {
                    $content .= ' ' . $message['caption'];
                }
                break;
            case 'image_text':
                $content = '[图片]';
                if (!empty($message['caption'])) {
                    $content .= ' ' . $message['caption'];
                }
                break;    
            case 'video':
                $content = '[视频]';
                if (!empty($message['caption'])) {
                    $content .= ' ' . $message['caption'];
                }
                break;
            case 'document':
                $content = '[文件]';
                if (!empty($message['caption'])) {
                    $content .= ' ' . $message['caption'];
                }
                break;
            case 'voice':
                $content = '[语音]';
                break;
            case 'sticker':
                $content = '[贴纸]';
                break;
            default:
                // 文本消息 - 使用 mb_strlen 和 mb_substr 处理多字节字符
                $text = $message['message_text'] ?? '';
                
                // 清理文本中的非法 UTF-8 字符
                $text = $this->cleanString($text);
                
                if (mb_strlen($text, 'UTF-8') > 50) {
                    $content = mb_substr($text, 0, 47, 'UTF-8') . '...';
                } else {
                    $content = $text;
                }
                break;
        }
        
        return $content;
    }
    
    // 清理字符串方法（补充）
    private function cleanString($string)
    {
        if (!is_string($string)) {
            return $string;
        }
        
        // 移除非法 UTF-8 字符
        $string = mb_convert_encoding($string, 'UTF-8', 'UTF-8');
        
        // 移除控制字符（可选）
        $string = preg_replace('/[\x00-\x1F\x7F]/u', '', $string);
        
        return $string;
    }
    // 获取指定聊天的消息历史
    public function getMessages()
    {
        $chatId = input('chat_id');
        $accountId = input('account_id');
        $limit = input('limit', 50);
        $offset = input('offset', 0);
    
        if (!$chatId || !$accountId) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
    
        try {
    
            // 获取账户
            $accountInfo = MtuserModel::where('account', $accountId)
                ->field('id,uuid')
                ->find();
    
            if (!$accountInfo) {
                return json(['code' => 400, 'msg' => '账户不存在']);
            }
    
            $account_id = "temp_" . $accountInfo['uuid'] . ".session";
    
            $messages = [];
    
            // =========================
            // 1 先查 Redis
            // =========================
    
            if (strpos($chatId, '-') === 0) {
    
                // 群
                $redisKey = "tdata8:group_message_{$chatId}:{$account_id}";
    
            } else {
    
                // 私聊
                $redisKey = "tdata8:messages:{$account_id}:{$chatId}";
            }
    
            $redisMessages = Cache::store('redis')->lRange(
                $redisKey,
                $offset,
                $offset + $limit - 1
            );
    
            if (!empty($redisMessages)) {
    
                foreach ($redisMessages as $msg) {
                    $messages[] = json_decode($msg, true);
                }
    
            }
    
            // =========================
            // 2 Redis 不够再查数据库
            // =========================
    
            if (count($messages) < $limit) {
    
                $dbMessages = Db::name('tdmessages')
                    ->where('account_id', $account_id)
                    ->where('chat_id', $chatId)
                    ->order('message_id', 'desc')
                    ->limit($offset, $limit)
                    ->select()
                    ->toArray();
    
                $messages = array_merge($messages, $dbMessages);
            }
    
            // =========================
            // 3 处理回复ID
            // =========================
    
            $replyMsgIds = [];
    
            foreach ($messages as $msg) {
    
                if (!empty($msg['reply_to_msg_id']) && $msg['reply_to_msg_id'] > 0) {
                    $replyMsgIds[] = $msg['reply_to_msg_id'];
                }
            }
    
            $replyMap = [];
    
            if (!empty($replyMsgIds)) {
    
                $replyMessages = Db::name('tdmessages')
                    ->where('account_id', $account_id)
                    ->where('chat_id', $chatId)
                    ->whereIn('message_id', array_unique($replyMsgIds))
                    ->field('message_id, sender_name, message_text, message_type, media_path')
                    ->select()
                    ->toArray();
    
                foreach ($replyMessages as $reply) {
    
                    $replyMap[$reply['message_id']] = [
                        'sender_name' => $reply['sender_name'],
                        'content' => $this->getMessageContent($reply)
                    ];
                }
            }
    
            // =========================
            // 4 格式化消息
            // =========================
    
            foreach ($messages as &$msg) {
    
                if ($msg['media_path'] == "图片") {
                    $msg['media_path'] = '/assets/images/pic.gif';
                } else {
                    $msg['media_path'] = preg_replace('#^\.\./public#', '', $msg['media_path'] ?? '');
                }
    
                $msg['message_time'] =
                    $msg['created_at'] ??
                    $msg['timestamp'] ??
                    date('Y-m-d H:i:s');
    
                $msg['content'] = $this->getMessageContent($msg);
    
                if (!empty($msg['reply_to_msg_id']) && $msg['reply_to_msg_id'] > 0) {
    
                    $replyId = $msg['reply_to_msg_id'];
    
                    if (isset($replyMap[$replyId])) {
    
                        $msg['reply_to_sender_name'] = $replyMap[$replyId]['sender_name'];
                        $msg['reply_to_content'] = $replyMap[$replyId]['content'];
    
                    } else {
    
                        $msg['reply_to_sender_name'] = '未知用户';
                        $msg['reply_to_content'] = '消息已删除';
                    }
    
                } else {
    
                    $msg['reply_to_sender_name'] = null;
                    $msg['reply_to_content'] = null;
                }
            }
    
            // 按时间排序
            $messages = array_reverse($messages);
    
            return json([
                'code' => 200,
                'data' => $messages,
                'total' => count($messages)
            ]);
    
        } catch (\Exception $e) {
    
            return json([
                'code' => 500,
                'msg' => '获取消息失败: ' . $e->getMessage()
            ]);
        }
    }
    
    // 辅助方法：获取消息内容
    private function getMessageContent($msg)
    {
        $messageType = $msg['message_type'] ?? 'text';
        
        switch ($messageType) {
            case 'text':
                return $msg['message_text'] ?? '';
                
            case 'photo':
            case 'image':
            case 'image_text':    
                // 如果有媒体路径，显示图片
                if (!empty($msg['media_path'])) {
                    $mediaPath = preg_replace('#^\.\./public#', '', $msg['media_path']);
                    // 确保是完整的URL
                    if (!str_starts_with($mediaPath, 'http')) {
                        $baseUrl = request()->domain();
                        $mediaPath = $baseUrl . $mediaPath;
                    }
                    return $mediaPath; // 前端需要处理这个URL
                }
                return '[图片]';
                
            case 'video':
                return '[视频]';
                
            case 'document':
                return '[文件]';
                
            case 'voice':
                return '[语音]';
                
            case 'image_text':
                $text = $msg['message_text'] ?? '';
                if (!empty($msg['media_path'])) {
                    return $text ? $text . ' [图片]' : '[图片]';
                }
                return $text;
                
            default:
                return '[其他消息]';
        }
    }
    
    
    
    
    /**
     * 获取群组信息（包括共同群组）
     */
    public function getGroupInfo() {
        $chatId = input('chat_id');
        $accountId = input('account_id');
        
        if (!$chatId || !$accountId) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }
        
        try {
            // 获取账户信息
            $accountInfo = MtuserModel::where('account', $accountId)->field('id,uuid')->find();
            
            if (!$accountInfo) {
                return json(['code' => 400, 'msg' => '账户不存在']);
            }
            
            $account_id = "temp_" . $accountInfo['uuid'] . ".session";
            
            // 获取当前群组信息
            $groupInfo = Db::name('tdchats')
                ->where('chat_id', $chatId)
                ->where('account_id', $account_id)
                ->find();
                
            if (!$groupInfo) {
                return json(['code' => 400, 'msg' => '群组不存在']);
            }
            
            // 解析群组数据
            $chatData = json_decode($groupInfo['chat_data'] ?? '{}', true);
            
            // 获取群组成员信息
            $memberCount = $chatData['member_count'] ?? 0;
            
            // 如果是私聊，获取共同群组
            if ($groupInfo['chat_type'] === 'private') {
                // 获取对方的用户ID
                $userId = $groupInfo['peer_id'] ?? null;
                
                if ($userId) {
                    // 获取对方所在的所有群组
                    $commonGroups = $this->getCommonGroups($account_id, $userId);
                } else {
                    $commonGroups = [];
                }
            } else {
                $commonGroups = [];
            }
            
            // 格式化返回数据
            $result = [
                'id' => $groupInfo['id'],
                'chat_id' => $groupInfo['chat_id'],
                'title' => $groupInfo['title'],
                'username' => $groupInfo['username'],
                'chat_type' => $groupInfo['chat_type'],
                'avatar_path' => $groupInfo['avatar_path'],
                'member_count' => $memberCount,
                'created_at' => $groupInfo['created_at'],
                'common_groups' => $commonGroups
            ];
            
            // 设置图标
            if ($groupInfo['chat_type'] === 'private') {
                $result['icon'] = 'el-icon-user';
                $result['color'] = '#67C23A';
            } else {
                $result['icon'] = 'el-icon-chat-dot-square';
                $result['color'] = '#409EFF';
            }
            
            return json([
                'code' => 200,
                'data' => $result
            ]);
            
        } catch (\Exception $e) {
            Log::error('获取群组信息失败: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => '获取群组信息失败: ' . $e->getMessage()]);
        }
    }
    
    
	// 执行Python脚本
	private function execPythonScriptBatch(array $tasks, int $concurrency = 10): array
    {
        $client = new Client([
            'timeout'         => 30.0,
            'connect_timeout' => 5.0,
            'read_timeout'    => 20.0,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ]
        ]);
        
        $promises = [];
        $results = [];
        
        foreach ($tasks as $index => $params) {
            $baseUrl = $params['last_api_address'];
            $url = $baseUrl . '/telegram_action';
            
            $requestData = [
                'action'    => $params['action'],
                'api_id'    => config('telegram.api_id'),
                'api_hash'  => config('telegram.api_hash')
            ];
            
            // 构建请求数据
            if (!empty($params['session_path'])) {
                $requestData['tdata_path'] = $params['session_path'];
            }
            
            // 处理代理
            if (!empty($params['proxyip'])) {
                $proxy = $this->formatProxy($params['proxyip']);
                if ($proxy) {
                    $requestData['proxy'] = $proxy;
                }
            }
            
            // 添加其他参数
            foreach($params as $key => $value) {
                if(!in_array($key, ['action', 'proxyip', 'session_path', 'last_api_address'])) {
                    $requestData[$key] = $value;
                }
            }
            
            $promises[$index] = $client->postAsync($url, [
                'json' => $requestData,
                'timeout' => 15 // 单个请求超时时间
            ]);
        }
        
        try {
            // 并行执行所有请求
            $responses = Utils::settle($promises)->wait();
            
            foreach ($responses as $index => $response) {
                $taskParams = $tasks[$index];
                
                if ($response['state'] === 'fulfilled') {
                    $responseBody = $response['value']->getBody()->getContents();
                    $result = json_decode($responseBody, true);
                    
                    if (json_last_error() === JSON_ERROR_NONE && !empty($result['status'])) {
                        $results[$index] = [
                            'success' => true,
                            'data' => $result,
                            'account' => $taskParams['account'] ?? ''
                        ];
                        Log::info('接口调用成功', [
                            'action' => $taskParams['action'],
                            'account' => $taskParams['account'] ?? ''
                        ]);
                    } else {
                        $results[$index] = [
                            'success' => false,
                            'error' => $result['message'] ?? '接口返回格式错误',
                            'account' => $taskParams['account'] ?? ''
                        ];
                        Log::error('接口返回错误', [
                            'action' => $taskParams['action'],
                            'account' => $taskParams['account'] ?? '',
                            'response' => $responseBody
                        ]);
                    }
                } else {
                    $error = $response['reason']->getMessage();
                    $results[$index] = [
                        'success' => false,
                        'error' => $error,
                        'account' => $taskParams['account'] ?? ''
                    ];
                    Log::error('请求失败', [
                        'action' => $taskParams['action'],
                        'account' => $taskParams['account'] ?? '',
                        'error' => $error
                    ]);
                }
            }
            
            return $results;
            
        } catch (\Exception $e) {
            Log::error('批量请求异常: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 格式化代理字符串
     */
    private function formatProxy(string $proxy): ?string
    {
        if (empty($proxy)) {
            return null;
        }
        
        $proxyParts = explode('##', $proxy);
        if (count($proxyParts) >= 3) {
            list($ipPort, $username, $password) = $proxyParts;
            return "socks5://{$username}:{$password}@{$ipPort}";
        }
        
        return null;
    }
}