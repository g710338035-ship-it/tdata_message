<?php
namespace app\job;

use think\queue\Job;
use app\admin\model\Mtuser as MtuserModel;
use app\admin\model\TelegramPorts as TelegramPortsModel;
use think\facade\Log;
use think\facade\Cache;
use think\facade\Config;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\Utils;
use think\db\Exception;

class TelegramTask
{
    /**
     * 批量更新数据库阈值
     * @var int
     */
    private $batchSize;
 
    /**
     * 数据库更新缓冲区
     * @var array
     */
    private $updateBuffer = [];
    
    /**
     * Guzzle HTTP客户端实例
     * @var Client
     */
    private $httpClient;
    
    private $redis;
    
    /**
     * 可用端口配置
     * @var array
     */
    private $availablePorts = [];
    
    /**
     * 端口缓存键名
     * @var string
     */
    private $portsCacheKey = 'telegram_available_ports';
    
    /**
     * 构造函数：初始化配置和HTTP客户端
     */
    public function __construct()
    {       
        // 从配置文件读取批量处理大小，默认50
        $this->batchSize = Config::get('telegram.batch_size', 10);       
        
        // 初始化Guzzle客户端，复用连接提高效率
        $this->httpClient = new Client([
            'timeout' => 120.0,               // 请求超时时间
            'connect_timeout' => 60.0,       // 连接超时时间
            'verify' => false,               // 生产环境建议开启SSL验证
        ]);
        $this->redis = Cache::store('redis');
        
        // 初始化端口配置
        $this->loadAvailablePorts();
    }

    /**
     * 加载可用端口配置
     */
    private function loadAvailablePorts(): void
    {
        try {
            // 先尝试从缓存获取
            $cachedPorts = $this->redis->get($this->portsCacheKey);
            if ($cachedPorts) {
                $this->availablePorts = json_decode($cachedPorts, true);
                Log::info("[loadAvailablePorts] 从缓存加载端口配置，数量: " . count($this->availablePorts));
                return;
            }
            // 从数据库加载
            $this->availablePorts = TelegramPortsModel::getAvailablePorts();            
            // 缓存配置（5分钟）
            $this->redis->setex($this->portsCacheKey, 300, json_encode($this->availablePorts));
            
            Log::info("[loadAvailablePorts] 从数据库加载端口配置，数量: " . count($this->availablePorts));
            
        } catch (\Exception $e) {
            Log::error("[loadAvailablePorts] 加载端口配置失败: " . $e->getMessage());
           
        }
    }


    /**
     * 根据端口号获取完整的API地址
     */
    private function getApiAddress($portConfig): string
    {
        $host = $portConfig['host'] ?? '127.0.0.1';
        $port = $portConfig['port'] ?? 5000;
        
        return "http://{$host}:{$port}";
    }
    /**
     * 轮询索引缓存键
     * @var string
     */
    private $roundRobinKey = 'telegram_task_rr_index';
    /**
     * 获取负载均衡端口配置
     */
    private function getBalancedPortConfig(int $userId): ?array
    {
        if (empty($this->availablePorts)) {
            Log::error("[getBalancedPortConfig] 没有可用的端口配置");
            return null;
        }
        
        // 过滤可用的端口（连接数未满）
        $availablePorts = array_filter($this->availablePorts, function($portConfig) {
            return ($portConfig['current_connections'] ?? 0) < ($portConfig['max_connections'] ?? 10);
        });
        // 重置数组索引
        $availablePorts = array_values($availablePorts);
        
        if (empty($availablePorts)) {
            Log::warning("[getBalancedPortConfig] 所有端口连接数已满");
            // 返回第一个端口作为后备
            return $this->availablePorts[0] ?? null;
        }
        
        // 基于用户ID的哈希分配，确保同一用户总是分配到相同端口
        /*$portIndex = $userId % count($availablePorts);
        $portConfig = array_values($availablePorts)[$portIndex];
        */
        // 使用原子计数器实现轮询
        $currentIndex = $this->redis->incr($this->roundRobinKey);
        // 如果计数器过大，重置它（虽然 Redis 能存很大，但为了保险）
        if ($currentIndex > 1000000) {
            $this->redis->set($this->roundRobinKey, 0);
        }
        
        $portIndex = $currentIndex % count($availablePorts);
        $portConfig = $availablePorts[$portIndex];
        
        Log::info("[getBalancedPortConfig] 轮询分配: 索引 {$portIndex} (总数 " . count($availablePorts) . ") -> 端口 {$portConfig['port']}");
        return $portConfig;
    }

