<?php
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Argument;
use think\console\input\Option;
use think\facade\Db;
use think\facade\Log;
use think\facade\Cache;
use Workerman\Worker;
use Workerman\Timer;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use app\admin\model\Mttask as MttaskModel;
use app\admin\model\Mtuser as MtuserModel;

class TelegramTaskManagerHttp extends Command
{
    // 工作进程池
    private $worker = null;
    // 进程PID文件路径
    private $pidFile = '';
    
    private $redisPrefix = 'telegram_task:';
    // 配置参数
    private $config = [
        // 单次获取任务数量
        'batch_size' => 10,
        // 任务超时时间(秒)
        'task_timeout' => 3600,
        // 并发任务数上限
        'max_concurrent_tasks' => 200,
        // 进程任务队列大小
        'process_task_queue_size' => 5
    ];
    
    // 构造函数初始化PID文件路径
    public function __construct()
    {
        parent::__construct();
        $this->pidFile = runtime_path() . 'telegram_task_manager_http.pid';
    }
    
    // 命令配置
    protected function configure()
    {
        $this->setName('telegram:task-manager-http')
             ->setDescription('Telegram多任务并行管理（HTTP版）')
             ->addArgument(
                 'action',
                 Argument::REQUIRED,
                 '操作类型: start/stop/restart/status'
             )
             ->addOption(
                 'processes',
                 'p',
                 Option::VALUE_OPTIONAL,
                 '工作进程数量',
                 1
             )
             ->addOption(
                 'task-id',
                 't',
                 Option::VALUE_OPTIONAL,
                 '指定单个任务ID处理'
             )
             ->addOption(
                 'daemon',
                 'd',
                 Option::VALUE_NONE,
                 '是否以守护进程方式运行'
             );
    }
    
    private function getRedisCache()
    {
        return Cache::store('redis');
    }
    
    // 执行命令
    protected function execute(Input $input, Output $output)
    {
        $action = $input->getArgument('action');
        $processes = (int)$input->getOption('processes');
        $taskId = $input->getOption('task-id');
        $daemon = $input->getOption('daemon');
        
        if (!in_array($action, ['start', 'stop', 'restart', 'status'])) {
            $output->error('无效的操作类型，支持: start/stop/restart/status');
            return 1;
        }
        
        if ($action === 'start' && $daemon) {
            $this->daemonize();
        }
        
        switch ($action) {
            case 'start':
                $this->startWorker($output, $processes, $taskId);
                break;
            case 'stop':
                $this->stopWorker($output);
                break;
            case 'restart':
                $this->stopWorker($output);
                sleep(2);
                $this->startWorker($output, $processes, $taskId);
                break;
            case 'status':
                $this->showStatus($output);
                break;
        }

        return 0;
    }
    
    /**
     * 启动工作进程
     */
    private function startWorker(Output $output, int $processes = 1, $taskId = null)
    {
        if (is_file($this->pidFile) && posix_kill((int)file_get_contents($this->pidFile), 0)) {
            $output->error('任务管理器已在运行中');
            return;
        }
    
        $this->worker = new Worker();
        $this->worker->count = $processes;
        $this->worker->name = 'TelegramTaskWorkerHttp';
    
        $this->worker->onWorkerStart = function ($worker) use ($output, $taskId) {
            if ($worker->id === 0) {
                //$this->cleanExpiredCache();
                // 初始化并发任务计数器
                $this->initConcurrentCounter();
                $masterPid = method_exists(Worker::class, 'getMasterPid')? Worker::getMasterPid(): posix_getppid();
                file_put_contents($this->pidFile, $masterPid);
            }
            
            $output->writeln("工作进程 #{$worker->id} 已启动");
            Log::info("Telegram任务工作进程启动: " . $worker->id);
            
            // 对于指定任务ID的情况，立即处理该任务
            /*if ($taskId) {
                try {
                    $task = MttaskModel::where('id', $taskId)->find();
                    if ($task) {
                        $this->processTask($task);
                    }
                } catch (\Exception $e) {
                    Log::error("处理指定任务失败: " . $e->getMessage());
                }
            } else {*/
                // 定期获取任务
                Timer::add(5, function () use ($worker, $taskId) {
                    $this->fetchAndProcessTasks($worker, $taskId);
                });
            //}
        };
    
        $this->worker->onWorkerStop = function ($worker) use ($output) {
            $output->writeln("工作进程 #{$worker->id} 已停止");
            Log::info("Telegram任务工作进程停止: " . $worker->id);
        };
        Log::info("Telegram任务管理器(HTTP版)已启动，工作进程数: {$processes}");
        $output->writeln("Telegram任务管理器(HTTP版)已启动，工作进程数: {$processes}");
        Worker::runAll();
    }


    
    /**
     * 批量获取并处理任务
     */
    private function fetchAndProcessTasks($worker, $specificTaskId = null)
    {
        try {
            // 检查当前并发任务数
            $currentConcurrentTasks = $this->getRedisCache()->get($this->redisPrefix . 'concurrent_tasks') ?: 0;
            if ($currentConcurrentTasks >= $this->config['max_concurrent_tasks']) {
                Log::info("当前并发任务数已达上限({$currentConcurrentTasks}/{$this->config['max_concurrent_tasks']})，暂停获取新任务");
                return;
            }
            
            $where = [
                'status' => 1,
            ];
    
            if ($specificTaskId) {
                $where['id'] = $specificTaskId;
                $batchSize = 1;
            } else {
                // 使用配置的批量大小
                $batchSize = $this->config['batch_size'];
                // 根据当前并发任务数动态调整批次大小
                $availableSlots = $this->config['max_concurrent_tasks'] - $currentConcurrentTasks;
                $batchSize = min($batchSize, $availableSlots, $this->config['process_task_queue_size']);
            }
    
            // 获取进程级任务队列缓存键
            $processQueueKey = $this->redisPrefix . 'process_queue:' . $worker->id;
            $currentQueueSize = $this->getRedisCache()->llen($processQueueKey);
            
            // 如果进程队列已满，不再获取新任务
            if ($currentQueueSize >= $this->config['process_task_queue_size']) {
                Log::info("工作进程 {$worker->id} 任务队列已满({$currentQueueSize}/{$this->config['process_task_queue_size']})，跳过本次获取");
                return;
            }
    
            try {
                // 尝试获取新任务（不使用全局锁，而是使用数据库事务保证原子性）
                $taskIds = MttaskModel::where($where)->order('create_time asc')->lock(true)->limit($batchSize)->column('id');
                
                if (empty($taskIds)) {
                    return;
                }
                
                Log::info("工作进程 {$worker->id} 成功锁定任务ID列表: " . implode(',', $taskIds));
                
                // 立即更新任务状态
                $updateResult = MttaskModel::whereIn('id', $taskIds)
                    ->update([
                        'status' => 2,
                        'lock_time' => time(),
                        'worker_pid' => posix_getpid(),
                        'worker_id' => $worker->id
                    ], true);
                
                
                // 将任务ID逐个加入进程队列
                foreach ($taskIds as $singleTaskId) {
                    $this->getRedisCache()->rpush($processQueueKey, $singleTaskId);//在列表中添加一个或多个值
                    Log::info("工作进程 {$worker->id} 将任务 {$singleTaskId} 加入进程队列");
                }
                // 更新并发任务数
                $this->getRedisCache()->incrBy($this->redisPrefix . 'concurrent_tasks', count($taskIds));//自增缓存
                
                Log::info("工作进程 {$worker->id} 获取到任务: " . implode(',', $taskIds) . ", 当前并发任务数: " . ($currentConcurrentTasks + count($taskIds)));
                
                // 处理进程队列中的任务
                $this->processTasksFromQueue($worker);
    
            } catch (\Exception $e) {
                Log::error("获取任务失败: " . $e->getMessage() . ", 堆栈: " . $e->getTraceAsString());
            }
        } catch (\Exception $e) {
            $errorInfo = [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'worker_id' => $worker->id
            ];
            Log::error("批量任务处理出错", $errorInfo);
        }
    }
    
