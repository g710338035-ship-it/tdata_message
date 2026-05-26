<?php
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Queue;
use think\facade\Log;
use app\admin\model\Mttask as MttaskModel;
use app\job\TelegramTaskExecutor; 
use think\facade\Cache;

class TelegramTaskProducer extends Command
{
    // 配置参数
    private $config = [
        // 单次获取任务数量
        'batch_size' => 10,
       
    ];
    
    protected function configure()
    {
        $this->setName('telegram:task-producer')
             ->setDescription('Telegram任务：扫描并投递任务到队列');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln('[' . date('Y-m-d H:i:s') . '] 开始扫描待处理任务...');
        // 查询条件
        $where = [
            'status' => 1, // 状态为未开始
        ];
        
        // 查询符合条件的任务
        $tasks = MttaskModel::where($where)
            ->order('create_time asc')
            ->limit($this->config['batch_size'])
            ->select();

        if ($tasks->isEmpty()) {
            $output->writeln('未发现符合条件的任务。');
            return;
        }

        $successCount = 0;
        $failedIds = [];
        
        foreach ($tasks as $task) {
            try {
                // 投递任务到队列
                $jobData = [
                    'task_id' => $task->id,
                    'create_time' => time()
                ];
                
                // 推送任务到指定队列
                $queueName = 'telegram_task_queue';
                $jobId = Queue::push(TelegramTaskExecutor::class, $jobData, $queueName);
                
                if ($jobId) {
                    $successCount++;
                    $output->writeln("任务 {$task->id} 已成功投递到队列 {$queueName}");
                    Log::info("任务 {$task->id} 已成功投递到队列 {$queueName}");
                } else {
                    throw new \Exception("队列推送返回无效ID");
                }
            } catch (\Exception $e) {
                $failedIds[] = $task->id;
                $output->error("任务 {$task->id} 投递失败: " . $e->getMessage());
                Log::error("任务 {$task->id} 投递失败: " . $e->getMessage());
            }
        }
        
        $output->writeln('任务扫描与投递完成 - 成功: ' . $successCount . ', 失败: ' . count($failedIds));
        if (!empty($failedIds)) {
            $output->warning('投递失败的任务ID: ' . implode(',', $failedIds));
        }
    }
}