    /**
     * 根据端口号获取端口配置
     */
    private function getPortConfigByPort(int $port): ?array
    {
        foreach ($this->availablePorts as $portConfig) {
            if ($portConfig['port'] == $port) {
                return $portConfig;
            }
        }
        return null;
    }

    /**
     * 任务执行主入口（队列回调函数）
     */
    public function fire(Job $job, $data)
    {
        $attempts = $job->attempts();
        
        Log::info("[TelegramTask] 批次处理开始: batch_id={$data['batch_id']}, type={$data['task_type']}, 任务数=" . count($data['tasks']));
        if ($attempts > 2) {
            Log::warning("[TelegramTask] 任务失败次数过多 ({$attempts})，强制删除: batch_id={$data['batch_id']}");
            $this->handleFatalError($data, "任务重试次数过多，强制终止");
            $job->delete();
            return;
        }
        if (empty($data['batch_id']) || empty($data['task_type']) || empty($data['tasks'])) {
            Log::error('[TelegramTask] 任务参数不完整: ' . json_encode($data));
            $job->delete();
            return;
        }

        try {
            $startTime = microtime(true);
            // 批量API处理模式
            $result = $this->batchApiProcess($data['task_type'], $data['tasks'], $data['batch_id']);
            
            $this->flushUpdateBuffer();

            $this->updateTaskProgress(
                $data['batch_id'],
                count($data['tasks']),
                $result['success'],
                $result['failed']
            );

            if ($this->isAllBatchesCompleted($data['batch_id'])) {
                $this->finalizeTaskProgress($data['batch_id']);
            }

            $costTime = round(microtime(true) - $startTime, 2);
            Log::info("[TelegramTask] 批次处理完成: batch_id={$data['batch_id']}, 成功={$result['success']}, 失败={$result['failed']}, 耗时={$costTime}秒");
        } catch (\Exception $e) {
            Log::error("[TelegramTask] 处理异常: " . $e->getMessage() . ", 数据: " . json_encode($data));
            $this->handleFatalError($data, $e->getMessage());
        }

        $job->delete();
    }

