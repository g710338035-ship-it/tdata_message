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
        'status_check_interval' => 15,    // 状态检查间隔(秒)
        'redis_batch_size' => 1000,      // Redis批量操作大小
        'max_retry_count' => 3,          // 最大重试次数
        'retry_delay' => 30,             // 重试延迟时间(秒)
        'cache_expire' => 86400,         // 缓存过期时间(秒)
        'task_cache_expire' => 86400,     // 任务配置缓存时间(秒)
        'cycle_cache_expire' => 86400,  // 循环计数缓存时间(秒)
        'mysql_gate_limit' => 200,
        'mysql_gate_retry_delay' => 3,
    ];
    
    // 任务状态常量
    const STATUS_PENDING = 1;       // 未开始
    const STATUS_RUNNING = 2;       // 运行中
    const STATUS_COMPLETED = 3;     // 已完成
    const STATUS_FAILED = 4;        // 失败
    const STATUS_STOPPED = 5;       // 已停止
    const STATUS_FILTERED = 6;      // 已过滤
    const STATUS_PAUSED = 7;        // 已暂停

    public function __construct()
    {
        $this->redis = Cache::store('redis');
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
        $progressKey = $this->getRedisKey("last_processed:{$taskId}");
        $currentIndex = $this->redis->get($progressKey) ?: ($data['task']['current_index'] ?? 0);
        
        Log::info("开始处理队列任务: task_id={$taskId}, job_id={$job->getJobId()}, current_index={$currentIndex}");
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
                if (!in_array($task->status, [self::STATUS_RUNNING, self::STATUS_PAUSED, self::STATUS_PENDING])) {
                    throw new \Exception("任务状态不允许执行，当前状态: {$task->status}");
                }
                
                // 如果是暂停状态，恢复为运行中
                if ($task->status == self::STATUS_PAUSED) {
                    $this->writeTaskLog($taskId, "任务从暂停状态恢复执行");
                }
                
                // 更新任务为运行中
                $task->save([
                    'status' => self::STATUS_RUNNING,
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
            
            $this->handleProcessResult($taskId, $data, $job, $result);
            
        } catch (\Exception $e) {
            pcntl_alarm(0); // 取消超时检测
            Log::error("任务 {$taskId} 处理异常: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            
            // 标记任务为失败
            $this->markTaskAsFailed($taskId, $e->getMessage());
            $this->releaseMysqlGate();
            $job->delete();
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
                    Log::info("任务 {$taskId} 将在 {$delay} 秒后继续处理下一条消息，索引: {$result['nextIndex']}");

                    $newData = $data;
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
       
        //$this->writeTaskLog($taskId, "任务初始化完成，准备开始发送消息，当前索引: {$currentIndex}");
        Log::info("任务 {$taskId} 初始化完成，准备开始发送消息，当前索引: {$currentIndex}");

        // 定义Redis键名
        $statusKey = $this->getRedisKey("status:{$taskId}");
        $lastProcessedKey = $this->getRedisKey("last_processed:{$taskId}");
        $pauseFlagKey = $this->getRedisKey("pause_flag:{$taskId}");
        $resumeTimeKey = $this->getRedisKey("resume_time:{$taskId}");
        $taskConfigCacheKey = $this->getRedisKey("config:{$taskId}");       
        $cycleKey = $this->getRedisKey("cycle:{$taskId}");
        
        $this->redis->set($statusKey, self::STATUS_RUNNING); // 标记为运行中
        
        // 检查是否是恢复的任务
        $lastProcessedIndex = $this->redis->get($lastProcessedKey);
        $isResumedTask = !empty($lastProcessedIndex) && $lastProcessedIndex > 0;
        
        if ($isResumedTask && $currentIndex == 0) {
            $currentIndex = $lastProcessedIndex;
            $this->redis->set($resumeTimeKey, time(), 3600);
            $this->writeTaskLog($taskId, "任务从消息索引 {$lastProcessedIndex} 处恢复处理");
            Log::info("任务 {$taskId} 从消息索引 {$lastProcessedIndex} 处恢复处理");
        }
        
        // 解析任务配置
        $config = $this->getTaskConfig($taskId, $task, $taskConfigCacheKey);
        // 提取配置变量: messages, groupList, concurrent, xhnum, currentCycle
        $messages = $config['messages'];
        $groupList = $config['groupList'];
        $xhnum = $config['xhnum'];
        $currentCycle = $config['currentCycle'];
        
        // 初始化消息缓存
        $this->initMessageCacheForGroups($taskId, $messages, $groupList, $currentCycle);
        
        // 检查是否有暂停请求
        if ($this->redis->get($pauseFlagKey)) {
            $this->handlePauseRequest($task, $currentIndex);
            return ['action' => 'pause'];
        }
        
        // 获取用户映射表
        list($userMap, $archivedUserIds) = $this->getUserMap($messages);
        
        // 过滤掉需要发送给已归档/已删除用户的消息
        $messages = $this->filterArchivedUserMessages($messages, $archivedUserIds, $task, $taskId);
        if (empty($messages)) {
            return ['action' => 'complete'];
        }
        
        // 处理首条消息（仅在当前索引为0时）
        /*if ($currentIndex == 0) {
            $firstMsgConfig = $messages[0];
            $firstMsgResult = $this->handleFirstMessage($taskId, $groupList, $firstMsgConfig, $userMap);
            
            // 更新任务计数
            $this->updateTaskCounters($task, $firstMsgResult['successCount'], $firstMsgResult['failCount'], $firstMsgResult['failDetails']);
            
            return [
                'action' => 'continue',
                'nextIndex' => 1,
                'delay' => isset($firstMsgConfig['delay']) ? max(1, (int)$firstMsgConfig['delay']) : 1
            ];
        }
        
        // 恢复的任务，获取已保存的首条消息ID
        $firstMessageIds = $this->getFirstMessageIds($taskId, $groupList);
        */
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
        
        if ($cachedConfig) {
            $config = json_decode($cachedConfig, true);
            $currentCycle = $this->redis->get($this->getRedisKey("cycle:{$taskId}")) ?: 1;
            return array_merge($config, ['currentCycle' => $currentCycle]);
        }
        
        // 解析配置
        $messages = json_decode($task->messages, true);
        $originalGroupList = $task->group_list ?? '';
        $groupList = !empty($originalGroupList) ? preg_split('/\s*[,，;；]\s*/', $originalGroupList) : [];
        $groupList = array_values(array_filter($groupList));
        $concurrent = $task->concurrent > 0 ? $task->concurrent : 5;
        $xhnum = $task->xhnum > 0 ? $task->xhnum : 1;
        $currentCycle = $this->redis->get($this->getRedisKey("cycle:{$taskId}")) ?: 1;
        
        // 缓存配置
        $this->redis->set($this->getRedisKey("cycle:{$taskId}"), $currentCycle, $this->config['cycle_cache_expire']);
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
    private function initMessageCacheForGroups(int $taskId, array $messages, array $groupList, int $currentCycle): void
    {
        foreach ($groupList as $groupId) {
            $cacheKey = $this->getGroupMessageCacheKey($taskId, $groupId);
            if ($currentCycle > 1) {
                $this->initMessageCache($taskId, $messages, [$groupId]);
                Log::info("任务 {$taskId} 第 {$currentCycle} 次循环，重置群组 {$groupId} 消息状态");
                $this->writeTaskLog($taskId, "任务 {$taskId} 第 {$currentCycle} 次循环，重置群组 {$groupId} 消息状态",true);
                
               
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
            $this->redis->set($this->getRedisKey("user:{$userId}"), json_encode($user), $this->config['cache_expire']);
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
        return $user && ($user['account_status'] ?? '') === '正常' && !empty($user['session_path']);
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
        $maxAttempts = 3;  // 最多尝试次数
        $attempt = 0;
        while ($attempt < $maxAttempts) {
            $attempt++;
            if ($this->redis->get($pauseFlagKey)) {
                return null;
            }
            $status = $this->checkTaskStatusConsistency($taskId);
            if (!in_array($status, [self::STATUS_RUNNING, self::STATUS_PAUSED])) {
                return null;
            }
            $msg = $this->getLatestMessageByIndex($taskId, $index);
            if (!$msg || empty($msg['sendUser'])) {
                $this->writeTaskLog($taskId, "{$index} 发送人 信息不存在");
                sleep($interval);
                continue;
            }
            $userId = (int)$msg['sendUser'];
            $user = $this->getUserFresh($userId);
            if ($this->isUserUsable($user)) {
                return ['id' => $userId, 'user' => $user];
            }
            $this->writeTaskLog($taskId, "{$index} 发送失败");
            sleep($interval);
        }
        return null;
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
                $this->config['cache_expire']
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
        
        Log::info("过滤已归档/删除用户的消息，用户ID: " . implode(',', $archivedUserIds));
        $originalCount = count($messages);
        $messages = array_filter($messages, function($message) use ($archivedUserIds) {
            return !in_array($message['sendUser'], $archivedUserIds);
        });
        $filteredCount = $originalCount - count($messages);
        $messages = array_values($messages);
        
        Log::info("共过滤 {$filteredCount} 条消息");
        
        // 如果所有消息都被过滤，更新任务状态
        if (empty($messages)) {
            Log::info("所有消息的发送人都已归档/删除，终止当前任务处理");
            $task->save([
                'status' => self::STATUS_FILTERED,
                'update_time' => time(),
                'error_msg' => '所有消息发送人已归档或删除'
            ]);
        }
        
        return $messages;
    }
    
    /**
     * 获取首条消息ID列表
     */
    private function getFirstMessageIds(int $taskId, array $groupList): array
    {
        $firstMsgIdsKey = $this->getRedisKey("{$taskId}:first_msg_ids");
        $rawFirstMsgIds = $this->redis->hGetAll($firstMsgIdsKey);
        $firstMessageIds = [];
        
        foreach ($rawFirstMsgIds as $groupId => $msgId) {
            $firstMessageIds[$groupId] = $msgId;
        }
        
        Log::info("任务 {$taskId} 恢复处理，已加载首条消息ID映射: " . count($firstMessageIds) . " 个群组");
        return $firstMessageIds;
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
            if ($msgResult['stoperror']) {
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
            $this->redis->set($this->getRedisKey("cycle:{$taskId}"), $nextCycle, $this->config['cycle_cache_expire']);
    
            Log::info("任务 {$taskId} 第 {$currentCycle} 轮完成，准备进行第 {$nextCycle} 轮");
            $this->cleanTaskCache($taskId, $statusKey, $taskConfigCacheKey, $lastProcessedKey, $groupList);
            
            return [
                'action'    => 'requeue',
                'nextIndex' => 0,
                'nextCycle' => $nextCycle,
                'delay'     => 5
            ];
        }
        
        // 任务完成
        $finalStatus = self::STATUS_COMPLETED;
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
        $this->redis->delete($statusKey);
        $this->redis->delete($taskConfigCacheKey);
        $this->redis->delete($lastProcessedKey);
        //$this->redis->delete($this->getRedisKey("cycle:{$taskId}"));
        
        foreach ($groupList as $groupId) {
            $cacheKey = $this->getGroupMessageCacheKey($taskId, $groupId);
            $this->redis->del($cacheKey);

            // 清理消息ID缓存
            $messageIdsKey = $this->getMessageIdsCacheKey($taskId, $groupId);
            $this->redis->del($messageIdsKey);
            
            // 清理feedbackType缓存
            $feedbackTypeCacheKey = $this->getFeedbackTypeCacheKey($taskId, $groupId);
            $this->redis->del($feedbackTypeCacheKey);
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
            'status' => self::STATUS_PAUSED,
            'update_time' => time()
        ]);
        
        // 保存当前处理进度
        $this->redis->set($lastProcessedKey, $currentIndex, $this->config['cache_expire']);
        
        // 清除暂停标志
        $this->redis->delete($pauseFlagKey);
        
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
        
        $msgConfig = $messages[$index];
        
        // 检查任务状态
        $currentStatus = $this->checkTaskStatusConsistency($taskId);
        if (!in_array($currentStatus, [self::STATUS_RUNNING, self::STATUS_PAUSED])) {
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
        if (empty($sendUserId) || empty($userMap[$sendUserId])) {
            $failCount++;
            $failDetails[] = "发送人ID无效: {$sendUserId}";
            $this->writeTaskLog($taskId, "{$index} 3 发送人ID无效: {$sendUserId}");
            return ['successCount' => $successCount, 'failCount' => $failCount, 'failDetails' => $failDetails, 'stopFlag' => true, 'stoperror'=>true];
        }
        
        $sendUser = $userMap[$sendUserId];
        
        // 过滤不可用账号
        if (($sendUser['account_status'] ?? '') !== '正常') {
            $this->writeTaskLog($taskId, "{$index} 发送人 {$sendUser['account']} 状态不可用: {$sendUser['account_status']} {$sendUser['account_status_desc']}");
            $assigned = $this->waitUntilAssignedUserReady($taskId, $index, $pauseFlagKey);
            if (!$assigned) {
                return ['successCount' => $successCount, 'failCount' => $failCount, 'failDetails' => $failDetails, 'stopFlag' => true, 'stoperror'=>true];
            }
            $sendUserId = $assigned['id'];
            $sendUser = $assigned['user'];
        }
        
        $tdataPath = $sendUser['session_path'];
        if (empty($tdataPath)) {
            $failCount++;
            $failDetails[] = "发送人 {$sendUserId} 的session路径无效";
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
            
           
            if ($this->isMessageSent($taskId, $groupId, $index)) {
                $successCount++;
                continue;
            }
            
            // 发送消息
            $sendSucceeded = false;
            while (!$sendSucceeded) {
                list($success, $error) = $this->sendMessageToGroup($taskId, $msgConfig, $sendUser, $groupId,$index);
                if ($success) {
                    $this->markMessageAsSent($taskId, $groupId, $index);
                    $successCount++;
                    $sendSucceeded = true;
                    break;
                }
                $failCount++;
                $failDetails[] = $error;
                if ($this->redis->get($pauseFlagKey)) {
                    return ['successCount' => $successCount, 'failCount' => $failCount, 'failDetails' => $failDetails, 'stopFlag' => true, 'stoperror'=>true];
                }
                $assigned = $this->waitUntilAssignedUserReady($taskId, $index, $pauseFlagKey);
                if (!$assigned) {
                    return ['successCount' => $successCount, 'failCount' => $failCount, 'failDetails' => $failDetails, 'stopFlag' => true, 'stoperror'=>true];
                }
                $sendUserId = $assigned['id'];
                $sendUser = $assigned['user'];
            }
        }
        
        return ['successCount' => $successCount, 'failCount' => $failCount, 'failDetails' => $failDetails, 'stopFlag' => $stopFlag, 'stoperror'=>false];
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
     */
    private function sendMessageToGroup(int $taskId, array $msgConfig, array $sendUser, string $groupId, int $index): array
    {
        try {
        

            // 根据 feedbackType 获取正确的 firstMsgId
            $actualFirstMsgId = $this->getMsgIdByFeedbackType($taskId, $groupId, $msgConfig);

            $params = $this->buildHttpParams($msgConfig, $sendUser, $groupId, $actualFirstMsgId);
             
            $result = $this->sendHttpRequest($params,$sendUser['last_api_address']);
            
            if ($result['status'] ?? false) {
                $messageId = $result['data']['success'][0]['message_id'];
                $this->saveMessageIdToCache($taskId, $groupId, $index, $messageId);
                $this->writeTaskLog($taskId, "{$sendUser['account']} [{$index}]{$msgConfig['text']} 1 {$groupId}  发送成功  message_id:{$messageId}");
                return [true, ''];
            } else {
                $error = "群组 {$groupId} 消息发送失败: " . ($result['message'] ?? '未知错误');
                $this->writeTaskLog($taskId, "{$sendUser['account']}  [{$index}]   2  {$groupId}  ".($result['message'] ?? '未知错误'));
                
                // 更新用户状态
                $this->updateUserStatus($sendUser, $result);
                return [false, $error];
            }
        } catch (\Exception $e) {
            $error = "群组 {$groupId} 请求异常: " . $e->getMessage();
            $this->writeTaskLog($taskId, "{$sendUser['account']}  [{$index}]  3  {$groupId}  ".$e->getMessage());
            return [false, $error];
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
            $messageIdsKey = $this->getMessageIdsCacheKey($taskId, $groupId);
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
     * 获取feedbackType缓存键
     */
    private function getFeedbackTypeCacheKey(int $taskId, string $groupId): string
    {
        $groupHash = md5($groupId);
        return $this->getRedisKey("{$taskId}:group:{$groupHash}:feedback_ids");
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
                $this->redis->expire($cacheKey, $this->config['cycle_cache_expire']);
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
        $groupHash = md5($groupId);
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
            Log::info("消息状态标记为已发送: task_id={$taskId}, group_id={$groupId}, msg_index={$msgIndex}");
            return true;
        } catch (\Exception $e) {
            Log::error("标记消息状态失败: error={$e->getMessage()}, task_id={$taskId}, group_id={$groupId}, msg_index={$msgIndex}");
            return false;
        }
    }
    
    /**
     * 处理首条消息发送
     */
    private function handleFirstMessage(int $taskId, array $groupList, array $firstMsgConfig, array $userMap): array
    {
        $firstMessageIds = [];
        $successCount = 0;
        $failCount = 0;
        $failDetails = [];
        $firstMsgIndex = 0;
        $pauseFlagKey = $this->getRedisKey("pause_flag:{$taskId}");
        $checkInterval = $this->config['status_check_interval'];
        $startTime = time();
        $firstMsgIdsKey = $this->getRedisKey("{$taskId}:first_msg_ids");

        foreach ($groupList as $groupId) {
            // 检查暂停请求
            if (time() - $startTime >= $checkInterval) {
                if ($this->redis->get($pauseFlagKey)) {
                    Log::info("处理首条消息过程中检测到暂停请求... task_id={$taskId}");
                    $this->writeTaskLog($taskId, "处理首条消息过程中检测到暂停请求...");
                    break;
                }
                $startTime = time();
            }
            
            if (!isset($firstMessageIds[$groupId]) && $firstMsgConfig) {
                $sendUserId = $firstMsgConfig['sendUser'] ?? 0;
                if (!empty($sendUserId) && !empty($userMap[$sendUserId])) {
                    $sendUser = $userMap[$sendUserId];
                    
                    if (!$this->isMessageSent($taskId, $groupId, $firstMsgIndex)) {
                        list($success, $messageId, $error) = $this->sendFirstMessage(
                            $taskId, $firstMsgConfig, $sendUser, $groupId
                        );
                        
                        if ($success && $messageId) {
                            $firstMessageIds[$groupId] = $messageId;
                            $this->markMessageAsSent($taskId, $groupId, $firstMsgIndex);
                            $successCount++;
                        } else {
                            $failCount++;
                            $failDetails[] = $error ?? "群组 {$groupId} 首条消息发送失败";
                        }
                    } else {
                        $firstMessageIds[$groupId] = $this->getCachedFirstMessageId($taskId, $groupId);
                        $successCount++;
                    }
                    
                    // 缓存首条消息ID
                    if (!empty($firstMessageIds[$groupId])) {
                        $this->redis->hSet($firstMsgIdsKey, $groupId, $firstMessageIds[$groupId]);
                        $this->redis->expire($firstMsgIdsKey, $this->config['cycle_cache_expire']);
                    }
                }
            }
        }
        
        return [
            'firstMessageIds' => $firstMessageIds,
            'successCount' => $successCount,
            'failCount' => $failCount,
            'failDetails' => $failDetails
        ];
    }
    
    /**
     * 发送首条消息
     */
    private function sendFirstMessage(int $taskId, array $msgConfig, array $sendUser, string $groupId): array
    {
        $pauseFlagKey = $this->getRedisKey("pause_flag:{$taskId}");
        $userId = $sendUser['id'];
        //while (true) {
            try {
                $params = $this->buildHttpParams($msgConfig, $sendUser, $groupId, 0);
               
                $result = $this->sendHttpRequest($params,$sendUser['last_api_address']);
                if ($result['status'] ?? false && !empty($result['data']['success'][0]['message_id'])) {
                    $messageId = $result['data']['success'][0]['message_id'];
                    
                    $this->saveMessageIdToCache($taskId, $groupId, 0, $messageId);
                    
                    $this->writeTaskLog($taskId, "{$sendUser['account']} [0]{$msgConfig['text']} 1 {$groupId}  发送成功");
                    return [true, $messageId, null];
                }
                $this->updateUserStatus($sendUser, $result);
                if ($this->redis->get($pauseFlagKey)) {
                    return [false, null, "任务已暂停"];
                }
                $assigned = $this->waitUntilAssignedUserReady($taskId, 0, $pauseFlagKey);
                if (!$assigned) {
                    return [false, null, "等待账号恢复被中断"];
                }
                $userId = $assigned['id'];
                $sendUser = $assigned['user'];
            } catch (\Exception $e) {
                if ($this->redis->get($pauseFlagKey)) {
                    return [false, null, "任务已暂停"];
                }
                $assigned = $this->waitUntilAssignedUserReady($taskId, 0, $pauseFlagKey);
                if (!$assigned) {
                    return [false, null, "等待账号恢复被中断"];
                }
                $userId = $assigned['id'];
                $sendUser = $assigned['user'];
            }
        //}
    }

    /**
     * 检查任务是否已停止
     */
    private function isTaskStopped(int $taskId): bool
    {
        // 先检查并更新任务状态一致性
        $currentStatus = $this->checkTaskStatusConsistency($taskId);
        
        if ($currentStatus === null) {
            // 如果状态检查失败，回退到原有逻辑
            $cacheKey = $this->getRedisKey("status:{$taskId}");
            $status = $this->redis->get($cacheKey);
            
            if ($status === null) {
                $status = MttaskModel::where('id', $taskId)->value('status');
                // 缓存10秒，避免频繁查库
                $this->redis->set($cacheKey, $status);
            }
            $currentStatus = $status;
        }
        
        // 任务状态：1-未开始，2-运行中，3-已完成，4-失败，5-已停止，6-已过滤，7-已暂停
        return in_array($currentStatus, [self::STATUS_PENDING, self::STATUS_STOPPED, self::STATUS_FILTERED]);
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
    private function saveMessageIdToCache(int $taskId, string $groupId, int $msgIndex, int $messageId): bool
    {
        try {
            // 创建消息ID缓存的键名
            $messageIdsKey = $this->getMessageIdsCacheKey($taskId, $groupId);
            
            // 使用Hash结构保存消息索引和对应的消息ID
            $this->redis->hSet($messageIdsKey, $msgIndex, $messageId);
            
            // 设置较长的过期时间，确保整个任务周期内可用
            $this->redis->expire($messageIdsKey, $this->config['cycle_cache_expire']);
            
            Log::info("消息ID缓存成功: task_id={$taskId}, group_id={$groupId}, msg_index={$msgIndex}, message_id={$messageId}");
            return true;
            
        } catch (\Exception $e) {
            Log::error("保存消息ID到缓存失败: error={$e->getMessage()}, task_id={$taskId}, group_id={$groupId}, msg_index={$msgIndex}");
            return false;
        }
    }
    
    /**
     * 获取消息ID缓存键名
     */
    private function getMessageIdsCacheKey(int $taskId, string $groupId): string
    {
        $groupHash = md5($groupId);
        return $this->getRedisKey("{$taskId}:group:{$groupHash}:message_ids");
    }
    /**
     * 获取缓存的首条消息ID
     */
    private function getCachedFirstMessageId(int $taskId, string $groupId): ?string
    {
        $firstMsgIdsKey = $this->getRedisKey("{$taskId}:first_msg_ids");
        $msgId = $this->redis->hGet($firstMsgIdsKey, $groupId);
        
        if ($msgId) {
            Log::info("获取缓存的首条消息ID成功: task_id={$taskId}, group_id={$groupId}, msg_id={$msgId}");
        } else {
            Log::warning("未找到缓存的首条消息ID: task_id={$taskId}, group_id={$groupId}");
        }
        
        return $msgId;
    }

    /**
     * 构建HTTP请求参数
     */
    private function buildHttpParams(array $msgConfig, array $sendUser, string $groupId, int $firstMessageId = 0): array
    {
        $feedbackType='';
        if($msgConfig['feedbackType']>0){$feedbackType="forward";}
        $params = [
            'action' => 'send_messages',
            'tdata_path' => $sendUser['session_path'],
            'api_id' => config('telegram.api_id'),
            'api_hash' => config('telegram.api_hash'),
            'group_id' => $groupId,
            'message_type' => $msgConfig['sendType'],
            'feedback_type' => $feedbackType,
            'delay' => intval($msgConfig['delay'] ?? 1),
            'first_msg_id' => $firstMessageId
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

    /**
     * 发送HTTP请求到Python服务
     */
    private function sendHttpRequest(array $params,string $last_api_address): array
    {
        $client = new Client();
        $apiAddress=$last_api_address??'http://127.0.0.1:5000';
        $pythonServiceUrl = $apiAddress . '/telegram_action';
        log::info("第{$last_api_address}条:".json_encode($params,JSON_UNESCAPED_UNICODE));
        try {
            $response = $client->post($pythonServiceUrl, [
                'json' => $params,
                'timeout' => 60
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
                    'status' => self::STATUS_FAILED,
                    'error_msg' => $errorMsg,
                    'update_time' => time()
                ]);
            }
            
            // 清理缓存
            $this->redis->delete($this->getRedisKey("status:{$taskId}"));
            $this->redis->delete($this->getRedisKey("config:{$taskId}"));
            $this->redis->delete($this->getRedisKey("pause_flag:{$taskId}"));
            
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
            if ($task && $task->status == self::STATUS_RUNNING) {
                $task->save([
                    'status' => self::STATUS_PAUSED,
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
