<?php
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\swoole\worker\Queue;

class SwooleQueue extends Command
{
    protected function configure()
    {
        $this->setName('swoole:queue')
             ->setDescription('Start Swoole queue worker');
    }

    protected function execute(Input $input, Output $output)
    {
        // 实例化 Swoole 队列 Worker 并启动
        $queue = new Queue(app());
        $queue->setQueue('mtuser_handle') // 指定队列
              ->setVerbose(true)         // 详细日志
              ->start();
    }
}