    /**
     * 批量API处理模式 - 按端口分组并发处理
     */
    private function batchApiProcess(string $taskType, array $tasks, string $batchId): array
    {
        Log::info("[batchApiProcess] 开始批量API处理，类型: {$taskType}, 总任务数: " . count($tasks));
        
        // 1. 按端口分组任务
        $portTasks = $this->groupTasksByPort($tasks, $taskType);
        
        // 1.1记录端口分配信息到缓存
        $this->recordPortAssignments($portTasks, $batchId);
        
        $results = ['success' => 0, 'failed' => 0];
        $promises = [];
        
        // 2. 并发发送到不同端口的API
        foreach ($portTasks as $port => $portTaskBatch) {
            if (empty($portTaskBatch)) continue;
            
            $portConfig = $this->getPortConfigByPort($port);
            if (!$portConfig) {
                Log::error("[batchApiProcess] 端口配置不存在: {$port}");
                $this->markPortTasksAsFailed($portTaskBatch, "端口配置不存在: {$port}", $batchId);
                $results['failed'] += count($portTaskBatch);
                continue;
            }
            
            $batchData = ['batch' => $portTaskBatch];
            $address = $this->getApiAddress($portConfig);
            
            Log::info("[batchApiProcess] 地址 {$address} 处理 " . count($portTaskBatch) . " 个任务");
            
            // 增加连接数计数
            $this->incrementPortConnections($port);
            log::info('发送任务'.json_encode($batchData,JSON_UNESCAPED_UNICODE));
            $promises[$port] = $this->callPythonApiAsync($batchData, $address, $port);
        }
        
        // 3. 等待所有端口响应
        if (!empty($promises)) {
            try {
                $allResults = Utils::settle($promises)->wait();
                log::info("[allResults] 的值：".json_encode($allResults));
                // 4. 处理所有端口的返回结果
                foreach ($allResults as $port => $result) {
                    $taskCount = count($portTasks[$port]);
                    
                    // 减少连接数计数
                    $this->decrementPortConnections($port);
                    
                    if ($result['state'] === 'fulfilled') {
                        $apiResult = $result['value'];
                        $portResults = $this->processApiResults($apiResult, $taskType, $batchId);
                        $results['success'] += $portResults['success'];
                        $results['failed'] += $portResults['failed'];
                        
                        Log::info("[batchApiProcess] 端口 {$port} 处理完成: 成功={$portResults['success']}, 失败={$portResults['failed']}");
                    } else {
                        Log::error("[batchApiProcess] 端口 {$port} 处理失败: " . $result['reason']->getMessage());
                        // 标记该端口所有任务为失败
                        $this->markPortTasksAsFailed($portTasks[$port], $result['reason']->getMessage(), $batchId);
                        $results['failed'] += $taskCount;
                    }
                }
            } catch (\Exception $e) {
                Log::error("[batchApiProcess] 并发处理异常: " . $e->getMessage());
                // 所有任务标记为失败，并减少连接数
                foreach ($portTasks as $port => $tasks) {
                    $this->decrementPortConnections($port);
                    $this->markPortTasksAsFailed($tasks, $e->getMessage(), $batchId);
                    $results['failed'] += count($tasks);
                }
            } finally {
                // 确保在任何异常情况下也刷新剩余的缓冲区
                $this->flushUpdateBuffer();
            }
            
            
        } else {
            Log::warning("[batchApiProcess] 没有有效的任务需要处理");
        }
        
        Log::info("[batchApiProcess] 所有端口处理完成: 总成功={$results['success']}, 总失败={$results['failed']}");
        return $results;
    }
    /**
     * 处理API返回结果
     */
    private function processApiResults(array $apiResult, string $taskType, string $batchId): array
    {
        $results = ['success' => 0, 'failed' => 0];
        
        if (!isset($apiResult['status']) || $apiResult['status'] !== true) {
            Log::error("[processApiResults] API返回状态异常: " . json_encode($apiResult));
            return $results;
        }
        
        if (!isset($apiResult['data']) || !is_array($apiResult['data'])) {
            Log::error("[processApiResults] API返回数据格式错误");
            return $results;
        }
        
        foreach ($apiResult['data'] as $item) {
            $userId = $item['mt_id'] ?? null;
            if (!$userId) {
                $results['failed']++;
                continue;
            }
            
            if (isset($item['status']) && $item['status'] === true) {
                $this->handleServiceResult($userId, $item, $taskType, $batchId);
                $results['success']++;
            } else {
                $this->handleTaskFailure($userId, $item, $batchId);
                $results['failed']++;
            }
            
            // 按批次刷新缓冲区
            if (count($this->updateBuffer) >= $this->batchSize) {
                $this->flushUpdateBuffer();
            }
        }
        
        return $results;
    }
    /**
     * 按端口分组任务 - 优先使用账户配置的端口
     */
    private function groupTasksByPort(array $tasks, string $taskType): array
    {
        $portTasks = [];
        
        foreach ($tasks as $task) {
            // 1. 优先使用账户配置的端口
            $assignedPort = $task['port'] ?? null;
            $portConfig = null;
            
            if (!empty($assignedPort)) {
                // 使用指定的端口
                $portConfig = $this->getPortConfigByPort($assignedPort);
                if (!$portConfig) {
                    Log::error("[groupTasksByPort] 指定端口配置不存在: {$assignedPort}, 用户ID: {$task['user_id']}");
                    continue;
                }
            } else {
                // 2. 如果没有配置端口，使用负载均衡分配
                $portConfig = $this->getBalancedPortConfig($task['user_id']);
                if (!$portConfig) {
                    Log::error("[groupTasksByPort] 无法分配端口，用户ID: {$task['user_id']}");
                    continue;
                }
                $assignedPort = $portConfig['port'];
            }
            
            // 3. 准备任务数据
            $preparedTask = $this->prepareTaskData($task, $taskType);
            if ($preparedTask) {
                $preparedTask['assigned_port'] = $assignedPort;
                $preparedTask['assigned_host'] = $portConfig['host'] ?? '127.0.0.1';
                $portTasks[$assignedPort][] = $preparedTask;
            }
        }
        
        // 记录分组情况
        foreach ($portTasks as $port => $tasks) {
            Log::info("[groupTasksByPort] 端口 {$port} 分配到 " . count($tasks) . " 个任务");
        }
        
        return $portTasks;
    }

