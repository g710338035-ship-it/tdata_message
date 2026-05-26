<?php
namespace app\command;

use think\worker\Server;
use Workerman\Connection\TcpConnection;
use think\facade\Log;
use think\App;

class ChatServer extends Server
{
    // WebSocket 监听地址
    protected $socket = 'websocket://0.0.0.0:8282';

    // 保存连接信息
    protected $accountConnections = []; // account_id => connection[]
    protected $connectionAccounts = []; // connection_id => account_id

    public function onConnect(TcpConnection $connection)
    {
        Log::info("新连接建立: {$connection->id}");
    }

    public function onMessage(TcpConnection $connection, $data)
    {
        $params = json_decode($data, true);
        if (empty($params['action'])) {
            return $connection->send(json_encode([
                'code' => 400,
                'msg'  => '缺少action参数'
            ]));
        }

        switch ($params['action']) {
            case 'bind_account':
                $this->bindAccount($connection, $params['account_id'] ?? null);
                break;
            case 'send_message':
                $this->handleSendMessage($connection, $params);
                break;
            default:
                $connection->send(json_encode([
                    'code' => 400,
                    'msg'  => '未知的action'
                ]));
        }
    }

    protected function bindAccount(TcpConnection $connection, $accountId)
    {
        if (!$accountId) {
            return $connection->send(json_encode([
                'code' => 400,
                'msg'  => '缺少account_id'
            ]));
        }

        // 解绑旧账号
        if (isset($this->connectionAccounts[$connection->id])) {
            $oldAccountId = $this->connectionAccounts[$connection->id];
            unset($this->accountConnections[$oldAccountId][$connection->id]);
        }

        // 新绑定
        $this->accountConnections[$accountId][$connection->id] = $connection;
        $this->connectionAccounts[$connection->id] = $accountId;

        $connection->send(json_encode([
            'code' => 200,
            'msg'  => '账号绑定成功'
        ]));
    }

    protected function handleSendMessage(TcpConnection $connection, $params)
    {
        // 调用 ThinkPHP 控制器
        $app = app(); // 直接取当前 App 容器
        $controller = $app->make(\app\kefu\controller\Index::class);
        $_POST = $params;
        $response = $controller->sendMessage();
        $result = json_decode($response->getContent(), true);

        $connection->send(json_encode($result));
    }

    public function onClose(TcpConnection $connection)
    {
        $accountId = $this->connectionAccounts[$connection->id] ?? null;
        if ($accountId) {
            unset($this->accountConnections[$accountId][$connection->id]);
            unset($this->connectionAccounts[$connection->id]);
        }
        Log::info("连接关闭: {$connection->id}");
    }
}
