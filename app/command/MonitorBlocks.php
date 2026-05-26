<?php

namespace app\command;

use Ratchet\Client\WebSocket;
use Ratchet\Client\Connector;
use React\EventLoop\Factory as LoopFactory;
use GuzzleHttp\Client as HttpClient;
use think\console\Command;
use think\console\Input;
use think\console\Output;

class MonitorBlocks extends Command
{
    protected function configure()
    {
        $this->setName('monitor:blocks')
             ->setDescription('使用 WebSocket 监听区块链变化并推送');
    }

    protected function execute(Input $input, Output $output)
    {
        $loop = LoopFactory::create();
        $connector = new Connector($loop);

        // WebSocket 连接地址（以太坊的节点地址）
        $webSocketUrl = 'wss://mainnet.infura.io/ws/v3/2d3ce08584234735a37856ab36b08f87';
        $connector($webSocketUrl)->then(function (WebSocket $conn) use ($output) {
            $output->writeln('WebSocket 连接成功，开始监听区块变化...');

            // 订阅新区块事件
            $conn->send(json_encode([
                'jsonrpc' => '2.0',
                'method'  => 'eth_subscribe',
                'params'  => ['newHeads'],
                'id'      => 1
            ]));

            // 处理接收的数据
            $conn->on('message', function ($message) use ($conn, $output) {
                $data = json_decode($message, true);
                if (isset($data['params']['result']['hash'])) {
                    $blockHash = $data['params']['result']['hash'];
                    $this->handleNewBlock($blockHash);
                }
            });

            // 处理连接关闭
            $conn->on('close', function ($code = null, $reason = null) use ($output) {
                $output->writeln("WebSocket 连接关闭，原因：{$reason}");
            });
        }, function ($error) use ($output) {
            $output->writeln("WebSocket 连接失败：{$error->getMessage()}");
        });

        $loop->run();
    }

    protected function handleNewBlock($blockHash)
    {
        // 获取区块数据
        $httpClient = new HttpClient(['base_uri' => 'https://api.arbiscan.io']);
        $apiKey = 'MF9HI7UWHBFT73NNZJ9YSH5MIVTIEH8UN6';

        try {
            $response = $httpClient->request('GET', '/api', [
                'query' => [
                    'module' => 'block',
                    'action' => 'getblockreward',
                    'blockno' => $blockHash,
                    'apikey' => $apiKey
                ]
            ]);

            $blockData = json_decode($response->getBody(), true);

            if ($blockData['status'] == '1' && isset($blockData['result'])) {
                // 推送新区块信息到 Telegram
                $this->sendToTelegram($blockData['result']);
            }
        } catch (\Exception $e) {
            // 捕获异常，记录错误日志
            \think\facade\Log::error('获取区块数据失败：' . $e->getMessage());
        }
    }

    protected function sendToTelegram($data)
    {
        $botToken = '7923628404:AAFL5jdkbUQP-rLHt3D44PxgfWJCZw6ioFw';
        $chatId = '6891604872';
        $message = "监测到新区块变化：\n";
        foreach ($data as $key => $value) {
            $message .= "{$key}: {$value}\n";
        }

        $httpClient = new HttpClient();
        $httpClient->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
            'form_params' => [
                'chat_id' => $chatId,
                'text'    => $message
            ]
        ]);
    }
}