    /**
     * 增加端口连接数
     */
    private function incrementPortConnections(int $port): void
    {
        try {
            TelegramPortsModel::incrementConnections($port);
            // 更新缓存中的端口配置
            $this->updatePortConfigInCache($port, 'increment');
        } catch (\Exception $e) {
            Log::error("[incrementPortConnections] 增加端口连接数失败: " . $e->getMessage());
        }
    }

    /**
     * 减少端口连接数
     */
    private function decrementPortConnections(int $port): void
    {
        try {
            TelegramPortsModel::decrementConnections($port);
            // 更新缓存中的端口配置
            $this->updatePortConfigInCache($port, 'decrement');
        } catch (\Exception $e) {
            Log::error("[decrementPortConnections] 减少端口连接数失败: " . $e->getMessage());
        }
    }

    /**
     * 更新缓存中的端口配置
     */
    private function updatePortConfigInCache(int $port, string $operation): void
    {
        try {
            $cachedPorts = $this->redis->get($this->portsCacheKey);
            if ($cachedPorts) {
                $ports = json_decode($cachedPorts, true);
                foreach ($ports as &$portConfig) {
                    if ($portConfig['port'] == $port) {
                        if ($operation === 'increment') {
                            $portConfig['current_connections'] += 1;
                        } elseif ($operation === 'decrement') {
                            $portConfig['current_connections'] = max(0, $portConfig['current_connections'] - 1);
                        }
                        break;
                    }
                }
                $this->redis->setex($this->portsCacheKey, 300, json_encode($ports));
            }
        } catch (\Exception $e) {
            Log::error("[updatePortConfigInCache] 更新端口缓存失败: " . $e->getMessage());
        }
    }

    /**
     * 准备任务数据
     */
    private function prepareTaskData(array $task, string $taskType): ?array
    {
        // 解析tdata路径
        $tdataPath = public_path($task['tdata_path']);
        $tdataPath = realpath($tdataPath);
        
        if ($tdataPath === false) {
            Log::error("[prepareTaskData] 无法解析路径: {$task['tdata_path']}");
            return null;
        }
        
        // 解析代理信息
        $proxy = $this->parseProxy($task['proxyip'] ?? '');
       /* if (!$proxy) {
            Log::error("[prepareTaskData] 代理信息不存在或格式错误，用户ID: {$task['user_id']}");
            return null;
        }*/
        
        return [
            'action' => $taskType,
            'user_id' => $task['user_id'],
            'tdata_path' => $tdataPath,
            'proxy' => $proxy,
            'params' => $task['params'] ?? [],
            'tguser_id' => $task['tguser_id'] ?? null,
            'first_name' => $task['first_name'] ?? null,
            'main_dc_id' => $task['main_dc_id'] ?? null,
            'auth_key_hex' => $task['auth_key_hex'] ?? null,
            'assigned_port' => $task['port'] ?? null, // 记录分配的端口
        ];
    }

