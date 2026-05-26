<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
       // 'night:mute' => \app\command\NightMute::class,
        //'night:mute' => 'app\command\NightMute',
        //'telegram:pushMessage' => 'app\command\PushMessage',
       // 'check:arbiscan' => 'app\command\CheckArbiscan',
       // 'monitor:group' => 'app\command\MonitorGroup',
       // 'telegram:monitor' => 'app\command\TelegramMonitor',
        //'telegram:task-manager'=>'app\command\TelegramTaskManager',
        'telegram:task-manager-http'=>'app\command\TelegramTaskManagerHttp',
       // 'telegram:task-producer'=>'app\command\TelegramTaskProducer',
        //'swoole:queue' => '\app\command\SwooleQueue::class',
        //'worker:chat' => 'app\command\ChatServer',
        //'monitor:blocks' => 'app\command\MonitorBlocks',
        'telegram:consume-results' => 'app\command\ConsumePythonResults',
    ],
];