    /**
     * 从进程队列处理任务
     */
    private function processTasksFromQueue($worker)
    {
        $processQueueKey = $this->redisPrefix . 'process_queue:' . $worker->id;
        
        // 一次性获取队列中所有任务
        $allTaskIds = $this->getRedisCache()->lrange($processQueueKey, 0, -1);
        Log::info("工作进程 {$worker->id} 队列中任务数量: " . count($allTaskIds) . ", 任务列表: " . implode(',', $allTaskIds));
        
        if (empty($allTaskIds)) {
            Log::info("工作进程 {$worker->id} 队列为空，退出处理");
            return;
        }
        
        // 创建进程数组，用于记录子进程ID
        $pids = [];
        
        foreach ($allTaskIds as $taskId) {
            // 检查任务状态一致性，确保数据库和缓存状态同步
            //$this->checkTaskStatusConsistency($taskId);
            if (empty($taskId) || !is_numeric($taskId)) {
                Log::error("任务ID异常，跳过任务: " . var_export($task, true));
                continue;
            }

            // 创建子进程处理每个任务
            $pid = pcntl_fork();
            //Log::info("工作进程 {$worker->id} 创建子进程id {$pid}");
            if ($pid == -1) {
                // 创建失败
                //Log::error("工作进程 {$worker->id} 创建子进程失败，无法处理任务 {$taskId}");
                $this->getRedisCache()->lRem($processQueueKey, $taskId, 1);
                $this->getRedisCache()->decr($this->redisPrefix . 'concurrent_tasks');
            } elseif ($pid == 0) {
                // 子进程 - 独立处理任务
                try {
                    // 再次检查队列中是否还有这个任务，防止重复处理
                    $remainingTasks = $this->getRedisCache()->lrange($processQueueKey, 0, -1);
                    if (!in_array($taskId, $remainingTasks)) {
                        Log::info("工作进程 {$worker->id} 任务 {$taskId} 已被其他进程处理，子进程退出");
                        exit(0);
                    }
                    
                    //Log::info("工作进程 {$worker->id} 任务 {$taskId} 子进程已创建，开始处理，PID=" . posix_getpid());
                    
                    // 从队列中移除任务
                    $removeCount = $this->getRedisCache()->lRem($processQueueKey, $taskId, 1);
                    if ($removeCount <= 0) {
                        Log::warning("工作进程 {$worker->id} 任务 {$taskId} 从队列移除失败，可能已被处理");
                        exit(0);
                    }
                    
                    // 检查任务状态，确保任务仍然是运行中状态
                    $taskRecord  = MttaskModel::where('id', $taskId)->find();
                    if (!$taskRecord || $taskRecord['status'] != 2) {
                        Log::warning("任务 {$taskId} 状态异常，子进程退出");
                        $this->getRedisCache()->decr($this->redisPrefix . 'concurrent_tasks');
                        exit(0);
                    }
                    
                    Log::info("工作进程 {$worker->id} 开始处理任务: task_id={$taskId}, pid=" . posix_getpid());
                    $this->processTask($taskRecord);
                    Log::info("工作进程 {$worker->id} 任务处理完成: task_id={$taskId}");
                } catch (\Exception $e) {
                    // 处理任务失败，记录错误
                    Log::error("任务 {$taskId} 子进程处理失败: " . $e->getMessage());
                    
                    // 更新任务状态为失败
                    if (!empty($taskId) && is_numeric($taskId)) {
                        MttaskModel::where('id', $taskId)->update([
                            'status' => 4,
                            'error_msg' => $e->getMessage(),
                            'update_time' => time()
                        ]);
                    }
                    
                    // 减少并发任务计数
                    $this->getRedisCache()->decr($this->redisPrefix . 'concurrent_tasks');
                } finally {
                    exit(0); // 子进程完成后退出
                }
            } else {
                // 父进程记录子进程ID
                $pids[] = $pid;
                Log::info("工作进程 {$worker->id} 为任务 {$taskId} 创建子进程，PID={$pid}");
                if (!empty($taskId) && is_numeric($taskId)) {
                    try {
                        MttaskModel::where('id', $taskId)->update(['task_pid' => $pid]);
                    } catch (\Throwable $e) {
                        Log::error("任务 {$taskId} 更新 task_pid 失败: " . $e->getMessage());
                    }
                }
                // 非阻塞方式等待子进程，防止僵尸进程
                pcntl_waitpid($pid, $status, WNOHANG);
            }
        }
        
        // 添加子进程回收机制
        Timer::add(2, function() use ($pids, $worker) {
            foreach ($pids as $key => $pid) {
                if (pcntl_waitpid($pid, $status, WNOHANG) > 0) {
                    Log::info("工作进程 {$worker->id} 子进程 {$pid} 已结束，状态码: {$status}");
                    unset($pids[$key]);
                }
            }
            // 所有子进程都已处理完毕，清除定时器
            if (empty($pids)) {
                return false;
            }
        });
        
        Log::info("工作进程 {$worker->id} 已为所有任务创建子进程，父进程继续处理其他工作");
    }
    