    /**
     * 解析代理信息
     */
    private function parseProxy(?string $proxy): ?string
    {
        if (empty($proxy)) {
            Log::warning("[parseProxy] 代理信息为空");
            return null;
        }
        
        $proxyParts = explode('##', $proxy);
        if (count($proxyParts) >= 3) {
            list($ipPort, $username, $password) = $proxyParts;
            return "socks5://{$username}:{$password}@{$ipPort}";
        }
        
        Log::error("[parseProxy] 代理格式错误: {$proxy}");
        return null;
    }

    /**
     * 异步调用Python API
     */
    private function callPythonApiAsync(array $data, string $address, int $port): Promise
    {
        $endpoint = "{$address}/telegram_action";
        
        return $this->httpClient->postAsync($endpoint, [
            'json' => $data,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'timeout' => 120.0  // 适当延长超时时间
        ])->then(
            function ($response) use ($address, $port) {
                $responseBody = $response->getBody()->getContents();
                $result = json_decode($responseBody, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception("Python API返回无效JSON: " . json_last_error_msg());
                }
                log::info("[callPythonApiAsync] 的值：".json_encode($result));
                return $result;
            },
            function ($reason) use ($address, $port) {
                throw $reason;
            }
        );
    }

    // ... 其余的方法保持不变（handleServiceResult, handleTaskFailure, flushUpdateBuffer等）
    // 为节省篇幅，这里省略了其他未改动的方法，您只需要替换上面的方法即可

    /**
     * 标记端口所有任务为失败
     */
    private function markPortTasksAsFailed(array $tasks, string $errorMessage, string $batchId)
    {
        foreach ($tasks as $task) {
            $userId = $task['user_id'];
            $this->updateBuffer[] = [
                'id' => $userId,
                'status' => 0,
                'remark' => "端口处理失败: {$errorMessage}",
                'updatetime' => time(),
                'account_status' => '异常',
                'account_status_desc' => "端口服务不可用"
            ];
            
            // 记录到缓存
            $updateData = [
                'id' => $userId,
                'account_status' => '异常',
                'account_status_desc' => "端口服务不可用",
                'online' => 0,
                'status' => 0,
                'remark' => "端口处理失败: {$errorMessage}",
                'update_time' => time()
            ];
            $this->recordAccountUpdate($userId, $updateData, $batchId);
        }
        
        // 立即刷新缓冲区
        $this->flushUpdateBuffer();
    }

