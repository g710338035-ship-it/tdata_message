<?php

namespace app\robot\controller;
use think\facade\Db;
use think\facade\Log;
use think\facade\Queue;
use think\facade\Cache;
use GuzzleHttp\Client;
class Callbackquery extends Apibot
{
    protected $cacheBot;
    
    public function __construct()
    {
        parent::__construct();
        //$this->index();
        $this->cacheBot = $this->cacheBot;
        $config = config('arbiscan');
        $this->apiUrl = $config['api_url'];
        $this->apiKey = $config['api_key'];
    }
    /**
     * 处理回调查询数据
     *
     * @param array $data 回调数据
     */
    public function handle($data)
    {
        $token = $data['token'];
        $userId = $data['from']['id'];
        $chat_id = $data['message']['chat']['id'];
        $messageId = $data['message']['message_id'] ?? null;
        // 验证用户是否为管理员
       $isAdmin = $this->isUserAdmin($token, $userId, $chat_id);
       if (strpos($data['data'], 'next_page') !== false||strpos($data['data'], 'prev_page') !== false) {
    // 解析回调数据
        $dataParts = explode(':', $data['data']);
        $action = $dataParts[0]; // 操作类型（prev_page 或 next_page）
        $address = $dataParts[1]; // 钱包地址
        $page = intval($dataParts[2]); // 当前页码

        // 根据操作类型更新页码
        if ($action === 'next_page') {
            $page++;
        } elseif ($action === 'prev_page') {
            $page--;
        }
       
        // 重新发送交易记录
        $this->queryTransactionRecords($address,$chat_id,$messageId, $page);
       }
        
        if ($isAdmin) {
             // Log::info($data['data']);
            // 处理不同类型的指令
            if (strpos($data['data'], '/custom_message') !== false) {
                $this->handleCustomMessage($data);
            } elseif (strpos($data['data'], '/welcome_') !== false) {
                $this->handleWelcomeMessage($data);
            } elseif (strpos($data['data'], '/banWords') !== false) {
                $this->handleGroupBanWords($data);
            } elseif (strpos($data['data'], '/set_night') !== false) {
                $this->handleGroupSetnight($data);
            }elseif (strpos($data['data'], '/openTwo') !== false) {
                $this->handleBotOpentwo($data);
            }elseif (strpos($data['data'], '/backwash') !== false) {
                $this->handleBackwash($data);
            }elseif (strpos($data['data'], '/kwfilter') !== false) {
                $this->handlekwfilter($data);
            }elseif (strpos($data['data'], '/group_setting') !== false) {
                $this->handleGroupset($data);
            }elseif (strpos($data['data'], '/keyworkauto') !== false) {
                $this->handlekeyworkautoset($data);
            }elseif (strpos($data['data'], '/openQunzf') !== false) {
                $this->handleopenqunzf($data);
            }elseif (strpos($data['data'], '/addressjc') !== false) {
                
                $this->handleaddressjc($data);
            }else {
                // 默认处理方式
                $this->handleMessage($data);
            }
        } else {
            
            // 处理普通用户消息
            $this->handleUserMessage($data);
        }
    }

private function queryTransactionRecords($address, $chatId, $messageId, $page = 1)
    {
        $bot = Cache::store('redis')->get($this->cacheBot);
        $apiKey = $this->apiKey; // 替换为你的 Arbiscan API 密钥
        $note=Db::name('arbnotice')->where('address', $address)->value("note");
        $remark =$note??'无备注';
        $client = new Client();
        // Redis 缓存键
        $cacheKey = "transactions_{$address}";
        // 尝试从 Redis 缓存中获取交易记录
        $cachedTransactions = Cache::store('redis')->get($cacheKey);
        if ($cachedTransactions) {
        // 如果缓存存在，直接使用缓存数据
        $transactions = $cachedTransactions;
        } else {
          $response = $client->get(config('arbiscan.api_url'), [
            'query' => [
                'module' => 'account',
                'action' => 'txlist',
                'address' => $address,
                'startblock' => 0,
                'endblock' => 99999999,
                'sort' => 'desc',
                'apikey' => config('arbiscan.api_key'),
            ],
        ]);
        // 获取外部交易列表
        $externalTxResponse = json_decode($response->getBody(), true);
      
        
        $responsewai = $client->get(config('arbiscan.api_url'), [
            'query' => [
                'module' => 'account',
                'action' => 'txlistinternal',
                'address' => $address,
                'startblock' => 0,
                'endblock' => 99999999,
                'sort' => 'desc',
                'apikey' => config('arbiscan.api_key'),
            ],
        ]);
        // 获取内部交易列表
        $internalTxResponse= json_decode($responsewai->getBody(), true);
        
      
        if (!$externalTxResponse && !$internalTxResponse) {
            $postData = [
                'chat_id' => $chatId,
                'text' => "未找到该地址的交易记录",
                'parse_mode' => 'HTML'
            ];
            send($bot['bot_token'], 'sendMessage', $postData);
            return;
        }
        // 合并外部和内部交易
        $transactions = [];
        
        // 合并外部交易
        if ($externalTxResponse['status'] == '1' && !empty($externalTxResponse['result'])) {
            $transactions = array_merge($transactions, $externalTxResponse['result']);
        }

        // 合并内部交易
        if ($internalTxResponse['status'] == '1' && !empty($internalTxResponse['result'])) {
            $transactions = array_merge($transactions, $internalTxResponse['result']);
        }

        // 按照时间戳排序，降序排列（最新交易排在最前面）
        usort($transactions, function ($a, $b) {
            return $b['timeStamp'] - $a['timeStamp'];
        });
        
        // 将交易记录存入 Redis 缓存，设置过期时间为 5 分钟
        Cache::store('redis')->set($cacheKey, $transactions, 300);
        }
        $perPage = 3; // 每页显示 5 条记录
        $totalPages = ceil(count($transactions) / $perPage);
        $page = max(1, min($page, $totalPages)); // 确保页码在有效范围内
        $offset = ($page - 1) * $perPage;
        $pagedTransactions = array_slice($transactions, $offset, $perPage);
        
        // 格式化交易记录
        $message = "交易记录（第 {$page} 页，共 {$totalPages} 页）：\n\n区块地址变化通知：\n地址：{$address}\n备注：{$remark}\n\n";
        foreach ($pagedTransactions as $tx) {
            /*$message .= sprintf(
                "交易类型：%s\n交易金额：%s ETH\n出账地址：%s\n入账地址：%s\n交易时间：%s\n交易哈希：%s\n\n",
                $this->getTransactionType($tx, $address),
                $tx['value'] / 1e18,
                $tx['from'],
                $tx['to'],
                date('Y-m-d H:i:s', $tx['timeStamp']),
                $tx['hash']
            );*/
            
            
            
             $txHash = $tx['hash'];
                $from = $tx['from'];
                $to = $tx['to'];
                $value = bcdiv($tx['value'], '1000000000000000000', 18);// 转换为 ETH
                $timeStamp = date('Y-m-d H:i:s', $tx['timeStamp']);
                $ethBalance = $this->getEthBalance($address); // 假设有方法来获取当前 ETH 余额
    
                // 判断交易类型
                $transactionType = $this->getTransactionType($tx, $address);
    
                $message .= sprintf(
                    "交易类型：%s\n交易金额：%s ETH\n出账地址：%s\n入账地址：%s\n交易时间：%s\n交易哈希：%s\nETH余额：%s ETH\n\n",
                    $transactionType,  // 交易类型
                    $value,    // 交易金额
                    $from,     // 出账地址
                    $to,       // 入账地址
                    $timeStamp,  // 交易时间
                    $txHash,   // 交易哈希
                    $ethBalance // 当前余额
                );
        }

        // 创建内联键盘
        $keyboard = [
            'inline_keyboard' => []
        ];

        // 添加上一页按钮（如果不是第一页）
        if ($page > 1) {
            $uppage=$page-1;
            $keyboard['inline_keyboard'][] = [
                [
                    'text' => '上一页，第'.$uppage.'页',
                    'callback_data' => "prev_page:{$address}:{$page}"
                ]
            ];
        }

        // 添加下一页按钮（如果不是最后一页）
        if ($page < $totalPages) {
            $nextpage=$page+1;
            $keyboard['inline_keyboard'][] = [
                [
                    'text' => '下一页，第'.$nextpage.'页',
                    'callback_data' => "next_page:{$address}:{$page}"
                ]
            ];
        }
        $keyboard['inline_keyboard'][] = [[
        	'text' => '❌关闭',
        	'callback_data' => '/closeMessage'
        ]];
        // 发送消息并附上翻页按钮
            $postData = [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode($keyboard),
                'message_id' =>$messageId
            ];
    
            // 发送消息
            $fs=send($bot['bot_token'], 'editMessageText', $postData);
           //log::info($fs);
            
            
        
    }
    