    /**
     * 处理单个任务
     */
    private function processTask($task)
    {
        $taskId = $task['id'];
        // 检查任务状态一致性
        $currentStatus = $this->checkTaskStatusConsistency($taskId);
        // 确保任务仍然是运行中状态
        if ($currentStatus != 2) {
            Log::warning("任务状态已变更，取消处理: task_id={$taskId}, 当前状态={$currentStatus}");
            // 更新并发计数
            $this->getRedisCache()->decr($this->redisPrefix . 'concurrent_tasks');
            return;
        }
        
        $this->writeTaskLog($taskId, "任务初始化完成，准备开始发送消息", true);
        
        $statusKey = $this->redisPrefix . 'status:' . $taskId;
        $lastProcessedKey = $this->redisPrefix . 'task:last_processed:' . $taskId;//最后的信息
        $resumeTimeKey = $this->redisPrefix . 'task:resume_time:' . $taskId;//恢复时间

        $firstMessageIds = [];
        $redis = $this->getRedisCache();
        
        try {
            $this->getRedisCache()->set($statusKey, 2); // 标记为运行中
           // Log::info("任务 {$taskId} 开始处理");
            
            // 检查是否是恢复的任务
            $lastProcessedIndex = $this->getRedisCache()->get($lastProcessedKey);
            $isResumedTask = !empty($lastProcessedIndex) && $lastProcessedIndex > 0;
            
            if ($isResumedTask) {
                // 记录恢复时间
                $this->getRedisCache()->set($resumeTimeKey, time(), 3600);
                $this->writeTaskLog($taskId, "任务从消息索引 {$lastProcessedIndex} 处恢复处理");
                Log::info("任务 {$taskId} 从消息索引 {$lastProcessedIndex} 处恢复处理");
            }
            
            // 解析任务配置
            $taskConfigCacheKey = $this->redisPrefix . 'task:config:' . $taskId;
            
            $cachedConfig = $this->getRedisCache()->get($taskConfigCacheKey);
            
            if ($cachedConfig) {
                $config = json_decode($cachedConfig, true);
                $messages = $config['messages'];
                $groupList = $config['groupList'];
                $concurrent = $config['concurrent'];
                $xhnum = $config['xhnum'];
                $currentCycle = $this->getRedisCache()->get($this->redisPrefix . 'task:cycle:' . $taskId) ?: 1;
            } else {
                $messages = json_decode($task['messages'], true);
                $groupList = !empty($task['group_list']) ? explode(',', $task['group_list']) : [];
                $concurrent = $task['concurrent'] > 0 ? $task['concurrent'] : 5;
                $xhnum = $task['xhnum'] > 0 ? $task['xhnum'] : 1;
                $currentCycle = 1;
                $this->getRedisCache()->set($this->redisPrefix . 'task:cycle:' . $taskId, $currentCycle, 172800);
                $this->getRedisCache()->set(
                    $taskConfigCacheKey,
                    json_encode([
                        'messages' => $messages,
                        'groupList' => $groupList,
                        'concurrent' => $concurrent,
                        'xhnum' => $xhnum,
                    ]),
                    7200
                );
            }
    
            // 初始化消息缓存
            foreach ($groupList as $groupId) {
                $cacheKey = $this->getGroupMessageCacheKey($taskId, $groupId);
                if ($currentCycle > 1) {
                    $this->initMessageCache($taskId, $messages, [$groupId]);
                    Log::info("任务 {$taskId} 第 {$currentCycle} 次循环，重置群组 {$groupId} 消息状态");
                } elseif (!$redis->exists($cacheKey)) {
                    $this->initMessageCache($taskId, $messages, [$groupId]);
                }
            }
            
            // 获取用户映射表
            $sendUserIds = array_unique(array_column($messages, 'sendUser'));
            $cacheKeys = array_map(function($id) {
                return $this->redisPrefix . 'user:' . $id;
            }, $sendUserIds);
        
            // 批量获取缓存
            $cachedUsers = $this->getRedisCache()->mGet($cacheKeys);
            $userMap = [];
            $missedIds = [];
            $archivedUserIds = []; // 存储已归档或已删除的用户ID
            
            // 处理缓存命中的数据
            foreach ($sendUserIds as $i => $userId) {
                $cacheData = $cachedUsers[$i];
                if ($cacheData === 'null') {
                    // 命中空值标记（已删除用户）
                    $archivedUserIds[] = $userId;
                    Log::info("用户 {$userId} 已删除（缓存标记），加入过滤列表");
                } elseif ($cacheData) {
                    // 关键修复：先反序列化，再解析JSON
                    $unserializedData = @unserialize($cacheData);
                    // 如果反序列化失败（不是序列化数据），则直接使用原数据
                    $jsonData = $unserializedData !== false ? $unserializedData : $cacheData;
                    $user = json_decode($jsonData, true);
                   
                    $userMap[$userId] = $user;
                    // 检查是否归档
                    if (isset($user['archive']) && $user['archive'] == 0) {
                        $archivedUserIds[] = $userId;
                        Log::info("用户 {$userId} 已归档，加入过滤列表");
                    }
                    
                } else {
                   // Log::info("用户的消息，userId: " . $userId);
                    $missedIds[] = $userId;
                }
            }
           
            // 查询未命中缓存的用户
            if (!empty($missedIds)) {
                 Log::info("用户的消息，missedIds: " . implode(',', $missedIds));
                $freshUsers = Db::name('mtuser')
                    ->whereIn('id', $missedIds)
                    ->field('id, tdata_path,session_path, proxyip, archive,account_status,account_status_desc')
                    ->select()
                    ->toArray();
                
                // 计算已删除的用户ID
                $existingUserIds = array_column($freshUsers, 'id');
                $deletedUserIds = array_diff($missedIds, $existingUserIds);
                
                // 处理存在的用户
                foreach ($freshUsers as $user) {
                    $userMap[$user['id']] = $user;
                    // 缓存用户信息（24小时过期）
                    $this->getRedisCache()->set(
                        $this->redisPrefix . 'user:' . $user['id'],
                        json_encode($user),
                        86400
                    );
                    // 检查是否归档
                    if ($user['archive'] == 0) {
                        $archivedUserIds[] = $user['id'];
                        Log::info("用户 {$user['id']} 已归档，加入过滤列表");
                    }
                }
                
                // 处理已删除的用户（缓存空值标记）
                if (!empty($deletedUserIds)) {
                    foreach ($deletedUserIds as $userId) {
                        $archivedUserIds[] = $userId;
                        // 存储空值标记（10分钟过期，避免缓存穿透）
                        $this->getRedisCache()->set(
                            $this->redisPrefix . 'user:' . $userId,
                            json_encode(null),
                            600
                        );
                        Log::info("用户 {$userId} 已删除，加入过滤列表并标记缓存");
                    }
                }
            }
            
            // 去重归档用户ID
            $archivedUserIds = array_unique($archivedUserIds);
            
            // 过滤掉需要发送给已归档/已删除用户的消息
            if (!empty($archivedUserIds)) {
                Log::info("过滤已归档/删除用户的消息，用户ID: " . implode(',', $archivedUserIds));
                $originalCount = count($messages);
                $messages = array_filter($messages, function($message) use ($archivedUserIds) {
                    return !in_array($message['sendUser'], $archivedUserIds);
                });
                $filteredCount = $originalCount - count($messages);
                Log::info("共过滤 {$filteredCount} 条消息");
                
                // 如果所有消息都被过滤，更新任务状态并提前返回
                if (empty($messages)) {
                    Log::info("所有消息的发送人都已归档/删除，终止当前任务处理");
                    MttaskModel::where('id', $task['id'])->update([
                        'status' => 6, // 新增状态：已过滤
                        'update_time' => time(),
                        'error_msg' => '所有消息发送人已归档或删除'
                    ]);
                    return;
                }
            }
            
            // 处理首条消息
            $firstMsgConfig = empty($messages) ? null : $messages[0];
            $successCount = 0;
            $failCount = 0;
            $failDetails = [];
            $stopFlag = false;
            
            $lastProcessedIndex = $this->getRedisCache()->get($this->redisPrefix . 'task:last_processed:' . $taskId) ?: -1;
            
            if ($lastProcessedIndex <= 0) {
                // 如果是新任务或从第一条消息开始
                $firstMsgResult = $this->handleFirstMessage($taskId, $groupList, $firstMsgConfig, $userMap);
                
                $firstMessageIds = $firstMsgResult['firstMessageIds'];
                $successCount = $firstMsgResult['successCount'];
                $failCount = $firstMsgResult['failCount'];
                $failDetails = $firstMsgResult['failDetails'];
            } else {
                // 恢复的任务，获取已保存的首条消息ID
                $firstMessageIds = [];
                $firstMsgIdsKey = $this->redisPrefix . "task:{$taskId}:first_msg_ids";
                $rawFirstMsgIds = $this->getRedisCache()->hGetAll($firstMsgIdsKey);
                
                foreach ($rawFirstMsgIds as $groupId => $msgId) {
                    $firstMessageIds[$groupId] = $msgId;
                }
                
                Log::info("任务 {$taskId} 恢复处理，已加载首条消息ID映射: " . count($firstMessageIds) . " 个群组");
            }
            
            // 处理后续消息
            $subsequentResult = $this->handleSubsequentMessages(
                $taskId, 
                $messages, 
                $groupList, 
                $firstMessageIds, 
                $userMap,
                $lastProcessedIndex
            );
            
            $successCount += $subsequentResult['successCount'];
            $failCount += $subsequentResult['failCount'];
            $failDetails = array_merge($failDetails, $subsequentResult['failDetails']);
            $stopFlag = $subsequentResult['stopFlag'];
            
            // 处理循环任务
            if ($xhnum > 1 && $currentCycle < $xhnum && !$stopFlag) {
                $nextCycle = $currentCycle + 1;
                $this->getRedisCache()->set($this->redisPrefix . 'task:cycle:' . $taskId, $nextCycle, 172800);
                MttaskModel::where('id', $taskId)->update([
                    'status' => 1, // 重置为未开始，等待下一轮
                    'current_cycle' => $nextCycle,
                    'update_time' => time()
                ]);
                Log::info("任务 {$taskId} 第 {$currentCycle} 轮完成，准备进行第 {$nextCycle} 轮");
            } else {
                // 任务完成
                $finalStatus = $stopFlag ? 5 : 3;
                MttaskModel::where('id', $taskId)->update([
                    'status' => $finalStatus,
                    'success_count' => $successCount,
                    'fail_count' => $failCount,
                    'fail_details' => implode('; ', $failDetails),
                    'update_time' => time(),
                    'end_time' => time(),
                    'task_pid' => 0,
                ]);
                $this->getRedisCache()->delete($statusKey);
                $this->getRedisCache()->delete($taskConfigCacheKey);
                if($finalStatus==3){
                    foreach ($groupList as $groupId) {
                        $cacheKey = $this->getGroupMessageCacheKey($taskId, $groupId);
                        $redis->del($cacheKey);
                    }
                    $redis->del($statusKey);
                  
                    $redis->del($this->redisPrefix . 'task:cycle:' . $taskId);
                }
                
                Log::info("任务 {$taskId} 处理完成，成功: {$successCount}, 失败: {$failCount}");
            }
            
        } catch (\Exception $e) {
            Log::error("任务处理异常: task_id={$taskId}, error={$e->getMessage()}, trace={$e->getTraceAsString()}");
            MttaskModel::where('id', $taskId)->update([
                'status' => 4,
                'error_msg' => $e->getMessage(),
                'update_time' => time()
            ]);
            $this->getRedisCache()->set($statusKey, 4); // 标记为失败
        } finally {
            // 确保并发计数减少
            $this->getRedisCache()->decr($this->redisPrefix . 'concurrent_tasks');
        }
    }
     /**
     * 初始化消息缓存
     */
    private function initMessageCache($taskId, $messages, $groupList)
    {
        try {
            // 每个子进程独立获取 Redis
            $redis = $this->getRedisCache();
    
            $batchSize = 1000; // 每批写入1000条，防止单次命令过大
    
            foreach ($groupList as $groupId) {
                $cacheKey = $this->getGroupMessageCacheKey($taskId, $groupId);
    
                $totalMessages = count($messages);
                $batches = array_chunk($messages, $batchSize, true); // 保留索引
    
                foreach ($batches as $batchIndex => $batch) {
                    $retry = 0;
                    while ($retry < 3) {
                        try {
                            $redis->multi(\Redis::PIPELINE);
                            foreach ($batch as $index => $msgConfig) {
                                $redis->hSet($cacheKey, $index, 0);
                            }
                            $redis->exec();
                            break; // 成功跳出重试循环
                        } catch (\RedisException $e) {
                            $retry++;
                            Log::warning("Redis批量写入失败，重试 {$retry}/3: " . $e->getMessage(), [
                                'task_id' => $taskId,
                                'group_id' => $groupId,
                                'batch' => $batchIndex
                            ]);
                            $redis->connect('127.0.0.1', 6379);
                            // 如果有密码，取消下面注释
                            // $redis->auth('yourpassword');
                            usleep(500000); // 0.5秒再重试
                        }
                    }
    
                    if ($retry === 3) {
                        Log::error("Redis批量写入失败，任务缓存初始化失败", [
                            'task_id' => $taskId,
                            'group_id' => $groupId,
                            'batch' => $batchIndex
                        ]);
                    }
                }
    
                // 设置过期时间
                $redis->expire($cacheKey, 172800); // 2天
            }
    
            Log::info("消息缓存初始化完成".json_encode([
                'task_id' => $taskId,
                'groups' => $groupList
            ]));
    
        } catch (\Exception $e) {
            Log::error("初始化消息缓存异常", [
                'task_id' => $taskId,
                'error' => $e->getMessage()
            ]);
        }
    }
    /**
     * 获取群组消息缓存键
     */
    private function getGroupMessageCacheKey($taskId, $groupId) {
        $groupHash = md5($groupId);
        return $this->redisPrefix . "task:{$taskId}:group:{$groupHash}:messages";
    }
    
