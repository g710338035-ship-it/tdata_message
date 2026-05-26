<?php
namespace app\job;

use think\queue\Job;
use app\admin\model\Mtuser as MtuserModel;
use app\admin\model\TelegramPorts as TelegramPortsModel;
use think\facade\Log;
use think\facade\Db;
use think\facade\Cache;
use GuzzleHttp\Client;
use GuzzleHttp\Promise;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Exception\RequestException;
use think\facade\Config;

class MtuserHandleJob
{
    private $batchSize = 20;
    private $insertBuffer = [];
    private $failedBuffer = [];
    private $httpClient;
    private $tdataCache = [];
    private $redis;
    private $availablePorts = [];
    private $portsCacheKey = 'telegram_available_ports';

    public function __construct()
    {
        $this->batchSize = Config::get('telegram.batch_size', 10);
        $this->httpClient = new Client([
            'timeout'         => 120.0,
            'connect_timeout' => 10.0,
            'read_timeout'    => 60.0,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'verify' => false,
        ]);
        $this->redis = Cache::store('redis')->handler();
        $this->loadAvailablePorts();
    }

    /**
     * 加载可用端口配置
     */
    private function loadAvailablePorts(): void
    {
        try {
            $cachedPorts = $this->redis->get($this->portsCacheKey);
            if ($cachedPorts) {
                $this->availablePorts = json_decode($cachedPorts, true);
                Log::info("[loadAvailablePorts] 从缓存加载端口配置，数量: " . count($this->availablePorts));
                return;
            }
            
            $this->availablePorts = TelegramPortsModel::getAvailablePorts();
            $this->redis->setex($this->portsCacheKey, 300, json_encode($this->availablePorts));
            
            Log::info("[loadAvailablePorts] 从数据库加载端口配置，数量: " . count($this->availablePorts));
            
        } catch (\Exception $e) {
            Log::error("[loadAvailablePorts] 加载端口配置失败: " . $e->getMessage());
        }
    }

    /**
     * 核心任务方法
     */
    public function fire(Job $job, $data)
    {
        $pid = getmypid();
        $batchId = $data['batch_id'] ?? 'unknown';
        $subBatchId = $data['sub_batch_id'] ?? 'unknown_sub';
        $chunkIndex = $data['chunk_index'] ?? -1;

        Log::info("进程{$pid}开始处理：主批次{$batchId}，子批次{$subBatchId}（索引{$chunkIndex}）");

        // 1. 子批次锁检查
        if (!$this->acquireSubBatchLock($subBatchId, $pid)) {
            $job->delete();
            return;
        }

        // 2. 标记子批次状态
        $this->markSubBatchProcessing($batchId, $chunkIndex, $pid);

        $successCount = 0;
        $failedCount = 0;
        $accountList = $data['accounts'] ?? [];
        $totalCount = count($accountList);

        try {
            Log::info("开始处理批次 {$batchId}，子批次 {$chunkIndex}，账号数: {$totalCount}");

            // 3. 批量检查tdata目录
            $validAccounts = $this->batchCheckTdataDir($accountList);
            if (empty($validAccounts)) {
                throw new \Exception("批量检查后无有效账号（共{$totalCount}个）");
            }

            Log::info("有效账号数：" . count($validAccounts) . "（原始共{$totalCount}个）");

            // 4. 按端口分组并并发调用Python接口
            $pythonResults = $this->batchCallPythonByPort($validAccounts, $batchId);
            
            // 5. 处理Python返回结果
            list($successCount, $failedCount) = $this->processPythonResults($pythonResults, $data);

            // 6. 刷新缓冲区
            $this->flushAllBuffers();

            // 7. 更新进度
            $this->batchUpdateRedisProgress($batchId, $chunkIndex, $successCount, $failedCount);

            Log::info("进程{$pid}：子批次{$subBatchId}处理完成，成功{$successCount}，失败{$failedCount}");

        } catch (\Exception $e) {
            Log::error("批次 {$batchId} 处理异常：" . $e->getMessage());
            
            // 异常情况下，将所有未处理账号标记为失败
            $processedCount = $successCount + $failedCount;
            $unprocessedCount = $totalCount - $processedCount;
            if ($unprocessedCount > 0) {
                $this->handleUnprocessedAccounts($accountList, $data, $e->getMessage());
                $failedCount += $unprocessedCount;
                $this->batchUpdateRedisProgress($batchId, $chunkIndex, 0, $unprocessedCount);
            }
        } finally {
            // 释放锁和缓冲区
            $this->releaseSubBatchLock($subBatchId);
            $this->flushAllBuffers();
            $job->delete();
            Log::info("进程{$pid}：子批次{$subBatchId}任务结束");
        }
    }

