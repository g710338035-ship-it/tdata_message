<?php
namespace app\command;

use app\service\ArbiscanService;
use think\console\Command;
use think\console\Input;
use think\console\Output;

class CheckArbiscan extends Command
{
    protected function configure()
    {
        $this->setName('check:arbiscan')->setDescription('检查区块地址变化');
    }

    protected function execute(Input $input, Output $output)
    {
        $service = new ArbiscanService();
        $service->checkAddresses();
        $output->writeln("区块地址检测已完成");
    }
}