    /**
     * 检查消息是否已发送
     */
    private function isMessageSent($taskId, $groupId, $msgIndex) {
        $cacheKey = $this->getGroupMessageCacheKey($taskId, $groupId);
        $status = $this->getRedisCache()->hGet($cacheKey, $msgIndex);
        return $status === '1';
    }
    
    /**
     * 标记消息为已发送
     */
    private function markMessageAsSent($taskId, $groupId, $msgIndex) {
        $cacheKey = $this->getGroupMessageCacheKey($taskId, $groupId);
        try {
            $this->getRedisCache()->hSet($cacheKey, $msgIndex, 1);
            Log::info("消息状态标记为已发送: task_id={$taskId}, group_id={$groupId}, msg_index={$msgIndex}");
            return true;
        } catch (\Exception $e) {
            Log::error("标记消息状态失败: error={$e->getMessage()}, task_id={$taskId}, group_id={$groupId}, msg_index={$msgIndex}");
            return false;
        }
    }
    
    /**
     * 获取缓存的首条消息ID
     */
    private function getCachedFirstMessageId($taskId, $groupId) {
        $firstMsgIdsKey = $this->redisPrefix . "task:{$taskId}:first_msg_ids";
        $msgId = $this->getRedisCache()->hGet($firstMsgIdsKey, $groupId);
        
        if ($msgId) {
            Log::info("获取缓存的首条消息ID成功: task_id={$taskId}, group_id={$groupId}, msg_id={$msgId}");
        } else {
            Log::warning("未找到缓存的首条消息ID: task_id={$taskId}, group_id={$groupId}");
        }
        
        return $msgId;
    }
    
