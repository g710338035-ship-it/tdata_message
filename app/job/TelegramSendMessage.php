<?php
namespace app\job;

use think\queue\Job;
use think\facade\Db;
use think\facade\Log;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class TelegramSendMessage
{
    // 最大重试次数
    protected $maxAttempts = 3;
    
    // HTTP客户端实例
    protected $httpClient;
    
    public function __construct()
    {
        $this->httpClient = new Client([
            'timeout' => 30,
            'connect_timeout' => 5,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]
        ]);
    }
    
    /**
     * 执行任务
     */
    public function fire(Job $job, $data)
    {
        try {
            Log::info('开始处理Telegram发送任务', ['job_id' => $job->getJobId(), 'data' => $data]);
            
            // 执行发送操作
            $result = $this->sendToPythonApi($data['params']);
            log::info(json_encode($result));
            if ($result['status']) {
                // 发送成功，保存消息到数据库
                if($data['params']['action']=='send_messages')$this->saveMessageToDB($result, $data);
                if($data['params']['action']=='mark_session_as_read')$this->saveChatToDB($result, $data);
                // 删除任务
                $job->delete();
                
                Log::info('Telegram消息发送成功');
                
                // 触发成功事件（可选）
                //$this->onSuccess($result, $data);
                
            } else {
                // 发送失败，处理重试逻辑
                $this->handleFailure($job, $data, $result['message'] ?? '发送失败');
            }
            
        } catch (\Exception $e) {
            Log::error('Telegram任务执行异常'.json_encode( [
                'job_id' => $job->getJobId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]));
            
            $this->handleFailure($job, $data, $e->getMessage());
        }
    }
    
    /**
     * 发送消息到Python API
     */
    protected function sendToPythonApi($params)
    {
        $url = $params['last_api_address'] . '/telegram_action';
        
        // 准备请求数据
        $requestData = [
            'action' => $params['action'],
            'api_id' => config('telegram.api_id'),
            'api_hash' => config('telegram.api_hash'),
            'tdata_path' => $params['session_path'],
        ];
        
        // 处理代理
        if (!empty($params['proxyip'])) {
            $proxyParts = explode('##', $params['proxyip']);
            if (count($proxyParts) >= 3) {
                list($ipPort, $username, $password) = $proxyParts;
                $requestData['proxy'] = "socks5://{$username}:{$password}@{$ipPort}";
            }
        }
        
        // 添加其他参数
        $excludeKeys = ['action', 'proxyip', 'session_path', 'last_api_address'];
        foreach ($params as $key => $value) {
            if (!in_array($key, $excludeKeys) && $value !== null) {
                $requestData[$key] = $value;
            }
        }
        
        Log::debug('发送请求到Python API', [
            'url' => $url,
            'params' => $requestData
        ]);
        
        // 发送请求
        $response = $this->httpClient->post($url, [
            'json' => $requestData,
            'timeout' => $this->getTimeoutByAction($params['action'])
        ]);
        
        $responseBody = $response->getBody()->getContents();
        $result = json_decode($responseBody, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('JSON解析失败: ' . json_last_error_msg());
        }
        
        return $result;
    }
    
    /**
     * 根据动作类型设置超时时间
     */
    protected function getTimeoutByAction($action)
    {
        $timeouts = [
            'send_messages' => 10,
            'get_history' => 15,
            'block_user' => 5,
            'delete_chat_history' => 8,
            'mark_session_as_read' => 5,
            'get_common_groups' => 10,
            'default' => 30
        ];
        
        return $timeouts[$action] ?? $timeouts['default'];
    }
    
    /**
     * 保存消息到数据库
     */
    protected function saveMessageToDB($result, $data)
    {
        try {
            $params = $data['params'];
            
            // 提取Telegram消息ID
            $telegramMessageId = null;
            if (isset($result['data']['success']) && is_array($result['data']['success'])) {
                foreach ($result['data']['success'] as $successItem) {
                    if (isset($successItem['message_id'])) {
                        $telegramMessageId = $successItem['message_id'];
                        break;
                    }
                }
            }
            
            // 获取账号信息
            $mtUser = Db::name('mtuser')
                ->where('session_path', $params['session_path'])
                ->field('id, uuid, nickName')
                ->find();
            
            if (!$mtUser) {
                throw new \Exception('账号信息不存在');
            }
            
            $accountId = "temp_" . $mtUser['uuid'] . ".session";
            
            // 消息数据
            $messageData = [
                'account_id' => $accountId,
                'chat_id' => $params['group_id'],
                'message_id' => $telegramMessageId,
                'sender_id' => $mtUser['uuid'],
                'sender_name' => $mtUser['nickName'],
                'message_type' => $params['message_type'] ?? 'text',
                'message_text' => $params['message_text'] ?? '',
                'timestamp' => date('Y-m-d H:i:s'),
                'is_outgoing' => 1,
                'is_read' => 0,
                'reply_to_msg_id' => $params['first_msg_id'] ?? 0,
                'feedback_type' => $params['feedback_type'] ?? '',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            // 插入消息记录
            Db::name('tdmessages')->insert($messageData);
            
            // 更新聊天记录
            Db::name('tdchats')
                ->where('chat_id', $params['group_id'])
                ->where('account_id', $accountId)
                ->update([
                    'last_message_time' => date('Y-m-d H:i:s'),
                    'last_message_text' => substr($params['message_text'] ?? '', 0, 255),
                    'unread_count' => Db::raw('unread_count + 1'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            
            Log::info('消息保存成功', ['chat_id' => $params['group_id'], 'message_id' => $telegramMessageId]);
            
        } catch (\Exception $e) {
            Log::error('保存消息到数据库失败', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            // 这里可以选择抛出异常让任务重试，或者记录错误继续
        }
    }
    /**
     * 保存消息到数据库
     */
    protected function saveChatToDB($result, $data)
    {
        try {
            $params = $data['params'];
            
            // 提取Telegram消息ID
            $telegramMessageId = null;
            if (isset($result['data']['participants_count'])) {
                // 更新聊天记录
                Db::name('tdchats')
                    ->where('chat_id', $params['group_id'])
                    ->update([
                        'participants_count' => $result['data']['participants_count'],
                    ]);
            }
            
        } catch (\Exception $e) {
            Log::error('保存消息到数据库失败', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            // 这里可以选择抛出异常让任务重试，或者记录错误继续
        }
    }
    /**
     * 处理失败任务
     */
    protected function handleFailure(Job $job, $data, $errorMessage)
    {
        $attempts = $job->attempts();
        
        if ($attempts < $this->maxAttempts) {
            // 重试
            $delay = $this->calculateRetryDelay($attempts);
            $job->release($delay);
            
            Log::warning('任务重试', [
                'job_id' => $job->getJobId(),
                'attempt' => $attempts,
                'delay' => $delay,
                'error' => $errorMessage
            ]);
        } else {
            // 最终失败
            $job->delete();
            
            // 记录到失败任务表
            
            
            Log::error('任务最终失败', [
                'job_id' => $job->getJobId(),
                'attempts' => $attempts,
                'error' => $errorMessage,
                'data' => $data
            ]);
            
            // 触发失败事件（可选）
            //$this->onFailed($data, $errorMessage);
        }
    }
    
    /**
     * 计算重试延迟（指数退避）
     */
    protected function calculateRetryDelay($attempt)
    {
        // 指数退避：1s, 4s, 9s, ...
        return min(pow($attempt, 2), 60);
    }
    

    
    
}