    /**
     * 处理服务成功返回的结果
     */
    private function handleServiceResult(int $userId, array $result, string $taskType, string $batchId)
    {
        $updateData = [
            'id' => $userId,
            'updatetime' => time()
        ];

        switch ($taskType) {
            case 'set_online':
                $updateData['online'] = 1;
                $updateData['status'] = 1;
                $updateData['remark'] = '已上线: ' . ($result['message'] ?? '');
                if(isset($result['data'])){
                    if($result['data']['account_status']=='正常'){
                        if (isset($result['account_info']['result'])) {
                            // 1. 先获取 account_info 下的 result 层级（包含 session_path 和 verification_result）
                            $account_result = $result['account_info']['result'];
                            
                            // 2. 从 verification_result 中提取用户核心信息（关键修复：补充 verification_result 层级）
                            $verification_info = $account_result['verification_result'] ?? [];
                            $updateData['online'] =1;
                            // 3. 赋值 updateData，注意各字段的实际层级
                            $updateData['session_path'] = $account_result['session_path'] ?? '';  // session_path 在 account_result 下
                            $updateData['uuid'] = $verification_info['user_id'] ?? '';  // user_id 在 verification_result 下
                            $updateData['username'] = $verification_info['username'] ?? '';  // username 在 verification_result 下
                            $updateData['nickName'] = $verification_info['nickname'] ?? '';  // nickname 在 verification_result 下
                            $updateData['is_authorized'] = isset($verification_info['is_authorized']) ? (int)$verification_info['is_authorized'] : 0;  // is_authorized 在 verification_result 下
                        }
                        $apiInfo = $this->getCurrentApiInfo($userId, $batchId);
                        if ($apiInfo) {
                            $updateData['api_host'] = $apiInfo['host'];
                            $updateData['api_port'] = $apiInfo['port'];
                            $updateData['last_api_address'] = $apiInfo['full_address'];
                            Log::info("[handleServiceResult] 用户 {$userId} 登录成功，保存API地址: {$apiInfo['full_address']}");
                        }
                    }
                }
                break;
            case 'set_offline':
                $updateData['online'] = 0;
                $updateData['remark'] = '已下线: ' . ($result['message'] ?? '');
                break;
            case 'get_account_info':
                $updateData['username'] = $result['data']['username'] ?? '';
                $updateData['nickName'] = $result['data']['nickname'] ?? '';
                $updateData['friends_count'] = $result['data']['friends_count'] ?? 0;
                $updateData['groups_count'] = $result['data']['groups_count'] ?? 0;
                $avatar_url = $result['data']['avatar_url'] ?? '';
                if (is_string($avatar_url) && $avatar_url !== '') {
                    $updateData['avatar_url'] = preg_replace('#^.+/public#', '', $avatar_url);
                } else {
                    $updateData['avatar_url'] = '';
                }
                $updateData['status'] = 1;
                $updateData['remark'] = '检查完成: ' . ($result['message'] ?? '');
                break;
            case 'delete_all_contacts':
                $updateData['remark'] = '好友已删除: ' . ($result['message'] ?? '');
                break;
            case 'update_nickname':
                 $updateData['nickName'] = $result['data']['updated_info']['first_name'] ?? '';
                break;    
            case 'leave_all_groups':
                $updateData['remark'] = '已退出所有群组: ' . ($result['message'] ?? '');
                break;
            case 'logout_other_sessions':
                $updateData['remark'] = '已退出其他设备: ' . ($result['message'] ?? '');
                break;
        }

        if (!empty($result['data']['account_status'])) {
            $updateData['account_status'] = $result['data']['account_status'];
            $updateData['account_status_desc'] = $result['data']['account_status_desc'] ?? '';
        }

        // 记录到缓存
        $this->recordAccountUpdate($userId, $updateData, $batchId);
        $this->updateBuffer[] = $updateData;
        
        // Redis 缓存同步
        try {
            $redisKey = "telegram_task:user:{$userId}";
            // 先查数据库用户最新数据，避免只存部分字段
            $this->redis->del($redisKey);
           
        } catch (\Exception $e) {
            Log::error("[handleServiceResult] Redis 缓存更新失败 (user_id={$userId}): " . $e->getMessage());
        }
    }
    /**
     * 记录端口分配到缓存
     */
    private function recordPortAssignments(array $portTasks, string $batchId): void
    {
        try {
            $assignments = [];
            
            foreach ($portTasks as $port => $tasks) {
                $portConfig = $this->getPortConfigByPort($port);
                if (!$portConfig) continue;
                
                $fullAddress = $this->getApiAddress($portConfig);
                
                foreach ($tasks as $task) {
                    $userId = $task['user_id'];
                    $assignments[$userId] = [
                        'host' => $portConfig['host'] ?? '127.0.0.1',
                        'port' => $portConfig['port'],
                        'full_address' => $fullAddress,
                        'assigned_at' => time()
                    ];
                }
            }
            
            $taskCacheKey = "task_{$batchId}_port_assignments";
            $this->redis->setex($taskCacheKey, 3600, json_encode($assignments)); // 缓存1小时
            
            Log::info("[recordPortAssignments] 已记录端口分配信息，任务数: " . count($assignments));
            
        } catch (\Exception $e) {
            Log::error("[recordPortAssignments] 记录端口分配失败: " . $e->getMessage());
        }
    }
    /**
     * 处理任务失败情况
     */
    private function handleTaskFailure(int $userId, array $result, string $batchId)
    {
        $account_status = '异常';
        $account_status_desc = '';
        
        // 检查 data 键是否存在且是数组
        if (isset($result['data']) && is_array($result['data'])) {
            $account_status = $result['data']['account_status'] ?? '异常';
            $account_status_desc = $result['data']['account_status_desc'] ?? ($result['message'] ?? '任务失败');
        } else {
            $account_status_desc = "结果数据结构异常";
        }
        
        $this->updateBuffer[] = [
            'id' => $userId,
            'status' => 0,
            'remark' => '任务失败',
            'updatetime' => time(),
            'account_status' => $account_status,
            'account_status_desc' => $account_status_desc
        ];
        
        // 准备缓存数据
        $updateData = [
            'id' => $userId,
            'account_status' => $account_status,
            'account_status_desc' => $account_status_desc,
            'online' => 0,
            'status' => 1,
            'remark' => '任务失败',
            'update_time' => time()
        ];
        // 记录到缓存
        $this->recordAccountUpdate($userId, $updateData, $batchId);
    }
    /**
     * 获取当前任务使用的API信息
     */
    private function getCurrentApiInfo(int $userId, string $batchId): ?array
    {
        try {
            // 从任务缓存中获取端口分配信息
            $taskCacheKey = "task_{$batchId}_port_assignments";
            $portAssignments = $this->redis->get($taskCacheKey);
            
            if ($portAssignments) {
                $assignments = json_decode($portAssignments, true);
                $userAssignment = $assignments[$userId] ?? null;
                
                if ($userAssignment) {
                    return [
                        'host' => $userAssignment['host'],
                        'port' => $userAssignment['port'],
                        'full_address' => $userAssignment['full_address']
                    ];
                }
            }
            
            // 如果缓存中没有，从端口配置中查找
            $portConfig = $this->getBalancedPortConfig($userId);
            if ($portConfig) {
                $fullAddress = $this->getApiAddress($portConfig);
                return [
                    'host' => $portConfig['host'] ?? '127.0.0.1',
                    'port' => $portConfig['port'],
                    'full_address' => $fullAddress
                ];
            }
            
            Log::warning("[getCurrentApiInfo] 无法获取用户 {$userId} 的API信息");
            return null;
            
        } catch (\Exception $e) {
            Log::error("[getCurrentApiInfo] 获取API信息失败: " . $e->getMessage());
            return null;
        }
    }
    /**
     * 刷新更新缓冲区，支持数据库连接重连
     */
    private function flushUpdateBuffer()
    {
        if (empty($this->updateBuffer)) return;

        $bufferCount = count($this->updateBuffer);
        try {
            $model = new MtuserModel();
            // 分批次插入，避免数据量过大
            $batches = array_chunk($this->updateBuffer, $this->batchSize);
            $totalUpdated = 0;
            
            foreach ($batches as $batch) {
                $updated = $model->saveAll($batch);
                $totalUpdated += count($updated);
            }
            
            Log::info("[flushUpdateBuffer] 批量更新成功，共 {$totalUpdated}/{$bufferCount} 条记录");
        } catch (\Exception $e) {
            Log::error("[flushUpdateBuffer] 批量更新失败: " . $e->getMessage());
            
            // 批量失败时降级为逐条更新
            foreach ($this->updateBuffer as $item) {
                try {
                    MtuserModel::update($item);
                } catch (\Exception $subE) {
                    Log::error("[flushUpdateBuffer] 单条更新失败 (ID: {$item['id']}): " . $subE->getMessage());
                }
            }
        }

        $this->updateBuffer = [];
    }