    /**
     * 清理过期缓存
     */
    private function cleanExpiredCache() {
        // 可添加缓存清理逻辑
    }
    
    /**
     * 构建HTTP请求参数
     */
    private function buildHttpParams($msgConfig, $sendUser, $groupId, $firstMessageId = 0)
    {
        $params = [
            'action' => 'send_messages',
            'tdata_path' => $sendUser['session_path'],
            'api_id' => config('telegram.api_id'),
            'api_hash' => config('telegram.api_hash'),
            'group_id' => $groupId,
            'message_type' => $msgConfig['sendType'],
            'feedback_type' => $msgConfig['feedbackType'] ?? '',
            'delay' => intval($msgConfig['delay'] ?? 1),
            'first_msg_id' => $firstMessageId
        ];
        Log::info("获取消息值：".json_encode($params));
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
            case 'image_text':
                if (!empty($msgConfig['images'])) {
                    $domain = rtrim(config('telegram.cdn_domain'), '/');
                    $fullImagePaths = [];
                    foreach ((array)$msgConfig['images'] as $img) {
                        $fullImagePaths[] = $domain . '/' . ltrim($img, '/');
                    }
                    $params['images'] = $fullImagePaths;
                    $params['image_text'] = $msgConfig['text'] ?? '';
                }
                break;
            // 可添加其他消息类型处理
        }
        log::info(json_encode($params));
        return $params;
    }

    /**
     * 发送HTTP请求到Python服务
     */
    private function sendHttpRequest($params)
    {
        $client = new Client();
        $pythonServiceUrl = config('telegram.python_service_url'). '/telegram_action';
        log::info($pythonServiceUrl);
        try {
            $response = $client->post($pythonServiceUrl, [
                'json' => $params,
                'timeout' => 60
            ]);
            
            $result = json_decode($response->getBody(), true);
            log::info(json_encode($result));
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("Python服务返回无效JSON: " . json_last_error_msg());
            }
            
            return $result;
        } catch (RequestException $e) {
             log::info($e->getMessage());
            throw new \Exception("HTTP请求失败: " . $e->getMessage());
        }
    }

    /**
     * 处理首条消息发送（HTTP版本）
     */
    private function handleFirstMessage($taskId, $groupList, $firstMsgConfig, $userMap)
    {
        $firstMessageIds = [];
        $successCount = 0;
        $failCount = 0;
        $failDetails = [];
        $firstMsgIndex = 0;

        foreach ($groupList as $groupId) {
            if (!isset($firstMessageIds[$groupId]) && $firstMsgConfig) {
                $sendUserId = $firstMsgConfig['sendUser'] ?? 0;
                if (!empty($sendUserId) && !empty($userMap[$sendUserId])) {
                    $sendUser = $userMap[$sendUserId];
                    
                    if (!$this->isMessageSent($taskId, $groupId, $firstMsgIndex)) {
                        $params = $this->buildHttpParams(
                            $firstMsgConfig, 
                            $sendUser, 
                            $groupId, 
                            0
                        );
                       // Log::info("获取消息值：".json_encode($params));
                        try {
                            
                            $result = $this->sendHttpRequest($params);
                         //    Log::info("获取首条消息值：".json_encode($result, JSON_UNESCAPED_UNICODE));
                            if ($result['status'] && !empty($result['data']['success'][0]['message_id'])) {
                                $firstMessageIds[$groupId] = $result['data']['success'][0]['message_id'];
                                $this->markMessageAsSent($taskId, $groupId, $firstMsgIndex);
                                $this->writeTaskLog($taskId, "✅ 群组 {$groupId} 用户 {$sendUserId} 发送成功");
                                $successCount++;
                            } else {
                                $failCount++;
                                $failDetails[] = "群组 {$groupId} 首条消息发送失败";
                                $this->writeTaskLog($taskId, "❌ 群组 {$groupId} 用户 {$sendUserId} 发送失败: {$sendUser['account_status_desc']}");
                            }
                        } catch (\Exception $e) {
                            $failCount++;
                            $failDetails[] = "群组 {$groupId} 请求异常: " . $e->getMessage();
                            $this->writeTaskLog($taskId, "⚠️ 群组 {$groupId} 异常: " . $e->getMessage());
                        }
                    } else {
                        $firstMessageIds[$groupId] = $this->getCachedFirstMessageId($taskId, $groupId);
                    }
                    
                    // 缓存首条消息ID，以便任务恢复时使用
                    if (!empty($firstMessageIds[$groupId])) {
                        $firstMsgIdsKey = $this->redisPrefix . "task:{$taskId}:first_msg_ids";
                        $this->getRedisCache()->hSet($firstMsgIdsKey, $groupId, $firstMessageIds[$groupId]);
                        $this->getRedisCache()->expire($firstMsgIdsKey, 172800);
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
     * 处理后续消息发送（HTTP版本）
     */
    private function handleSubsequentMessages($taskId, $messages, $groupList, $firstMessageIds, $userMap, $startIndex = 0)
    {
        $successCount = 0;
        $failCount = 0;
        $failDetails = [];
        $firstMsgIndex = 0;
        $lastSendTime = 0;
        $stopFlag = false;
        $lastProcessedKey = $this->redisPrefix . 'task:last_processed:' . $taskId;
        $resumeTimeKey = $this->redisPrefix . 'task:resume_time:' . $taskId;
        
        // 检查是否是恢复的任务
        $isResumedTask = $this->getRedisCache()->exists($resumeTimeKey);
        $resumeTime = $isResumedTask ? $this->getRedisCache()->get($resumeTimeKey) : 0;

        foreach ($messages as $index => $msgConfig) {
            // 跳过首条消息
            if ($index == $firstMsgIndex) {
                continue;
            }
            
            // 如果指定了开始索引，跳过前面的消息
            if ($index < $startIndex) {
                continue;
            }
            
            // 定期检查任务状态一致性
            if ($index % 10 === 0) { // 每处理10条消息检查一次
                $currentStatus = $this->checkTaskStatusConsistency($taskId);
                if ($currentStatus != 2) {
                    Log::info("检测到任务状态已变更，准备优雅退出... task_id={$taskId}, 当前状态={$currentStatus}");
                    $this->writeTaskLog($taskId, "检测到任务状态已变更，准备优雅退出...");
                    
                    // 保存最后处理的消息索引
                    $this->getRedisCache()->set($lastProcessedKey, $index, 86400);
                    $stopFlag = true;
                    break;
                }
            }
            
            if ($this->isTaskStopped($taskId)) {
                Log::info("检测到任务已被手动停止，准备优雅退出...");
                $this->writeTaskLog($taskId, "检测到任务已被手动停止，准备优雅退出...");
                
                // 保存最后处理的消息索引
                $this->getRedisCache()->set($lastProcessedKey, $index, 86400);
                $stopFlag = true;
                break;
            }
            
            $delay = isset($msgConfig['delay']) ? max(1, (int)$msgConfig['delay']) : 1;
            
            if (empty($msgConfig['sendType'])) {
                $failCount++;
                $failDetails[] = "消息类型不能为空";
                continue;
            }
            
            $sendUserId = $msgConfig['sendUser'] ?? 0;
            if (empty($sendUserId) || empty($userMap[$sendUserId])) {
                $failCount++;
                $failDetails[] = "发送人ID无效: {$sendUserId}";
                continue;
            }
            
            $sendUser = $userMap[$sendUserId];
            // 过滤不可用账号
            if (($sendUser['account_status'] ?? '') !== '正常') {
                $failCount++;
                $failDetails[] = "发送人 {$sendUserId} 状态不可用: {$sendUser['account_status']}";
                $this->writeTaskLog($taskId, "发送人 {$sendUserId} 状态不可用: {$sendUser['account_status']}");
                continue;
            }
            $tdataPath = $sendUser['session_path'];
            
            if (empty($tdataPath)) {
                $failCount++;
                $failDetails[] = "发送人 {$sendUserId} 的tdata路径无效";
                continue;
            }
            
            // 处理发送延迟
            $currentTime = time();
            if ($lastSendTime > 0) {
                // 对于恢复的任务，首次不需要等待延迟
                if (!($isResumedTask && $index == $startIndex)) {
                    $elapsed = $currentTime - $lastSendTime;
                    if ($elapsed < $delay) {
                        $waitTime = $delay - $elapsed;
                        $endTime = $currentTime + $waitTime;
                        while (time() < $endTime) {
                            if ($this->isTaskStopped($taskId)) {
                                // 保存最后处理的消息索引
                                $this->getRedisCache()->set($lastProcessedKey, $index, 86400);
                                $stopFlag = true;
                                break 2;
                            }
                            usleep(100000);
                        }
                    }
                }
            }
            // 清除恢复标记
            if ($isResumedTask && $index == $startIndex) {
                $this->getRedisCache()->delete($resumeTimeKey);
            }
            
            // 检查是否所有群组的该消息都已发送
            $allGroupsSent = true;
            foreach ($groupList as $groupId) {
                if (!$this->isMessageSent($taskId, $groupId, $index)) {
                    $allGroupsSent = false;
                    break;
                }
            }
            
            // 如果所有群组的该消息都已发送，跳过该消息的延迟计算
            if (!$allGroupsSent) {
                $lastSendTime = time();
            } else {
                // 所有群组都已发送，增加成功计数并跳过
                $successCount += count($groupList);
                continue;
            }
            
            foreach ($groupList as $groupId) {
                /*if (!isset($firstMessageIds[$groupId])) {
                    $failCount++;
                    $failDetails[] = "群组 {$groupId} 未获取到首条消息ID";
                    continue;
                }*/
                $firstMsgFailed = !isset($firstMessageIds[$groupId]);
                if ($firstMsgFailed) {
                    // 仅记录一次该群组的首条消息失败状态
                    if (!in_array("群组 {$groupId} 首条消息发送失败，但继续处理后续消息", $failDetails)) {
                        $failCount++;
                        $failDetails[] = "群组 {$groupId} 首条消息发送失败，但继续处理后续消息";
                    }
                }
                
                if ($this->isMessageSent($taskId, $groupId, $index)) {
                    $successCount++;
                    continue;
                }
                
                try {
                    $firstMsgId = $firstMsgFailed ? 0 : $firstMessageIds[$groupId];
                    $params = $this->buildHttpParams(
                        $msgConfig, 
                        $sendUser, 
                        $groupId, 
                        $firstMsgId
                    );
                    
                    $result = $this->sendHttpRequest($params);
                    
                    if ($result['status']) {
                        $this->markMessageAsSent($taskId, $groupId, $index);
                        $this->writeTaskLog($taskId, "✅ 群组 {$groupId} 用户 {$sendUserId} 发送成功");
                        $successCount++;
                    } else {
                        
                        $failCount++;
                        $failDetails[] = "群组 {$groupId} 消息发送失败: " . ($result['message'] ?? '未知错误');
                        $this->writeTaskLog($taskId, "❌ 群组 {$groupId} 用户 {$sendUserId} 发送失败: {$sendUser['account_status_desc']}");
                        // 更新数据库
                        MtuserModel::where('id', $sendUser['id'])->update([
                            'account_status' => $result['data']['account_status'],
                            'account_status_desc' => $result['data']['account_status_desc']
                        ]);
                        
                        // 更新 Redis 缓存
                        $cacheKey = $this->redisPrefix . 'user:' . $sendUser['id'];
                        
                        // 获取原缓存
                        $cachedData = $this->getRedisCache()->get($cacheKey);
                        
                        if ($cachedData) {
                            $userData = json_decode($cachedData, true);
                        
                            // 防止反序列化失败
                            if ($userData === null) {
                                $unserialized = @unserialize($cachedData);
                                $userData = $unserialized !== false ? $unserialized : [];
                            }
                        
                            $userData['account_status'] = $result['data']['account_status'];
                            $userData['account_status_desc'] = $result['data']['account_status_desc'];
                        
                            $this->getRedisCache()->set($cacheKey, json_encode($userData), 86400);
                        } else {
                            // 缓存不存在直接写入
                            $sendUser['account_status'] = $result['data']['account_status'];
                            $sendUser['account_status_desc'] = $result['data']['account_status_desc'];
                            $this->getRedisCache()->set($cacheKey, json_encode($sendUser), 86400);
                        }
                    }
                } catch (\Exception $e) {
                    $failCount++;
                    $failDetails[] = "群组 {$groupId} 请求异常: " . $e->getMessage();
                    $this->writeTaskLog($taskId, "⚠️ 群组 {$groupId} 异常: " . $e->getMessage());
                }
            }
        }
        
        return [
            'successCount' => $successCount,
            'failCount' => $failCount,
            'failDetails' => $failDetails,
            'stopFlag' => $stopFlag
        ];
    }

    /**
     * 检查任务是否已停止
     */
    private function isTaskStopped($taskId)
    {
        // 先检查并更新任务状态一致性
        $currentStatus = $this->checkTaskStatusConsistency($taskId);
        
        if ($currentStatus === null) {
            // 如果状态检查失败，回退到原有逻辑
            $cacheKey = $this->redisPrefix . 'status:' . $taskId;
            $status = $this->getRedisCache()->get($cacheKey);
            
            if ($status === null) {
                $status = MttaskModel::where('id', $taskId)->value('status');
                // 缓存10秒，避免频繁查库
                $this->getRedisCache()->set($cacheKey, $status);
            }
            $currentStatus = $status;
        }
        
        // 任务状态：1-未开始，2-运行中，3-已完成，4-失败，5-已停止，6-已过滤
        return in_array($currentStatus, [1, 5, 6]); // 5:已停止 6:已过滤
    }
    
    /**
     * 初始化并发任务计数
     */
    private function initConcurrentCounter()
    {
        try {
            // 重置并发任务计数
            $this->getRedisCache()->set($this->redisPrefix . 'concurrent_tasks', 0);
            
            // 清理所有进程队列
            $keys = $this->getRedisCache()->keys($this->redisPrefix . 'process_queue:*');
            if (!empty($keys)) {
                $this->getRedisCache()->del($keys);
            }
            
            // 清理所有任务状态缓存
            $statusKeys = $this->getRedisCache()->keys($this->redisPrefix . 'status:*');
            if (!empty($statusKeys)) {
                $this->getRedisCache()->del($statusKeys);
            }
            
            // 重置所有运行中的任务状态为未开始
            MttaskModel::where('status', 2)
                ->update([
                    'status' => 1,
                    'worker_pid' => 0,
                    'worker_id' => 0,
                    'task_pid' => 0,
                    'update_time' => time()
                ]);
                
            Log::info('任务管理器初始化完成：重置了并发计数、清理了进程队列和状态缓存、重置了运行中任务状态');
        } catch (\Exception $e) {
            Log::error('初始化并发任务计数失败：' . $e->getMessage());
        }
    }
    
    /**
     * 检查并更新任务状态一致性
     */
    private function checkTaskStatusConsistency($taskId)
    {
        $retryCount = 3; // 重试次数
        $retryDelay = 100; // 重试延迟(毫秒)
        $cacheKey = $this->redisPrefix . 'status:' . $taskId;
        
        try {
            for ($i = 0; $i < $retryCount; $i++) {
                try {
                    $redis = $this->getRedisCache();
                    
                    // 检查连接状态
                    if (!$redis->ping()) {
                        // 重新获取连接
                        $this->resetRedisCache(); // 假设存在重置连接的方法
                        $redis = $this->getRedisCache();
                    }
                    
                    // 从缓存获取状态
                    $cacheStatus = $redis->get($cacheKey);
                    
                    // 如果缓存有值，直接返回
                    if ($cacheStatus !== null) {
                        return $cacheStatus;
                    }
                    
                    // 缓存无值，从数据库获取并更新缓存
                    $dbStatus = MttaskModel::where('id', $taskId)->value('status');
                    
                    if ($dbStatus !== null) {
                        // 设置缓存时增加过期时间，避免缓存长期无效
                        $redis->setex($cacheKey, 3600, $dbStatus); // 1小时过期
                        Log::info("任务状态缓存同步：task_id={$taskId}, 状态={$dbStatus}");
                        return $dbStatus;
                    }
                    
                    Log::warning("任务状态数据库查询为空：task_id={$taskId}");
                    return null;
                    
                } catch (\RedisException $e) {
                    // Redis相关异常，考虑重试
                
                    if ($i == $retryCount - 1) {
                        throw $e; // 最后一次重试失败，抛出异常
                    }
                    usleep($retryDelay * 1000); // 毫秒转微秒
                }
            }
        } catch (\Exception $e) {
         
            return null;
        }
    }

    /**
     * 缓存首条消息ID
     */
    private function cacheFirstMessageId($taskId, $groupId, $messageId)
    {
        $cacheKey = $this->redisPrefix . "task:{$taskId}:first_msg_ids";
        $this->getRedisCache()->hSet($cacheKey, $groupId, $messageId);
        $this->getRedisCache()->expire($cacheKey, 172800);
    }


    /**
     * 停止工作进程
     */
    private function stopWorker(Output $output)
    {
        $workerName = 'TelegramTaskWorkerHttp'; // 工作进程名称（必须与启动时一致）
        
        // 步骤1：强制杀死所有包含进程名的进程
        $psCommand = "ps aux | grep '{$workerName}' | grep -v grep | awk '{print $2}'";
        exec($psCommand, $pids);
        
        if (!empty($pids)) {
            foreach ($pids as $pid) {
                if (is_numeric($pid)) {
                    // 先尝试优雅终止，再强制终止
                    posix_kill((int)$pid, SIGTERM);
                    usleep(100000); // 等待100ms
                    if (posix_kill((int)$pid, 0)) { // 检查是否仍存在
                        posix_kill((int)$pid, SIGKILL); // 强制杀死
                        $output->writeln("已强制终止进程: {$pid}");
                    } else {
                        $output->writeln("已终止进程: {$pid}");
                    }
                }
            }
        }
        
        // 步骤2：清理PID文件（无论是否有进程）
        if (is_file($this->pidFile)) {
            unlink($this->pidFile);
            $output->writeln("已清理PID文件");
        }
        
        // 步骤3：最终验证
        exec($psCommand, $remainingPids);
        if (empty($remainingPids)) {
            $output->writeln("任务管理器已成功停止");
        } else {
            $output->warning("仍有残留进程，请手动检查: " . implode(',', $remainingPids));
        }
    }

    /**
     * 显示运行状态
     */
    private function showStatus(Output $output)
    {
        $workerName = 'TelegramTaskWorkerHttp';
        
        // 直接查询所有相关进程
        $psCommand = "ps aux | grep '{$workerName}' | grep -v grep";
        exec($psCommand, $processes);
        
        if (empty($processes)) {
            // 无进程，清理残留PID文件
            if (is_file($this->pidFile)) {
                unlink($this->pidFile);
            }
            $output->writeln("任务管理器未在运行中");
            return;
        }
        
        // 提取主进程PID（第一个进程通常是主进程）
        $firstProcess = explode(' ', preg_replace('/\s+/', ' ', $processes[0]));
        $masterPid = $firstProcess[1] ?? '未知';
        
        $output->writeln("任务管理器正在运行中");
        $output->writeln("主进程PID: {$masterPid}");
        $output->writeln("工作进程数量: " . count($processes));
        
        // 任务状态统计（保持不变）
        $taskStats = MttaskModel::field('status, count(*) as num')
            ->group('status')
            ->select();
            
        $statusMap = [1 => '未开始', 2 => '运行中', 3 => '已完成', 4 => '失败', 5 => '已停止', 6 => '已过滤'];
        $output->writeln("任务状态统计:");
        foreach ($taskStats as $stat) {
            $statusName = $statusMap[$stat['status']] ?? "未知状态({$stat['status']})";
            $output->writeln("  {$statusName}: {$stat['num']}个");
        }
    }
    /**
     * 守护进程化
     */
    private function daemonize()
    {
        $pid = pcntl_fork();
        if ($pid == -1) {
            throw new \Exception('fork失败');
        } elseif ($pid > 0) {
            exit(0);
        }

        posix_setsid();
        
        $pid = pcntl_fork();
        if ($pid == -1) {
            throw new \Exception('fork失败');
        } elseif ($pid > 0) {
            exit(0);
        }
    }
    
    private function writeTaskLog($taskId, $message, $isNew = false)
    {
        $logDir = public_path() . 'uploads/task_logs/';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
    
        $logFile = $logDir . $taskId . '.txt';
        $time = date('Y-m-d H:i:s');
    
        if ($isNew) {
            // 新任务 -> 覆盖写，清空旧日志
            file_put_contents($logFile, "[{$time}] 【任务开始】\n");
        }
    
        // 追加写
        file_put_contents($logFile, "[{$time}] {$message}\n", FILE_APPEND);
    }

}
