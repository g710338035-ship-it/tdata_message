<?php
namespace app\job;

use think\queue\Job;
use think\facade\Log;
use think\facade\Cache;
use think\facade\Db;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use app\admin\model\Mttask as MttaskModel;
use app\admin\model\Mtuser as MtuserModel;
use think\facade\Queue;


class TelegramTaskExecutor
{
    // Redis缓存前缀
    private string $redisPrefix = 'telegram_task:';
    private $redis;
    
    // 配置参数
    private array $config = [
        'task_timeout' => 36000,         // 任务超时时间(秒)
        'status_check_interval' => 60,    // 状态检查间隔(秒)
        'redis_batch_size' => 1000,      // Redis批量操作大小
        'max_retry_count' => 3,          // 最大重试次数
        'retry_delay' => 30,             // 重试延迟时间(秒)
        'user_cache_expire' => 7200,    // 用户缓存过期时间(秒)
        'cache_expire' => 604800,         // 缓存过期时间(秒)
        'task_cache_expire' => 86400,     // 任务配置缓存时间(秒)
        'cycle_cache_expire' => 604800,  // 循环计数缓存时间(秒)
        'mysql_gate_limit' => 200,
        'mysql_gate_retry_delay' => 3,
        'group_cache_expire' => 604800, // 分组缓存过期时间(秒)
        'message_cache_expire' => 604800, // 消息缓存过期时间(秒)
        'max_send_retry' => 10,          // 最大发送重试次数
    ];
    
    // 任务状态常量
    const status_pending = 1;       // 未开始
    const status_running = 2;       // 运行中
    const status_completed = 3;     // 已完成
    const status_failed = 4;        // 失败
    const status_stopped = 5;       // 已停止
    const status_filtered = 6;      // 已过滤
    const status_paused = 7;        // 已暂停

    public function __construct()
    {
        $this->redis = Cache::store('redis')->handler();
    }
    