    // 获取 ETH 余额
     private function getEthBalance($address)
    {
        $apiKey = $this->apiKey; // 替换为你的 Arbiscan API 密钥
        $client = new Client();
    
        try {
            // 发送请求并获取响应
            $response = $client->get(config('arbiscan.api_url'), [
                'query' => [
                    'module' => 'account',
                    'action' => 'balance',
                    'address' => $address,
                    'tag' => 'latest',
                    'apikey' => $apiKey,
                ],
            ]);
    
            // 解码 JSON 响应
            $data = json_decode($response->getBody(), true);
    
            // 检查是否成功获取到余额
            if (isset($data['result'])) {
                // 获取余额并转换为 ETH (确保为数字类型)
                $balanceInWei =bcdiv($data['result'], '1000000000000000000', 18);
                return $balanceInWei; // 转换为 ETH
            } else {
                // 如果没有获取到余额或出错，返回 0
                return 0;
            }
        } catch (\Exception $e) {
            // 请求失败，记录错误日志并返回 0
            Log::error("获取 ETH 余额失败：" . $e->getMessage());
            return 0;
        }
    }
    
    
    // 判断交易类型
    private function getTransactionType($transaction, $address)
    {
        // 如果交易的目标地址是监控地址，则为入账
        if (strtolower($transaction['to']) === strtolower($address)) {
            // 判断是否为合约入账
            if (!empty($transaction['input']) && $transaction['input'] !== '0x') {
                return '合约入账';
            } else {
                return '普通入账';
            }
        } else {
            return '出账';
        }
    }