    /**
     * 更新任务进度到Redis
     */
    private function updateTaskProgress(string $batchId, int $total, int $success, int $failed)
    {
        $cacheKey = "task_{$batchId}_progress";
        // 无数据更新时直接返回
        if ($success <= 0 && $failed <= 0) {
            return;
        }
        
        if (!$this->redis->exists($cacheKey)) return;

        $pipe = $this->redis->multi(\Redis::PIPELINE);
        $pipe->hIncrBy($cacheKey, 'completed', $total);
        $pipe->hIncrBy($cacheKey, 'success', $success);
        $pipe->hIncrBy($cacheKey, 'failed', $failed);
        
        $pipe->exec();
    }

    /**
     * 检查是否所有批次都已完成
     */
    private function isAllBatchesCompleted(string $batchId): bool
    {
        $cacheKey = "task_{$batchId}_progress";
        
        $total = $this->redis->hGet($cacheKey, 'total');
        $completed = $this->redis->hGet($cacheKey, 'completed');
        
        return (int)$completed >= (int)$total;
    }

    /**
     * 最终化任务进度
     */
    private function finalizeTaskProgress(string $batchId)
    {
        $cacheKey = "task_{$batchId}_progress";
        
        $this->redis->hMSet($cacheKey, [
            'status' => 'completed',
            'end_time' => time(),
            'finish_time' => date('Y-m-d H:i:s')
        ]);
        // 清理端口分配缓存
        $this->cleanupPortAssignments($batchId);
    }