    /**
     * 执行队列任务
     */
    public function fire(Job $job, $data): void
    {
        if (empty($data['task']['id'])) {
            Log::error("队列任务数据无效，缺少task_id");
            $job->delete();
            return;
        }

        $taskId = $data['task']['id'];
        $instanceId = $data['task']['instance_id'] ?? uniqid('task_', true);
        #$currentIndex = $data['task']['current_index'] ?? 0;
        
        // 1. 添加分布式锁防止重复处理
        $lockKey = $this->getRedisKey("task_lock:{$taskId}");
        $taskLockTtl = (int)($this->config['task_lock_ttl'] ?? 60);
        if (!$this->acquireLock($lockKey, $instanceId, $taskLockTtl)) {
            Log::info("任务 {$taskId} 正在被其他进程处理，跳过当前实例: {$instanceId}");
            Log::warning("任务 {$taskId} 正在被其他进程处理,请等待1分钟再试");
            $job->delete();
            return;
        }
        
        $progressKey = $this->getRedisKey("last_processed:{$taskId}");
        $currentIndex = $this->redis->get($progressKey) ?: ($data['task']['current_index'] ?? 0);
        
        Log::info("开始处理队列任务: task_id={$taskId}, current_index={$currentIndex}");
        //数据库检测
        if (!$this->acquireMysqlGate()) {
            $delay = $this->config['mysql_gate_retry_delay'];
            $newData = $data;
            $newData['task']['current_index'] = $currentIndex;
            Queue::later($delay, self::class, $newData, $job->getQueue());
            $job->delete();
            return;
        }

        // 检查任务是否已经超过最大尝试次数
        if ($job->attempts() > $this->config['max_retry_count']) {
            Log::warning("任务 {$taskId} 已超过最大重试次数({$this->config['max_retry_count']}次)，标记为失败");
            $this->markTaskAsFailed($taskId, "任务已超过最大重试次数");
            $job->delete();
            return;
        }

        // 锁定任务并获取详情
        try {
            // 使用事务和悲观锁确保任务不会被重复处理
            $task = Db::transaction(function () use ($taskId) {
                $task = MttaskModel::where('id', $taskId)->lock(true)->find();      

                if (!$task) {
                    throw new \Exception("任务不存在");
                }
                
                // 检查任务状态是否允许执行
                if (!in_array($task->status, [self::status_running, self::status_paused, self::status_pending])) {
                    throw new \Exception("任务状态不允许执行，当前状态: {$task->status}");
                }
                
                // 如果是暂停状态，恢复为运行中
                if ($task->status == self::status_paused) {
                    $this->writeTaskLog($taskId, "任务从暂停状态恢复执行");
                }
                //$workerId = posix_getpid() . '_' . uniqid();
                // 更新任务为运行中
                $task->save([
                    'status' => self::status_running,
                    'lock_time' => time(),
                    'worker_pid' => posix_getpid(),
                    'update_time' => time()
                ]);
                
                return $task;
            });
        } catch (\Exception $e) {
            Log::error("任务 {$taskId} 锁定失败: " . $e->getMessage());
            
            // 根据错误类型决定处理方式
            if (strpos($e->getMessage(), "不存在") !== false || strpos($e->getMessage(), "状态不允许") !== false) {
                $this->releaseMysqlGate();
                $job->delete();
            } else {
                if ($this->isTooManyConnectionsError($e)) {
                    $this->releaseMysqlGate();
                    $job->release($this->config['mysql_gate_retry_delay']);
                } else {
                    $job->release($this->config['retry_delay']);
                }
            }
            return;
        }

        // 执行任务处理
        try {
            // 设置任务超时检测
            pcntl_alarm($this->config['task_timeout']);
            pcntl_signal(SIGALRM, function () use ($taskId) {
                Log::error("任务 {$taskId} 执行超时");
                $this->markTaskAsFailed($taskId, "任务执行超时");
                $this->releaseMysqlGate();
                exit(1);
            });
            
            // 执行核心处理逻辑
            $result = $this->processTask($task, $currentIndex, $job);
            
            // 取消超时检测
            pcntl_alarm(0);
            //处理任务处理结果
            $this->handleProcessResult($taskId, $data, $job, $result);
            
        } catch (\Exception $e) {
            pcntl_alarm(0); // 取消超时检测
            Log::error("任务 {$taskId} 处理异常: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            
            // 标记任务为失败
            $this->markTaskAsFailed($taskId, $e->getMessage());
            $this->releaseMysqlGate();
            $job->delete();
        } finally {
            // 释放锁
            $this->releaseLock($lockKey, $instanceId);
        }
    }
    /**
     * 获取分布式锁
     */
    private function acquireLock(string $key, string $instanceId, int $ttl = 30): bool
    {
        if ($ttl <= 0) {
            $ttl = 30;
        }
        
        try {
            $result = $this->redis->set($key, $instanceId, ['nx', 'ex' => $ttl]);
            if ($result === true || $result === 'OK') {
                return true;
            }
        } catch (\Throwable $e) {
        }
        
        try {
            $result = $this->redis->setnx($key, $instanceId);
            if ($result) {
                $this->redis->expire($key, $ttl);
                return true;
            }
        } catch (\Throwable $e) {
        }
        
        return false;
    }
    
    /**
     * 释放分布式锁
     */
    private function releaseLock(string $key, string $instanceId): void
    {
        // 只有锁的持有者才能释放
        if ($this->redis->get($key) === $instanceId) {
            $this->redis->del($key);
        }
    }
    /**
     * 处理任务处理结果
     */
    private function handleProcessResult(int $taskId, array $data, Job $job, array $result): void
    {
        switch ($result['action'] ?? '') {
            case 'requeue':
                $delay = $result['delay'] ?? 60;
                $nextIndex = $result['nextIndex'] ?? 0;
                Log::info("任务 {$taskId} 即将为下一轮重新入队，延迟 {$delay} 秒，从索引 {$nextIndex} 开始");
                // 立即更新进度，避免其他worker处理重复索引
                $progressKey = $this->getRedisKey("last_processed:{$taskId}");
                $this->redis->set($progressKey, $nextIndex);
                
                $newData = $data;
                $newData['task']['current_index'] = $nextIndex;
                Queue::later($delay, self::class, $newData, $job->getQueue());
                $this->releaseMysqlGate();
                $job->delete();
                break;
                
            case 'continue':
                if (isset($result['nextIndex'])) {
                    $delay = $result['delay'] ?? 1;
                    
                    $progressKey = $this->getRedisKey("last_processed:{$taskId}");
                    $this->redis->set($progressKey, $result['nextIndex']);
                    
                    
                    
                    // 检查是否已有相同索引的任务在队列中
                    $taskCheckKey = $this->getRedisKey("task_check:{$taskId}:{$result['nextIndex']}");
                    if ($this->redis->get($taskCheckKey)) {
                        Log::info("任务 {$taskId} 索引 {$result['nextIndex']} 已在队列中，跳过重复添加");
                        $this->releaseMysqlGate();
                        $job->delete();
                        return;
                    }
                    
                    // 设置检查标记，60秒过期（大于延迟时间）
                    $this->redis->setex($taskCheckKey, $delay + 5, time());
                    
                    // 为每个任务实例生成唯一ID
                    $taskInstanceId = "task_{$taskId}_index_{$result['nextIndex']}_" . microtime(true);
                    
                    Log::info("任务 {$taskId} 将在 {$delay} 秒后继续处理下一条消息，索引: {$result['nextIndex']}, 实例ID: {$taskInstanceId}");
            
                    $newData = $data;
                    $newData['task']['current_index'] = $result['nextIndex'];
                    $newData['task']['instance_id'] = $taskInstanceId;
                    
                    Queue::later($delay, self::class, $newData, $job->getQueue());
                    
                    $this->releaseMysqlGate();
                    $job->delete();
                }    
                break;                
            case 'pause':
                Log::info("任务 {$taskId} 已暂停");
                $this->releaseMysqlGate();
                $job->delete();
                break;
            case 'taskerror':
                Log::info("任务 {$taskId} 错误已暂停");
                $this->markTaskAsFailed($taskId, '错误已暂停');
                $this->releaseMysqlGate();
                $job->delete();
                break;    
            default:
                Log::info("任务 {$taskId} 处理完成，删除队列任务");
                $this->releaseMysqlGate();
                $job->delete();
                break; 
        }
    }
    
    /**
     * 核心任务处理逻辑
     */
    private function processTask($task, int $currentIndex, Job $job): array
    {
        $taskId = $task->id;
        $writeStatus=false;
        if($currentIndex==0) {
            $task->success_count =0;
            $task->fail_count = 0;
            $task->fail_details = [];
            $task->update_time = time();
            $task->save();
            $writeStatus=true;
            
        }
        $this->writeTaskLog($taskId, "任务初始化完成，准备开始发送消息，当前索引: {$currentIndex}",$writeStatus);
        //Log::info("任务 {$taskId} 初始化开始，准备开始发送消息，当前索引: {$currentIndex}");

        // 定义Redis键名
        $statusKey = $this->getRedisKey("status:{$taskId}");//状态
        $lastProcessedKey = $this->getRedisKey("last_processed:{$taskId}");//上次处理索引
        $pauseFlagKey = $this->getRedisKey("pause_flag:{$taskId}");//暂停标志
        //$resumeTimeKey = $this->getRedisKey("resume_time:{$taskId}");//恢复时间
        $taskConfigCacheKey = $this->getRedisKey("config:{$taskId}");//任务配置缓存
        $cycleKey = $this->getRedisKey("cycle:{$taskId}");//循环次数
        
        $this->redis->set($statusKey, self::status_running); // 标记为运行中
        
        // 检查是否是恢复的任务
        $lastProcessedIndex = $this->redis->get($lastProcessedKey);
        $isResumedTask = !empty($lastProcessedIndex) && $lastProcessedIndex > 0;
        
        if ($isResumedTask && $currentIndex == 0) {
            $currentIndex = $lastProcessedIndex;
            //$this->redis->set($resumeTimeKey, time(), 3600);
            $this->writeTaskLog($taskId, "任务从消息索引 {$lastProcessedIndex} 处恢复处理");
            //Log::info("任务 {$taskId} 从消息索引 {$lastProcessedIndex} 处恢复处理");
        }
        
        // 解析任务配置
        $config = $this->getTaskConfig($taskId, $task, $taskConfigCacheKey);
        // 提取配置变量: messages, groupList, concurrent, xhnum, currentCycle
        $messages = $config['messages'];
        $groupList = $config['groupList'];
        $xhnum = $config['xhnum'];
        $currentCycle = $config['currentCycle'];//当前循环次数
        
        // 初始化消息缓存
        $this->initMessageCacheForGroups($taskId, $messages, $groupList, $currentCycle,$currentIndex);
        
        // 检查是否有暂停请求
        if ($this->redis->get($pauseFlagKey)) {
            $this->handlePauseRequest($task, $currentIndex);
            
            return ['action' => 'pause'];
        }
        
        // 获取用户映射表
        list($userMap, $archivedUserIds) = $this->getUserMap($messages);
        
        // 已归档/已删除账号：不丢弃消息，优先自动换号
        $messages = $this->filterArchivedUserMessages($messages, $archivedUserIds, $task, $taskId);
        list($userMap, $archivedUserIds) = $this->getUserMap($messages);
        //log::info(json_encode($userMap));
        //log::info("任务 {$taskId} 过滤后消息数量: ".count($messages)." 已归档/已删除账号数量: ".count($archivedUserIds)." 有效账号数量: ".count($userMap));
        if (empty($messages)) {
            return ['action' => 'complete'];
        }
        
       
        // 检查是否有暂停请求
        if ($this->redis->get($pauseFlagKey)) {
            $this->handlePauseRequest($task, $currentIndex);            
            return ['action' => 'pause'];
        }
        
        // 处理消息
        return $this->processMessages($task, $taskId, $messages, $groupList, $userMap, $currentIndex, $xhnum, $currentCycle, $taskConfigCacheKey, $lastProcessedKey, $statusKey);
    }
    
    /**
     * 获取任务配置
     */
    private function getTaskConfig(int $taskId, $task, string $taskConfigCacheKey): array
    {
        $cachedConfig = $this->redis->get($taskConfigCacheKey);
        //Log::info("任务 {$taskId} 从消息索引 {$taskConfigCacheKey} 处恢复处理".json_encode($cachedConfig));
        if ($cachedConfig !== false && $cachedConfig !== null && $cachedConfig !== '') {
            //$this->writeTaskLog($taskId, "任务 {$taskId} 从缓存恢复配置");
            $config = json_decode($cachedConfig, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($config)) {
                $this->writeTaskLog($taskId, "任务 {$taskId} 缓存配置解析失败，重新生成配置");
            } else {    
                $currentCycle = $this->redis->get($this->getRedisKey("cycle:{$taskId}")) ?: 1;
                return array_merge($config, ['currentCycle' => $currentCycle]);//添加当前循环次数
            }
        }
        
        // 解析配置
        $messages = json_decode($task->messages, true);
        $originalGroupList = $task->group_list ?? '';
        $groupList = !empty($originalGroupList) ? preg_split('/\s*[,，;；]\s*/', $originalGroupList) : [];
        $groupList = array_values(array_filter($groupList));
        $concurrent = $task->concurrent > 0 ? $task->concurrent : 5;
        $xhnum = $task->xhnum > 0 ? $task->xhnum : 1;
        $currentCycle = $this->redis->get($this->getRedisKey("cycle:{$taskId}")) ?: 1;//获取当前循环次数
        
        // 缓存配置
        $this->redis->set($this->getRedisKey("cycle:{$taskId}"), $currentCycle, $this->config['cycle_cache_expire']);//缓存当前循环次数
        $this->redis->set($taskConfigCacheKey, json_encode([
            'messages' => $messages,
            'groupList' => $groupList,
            'concurrent' => $concurrent,
            'xhnum' => $xhnum,
        ]), $this->config['task_cache_expire']);
        
        return [
            'messages' => $messages,
            'groupList' => $groupList,
            'concurrent' => $concurrent,
            'xhnum' => $xhnum,
            'currentCycle' => $currentCycle
        ];
    }
    
    /**
     * 初始化群组消息缓存
     */
    private function initMessageCacheForGroups(int $taskId, array $messages, array $groupList, int $currentCycle,int $currentIndex): void
    {
        foreach ($groupList as $groupId) {
            $cacheKey = $this->getGroupMessageCacheKey($taskId, $groupId);
            if ($currentCycle > 1) {
                $this->initMessageCache($taskId, $messages, [$groupId]);
                Log::info("任务 {$taskId} 第 {$currentCycle} 次循环，重置群组 {$groupId} 消息状态");
                $writeStatus=false;
                if($currentIndex==0) $writeStatus=true;
                $this->writeTaskLog($taskId, "任务 {$taskId} 第 {$currentCycle} 次循环，重置群组 {$groupId} 消息状态",$writeStatus);
                
               
            } elseif (!$this->redis->exists($cacheKey)) {
                $this->initMessageCache($taskId, $messages, [$groupId]);
            }
        }
    }
    
    /**
     * 获取用户映射表
     */
    private function getUserMap(array $messages): array
    {
        $sendUserIds = array_unique(array_column($messages, 'sendUser'));
        $cacheKeys = array_map(function($id) {
            return $this->getRedisKey("user:{$id}");
        }, $sendUserIds);
    
        // 批量获取缓存
        $cachedUsers = $this->redis->mGet($cacheKeys);
        $userMap = [];
        $missedIds = [];
        $archivedUserIds = [];
        
        // 处理缓存命中的数据
        foreach ($sendUserIds as $i => $userId) {
            if (!isset($cachedUsers[$i]) || $cachedUsers[$i] === false) {
                $missedIds[] = $userId;
                continue;
            }
            
            $cacheData = $cachedUsers[$i];
            if ($cacheData === 'null') {
                $archivedUserIds[] = $userId;
                Log::info("用户 {$userId} 已删除（缓存标记），加入过滤列表");
            } elseif ($cacheData) {
                $user = $this->parseUserCacheData($cacheData, $userId);
                if ($user) {
                    $userMap[$userId] = $user;
                    if (isset($user['archive']) && $user['archive'] == 0) {
                        $archivedUserIds[] = $userId;
                        Log::info("用户 {$userId} 已归档，加入过滤列表");
                    }
                } else {
                    $missedIds[] = $userId;
                }
            } else {
                $missedIds[] = $userId;
            }
        }
        
        // 查询未命中缓存的用户
        if (!empty($missedIds)) {
            list($userMap, $archivedUserIds) = $this->fetchAndCacheMissingUsers(
                $missedIds, $userMap, $archivedUserIds
            );
        }
        
        return [$userMap, array_unique($archivedUserIds)];
    }
    
    /**
     * 解析用户缓存数据
     */
    private function parseUserCacheData(string $cacheData, int $userId): ?array
    {
        $unserializedData = @unserialize($cacheData);
        $jsonData = $unserializedData !== false ? $unserializedData : $cacheData;
        $user = json_decode($jsonData, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning("用户 {$userId} 缓存数据解析失败: " . json_last_error_msg());
            return null;
        }
        
        return $user;
    }

    private function getUserInfo(int $userId): ?array
    {
        $user = Db::name('mtuser')
            ->where('id', $userId)
            ->field('id, tdata_path, account, session_path, proxyip, archive, account_status, account_status_desc,last_api_address')
            ->find();
        if ($user) {
            $this->redis->set($this->getRedisKey("user:{$userId}"), json_encode($user), $this->config['user_cache_expire']);
            return (array)$user;
        }
        return null;
    }


    private function getLatestMessageByIndex(int $taskId, int $index): ?array
    {
        $messagesJson = MttaskModel::where('id', $taskId)->value('messages');
        if (!$messagesJson) {
            return null;
        }
        $messages = json_decode($messagesJson, true);
        if (!is_array($messages)) {
            return null;
        }
        return $messages[$index] ?? null;
    }
    
    
    private function isUserUsable(?array $user): bool
    {
        return $user
            && (int)($user['archive'] ?? 0) === 1
            && ($user['account_status'] ?? '') === '正常'
            && !empty($user['session_path'])
            && !empty($user['last_api_address']);
    }

    private function getUserFresh(int $userId): ?array
    {
        $cacheKey = $this->getRedisKey("user:{$userId}");
        $cacheData = $this->redis->get($cacheKey);
        $user = $cacheData ? $this->parseUserCacheData($cacheData, $userId) : null;
        if ($this->isUserUsable($user)) {
            return $user;
        }
        $fresh = $this->getUserInfo($userId);
        return $fresh ?: $user;
    }
    //循环错误
    private function waitUntilAssignedUserReady(int $taskId, int $index, string $pauseFlagKey): ?array
    {
        $interval = $this->config['status_check_interval'];
        $maxAttempts = 10;  // 最多尝试次数
        $attempt = 0;
        while ($attempt < $maxAttempts) {
            $attempt++;
            if ($this->redis->get($pauseFlagKey)) {
                return null;
            }
            // 检查任务状态是否一致
            $status = $this->checkTaskStatusConsistency($taskId);
            if (!in_array($status, [self::status_running, self::status_paused])) {
                return null;
            }
            //获取当前消息配置
            $msg = $this->getLatestMessageByIndex($taskId, $index);
            if (!$msg || empty($msg['sendUser'])) {
                $this->writeTaskLog($taskId, "{$index} 发送人 信息不存在");
                sleep($interval);
                continue;
            }
            $userId = (int)$msg['sendUser'];

            // 首次循环记录原始账户ID
            if ($attempt === 1) {
                $originalUserId = $userId;            
            }
             // 获取用户信息（强制刷新）
            $user = $this->getUserFresh($userId);
            if ($this->isUserUsable($user)) {
                if ($userId !== $originalUserId) {
                    $this->writeTaskLog($taskId, "已从账户 {$originalUserId} 切换到账户 {$userId}");
                }
                return ['id' => $userId, 'user' => $user];
            }
            // 账户不可用，记录原因
            $errorReason = $this->getUserUnavailableReason($user);
            $this->writeTaskLog($taskId, "账户 {$userId} 不可用: {$errorReason}");

            // 如果是第1次尝试失败，且是原始指定账户，尝试寻找备用账户
            if ($attempt === 1 && $userId === $originalUserId) {
                Log::info("任务 {$taskId} 第 {$index} 条消息的指定账户 {$userId} 不可用，开始寻找备用账户");
                
                // 尝试寻找其他可用账户
                $alternativeUser = $this->findAlternativeUser($taskId,  $originalUserId);
                if ($alternativeUser) {
                    // 批量替换任务中所有使用该异常账户的消息
                    $success = $this->replaceAllMessagesWithNewUser($taskId, $originalUserId, $alternativeUser['id']);
                    if ($success) {
                        $userId = $alternativeUser['id'];
                        $user = $alternativeUser;
                        // 清除任务配置缓存，确保下次获取最新配置
                        $taskConfigCacheKey = $this->getRedisKey("config:{$taskId}");
                        $this->redis->del($taskConfigCacheKey);
                        continue; // 继续循环检查新账户
                    }
                }
            }
            
            // 如果已经尝试过其他账户但仍然失败，等待一段时间后重试
            $this->writeTaskLog($taskId, "等待 {$interval} 秒后重新检查账户状态（尝试 {$attempt}/{$maxAttempts}）");
            sleep($interval);
        }
        // 所有尝试都失败
        $this->writeTaskLog($taskId, "经过 {$maxAttempts} 次尝试，未能找到可用账户");
        return null;
    }

    /**
     * 寻找备用可用账户（一个备用账户对应一个不正常账户）
     */
    private function findAlternativeUser(int $taskId, int $excludeUserId): ?array
    {
        try {
            // 1. 获取任务的分类信息
            $task = MttaskModel::where('id', $taskId)->find();
            if (!$task) {
                Log::error("任务 {$taskId} 不存在");
                return null;
            }
            
            $accountGroup = $task->account_group ?? null;
            if (empty($accountGroup)) {
                Log::warning("任务 {$taskId} 未设置账户分类，从所有账户中查找");
            }
            
            // 2. 获取任务中已经使用的所有账户ID
            $usedUserIds = $this->getAllUsedUserIdsInTask($task);
        
            // 3. 获取已建立的备用账户映射
            $backupMapping = $this->getBackupAccountMapping($taskId);
            
            // 4. 检查是否已经为该异常账户分配了备用账户
            if (isset($backupMapping[$excludeUserId])) {
                $backupUserId = $backupMapping[$excludeUserId];
                $backupUser = $this->getUserInfo($backupUserId);
                if ($this->isUserUsable($backupUser)) {
                    Log::info("使用已分配的备用账户 {$backupUserId} 替换异常账户 {$excludeUserId}");
                    return $backupUser;
                } else {
                    Log::warning("已分配的备用账户 {$backupUserId} 不可用，重新分配新备用账户");
                    // 移除无效的映射
                    unset($backupMapping[$excludeUserId]);
                    $this->saveBackupAccountMapping($taskId, $backupMapping);
                }
            }
            
            // 5. 查找可用的备用账户（排除已使用的和已分配的）
            $excludeUserIds = array_merge([$excludeUserId], $usedUserIds, array_values($backupMapping));
            $excludeUserIds = array_unique($excludeUserIds);
            
            Log::info("任务 {$taskId} 属于分类: {$accountGroup}，开始查找该分类下的正常账户");
            
            // 6. 从该分类中查找可用账户
            $users = Db::name('mtuser')
                ->whereNotIn('id', $excludeUserIds)
                ->where('cateid', $accountGroup) // 匹配任务分类
                ->where('account_status', '正常')
                ->where('archive', 1) // 假设1表示未归档
                ->whereNotNull('session_path')
                ->where('session_path', '<>', '')
                ->order('id asc')
                ->field('id, account, cateid, tdata_path, session_path, proxyip, archive, account_status, account_status_desc, last_api_address')
                ->select();
            
            if ($users->count() > 0) {
                // 选择第一个可用的备用账户
                $user = $users[0];
                
                // 建立映射关系：一个备用账户对应一个异常账户
                $backupMapping[$excludeUserId] = $user['id'];
                $this->saveBackupAccountMapping($taskId, $backupMapping);
                
                Log::info("从分类 {$accountGroup} 找到可用备用账户: {$user['id']} - {$user['account']}，已分配给异常账户 {$excludeUserId}");
                return $user;
            }
            
            // 7. 备用账户不足时的异常处理
            $this->handleInsufficientBackupAccounts($taskId, $excludeUserId, $accountGroup, $excludeUserIds);
            
            return null;
            
        } catch (\Exception $e) {
            Log::error("从任务分类查找账户时出错: task_id={$taskId}, error={$e->getMessage()}");
            return null;
        }
    }
    /**
     * 获取任务中已经使用的所有账户ID
     */
    private function getAllUsedUserIdsInTask($task): array
    {
        $usedUserIds = [];
        
        try {
            // 从任务消息中提取所有发送人ID
            $messages = json_decode($task->messages, true);
            
            if (is_array($messages)) {
                foreach ($messages as $message) {
                    if (isset($message['sendUser']) && is_numeric($message['sendUser'])) {
                        $userId = (int)$message['sendUser'];
                        if ($userId > 0 && !in_array($userId, $usedUserIds)) {
                            $usedUserIds[] = $userId;
                        }
                    }
                }
            }
            
            Log::info("任务 {$task->id} 中已使用的账户ID: " . implode(',', $usedUserIds));
            
        } catch (\Exception $e) {
            Log::error("获取任务已用账户ID失败: task_id={$task->id}, error={$e->getMessage()}");
        }
        
        return $usedUserIds;
    }
    /**
     * 批量替换任务中所有使用指定异常账户的消息
     */
    private function replaceAllMessagesWithNewUser(int $taskId, int $oldUserId, int $newUserId): bool
    {
        try {
            // 开启数据库事务
            return Db::transaction(function () use ($taskId, $oldUserId, $newUserId) {
                // 使用悲观锁获取任务
                $task = MttaskModel::where('id', $taskId)->lock(true)->find();
                if (!$task) {
                    throw new \Exception("任务不存在");
                }
                
                // 检查新用户是否存在
                $newUser = Db::name('mtuser')->where('id', $newUserId)->find();
                if (!$newUser) {
                    throw new \Exception("新用户不存在");
                }
                
                // 解析消息配置
                $messages = json_decode($task->messages, true);
                if (!is_array($messages)) {
                    throw new \Exception("消息配置无效");
                }
                
                // 统计替换数量
                $replaceCount = 0;
                $affectedIndexes = [];
                
                // 查找所有使用旧账户的消息并替换
                foreach ($messages as $index => &$message) {
                    if (isset($message['sendUser']) && (int)$message['sendUser'] === $oldUserId) {
                        $message['sendUser'] = $newUserId;
                        $replaceCount++;
                        $affectedIndexes[] = $index;
                        
                        // 记录替换日志
                        $this->writeTaskLog($taskId, "消息 {$index} 的发送账户从 {$oldUserId} 替换为 {$newUserId}");
                    }
                }
                
                if ($replaceCount === 0) {
                    Log::info("任务 {$taskId} 中未找到使用账户 {$oldUserId} 的消息，无需替换");
                    return true;
                }
                
                // 更新任务消息配置
                $task->messages = json_encode($messages, JSON_UNESCAPED_UNICODE);
                $task->update_time = time();
                
                $success = $task->save();
                
                if ($success) {
                    Log::info("任务 {$taskId} 批量替换账户成功：将 {$oldUserId} 替换为 {$newUserId}，共替换 {$replaceCount} 条消息");
                    $this->writeTaskLog($taskId, "批量替换账户：将账户 {$oldUserId} 替换为 {$newUserId}，共影响 {$replaceCount} 条消息");
                    
                    // 更新相关缓存
                    $this->updateCacheAfterUserReplacement($taskId, $oldUserId, $newUserId, $affectedIndexes);
                }
                
                return $success;
            });
            
        } catch (\Exception $e) {
            Log::error("批量替换账户失败: task_id={$taskId}, old_user={$oldUserId}, new_user={$newUserId}, error={$e->getMessage()}");
            return false;
        }
    }

    /**
     * 获取备份账户映射关系
     */
    private function getBackupAccountMapping(int $taskId): array
    {
        $mappingKey = $this->getRedisKey("backup_mapping:{$taskId}");
        $mappingData = $this->redis->get($mappingKey);
        
        if ($mappingData === false || $mappingData === null) {
            return [];
        }
        
        $mapping = json_decode($mappingData, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($mapping)) {
            Log::warning("任务 {$taskId} 备份账户映射数据解析失败，重置为空映射");
            return [];
        }
        
        return $mapping;
    }
    
    /**
     * 保存备份账户映射关系
     */
    private function saveBackupAccountMapping(int $taskId, array $mapping): bool
    {
        try {
            $mappingKey = $this->getRedisKey("backup_mapping:{$taskId}");
            $mappingData = json_encode($mapping, JSON_UNESCAPED_UNICODE);
            
            // 设置较长的过期时间，确保任务执行期间映射关系有效
            $result = $this->redis->set($mappingKey, $mappingData, $this->config['task_cache_expire']);
            
            if ($result) {
                Log::info("任务 {$taskId} 备份账户映射关系保存成功：" . json_encode($mapping));
            }
            
            return $result;
        } catch (\Exception $e) {
            Log::error("保存备份账户映射失败: task_id={$taskId}, error={$e->getMessage()}");
            return false;
        }
    }
    
    /**
     * 处理备用账户不足的情况
     */
    private function handleInsufficientBackupAccounts(int $taskId, int $excludeUserId, ?string $accountGroup, array $excludeUserIds): void
    {
        $availableCount = $this->countAvailableBackupAccounts($accountGroup, $excludeUserIds);
        
        if ($availableCount === 0) {
            $this->writeTaskLog($taskId, "⚠️ 严重：分类 {$accountGroup} 下没有可用的备用账户，任务将异常终止");
            $this->markTaskAsFailed($taskId, "备用账户不足，无法继续执行任务");
            throw new \Exception("备用账户不足，任务异常终止");
        } else {
            $this->writeTaskLog($taskId, "⚠️ 警告：分类 {$accountGroup} 下备用账户不足（可用：{$availableCount}），但仍有账户可用，继续尝试");
        }
    }
    
    /**
     * 统计可用的备用账户数量
     */
    private function countAvailableBackupAccounts(?string $accountGroup, array $excludeUserIds): int
    {
        try {
            $query = Db::name('mtuser')
                ->whereNotIn('id', $excludeUserIds)
                ->where('account_status', '正常')
                ->where('archive', 1)
                ->whereNotNull('session_path')
                ->where('session_path', '<>', '');
                
            if (!empty($accountGroup)) {
                $query->where('cateid', $accountGroup);
            }
            
            return $query->count();
        } catch (\Exception $e) {
            Log::error("统计备用账户数量失败: error={$e->getMessage()}");
            return 0;
        }
    }

    /**
     * 替换账户后更新相关缓存
     */
    private function updateCacheAfterUserReplacement(int $taskId, int $oldUserId, int $newUserId, array $affectedIndexes): void
    {
        try {
            // 1. 清除任务配置缓存（因为消息配置已改变）
            $taskConfigCacheKey = $this->getRedisKey("config:{$taskId}");
            $this->redis->del($taskConfigCacheKey);
            
            // 2. 清除用户映射缓存
            $oldUserCacheKey = $this->getRedisKey("user:{$oldUserId}");
            $this->redis->del($oldUserCacheKey);
            
            // 3. 预热新用户缓存（可选）
            $this->getUserInfo($newUserId);
            
            // 4. 如果有进度缓存，可能需要更新相关索引的状态
            // 获取群组列表
            $task = MttaskModel::where('id', $taskId)->find();
            if ($task && !empty($task->group_list)) {
                $groupList = preg_split('/\s*[,，;；]\s*/', $task->group_list);
                
                foreach ($groupList as $groupId) {
                    $cacheKey = $this->getGroupMessageCacheKey($taskId, $groupId);
                    
                    // 对于每个受影响的消息索引，可能需要重置发送状态（可选）
                    // 因为换了新账户，之前的发送状态可能无效
                    foreach ($affectedIndexes as $msgIndex) {
                        // 如果消息还没发送过，不需要处理
                        // 如果消息已经发送过，根据业务决定是否重置状态
                        $status = $this->redis->hGet($cacheKey, $msgIndex);
                        if ($status === '0') { // 未发送
                            // 保持未发送状态
                            continue;
                        } elseif ($status === '1') { // 已发送
                            // 可以根据业务需求决定：
                            // 1. 保持已发送状态（假设原账户已发送成功）
                            // 2. 重置为未发送（重新发送）
                            // 这里选择保持已发送状态，避免重复发送
                            Log::info("任务 {$taskId} 群组 {$groupId} 消息 {$msgIndex} 已由原账户发送，保持发送状态");
                        }
                    }
                }
            }
            
            Log::info("任务 {$taskId} 账户替换后的缓存更新完成");
            
        } catch (\Exception $e) {
            Log::error("更新替换账户缓存时出错: task_id={$taskId}, error={$e->getMessage()}");
        }
    }




    /**
     * 获取账户不可用的原因
     */
    private function getUserUnavailableReason(?array $user): string
    {
        if (!$user) {
            return "账户不存在";
        }
        
        if (($user['archive'] ?? 0) == 0) {
            return "账户已被归档";
        }
        
        if (empty($user['session_path'])) {
            return "session路径为空";
        }
        
        if (empty($user['last_api_address'])) {
            return "API地址为空";
        }
        
        if (($user['account_status'] ?? '') !== '正常') {
            return "状态异常: {$user['account_status']} - {$user['account_status_desc']}";
        }
        
        return "未知原因";
    }    
    /**
     * 获取并缓存缺失的用户数据
     */
    private function fetchAndCacheMissingUsers(array $missedIds, array $userMap, array $archivedUserIds): array
    {
        $freshUsers = Db::name('mtuser')
            ->whereIn('id', $missedIds)
            ->field('id, tdata_path, account, session_path, proxyip, archive, account_status, account_status_desc,last_api_address')
            ->select()
            ->toArray();
        
        // 计算已删除的用户ID
        $existingUserIds = array_column($freshUsers, 'id');
        $deletedUserIds = array_diff($missedIds, $existingUserIds);
        
        // 处理存在的用户
        foreach ($freshUsers as $user) {
            $userMap[$user['id']] = $user;
            $this->redis->set(
                $this->getRedisKey("user:{$user['id']}"),
                json_encode($user),
                $this->config['user_cache_expire']
            );
            
            if ($user['archive'] == 0) {
                $archivedUserIds[] = $user['id'];
                Log::info("用户 {$user['id']} 已归档，加入过滤列表");
            }
        }
        
        // 处理已删除的用户（缓存空值标记）
        foreach ($deletedUserIds as $userId) {
            $archivedUserIds[] = $userId;
            $this->redis->set(
                $this->getRedisKey("user:{$userId}"),
                json_encode(null),
                600 // 10分钟过期
            );
            Log::info("用户 {$userId} 已删除，加入过滤列表并标记缓存");
        }
        
        return [$userMap, $archivedUserIds];
    }
    
    /**
     * 过滤已归档用户的消息
     */
    private function filterArchivedUserMessages(array $messages, array $archivedUserIds, $task, int $taskId): array
    {
        if (empty($archivedUserIds)) {
            return $messages;
        }
        
        $archivedUserIds = array_values(array_unique(array_map('intval', $archivedUserIds)));
        Log::info("检测到已归档/删除用户ID: " . implode(',', $archivedUserIds) . "，将尝试自动替换发送账号");
        
        $needReplaceUserIds = [];
        foreach ($messages as $message) {
            $uid = (int)($message['sendUser'] ?? 0);
            if ($uid > 0 && in_array($uid, $archivedUserIds, true)) {
                $needReplaceUserIds[$uid] = true;
            }
        }
        $needReplaceUserIds = array_keys($needReplaceUserIds);
        
        foreach ($needReplaceUserIds as $oldUserId) {
            $alternativeUser = $this->findAlternativeUser($taskId, $oldUserId);
            if (!$alternativeUser) {
                $this->writeTaskLog($taskId, "发送账号 {$oldUserId} 已归档/删除，但未找到可替换账号，后续将继续尝试自动换号");
                continue;
            }
            
            $newUserId = (int)$alternativeUser['id'];
            $success = $this->replaceAllMessagesWithNewUser($taskId, $oldUserId, $newUserId);
            if (!$success) {
                $this->writeTaskLog($taskId, "发送账号 {$oldUserId} 自动替换为 {$newUserId} 失败，后续将继续尝试自动换号");
                continue;
            }
            
            foreach ($messages as &$message) {
                if ((int)($message['sendUser'] ?? 0) === $oldUserId) {
                    $message['sendUser'] = $newUserId;
                }
            }
            unset($message);
            $this->writeTaskLog($taskId, "发送账号 {$oldUserId} 已归档/删除，已自动替换为 {$newUserId}");
            // 清除任务配置缓存，确保下次获取最新配置
            $taskConfigCacheKey = $this->getRedisKey("config:{$taskId}");
            $this->redis->del($taskConfigCacheKey);
        }
        
        return array_values($messages);
    }
    
  
    
    /**
     * 处理消息列表
     */
    private function processMessages($task, int $taskId, array $messages, array $groupList, array $userMap, int $currentIndex, int $xhnum, int $currentCycle,string $taskConfigCacheKey, string $lastProcessedKey, string $statusKey): array
    {
        // 如果还有消息要处理
        if ($currentIndex < count($messages)) {
            //处理单条消息发送
            $msgResult = $this->handleSingleMessage($taskId,$messages,$groupList,$userMap,$currentIndex);
            
            // 更新任务状态
            $this->updateTaskCounters($task, $msgResult['successCount'], $msgResult['failCount'], $msgResult['failDetails']);
            
            // 保存最后处理的消息索引
            $this->redis->set($lastProcessedKey, $currentIndex, $this->config['cache_expire']);
            
            // 检查是否有暂停请求
            if ($this->redis->get($this->getRedisKey("pause_flag:{$taskId}"))) {
                $this->handlePauseRequest($task, $currentIndex);
                 
                return ['action' => 'pause'];
            }
            if (isset($msgResult['stoperror']) && $msgResult['stoperror']) {
                return ['action' => 'taskerror'];
            }

            if ($msgResult['stopFlag']) {
                return ['action' => 'complete'];
            }
            
            // 准备处理下一条消息
            $nextIndex = $currentIndex + 1;
            $delay = isset($messages[$currentIndex]['delay']) ? max(1, (int)$messages[$currentIndex]['delay']) : 1;
            
            return [
                'action' => 'continue',
                'nextIndex' => $nextIndex,
                'delay' => $delay
            ];
        }
        
        // 所有消息处理完毕，处理循环任务
        return $this->handleTaskCompletion($task, $taskId, $xhnum, $currentCycle, $taskConfigCacheKey, $statusKey, $lastProcessedKey, $groupList);
    }
    
    /**
     * 处理任务完成逻辑
     */
    private function handleTaskCompletion($task, int $taskId, int $xhnum, int $currentCycle,string $taskConfigCacheKey, string $statusKey,string $lastProcessedKey, array $groupList): array
    {
        // 处理循环任务
        if ($xhnum > 1 && $currentCycle < $xhnum) {
            $nextCycle = $currentCycle + 1;
            $this->redis->del($this->getRedisKey("cycle:{$taskId}"));//删除当前轮次缓存
            $this->redis->set($this->getRedisKey("cycle:{$taskId}"), $nextCycle, $this->config['cycle_cache_expire']);
    
            Log::info("任务 {$taskId} 第 {$currentCycle} 轮完成，准备进行第 {$nextCycle} 轮");
            $this->cleanTaskCache($taskId, $statusKey, $taskConfigCacheKey, $lastProcessedKey, $groupList);
            //更新新的任务状态
            //$this->updateTaskCounters($task, 0, 0, []);
            
            $task->success_count =0;
            $task->fail_count = 0;
            $task->fail_details = [];
            $task->update_time = time();
            $task->save();
            
            
            return [
                'action'    => 'requeue',
                'nextIndex' => 0,
                'nextCycle' => $nextCycle,
                'delay'     => 5
            ];
        }
        
        // 任务完成
        $finalStatus = self::status_completed;
        $task->save([
            'status' => $finalStatus,
            'update_time' => time()
        ]);
        
        $this->cleanTaskCache($taskId, $statusKey, $taskConfigCacheKey, $lastProcessedKey, $groupList);
        Log::info("任务 {$taskId} 处理完成，成功: {$task->success_count}, 失败: {$task->fail_count}");
        $this->writeTaskLog($taskId, "任务 {$taskId} 处理完成，成功: {$task->success_count}, 失败: {$task->fail_count}");
        
        return ['action' => 'complete'];
    }
    
    /**
     * 清理任务缓存
     */
    private function cleanTaskCache(int $taskId, string $statusKey, string $taskConfigCacheKey,string $lastProcessedKey, array $groupList): void
    {
        $this->redis->del($statusKey);
        $this->redis->del($taskConfigCacheKey);
        $this->redis->del($lastProcessedKey);
        //$this->redis->del($this->getRedisKey("cycle:{$taskId}"));
        
        foreach ($groupList as $groupId) {
            // 清理群组消息缓存
            $cacheKey = $this->getGroupMessageCacheKey($taskId, $groupId);
            $this->redis->del($cacheKey);

            // 清理消息ID缓存
            $messageIdsKey = $this->getMessageIdsCacheKey($taskId, $groupId);
            $this->redis->del($messageIdsKey);            
           
        }
    }
    
    /**
     * 更新任务计数
     */
    private function updateTaskCounters($task, int $success, int $fail, array $failDetails): void
    {
        $task->success_count = ($task->success_count ?? 0) + $success;
        $task->fail_count = ($task->fail_count ?? 0) + $fail;
        
        $existingDetails = $task->fail_details ? explode('; ', $task->fail_details) : [];
        $task->fail_details = implode('; ', array_merge($existingDetails, $failDetails));
        $task->update_time = time();
        
        $task->save();
    }
    
    /**
     * 处理暂停请求
     */
    private function handlePauseRequest($task, int $currentIndex): void
    {
        $taskId = $task->id;
        $pauseFlagKey = $this->getRedisKey("pause_flag:{$taskId}");
        $lastProcessedKey = $this->getRedisKey("last_processed:{$taskId}");
        
        // 更新任务状态为暂停
        $task->save([
            'status' => self::status_paused,
            'update_time' => time()
        ]);
        
        // 保存当前处理进度
        $this->redis->set($lastProcessedKey, $currentIndex, $this->config['cache_expire']);
        
        // 清除暂停标志
        $this->redis->del($pauseFlagKey);
        
        $this->writeTaskLog($taskId, "任务已暂停，当前处理到消息索引: {$currentIndex}");
        Log::info("任务 {$taskId} 已暂停，当前处理到消息索引: {$currentIndex}");
    }
    
    /**
     * 处理单条消息发送
     */
    private function handleSingleMessage(int $taskId, array $messages, array $groupList, array $userMap, int $index): array
    {
        $successCount = 0;
        $failCount = 0;
        $failDetails = [];
        $stopFlag = false;
        $stoperror=false;
        $pauseFlagKey = $this->getRedisKey("pause_flag:{$taskId}");
        $checkInterval = $this->config['status_check_interval'];
        $startTime = time();
        $currentCycle = (int)($this->redis->get($this->getRedisKey("cycle:{$taskId}")) ?: 1);
        $sendLockTtl = (int)($this->config['send_lock_ttl'] ?? 180);
        
        $msgConfig = $messages[$index];
        
        // 检查任务状态
        $currentStatus = $this->checkTaskStatusConsistency($taskId);
        if (!in_array($currentStatus, [self::status_running, self::status_paused])) {
            Log::info("检测到任务状态已变更，准备退出... task_id={$taskId}, 当前状态={$currentStatus}");
            $this->writeTaskLog($taskId, "检测到任务状态已变更，准备退出... task_id={$taskId}, 当前状态={$currentStatus}");
            return ['successCount' => $successCount, 'failCount' => $failCount, 'failDetails' => $failDetails, 'stopFlag' => true, 'stoperror'=>true];
        }
        
        // 检查是否有暂停请求
        if ($this->redis->get($pauseFlagKey)) {
            Log::info("检测到暂停请求，准备暂停任务... task_id={$taskId}");
            $this->writeTaskLog($taskId, "检测到暂停请求，准备暂停任务...");
            return ['successCount' => $successCount, 'failCount' => $failCount, 'failDetails' => $failDetails, 'stopFlag' => true, 'stoperror'=>true];
        }
        
        // 验证消息配置
        if (empty($msgConfig['sendType'])) {
            $failCount++;
            $failDetails[] = "消息类型不能为空";
            $this->writeTaskLog($taskId, "{$index} 3 消息类型不能为空");
            return ['successCount' => $successCount, 'failCount' => $failCount, 'failDetails' => $failDetails, 'stopFlag' => true, 'stoperror'=>true];
        }
        
        $sendUserId = $msgConfig['sendUser'] ?? 0;
        if (empty($sendUserId)) {
            $failCount++;
            $failDetails[] = "发送人ID无效: {$sendUserId}";
            $this->writeTaskLog($taskId, "{$index} 3 发送人ID无效: {$sendUserId}");
            return ['successCount' => $successCount, 'failCount' => $failCount, 'failDetails' => $failDetails, 'stopFlag' => true, 'stoperror'=>true];
        }
        
        $sendUser = $userMap[$sendUserId] ?? null;
        if (!$this->isUserUsable($sendUser)) {
            $account = $sendUser['account'] ?? (string)$sendUserId;
            $status = $sendUser['account_status'] ?? '';
            $desc = $sendUser['account_status_desc'] ?? '';
            $this->writeTaskLog($taskId, "{$index} 发送人 {$account} 不可用: {$status} {$desc}");
            $assigned = $this->waitUntilAssignedUserReady($taskId, $index, $pauseFlagKey);
            if (!$assigned) {
                return ['successCount' => $successCount, 'failCount' => $failCount, 'failDetails' => $failDetails, 'stopFlag' => true, 'stoperror'=>true];
            }
            $sendUserId = $assigned['id'];
            $sendUser = $assigned['user'];
            // 更新 userMap 中的对应条目，确保后续使用新账户
            $userMap[$sendUserId] = $sendUser;
            // 同时更新消息配置中的发送人ID，确保后续群组使用正确的账户
            $msgConfig['sendUser'] = $sendUserId;
        }
        
        $tdataPath = $sendUser['session_path'];
        if (empty($tdataPath)) {
            $failCount++;
            $failDetails[] = "发送人 {$sendUserId} 的session路径无效";
            $this->writeTaskLog($taskId, "发送人 {$sendUserId} 的session路径无效");
            return ['successCount' => $successCount, 'failCount' => $failCount, 'failDetails' => $failDetails, 'stopFlag' => true, 'stoperror'=>true];
        }
        
        // 检查是否所有群组的该消息都已发送
        if ($this->checkAllGroupsSent($taskId, $groupList, $index, $startTime, $checkInterval, $pauseFlagKey)) {
            $successCount += count($groupList);
            return ['successCount' => $successCount, 'failCount' => $failCount, 'failDetails' => $failDetails, 'stopFlag' => $stopFlag];
        }
        
        // 处理每个群组的消息发送
        foreach ($groupList as $groupId) {
            
            
            // 定期检查暂停请求
            if (time() - $startTime >= $checkInterval) {
                if ($this->redis->get($pauseFlagKey)) {
                    Log::info("处理消息过程中检测到暂停请求... task_id={$taskId}");
                    $this->writeTaskLog($taskId, "处理消息过程中检测到暂停请求...");
                    return ['successCount' => $successCount, 'failCount' => $failCount, 'failDetails' => $failDetails, 'stopFlag' => true, 'stoperror'=>true];
                }
                $startTime = time();
            }
            
            // 增强消息发送状态检查
            if ($this->isMessageSent($taskId, $groupId, $index)) {
                $successCount++;
                continue;
            }
            // 额外检查：如果消息已经有message_id，说明已发送
            $messageId = $this->getMessageIdFromCache($taskId, $groupId, $index, $sendUserId);
            if ($messageId) {
                // 标记为已发送
                $this->markMessageAsSent($taskId, $groupId, $index);
                $successCount++;
                continue;
            }
            
            // 发送锁键不包含sendUserId，确保同一消息索引在同一时间只能被一个进程处理
            $sendLockKey = $this->getRedisKey("send_lock:{$taskId}:{$currentCycle}:" . md5($groupId) . ":{$index}");
            $sendLockToken = uniqid('send_', true);
            if (!$this->acquireLock($sendLockKey, $sendLockToken, $sendLockTtl)) {
                if ($this->isMessageSent($taskId, $groupId, $index) || $this->getMessageIdFromCache($taskId, $groupId, $index, $sendUserId)) {
                    $this->markMessageAsSent($taskId, $groupId, $index);
                    $successCount++;
                }
                continue;
            }
            
            // 发送消息
            $sendSucceeded = false;
            $sendRetryCount = 0;
        
            
            
            try {
                while (!$sendSucceeded && $sendRetryCount < $this->config['max_send_retry']) {
                    $sendRetryCount++;
                    // 检查暂停请求
                    if ($this->redis->get($pauseFlagKey)) {
                        Log::info("重试过程中检测到暂停请求，中止重试... task_id={$taskId}");
                        $this->writeTaskLog($taskId, "重试过程中检测到暂停请求，中止重试...");
                        return ['successCount' => $successCount, 'failCount' => $failCount, 'failDetails' => $failDetails, 'stopFlag' => true, 'stoperror'=>true];
                    }
                    
                    // 检查任务状态
                    $currentStatus = $this->checkTaskStatusConsistency($taskId);
                    if (!in_array($currentStatus, [self::status_running, self::status_paused])) {
                        Log::info("重试过程中检测到任务状态变更，中止重试... task_id={$taskId}, 状态={$currentStatus}");
                        $this->writeTaskLog($taskId, "重试过程中检测到任务状态变更，中止重试...");
                        return ['successCount' => $successCount, 'failCount' => $failCount, 'failDetails' => $failDetails, 'stopFlag' => true, 'stoperror'=>true];
                    }
                    //发送消息处理返回值
                    list($success, $error) = $this->sendMessageToGroup($taskId, $msgConfig, $sendUser, $groupId, $index, $currentCycle);
                    if ($success) {
                        $this->markMessageAsSent($taskId, $groupId, $index);
                        $successCount++;
                        $sendSucceeded = true;
                        break;
                    }
                    $failCount++;
                    $failDetails[] = "第{$sendRetryCount}次尝试失败: " . $error;
                    $this->writeTaskLog($taskId, "{$index} {$sendRetryCount} 群组 {$groupId} 第{$sendRetryCount}次尝试失败: {$error}");
                    if ($error == '无权限') {
                        $stoperror = true;
                        return ['successCount' => $successCount, 'failCount' => $failCount, 'failDetails' => $failDetails, 'stopFlag' => true, 'stoperror' => $stoperror];
                    }
                    // 如果不是最后一次重试，进行重试准备
                    if ($sendRetryCount < $this->config['max_send_retry']) {
                        // 检查是否需要更换发送账号
                        $assigned = $this->waitUntilAssignedUserReady($taskId, $index, $pauseFlagKey);
                        if (!$assigned) {
                            return ['successCount' => $successCount, 'failCount' => $failCount, 'failDetails' => $failDetails, 'stopFlag' => true, 'stoperror'=>true];
                        }
                        $sendUserId = $assigned['id'];
                        $sendUser = $assigned['user'];
                        
                        // 计算重试延迟（指数退避）
                        $retryDelay = $this->calculateRetryDelay($sendRetryCount);
                        $this->writeTaskLog($taskId, "等待 {$retryDelay} 秒后进行第 " . ($sendRetryCount + 1) . " 次重试...");
                        sleep($retryDelay);
                    }            
                }
            } finally {
                $this->releaseLock($sendLockKey, $sendLockToken);
               
            }
            
            // 检查是否达到最大重试次数仍然失败
            if (!$sendSucceeded) {
                $failCount++;
                $this->writeTaskLog($taskId, "群组 {$groupId} 消息发送失败，已达到最大重试次数({$this->config['max_send_retry']}次)");
                
                // 如果是关键错误，停止整个任务                
                $stoperror = true;
                return ['successCount' => $successCount, 'failCount' => $failCount, 'failDetails' => $failDetails, 'stopFlag' => true, 'stoperror' => $stoperror];
            }
        }
        
        return ['successCount' => $successCount, 'failCount' => $failCount, 'failDetails' => $failDetails, 'stopFlag' => $stopFlag, 'stoperror'=>false];
    }
    // 添加 getMessageIdFromCache 方法
    private function getMessageIdFromCache(int $taskId, string $groupId, int $index, int $sendUserId): ?string
    {
        $messageIdsKey = $this->getMessageIdsCacheKey($taskId, $groupId);
        return $this->redis->hGet($messageIdsKey, $index);
    }
    /**
     * 计算重试延迟（指数退避算法）
     */
    private function calculateRetryDelay(int $retryCount): int
    {
        $baseDelay = 2;   // 基础延迟（秒）
        $maxDelay  = 50;  // 最大延迟（秒）
    
        // 指数退避（全程 int）
        $delay = $baseDelay * (1 << max(0, $retryCount - 1));
    
        // 抖动（整数秒，避免 float）
        $jitter = random_int(0, 2); // 0~2 秒
    
        return min($delay + $jitter, $maxDelay);
    }
    /**
     * 检查是否所有群组的消息都已发送
     */
    private function checkAllGroupsSent(int $taskId, array $groupList, int $index, int &$startTime,int $checkInterval, string $pauseFlagKey): bool
    {
        foreach ($groupList as $groupId) {
            if (!$this->isMessageSent($taskId, $groupId, $index)) {
                return false;
            }
            
            // 处理过程中检查暂停请求
            if (time() - $startTime >= $checkInterval) {
                if ($this->redis->get($pauseFlagKey)) {
                    return true;
                }
                $startTime = time();
            }
        }
        return true;
    }
    
    /**
     * 发送消息到群组
     * {"status":true,"message":"发送完成，成功0个，失败1个[{'group_id': None, 'recognized_as_link': True, 'group_identifier': 'https:\/\/t.me\/+BFJUm847mas4Yzg5', 'type': 'unknown', 'message': '处理链接 https:\/\/t.me\/+BFJUm847mas4Yzg5 时出错: The chat the user tried to join has expired and is not valid anymore (caused by CheckChatInviteRequest)'}]","data":{"success":[],"failed":[{"group_id":null,"recognized_as_link":true,"group_identifier":"https:\/\/t.me\/+BFJUm847mas4Yzg5","type":"unknown","message":"处理链接 https:\/\/t.me\/+BFJUm847mas4Yzg5 时出错: The chat the user tried to join has expired and is not valid anymore (caused by CheckChatInviteRequest)"}],"debug":["处理标识符: https:\/\/t.me\/+BFJUm847mas4Yzg5, 清理后: https:\/\/t.me\/+BFJUm847mas4Yzg5, 识别为链接: True, 识别为数字ID: False","处理链接 https:\/\/t.me\/+BFJUm847mas4Yzg5 时出错: The chat the user tried to join has expired and is not valid anymore (caused by CheckChatInviteRequest)"],"warning":""}}
     */
    private function sendMessageToGroup(int $taskId, array $msgConfig, array $sendUser, string $groupId, int $index, int $currentCycle = 1): array
    {
           
           
            try {
                
                
                // 生成唯一消息ID，用于幂等性
               
                // 根据 feedbackType 获取正确的 
                $actualFirstMsgId = $this->getMsgIdByFeedbackType($taskId, $groupId, $msgConfig);
    
                $params = $this->buildHttpParams($msgConfig, $sendUser, $groupId, $actualFirstMsgId);
                $user_send=$params['user_send'];
                $messageUniqueId = md5("{$taskId}_{$groupId}_{$index}_{$currentCycle}_{$user_send['id']}");
                // 添加唯一标识符到参数
                $params['message_unique_id'] = $messageUniqueId; 
                // 账户级分布式锁，防止同一账户被多个进程并发使用
                $accountLockKey = $this->getRedisKey("account_lock:" . intval($user_send['id']));
                $accountLockToken = uniqid('acct_', true);
                if (!$this->acquireLock($accountLockKey, $accountLockToken, intval($this->config['account_lock_ttl'] ?? 600))) {
                    //$this->writeTaskLog($taskId, "{$sendUser['account']} [{$index}] 账户正在其他任务中使用");
                    return [false, '账户占用'];
                }
                try {
                    $result = $this->sendHttpRequest($params,$params['last_api_address']);
                } finally {
                    $this->releaseLock($accountLockKey, $accountLockToken);
                }
                // 增强错误检测：检查是否有链接过期错误
                $errorMessage = $result['message'] ?? '';
                $hasExpiredLinkError = strpos($errorMessage, 'CheckChatInviteRequest') !== false || 
                                       strpos($errorMessage, 'expired and is not valid') !== false ||
                                       strpos($errorMessage, '无权限') !== false;
                                       
                if ($result['status'] ?? false) {
                 
                    // 即使 status 为 true，也可能有失败情况
                    if (!empty($result['data']['failed'])) {
                        foreach ($result['data']['failed'] as $failedItem) {
                            if (strpos($failedItem['message'] ?? '', 'CheckChatInviteRequest') !== false) {
                                $hasExpiredLinkError = true;
                                break;
                            }
                        }
                    }
                    // 如果有链接过期错误，按失败处理
                    if ($hasExpiredLinkError) {
                        $this->writeTaskLog($taskId, "{$user_send['account']} [{$index}]{$msgConfig['text']} 1 {$groupId} 发送失败 邀请链接已过期,请检查账户是否在该群组");
                        return [false, '无权限'];
                    }
                    if (!empty($result['data']['success']) && isset($result['data']['success'][0]['message_id'])) {
                        $messageId = $result['data']['success'][0]['message_id'];
                     
                        $this->saveMessageIdToCache($taskId, $groupId, $index, $messageId, $user_send['id']);
                        $this->writeTaskLog($taskId, "{$user_send['account']} [{$index}]{$msgConfig['text']} 1 {$groupId}  发送成功  message_id:{$messageId}");
                        return [true, ''];
                    }
                    $error = "群组 {$groupId} 消息发送失败: " . ($result['message'] ?? '未知错误');
                    $this->writeTaskLog($taskId, "{$user_send['account']} [{$index}]{$msgConfig['text']} 1 {$groupId}  发送失败 ");
                    return [false, $error];
                        
                    
                } else {
                    $error = "群组 {$groupId} 消息发送失败: " . ($result['message'] ?? '未知错误');
                    $this->writeTaskLog($taskId, "{$user_send['account']}  [{$index}]   2  {$groupId}  ".($result['message'] ?? '未知错误'));
                    
                    // 更新用户状态
                    $this->updateUserStatus($user_send, $result);
                    return [false, $error];
                }
            } catch (\Exception $e) {
                $error = "群组 {$groupId} 请求异常: " . $e->getMessage();
                $this->writeTaskLog($taskId, "{$user_send['account']}  [{$index}]  3  {$groupId}  ".$e->getMessage());
                return [false, $error];
            }finally {
                // 释放锁
                
            }
          
         
    }
    /**
     * 根据feedbackType获取首条消息ID
     */
    private function getMsgIdByFeedbackType(int $taskId, string $groupId, array $msgConfig): int
    {
        $feedbackType = $msgConfig['feedbackType'];
        
        // 如果是0，表示回复上一条消息
        if ($feedbackType > 0&&$feedbackType!='none') {        
            // 如果不是0，表示回复特定反馈类型的消息
            // 从缓存中获取该feedbackType对应的消息ID
            $feedbackMsgId = $this->getFeedbackTypeMessageId($taskId, $groupId, $feedbackType);
            
            if ($feedbackMsgId > 0) {
                Log::info("使用feedbackType={$feedbackType}的消息ID: {$feedbackMsgId}");
                return $feedbackMsgId;
            }
           
        }
        return 0;
    }
    
    /**
     * 获取feedbackType对应的消息ID
     */
    private function getFeedbackTypeMessageId(int $taskId, string $groupId, int $feedbackType): int
    {
        try {
            $messageIdsKey = $this->getMessageIdsCacheKey($taskId, $groupId);// 消息ID缓存键
           // $messageId = $this->redis->hGet($cacheKey, $feedbackType);
            $messageId = $this->redis->hGet($messageIdsKey, $feedbackType-1);
            if ($messageId) {
                return (int)$messageId;
            }
            
            return 0;
            
        } catch (\Exception $e) {
            Log::error("获取feedbackType消息ID失败: error={$e->getMessage()}, task_id={$taskId}, group_id={$groupId}, feedback_type={$feedbackType}");
            return 0;
        }
    }
  
    /**
     * 更新用户状态
     */
    private function updateUserStatus(array $sendUser, array $result): void
    {
        $updateData = [
            'account_status' => $result['data']['account_status'] ?? '异常',
            'account_status_desc' => $result['data']['account_status_desc'] ?? '发送失败'
        ];
        
        // 更新数据库
        MtuserModel::where('id', $sendUser['id'])->update($updateData);
        
        // 更新Redis缓存
        $cacheKey = $this->getRedisKey("user:{$sendUser['id']}");
        $sendUser = array_merge($sendUser, $updateData);
        $this->redis->set($cacheKey, json_encode($sendUser), $this->config['cache_expire']);
    }
    
    /**
     * 初始化消息缓存
     */
    private function initMessageCache(int $taskId, array $messages, array $groupList): void
    {
        try {
            foreach ($groupList as $groupId) {
                $cacheKey = $this->getGroupMessageCacheKey($taskId, $groupId);
                $batches = array_chunk($messages, $this->config['redis_batch_size'], true);
    
                foreach ($batches as $batchIndex => $batch) {
                    $this->redisBatchSet($cacheKey, $batch, $taskId, $groupId, $batchIndex);
                }
    
                // 设置过期时间
                $this->redis->expire($cacheKey, $this->config['group_cache_expire']);
            }
    
            Log::info("消息缓存初始化完成: task_id={$taskId}, groups=" . implode(',', $groupList));
    
        } catch (\Exception $e) {
            Log::error("初始化消息缓存异常: task_id={$taskId}, error={$e->getMessage()}");
        }
    }
    
    /**
     * Redis批量设置
     */
    private function redisBatchSet(string $cacheKey, array $batch, int $taskId, string $groupId, int $batchIndex): void
    {
        $retry = 0;
        while ($retry < $this->config['max_retry_count']) {
            try {
                $this->redis->multi(1);
                foreach ($batch as $index => $msgConfig) {
                    $this->redis->hSet($cacheKey, $index, 0);
                }
                $this->redis->exec();
                break;
            } catch (\Exception $e) {
                $retry++;
                Log::warning("Redis批量写入失败，重试 {$retry}/{$this->config['max_retry_count']}: " . $e->getMessage(), [
                    'task_id' => $taskId,
                    'group_id' => $groupId,
                    'batch' => $batchIndex
                ]);
                usleep(500000);
            }
        }
    
        if ($retry === $this->config['max_retry_count']) {
            Log::error("Redis批量写入失败，任务缓存初始化失败", [
                'task_id' => $taskId,
                'group_id' => $groupId,
                'batch' => $batchIndex
            ]);
        }
    }

    /**
     * 获取群组消息缓存键
     */
    private function getGroupMessageCacheKey(int $taskId, string $groupId): string
    {
        $groupHash = $groupId;
        return $this->getRedisKey("{$taskId}:group:{$groupHash}:messages");
    }
    
    /**
     * 检查消息是否已发送
     */
    private function isMessageSent(int $taskId, string $groupId, int $msgIndex): bool
    {
        $cacheKey = $this->getGroupMessageCacheKey($taskId, $groupId);
        $status = $this->redis->hGet($cacheKey, $msgIndex);
        return $status === '1';
    }
    
    /**
     * 标记消息为已发送
     */
    private function markMessageAsSent(int $taskId, string $groupId, int $msgIndex): bool
    {
        $cacheKey = $this->getGroupMessageCacheKey($taskId, $groupId);
        try {
            $this->redis->hSet($cacheKey, $msgIndex, 1);
            $this->redis->expire($cacheKey, $this->config['message_cache_expire'] ?? 604800);
            Log::info("消息状态标记为已发送: task_id={$taskId}, group_id={$groupId}, msg_index={$msgIndex}");
            return true;
        } catch (\Exception $e) {
            Log::error("标记消息状态失败: error={$e->getMessage()}, task_id={$taskId}, group_id={$groupId}, msg_index={$msgIndex}");
            return false;
        }
    }
    
   
    
    /**
     * 检查并更新任务状态一致性
     */
    private function checkTaskStatusConsistency(int $taskId): ?int
    {
        $cacheKey = $this->getRedisKey("status:{$taskId}");
        
        try {
            for ($i = 0; $i < $this->config['max_retry_count']; $i++) {
                try {
                    // 检查连接状态
                    if (!$this->redis->ping()) {
                        $this->redis = Cache::store('redis');
                    }
                    
                    // 从缓存获取状态
                    $cacheStatus = $this->redis->get($cacheKey);
                    
                    // 如果缓存有值，直接返回
                    if ($cacheStatus !== null) {
                        return (int)$cacheStatus;
                    }
                    
                    // 缓存无值，从数据库获取并更新缓存
                    $dbStatus = MttaskModel::where('id', $taskId)->value('status');
                    
                    if ($dbStatus !== null) {
                        // 设置缓存时增加过期时间
                        $this->redis->setex($cacheKey, 3600, $dbStatus);
                        Log::info("任务状态缓存同步：task_id={$taskId}, 状态={$dbStatus}");
                        return $dbStatus;
                    }
                    
                    Log::warning("任务状态数据库查询为空：task_id={$taskId}");
                    return null;
                    
                } catch (\Exception $e) {
                    if ($i == $this->config['max_retry_count'] - 1) {
                        throw $e;
                    }
                    usleep(100000);
                }
            }
        } catch (\Exception $e) {
            Log::error("检查任务状态一致性失败: task_id={$taskId}, error={$e->getMessage()}");
            return null;
        }
        
        return null;
    }
    /**
     * 保存消息ID到缓存
     */
    private function saveMessageIdToCache(int $taskId, string $groupId, int $msgIndex, int $messageId, int $sendUserId): bool
    {
        try {
            // 创建消息ID缓存的键名，包含发送账户ID
            $messageIdsKey = $this->getMessageIdsCacheKey($taskId, $groupId);
            
            // 使用Hash结构保存消息索引和对应的消息ID
            $this->redis->hSet($messageIdsKey, $msgIndex, $messageId);
            
            // 设置较长的过期时间，确保整个任务周期内可用
            $this->redis->expire($messageIdsKey, $this->config['message_cache_expire']);
            
            Log::info("消息ID缓存成功: task_id={$taskId}, group_id={$groupId}, msg_index={$msgIndex}, message_id={$messageId}, send_user_id={$sendUserId}");
            return true;
            
        } catch (\Exception $e) {
            Log::error("保存消息ID到缓存失败: error={$e->getMessage()}, task_id={$taskId}, group_id={$groupId}, msg_index={$msgIndex}, send_user_id={$sendUserId}");
            return false;
        }
    }
    
    /**
     * 获取消息ID缓存键名
     */
    private function getMessageIdsCacheKey(int $taskId, string $groupId): string
    {
        $groupHash = $groupId;
        $currentCycle = (int)($this->redis->get($this->getRedisKey("cycle:{$taskId}")) ?: 1);
        return $this->getRedisKey("{$taskId}:group:{$groupHash}:cycle:{$currentCycle}:message_ids");
    }
    

    /**
     * 构建HTTP请求参数
     */
    private function buildHttpParams(array $msgConfig, array $sendUser, string $groupId, int $firstMessageId = 0): array
    {
        $feedbackType='';
        if($msgConfig['feedbackType']>0){$feedbackType="forward";}
        $sessionPath = $this->getSessionPath($msgConfig, $sendUser);
        $params = [
            'action' => 'send_messages',
            'tdata_path' => $sessionPath['session_path'],
            'api_id' => config('telegram.api_id'),
            'api_hash' => config('telegram.api_hash'),
            'group_id' => $groupId,
            'message_type' => $msgConfig['sendType'],
            'feedback_type' => $feedbackType,
            'delay' => intval($msgConfig['delay'] ?? 1),
            'first_msg_id' => $firstMessageId,
            'last_api_address'=>$sessionPath['last_api_address'],
            'user_send'=>$sessionPath
        ];
        
        // 添加代理信息
        if (!empty($sendUser['proxyip'])) {
            $parts = explode('##', $sendUser['proxyip']);
            if (count($parts) >= 3) {
                list($ip_port, $username, $password) = $parts;
                $params['proxy'] = "socks5://{$username}:{$password}@{$ip_port}";
            }
        }

        // 根据消息类型添加特有参数
        switch ($msgConfig['sendType']) {
            case 'text':
                $params['message_text'] = $msgConfig['text'] ?? '';
                break;
            case 'image':
                if (!empty($msgConfig['images'])) {
                    $domain = rtrim(config('telegram.cdn_domain'), '/');
                    $fullImagePaths = [];
                    foreach ((array)$msgConfig['images'] as $img) {
                        $fullImagePaths[] = $domain . '/' . ltrim($img, '/');
                    }
                    $params['media_paths'] = implode(',', $fullImagePaths);
                }
                break;    
            case 'image_text':
                if (!empty($msgConfig['images'])) {
                    $domain = rtrim(config('telegram.cdn_domain'), '/');
                    $fullImagePaths = [];
                    foreach ((array)$msgConfig['images'] as $img) {
                        $fullImagePaths[] = $domain . '/' . ltrim($img, '/');
                    }
                    $params['media_paths'] = implode(',', $fullImagePaths);
                    $params['message_text'] = $msgConfig['text'] ?? '';
                }
                break;
        }
        
        return $params;
    }
    private function getSessionPath(array $msgConfig, array $sendUser) {
        // 检查消息配置中的发送用户ID与当前使用的发送用户ID是否一致
        if (isset($msgConfig['sendUser']) && (int)$msgConfig['sendUser'] !== (int)$sendUser['id']) {
            try {
                $userId = (int)$msgConfig['sendUser'];
                $cacheKey = $this->getRedisKey("user:{$userId}");
                $cacheData = $this->redis->get($cacheKey);
                
                if ($cacheData && $cacheData !== 'null') {
                    // 解析缓存数据
                    $userData = json_decode($cacheData, true);
                    
                    if ($userData && !empty($userData['session_path'])) {
                        Log::info("使用消息配置中指定的用户 {$userId} 的 session_path".$userData['session_path']);
                        return $userData;
                    }
                }
                
                // 缓存未命中或解析失败，尝试从数据库获取
                $userInfo = $this->getUserInfo($userId);
                if ($userInfo && !empty($userInfo['session_path'])) {
                    Log::info("从数据库获取用户 {$userId} 的 session_path");
                    return $userInfo;
                }
            } catch (\Exception $e) {
                Log::error("获取消息配置中指定用户的 session_path 失败: " . $e->getMessage());
            }
        }
        
        // 默认使用当前发送用户的 session_path
        Log::info("使用当前发送用户 {$sendUser['id']} 的 session_path".$sendUser['session_path']);
        return $sendUser;
    }
    /**
     * 发送HTTP请求到Python服务
     */
    private function sendHttpRequest(array $params,string $last_api_address): array
    {
        $client = new Client();
        $apiAddress=$last_api_address??'http://127.0.0.1:5000';
        $pythonServiceUrl = $apiAddress . '/telegram_action';
        //log::info("第{$last_api_address}条:".json_encode($params,JSON_UNESCAPED_UNICODE));
        try {
            $response = $client->post($pythonServiceUrl, [
                'json' => $params,
                'timeout' => 90
            ]);
            
            $result = json_decode($response->getBody(), true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("Python服务返回无效JSON: " . json_last_error_msg());
            }
            log::info("返回:".json_encode($result,JSON_UNESCAPED_UNICODE));
            return $result;
        } catch (RequestException $e) {
            throw new \Exception("HTTP请求失败: " . $e->getMessage());
        }
    }
    
    /**
     * 标记任务为失败
     */
    private function markTaskAsFailed(int $taskId, string $errorMsg): void
    {
        try {
            $task = MttaskModel::find($taskId);
            if ($task) {
                $task->save([
                    'status' => self::status_failed,
                    'error_msg' => $errorMsg,
                    'update_time' => time()
                ]);
            }
            
            // 清理缓存
            $this->redis->del($this->getRedisKey("status:{$taskId}"));
            $this->redis->del($this->getRedisKey("config:{$taskId}"));
            $this->redis->del($this->getRedisKey("pause_flag:{$taskId}"));
            
        } catch (\Exception $e) {
            Log::error("标记任务为失败时出错: task_id={$taskId}, error={$e->getMessage()}");
        }
    }
    
    /**
     * 写入任务日志
     */
    private function writeTaskLog(int $taskId, string $message, bool $isNew = false): void
    {
        $logDir = public_path() . 'uploads/task_logs/';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
    
        $logFile = $logDir . $taskId . '.txt';
        $time = date('Y-m-d H:i:s');
    
        if ($isNew) {
            // 新任务 -> 覆盖写，清空旧日志
            file_put_contents($logFile, "[{$time}] 【发送者	  内容	类型：1成功,2失败,3其他	  接收者  结果 	附加】\n");
        }
    
        // 追加写
        file_put_contents($logFile, "[{$time}] {$message}\n", FILE_APPEND);
    }
    
    /**
     * 生成Redis键名
     */
    private function getRedisKey(string $key): string
    {
        return $this->redisPrefix . $key;
    }
    
    /**
     * 外部调用接口：暂停任务
     */
    public static function pauseTask(int $taskId): bool
    {
        $redisPrefix = 'telegram_task:';
        $pauseFlagKey = $redisPrefix . 'pause_flag:' . $taskId;
        
        try {
            $redis = Cache::store('redis');
            // 设置暂停标志
            $redis->set($pauseFlagKey, 1, 86400);
            
            // 更新任务状态为暂停中
            $task = MttaskModel::find($taskId);
            if ($task && $task->status == self::status_running) {
                $task->save([
                    'status' => self::status_paused,
                    'update_time' => time()
                ]);
            }
            
            Log::info("已发送暂停请求: task_id={$taskId}");
            return true;
        } catch (\Exception $e) {
            Log::error("发送暂停请求失败: task_id={$taskId}, error={$e->getMessage()}");
            return false;
        }
    }
    
    /**
     * 任务失败回调
     */
    public function failed(array $data, \Exception $e): void
    {
        $taskId = $data['task']['id'] ?? 0;
        Log::error("队列任务最终失败，已达到最大重试次数。 task_id={$taskId}, error=" . $e->getMessage());
        
        // 标记任务为失败
        $this->markTaskAsFailed($taskId, "队列执行失败: " . $e->getMessage());
    }
    
    
    private function acquireMysqlGate(): bool
    {
        try {
            $key = $this->getRedisKey('mysql_gate_count');
            $val = (int)$this->redis->incr($key);
            if ($val === 1) {
                $this->redis->expire($key, 60);
            }
            if ($val > (int)$this->config['mysql_gate_limit']) {
                $this->redis->decr($key);
                return false;
            }
            return true;
        } catch (\Exception $e) {
            return true;
        }
    }

    private function releaseMysqlGate(): void
    {
        try {
            $key = $this->getRedisKey('mysql_gate_count');
            $val = (int)$this->redis->get($key);
            if ($val > 0) {
                $this->redis->decr($key);
            }
        } catch (\Exception $e) {
        }
    }

    private function isTooManyConnectionsError(\Exception $e): bool
    {
        $msg = $e->getMessage();
        return stripos($msg, 'Too many connections') !== false || stripos($msg, '1040') !== false;
    }
}
