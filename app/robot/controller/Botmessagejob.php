<?php

namespace app\robot\controller;
use think\facade\Db;
use think\facade\Log;
use think\facade\Cache;
use GuzzleHttp\Client;
class Botmessagejob extends Apibot
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
    
    public function handle($data)
    {
       
        $fromChatId = $data['chat']['id'];
        if (isset($data['photo'])) {
            $photos = $data['photo'];
            $largestPhoto = end($photos);
            $message = $largestPhoto['file_id'];
        }else{
           $message = isset($data['text']) ? $data['text'] : ''; 
        }
        
        $messageId = $data['message_id'];
        $chatType = $data['chat']['type'];
        $senderId = $data['from']['id'];
        $truename = $data['from']['first_name'] . (isset($data['from']['last_name']) ? $data['from']['last_name'] : '');
        $token = $data['token'];
      
        
            if (isset($data['reply_to_message'])) {
                $this->handleReplyMessage($data, $token, $fromChatId);
            }elseif(isset($data['photo'])){
                $botinfo=Cache::store('redis')->get($this->cacheBot);
                if ($botinfo['chat_id'] == $fromChatId) {
                    $waiting = 'waiting_for_message';
                    if($this->handleGroupCommands($message, $token, $fromChatId, $senderId, $messageId,$waiting)){
                          return;
                    }
                    $caption=isset($data['caption'])?$data['caption']:'';
                    
                    if($this->handleXXphoto($message, $token, $fromChatId, $senderId, $messageId,$waiting,$caption)){
                          return;
                    }
                }   
            }else {
            
                $entities = isset($data['entities']) ? $data['entities'] : '';
                if($entities){
                  $message=$this->sendTextentities($message,$entities);  
                }
                
                log::info($message);
                $this->handleDirectMessage($message, $token, $fromChatId,$truename, $senderId, $messageId, $chatType);
            }
    

        // 如果队列处理完成，删除任务
    }


    // 处理机器人回复消息
    private function handleReplyMessage($data, $token, $fromChatId)
    {
        $replyToMessage = $data['reply_to_message'];
        $forwardOrigin = $replyToMessage['forward_origin'];

        if (isset($forwardOrigin['sender_user'])) {
            $senderUserId = $forwardOrigin['sender_user']['id'];
            $this->botToMember($data['text'], $senderUserId, $token);
        }
    }

    // 处理机器人直接消息
    private function handleDirectMessage($message, $token, $fromChatId, $truename,$senderId, $messageId, $chatType)
    {
         $botinfo=Cache::store('redis')->get($this->cacheBot);
         if($botinfo['addressjc']==1){
            /*地址检测*/
            // 处理特定命令
            if (strpos($message, '++') === 0) {
                // 查询地址交易记录
                $address = substr($message, 2); // 获取地址
                $this->queryTransactionRecords($address, $fromChatId, $senderId);
                return;
            } elseif (strpos($message, 'add+') === 0) {
                //Log::info("管理员消息处理add");
               $this->addAddress($message,$fromChatId,$senderId);
               return;
            } elseif (strpos($message, 'del+') === 0) {
                // 删除地址
               $this->delAddress($message,$fromChatId,$senderId);
               return;
            } 
               /*地址完成*/  
         }
        if ($this->isFormToBot($message,$fromChatId)) {
            
        // 转发消息给用户 机器人
            $this->forwardTobot($fromChatId, $messageId, $senderId, $truename, $token, $message, $chatType);
        }else{
           
            if ($this->isValidToken($message)) {
                   
                    //if($botinfo['clone_type']==1){
                        $this->processCloneBot($message, $fromChatId, $token, $messageId);
                    //}
                }else{
                    
                  /*if($this->handlexxButton($senderId,$fromChatId,$message,$token,$messageId)){
                    return;
                  }*/
               
                   
                   
                  $this->botMessage($fromChatId, $messageId, $senderId, $truename, $token, $message, $chatType);
                    
                }
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
        
        // 分页逻辑
        //$transactions = $data['result'];
        
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
            );
            */
            
            
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
                'reply_markup' => json_encode($keyboard)
            ];
    
            // 发送消息
            send($bot['bot_token'], 'sendMessage', $postData);
            
            
        
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
            log::info($data);
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
// 处理按钮点击的回调
/*public function handleCallbackQuery($callbackQuery)
{
    $bot = Cache::store('redis')->get($this->cacheBot);
    $chatId = $callbackQuery['message']['chat']['id'];
    $messageId = $callbackQuery['message']['message_id'];
    $data = $callbackQuery['data'];  // 获取回调数据

    // 解析回调数据，获取当前页数
    preg_match('/tx_page_(\d+)_([a-z]+)/', $data, $matches);

    if (count($matches) === 3) {
        $page = (int)$matches[1];
        $action = $matches[2];

        // 根据用户操作（上一页/下一页），调整页码
        if ($action == 'prev' && $page > 1) {
            $page--; // 上一页
        } elseif ($action == 'next') {
            $page++; // 下一页
        }

        // 重新查询交易记录并发送
        $this->queryTransactionRecords($address, $chatId, $messageId, $page);
    }
}*/

    
    private function addAddress($text, $chatId, $fromUserId)
    {
        // 获取 bot 信息
        $bot = Cache::store('redis')->get($this->cacheBot);
    
        // 提取地址和备注
        $addresses = explode("\n", substr($text, 4)); // 去掉 'add+' 前缀
        $addressData = []; // 用来存储待添加的地址和备注
        $existingAddresses = []; // 已存在的地址
    
        foreach ($addresses as $address) {
            $parts = explode('+', $address);
            
            if (count($parts) == 2) {
                $address = $parts[0];
                $remark = $parts[1];
    
                // 存储待添加的地址和备注
                $addressData[] = [
                    'address' => $address,
                    'remark' => $remark
                ];
            }
        }
    
        // 批量查询数据库，获取现有的地址
        $addressList = Db::name('arbnotice')
            ->where('chat_id', $chatId)
            ->whereIn('address', array_column($addressData, 'address')) // 查找已经存在的地址
            ->select()
            ->toArray();
    
        // 提取已存在的地址
        foreach ($addressList as $addressRecord) {
            $existingAddresses[] = $addressRecord['address'];
        }
    
        // 过滤出不存在的地址
        $newAddresses = array_filter($addressData, function ($addressItem) use ($existingAddresses) {
            return !in_array($addressItem['address'], $existingAddresses);
        });
    
        // 如果有新地址，插入到数据库
        if (!empty($newAddresses)) {
            $insertData = [];
            foreach ($newAddresses as $addressItem) {
                $insertData[] = [
                    'user_id' => $fromUserId,
                    'address' => $addressItem['address'],
                    'chat_id' => $chatId,
                    'note' => $addressItem['remark'],
                    'created_at' => time(),
                    'botid' => $bot['id'],
                ];
                $addinfo=[
                    'user_id' => $fromUserId,
                    'address' => $addressItem['address'],
                    'chat_id' => $chatId,
                    'note' => $addressItem['remark'],
                    'created_at' => time(),
                    'botid' => $bot['id'],
                ];
                $cacheKeyadd = "arbiscan_address_info_{$chatId}_{$address}";
                Cache::store('redis')->set($cacheKeyadd, $addinfo);
            }
    
            // 批量插入新地址
            Db::name('arbnotice')->insertAll($insertData);
    
            // 返回成功的消息
            $postData = [
                'chat_id' => $chatId,
                'text' => "成功添加以下地址：\n" . implode("\n", array_column($newAddresses, 'address')),
                'parse_mode' => 'HTML'
            ];
            send($bot['bot_token'], 'sendMessage', $postData);
        }
    
        // 如果有重复地址，返回已存在的地址
        if (!empty($existingAddresses)) {
            $postData = [
                'chat_id' => $chatId,
                'text' => "以下地址已经存在：\n" . implode("\n", $existingAddresses),
                'parse_mode' => 'HTML'
            ];
            send($bot['bot_token'], 'sendMessage', $postData);
        }
    
        // 如果没有新地址，也可以提示
        if (empty($newAddresses) && empty($existingAddresses)) {
            $postData = [
                'chat_id' => $chatId,
                'text' => "没有有效的地址需要添加。",
                'parse_mode' => 'HTML'
            ];
            send($bot['bot_token'], 'sendMessage', $postData);
        }
    }

    
    private function delAddress($text, $chatId, $fromUserId)
    {
        // 获取缓存中的 bot 信息
        $bot = Cache::store('redis')->get($this->cacheBot);
    
      
    
        // 去掉 'del+' 前缀并分割地址
        $addresses = explode("\n", substr($text, 4));
    
        // 如果没有地址，返回错误
        if (empty($addresses)) {
             // 发送成功的通知
            $postData = [
                'chat_id' => $chatId,
                'text' => "请输入有效的地址！",
                'parse_mode' => 'HTML'
            ];
        
            send($bot['bot_token'], 'sendMessage', $postData);
            return;
        }
        $num=0;
        // 遍历地址列表并删除相关记录
        foreach ($addresses as $address) {
            $address = trim($address); // 去掉地址前后空格
    
            // 如果地址为空，跳过
            if (empty($address)) {
                continue;
            }
    
            // 从数据库中删除地址
            $result = Db::name('arbnotice')->where('address', $address)->delete();
    
            // 如果删除失败，提示用户
            if ($result) {
                $num++;
                // 删除缓存中的相关数据
                $cacheKeyadd = "arbiscan_address_info_{$chatId}_{$address}";
                Cache::store('redis')->delete($cacheKeyadd);
        
                $cacheKey = 'arbiscan_processed_' . $address;
                Cache::store('redis')->delete($cacheKey);
        
                $txCacheKey = 'arbiscan_latest_tx_' . $address;
                Cache::store('redis')->delete($txCacheKey);
            }
            
        }
        if($num==0){
        $postData = [
                    'chat_id' => $chatId,
                    'text' => "地址 {$address} 删除失败，地址未找到或已删除！",
                    'parse_mode' => 'HTML'
                ];
            
        }else{ 
                
        // 发送成功的通知
        $postData = [
            'chat_id' => $chatId,
            'text' => "已成功删除地址。\n\n",
            'parse_mode' => 'HTML'
        ];
        }
        send($bot['bot_token'], 'sendMessage', $postData);
    }

    
    
    
    private function isFormToBot($message,$fromChatId)
    {
        if ($this->isValidToken($message)) {
            return false;
        }elseif (strpos($message, '/') !== false) {
	        return false; // 如果检测到自定义回复，直接返回
	    }else{
	       
    	        $botinfo=Cache::store('redis')->get($this->cacheBot);
    	         echo "message opentwo info: $message".$botinfo['opentwo'];
    	        if($botinfo['opentwo']==1){
    	            if ($botinfo['chat_id'] != $fromChatId) {
        	            return true;
        	        }else{
        	         return false;    
        	        } 
    	        }else{
    	            return false; 
    	        }
    	        
	    
            
        }
        /*if(){
            
        }*/
    }
    	// 处理机器人自身信息
	protected function botMessage($fromChatId, $messageId, $senderId, $truename, $token, $message, $chatType) {
	  
	    if (Cache::store('redis')->has($this->cacheBot)) {
	        $botinfo=Cache::store('redis')->get($this->cacheBot);

	        if ($botinfo['chat_id'] != $fromChatId) {
	            //if($botinfo['clone_type']==1){
	            if ($message == '/applys') {
	               $add_group_url = 'https://t.me/' . $botinfo['bot_name'] . '?startgroup=gsetting';
	                
	                $replyKeyboardMarkup = [
	                    'inline_keyboard' => [
	                       // [['text' => '➕加我到群组', 'url' => $add_group_url]],
	                        [['text' => '🤖克隆机器人', 'callback_data' => '/startclone']],
	                        [['text' => '⚙️申请权限', 'callback_data' => '/Applyforper']],
	                        
	                        [['text' => '🤵️联系客服', 'url' =>"https://t.me/ABCDE988888"]]
	                    ]
	                ];
	                
	                $bot_first_name = $botinfo['first_name'];
	                $encodedMarkup = json_encode($replyKeyboardMarkup, true);
	                
	                $content = [
	                    'chat_id' => $fromChatId,
	                    'reply_markup' => $encodedMarkup,
	                    'text' => "🏠 $truename 你好！\n\n $bot_first_name \n\n申请权限请点击下方按钮",
	                    'parse_mode' => 'HTML'
	                ];
	                
	                send($token, 'sendMessage', $content);
	                
	                
	                $replyKeyboardMarkup = [
                        'keyboard' => [[['text'=>'申请权限']]],
                        'resize_keyboard'=>true,
                        'one_time_keyboard'=>true,
                        ];
                    $encodedMarkup = json_encode($replyKeyboardMarkup,true);
                    $content = array(
                        'chat_id' => $fromChatId,
                        'reply_markup' => json_encode((object)[]),
                        //'text' => "申请录入信息权限",
                        //
                    );
                    send($token,'sendMessage', $content);
                    return;
	            }
	           if ($message == '/addressq') {
	               if($botinfo['addressjc']==1){
	                  $replyKeyboardMarkup = [
	                    'inline_keyboard' => [
	                       // [['text' => '➕加我到群组', 'url' => $add_group_url]],
	                        [['text' => '💠地址监测', 'callback_data' => '/addressjc_set']],
	                        
	                      
	                    ]
	                ]; 
	               }
	                
	                $bot_first_name = $botinfo['first_name'];
	                $encodedMarkup = json_encode($replyKeyboardMarkup, true);
	                
	                $content = [
	                    'chat_id' => $fromChatId,
	                    'reply_markup' => $encodedMarkup,
	                    'text' => "🏠 $truename 你好！\n\n $bot_first_name \n\n请点击下方按钮操作",
	                    'parse_mode' => 'HTML'
	                ];
	                
	                send($token, 'sendMessage', $content);
	                return;
	           } 
	           // }
	        } else {
	            if ($message == '/start') {
	                $data = [
	                    'commands' => json_encode([
	                        ['command' => 'start', 'description' => '启动'],
	                    ])
	                ];
	                // 发送请求
	                $rss = send($token, 'setMyCommands', $data);
	            
	            }
	            if($this->isCommand($message)){
	            if ($this->checkfortelemessage->checkForTelemessage($fromChatId, $chatType, $message, $senderId, $token, $messageId)) {
	                return; // 如果检测到自定义回复，直接返回
	            }
	            }
	            if($this->handleBanwordCommands($message, $token, $fromChatId, $senderId, $messageId)){
                      return;
                  }
                  
	            if($this->cacheInfoToData($senderId,$fromChatId,$message,$token,$messageId)){
                    return;
                }
	        }
	    }
	}
	// 判断是否为指令
    private function isCommand($message)
    {
        // 判断是否以斜杠 / 开头并且后跟字母或数字
        if (preg_match('/^\/[a-zA-Z0-9]+/', $message)) {
            return true;  // 是指令
        }
        return false;  // 不是指令
    }
    
    // 发送克隆机器人指引
    private function sendCloneInstructions($token, $fromChatId)
    {
        $replyMarkup = json_encode([
            'inline_keyboard' => [[
                ['text' => '❌关闭', 'callback_data' => '/closeMessage']
            ]]
        ]);
        $content = [
            'chat_id' => $fromChatId,
            'reply_markup' => $replyMarkup,
            'text' => "👉 按照以下流程进行机器人克隆：\n1. 打开 @BotFather\n2. 发送 /newbot\n3. 按指引设置机器人名字，可中文\n4. 设置机器人username，英文+数字，需要以bot结尾\n5. 创建完成后将注册好的token发送给我\n\ntoken格式：\n6422100000:AAFMTBWko3t7gA3mN5SRYp5FuYcxxxxxxxxx",
        ];
        send($token, 'sendMessage', $content);
    }

    // 切换双向通信状态
    private function toggleTwoWayCommunication($token, $fromChatId)
    {
        $bot = Cache::store('redis')->get($this->cacheBot);
        $str = $bot['opentwo'] ? '已开启双向，如果需要关闭请点击下方按钮' : '已关闭双向，如果需要开启请点击下方按钮';
        $bs = $bot['opentwo'] ? '关闭双向' : '开启双向';
        
        $replyMarkup = json_encode([
            'inline_keyboard' => [[
                ['text' => "🔛$bs", 'callback_data' => '/openTwo-way'],
                ['text' => '❌关闭', 'callback_data' => '/closeMessage']
            ]]
        ]);

        $content = [
            'chat_id' => $fromChatId,
            'reply_markup' => $replyMarkup,
            'text' => "🔛开启双向\n\n此机器人$str",
        ];
        send($token, 'sendMessage', $content);
    }

    // 处理禁用词命令
    private function handleBanwordCommands($message, $token, $fromChatId, $senderId, $messageId)
    {
        $waiting = 'waiting_for_message';

        // 检查添加和删除禁用词的状态
       if($this->processBanwordAdd($message, $token, $fromChatId, $senderId, $messageId,  $waiting)) { return true;} 
       if($this->processBanwordDelete($message, $token, $fromChatId, $senderId, $messageId,  $waiting)) { return true;} 
       
       if($this->handleGroupCommands($message, $token, $fromChatId, $senderId, $messageId,  $waiting)) { return true;} 
       
       if($this->handleKeyword($message, $token, $fromChatId, $senderId, $messageId,  $waiting)) { return true;} 
      
    }
    
    
    private function cacheInfoToData($userId,$chat_id,$message,$token,$messageId){
	   $botinfo=Cache::store('redis')->get($this->cacheBot);
	   $bot_id=$botinfo['bot_id'];
        ///////banwodadd add_status
                $waiting='waiting_for_message';
                
                $redisKeycustomadd = "customadd:$bot_id.txt:add_status";
				$redisHashKeycustomadd = "customadd:$bot_id.txt:addmessage";
                if ($this->redis->sismember($redisKeycustomadd, $waiting)) {
                       $string = $this->redis->hget($redisHashKeycustomadd, $waiting);
                       $parts = explode('_', $string);
                       $newMessageId= $parts[0]; // 固定为 00:00
                       $bgid =$parts[1];   
                       
                       
                       $this->addCustom($message,$chat_id,$userId,$token,$messageId,$bgid);
                       $content = array(
                           'chat_id' => $chat_id,
                           'message_id' => $newMessageId
                       );
                       
                       send($token, 'deleteMessage', $content);
                          $bwButtons[] = [[
                                'text' => '🔙 返回',
                                'callback_data' => '/custom_message_start:'.$bgid
                            ],[
                                'text' => '❌关闭',
                                'callback_data' => '/closeMessage'
                            ]];
                            $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                            
        		            $content = [
                                'chat_id' => $chat_id,
                                'text' => "🕑 重发消息\n\n\👉🏻 设置成功。\n\n ",
                                'reply_markup' => $replyMarkup,
                                'message_id' => $messageId,
                                'parse_mode' => 'HTML' 
                            ];
                
                     // 将 Token 和 Chat ID 推送到队列进行异步处理
                     // 给用户发送一条消息，确认 Token 已提交
                     send($token,'sendMessage', $content);
                     
                      // 从集合中删除指定的 $data
                     $this->redis->srem($redisKeycustomadd,  $waiting);
					$this->redis->del($redisHashKeycustomadd);
                     
                     return;
                }        

                $redisKeycustomedit = "customedit:$bot_id.txt:add_status";
				$redisHashKeycustomedit = "customedit:$bot_id.txt:addmessage";
                if ($this->redis->sismember($redisKeycustomedit, $waiting)) {
                    $hashMessageId = $this->redis->hget($redisHashKeycustomedit, $waiting);
                   
                   
                    if ($hashMessageId) {
                        $parts = explode('_', $hashMessageId);
                        $newMessageId= $parts[0]; // 固定为 00:00
                         $id =$parts[1];
                        Db::name('xiaoxi')->where('id', $id)->update(['content'=>$message]);
                      
                    
                        $content = array(
                            'chat_id' => $chat_id,
                            'message_id' => $newMessageId
                        );
                        
                        send($token, 'deleteMessage', $content);
                          $bgid=Db::name('xiaoxi')->where('id', $id)->value('bgid');
                          $bwButtons[] = [[
                                'text' => '🔙 返回',
                                'callback_data' => '/custom_message_info_id:'.$id."_".$bgid
                            ],[
                                'text' => '❌关闭',
                                'callback_data' => '/closeMessage'
                            ]];
                            $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                            
        		            $content = [
                                'chat_id' => $chat_id,
                                'text' => "🕑 重发消息\n\n\👉🏻 修改成功。\n\n ",
                                'reply_markup' => $replyMarkup,
                                'message_id' => $newMessageId,
                                'parse_mode' => 'MarkdownV2' 
                            ];
                
                     // 将 Token 和 Chat ID 推送到队列进行异步处理
                     // 给用户发送一条消息，确认 Token 已提交
                     send($token,'sendMessage', $content);
                    
                      // 从集合中删除指定的 $data
                     $this->redis->srem($redisKeycustomedit,  $waiting);
                     
                     return;
                    }   
                }
                

                
                $redisKeyxxButtonadd = "xxButtonadd:$bot_id:add_status";
				$redisHashKeyxxButtonadd = "xxButtonadd:$bot_id:addmessage";
                if ($this->redis->sismember($redisKeyxxButtonadd, $waiting)) {
                         $hashMessageId = $this->redis->hget($redisHashKeyxxButtonadd, $waiting);
                         $parts = explode('_', $hashMessageId);
                         $newMessageId= $parts[0]; // 固定为 00:00
                         $bgid =$parts[1];
                        $isadd=$this->xxButtonadd($message,$bgid);
						
                       $hashMessageId = $this->redis->hget($redisHashKeyxxButtonadd, $waiting);
                       $content = array(
                           'chat_id' => $chat_id,
                           'message_id' => $hashMessageId
                       );
                       
                       send($token, 'deleteMessage', $content);
                       $buttons = explode("\n", trim($message));
                        $bwButtons = []; // 按钮格式化数组
                        foreach ($buttons as $button) {
                            $parts = explode('#', $button);
                            if (count($parts) === 2) {
                                $bwButtons[] = [[
                                    'text' => $parts[0], // 按钮文本
                                    'url' => $parts[1]  // 按钮链接
                                ]];
                            }
                        }
                          $bwButtons[] = [[
                                'text' => '🔙 返回',
                                'callback_data' => '/custom_message_setting:'.$bgid
                            ]];
                            $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                            
        		            $content = [
                                'chat_id' => $chat_id,
                                'text' => "❇️按钮添加成功，效果如下",
                                'reply_markup' => $replyMarkup,
                                'message_id' => $messageId,
                                'parse_mode' => 'MarkdownV2' 
                            ];
                
                     // 将 Token 和 Chat ID 推送到队列进行异步处理
                     // 给用户发送一条消息，确认 Token 已提交
                     $rss=send($token,'sendMessage', $content);
                    log::write($rss);
                      // 从集合中删除指定的 $data
                     $this->redis->srem($redisKeyxxButtonadd,  $waiting);
					 $this->redis->del($redisHashKeyxxButtonadd);
                     return;
                }
                
                $redisKeyxxButtonedit = "xxButtonedit:$bot_id:add_status";
                $redisHashKeyxxButtonedit = "xxButtonedit:$bot_id:addmessage";
                if ($this->redis->sismember($redisKeyxxButtonedit, $waiting)) {
                    $hashMessageId = $this->redis->hget($redisHashKeyxxButtonedit, $waiting);
                    if ($hashMessageId) {
                        $parts = explode('_', $hashMessageId);
                        $newMessageId= $parts[0]; // 固定为 00:00
                         $id =$parts[1];
                        Db::name('xiaoxi')->where('id', $id)->update(['buttonset'=>$message]);
                      
                        $bgid=Db::name('xiaoxi')->where('id', $id)->value('bgid');
                        $content = array(
                            'chat_id' => $chat_id,
                            'message_id' => $newMessageId
                        );
                        
                        send($token, 'deleteMessage', $content);
                          $bwButtons[] = [[
                                'text' => '🔙 返回',
                                'callback_data' => '/custom_message_info_id:'.$id."_".$bgid
                            ]];
                            $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                            
        		            $content = [
                                'chat_id' => $chat_id,
                                'text' => "🕑 重发消息\n\n\👉🏻 按钮修改成功。\n\n",
                                'reply_markup' => $replyMarkup,
                                'message_id' => $newMessageId,
                                'parse_mode' => 'MarkdownV2' 
                            ];
                
                     // 将 Token 和 Chat ID 推送到队列进行异步处理
                     // 给用户发送一条消息，确认 Token 已提交
                     send($token,'sendMessage', $content);
                     
                      // 从集合中删除指定的 $data
                     $this->redis->srem($redisKeyxxButtonedit,  $waiting);
                     $this->redis->del($redisHashKeyxxButtonedit);
                     
                     return;
                    }   
                }
                
                $redisKeyxxbotgroupadd = "botgroupadd:$bot_id:add_status";
                $redisHashKeyxxbotgroupadd = "botgroupadd:$bot_id:addmessage";
                if ($this->redis->sismember($redisKeyxxbotgroupadd, $waiting)) {
                        $isadd=$this->botgroupadd($message,$chat_id);
						
                       $hashMessageId = $this->redis->hget($redisHashKeyxxbotgroupadd, $waiting);
                       $content = array(
                           'chat_id' => $chat_id,
                           'message_id' => $hashMessageId
                       );
                       
                       send($token, 'deleteMessage', $content);
                      
                          $bwButtons[] = [[
                                'text' => '🔙 返回',
                                'callback_data' => '/group_setting'
                            ]];
                            $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                            
        		            $content = [
                                'chat_id' => $chat_id,
                                'text' => "添加成功：",
                                'reply_markup' => $replyMarkup,
                                'message_id' => $messageId,
                                'parse_mode' => 'MarkdownV2' 
                            ];
                
                     // 将 Token 和 Chat ID 推送到队列进行异步处理
                     // 给用户发送一条消息，确认 Token 已提交
                     $rss=send($token,'sendMessage', $content);
                  
                      // 从集合中删除指定的 $data
                     $this->redis->srem($redisKeyxxbotgroupadd,  $waiting);
					 $this->redis->del($redisHashKeyxxbotgroupadd);
                     return;
                }
                
                $redisKeybotgroupedit = "botgroupedit:$bot_id:add_status";
                $redisHashKeybotgroupedit = "botgroupedit:$bot_id:addmessage";
                if ($this->redis->sismember($redisKeybotgroupedit, $waiting)) {
                    $hashMessageId = $this->redis->hget($redisHashKeybotgroupedit, $waiting);
                    if ($hashMessageId) {
                        $parts = explode('_', $hashMessageId);
                        $newMessageId= $parts[0]; // 固定为 00:00
                         $id =$parts[1];
                        $isadd=$this->botgroupedit($message,$chat_id,$id);
                    
                        $content = array(
                            'chat_id' => $chat_id,
                            'message_id' => $newMessageId
                        );
                        
                        send($token, 'deleteMessage', $content);
                          $bwButtons[] = [[
                                'text' => '🔙 返回',
                                'callback_data' => '/group_setting'
                            ]];
                            $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                            
        		            $content = [
                                'chat_id' => $chat_id,
                                'text' => "群组编辑\n\n\👉🏻 修改成功。\n\n",
                                'reply_markup' => $replyMarkup,
                                'message_id' => $newMessageId,
                                'parse_mode' => 'MarkdownV2' 
                            ];
                
                     // 将 Token 和 Chat ID 推送到队列进行异步处理
                     // 给用户发送一条消息，确认 Token 已提交
                     send($token,'sendMessage', $content);
                     
                      // 从集合中删除指定的 $data
                     $this->redis->srem($redisKeybotgroupedit,  $waiting);
                     $this->redis->del($redisHashKeybotgroupedit);
                     
                     return;
                    }   
                }
                
                $redisKeykwfilter = "kwfilter:$bot_id:add_status";
				$redisHashKeykwfilter = "kwfilter:$bot_id:addmessage";
                if ($this->redis->sismember($redisKeykwfilter, $waiting)) {
                       $string = $this->redis->hget($redisHashKeykwfilter, $waiting);
                       $parts = explode('_', $string);
                       $newMessageId= $parts[0]; // 固定为 00:00
                       $bgid =$parts[1];  
                       $content = array(
                           'chat_id' => $chat_id,
                           'message_id' => $newMessageId
                       );
                       $redisKey = "kwfilter:$bot_id:".$bgid.":add_status";
                       $cahcedata=[
                           "bgid"=>$bgid,
                           "message"=>$message,
                          ];
                       Cache::store('redis')->set($redisKey, $cahcedata, 1200);
                       
                       send($token, 'deleteMessage', $content);
                          $bwButtons = [[[
                                'text' => '删除消息',
                                'callback_data' => '/kwfilter_xiaoxi_word_adddel:'.$bgid
                            ]],[[
                                'text' => '踢出群组',
                                'callback_data' => '/kwfilter_xiaoxi_word_addgouout:'.$bgid
                            ]],[[
                                'text' => '封禁用户',
                                'callback_data' => '/kwfilter_xiaoxi_word_addnosay:'.$bgid
                            ]],[[
                                'text' => '❌关闭',
                                'callback_data' => '/closeMessage'
                            ]]];
                            $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                            
        		            $content = [
                                'chat_id' => $chat_id,
                                'text' => "🕑 消息过滤规则\n$message\n👉🏻 请选择:\n\n ",
                                'reply_markup' => $replyMarkup,
                                'message_id' => $messageId,
                                'parse_mode' => 'HTML' 
                            ];
                
                     // 将 Token 和 Chat ID 推送到队列进行异步处理
                     // 给用户发送一条消息，确认 Token 已提交
                     send($token,'sendMessage', $content);
                     
                      // 从集合中删除指定的 $data
                     $this->redis->srem($redisKeykwfilter,  $waiting);
					$this->redis->del($redisHashKeykwfilter);
                     
                     return;
                }
                
                $redisKeykwfiltermz = "kwfiltermz:$bot_id:add_status";
				$redisHashKeykwfiltermz = "kwfiltermz:$bot_id:addmessage";
                if ($this->redis->sismember($redisKeykwfiltermz, $waiting)) {
                       $string = $this->redis->hget($redisHashKeykwfiltermz, $waiting);
                       $parts = explode('_', $string);
                       $newMessageId= $parts[0]; // 固定为 00:00
                       $bgid =$parts[1];  
                       $content = array(
                           'chat_id' => $chat_id,
                           'message_id' => $newMessageId
                       );
                       $redisKey = "kwfiltermz:$bot_id:".$bgid.":add_status";
                       $cahcedata=[
                           "bgid"=>$bgid,
                           "message"=>$message,
                          ];
                       Cache::store('redis')->set($redisKey, $cahcedata, 1200);
                       
                       send($token, 'deleteMessage', $content);
                          $bwButtons = [[[
                                'text' => '删除消息',
                                'callback_data' => '/kwfilter_mingzi_word_adddel:'.$bgid
                            ]],[[
                                'text' => '踢出群组',
                                'callback_data' => '/kwfilter_mingzi_word_addgouout:'.$bgid
                            ]],[[
                                'text' => '封禁用户',
                                'callback_data' => '/kwfilter_mingzi_word_addnosay:'.$bgid
                            ]],[[
                                'text' => '❌关闭',
                                'callback_data' => '/closeMessage'
                            ]]];
                            $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                            
        		            $content = [
                                'chat_id' => $chat_id,
                                'text' => "🕑 名字过滤规则\n$message \n👉🏻 请选择:\n\n ",
                                'reply_markup' => $replyMarkup,
                                'message_id' => $messageId,
                                'parse_mode' => 'HTML' 
                            ];
                
                     // 将 Token 和 Chat ID 推送到队列进行异步处理
                     // 给用户发送一条消息，确认 Token 已提交
                     send($token,'sendMessage', $content);
                     
                      // 从集合中删除指定的 $data
                     $this->redis->srem($redisKeykwfiltermz,  $waiting);
					$this->redis->del($redisHashKeykwfiltermz);
                     
                     return;
                }
                
                $redisKeykwfilterdel = "kwfilterdel:$bot_id:add_status";
				$redisHashKeykwfilterdel = "kwfilterdel:$bot_id:addmessage";
                if ($this->redis->sismember($redisKeykwfilterdel, $waiting)) {
                       $string = $this->redis->hget($redisHashKeykwfilterdel, $waiting);
                       $parts = explode('_', $string);
                       $newMessageId= $parts[0]; // 固定为 00:00
                       $bgid =$parts[1];  
                       $content = array(
                           'chat_id' => $chat_id,
                           'message_id' => $newMessageId
                       );
                       send($token, 'deleteMessage', $content);
                       
                       $this->delkwfilter($message, $bot_id,$bgid);
                          $bwButtons = [[[
                                'text' => '🔙 返回',
                                'callback_data' => '/group_setting_botquninfo:'.$bgid
                            ]]];
                            $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                            
        		            $content = [
                                'chat_id' => $chat_id,
                                'text' => "过滤规则\nID $message \n👉🏻 删除成功\n\n ",
                                'reply_markup' => $replyMarkup,
                                'message_id' => $messageId,
                                'parse_mode' => 'HTML' 
                            ];
                
                     // 将 Token 和 Chat ID 推送到队列进行异步处理
                     // 给用户发送一条消息，确认 Token 已提交
                     send($token,'sendMessage', $content);
                     
                      // 从集合中删除指定的 $data
                     $this->redis->srem($redisKeykwfilterdel,  $waiting);
					$this->redis->del($redisHashKeykwfilterdel);
                     
                     return;
                }
    }
    
    
    //群组信息处理
    private function handleGroupCommands($message, $token, $fromChatId, $senderId, $messageId,$waiting)
    {
       // 检查修改的状态
       if($this->processGroupPhotoDesAdd($message, $token, $fromChatId, $senderId, $messageId,  $waiting)) { return true;} 
    }
    
     //群组信息图片
    private function handleXXphoto($message, $token, $fromChatId, $senderId, $messageId,$waiting,$caption)
    {
        
         $botinfo=Cache::store('redis')->get($this->cacheBot);
         $bot_id=$botinfo['bot_id'];
                $redisKeycustomaddphoto = "customadd:$bot_id.photo:add_status";
				$redisHashKeycustomaddphoto = "customadd:$bot_id.photo:addmessage";
                if ($this->redis->sismember($redisKeycustomaddphoto, $waiting)) {
                     $string = $this->redis->hget($redisHashKeycustomaddphoto, $waiting);
                      $parts = explode('_', $string);
                       $newMessageId= $parts[0]; // 固定为 00:00
                       $bgid =$parts[1];   
                    
                       
                      $xxsetting=Db::name('xxsetting')->where('bgid',$bgid)->find();
                  
                       $data['send_time']=$xxsetting['send_time'];
                       $data['nexttime']=$xxsetting['send_time'];
                       $data['buttonset']=$xxsetting['buttonset'];
                       $data['content']=$caption;
                       $data['photo']=$message;
                       $data['is_top']=$xxsetting['is_top'];
                       if($xxsetting['repeat_interval']>0){
                       $data['repeat_interval']=$xxsetting['repeat_interval'];
                       }else{
                          $data['repeat_interval']=1440; 
                       }
                       $data['status']=0;
                       $data['create_time']=time();
                       $data['username']=$senderId;
                       $data['group_id']=$fromChatId;
                       $data['token']=$token;
                       $data['bot_id']=$bot_id;
                       $data['bgid']=$bgid;
                       $data['message_id']=$messageId;
                       $rs=Db::name('xiaoxi')->insert($data); 
                        if($rs) {
                            Db::name('xxsetting')->where('user_id',$botinfo['bot_id'])->where('bgid',$bgid)->update(['send_time'=>null,'repeat_interval'=>null,'is_top'=>0,'is_del'=>0,'buttonset'=>null]);
                        }
                     
                        
                        $content = array(
                            'chat_id' => $fromChatId,
                            'message_id' => $newMessageId
                        );
                        
                        send($token, 'deleteMessage', $content);
                          $bwButtons[] = [[
                                'text' => '🔙 返回',
                                'callback_data' => '/group_setting_botquninfo:'.$bgid
                            ],[
                                'text' => '❌关闭',
                                'callback_data' => '/closeMessage'
                            ]];
                            $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                            
        		            $content = [
                                'chat_id' => $fromChatId,
                                'text' => "🕑 重发消息\n\n\👉🏻 设置成功。",
                                'reply_markup' => $replyMarkup,
                                'message_id' => $messageId,
                                'parse_mode' => 'MarkdownV2' 
                            ];
                
                     // 将 Token 和 Chat ID 推送到队列进行异步处理
                     // 给用户发送一条消息，确认 Token 已提交
                     send($token,'sendMessage', $content);
                     
                      // 从集合中删除指定的 $data
                     $this->redis->srem($redisKeycustomaddphoto,  $waiting);
					$this->redis->del($redisHashKeycustomaddphoto);
                     
                     return;
                }        

                $redisKeycustomedit = "customedit:$bot_id.photo:add_status";
                $redisHashKeycustomedit = "customedit:$bot_id.photo:addmessage";
                if ($this->redis->sismember($redisKeycustomedit, $waiting)) {
                    $hashMessageId = $this->redis->hget($redisHashKeycustomedit, $waiting);
                    if ($hashMessageId) {
                        $parts = explode('_', $hashMessageId);
                        $newMessageId= $parts[0]; // 固定为 00:00
                         $id =$parts[1];
                        Db::name('xiaoxi')->where('id', $id)->update(['content'=>$caption,'photo'=>$message]);
                        $content = array(
                            'chat_id' => $fromChatId,
                            'message_id' => $newMessageId
                        );
                        
                        send($token, 'deleteMessage', $content);
                         $bgid=Db::name('xiaoxi')->where('id', $id)->value('bgid');
                          $bwButtons[] = [[
                                'text' => '🔙 返回',
                                'callback_data' => '/custom_message_info_id:'.$id."_".$bgid
                            ],[
                                'text' => '❌关闭',
                                'callback_data' => '/closeMessage'
                            ]];
                            $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                            
        		            $content = [
                                'chat_id' => $fromChatId,
                                'text' => "🕑 重发消息\n\n\👉🏻 修改成功。",
                                'reply_markup' => $replyMarkup,
                                'message_id' => $newMessageId,
                                'parse_mode' => 'MarkdownV2' 
                            ];
                
                     // 将 Token 和 Chat ID 推送到队列进行异步处理
                     // 给用户发送一条消息，确认 Token 已提交
                     send($token,'sendMessage', $content);
                     
                      // 从集合中删除指定的 $data
                     $this->redis->srem($redisKeycustomedit,  $waiting);
                     $this->redis->del($redisHashKeycustomedit);
                     
                     return;
                    }   
                }
                
    }
    
    // 处理添加禁用词
    private function processBanwordAdd($message, $token, $fromChatId, $senderId, $messageId,  $waiting)
    {
         $bot = Cache::store('redis')->get($this->cacheBot);
		 $bot_id=$bot['bot_id'];
        $redisKey = "banwordadd:notsay.$bot_id:add_status";
        $redisHashKey = "banwordadd:notsay.$bot_id:addmessage";
        
        
        if ($this->redis->sismember($redisKey, $waiting)) {
           
            
            $this->addBanwords($message, $bot['bot_id'], $bot['duration'], 1);
            $this->sendBanwordSuccessMessage($token, $fromChatId, $messageId, "违禁词禁言\n\n$message\n\n*添加成功*！", $redisKey, $waiting,$redisHashKey);
            
            return true;
        }
        
        $redisKeygoout = "banwordadd:goout.$bot_id:add_status";
        $redisHashKeygoout = "banwordadd:goout.$bot_id:addmessage";
        
        if ($this->redis->sismember($redisKeygoout, $waiting)) {
            $this->addBanwords($message, $bot['bot_id'], $bot['duration'], 2);
            $this->sendBanwordSuccessMessage($token, $fromChatId, $messageId, "违禁词踢出\n\n$message\n\n添加成功！", $redisKeygoout, $waiting,$redisHashKeygoout);
            return true;
        }
    }
    
    // 处理关键词回复
    private function handleKeyword($message, $token, $fromChatId, $senderId, $messageId,  $waiting)
    {
        $botinfo=Cache::store('redis')->get($this->cacheBot);
        $bot_id=$botinfo['bot_id'];
        
        $redisKey = "keywordadd:$bot_id:add_status";
        $redisHashKey = "keywordadd:$bot_id:addmessage";
        
        
        if ($this->redis->sismember($redisKey, $waiting)) {
            $hashMessageId = $this->redis->hget($redisHashKey, $waiting);
            $parts = explode('_', $hashMessageId);
            $newMessageId= $parts[0]; // 固定为 00:00
            $bgid =$parts[1];
            $this->addKeyword($message, $bot_id,$newMessageId,$bgid);
            $this->sendKeywordSuccessMessage($token, $fromChatId, $messageId, "关键词\n\n*添加成功*！", $redisKey, $waiting,$redisHashKey);
            
            return true;
        }
        
        $redisKeygoout = "keyworddel:$bot_id:add_status";
        $redisHashKeygoout = "keyworddel:$bot_id:addmessage";
        
        if ($this->redis->sismember($redisKeygoout, $waiting)) {
            $hashMessageId = $this->redis->hget($redisHashKeygoout, $waiting);
            $parts = explode('_', $hashMessageId);
            $newMessageId= $parts[0]; // 固定为 00:00
            $bgid =$parts[1];
            $this->delKeyword($message, $bot_id,$newMessageId,$bgid);
            $this->sendKeywordSuccessMessage($token, $fromChatId, $messageId, "关键词\n\n删除成功！", $redisKeygoout, $waiting,$redisHashKeygoout);
            return true;
        }
    }
    
    // 处理删除禁用词
    private function processBanwordDelete($message, $token, $fromChatId, $senderId, $messageId,  $waiting)
    {
        $bot = Cache::store('redis')->get($this->cacheBot);
		$bot_id=$bot['bot_id'];
        $redisKey = "banwordadd:notsay.$bot_id:del_status";
        $redisHashKey = "banwordadd:notsay.$bot_id:delmessage";
        if ($this->redis->sismember($redisKey, $waiting)) {
            
            $this->delBanwords($message, $bot['bot_id']);
            $this->sendBanwordSuccessMessage($token, $fromChatId, $messageId, "违禁词\n\n$message\n\n*删除成功*！", $redisKey, $waiting,$redisHashKey);
            return true;
        }
        $redisKeygoout = "banwordadd:goout.$bot_id:del_status";
        $redisHashKeygoout = "banwordadd:goout.$bot_id:delmessage";
        if ($this->redis->sismember($redisKeygoout, $waiting)) {
           
            $this->delBanwords($message, $bot['bot_id']);
            $this->sendBanwordSuccessMessage($token, $fromChatId, $messageId, "违禁词\n\n$message\n\n*删除成功*！", $redisKeygoout, $waiting,$redisHashKeygoout);
            return true;
        }
    }

    // 发送违禁词成功信息
    private function sendBanwordSuccessMessage($token, $fromChatId, $messageId, $successMessage, $redisKey, $waiting,$redisHashKey)
    {
       
        $hashMessageId = $this->redis->hget($redisHashKey, $waiting);
        $content = [
            'chat_id' => $fromChatId,
            'message_id' => $hashMessageId
        ];
        send($token, 'deleteMessage', $content);
        
        $replyMarkup = json_encode([
            'inline_keyboard' => [[
                ['text' => '🔙 返回', 'callback_data' => '/banWords'],
                ['text' => '❌关闭', 'callback_data' => '/closeMessage']
            ]]
        ]);

        $content = [
            'chat_id' => $fromChatId,
            'text' => $successMessage,
            'reply_markup' => $replyMarkup,
            'message_id' => $messageId,
            'parse_mode' => 'MarkdownV2'
        ];
        $rrs=send($token, 'sendMessage', $content);
     
        $this->redis->srem($redisKey, $waiting);
        $this->redis->del($redisHashKey);
    }
    //处理群组头像和介绍
    private function processGroupPhotoDesAdd($message, $token, $fromChatId, $senderId, $messageId,  $waiting)
    {
		$bot=Cache::store('redis')->get($this->cacheBot);
		$bot_id=$bot['bot_id'];
        $redisKey = "gphoto:$bot_id:add_status";
        $redisHashKey = "gphoto:$bot_id:addmessage";
        
        if ($this->redis->sismember($redisKey, $waiting)) {
            $hashMessageId = $this->redis->hget($redisHashKey, $waiting);
            $parts = explode('_', $hashMessageId);
            $newMessageId= $parts[0]; // 固定为 00:00
            $bgid =$parts[1];
            
            $str=$this->editGroupInfo($message, $bot['bot_id'],$token, 1,$newMessageId,$bgid);
            $this->sendGroupInfoSuccessMessage($token, $fromChatId, $messageId, "更新群组头像\n\n*修改成功*！\n\n$str", $redisKey, $waiting,$redisHashKey);
            
            return true;
        }
        
        $redisKeygoout = "gdes:$bot_id:add_status";
        $redisHashKeygoout = "gdes:$bot_id:addmessage";
        
        if ($this->redis->sismember($redisKeygoout, $waiting)) {
            $hashMessageId = $this->redis->hget($redisHashKeygoout, $waiting);
            $parts = explode('_', $hashMessageId);
            $newMessageId= $parts[0]; 
            $bgid =$parts[1];
            
            $str= $this->editGroupInfo($message, $bot['bot_id'],$token, 2,$newMessageId,$bgid);
            $this->sendGroupInfoSuccessMessage($token, $fromChatId, $messageId, "更新群组介绍\n\n*修改成功*！\n\n$str", $redisKeygoout, $waiting,$redisHashKeygoout);
            return true;
        }
    }
    
     // 发送关键词成功信息
    private function sendKeywordSuccessMessage($token, $fromChatId, $messageId, $successMessage, $redisKey, $waiting,$redisHashKey)
    {
        $hashMessageId = $this->redis->hget($redisHashKey, $waiting);
        $parts = explode('_', $hashMessageId);
        $newMessageId= $parts[0]; // 固定为 00:00
        $bgid =$parts[1];
        $content = [
            'chat_id' => $fromChatId,
            'message_id' => $newMessageId
        ];
        send($token, 'deleteMessage', $content);
        
        $replyMarkup = json_encode([
            'inline_keyboard' => [[
                ['text' => '🔙 返回', 'callback_data' => '/keyworkauto_Reply:'.$bgid],
                ['text' => '❌关闭', 'callback_data' => '/closeMessage']
            ]]
        ]);

        $content = [
            'chat_id' => $fromChatId,
            'text' => $successMessage,
            'reply_markup' => $replyMarkup,
            'parse_mode' => 'MarkdownV2'
        ];
        $rrs=send($token, 'sendMessage', $content);
        $this->redis->srem($redisKey, $waiting);
        $this->redis->del($redisHashKey);
    }
    
    private function delkwfilter($text, $bot_id,$bgid) {
	    // 将文本按换行符分割成数组
	    $words = explode("\n", $text);
	    
	    // 去除前后空格并过滤空行
	    $words = array_filter(array_map('trim', $words));
	    
	    // 如果数组为空，直接返回
	    if (empty($words)) {
	        echo "没有需要处理的词条。";
	        return;
	    }
	    
	    // 批量查询数据库中已有的词条
	    $existingWords = Db::name('kwfilter')
	        ->whereIn('id', $words)
	        ->where('bgid', $bgid)
	        ->column('id');
	    
	    // 如果没有匹配的词条，直接返回
	    if (empty($existingWords)) {
	        echo "没有匹配的词条需要删除。";
	        return;
	    }
	    
	    // 批量删除存在的词条，同时匹配指定的 bot_id
	    Db::name('kwfilter')
	        ->whereIn('id', $existingWords)
	        ->where('bgid', $bgid)
	        ->delete();

	    $cachekeywordKey = "kl_bg_kwfilter";
        $cachekeywordData = Db::name('kwfilter')->select()->toArray();
        Cache::store('redis')->set($cachekeywordKey, $cachekeywordData, 3600);   
	}
    
    // 删除关键词
	private function delKeyword($text, $bot_id,$newMessageId,$bgid) {
	    // 将文本按换行符分割成数组
	    $words = explode("\n", $text);
	    
	    // 去除前后空格并过滤空行
	    $words = array_filter(array_map('trim', $words));
	    
	    // 如果数组为空，直接返回
	    if (empty($words)) {
	        echo "没有需要处理的词条。";
	        return;
	    }
	    
	    // 批量查询数据库中已有的词条
	    $existingWords = Db::name('keyword')
	        ->whereIn('keyword', $words)
	        ->where('bot_id', $bot_id)
	        ->where('bgid', $bgid)
	        ->column('keyword');
	    
	    // 如果没有匹配的词条，直接返回
	    if (empty($existingWords)) {
	        echo "没有匹配的词条需要删除。";
	        return;
	    }
	    
	    // 批量删除存在的词条，同时匹配指定的 bot_id
	    Db::name('keyword')
	        ->whereIn('keyword', $existingWords)
	        ->where('bot_id', $bot_id)  // 增加 bot_id 条件
	        ->where('bgid', $bgid)
	        ->delete();

	    $cachekeywordKey = "kl_tg_keyword";
        $cachekeywordData = Db::name('keyword')->select()->toArray();
        Cache::store('redis')->set($cachekeywordKey, $cachekeywordData, 3600);   
	}
	
	// 添加关键词词条
	private function addKeyword($text, $bot_id,$newMessageId,$bgid) {
	    // 将文本按换行符分割成数组
	    $words = explode("\n", $text);
	    // 去除前后空格并过滤空行
	    $words = array_filter(array_map('trim', $words));
	    // 如果数组为空，直接返回
	    if (empty($words)) {
	        echo "没有需要处理的词条。";
	        return;
	    }
	    $keywords = [];
	    
	    foreach ($words as $line) {
            // 将每行的关键词和回复内容分隔开
            $parts = explode('#', $line);
            if (count($parts) === 2) {
                $keywords[] = [
                    'keyword' => trim($parts[0]),
                    'reply' => trim($parts[1]),
                    'bot_id' => $bot_id,
                    'bgid' => $bgid, 
                ];
            }
        }
	    $insertData = [];
	    
	    foreach ($keywords as $item) {
            // 检查关键词是否已经存在
            $existing = Db::name('keyword')->where('bot_id', $bot_id)->where('bgid', $bgid)->where('keyword', $item['keyword'])->find();

            if ($existing) {
                // 如果存在，更新对应的回复内容
                Db::name('keyword')
                    ->where('keyword', $item['keyword'])
                    ->where('bot_id', $bot_id)
                    ->where('bgid', $bgid)
                    ->update(['reply' => $item['reply']]);
            } else {
                // 如果不存在，插入新的记录
                $insertData[] = $item;
            }
        }

        // 批量插入不重复的记录
        if (!empty($insertData)) {
                Db::name('keyword')->insertAll($insertData);
        }
        
		$cachekeywordKey = "kl_tg_keyword";
        $cachekeywordData = Db::name('keyword')->select()->toArray();
        Cache::store('redis')->set($cachekeywordKey, $cachekeywordData, 3600);   
	}
	
    // 其他必要的方法 (botToMember, isValidToken, CloneBot, forwardTobot, addBanwords, delBanwords)...
	// 删除禁用词条
	private function delBanwords($text, $bot_id) {
	    // 将文本按换行符分割成数组
	    $words = explode("\n", $text);
	    
	    // 去除前后空格并过滤空行
	    $words = array_filter(array_map('trim', $words));
	    
	    // 如果数组为空，直接返回
	    if (empty($words)) {
	        echo "没有需要处理的词条。";
	        return;
	    }
	    
	    // 批量查询数据库中已有的词条
	    $existingWords = Db::name('banwords')
	        ->whereIn('word', $words)
	        ->where('bot_id', $bot_id) 
	        ->column('word');
	    
	    // 如果没有匹配的词条，直接返回
	    if (empty($existingWords)) {
	        echo "没有匹配的词条需要删除。";
	        return;
	    }
	    
	    // 批量删除存在的词条，同时匹配指定的 bot_id
	    Db::name('banwords')
	        ->whereIn('word', $existingWords)
	        ->where('bot_id', $bot_id)  // 增加 bot_id 条件
	        ->delete();

	    $cacheBanwordsKey = "kl_tg_banwords";
        $cacheBanwordsData = Db::name('banwords')->where('status', 1)->select()->toArray();
        Cache::store('redis')->set($cacheBanwordsKey, $cacheBanwordsData, 3600);   
	}
	
	//添加按钮链接
	private function xxButtonadd($text,$bgid) {
	    $botinfo=Cache::store('redis')->get($this->cacheBot);
	    $xxsetting=Db::name('xxsetting')->where('user_id',$botinfo['bot_id'])->where('bgid',$bgid)->find();

	    if($xxsetting){
	        $rs=Db::name('xxsetting')->where('user_id',$botinfo['bot_id'])->where('bgid',$bgid)->update(['buttonset'=>$text]);
	        if($rs) {
                return true;
	        }else {return false;}
	    }
	}
	
	// 添加禁用词条
	private function addBanwords($text, $bot_id, $duration, $psid) {
	    // 将文本按换行符分割成数组
	    $words = explode("\n", $text);
	    
	    // 去除前后空格并过滤空行
	    $words = array_filter(array_map('trim', $words));
	    
	    // 如果数组为空，直接返回
	    if (empty($words)) {
	        echo "没有需要处理的词条。";
	        return;
	    }
	    
	    // 批量查询数据库中已有的词条
	    $existingWords = Db::name('banwords')
	        ->whereIn('word', $words)
	        ->where('bot_id', $bot_id) 
	        ->column('word');
	    
	    // 过滤掉已存在的词条
	    $newWords = array_diff($words, $existingWords);
	    
	    // 如果没有新的词条，直接返回
	    if (empty($newWords)) {
	        echo "没有新的词条需要插入。";
	        return;
	    }
	    
	    // 批量插入新的词条到数据库
	    $insertData = [];
	    foreach ($newWords as $word) {
	        if ($psid == 1) {
	            $insertData[] = [
	                'word' => $word,
	                'bot_id' => $bot_id,
	                'duration' => $duration,
	                'create_time' => time(),
	                'psid' => $psid
	            ];
	        } else {
	            $insertData[] = [
	                'word' => $word,
	                'bot_id' => $bot_id,
	                'create_time' => time(),
	                'psid' => $psid
	            ];
	        }
	    }
	    Db::name('banwords')->insertAll($insertData); 
		$cacheBanwordsKey = "kl_tg_banwords";
        $cacheBanwordsData = Db::name('banwords')->where('status', 1)->select()->toArray();
        Cache::store('redis')->set($cacheBanwordsKey, $cacheBanwordsData, 3600);   
	}
	//更新群组信息
	private function editGroupInfo($text,$bot_id,$token, $typeid,$newMessageId,$bgid) {
	    // 批量查询数据库中已有的词条
	    $rs=Db::name('botgroup')->where('id',$bgid)->find();
	    $botinfo=Cache::store('redis')->get($this->cacheBot);
	    $content = [
            'chat_id' => $botinfo['chat_id'],
            'text' => '更新中，请稍后！'
        ];
        send($token, 'sendMessage', $content);
	    
        $node=$rs['node'];
        $groupIds = explode(',', trim($node, ','));
        $groupChatIds = Db::name('telegraggroup')
        ->whereIn('group_id', $groupIds)
        ->where('bot_id', $botinfo['bot_id'])
        ->field('id,group_id, title')
        ->select()
        ->toArray();
        $cshu=0;
        $sshu=0;
	    if($typeid==1){
	        $filePath = $this->getFilePath($token, $text);
            if ($filePath) {
            // 2. 下载文件
                $localFile = $this->downloadFile($token, $filePath);
    	        foreach ($groupChatIds as $chatId) {
            // 调用 Telegram API 的 setChatPhoto 方法
            
                    $postData = [
                        'chat_id' => $chatId['group_id'],
                        'photo' => new \CURLFile(realpath($localFile)) 
                    ];
                    $resp = sendPhoto($token, 'setChatPhoto', $postData);
                    $data = json_decode($resp, true);
                if (isset($data['ok']) && $data['ok'] === false) {
                    $sshu++;
                } else {
                    $cshu++;
                }
               
    	       } 
    	     // unlink($localFile); 
           } 
	    }else{
	        foreach ($groupChatIds as $chatId) {
     
                 $postData = [
                    'chat_id' => $chatId['group_id'],
                    'description' => $text
                ];
                $resp = send($token, 'setChatDescription', $postData);
                $data = json_decode($resp, true);
                if (isset($data['ok']) && $data['ok'] === false) {
                    $sshu++;
                } else {
                    $cshu++;
                }
                
            }   
	    }
	    
	    return "更新成功".$cshu."条,更新失败".$sshu."条";
	}
	// 添加自定义消息
	private function addCustom($text,$groupID,$username,$token,$messageId,$bgid){
	    $botinfo=Cache::store('redis')->get($this->cacheBot);
        $xxsetting=Db::name('xxsetting')->where('bgid',$bgid)->find();
      
           $data['send_time']=$xxsetting['send_time'];
           $data['nexttime']=$xxsetting['send_time'];
           $data['buttonset']=$xxsetting['buttonset'];
           $data['content']=$text;
           $data['is_top']=$xxsetting['is_top'];
           if($xxsetting['repeat_interval']>0){
           $data['repeat_interval']=$xxsetting['repeat_interval'];
           }else{
              $data['repeat_interval']=1440; 
           }
           $data['status']=0;
           $data['create_time']=time();
           $data['username']=$username;
           $data['group_id']=$groupID;
           $data['token']=$token;
           $data['message_id']=$messageId;
           $data['bot_id']=$botinfo['bot_id'];
           $data['bgid']=$bgid;
           $rs=Db::name('xiaoxi')->insert($data); 
            if($rs) {
                Db::name('xxsetting')->where('bgid',$bgid)->update(['send_time'=>null,'repeat_interval'=>null,'is_top'=>0,'is_del'=>0,'buttonset'=>null]);
                return true;
            }else {
                return false;
            }
        
     }
     
     private function botgroupadd($text,$groupID){
	    $botinfo=Cache::store('redis')->get($this->cacheBot);
       $selectedGroupsKey = 'selected_groups_' . $groupID;
       $cachedGroups = Cache::store('redis')->get($selectedGroupsKey);
       $content='';
       if ($cachedGroups !== null && is_array($cachedGroups)) {
            // 循环将缓存数据添加到新的数组中
            foreach ($cachedGroups as $group) {
               $content.=$group.",";
            }
        }
           $data['title']=$text;
           $data['node']=$content;
           $data['bot_id']=$botinfo['bot_id'];
           
           $rsid=Db::name('botgroup')->insertGetId($data); 
            if($rsid) {
              $rsxx = Db::name('xxsetting')->where('bot_id', $botinfo['bot_id'])->where('bgid', $rsid)->find();
                if (!$rsxx) {
                Db::name('xxsetting')->insert([
                        'user_id' => $botinfo['bot_id'],
                        'bgid' => $rsid
                    ]);
                }
                return true;}
            else {return false;}
        
     }
     private function botgroupedit($text,$chat_id,$id){
	    $botinfo=Cache::store('redis')->get($this->cacheBot);
       $selectedGroupsKey = 'selected_groups_' . $chat_id;
       $cachedGroups = Cache::store('redis')->get($selectedGroupsKey);
       $content='';
       if ($cachedGroups !== null && is_array($cachedGroups)) {
            // 循环将缓存数据添加到新的数组中
            foreach ($cachedGroups as $group) {
               $content.=$group.",";
            }
        }
           $data['title']=$text;
           $data['node']=$content;
           
           $rs=Db::name('botgroup')->where("id",$id)->update($data); 
            if($rs) {
              
                return true;}
            else {return false;}
        
     }
	 // 发送群组成功信息
    private function sendGroupInfoSuccessMessage($token, $fromChatId, $messageId, $successMessage, $redisKey, $waiting,$redisHashKey)
    {
      
        $hashMessageId = $this->redis->hget($redisHashKey, $waiting);
        $parts = explode('_', $hashMessageId);
        $newMessageId= $parts[0]; // 固定为 00:00
        $id =$parts[1];
        $content = [
            'chat_id' => $fromChatId,
            'message_id' => $newMessageId
        ];
        send($token, 'deleteMessage', $content);
        
        $replyMarkup = json_encode([
            'inline_keyboard' => [[
                ['text' => '🔙 返回', 'callback_data' => '/group_setting'],
            ]]
        ]);

        $content = [
            'chat_id' => $fromChatId,
            'text' => $successMessage,
            'reply_markup' => $replyMarkup,
            'message_id' => $messageId,
            'parse_mode' => 'MarkdownV2'
        ];
        $rrs=send($token, 'sendMessage', $content);
    
        $this->redis->srem($redisKey, $waiting);
        $this->redis->del($redisHashKey);
    }
	// 将消息转发给机器人创建者 B
	protected function forwardTobot($fromChatId, $messageId, $senderId, $truename, $token, $message, $chatType) {
	    $botinfo=Cache::store('redis')->get($this->cacheBot);
	    // 查询机器人 A 的创建者 B 的 chat_id
	                $messageData = [
	                    'chat_id' => $botinfo['chat_id'],
	                    'from_chat_id' => $fromChatId,
	                    'message_id' => $messageId,  
	                ];
	                $resp = send($token, 'forwardMessage', $messageData);
	}
	
	// 处理用户 机器人 的回复
	public function botToMember($message, $sender_userId, $token) {
	    $messageData = [
	        'chat_id' => $sender_userId,
	        'text' => $message,
	    ];
	    $resp = send($token, 'sendMessage', $messageData);
	}
	
	// 验证 Token 是否有效（这里可以自定义验证规则） 
	private function isValidToken($ytoken) {
	    $istoken = preg_match('/^\d{9,10}:[A-Za-z0-9_-]{35}$/', $ytoken);
	    return $istoken;
	}
	
	// 克隆机器人processCloneBot
	private function processCloneBot($message, $fromChatId, $token, $messageId) {
	    $istokenRs = Db::name('telegrambot')->where('bot_token',$message)->find();
	    
	    if ($istokenRs&&$istokenRs['bot_token']==$message) {
    	        $first_name=$istokenRs['first_name'];
                $msg = "✅ 克隆机器人失败,token已存在；\n\n机器人： *{$first_name}*\n\n请点击下方按钮进行详细设置。";
                $add_bot_url = 'https://t.me/' . $istokenRs['bot_name']."?";
                $bwButtons[] = [
                	['text' => '去设置🤖', 'url' => $add_bot_url],
                	['text' => '❌关闭', 'callback_data' => '/closeMessage']
                ];
                $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
                
                $content = [
                	'chat_id' => $fromChatId,
                	'text' => $msg,
                	'reply_markup' => $replyMarkup,
                	'parse_mode' => 'MarkdownV2'
                ];   
                	
                send($token, 'sendMessage', $content);
	        
	       /* $msg = "克隆机器人失败,token已存在";
	        $content = [
	            'chat_id' => $fromChatId,
	            'text' => $msg,
	        ];
	        send($token, 'sendMessage', $content);*/
	        
	    } else {
	        $cloneBot = Cache::store('redis')->get($this->cacheBot);
	        $botInfo = getBotInfo($message);
	        $content = [
	                    'chat_id' => $fromChatId,
	                    'text' => '机器人克隆中，请稍后！',
	                    'parse_mode' => 'MarkdownV2'
	                ];   
	                    
	                send($token, 'sendMessage', $content);
	        if ($botInfo && isset($botInfo['result'])) {
	             
	            
	            
	            $botUsername = $botInfo['result']['first_name'];
	            $botName = $botInfo['result']['username'];
	            $bot_id = $botInfo['result']['id'];
	            
	            try {
	                // 将 token 存入数据库
	                $tokenID = Db::name('telegrambot')->insertGetId([
	                    'bot_id' => $bot_id,
	                    'chat_id' => $fromChatId,
	                    'bot_token' => $message,
	                    'bot_name' => $botName,
	                    'first_name' => $botUsername,
	                    'is_active' => 1,
	                    'create_time' => time(),
	                    'starttime' => $cloneBot['starttime'],
	                    'endtime' => $cloneBot['endtime']
	                ]);
	                
	                $Ntoken = $message;
	                $response=getWebHookreg($Ntoken,$tokenID);
	                
					$cacheKey = 'telegram_bot_' . $tokenID;
					$rs = Db::name('telegrambot')->where('id',$tokenID)->find();
                    Cache::store('redis')->set($cacheKey, $rs, 3600);
	                
	                
	                $msg = "✅ 恭喜您，添加机器人完成，\n\n机器人：*{$botUsername}*\n\n当前设置使用默认参数初始化，请点击下方按钮进行详细设置。";
	                $add_bot_url = 'https://t.me/' . $botName;
	                $bwButtons[] = [
	                    ['text' => '去设置🤖', 'url' => $add_bot_url],
	                    ['text' => '❌关闭', 'callback_data' => '/closeMessage']
	                ];
	                $replyMarkup = json_encode(['inline_keyboard' => $bwButtons]);
	                
	                $content = [
	                    'chat_id' => $fromChatId,
	                    'text' => $msg,
	                    'reply_markup' => $replyMarkup,
	                    'parse_mode' => 'MarkdownV2'
	                ];   
	                    
	                send($token, 'sendMessage', $content);
	            } catch (\Exception $e) {
	                echo "克隆机器人失败: " . $e->getMessage();
	            }
	        } else {
	            echo "无效的token，克隆失败！";
	        }
	    }
	}

    function nowpushtxtCustom($text,$groupID,$username,$token){
    	$botinfo=Cache::store('redis')->get($this->cacheBot);
    	$cacheNowtopkey="telegram_bot_nowpush_top_".$botinfo['bot_id'];
    	$photo='';
    	
    	$Nowtop = Cache::store('redis')->get($cacheNowtopkey);
    	$grouplist = Db::name('telegraggroup')
            ->where('bot_id', $botinfo['bot_id'])
            ->order('id desc')
            ->select();
            if($grouplist){
            // 推送消息
                foreach ($grouplist as $group) {
    	
            	if ($Nowtop == 1) {
                            $content = [
                            'chat_id' => $group['group_id'],
                            'text' => $text,
                            ];
                            
                            $response = send($token, 'sendMessage', $content);
                            $data = json_decode($response, true);
                          
                            if ($data['ok']) {
                                $messageId = $data['result']['message_id'];
                                
                            }
                          
                            $this->pinMessage($group['group_id'], $token, $messageId);
                  
                } else {
                    $this->sendContent($group['group_id'], $text, $photo, $token);
                }
            }
        }
            
        $this->redis->del($cacheNowtopkey); 
        
    }



    /**
 * 获取 Telegram 上的文件路径
 *
 * @param string $botToken Telegram Bot Token
 * @param string $fileId 图片的 file_id
 * @return string|null 文件路径
 */
    function getFilePath($botToken, $fileId) {
        if($fileId){
        $url = "https://api.telegram.org/bot{$botToken}/getFile?file_id={$fileId}";
        $result = file_get_contents($url);
        $data = json_decode($result, true);
        return $data['ok'] ? $data['result']['file_path'] : null;}else{
            return false;
        }
    }
    
    /**
     * 下载 Telegram 文件到本地
     *
     * @param string $botToken Telegram Bot Token
     * @param string $filePath 文件路径
     * @return string|null 本地保存的文件路径
     */
    function downloadFile($botToken, $filePath) {
        $url = "https://api.telegram.org/file/bot{$botToken}/{$filePath}";
        $localFile = '/www/wwwroot/klbot.globaldoge.site/public/temp/'. basename($filePath);
    
        // 使用 cURL 下载文件
        $ch = curl_init($url);
        $fp = fopen($localFile, 'wb'); // 打开文件写入
        curl_setopt($ch, CURLOPT_FILE, $fp);  // 将文件流写入文件
        curl_setopt($ch, CURLOPT_HEADER, 0);  // 不返回头信息
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // 跟随重定向
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 设置超时，避免长时间无响应
    
        // 执行下载
        curl_exec($ch);
        
        // 检查是否发生错误
        if (curl_errno($ch)) {
            echo 'cURL 错误: ' . curl_error($ch) . "\n";
            fclose($fp);
            curl_close($ch);
            return null;
        }
    
        fclose($fp);
        curl_close($ch);
    
        // 检查文件是否成功下载
        if (filesize($localFile) > 0) {
            return $localFile;
        } else {
            echo "文件下载失败，文件为空: " . $localFile . "\n";
            return null;
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



    private function sendContent($groupId, $text, $photo, $token)
    {
        if (!empty($photo)) {
            $this->sendPhoto($groupId, $text, $photo, $token);
        } else {
            $this->sendTextMessage($groupId, $text, $token);
        }
    }

    private function sendPhoto($groupId, $text, $photo, $token)
    {
        $photoData = json_decode($photo, true);
        foreach ($photoData as $photoInfo) {
            if ($photoInfo['width'] === 800) {
                $content = [
                    'chat_id' => $groupId,
                    'photo' => $photoInfo['file_id'],
                    'caption' => $text,
                    'parse_mode' => 'Markdown'
                ];

                $response = send($token, 'sendPhoto', $content);
              
                break; // 发送成功后退出循环
            }
        }
    }

    private function sendTextMessage($groupId, $text, $token)
    {
        $isHtml=preg_match('/<[^>]+>/', $text);
        if($isHtml){
            $parse_mode='HTML';
        }else{
           $parse_mode='Markdown'; 
        }
        $content = [
            'chat_id' => $groupId,
            'text' => $text,
            'parse_mode' => $parse_mode,
            'disable_notification' => true
        ];
        send($token, 'sendMessage', $content);
       
    }
    
    
private function sendTextentities($message, $entities)
{
    $parsedMessage = '';
    $utf16Chars = mb_convert_encoding($message, 'UTF-16LE', 'UTF-8');
    $utf16CharArray = str_split($utf16Chars, 2); // UTF-16 每个字符占2字节
    $lastOffset = 0;
    
    foreach ($entities as $entity) {
        $utf16Offset = $entity['offset'];
        $utf16Length = $entity['length'];
        $type = $entity['type'];

        // 只处理当前实体之前的文本
        if ($utf16Offset > $lastOffset) {
            $parsedMessage .= $this->utf16ToUtf8(array_slice($utf16CharArray, $lastOffset, $utf16Offset - $lastOffset));
        }

        // 获取当前实体的文本
        $entityText = $this->utf16ToUtf8(array_slice($utf16CharArray, $utf16Offset, $utf16Length));
        
        // 处理实体的格式
        switch ($type) {
            case 'text_link':
                $url = $entity['url'];
                $parsedMessage .= "<a href=\"{$url}\">{$entityText}</a>";
                break;
            case 'url':
                $parsedMessage .= "<a href=\"{$entityText}\">{$entityText}</a>";
                break;
            case 'bold':
                $parsedMessage .= "<b>{$entityText}</b>";
                break;
            case 'italic':
                $parsedMessage .= "<i>{$entityText}</i>";
                break;
            case 'code':
                $parsedMessage .= "<code>{$entityText}</code>";
                break;
            case 'pre':
                $parsedMessage .= "<pre>{$entityText}</pre>";
                break;
            case 'underline':
                $parsedMessage .= "<u>{$entityText}</u>";
                break;
            case 'strikethrough':
                $parsedMessage .= "<s>{$entityText}</s>";
                break;
            default:
                $parsedMessage .= $entityText;
                break;
        }

        // 更新 lastOffset，确保下一个实体的文本不会重复处理
        $lastOffset = $utf16Offset + $utf16Length;
    }

    // 添加最后一部分普通文本（如果有）
    if ($lastOffset < count($utf16CharArray)) {
        $parsedMessage .= $this->utf16ToUtf8(array_slice($utf16CharArray, $lastOffset));
    }

    return $parsedMessage;
}

private function utf16ToUtf8($utf16Array)
{
    $utf16String = implode('', $utf16Array);
    return mb_convert_encoding($utf16String, 'UTF-8', 'UTF-16LE');
}

}

    
    