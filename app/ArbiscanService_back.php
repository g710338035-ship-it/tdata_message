<?php
namespace app\service;

use think\facade\Cache;
use think\facade\Db;
use GuzzleHttp\Client;

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
                ->limit($this->batchSize)
                ->select();

            if (empty($addresses)) {
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
        $cacheKey = 'arbiscan_processed_' . $address;
        if (Cache::store('redis')->has($cacheKey)) {
            return; // 已处理过，跳过
        }

        // 获取交易列表
        $response = $client->get($this->apiUrl, [
            'query' => [
                'module' => 'account',
                'action' => 'txlist',
                'address' => $address,
                'startblock' => 0,
                'endblock' => 99999999,
                'sort' => 'desc',
                'apikey' => $this->apiKey,
            ],
        ]);

        $data = json_decode($response->getBody(), true);

        // 如果 API 返回无效数据，直接返回
        if ($data['status'] != '1' || empty($data['result'])) {
            return;
        }

        $latestTransaction = $data['result'][0];
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
        
        // 如果普通交易没有找到，我们尝试查找内部交易（txlistinternal）
        if (empty($data['result'])) {
            // 获取内部交易列表
            $responseInternal = $client->get($this->apiUrl, [
                'query' => [
                    'module' => 'account',
                    'action' => 'txlistinternal',
                    'address' => $address,
                    'startblock' => 0,
                    'endblock' => 99999999,
                    'sort' => 'desc',
                    'apikey' => $this->apiKey,
                ],
            ]);
    
            $dataInternal = json_decode($responseInternal->getBody(), true);
    
            // 如果内部交易数据返回有效，则处理并发送通知
            if ($dataInternal['status'] == '1' && !empty($dataInternal['result'])) {
                foreach ($dataInternal['result'] as $internalTx) {
                    // 格式化内部交易消息
                    $this->sendInternalTransactionNotification($internalTx, $address, $chatId, $botId, $client);
                }
            }
        }
        // 标记地址为已处理
        Cache::store('redis')->set($cacheKey, true);
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
        $ethBalance = isset($balanceData['result']) ? $balanceData['result'] / 1e18 : '未知';

        // 判断交易类型
        $transactionType = $this->getTransactionType($transaction, $address);

        // 格式化通知消息
        $message = sprintf(
            "区块地址变化通知：\n\n地址：%s\n备注：%s\n交易类型：%s\n交易金额：%s ETH\n出账地址：%s\n入账地址：%s\n交易时间：%s\n交易哈希：%s\nETH余额：%s ETH",
            $address,
            $remark,
            $transactionType,
            $transaction['value'] / 1e18,
            $transaction['from'],
            $transaction['to'],
            date('Y-m-d H:i:s', $transaction['timeStamp']),
            $transaction['hash'],
            $ethBalance
        );

        // 获取对应机器人的 bot_token
        $cacheBot = 'telegram_bot_' . $botId;
        $botinfo = Cache::store('redis')->get($cacheBot) ?? [];
        $botToken = $botinfo['bot_token'] ?? '';

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