    /**
     * 检查用户是否为管理员
     *
     * @param string $token 机器人令牌
     * @param int $userId 用户ID
     * @param int $chat_id 群聊ID
     * @return bool 是否为管理员
     */
    private function isUserAdmin($token, $userId, $chat_id)
    {
        if (Cache::store('redis')->has($this->cacheBot)) {
	        $botinfo=Cache::store('redis')->get($this->cacheBot);
	        $ss=$botinfo['chat_id'];
            return $botinfo && $botinfo['chat_id'] == $userId;
        }
    }
    /**
     * 处理自定义消息
     *
     * @param array $data 数据内容
     */
    private function handleCustomMessage(&$data)
    {
        $data['messagetype'] = 2;
        if (strpos($data['data'], '/custom_infoedit_') !== false) {
            $this->getControllerInstance(Custommessageedit::class)->handle($data);
        } else {
            $this->getControllerInstance(Custommessage::class)->handle($data);
        }
    }
    /**
     * 处理欢迎消息
     *
     * @param array $data 数据内容
     */
    private function handleWelcomeMessage(&$data)
    {
        $data['messagetype'] = 2;
        $this->getControllerInstance(Welcomeinfo::class)->handle($data);
    }
    //双向
    private function handleBotOpentwo(&$data)
    {
        $data['messagetype'] = 2;
        $this->getControllerInstance(Opentwoway::class)->handle($data);
    }
    
