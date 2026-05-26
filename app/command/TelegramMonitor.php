<?php

namespace app\command;

use app\service\TelegramService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

class TelegramMonitor extends Command
{
    protected function configure()
    {
        // 命令配置
        $this->setName('telegram:monitor')
            ->setDescription('Start Telegram message monitoring');
    }

    protected function execute(Input $input, Output $output)
    {
         try {
            $telegramService = new TelegramService();
            $telegramService->startListening('+917015142278');
            $output->writeln("Telegram message monitoring has been started.");
        } catch (\Exception $e) {
            $output->writeln("Failed to start monitoring: " . $e->getMessage());
        }

        // 保持脚本运行，持续监听消息
        while (true) {
            sleep(1);
        }
        // 获取需要监听的任务信息，这里假设从数据库中获取
        /*$tasks = Db::name('monitor_tasks')->where('status', 1)->select()->toArray();

        if (empty($tasks)) {
            $output->writeln('No active monitoring tasks found.');
            return;
        }

        foreach ($tasks as $task) {
            try {
                $telegramService = new TelegramService();
                $telegramService->startTask($task);
                $output->writeln("Monitoring task for phone {$task['phone']} has been started.");
            } catch (\Exception $e) {
                $output->writeln("Failed to start monitoring task for phone {$task['phone']}: " . $e->getMessage());
            }
        }

        // 保持脚本运行，持续监听消息
        while (true) {
            sleep(1);
        }*/
    }
}