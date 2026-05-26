<?php
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;

use think\facade\Db;
use think\facade\Log;

class TelegramMute extends Command
{
    protected function configure()
    {
        $this->setName('telegram:mute')
             ->setDescription('Handle telegram group mute');
    }

    protected function execute(Input $input, Output $output)
    {
        $nowTime = date("H:i:s");

        // 获取禁言时间到了的群组
        $groups = Db::name('telegram_groups')
            ->where('starttime', '<=', $nowTime)
            ->where('endtime', '>=', $nowTime)
            ->select();

        foreach ($groups as $group) {
            $this->muteGroup($group['group_id']);
        }
    }

    protected function muteGroup($groupId)
    {
        // 调用 Telegram API 禁言群组（不使用 SDK）
        $url = "https://api.telegram.org/bot<your-bot-token>/restrictChatMember?chat_id={$groupId}&permissions={\"can_send_messages\":false}";
        file_get_contents($url);
    }
}
