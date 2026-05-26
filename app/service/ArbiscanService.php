<?php
namespace app\service;

use think\facade\Cache;
use think\facade\Db;
use GuzzleHttp\Client;
use think\facade\Log;
class ArbiscanService
{
    private $apiUrl;
    private $apiKey;
    private $batchSize = 1000; // 每批次处理 1000 条地址

    public function __construct()
    {
        $config = config('arbiscan');
        $this->apiUrl = $config['api_url'];
        $this->apiKey = $config['api_key'];
    }

    /**
     * 检查所有地址的交易变化（分批次处理）
     */
    public function checkAddresses()
    {
        $offset = 0;
        $client = new Client();

        while (true) {
            // 分批次查询地址
            $addresses = Db::name('arbnotice')
                ->limit($offset, $this->batchSize) 
                ->select();

            if ($addresses === null || count($addresses) === 0) {
                break; // 没有更多地址，退出循环
            }

            // 处理当前批次的地址
            foreach ($addresses as $address) {
                $this->checkAddressChanges($address, $client);
            }

            $offset += $this->batchSize; // 更新偏移量
        }
    }

    /**
     * 检查单个地址的交易变化
     */
    private function checkAddressChanges($addressInfo, $client)
    {
        $address = $addressInfo['address'];
        $chatId = $addressInfo['chat_id'];
        $botId = $addressInfo['botid'];

        // 检查缓存中是否已处理过该地址
        /*$cacheKey = 'arbiscan_processed_' . $address;
        if (Cache::store('redis')->has($cacheKey)) {
            return; // 已处理过，跳过
        }*/
         // 获取最新区块号
        $responseBlock = $client->get($this->apiUrl, [
            'query' => [
                'module' => 'proxy',
                'action' => 'eth_blockNumber',
                'apikey' => $this->apiKey,
            ],
        ]);
        $blockData = json_decode($responseBlock->getBody(), true);
       // log::info($blockData);
       if (!isset($blockData['result']) || empty($blockData['result'])) {
 
            return; // 如果获取最新区块号失败，直接返回
        }
        // 获取最新区块号
        $blockHex = $blockData['result'];
        
        // 去除 '0x' 前缀
        if (strpos($blockHex, '0x') === 0) {
            $blockHex = substr($blockHex, 2);
        }

        // 确保只有合法的十六进制字符
        if (preg_match('/^[0-9a-fA-F]+$/', $blockHex)) {
            $latestBlock = hexdec($blockHex); // 将十六进制区块号转换为十进制
            log::info("最新区块号：{$latestBlock}");
        } else {
            log::error("无效的区块号：{$blockHex}");
            return;
        }
        
        
        
        $response = $client->get($this->apiUrl, [
            'query' => [
                'module' => 'account',
                'action' => 'txlist',
                'address' => $address,
                'startblock' => 0,
                'endblock' => $latestBlock,
                'sort' => 'desc',
                'apikey' => $this->apiKey,
            ],
        ]);
        // 获取外部交易列表
        $externalTxResponse = json_decode($response->getBody(), true);
      
        
        $responsewai = $client->get($this->apiUrl, [
            'query' => [
                'module' => 'account',
                'action' => 'txlistinternal',
                'address' => $address,
                'startblock' => 0,
                'endblock' => $latestBlock,
                'sort' => 'desc',
                'apikey' => $this->apiKey,
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
        
    
        
  
        // API 返回成功，处理交易数据
            if ($transactions) {
        
                $latestTransaction = $transactions[0];
                log::info($latestTransaction);
                $txCacheKey = 'arbiscan_latest_tx_' . $address;
        
                // 获取缓存的交易哈希
                $cachedTxHash = Cache::store('redis')->get($txCacheKey);
        
                // 如果缓存中没有哈希，或者哈希不一致，说明有新交易
                if ($cachedTxHash !== $latestTransaction['hash']) {
                    // 更新缓存
                    Cache::store('redis')->set($txCacheKey, $latestTransaction['hash']);
                    // 发送通知
                    $this->sendNotification($latestTransaction, $address, $chatId, $botId, $client);
                }
            }
            
     // 标记地址为已处理
       // Cache::store('redis')->set($cacheKey, true);
       
    }

    /**
     * 发送交易变化通知
     */
    private function sendNotification($transaction, $address, $chatId, $botId, $client)
    {
        // 从缓存中获取备注
        $cacheKey = "arbiscan_address_info_{$chatId}_{$address}";
        $addinfo = Cache::store('redis')->get($cacheKey) ?? [];  // 防止为空导致错误
        $remark = $addinfo['note'] ?? '无备注';  // 如果备注不存在，使用 '无备注'

        // 获取 ETH 余额
        $response = $client->get($this->apiUrl, [
            'query' => [
                'module' => 'account',
                'action' => 'balance',
                'address' => $address,
                'apikey' => $this->apiKey,
            ],
        ]);

        $balanceData = json_decode($response->getBody(), true);
        //$ethBalance = isset($balanceData['result']) ? floatval($balanceData['result']) / 1e18 : '未知';
        $ethBalance = $this->getEthBalance($address); 
        $value = bcdiv($transaction['value'], '1000000000000000000', 18); // 转换为 ETH
        // 判断交易类型
        $transactionType = $this->getTransactionType($transaction, $address);

        // 格式化通知消息
        $message = sprintf(
            "区块地址变化通知：\n\n地址：%s\n备注：%s\n交易类型：%s\n交易金额：%s ETH\n出账地址：%s\n入账地址：%s\n交易时间：%s\n交易哈希：%s\nETH余额：%s ETH",
            $address,
            $remark,
            $transactionType,
            $value,
            $transaction['from'],
            $transaction['to'],
            date('Y-m-d H:i:s', $transaction['timeStamp']),
            $transaction['hash'],
            $ethBalance
        );

        // 获取对应机器人的 bot_token
       /* $cacheBot = 'telegram_bot_' . $botId;
        $botinfo = Cache::store('redis')->get($cacheBot) ?? [];
        $botToken = $botinfo['bot_token'] ?? '';
        */
        $cacheBot = 'telegram_bot_' . $botId;
        if (Cache::store('redis')->has($cacheBot)) {
            $cachedBot = Cache::store('redis')->get($cacheBot);
            $botToken =$cachedBot['bot_token'];
        }else{
           $rs = Db::name('telegrambot')->where('id', $botId)->find();
           Cache::store('redis')->set($cacheBot, $rs, 3600);
           $botToken =$rs['bot_token'];
        }
        
        if ($botToken) {
            // 发送消息到 Telegram
            $client->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'form_params' => [
                    'chat_id' => $chatId,
                    'text' => $message,
                ],
            ]);
        }
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
                $balanceInWei = $data['result'];
                return (float)$balanceInWei / 1000000000000000000; // 转换为 ETH
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
    /**
     * 判断交易类型
     */
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
}