    /**
     * 处理致命错误
     */
    private function handleFatalError(array $data, string $errorMsg)
    {
        foreach ($data['tasks'] as $task) {
            if (!empty($task['user_id'])) {
                $this->handleTaskFailure($task['user_id'], ['status' => false, 'message' => '系统异常: ' . $errorMsg], $data['batch_id']);
            }
        }
        
        $this->flushUpdateBuffer();
        $this->updateTaskProgress($data['batch_id'], count($data['tasks']), 0, count($data['tasks']));
    }

    /**
     * 记录账号状态更新到缓存
     */
    private function recordAccountUpdate(int $userId, array $updateData, string $batchId)
    {
        try {
            $accountCacheKey = "task_{$batchId}_updated_accounts";
            $userCacheKey = "task_{$batchId}_account_{$userId}";
            
            // 准备缓存数据
            $cacheData = [
                'id' => $userId,
                'account_status' => $updateData['account_status'] ?? '',
                'account_status_desc' => $updateData['account_status_desc'] ?? '',
                'online' => $updateData['online'] ?? 0,
                'status' => $updateData['status'] ?? 0,
                'remark' => $updateData['remark'] ?? '',
                'update_time' => time()
            ];
            
            // 添加到账号集合
            $this->redis->sAdd($accountCacheKey, $userId);
            $this->redis->expire($accountCacheKey, $this->config['user_cache_expire'] ?? 7200);
            // 缓存账号数据，设置过期时间（例如1小时）
            $this->redis->setex($userCacheKey, 3600, json_encode($cacheData));
            
            Log::info("[recordAccountUpdate] 已记录账号状态更新: user_id={$userId}, batch_id={$batchId}");
            
        } catch (\Exception $e) {
            Log::error("[recordAccountUpdate] 记录账号状态更新失败: " . $e->getMessage());
        }
    }

    /**
     * 队列任务失败回调
     */
    public function failed($data)
    {
        Log::error("[TelegramTask] 任务执行失败: " . json_encode($data));
        
        if (!empty($data['batch_id'])) {
            Cache::store('redis')->set(
                "failed_task_{$data['batch_id']}",
                json_encode($data),
                86400
            );
        }
    }
    
    /**
     * 清理端口分配缓存
     */
    private function cleanupPortAssignments(string $batchId): void
    {
        try {
            $taskCacheKey = "task_{$batchId}_port_assignments";
            $this->redis->del($taskCacheKey);
            Log::info("[cleanupPortAssignments] 已清理端口分配缓存: {$batchId}");
        } catch (\Exception $e) {
            Log::error("[cleanupPortAssignments] 清理端口分配缓存失败: " . $e->getMessage());
        }
    }
    /**
     * 析构函数：确保缓冲区数据写入
     */
    public function __destruct()
    {
        $this->flushUpdateBuffer();
    }
}