    /**
     * 按端口分组并并发调用Python接口
     */
    private function batchCallPythonByPort(array $validAccounts, string $batchId): array
    {
        if (empty($validAccounts)) {
            return [];
        }

        // 1. 按端口分组账号
        $portGroups = $this->groupAccountsByPort($validAccounts);
        Log::info("批次 {$batchId}：按端口分组完成，分组数: " . count($portGroups));

        $allResults = [];
        $promises = [];

        // 2. 为每个端口创建异步请求
        foreach ($portGroups as $port => $accounts) {
            if (empty($accounts)) continue;

            $portConfig = $this->getPortConfigByPort($port);
            if (!$portConfig) {
                Log::error("端口配置不存在: {$port}，跳过处理");
                // 标记该端口所有账号为失败
                foreach ($accounts as $account) {
                    $allResults[] = [
                        'success' => false,
                        'phone' => $account['phone'],
                        'accountDir' => $account['accountDir'],
                        'error' => "端口配置不存在: {$port}"
                    ];
                }
                continue;
            }

            $address = $this->getApiAddress($portConfig);
            
            // 增加连接数计数
            $this->incrementPortConnections($port);
            
            $promises[$port] = $this->callPythonApiAsync($accounts, $address, $batchId, $port);
        }

        // 3. 等待所有端口响应
        if (!empty($promises)) {
            try {
                $settledResults = Utils::settle($promises)->wait();
                Log::info("批次 {$batchId}：所有端口请求完成");
                
                // 处理每个端口的结果
                foreach ($settledResults as $port => $result) {
                    // 减少连接数计数
                    $this->decrementPortConnections($port);
                    
                    if ($result['state'] === 'fulfilled') {
                        $portResults = $result['value'];
                        $allResults = array_merge($allResults, $portResults);
                        Log::info("端口 {$port} 处理完成: " . count($portResults) . " 个账号");
                    } else {
                        Log::error("端口 {$port} 处理失败: " . $result['reason']->getMessage());
                        // 标记该端口所有账号为失败
                        foreach ($portGroups[$port] as $account) {
                            $allResults[] = [
                                'success' => false,
                                'phone' => $account['phone'],
                                'accountDir' => $account['accountDir'],
                                'error' => "端口 {$port} 处理失败: " . $result['reason']->getMessage()
                            ];
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::error("批次 {$batchId}：并发请求异常: " . $e->getMessage());
                // 所有端口都失败，减少连接数
                foreach ($portGroups as $port => $accounts) {
                    $this->decrementPortConnections($port);
                    foreach ($accounts as $account) {
                        $allResults[] = [
                            'success' => false,
                            'phone' => $account['phone'],
                            'accountDir' => $account['accountDir'],
                            'error' => "并发请求异常: " . $e->getMessage()
                        ];
                    }
                }
            }
        }

        return $allResults;
    }

    /**
     * 按端口分组账号
     */
    private function groupAccountsByPort(array $validAccounts): array
    {
        $portGroups = [];

        foreach ($validAccounts as $account) {
            $phone = $account['phone'] ?? '';
            
            // 基于手机号哈希分配端口，使用数据库中的端口配置
            $portConfig = $this->getBalancedPortConfig($phone);
            if (!$portConfig) {
                Log::warning("无法为账号 {$phone} 分配端口，使用默认端口");
                $portConfig = $this->availablePorts[0] ?? null;
            }

            if ($portConfig) {
                $port = $portConfig['port'];
                if (!isset($portGroups[$port])) {
                    $portGroups[$port] = [];
                }
                $portGroups[$port][] = $account;
            }
        }

        // 记录分组情况
        foreach ($portGroups as $port => $accounts) {
            Log::info("端口 {$port} 分配到 " . count($accounts) . " 个账号");
        }

        return $portGroups;
    }

    /**
     * 获取负载均衡端口配置
     */
    private function getBalancedPortConfig(string $phone): ?array
    {
        if (empty($this->availablePorts)) {
            Log::error("[getBalancedPortConfig] 没有可用的端口配置");
            return null;
        }
        
        // 过滤可用的端口（连接数未满且状态正常）
        $availablePorts = array_filter($this->availablePorts, function($portConfig) {
            $currentConnections = $portConfig['current_connections'] ?? 0;
            $maxConnections = $portConfig['max_connections'] ?? 100;
            $status = $portConfig['status'] ?? 1;
            
            return $status === 1 && $currentConnections < $maxConnections;
        });
        
        if (empty($availablePorts)) {
            Log::warning("[getBalancedPortConfig] 所有端口连接数已满或不可用");
            return $this->availablePorts[0] ?? null;
        }
        
        // 基于手机号哈希分配，确保同一手机号总是分配到相同端口
        $portIndex = crc32($phone) % count($availablePorts);
        $portConfig = array_values($availablePorts)[$portIndex];
        
        Log::info("[getBalancedPortConfig] 账号 {$phone} 分配到端口 {$portConfig['port']}");
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
     * 根据端口配置获取完整的API地址
     */
    private function getApiAddress(array $portConfig): string
    {
        $host = $portConfig['host'] ?? '127.0.0.1';
        $port = $portConfig['port'] ?? 5000;
        return "http://{$host}:{$port}";
    }

    /**
     * 增加端口连接数
     */
    private function incrementPortConnections(int $port): void
    {
        try {
            TelegramPortsModel::incrementConnections($port);
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
     * 异步调用单个端口的Python API
     */
    private function callPythonApiAsync(array $accounts, string $address, string $batchId, int $port): Promise\PromiseInterface
    {
        $apiEndpoint = "{$address}/batch_check_account";

        // 构造批量请求参数
        $batchParams = array_map(function ($account) {
            return [
                'tdata_path' => (string)($account['tdataDir'] ?? ''),
                'tdata_phone' => (string)($account['phone'] ?? ''),
                'account_id' => md5($account['tdataDir'] . $account['phone'])
            ];
        }, $accounts);

        return $this->httpClient->postAsync($apiEndpoint, [
            'json' => ['accounts' => $batchParams],
            'timeout' => 30.0,
            'connect_timeout' => 5.0,
            'headers' => [
                'X-Batch-Id' => $batchId,
                'X-Port' => $port,
                'X-Request-Count' => count($batchParams)
            ]
        ])->then(
            function ($response) use ($batchId, $port, $batchParams) {
                $statusCode = $response->getStatusCode();
                if ($statusCode < 200 || $statusCode >= 300) {
                    throw new \Exception("Python接口返回非成功状态码：{$statusCode}");
                }

                $body = $response->getBody()->getContents();
                $data = json_decode($body, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception("Python接口返回无效JSON：" . json_last_error_msg());
                }

                if (!is_array($data)) {
                    throw new \Exception("Python接口返回非数组结构");
                }

                // 处理返回结果
                $results = [];
                $accountMap = array_column($batchParams, null, 'account_id');
                
                foreach ($data as $item) {
                    if (!isset($item['account_id'], $item['phone'], $item['result'])) {
                        Log::warning("端口 {$port}：无效返回项: " . json_encode($item));
                        continue;
                    }

                    $accountId = $item['account_id'];
                    if (!isset($accountMap[$accountId])) {
                        Log::warning("端口 {$port}：无法匹配账号ID：{$accountId}");
                        continue;
                    }

                    $results[] = [
                        'success' => empty($item['error']),
                        'phone' => $item['phone'],
                        'accountDir' => $accountMap[$accountId]['tdata_path'],
                        'result' => $item['result'] ?? [],
                        'error' => $item['error'] ?? ''
                    ];
                }

                // 检查遗漏的账号
                $returnedIds = array_column($data, 'account_id');
                foreach ($batchParams as $param) {
                    if (!in_array($param['account_id'], $returnedIds)) {
                        $results[] = [
                            'success' => false,
                            'phone' => $param['tdata_phone'],
                            'accountDir' => $param['tdata_path'],
                            'error' => "Python接口未返回该账号结果"
                        ];
                    }
                }

                Log::info("端口 {$port} 处理完成: " . count($results) . " 个结果");
                return $results;
            },
            function ($exception) use ($port) {
                $errorMsg = $exception instanceof RequestException
                    ? ($exception->hasResponse()
                        ? "请求失败：" . $exception->getResponse()->getStatusCode()
                        : "连接失败：" . $exception->getMessage())
                    : "未知错误：" . $exception->getMessage();
                
                throw new \Exception("端口 {$port} 请求异常: {$errorMsg}");
            }
        );
    }

    // 以下辅助方法保持不变...
    private function batchCheckTdataDir(array $accountList): array
    {
        $validAccounts = [];
        foreach ($accountList as $phone => $accountDir) {
            if (empty($phone) || empty($accountDir) || !is_string($accountDir)) {
                Log::warning("无效账号数据：phone={$phone}，accountDir=" . json_encode($accountDir));
                continue;
            }

            $cacheKey = md5($accountDir);
            if (isset($this->tdataCache[$cacheKey])) {
                $tdataDir = $this->tdataCache[$cacheKey];
            } else {
                $tdataDir = $this->findTdataInAccountDir($accountDir);
                $this->tdataCache[$cacheKey] = $tdataDir;
            }

            if ($tdataDir) {
                $validAccounts[] = [
                    'phone' => $phone,
                    'accountDir' => $accountDir,
                    'tdataDir' => $tdataDir
                ];
            } else {
                $this->recordFailedAccount([], $phone, $accountDir, '未找到tdata目录');
            }
        }
        return $validAccounts;
    }

    private function findTdataInAccountDir(string $accountDir): ?string
    {
        $accountDir = rtrim($accountDir, '/');
        $paths = [
            "{$accountDir}/tdata",
            "{$accountDir}/Tdata",
            "{$accountDir}/TDATA",
            $accountDir
        ];

        foreach ($paths as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }
        return null;
    }

    private function processPythonResults(array $pythonResults, array $taskData): array
    {
        $successCount = 0;
        $failedCount = 0;

        foreach ($pythonResults as $item) {
            if ($item['success']) {
                $this->saveAccountInfo($taskData, $item['phone'], $item['accountDir'], $item['result']);
                $successCount++;
            } else {
                $this->recordFailedAccount($taskData, $item['phone'], $item['accountDir'], $item['error']);
                $failedCount++;
            }
        }

        return [$successCount, $failedCount];
    }

    private function saveAccountInfo(array $taskData, string $phone, string $accountDir, array $pythonResult)
    {
        $tdataRelPath = str_replace(public_path(), '', $pythonResult['tdata_path'] ?? $accountDir);
        $finalAccount = $pythonResult['phone'] ?? $phone;

        $insertData = [
            'cateid' => $taskData['cateid'] ?? 0,
            'account' => $finalAccount,
            'tdata_path' => $tdataRelPath ?: '',
            'status' => $pythonResult['status'] ?? 0,
            'auth_key' => $pythonResult['auth_key'] ?? '',
            'main_dc_id' => $pythonResult['main_dc_id'] ?? '',
            'uuid' => $pythonResult['user_id'] ?? '',
            'username' => $pythonResult['username'] ?? '',
            'nickName' => $pythonResult['nickname'] ?? '',
            'region' => $pythonResult['country'] ?? '',
            'is_authorized' => isset($pythonResult['is_authorized']) ? (int)$pythonResult['is_authorized'] : 0,
            'session_path' => $pythonResult['session_path'] ?? '',
            'remark' => $pythonResult['message'] ?? '',
            'addtime' => time(),
            'online' => $pythonResult['online'] ?? 0,
            'admin_id' => $taskData['userid'] ?? 0,
            'task_status' => 2,
            'task_error' => '',
            'account_status' => $pythonResult['account_status'] ?? '账户错误',
            'account_status_desc' => $pythonResult['account_status_desc'] ?? '账户解析错误'
        ];

        $this->insertBuffer[] = $insertData;
        if (count($this->insertBuffer) >= $this->batchSize) {
            $this->flushInsertBuffer();
        }
    }

    private function recordFailedAccount(array $taskData, string $phone, string $accountDir, string $errorMsg)
    {
        $insertData = [
            'cateid' => $taskData['cateid'] ?? 0,
            'account' => $phone,
            'tdata_path' => str_replace(public_path(), '', $accountDir) ?: '',
            'status' => 0,
            'remark' => '任务处理失败',
            'addtime' => time(),
            'online' => 0,
            'admin_id' => $taskData['userid'] ?? 0,
            'task_status' => 3,
            'task_error' => mb_substr($errorMsg, 0, 500),
            'account_status' => '异常',
            'account_status_desc' => mb_substr($errorMsg, 0, 500)
        ];

        $this->failedBuffer[] = $insertData;
        if (count($this->failedBuffer) >= $this->batchSize / 2) {
            $this->flushFailedBuffer();
        }
    }

    private function acquireSubBatchLock(string $subBatchId, int $pid): bool
    {
        $lockKey = "sub_batch_lock:{$subBatchId}";
        if (!$this->redis->setnx($lockKey, $pid)) {
            Log::warning("子批次{$subBatchId}已被其他进程锁定，跳过处理");
            return false;
        }
        $this->redis->expire($lockKey, 300);
        return true;
    }

    private function releaseSubBatchLock(string $subBatchId): void
    {
        $this->redis->del("sub_batch_lock:{$subBatchId}");
    }

    private function markSubBatchProcessing(string $batchId, int $chunkIndex, int $pid): void
    {
        $this->redis->hSet("task_{$batchId}_subs", $chunkIndex, json_encode([
            'status' => 'processing',
            'pid' => $pid,
            'start_time' => time()
        ], JSON_UNESCAPED_UNICODE));
    }

    private function handleUnprocessedAccounts(array $accountList, array $taskData, string $errorMsg): void
    {
        foreach ($accountList as $phone => $accountDir) {
            $this->recordFailedAccount($taskData, $phone, $accountDir, "全局异常: {$errorMsg}");
        }
    }

    private function flushInsertBuffer(): void
    {
        if (empty($this->insertBuffer)) return;
        
        try {
            // 使用 INSERT IGNORE 忽略重复记录
            $result = Db::name('mtuser')
                ->data($this->insertBuffer)
                ->strict(false)
                ->insertAll($this->insertBuffer, true);
            
            Log::info("批量插入有效账号成功：插入" . $result . "条数据，跳过" . (count($this->insertBuffer) - $result) . "条重复数据");
        } catch (\Exception $e) {
            Log::error("批量插入有效账号失败：" . $e->getMessage());
            
            // 如果是唯一键冲突，尝试逐条插入
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                $this->insertOneByOne($this->insertBuffer);
            }
        }
        $this->insertBuffer = [];
    }
    
    /**
     * 逐条插入，忽略重复记录
     */
    private function insertOneByOne(array $insertBuffer): void
    {
        $successCount = 0;
        $duplicateCount = 0;
        
        foreach ($insertBuffer as $data) {
            try {
                // 使用 INSERT IGNORE
                $sql = "INSERT IGNORE INTO " . config('database.connections.mysql.prefix') . "mtuser 
                       (cateid, account, tdata_path, status, auth_key, main_dc_id, uuid, username, 
                        nickName, region, is_authorized, session_path, remark, addtime, online, 
                        admin_id, task_status, task_error, account_status, account_status_desc) 
                       VALUES 
                       (:cateid, :account, :tdata_path, :status, :auth_key, :main_dc_id, :uuid, :username, 
                        :nickName, :region, :is_authorized, :session_path, :remark, :addtime, :online, 
                        :admin_id, :task_status, :task_error, :account_status, :account_status_desc)";
                
                Db::execute($sql, $data);
                $successCount++;
                
            } catch (\Exception $e) {
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $duplicateCount++;
                    Log::warning("账号 {$data['account']} 已存在，跳过插入");
                } else {
                    Log::error("插入账号 {$data['account']} 失败：" . $e->getMessage());
                }
            }
        }
        
        Log::info("逐条插入完成：成功{$successCount}条，重复{$duplicateCount}条，失败" . (count($insertBuffer) - $successCount - $duplicateCount) . "条");
    }

    private function flushFailedBuffer(): void
    {
        if (empty($this->failedBuffer)) return;
        
        try {
            MtuserModel::insertAll($this->failedBuffer);
            Log::info("批量插入失败账号成功：" . count($this->failedBuffer) . "条数据");
        } catch (\Exception $e) {
            Log::error("批量插入失败账号失败：" . $e->getMessage());
        }
        $this->failedBuffer = [];
    }

    private function flushAllBuffers(): void
    {
        $this->flushInsertBuffer();
        $this->flushFailedBuffer();
    }

    private function batchUpdateRedisProgress(string $batchId, int $chunkIndex, int $successCount, int $failedCount): void
    {
        $progressKey = "task_{$batchId}_progress";
        $subsKey = "task_{$batchId}_subs";
        
        if (($successCount <= 0 && $failedCount <= 0) || !$this->redis->exists($progressKey)) {
            return;
        }

        $pipe = $this->redis->multi(\Redis::PIPELINE);
        $pipe->hIncrBy($progressKey, 'completed', $successCount + $failedCount);
        $pipe->hIncrBy($progressKey, 'success', $successCount);
        $pipe->hIncrBy($progressKey, 'failed', $failedCount);
        
        $pipe->hSet($subsKey, $chunkIndex, json_encode([
            'status' => 'completed',
            'success' => $successCount,
            'failed' => $failedCount,
            'end_time' => time()
        ], JSON_UNESCAPED_UNICODE));

        $pipe->exec();
    }

    public function __destruct()
    {
        $this->flushAllBuffers();
    }
}