<?php
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Argument;
use think\console\input\Option;
use think\facade\Db;
use think\facade\Log;
use Workerman\Worker;
use Workerman\Timer;
use think\facade\Cache;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class TelegramTaskManager extends Command
{
    // 工作进程池
    private $worker = null;
    // 进程PID文件路径
    private $pidFile = '';
    
    private $redisPrefix = 'telegram_task:';
    // 构造函数初始化PID文件路径
    public function __construct()
    {
        parent::__construct();
        $this->pidFile = runtime_path() . 'telegram_task_manager.pid';
    }
    
    // 命令配置 - 修复选项定义问题
    protected function configure()
    {
        $this->setName('telegram:task-manager')
             ->setDescription('Telegram多任务并行管理（支持启动/停止/重启）')
             // 使用Argument作为主操作参数，而非Option
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
        // 获取操作类型（从参数获取而非选项）
        $action = $input->getArgument('action');
        $processes = (int)$input->getOption('processes');
        $taskId = $input->getOption('task-id');
        $daemon = $input->getOption('daemon');
        
        if (!in_array($action, ['start', 'stop', 'restart', 'status'])) {
            $output->error('无效的操作类型，支持: start/stop/restart/status');
            return 1;
        }
        
        // 如果是守护进程模式启动，先进行 daemonize
        if ($action === 'start' && $daemon) {
            $this->daemonize();
        }
        
        // 执行对应操作
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
        // 检查是否已运行
        if (is_file($this->pidFile) && posix_kill((int)file_get_contents($this->pidFile), 0)) {
            $output->error('任务管理器已在运行中');
            return;
        }
    
        // 创建工作进程池
        $this->worker = new Worker();
        $this->worker->count = $processes;
        $this->worker->name = 'TelegramTaskWorker';
    
        // 进程启动回调
        $this->worker->onWorkerStart = function ($worker) use ($output, $taskId) {
            // 记录主进程PID（在Worker启动后获取）
            if ($worker->id === 0) { // 只在第一个进程记录一次
                $this->cleanExpiredCache();
                if (!method_exists(Worker::class, 'getMasterPid')) {
                    $masterPid = posix_getppid(); // 获取父进程ID作为主进程ID
                } else {
                    $masterPid = Worker::getMasterPid();
                }
                file_put_contents($this->pidFile, $masterPid);
            }
            
            $output->writeln("工作进程 #{$worker->id} 已启动");
            Log::info("Telegram任务工作进程启动". $worker->id);
            
            
            
            // 定时查询任务
            Timer::add(5, function () use ($worker, $taskId) {
                $this->fetchAndProcessTasks($worker, $taskId);
            });
        };
    
        // 进程停止回调
        $this->worker->onWorkerStop = function ($worker) use ($output) {
            $output->writeln("工作进程 #{$worker->id} 已停止");
            Log::info("Telegram任务工作进程停止". $worker->id);
        };
    
        // 启动服务
        $output->writeln("Telegram任务管理器已启动，工作进程数: {$processes}");
        Worker::runAll();
    }

    /**
     * 停止工作进程
     */
    private function stopWorker(Output $output)
    {
        if (!is_file($this->pidFile)) {
            $output->error('任务管理器未在运行中');
            return;
        }

        $pid = (int)file_get_contents($this->pidFile);
        if (posix_kill($pid, SIGTERM)) {
            // 等待进程退出
            for ($i = 0; $i < 10; $i++) {
                if (!posix_kill($pid, 0)) {
                    unlink($this->pidFile);
                    $output->writeln("任务管理器已成功停止");
                    return;
                }
                sleep(1);
            }
            $output->error("任务管理器停止超时");
        } else {
            unlink($this->pidFile);
            $output->error("任务管理器已停止（PID文件已清理）");
        }
    }

    /**
     * 显示运行状态
     */
    private function showStatus(Output $output)
    {
        if (!is_file($this->pidFile)) {
            $output->writeln("任务管理器未在运行中");
            return;
        }

        $pid = (int)file_get_contents($this->pidFile);
        if (posix_kill($pid, 0)) {
            $output->writeln("任务管理器正在运行中");
            $output->writeln("主进程PID: {$pid}");
            
            // 查询工作进程数量
            $psOutput = shell_exec("ps -ef | grep TelegramTaskWorker | grep -v grep | wc -l");
            $output->writeln("工作进程数量: " . trim($psOutput));
            
            // 查询当前任务状态
            $taskStats = Db::name('mttask')
                ->field('status, count(*) as num')
                ->group('status')
                ->select();
                
            $statusMap = [1 => '未开始', 2 => '运行中', 3 => '已完成', 4 => '失败', 5 => '已停止'];
            $output->writeln("任务状态统计:");
            foreach ($taskStats as $stat) {
                $output->writeln("  {$statusMap[$stat['status']]}: {$stat['num']}个");
            }
        } else {
            $output->writeln("任务管理器已停止（残留PID文件已清理）");
            unlink($this->pidFile);
        }
    }
    
    /**
     * 获取并处理任务
     */
     
     /**
 * 批量获取并处理任务（优化版）
 */
    private function fetchAndProcessTasks($worker, $specificTaskId = null)
    {
        try {
            // 构建查询条件：未开始 + 超时未处理的任务
            $where = [
                'status' => 1, // 支持未开始(1)和循环任务(2)
                //'lock_time' => ['lt', time() - 300] // 解锁超过5分钟的僵死任务
            ];
    
            // 若指定单个任务ID，忽略批量逻辑
            if ($specificTaskId) {
                $where['id'] = $specificTaskId;
                $batchSize = 1; // 强制单任务
            } else {
                $batchSize = 5; // 批量获取5个任务（可根据实际调整）
            }
    
            $lockKey = $this->redisPrefix . 'lock:task_fetch';
            $lockValue = uniqid();
            // 获取分布式锁（5秒过期，防止死锁）
            $lockAcquired = $this->getRedisCache()->set($lockKey, $lockValue, 5, ['nx', 'ex' => 5]);
            
            if (!$lockAcquired) {
                Log::info("工作进程 {$worker->id} 未获取到任务锁，跳过本次查询");
                return;
            }
         
            try {
                // 批量查询任务并加行锁
                // 直接查询并提取ID数组（推荐）
                $taskIds = Db::name('mttask')
                    ->where($where)
                    ->order('create_time asc')
                    ->lock(true)
                    ->limit($batchSize)
                    ->column('id'); // 直接返回id字段的一维数组
                
                if (empty($taskIds)) {
                   // Log::info("工作进程 {$worker->id} 未获取到任务");
                    return;
                }
                
                // 如果需要完整的任务数据，再单独查询一次
                $tasks = Db::name('mttask')
                    ->whereIn('id', $taskIds)
                    ->select()
                    ->toArray(); // 确保转换为数组
                Log::info("工作进程 {$worker->id} 批量获取到任务: " . implode(',', $taskIds) . ", pid=" . posix_getpid());
    
                // 批量更新任务状态为“运行中”
                $updateResult = Db::name('mttask')
                    ->whereIn('id', $taskIds)
                    ->update([
                        'status' => 2,
                        'lock_time' => time(),
                        'worker_pid' => posix_getpid()
                    ]);
    
                if ($updateResult !== count($taskIds)) {
                    throw new \Exception("部分任务状态更新失败，可能已被其他进程处理，成功更新: {$updateResult}/" . count($taskIds));
                }
    
                // 逐个处理任务
                foreach ($tasks as $task) {
                    Log::info("开始处理任务: task_id={$task['id']}, worker_id={$worker->id}, pid=" . posix_getpid());
                    $this->processTask($task); // 复用原有单任务处理逻辑
                    Log::info("任务处理完成: task_id={$task['id']}");
                }
    
            } finally {
                // 释放分布式锁
                if ($this->getRedisCache()->get($lockKey) == $lockValue) {
                    $this->getRedisCache()->delete($lockKey);
                }
            }
        } catch (\Exception $e) {
            $errorInfo = [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'worker_id' => $worker->id,
                'task_ids' => isset($taskIds) ? implode(',', $taskIds) : '未知'
            ];
            Log::error("批量任务处理出错", $errorInfo);
            
            // 批量标记失败（若有任务ID）
            if (!empty($taskIds)) {
                Db::name('mttask')
                    ->whereIn('id', $taskIds)
                    ->update([
                        'status' => 4,
                        'error_msg' => $e->getMessage(),
                        'update_time' => time()
                    ]);
            }
        }
    }
     
     
    /*
    private function fetchAndProcessTasks($worker, $specificTaskId = null)
    {
        try {
            // 构建查询条件
            $where = [
                'status' => 1,  // 只处理未开始的任务
               // 'lock_time' => ['lt', time() - 300] // 解锁超过5分钟的任务
            ];
    
            // 如果指定了任务ID
            if ($specificTaskId) {
                $where['id'] = $specificTaskId;
            }
            
            $lockKey = $this->redisPrefix . 'lock:task_fetch';
            $lockValue = uniqid();
            $lockAcquired = $this->getRedisCache()->set($lockKey, $lockValue, 5, ['nx', 'ex' => 5]);
            
            if (!$lockAcquired) {
                Log::info("工作进程 {$worker->id} 未获取到任务锁，跳过本次查询");
                return;
            }
         
            // 尝试获取一个任务并加锁
            try {
                $task = Db::name('mttask')
                    ->where($where)
                    ->order('create_time asc')
                    ->lock(true)
                    ->find();
                
                if ($task) {
                    // 记录任务开始处理的详细日志
                    Log::info("准备处理任务: task_id={$task['id']}, worker_id={$worker->id}, pid=" . posix_getpid());
        
                    // 标记任务为运行中
                    $updateResult = Db::name('mttask')
                        ->where('id', $task['id'])
                        ->update([
                            'status' => 2,
                            'lock_time' => time(),
                            'worker_pid' => posix_getpid()
                        ]);
        
                    if (!$updateResult) {
                        throw new \Exception("任务状态更新失败，可能已被其他进程处理");
                    }
        
                    Log::info("开始处理任务: task_id={$task['id']}, worker_id={$worker->id}, pid=" . posix_getpid());
        
                    // 处理任务
                    $this->processTask($task);
        
                    Log::info("任务处理完成". $task['id']);
                }
            } finally {
                // 释放锁（防止死锁）
                if ($this->getRedisCache()->get($lockKey) == $lockValue) {
                    $this->getRedisCache()->delete($lockKey);
                }
            }
        } catch (\Exception $e) {
            // 增强错误日志信息
            $errorInfo = [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'worker_id' => $worker->id,
                'task_id' => isset($task['id']) ? $task['id'] : '未知'
            ];
            Log::error("任务处理出错", $errorInfo);
            
            // 如果有任务ID，将任务状态标记为失败
            if (isset($task['id'])) {
                Db::name('mttask')
                    ->where('id', $task['id'])
                    ->update([
                        'status' => 4, // 失败状态
                        'error_msg' => $e->getMessage(),
                        'update_time' => time()
                    ]);
            }
        }
    }
    */
    /**
     * 初始化消息缓存（按任务+群组粒度）
     */
    private function initMessageCache($taskId, $messages, $groupList) {
        $redis = $this->getRedisCache();
        foreach ($groupList as $groupId) {
            $cacheKey = $this->getGroupMessageCacheKey($taskId, $groupId);
            foreach ($messages as $index => $msgConfig) {
                // 初始状态设为0（未发送）
                $redis->hSet($cacheKey, $index, 0);
            }
            // 设置48小时过期，避免残留
            $redis->expire($cacheKey, 172800);
        }
        Log::info("消息缓存初始化完成", ['task_id' => $taskId, 'groups' => $groupList]);
    }
    
    /**
     * 获取群组级别的消息缓存键（任务ID+群组ID）
     */
    private function getGroupMessageCacheKey($taskId, $groupId) {
        // 对群组ID（可能是链接）进行哈希，避免键名过长
        $groupHash = md5($groupId);
        return $this->redisPrefix . "task:{$taskId}:group:{$groupHash}:messages";
    }
    
    /**
     * 检查消息是否已发送（按群组+消息索引）
     */
    private function isMessageSent($taskId, $groupId, $msgIndex) {
        $cacheKey = $this->getGroupMessageCacheKey($taskId, $groupId);
        $status = $this->getRedisCache()->hGet($cacheKey, $msgIndex);
        return $status === '1'; // 1表示发送成功
    }
    
    /**
     * 更新消息发送状态（仅成功时标记）
     */
    private function markMessageAsSent($taskId, $groupId, $msgIndex) {
        $cacheKey = $this->getGroupMessageCacheKey($taskId, $groupId);
        try {
            $this->getRedisCache()->hSet($cacheKey, $msgIndex, 1);
            Log::info("消息状态标记为已发送".'task_id'.$taskId.'group_id'. $groupId.'msg_index'.$msgIndex);
            return true;
        } catch (\Exception $e) {
            Log::error("标记消息状态失败".'error'. $e->getMessage().'task_id'.$taskId.'group_id'. $groupId.'msg_index'.$msgIndex);
            return false;
        }
    }
    /**
     * 处理单个任务
     */
    private function processTask($task)
    {
        $taskId = $task['id'];
        $statusKey = $this->redisPrefix . 'status:' . $taskId;
        $first_msg_idsKey = $this->redisPrefix . 'task:' . $taskId.':first_msg_ids';
        $firstMessageIds = [];
        $redis = $this->getRedisCache();
        
        
        // 在processTask开头添加
        $taskConfigCacheKey = $this->redisPrefix . 'task:config:' . $taskId;
        $cachedConfig = $this->getRedisCache()->get($taskConfigCacheKey);
        
        
        
        
        try {
            $this->getRedisCache()->set($statusKey, 2); // 2:运行中
            
            // 1. 解析任务配置
            /*$messages = json_decode($task['messages'], true);
            $groupList = !empty($task['group_list']) ? explode(',', $task['group_list']) : [];
            $concurrent = $task['concurrent'] > 0 ? $task['concurrent'] : 5;*/
            
            if ($cachedConfig) {
                $config = json_decode($cachedConfig, true);
                $messages = $config['messages'];
                $groupList = $config['groupList'];
                $concurrent = $config['concurrent'];
                $xhnum = $config['xhnum'];
                // 从缓存获取当前循环次数
                $currentCycle = $this->getRedisCache()->get($this->redisPrefix . 'task:cycle:' . $taskId) ?: 1;
            } else {
                $messages = json_decode($task['messages'], true);
                $groupList = !empty($task['group_list']) ? explode(',', $task['group_list']) : [];
                $concurrent = $task['concurrent'] > 0 ? $task['concurrent'] : 5;
                $xhnum = $task['xhnum'] > 0 ? $task['xhnum'] : 1;
                // 初始化当前循环次数为1
                $currentCycle = 1;
                $this->getRedisCache()->set($this->redisPrefix . 'task:cycle:' . $taskId, $currentCycle);
                // 缓存任务配置（1小时过期）
                $this->getRedisCache()->set(
                    $taskConfigCacheKey,
                    json_encode([
                        'messages' => $messages,
                        'groupList' => $groupList,
                        'concurrent' => $concurrent,
                        'xhnum' => $xhnum,
                    ])
                );
            }
            
            
            
    
             // 初始化消息缓存（未初始化过的群组）
            foreach ($groupList as $groupId) {
                $cacheKey = $this->getGroupMessageCacheKey($taskId, $groupId);
                // 循环任务需要重置消息发送状态（关键修改）
                if ($currentCycle > 1) {
                    $this->initMessageCache($taskId, $messages, [$groupId]);
                    Log::info("任务 {$taskId} 第 {$currentCycle} 次循环，重置群组 {$groupId} 消息状态");
                } elseif (!$redis->exists($cacheKey)) {
                    $this->initMessageCache($taskId, $messages, [$groupId]);
                }
            }
            $lastSendTime = 0; // 新增：记录上次发送时间
            // 验证配置
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("消息配置解析错误：" . json_last_error_msg());
            }
            if (empty($groupList)) {
                throw new \Exception("群组列表不能为空");
            }
            if (empty($messages) || !is_array($messages)) {
                throw new \Exception("消息配置格式错误，应为数组");
            }
            
            
            // 2. 预加载发送人信息（使用缓存优化版本替换原有逻辑）
            $sendUserIds = array_unique(array_column($messages, 'sendUser'));
            $cacheKeys = array_map(function($id) {
                return $this->redisPrefix . 'user:' . $id;
            }, $sendUserIds);
        
            // 批量获取缓存
            $cachedUsers = $this->getRedisCache()->mGet($cacheKeys);
            $userMap = [];
            $missedIds = [];
            $archivedUserIds = []; // 存储已归档或已删除的用户ID
            //Log::info("用户的消息，用户cacheData: " . json_encode($cachedUsers, JSON_UNESCAPED_UNICODE));
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
                     Log::info("用户的消息，用户cacheData: " . json_encode($user, JSON_UNESCAPED_UNICODE));
                    
                } else {
                    Log::info("用户的消息，userId: " . $userId);
                    $missedIds[] = $userId;
                }
            }
           
            // 查询未命中缓存的用户
            if (!empty($missedIds)) {
                 Log::info("用户的消息，missedIds: " . implode(',', $missedIds));
                $freshUsers = Db::name('mtuser')
                    ->whereIn('id', $missedIds)
                    ->field('id, tdata_path,session_path, proxyip, archive')
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
                    Db::name('mttask')->where('id', $task['id'])->update([
                        'status' => 6, // 新增状态：已过滤
                        'update_time' => time(),
                        'error_msg' => '所有消息发送人已归档或删除'
                    ]);
                    return;
                }
            }

            
            
            
            // 3. 准备Python脚本路径（改为telegram_manager.py）
            $pythonScript = root_path() . 'python_scripts/telegram_manager.py';
            if (!file_exists($pythonScript)) {
                throw new \Exception("Python脚本不存在：{$pythonScript}");
            }
    
            $successCount = 0;
            $failCount = 0;
            $failDetails = [];
            $processes = [];
            $processCount = 0;
            // 获取第一条消息的索引（通常是0）
            $firstMsgIndex = 0;
            $firstMsgConfig = $messages[$firstMsgIndex] ?? null;
            Log::info("用户的消息，用户ID: " . json_encode($userMap, JSON_UNESCAPED_UNICODE));
            // 预先生成所有群组的首条消息ID并标记为已发送
            foreach ($groupList as $groupId) {
                if (!isset($firstMessageIds[$groupId]) && $firstMsgConfig) {
                    $sendUserId = $firstMsgConfig['sendUser'] ?? 0;
                    if (!empty($sendUserId) && !empty($userMap[$sendUserId])) {
                        $sendUser = $userMap[$sendUserId];
                        $tdataPath =  $sendUser['session_path'];
                        
                        // 检查首条消息是否已发送
                        if (!$this->isMessageSent($taskId, $groupId, $firstMsgIndex)) {
                            // 发送首条消息并获取ID
                            $firstMsgParams = $this->buildPythonParams($pythonScript, $tdataPath, $firstMsgConfig, $sendUser, $groupId, 0);
                            $result = $this->runPythonCommand($firstMsgParams);
                             Log::info("获取首条消息值：".json_encode($result, JSON_UNESCAPED_UNICODE));
                            if ($result['status'] && !empty($result['data']['success'][0]['message_id'])) {
                                $firstMessageIds[$groupId] = $result['data']['success'][0]['message_id'];
                                $this->markMessageAsSent($taskId, $groupId, $firstMsgIndex);
                                $this->cacheFirstMessageId($taskId, $groupId, $firstMessageIds[$groupId]);
                                $successCount++;
                                $this->cacheFirstMessageId($taskId, $groupId, $result['data']['success'][0]['message_id']);
                                Log::info("获取首条消息 ID：群组{$firstMsgConfig['sendUser']}-- {$groupId} → {$firstMessageIds[$groupId]}");
                            } else {
                                $failCount++;
                                $failDetails[] = "群组 {$groupId} 首条消息发送失败";
                            }
                        } else {
                            // 如果已发送，直接从缓存获取首条消息ID
                            // 这里假设你有存储首条消息ID的逻辑，或需要从历史记录中查询
                            $firstMessageIds[$groupId] = $this->getCachedFirstMessageId($taskId, $groupId);
                            Log::info("从缓存获取首条消息 ID：群组 {$groupId} → {$firstMessageIds[$groupId]}");
                        }
                    }
                }
            }
            $stopFlag = false;
            // 4. 遍历处理所有消息
            foreach ($messages as $index =>$msgConfig) {
                // 跳过首条消息，因为已经处理过了
                if ($index == $firstMsgIndex) {
                    continue;
                }
                 // 检查任务是否已被停止
                if ($this->isTaskStopped($task['id'])) {
                    Log::info("检测到任务已被手动停止，准备优雅退出...");
                    $stopFlag = true;
                }
            
                if ($stopFlag) {
                    Log::info("检测到任务已被手动停止，准备优雅退出...");
                    // 如果任务停止，先处理当前的进程再退出
                    if (!empty($processes)) {
                        $this->waitForProcesses($processes, $successCount, $failCount, $failDetails, $firstMessageIds, $redis, $taskId);
                        $processes = [];
                    }
                    break; // 退出消息循环
                }
           
                
                 $delay = isset($msgConfig['delay']) ? max(1, (int)$msgConfig['delay']) : 1; 
                // 验证消息配置必填项
                if (empty($msgConfig['sendType'])) {
                    $failCount++;
                    $failDetails[] = "群组：消息类型不能为空";
                    continue;
                }
                if (empty($msgConfig['text'])&&empty($msgConfig['images'])) {
                    $failCount++;
                    $failDetails[] = "群组：消息不能为空";
                    continue;
                }
                $sendUserId = $msgConfig['sendUser'] ?? 0;
                if (empty($sendUserId) || empty($userMap[$sendUserId])) {
                    $failCount++;
                    $failDetails[] = "群组：发送人ID无效".$msgConfig['sendUser'];
                    continue;
                }
                $sendUser = $userMap[$sendUserId];
                $tdataPath=$sendUser['session_path'];
                log::info('发送人tdataPath:'.$tdataPath);
                // 检查tdata路径
                if (empty($tdataPath) || !file_exists($tdataPath)) {
                    $failCount++;
                    $failDetails[] = "群组：发送人 {$sendUserId} 的tdata路径无效";
                    continue;
                }
                // 新增：处理延迟 - 计算需要等待的时间
                $currentTime = time();
                log::info("lastSendTime时间：".$lastSendTime.',发送人:'.$msgConfig['sendUser']);
                if ($lastSendTime > 0) {
                    $elapsed = $currentTime - $lastSendTime;
               
                    if ($elapsed < $delay) {
                        $waitTime = $delay - $elapsed;
                        // 使用非阻塞等待，同时检查任务是否被停止
                        $endTime = $currentTime + $waitTime;
                        while (time() < $endTime) {
                            if ($this->isTaskStopped($task['id'])) {
                               Log::info("检测到任务已被手动停止（延迟等待中）...");
                                $stopFlag = true;
                                break 2; // 跳出等待，回到主循环处理收尾
                            }
                            usleep(100000); // 每0.1秒检查一次
                        }
                    }
                }
                $lastSendTime = time(); // 更新上次发送时间
                // 遍历所有群组发送消息
                foreach ($groupList as $groupId) {
                    // 确保首条消息ID已获取
                    if (!isset($firstMessageIds[$groupId])) {
                        $failCount++;
                        $failDetails[] = "群组 {$groupId} 尚未获取到首条消息ID，无法继续发送";
                        continue;
                    }
                    if ($this->isMessageSent($taskId, $groupId, $index)) {
                        Log::info("消息已发送，跳过".$taskId.'-'.$groupId.'-'. $index);
                        $successCount++;
                        continue;
                    }
                
                    // 构建Python命令参数（适配telegram_manager.py的--action=send_messages）
                    $params = [
                        '--action' => 'send_messages',
                        '--tdata_path' => escapeshellarg($tdataPath),
                        '--api_id' => config('telegram.api_id'),
                        '--api_hash' => escapeshellarg(config('telegram.api_hash')),
                        '--group_id' => escapeshellarg($groupId), // 单个群组ID（每次处理一个）
                        '--message_type' => escapeshellarg($msgConfig['sendType']),
                        '--feedback_type' => escapeshellarg($msgConfig['feedbackType']),
                        '--delay' => intval($msgConfig['delay'] ?? 1),
                        '--first_msg_id' => $firstMessageIds[$groupId]
                    ];
    
                 
                    // 添加代理信息（如果有）
                    if (!empty($sendUser['proxyip'])) {
                        $proxy=$sendUser['proxyip'];
                        // 分割代理各部分
                        $parts = explode('##', $proxy);
                        
                        // 检查是否有足够的部分
                        if (count($parts) >= 3) {
                            list($ip_port, $username, $password) = $parts;
                            // 构建正确的代理格式
                            $proxy = "socks5://{$username}:{$password}@{$ip_port}";
                            $commandParts[] = "--proxy " . escapeshellarg($proxy);
                        } else {
                            // 处理格式错误
                            echo "代理格式错误: 缺少必要的部分";
                        }
                    }
                    // 根据消息类型添加特有参数
                    switch ($msgConfig['sendType']) {
                        case 'text':
                            $params['--message_text'] = escapeshellarg($msgConfig['text'] ?? '');
                            break;
                        case 'image_text':
                           
                            
                            if (!empty($msgConfig['images'])) {
                                $images = is_array($msgConfig['images']) ? $msgConfig['images'] : [];
                                // 拼接完整URL（如之前的逻辑）
                                $domain = config('telegram.cdn_domain');
                                $fullImagePaths = [];
                                // 遍历原始图片路径数组
                                foreach ($images as $img) {
                                    // 去除路径开头的斜杠（避免拼接后出现双斜杠）
                                    $imgPath = ltrim($img, '/');
                                    // 去除域名结尾的斜杠（同上）
                                    $baseUrl = rtrim($domain, '/');
                                    // 拼接完整URL并添加到新数组
                                    $fullImagePaths[] = $baseUrl . '/' . $imgPath;
                                }
                                
                                $mediaPaths = implode(',', $fullImagePaths); // 转为字符串
                                
                                $params["--media_paths " ]= escapeshellarg($mediaPaths) . " ";
                            }
                            //$params['--media_paths'] = escapeshellarg(implode(',', $images));
                            $params['--message_text'] = escapeshellarg($msgConfig['text'] ?? '');
                            break;
                        case 'image':
                            // 图片路径数组转逗号分隔字符串
                            if (!empty($msgConfig['images'])) {
                                $images = is_array($msgConfig['images']) ? $msgConfig['images'] : [];
                                // 拼接完整URL（如之前的逻辑）
                                $domain = config('telegram.cdn_domain');
                                $fullImagePaths = [];
                                // 遍历原始图片路径数组
                                foreach ($images as $img) {
                                    // 去除路径开头的斜杠（避免拼接后出现双斜杠）
                                    $imgPath = ltrim($img, '/');
                                    // 去除域名结尾的斜杠（同上）
                                    $baseUrl = rtrim($domain, '/');
                                    // 拼接完整URL并添加到新数组
                                    $fullImagePaths[] = $baseUrl . '/' . $imgPath;
                                }
                                
                                $mediaPaths = implode(',', $fullImagePaths); // 转为字符串
                                
                                $params["--media_paths " ]= escapeshellarg($mediaPaths) . " ";
                            }
                            break;
                            
                        default:
                            $failCount++;
                            $failDetails[] = "群组：不支持的消息类型 {$msgConfig['sendType']}";
                            continue 2;
                    }
    
                    // 构建完整命令
                    $command = "/www/server/pyporject_evn/versions/3.9.23/bin/python3  {$pythonScript} ";
                    foreach ($params as $key => $value) {
                        $command .= "{$key} {$value} ";
                    }
    
                    // 执行Python脚本（控制并发）
                    $process = popen($command, 'r');
                    log::info('指令：'.$command);
                    
                    if ($process) {
                        $processCount++;
                        $processes[] = [
                            'process' => $process,
                            //'group_id' => $groupId,
                            'send_user_id' => $sendUserId,
                            'msg_type' => $msgConfig['sendType'],
                            'index' => $index 
                        ];
                        
    
                        if ($processCount >= $concurrent) {
                            $this->waitForProcesses($processes, $successCount, $failCount, $failDetails, $firstMessageIds, $redis, $taskId);
                            $processes = [];
                            $processCount = 0;
                            $lastSendTime = time();
                        }
                    } else {
                        $failCount++;
                        $failDetails[] = "群组：无法启动发送进程";
                    }
                }
            }
        
    
            // 处理剩余进程
            if (!empty($processes)) {
                $this->waitForProcesses($processes, $successCount, $failCount, $failDetails, $firstMessageIds, $redis, $taskId);
            }
            if (!$stopFlag) {
                // 递增循环计数器
                $nextCycle = $currentCycle + 1;
                $redis->set($this->redisPrefix . 'task:cycle:' . $taskId, $nextCycle);
                
                // 检查是否达到循环次数
                if ($nextCycle > $xhnum) {
                    $this->getRedisCache()->set($statusKey, 3);
                    // 5. 更新任务状态
                    Db::name('mttask')
                        ->where('id', $task['id'])
                        ->update([
                            'status' => 3, // 已完成
                            'update_time' => time(),
                            'success_count' => $successCount,
                            'fail_count' => $failCount,
                            'error_msg' => implode('; ', $failDetails)
                        ]);
            
                    //$output->writeln("任务 {$task['id']} 处理完成：成功{$successCount}条，失败{$failCount}条");
                    Log::info("任务处理完成：".'task_id:'.$task['id'].',success:'.$successCount.',fail:'. $failCount);
                    // 清理缓存（任务完成后可选）
                   
                    foreach ($groupList as $groupId) {
                        $cacheKey = $this->getGroupMessageCacheKey($taskId, $groupId);
                        $redis->del($cacheKey);
                    }
                    $redis->del($statusKey);
                    $redis->del($first_msg_idsKey);
                    $redis->del($this->redisPrefix . 'task:cycle:' . $taskId);
                }else {
                    // 未达到指定循环次数，保持任务为运行中状态，等待下一轮执行
                   
                   
                    Db::name('mttask')
                    ->where('id', $task['id'])
                    ->update([
                        'status' => 1, // 继续运行中
                        'update_time' => time(),
                        'success_count' => $successCount,
                        'fail_count' => $failCount,
                        'error_msg' => implode('; ', $failDetails)
                    ]);
                    $redis->del($statusKey);
                    $redis->del($first_msg_idsKey);
                    Log::info("任务 {$taskId} 第 {$currentCycle}/{$xhnum} 次循环执行完成，等待下一轮");
                }    
            }else
                {
                // 被停止
                $this->getRedisCache()->set($statusKey, 5);
                Db::name('mttask')->where('id', $task['id'])->update([
                    'status' => 5,
                    'update_time' => time(),
                    'success_count' => $successCount,
                    'fail_count' => $failCount,
                    'error_msg' => implode('; ', $failDetails)
                ]);
                Log::info("任务被停止：task_id:{$task['id']}, success:{$successCount}, fail:{$failCount}");
            }
            
        } catch (\Exception $e) {
            $this->getRedisCache()->set($statusKey, 4);
            Db::name('mttask')
                ->where('id', $task['id'])
                ->update([
                    'status' => 4, // 失败
                    'update_time' => time(),
                    'error_msg' => $e->getMessage()
                ]);
    
            //$output->error("任务 {$task['id']} 处理失败：{$e->getMessage()}");
            Log::error("任务处理失败", ['task_id' => $task['id'], 'error' => $e->getMessage()]);
        }
    }
    
    /**
     * 等待进程完成并处理结果
     */
    private function waitForProcesses(&$processes, &$successCount, &$failCount, &$failDetails, &$firstMessageIds, $redis, $taskId)
    {
        foreach ($processes as $item) {
            // 读取Python脚本输出
            $output = stream_get_contents($item['process']);
            $exitCode = pclose($item['process']);
            
            // 解析JSON结果（适配telegram_manager.py的返回格式）
            $result = json_decode(trim($output), true);
            log::info("输出: " . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n返回码: {$exitCode}\n\n");
            if (json_last_error() !== JSON_ERROR_NONE) {
                $failCount++;
                $failDetails[] = "群组：脚本输出格式错误 - {$output}";
                continue;
            }
            // 标记消息为已发送
             
            // 根据返回状态更新统计
            if ($result['status'] === true) {
                // 成功：累加成功数量（支持批量群组）
                $successInBatch = $result['data']['success'] ?? [];
                foreach ($successInBatch as $successItem) {
                    $groupId = $successItem['group_id']; // 群组ID
                    $messageId = $successItem['message_id'] ?? null; // 消息ID（需Python返回）
                    $this->markMessageAsSent($taskId, $successItem['group_link'], $item['index']);
                    // 记录第一条成功消息的ID（按群组分组）
                    if ($messageId && !isset($firstMessageIds[$groupId])) {
                        $firstMessageIds[$groupId] = $messageId;
                        log::info("记录任务内第一条成功消息ID：群组{$groupId} → 消息ID{$messageId}");
                    }
                    
                    $successCount++;
                }
                
                // 检查批量中是否有失败项
                $failedInBatch = $result['data']['failed'] ?? [];
                foreach ($failedInBatch as $fail) {
                    $failCount++;
                    $failDetails[] = "群组：{$fail['message']}";
                }
            } else {
                // 整体失败
                $failCount++;
                $errorMsg = $result['message'] ?? "未知错误（退出码：{$exitCode}）";
                $failDetails[] = "群组（类型：{$item['msg_type']}）：{$errorMsg}";
            }
        }
    }
    /**
     * 存储首条消息ID到缓存
     */
    private function cacheFirstMessageId($taskId, $groupId, $messageId) {
        if (empty($taskId) || empty($groupId) || empty($messageId)) {
            Log::warning("缓存首条消息ID失败：参数不完整".'task_id' . $taskId. 'group_id'.$groupId. 'message_id' . $messageId);
            return false;
        }
        
        try {
            $cacheKey = $this->redisPrefix . "task:{$taskId}:first_msg_ids";
            // 存储到Redis哈希表，设置24小时过期时间（可根据需求调整）
            $this->getRedisCache()->hSet($cacheKey, $groupId, $messageId);
            $this->getRedisCache()->expire($cacheKey, 86400);
            
            Log::info("首条消息ID已缓存".'task_id' . $taskId. 'group_id'.$groupId. 'message_id' . $messageId);
            return true;
        } catch (\Exception $e) {
            Log::error("缓存首条消息ID时发生错误".'error' . $e->getMessage(). 'group_id'.$groupId. 'message_id' . $messageId);
            return false;
        }
    }
    
    /**
     * 从缓存获取首条消息ID
     */
    private function getCachedFirstMessageId($taskId, $groupId) {
        if (empty($taskId) || empty($groupId)) {
            Log::warning("获取首条消息ID失败：参数不完整".'task_id'. $taskId.'group_id'.$groupId);
            return null;
        }
        
        try {
            $cacheKey = $this->redisPrefix . "task:{$taskId}:first_msg_ids";
            $messageId = $this->getRedisCache()->hGet($cacheKey, $groupId);
            
            // 如果Redis中没有，尝试从数据库查询
            if ($messageId === false) {
                    return null;
                
            }
            
            Log::info("获取首条消息ID成功".'task_id' . $taskId. 'group_id'.$groupId. 'message_id' . $messageId);
            return $messageId;
        } catch (\Exception $e) {
            Log::error("获取首条消息ID时发生错误");
            return null;
        }
    }  
    private function buildPythonParams($pythonScript, $tdataPath, $msgConfig, $sendUser, $groupId, $firstMsgId)
    {
        $params = "/www/server/pyporject_evn/versions/3.9.23/bin/python3 {$pythonScript} ";
        $params .= "--action send_messages ";
        $params .= "--tdata_path " . escapeshellarg($tdataPath) . " ";
        $params .= "--api_id " . config('telegram.api_id') . " ";
        $params .= "--api_hash " . escapeshellarg(config('telegram.api_hash')) . " ";
        $params .= "--group_id " . escapeshellarg($groupId) . " ";
        $params .= "--message_type " . escapeshellarg($msgConfig['sendType']) . " ";
        $params .= "--feedback_type " . escapeshellarg($msgConfig['feedbackType'] ?? '') . " ";
        $params .= "--delay " . intval($msgConfig['delay'] ?? 1) . " ";
        $params .= "--first_msg_id {$firstMsgId} ";
    
        if (!empty($sendUser['proxyip'])) {
            $parts = explode('##', $sendUser['proxyip']);
            if (count($parts) >= 3) {
                list($ip_port, $username, $password) = $parts;
                $proxy = "socks5://{$username}:{$password}@{$ip_port}";
                $params .= "--proxy " . escapeshellarg($proxy) . " ";
            }
        }
    
        switch ($msgConfig['sendType']) {
            case 'text':
                $params .= "--message_text " . escapeshellarg($msgConfig['text'] ?? '') . " ";
                break;
            case 'image_text':
            case 'image':
                $baseUrl=config('telegram.cdn_domain');
                $images = is_array($msgConfig['images']) ? $msgConfig['images'] : [];
                $fullImagePaths = array_map(function($img) use ($baseUrl) {
                    // 处理图片路径（适配 ThinkPHP 的 public 目录结构）
                    // 假设 $img 存储的是从 public 目录开始的相对路径，如 'uploads/admin/202508/xxx.jpg'
                    // 若 $img 包含 '/uploads/...'，则先去除开头的斜杠
                    $imgPath = ltrim($img, '/'); 
                    // 拼接完整 URL（如 https://域名/uploads/...）
                    return rtrim($baseUrl, '/') . '/' . $imgPath;
                }, $images);
                
                $params .= "--media_paths " . escapeshellarg(implode(',', $fullImagePaths)) . " ";
                
                if ($msgConfig['sendType'] === 'image_text') {
                    $params .= "--message_text " . escapeshellarg($msgConfig['text'] ?? '') . " ";
                }
                break;
        }
        return $params;
    }
    
    private function runPythonCommand($command)
    {
        $process = popen($command, 'r');
        $output = stream_get_contents($process);
        $exitCode = pclose($process);
        $result = json_decode(trim($output), true);
        return $result;
    }
    /**
     * 检查任务是否已被停止
     */
    private function isTaskStopped($taskId)
    {
        // 先查缓存，缓存不存在则查数据库并同步到缓存
        $cacheKey = $this->redisPrefix . 'status:' . $taskId;
        $status = $this->getRedisCache()->get($cacheKey);
        
        if ($status === null) {
            $status = Db::name('mttask')->where('id', $taskId)->value('status');
            // 缓存10秒，避免频繁查库
            $this->getRedisCache()->set($cacheKey, $status, 30);
        }
        
        return in_array($status, [1, 5]); // 1:未开始 5:已停止
    }




    /**
     * 后台运行
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


    // 新增：清理过期任务缓存
    private function cleanExpiredCache()
    {
        // 定时清理30分钟前的任务缓存
        Timer::add(1800, function () {
            $pattern = $this->redisPrefix . 'info:*';
            $keys = $this->getRedisCache()->handler()->keys($pattern);
            if (!empty($keys)) {
                $this->getRedisCache()->delete($keys);
                Log::info("清理过期任务缓存", ['count' => count($keys)]);
            }
        });
    }
    
    
    private function callSendMessagesApi($tdataPath, $msgConfig, $sendUser, $groupId, $firstMsgId)
    {
        // 初始化Guzzle客户端
        $client = new Client([
            'timeout' => 60.0,
            'connect_timeout' => 10.0,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ]
        ]);

        // 构建基础请求数据
        $requestData = [
            'action' => 'send_messages',
            'tdata_path' => $tdataPath,
            'api_id' => config('telegram.api_id'),
            'api_hash' => config('telegram.api_hash'),
            'group_id' => $groupId,
            'message_type' => $msgConfig['sendType'],
            'feedback_type' => $msgConfig['feedbackType'] ?? '',
            'delay' => intval($msgConfig['delay'] ?? 1),
            'first_msg_id' => $firstMsgId
        ];

        // 处理代理信息
        if (!empty($sendUser['proxyip'])) {
            $parts = explode('##', $sendUser['proxyip']);
            if (count($parts) >= 3) {
                list($ip_port, $username, $password) = $parts;
                $requestData['proxy'] = "socks5://{$username}:{$password}@{$ip_port}";
            }
        }

        // 根据消息类型添加特定参数
        switch ($msgConfig['sendType']) {
            case 'text':
                $requestData['message_text'] = $msgConfig['text'] ?? '';
                break;
            case 'image_text':
            case 'image':
                $baseUrl = config('telegram.cdn_domain');
                $images = is_array($msgConfig['images']) ? $msgConfig['images'] : [];
                
                // 处理图片路径为完整URL
                $fullImagePaths = array_map(function($img) use ($baseUrl) {
                    $imgPath = ltrim($img, '/');
                    return rtrim($baseUrl, '/') . '/' . $imgPath;
                }, $images);
                
                $requestData['media_paths'] = implode(',', $fullImagePaths);
                
                // 图文类型需要额外添加文本内容
                if ($msgConfig['sendType'] === 'image_text') {
                    $requestData['message_text'] = $msgConfig['text'] ?? '';
                }
                break;
        }

        try {
            // 发送POST请求到接口
            $response = $client->post(config('telegram.flask_url') . '/telegram_action', [
                'json' => $requestData
            ]);

            // 解析响应
            $responseBody = $response->getBody()->getContents();
            $result = json_decode($responseBody, true);

            // 检查JSON解析错误
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("接口响应解析失败: " . json_last_error_msg());
            }

            // 检查接口返回状态
            if (empty($result['status'])) {
                $message = $result['message'] ?? '未知错误';
                throw new Exception("发送消息失败: {$message}");
            }

            return $result;

        } catch (RequestException $e) {
            // 处理HTTP请求异常
            $errorMsg = "请求接口失败: ";
            if ($e->hasResponse()) {
                $errorMsg .= "状态码: {$e->getResponse()->getStatusCode()}, 内容: {$e->getResponse()->getBody()->getContents()}";
            } else {
                $errorMsg .= $e->getMessage();
            }
            throw new Exception($errorMsg);
        } catch (Exception $e) {
            // 处理其他异常
            throw new Exception("发送消息时发生错误: " . $e->getMessage());
        }
    }
}
    