     private function handleopenqunzf(&$data)
    {
        $data['messagetype'] = 2;
        $this->getControllerInstance(Openqunzf::class)->handle($data);
    }
    private function handleaddressjc(&$data)
    {
        $data['messagetype'] = 2;
        $this->getControllerInstance(Addressjc::class)->handle($data);
    }
    /**
     * 处理群组禁用词消息
     *
     * @param array $data 数据内容
     */
    private function handleGroupBanWords(&$data)
    {
        $data['messagetype'] = 2;
        $this->getControllerInstance(Groupbanwords::class)->handle($data);
    }
    private function handleGroupSetnight(&$data)
    {
        $data['messagetype'] = 2;
        $this->getControllerInstance(Setnight::class)->handle($data);
    }
    
    private function handleBackwash(&$data)
    {
        $data['messagetype'] = 2;
        $this->getControllerInstance(Backwash::class)->handle($data);
    }
    private function handlekwfilter(&$data)
    {
        $this->getControllerInstance(Kwfilter::class)->handle($data);
    }
    private function handleGroupset(&$data)
    {
        $data['messagetype'] = 2;
        $this->getControllerInstance(Groupset::class)->handle($data);
    }
    private function handlekeyworkautoset(&$data)
    {
        $data['messagetype'] = 2;
        $this->getControllerInstance(Keyworkauto::class)->handle($data);
    }
    /**
     * 实例化控制器类
     *
     * @param string $className 控制器类名
     * @return object 控制器实例
     */
    private function getControllerInstance($className)
    {
        $fullClassName =  $className;
       
        return new $fullClassName();
    }
    ///管理员消息处理
    protected function handleMessage($update)
    {
      
		$token=$update['token'];
		$callbackQueryId = $update['id'];
		$last_name=isset($update['from']['last_name']);
        $name = $update['from']['first_name'].$last_name;
        $chat_id = $update['message']['chat']['id'];
        $chatType = $update['message']['chat']['type'];
        $text=$update['data'];
		$userId =$update['from']['id'];
		$messageId = $update['message']['message_id']; 
		$username ='';
	
		switch ($text) {
		         
        	case '/cloneBot':
        	    $this->cloneBot($token,$messageId,$userId,$chat_id); //克隆
        		break;
        	case '/closeMessage':
        	   if($chatType=='private'){
        	       $this->closeMessage($token,$messageId, $chat_id); 
        	    }else{
        	        
        	        if (isset($update['message']['reply_to_message'])) {
        	            if($update['message']['reply_to_message']['from']['id']==$userId){
                            $this->closeMessage($token,$messageId, $chat_id); 
                        }
                    }
                    $botinfo=Cache::store('redis')->get($this->cacheBot);
	               
                    if($userId==$botinfo['chat_id']){
                         $this->closeMessage($token,$messageId, $chat_id); 
                    }
        	    }
        		break;	
        	case '/del_group_allmessage':
        	        $content = [
                        'callback_query_id' => $callbackQueryId,
                        'text' => '群消息删除,提交成功，系统删除中！',
                        'show_alert' => true,
                        'cache_time' => 5
                    ];
                    send($token,'answerCallbackQuery', $content);
        	        
                    $content = [
                        'group_id' => $chat_id,
                        'token' => $token,
                    ];
                	 Queue::push('app\job\GroupMessageDelJob', $content);       	    
        	    break;
        	case '/openBicx':
		        $bot=Cache::store('redis')->get($this->cacheBot);
		       
                    if ($bot['isbi'] == 1) {
                        $str = '已*【开启】*币查询，如果需要 * 取消币查询 * 请点击下方按钮'; // 使用 Markdown 格式
                        $bs = '取消双向❌';
                    } else {
                        $str = '已*【关闭】*币查询，如果需要 * 开启币查询 * 请点击下方按钮'; // 使用 Markdown 格式
                        $bs = '开启币查询✅';
                    }
                    
                    // 确保 $bwButtons 被初始化为数组
                    $bwButtons = [];
                    
                    $bwButtons[] = [[
                        'text' => "💹$bs",
                        'callback_data' => '/openBicx-status'
                    ],  [
                        'text' => '🔙 返回',
                        'callback_data' => '/start'
                    ]];
                    
                    $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                    
                    $content = [
                        'chat_id' => $chat_id,
                        'reply_markup' => $replyMarkup,
                        'message_id' => $messageId,
                        'text' => "💹开启币查询\n\n此机器人$str",
                        'parse_mode' => 'Markdown' // 改为 Markdown 格式
                    ];
                    
                    // 发送请求以编辑消息
                    $response = send($token, 'editMessageText', $content);
		        break;
		    case '/openBicx-status':
		        $bot=Cache::store('redis')->get($this->cacheBot);
		        $isbi = ($bot['isbi'] == 0) ? 1 : 0;
                // 更新 is_top 值
                Db::name('telegrambot')->where('bot_token', $token)->update(['isbi' => $isbi]);
		        
		   
                    if ($bot['isbi'] == 0) {
                        $str = '已*【开启】*币查询，如果需要 * 取消币查询 * 请点击下方按钮'; // 使用 Markdown 格式
                        $bs = '取消币查询❌';
                    } else {
                        $str = '已*【关闭】*币查询，如果需要 * 开启币查询 * 请点击下方按钮'; // 使用 Markdown 格式
                        $bs = '开启币查询✅';
                    }
                    
                    echo $str;
                    
                    // 确保 $bwButtons 被初始化为数组
                    $bwButtons = [];
                    
                    $bwButtons[] = [[
                        'text' => "💹$bs",
                        'callback_data' => '/openBicx-status'
                    ],  [
                        'text' => '🔙 返回',
                        'callback_data' => '/start'
                    ]];
                    
                    $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                    
                    $content = [
                        'chat_id' => $chat_id,
                        'reply_markup' => $replyMarkup,
                        'message_id' => $messageId,
                        'text' => "💹开启币查询\n\n此机器人$str",
                        'parse_mode' => 'Markdown' // 改为 Markdown 格式
                    ];
                    
                    // 发送请求以编辑消息
                    $response = send($token, 'editMessageText', $content);
                    $bot['isbi']=$isbi;
                    Cache::store('redis')->set($this->cacheBot, $bot,3600);
		        break; 
        	default:
        	    
  
        	  break;  
        
       
        }
       
       
        if ($this->checkfortelemessage->checkForTelemessageEdit($chat_id, $chatType, $text, $userId, $token, $messageId)) {
	         return; // 如果检测到自定义回复，直接返回
	     }
    }
    
    
    
    
    // 处理普通用户消息
    protected function handleUserMessage($update)
    {   $callbackQueryId = $update['id'];
		$token=$update['token'];
        $chat_id = $update['message']['chat']['id'];
        $chatType = $update['message']['chat']['type'];
        $text=$update['data'];
		$userId =$update['from']['id'];
		$messageId = $update['message']['message_id']; 
		$username ='';
		$commandUserId = Cache::get("user:{$chat_id}:command_user");
		
        $userInfo = $update['from'];
        $name = $userInfo['first_name'] . (isset($userInfo['last_name']) ? ' ' . $userInfo['last_name'] : '');
        $date = date("Y-m-d H:i:s", $update['message']['date']);  // 消息的发送时间作为进群时间
        //$username = isset($userInfo['username']) ? '@' . $userInfo['username'] : $name;
        $username = isset($userInfo['username']) ?  $userInfo['username'] : $name;
        $groupname = isset($update['message']['chat']['title'])?$update['message']['chat']['title']:'';  // 群组名称
    
    
		switch ($text) {
		       
        	case '/cloneBot':
        		// 处理文本消息
        		// 添加关闭按钮
        		if($chatType=='private'){
        		    $this->cloneBot($token,$messageId,$userId,$chat_id); 
        		}
        		break;
        	case '/closeMessage':
        	    if($chatType=='private'){
        	       $this->closeMessage($token,$messageId, $chat_id); 
        	    }
        		break;
        	case '/Applyforper':
        	    if($chatType=='private'){
        	       $this->Applyforper($token,$messageId, $chat_id,$userId,$username,$name); 
        	    }
        		break;	
        	case '/addressjc_set':
                $this->addressjc_set($chat_id, $chatType, $text, $userId, $token, $messageId);
                break;
            case '/addressjc_see':
                $this->addressjc_see($chat_id, $chatType, $text, $userId, $token, $messageId,$username);
                break;    
        	default:
        	    /*if (strpos($text, '/Joingroupcorrect:') === 0) {
                    $newUser=str_replace('/Joingroupcorrect:', '', $text);
                  
                    if($newUser==$userId){
                        Log::info($text);
                        $this->newUserjion($chat_id, $userId,$messageId,$token,$name,$date, $username, $groupname);
                    }
                }else if(strpos($text, '/Joingroupwrong:') === 0){
                    $newUser=str_replace('/Joingroupwrong:', '', $text);
                    if($newUser==$userId){
                        $content = [
                        'callback_query_id' => $callbackQueryId,
                        'text' => '答案错误！',
                        'show_alert' => true,
                        'cache_time' => 5
                        ];
                        send($token,'answerCallbackQuery', $content);
                    
                        $this->newUserexit($chat_id, $userId,$messageId,$token,$name);
                    }
                }*/
                if($chatType=='private'){	 
    		    // Log::info($text);
                if ($this->checkfortelemessage->checkForTelemessageEdit($chat_id, $chatType, $text, $userId, $token, $messageId)) {
        	         return; // 如果检测到自定义回复，直接返回
        	     }
    	    }  
        	  break;  
            
       
        }
        //if ($commandUserId && $commandUserId == $userId) {
    		 // 检查消息中是否包含自定义回复
    	   /* if($chatType=='private'){	 
    		    // Log::info($text);
                if ($this->checkfortelemessage->checkForTelemessageEdit($chat_id, $chatType, $text, $userId, $token, $messageId)) {
        	         return; // 如果检测到自定义回复，直接返回
        	     }
    	    }  */
        //}
        
      // Log::info($text);
    }
protected function addressjc_set($chat_id, $chatType, $text, $userId, $token, $messageId) {
    $customMessage = "💠 请先添加你需要监控的 TRC/ERC 地址，可以帮您实时监控地址的资产余额变动情况，可同时添加多条地址，一行一条。\n
*命令行说明:*\n
查询地址交易记录命令：*++地址*\n
示例：++TL8TBpubVzBr1UWPXBXU8Pci5ZAip9SwEf\n
添加地址（可批量添加，一行一条）：*add+地址+备注*\n
示例：\n
add+TEKdVLe9SjFjfAb26VJ58xMKZpYHWz1111+地址一\n
add+TWFsx2DTfojosue4cpcvNpBA7iPSXamgMr+地址二\n
删除地址（可批量删除，一行一条）：*del+地址*\n
示例：\n
del+TEKdVLe9SJFjfAb26VJ58xMKZpYHWz1111\n
del+TWFsx2DTfojosue4cpcvNpBA7iPSXamgMr\n\n
👇下面输入地址直接绑定地址，该地址有资金变动就发通知";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '👀查看地址', 'callback_data' => '/addressjc_see'],
                ['text' => '❌关闭', 'callback_data' => '/closeMessage']
            ]
        ],
    ];

    $content = [
        'chat_id' => $chat_id,
        'reply_markup' => json_encode($keyboard),
        'message_id' => $messageId,
        'text' => $customMessage,
        'parse_mode' => 'Markdown' // 使用 Markdown 格式
    ];

    // 发送请求以编辑消息
    $response = send($token, 'editMessageText', $content);
}

    //克隆机器人
    protected function cloneBot($token,$messageId,$userId,$chat_id){
        $cbButtons[] = [[
        	'text' => '❌关闭',
        	'callback_data' => '/closeMessage'
        ]];
        $replyMarkup = json_encode(['inline_keyboard' => $cbButtons]);
        $content = [
        	'chat_id' => $chat_id,
        	'text' => "👉 按照以下流程进行机器人克隆：\n\n1. 打开 @BotFather\n2. 发送 /newbot\n3. 按指引设置机器人名字，可中文\n4. 设置机器人username，英文+数字，需要以bot结尾\n5. 创建完成后将注册好的token发送给我\n\ntoken格式：\n6422100000:AAFMTBWko3t7gA3mN5SRYp5FuYcxxxxxxxxx",
        	'reply_markup' => $replyMarkup,
        	'message_id' => $messageId,
        ];
        
        send($token,'editMessageText', $content);
        
        if ($messageId) {
        	// 使用 Redis 的列表来存储消息 ID，以群组 ID 为 key
        	$redisKey = "cloneBot:$userId:cloneBot_status";
        	$waiting='waiting_for_message';
          
        	// 将消息 ID 推入 Redis 列表
        	$this->redis->sadd($redisKey, $waiting);
        
        	// 设置列表的过期时间（例如一周后自动清理）
        	$this->redis->expire($redisKey, 20 * 60); // 7天
        }
    }
    
     protected function addressjc_see($chat_id, $chatType, $text, $userId, $token, $messageId, $username)
    {
        $str = "*" . $username . "*, 您的监测地址如下：\n";
        $rsaddress = Db::name('arbnotice')->where('user_id', $userId)->select();
    
        if ($rsaddress) {
            $counter = 1; // 初始化计数器
            foreach ($rsaddress as $info) {
                $str .= $counter . '. ' . $info['address'] . ' + ' . $info['note'] . "\n";
                $counter++; // 每次循环后递增计数器
            }
        } else {
            $str = '暂无检测地址';
        }
    
        $messageText = $str;
    
        $bwButtons = [
            [[
                'text' => '🔙 返回',
                'callback_data' => '/addressjc_set'
            ]]
        ];
    
        $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
    
        $content = [
            'chat_id' => $chat_id,
            'text' => $messageText,
            'message_id' => $messageId,
            'reply_markup' => $replyMarkup,
            'parse_mode' => 'Markdown' // 使用 Markdown 格式
        ];
    
        send($token, 'editMessageText', $content);
    }

    
    //关闭消息
    protected function Applyforper($token,$messageId, $chat_id,$userId,$username,$name)
    {
        $bot=Cache::store('redis')->get($this->cacheBot);
        $rs=Db::name('membe')->where('user_id',$userId)->find();
        if($rs){
            if($rs['status']==1){$str='您的信息成功了';}else{$str=' 您的数据已提交申请，请联系客服！!';}
            $content = [
                'chat_id' => $chat_id,
                
                'text'=>$str
            ];
            send($token,'sendMessage', $content);
        }else{
        $content = [
            'chat_id' => $chat_id,
            'text'=>' 您的数据已提交申请，请联系客服！！'
        ];
        send($token,'sendMessage', $content);
        
        $insertData= [
	                'user_id' => $userId,
	                'username' => $username,
	                'name' => $name,
	                //'bot_id' => $bot['bot_id'],
	                'status' => 0,
	                'create_time' => time(),
	               
	            ];
	    Db::name('membe')->insert($insertData); 
            
        }      
        
    }
    
    //关闭消息
    protected function closeMessage($token,$messageId, $chat_id)
    {
        $content = [
            'chat_id' => $chat_id,
            'message_id' => $messageId,
        ];
        send($token,'deleteMessage', $content);
    }
    //新用户加入
    private function newUserjion($chat_id, $userId,$messageId,$token,$name,$date, $username, $groupname)
    {
       // Log::info($chat_id);
        if ($this->isUserInGroup($chat_id, $userId)) {
                    // 如果用户不在群组，存储用户信息到 Redis
        $cacheGroupkey="telegram_group_".$chat_id;
       // Log::info($cacheGroupkey);
        $groupInfo = Cache::store('redis')->get($cacheGroupkey);
        if($groupInfo['content']){
            $welcome =$groupInfo['content'];
            // 替换函数
            $welcome = preg_replace_callback('/\{(\w+)\}/', function ($matches) use ($name, $date, $username, $groupname) {
                // 提取占位符名称
                $placeholder = $matches[1];
                
                // 判断占位符名称是否对应变量，并返回变量的值
                switch ($placeholder) {
                    case 'truename':
                        return $name;
                    case 'date':
                        return $date;
                    case 'username':
                        return $username;
                    case 'groupname':
                        return $groupname;
                    default:
                        // 未定义的占位符保持原样
                        return $matches[0];
                }
            }, $welcome);
            
            $str=$welcome;
        }else{
            $str="欢迎 用户 $name 加入群组";
        }
                $redisKeydel = "group_user_joins:$chat_id.$userId";
                
                // 从集合中删除指定的 $data
                $this->redis->del($redisKeydel);
                
                  
                    $content = array(
                        'chat_id' => $chat_id,
                        'text' => $str,
                   ); 
                   
                    $replyMessageId=false;
                    if ($replyMessageId) {
                        $content['reply_to_message_id'] = $data['message_id'];
                    }
                    
                    send($token, 'sendMessage', $content);
                    
                   $content = array(
                        'chat_id' => $chat_id,
                        'user_id' => $userId,
                        'permissions' => array(
                            'can_send_messages' => true, // 允许发送文本消息
                            'can_send_media_messages' => true, // 允许发送多媒体消息
                            
                        )
                    );
                    
                    $response = send($token, 'restrictChatMember', $content);
                    if ($response) {
                        echo "操作失败: " . $response;
                    }
        
        		     $content = array(
                        'chat_id' => $chat_id,
                        'message_id' => $messageId
                    );
                    send($token, 'deleteMessage', $content);
        } 
          
    }
    //新用户退出
     private function newUserexit($chat_id, $userId,$messageId,$token,$name)
    {          
        
                $content = array(
                        'chat_id' => $chat_id,
                        'message_id' => $messageId
                    );
                    
                send($token, 'deleteMessage', $content);
               
                $data = json_encode([
                    'group_id' => $chat_id,
                    'userId' => $userId
                ]);
                
                $redisKeydel = "group_user_joins:$chat_id.$userId";
                
                // 从集合中删除指定的 $data
                $this->redis->del($redisKeydel);
               
                //Db::name('membe')->where('group_id', $chat_id)->where('user_id', $userId)->delete();
                $content = array(
                        'chat_id' => $chat_id,
                        'user_id' => $userId
                    );
                    
                send($token, 'kickChatMember', $content);
                $content = array(
                    'chat_id' => $chat_id,
                    'user_id' => $userId
                );
                $response = send($token, 'unbanChatMember', $content);
    }
    
    // 检查用户是否在群组中
    private function isUserInGroup($groupId, $userId)
    {
        try {
             $redisKey = "group_user_joins:$groupId.$userId";
             //Log::info($redisKey);
             if ($this->redis->exists($redisKey)) {
                //Log::info("键 $redisKey 存在");
                return true;
            } else {
               // Log::info("键 $redisKey 不存在");
                return false;
            }
           
            //return Cache::hExists("group:{$groupId}:users", $userId);
        } catch (\Exception $e) {
            Log::error("Redis 错误: " . $e->getMessage());
            return false;  // 默认返回 false，继续处理
        }
    }  
}
